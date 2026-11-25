<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * Bug reports the bot saved, for the admins to act on later.
 *
 * One report per topic: a later post in the same topic updates it, so a
 * long bug thread is one report that grows, not twenty. A report whose
 * title is close to an earlier one's is marked as a possible duplicate,
 * never merged: a person decides. Nothing here sends anything anywhere;
 * github_url() only builds a link an admin opens and submits by hand.
 *
 * Every field comes from Claude reading member posts, so it is stored as
 * plain text, cut to length, and escaped wherever it is shown.
 */
class reports
{
	public const STATUSES = ['new', 'confirmed', 'filed', 'done', 'not_a_bug', 'duplicate'];

	/** Field => longest kept, in characters. */
	public const FIELDS = [
		'title' => 120,
		'component' => 64,
		'summary' => 4000,
		'steps' => 4000,
		'expected' => 1000,
		'actual' => 1000,
		'platform' => 100,
		'version' => 64,
	];

	/** How alike two titles must be (0..100, similar_text percent) to flag a duplicate. */
	public const DUPLICATE_PERCENT = 80;

	protected \phpbb\db\driver\driver_interface $db;
	protected string $table;

	public function __construct(\phpbb\db\driver\driver_interface $db, string $table_prefix)
	{
		$this->db = $db;
		$this->table = $table_prefix . 'beyondbot_reports';
	}

	/**
	 * Claude's tool input, made safe to store: known fields only, plain
	 * one-line text where a line is expected, control characters out,
	 * each cut to its length. Null when the title or summary is missing.
	 */
	public static function clean(array $input): ?array
	{
		$out = [];
		foreach (self::FIELDS as $field => $max)
		{
			$v = is_scalar($input[$field] ?? null) ? (string) $input[$field] : '';
			$v = str_replace(["\r\n", "\r"], "\n", $v);
			$v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
			if ($max <= 120)
			{
				$v = preg_replace('/\s+/u', ' ', $v) ?? $v;
			}
			$out[$field] = mb_substr(trim($v), 0, $max);
		}
		if (mb_strlen($out['title']) < 3 || $out['summary'] === '')
		{
			return null;
		}
		return $out;
	}

	/** Lower case, letters and digits only, single spaces: what a duplicate check compares. */
	public static function normal_title(string $title): string
	{
		$t = mb_strtolower($title);
		$t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? $t;
		return trim($t);
	}

	public static function alike(string $a, string $b): bool
	{
		$a = self::normal_title($a);
		$b = self::normal_title($b);
		if ($a === '' || $b === '')
		{
			return false;
		}
		if ($a === $b)
		{
			return true;
		}
		similar_text($a, $b, $percent);
		return $percent >= self::DUPLICATE_PERCENT;
	}

