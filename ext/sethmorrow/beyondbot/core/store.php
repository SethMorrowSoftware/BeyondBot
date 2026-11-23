<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * The queue and the answer log.
 *
 *   beyondbot_queue: one row per post that called the bot (post_id unique)
 *   beyondbot_log:   one row per thing the bot did or refused to do, with
 *                    tokens and cost; the limits and the spend cap are
 *                    counted from it, so there is no counter to drift
 */
class store
{
	public const PENDING = 'pending';
	public const DONE = 'done';
	public const SKIPPED = 'skipped';
	public const FAILED = 'failed';

	/** Log outcomes that count as an answer for the limits. */
	public const ANSWERED = 'answered';
	public const LIMIT_NOTE = 'limit_note';

	protected \phpbb\db\driver\driver_interface $db;
	protected string $queue_table;
	protected string $log_table;

	public function __construct(\phpbb\db\driver\driver_interface $db, string $table_prefix)
	{
		$this->db = $db;
		$this->queue_table = $table_prefix . 'beyondbot_queue';
		$this->log_table = $table_prefix . 'beyondbot_log';
	}

	/** Queue a post once; a second call for the same post (an edit) does nothing. */
	public function enqueue(int $post_id, int $topic_id, int $forum_id, int $user_id, int $now): bool
	{
		$sql = 'SELECT job_id FROM ' . $this->queue_table . ' WHERE post_id = ' . $post_id;
		$result = $this->db->sql_query_limit($sql, 1);
		$exists = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if ($exists)
		{
			return false;
		}
		$this->db->sql_return_on_error(true);
		$this->db->sql_query('INSERT INTO ' . $this->queue_table . ' ' . $this->db->sql_build_array('INSERT', [
			'post_id' => $post_id,
			'topic_id' => $topic_id,
			'forum_id' => $forum_id,
			'user_id' => $user_id,
			'status' => self::PENDING,
			'attempts' => 0,
			'next_try' => $now,
			'created' => $now,
			'reason' => '',
			'reply_post_id' => 0,
		]));
		// A race with a second request for the same post hits the unique key: fine.
		$ok = !$this->db->get_sql_error_triggered();
		$this->db->sql_return_on_error(false);
		return $ok;
	}

	public function due_jobs(int $limit, int $now): array
	{
		$sql = 'SELECT * FROM ' . $this->queue_table . "
			WHERE status = '" . self::PENDING . "'
				AND next_try <= " . $now . '
			ORDER BY next_try ASC, job_id ASC';
		$result = $this->db->sql_query_limit($sql, $limit);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows ?: [];
	}

	public function pending_count(int $now): int
	{
		$sql = 'SELECT COUNT(job_id) AS n FROM ' . $this->queue_table . "
			WHERE status = '" . self::PENDING . "' AND next_try <= " . $now;
		$result = $this->db->sql_query($sql);
		$n = (int) $this->db->sql_fetchfield('n');
		$this->db->sql_freeresult($result);
		return $n;
	}

	public function update_job(int $job_id, array $fields): void
	{
		$this->db->sql_query('UPDATE ' . $this->queue_table . ' SET ' . $this->db->sql_build_array('UPDATE', $fields) . ' WHERE job_id = ' . $job_id);
	}

	public function log(array $row): void
	{
		$row += [
			'job_id' => 0, 'post_id' => 0, 'topic_id' => 0, 'user_id' => 0, 'log_time' => time(),
			'model' => '', 'input_tokens' => 0, 'output_tokens' => 0, 'cache_write_tokens' => 0,
			'cache_read_tokens' => 0, 'cost_micro' => 0, 'duration_ms' => 0, 'queries' => '',
			'outcome' => '', 'detail' => '', 'reply_post_id' => 0,
		];
		$row['detail'] = mb_substr((string) $row['detail'], 0, 255);
		$row['model'] = substr((string) $row['model'], 0, 64);
		$this->db->sql_query('INSERT INTO ' . $this->log_table . ' ' . $this->db->sql_build_array('INSERT', $row));
	}

	protected function count_log(string $where): int
	{
		$result = $this->db->sql_query('SELECT COUNT(log_id) AS n FROM ' . $this->log_table . ' WHERE ' . $where);
		$n = (int) $this->db->sql_fetchfield('n');
		$this->db->sql_freeresult($result);
		return $n;
	}

	/** The counts limits::decide() reads. */
	public function counts(int $user_id, int $topic_id, int $now): array
	{
		$day = $now - 86400;
		$answered = "outcome = '" . self::ANSWERED . "'";
		return [
			'user_answers_24h' => $this->count_log("$answered AND user_id = $user_id AND log_time >= $day"),
			'topic_answers' => $this->count_log("$answered AND topic_id = $topic_id"),
			'board_answers_24h' => $this->count_log("$answered AND log_time >= $day"),
			'month_spent_micro' => $this->month_spent($now),
		];
	}

	public function limit_note_sent(int $user_id, int $now): bool
	{
		return $this->count_log("outcome = '" . self::LIMIT_NOTE . "' AND user_id = $user_id AND log_time >= " . ($now - 86400)) > 0;
	}

	/** Midnight UTC on the first of the month. */
	public static function month_start(int $now): int
	{
		return gmmktime(0, 0, 0, (int) gmdate('n', $now), 1, (int) gmdate('Y', $now));
	}

	public function month_spent(int $now): int
	{
		$result = $this->db->sql_query('SELECT SUM(cost_micro) AS spent FROM ' . $this->log_table . ' WHERE log_time >= ' . self::month_start($now));
		$spent = (int) $this->db->sql_fetchfield('spent');
		$this->db->sql_freeresult($result);
		return $spent;
	}

	public function recent_log(int $limit, int $start = 0): array
	{
		$result = $this->db->sql_query_limit('SELECT * FROM ' . $this->log_table . ' ORDER BY log_id DESC', $limit, $start);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows ?: [];
	}

	public function log_total(): int
	{
		return $this->count_log('1 = 1');
	}

	public function prune(int $before): void
	{
		$this->db->sql_query('DELETE FROM ' . $this->queue_table . " WHERE status <> '" . self::PENDING . "' AND created < " . $before);
	}
}