	/**
	 * Save a report for a topic, or update the topic's report.
	 *
	 * @param array $fields from clean()
	 * @param array $ctx    topic_id, post_id, forum_id, user_id, source
	 * @return array{id:int,updated:bool,dup_of:int}|array{error:string}
	 */
	public function save(array $fields, array $ctx, int $now, int $daily_cap): array
	{
		$topic_id = (int) $ctx['topic_id'];
		$existing = $this->for_topic($topic_id);
		if ($existing)
		{
			$id = (int) $existing['report_id'];
			$update = ['updated' => $now, 'post_id' => (int) $ctx['post_id']];
			foreach ($fields as $k => $v)
			{
				// A later post fills in what was missing; it never blanks a field.
				if ($v !== '')
				{
					$update[$k] = $v;
				}
			}
			$this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $this->db->sql_build_array('UPDATE', $update) . ' WHERE report_id = ' . $id);
			return ['id' => $id, 'updated' => true, 'dup_of' => (int) $existing['dup_of']];
		}
		if ($this->count_since($now - 86400) >= $daily_cap)
		{
			return ['error' => 'daily report limit reached'];
		}
		$dup_of = $this->find_alike($fields['title'], $fields['component']);
		$this->db->sql_return_on_error(true);
		$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $fields + [
			'topic_id' => $topic_id,
			'post_id' => (int) $ctx['post_id'],
			'forum_id' => (int) $ctx['forum_id'],
			'user_id' => (int) $ctx['user_id'],
			'source' => (string) $ctx['source'],
			'created' => $now,
			'updated' => $now,
			'status' => 'new',
			'note' => '',
			'dup_of' => $dup_of,
		]));
		$failed = $this->db->get_sql_error_triggered();
		$this->db->sql_return_on_error(false);
		if ($failed)
		{
			// Two jobs for one topic at once: the unique key let one in.
			$row = $this->for_topic($topic_id);
			return $row ? ['id' => (int) $row['report_id'], 'updated' => true, 'dup_of' => (int) $row['dup_of']] : ['error' => 'could not save'];
		}
		return ['id' => (int) $this->db->sql_nextid(), 'updated' => false, 'dup_of' => $dup_of];
	}

	public function for_topic(int $topic_id): ?array
	{
		$result = $this->db->sql_query_limit('SELECT * FROM ' . $this->table . ' WHERE topic_id = ' . $topic_id, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ?: null;
	}

	public function get(int $id): ?array
	{
		$result = $this->db->sql_query_limit('SELECT * FROM ' . $this->table . ' WHERE report_id = ' . $id, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ?: null;
	}

	public function count_since(int $since): int
	{
		$result = $this->db->sql_query('SELECT COUNT(report_id) AS n FROM ' . $this->table . ' WHERE created >= ' . $since);
		$n = (int) $this->db->sql_fetchfield('n');
		$this->db->sql_freeresult($result);
		return $n;
	}

	/** The newest open report with a title like this one (and the same component, when both name one), or 0. */
	protected function find_alike(string $title, string $component): int
	{
		$result = $this->db->sql_query_limit('SELECT report_id, title, component FROM ' . $this->table . "
			WHERE status <> 'not_a_bug' ORDER BY report_id DESC", 500);
		$found = 0;
		while ($row = $this->db->sql_fetchrow($result))
		{
			$same_part = $component === '' || $row['component'] === '' || mb_strtolower($component) === mb_strtolower($row['component']);
			if ($same_part && self::alike($title, $row['title']))
			{
				$found = (int) $row['report_id'];
				break;
			}
		}
		$this->db->sql_freeresult($result);
		return $found;
	}

	/** @return list<array> newest first, optionally one status only */
	public function list(string $status, int $limit, int $start = 0): array
	{
		$where = in_array($status, self::STATUSES, true) ? " WHERE status = '" . $this->db->sql_escape($status) . "'" : '';
		$result = $this->db->sql_query_limit('SELECT * FROM ' . $this->table . $where . ' ORDER BY report_id DESC', $limit, $start);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows ?: [];
	}

	public function total(string $status): int
	{
		$where = in_array($status, self::STATUSES, true) ? " WHERE status = '" . $this->db->sql_escape($status) . "'" : '';
		$result = $this->db->sql_query('SELECT COUNT(report_id) AS n FROM ' . $this->table . $where);
		$n = (int) $this->db->sql_fetchfield('n');
		$this->db->sql_freeresult($result);
		return $n;
	}

	public function set_status(int $id, string $status, string $note, int $now): bool
	{
		if (!in_array($status, self::STATUSES, true))
		{
			return false;
		}
		$this->db->sql_query('UPDATE ' . $this->table . ' SET ' . $this->db->sql_build_array('UPDATE', [
			'status' => $status,
			'note' => mb_substr($note, 0, 2000),
			'updated' => $now,
		]) . ' WHERE report_id = ' . $id);
		return (bool) $this->db->sql_affectedrows();
	}

	public function delete(int $id): void
	{
		$this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE report_id = ' . $id);
	}

	/**
	 * GitHub's "new issue" page with the report filled in. The admin
	 * reads it there and presses Submit: the board holds no token.
	 * Kept under GitHub's URL length limit by cutting the longest fields.
	 */
	public static function github_url(string $repo, array $r, string $post_url): string
	{
		if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo))
		{
			return '';
		}
		$body = self::issue_body($r, $post_url, 4000);
		$url = 'https://github.com/' . $repo . '/issues/new?' . http_build_query(['title' => (string) $r['title'], 'body' => $body], '', '&', PHP_QUERY_RFC3986);
		foreach ([800, 200] as $max)
		{
			if (strlen($url) <= 7500)
			{
				break;
			}
			$body = self::issue_body($r, $post_url, $max);
			$url = 'https://github.com/' . $repo . '/issues/new?' . http_build_query(['title' => (string) $r['title'], 'body' => $body], '', '&', PHP_QUERY_RFC3986);
		}
		return $url;
	}

	public static function issue_body(array $r, string $post_url, int $max_section): string
	{
		$out = [];
		$sections = [
			'summary' => 'Summary',
			'steps' => 'Steps to reproduce',
			'expected' => 'Expected',
			'actual' => 'Actual',
		];
		foreach ($sections as $field => $heading)
		{
			$v = trim((string) ($r[$field] ?? ''));
			if ($v !== '')
			{
				if (mb_strlen($v) > $max_section)
				{
					$v = mb_substr($v, 0, $max_section) . ' [...]';
				}
				$out[] = '### ' . $heading . "\n\n" . $v;
			}
		}
		$facts = [];
		foreach (['component' => 'Component', 'platform' => 'Platform', 'version' => 'Version'] as $field => $label)
		{
			if (trim((string) ($r[$field] ?? '')) !== '')
			{
				$facts[] = '- ' . $label . ': ' . trim((string) $r[$field]);
			}
		}
		if ($facts)
		{
			$out[] = implode("\n", $facts);
		}
		$out[] = 'Reported on the forum: ' . $post_url . "\n\n_Summarised by Beyond Bot from the forum post; check it against the post before acting._";
		return implode("\n\n", $out);
	}
}
