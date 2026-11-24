<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * Works the queue: one question at a time, every limit checked first.
 *
 * Run by the cron task. Nothing here runs while a member is posting.
 * A job ends in one of four states: done (answered), skipped (a limit or
 * a rule said no, or Claude declined), failed (errors three times), or
 * still pending (waiting for approval, the cap, or a working API key).
 * Every outcome that cost money or posted anything is written to the log.
 */
class worker
{
	public const MAX_ATTEMPTS = 3;

	protected settings $settings;
	protected store $store;
	protected forum $forum;
	protected knowledge $knowledge;
	protected \phpbb\language\language $language;
	protected ?\phpbb\log\log_interface $log;
	/** @var callable(string $api_key, string $base_url): gateway */
	protected $gateway_factory;
	/** @var callable(): int */
	protected $clock;

	public function __construct(settings $settings, store $store, forum $forum, knowledge $knowledge,
		\phpbb\language\language $language, ?\phpbb\log\log_interface $log, callable $gateway_factory, ?callable $clock = null)
	{
		$this->settings = $settings;
		$this->store = $store;
		$this->forum = $forum;
		$this->knowledge = $knowledge;
		$this->language = $language;
		$this->log = $log;
		$this->gateway_factory = $gateway_factory;
		$this->clock = $clock ?? 'time';
	}

	protected function now(): int
	{
		return (int) ($this->clock)();
	}

	/**
	 * @return array<string,int> how many jobs ended how
	 */
	public function run(int $limit, float $budget = 90.0): array
	{
		$this->language->add_lang('common', 'sethmorrow/beyondbot');
		$deadline = microtime(true) + $budget;
		$tally = [];
		foreach ($this->store->due_jobs($limit, $this->now()) as $job)
		{
			$outcome = $this->process($job);
			$tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
			if ($outcome === 'paused' || microtime(true) >= $deadline)
			{
				break;
			}
		}
		$this->store->prune($this->now() - 90 * 86400);
		return $tally;
	}

	/**
	 * @return string answered|skipped|waiting|limit_note|retry|failed|paused
	 */
	public function process(array $job): string
	{
		$now = $this->now();
		$job_id = (int) $job['job_id'];
		$q = $this->forum->question((int) $job['post_id']);
		if (!$q)
		{
			$this->store->update_job($job_id, ['status' => store::SKIPPED, 'reason' => 'post gone']);
			return 'skipped';
		}

		$asker = (int) $q['poster_id'];
		$facts = [
			'forum_id' => (int) $q['forum_id'],
			'topic_id' => (int) $q['topic_id'],
			'topic_locked' => (int) $q['topic_status'] === ITEM_LOCKED,
			'asker_is_bot' => $asker === $this->settings->bot_user_id(),
			'asker_ignored' => $asker === ANONYMOUS
				|| in_array((int) $q['user_type'], [USER_IGNORE, USER_INACTIVE], true)
				|| $this->forum->is_banned($asker),
			'asker_group_ids' => $this->forum->groups($asker),
			'asker_posts' => (int) $q['user_posts'],
			'visibility' => self::visibility((int) $q['post_visibility']),
			'age_seconds' => $now - (int) $q['post_time'],
		];
		$counts = $this->store->counts($asker, (int) $q['topic_id'], $now);
		[$verdict, $reason] = limits::decide($this->settings->for_limits(), $facts, $counts);

		$base_log = ['job_id' => $job_id, 'post_id' => (int) $q['post_id'], 'topic_id' => (int) $q['topic_id'], 'user_id' => $asker, 'log_time' => $now];

		if ($verdict === limits::SKIP)
		{
			$this->store->update_job($job_id, ['status' => store::SKIPPED, 'reason' => $reason]);
			$this->store->log($base_log + ['outcome' => 'skipped', 'detail' => $reason]);
			return 'skipped';
		}
		if ($verdict === limits::WAIT)
		{
			$later = $reason === 'waiting for moderator approval' ? 600 : 3600;
			$this->store->update_job($job_id, ['next_try' => $now + $later, 'reason' => $reason]);
			return 'waiting';
		}
		if ($verdict === limits::USER_LIMIT)
		{
			$this->store->update_job($job_id, ['status' => store::SKIPPED, 'reason' => $reason]);
			if (!$this->store->limit_note_sent($asker, $now))
			{
				$note = $this->language->lang('BEYONDBOT_LIMIT_NOTE', (int) $this->settings->get('user_daily'));
				$reply_id = $this->forum->post_reply($this->settings->bot_user_id(), $q, $this->quote($q) . $note);
				$this->store->log($base_log + ['outcome' => store::LIMIT_NOTE, 'detail' => $reason, 'reply_post_id' => $reply_id]);
				return 'limit_note';
			}
			$this->store->log($base_log + ['outcome' => 'skipped', 'detail' => $reason]);
			return 'skipped';
		}

		return $this->answer($job, $q, $base_log);
	}

	protected function answer(array $job, array $q, array $base_log): string
	{
		$job_id = (int) $job['job_id'];
		$started = microtime(true);
		$responder = new responder(
			($this->gateway_factory)($this->settings->api_key(), (string) $this->settings->get('api_base')),
			$this->knowledge,
			[
				'model' => $this->settings->model(),
				'effort' => (string) ($this->settings->get('effort') ?: 'medium'),
				'max_tokens' => max(1024, (int) $this->settings->get('max_tokens')),
				'system' => $this->settings->system_prompt(),
			]
		);
		$user_turn = prompt::user_turn(
			['title' => $q['topic_title'], 'forum_name' => $q['forum_name']],
			$this->forum->context((int) $q['topic_id'], (int) $q['topic_first_post_id'], (int) $q['post_id']),
			['post_id' => (int) $q['post_id'], 'author' => $q['username'], 'time' => (int) $q['post_time'], 'text' => $q['text']]
		);

		try
		{
			$r = $responder->answer($user_turn);
		}
		catch (gateway_error $e)
		{
			return $this->failed_call($job, $base_log + $this->usage_log($e->calls, $started), $e);
		}

		$log = $base_log + $this->usage_log($r['calls'], $started) + ['queries' => json_encode($r['queries'])];

		if ($r['stop_reason'] === 'refusal')
		{
			$this->store->update_job($job_id, ['status' => store::SKIPPED, 'reason' => 'Claude declined']);
			$this->store->log($log + ['outcome' => 'refused', 'detail' => 'Claude declined to answer']);
			return 'skipped';
		}
		$filter = new output_filter($this->settings->domains(), max(500, (int) $this->settings->get('max_chars')), $this->settings->bot_name());
		$text = $filter->clean($r['text']);
		if ($text === null)
		{
			$this->store->update_job($job_id, ['status' => store::SKIPPED, 'reason' => 'empty answer']);
			$this->store->log($log + ['outcome' => 'empty', 'detail' => 'nothing to post after filtering (' . $r['stop_reason'] . ')']);
			return 'skipped';
		}

		try
		{
			$reply_id = $this->forum->post_reply($this->settings->bot_user_id(), $q,
				$this->quote($q) . $text . "\n\n" . $this->language->lang('BEYONDBOT_FOOTER'));
		}
		catch (\Throwable $e)
		{
			$this->store->update_job($job_id, ['status' => store::FAILED, 'reason' => 'posting failed']);
			$this->store->log($log + ['outcome' => 'failed', 'detail' => 'posting failed: ' . $e->getMessage()]);
			return 'failed';
		}
		$this->store->update_job($job_id, ['status' => store::DONE, 'reason' => '', 'reply_post_id' => $reply_id]);
		$this->store->log($log + ['outcome' => store::ANSWERED, 'reply_post_id' => $reply_id, 'detail' => (string) $r['stop_reason']]);
		return 'answered';
	}

	/**
	 * Tokens and cost of a list of calls (responder's {model, usage}),
	 * each priced on the model that answered it.
	 */
	protected function usage_log(array $calls, float $started): array
	{
		$row = ['model' => '', 'input_tokens' => 0, 'output_tokens' => 0, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0, 'cost_micro' => 0];
		foreach ($calls as $call)
		{
			$row['model'] = (string) $call['model'];
			$row['input_tokens'] += (int) ($call['usage']['input'] ?? 0);
			$row['output_tokens'] += (int) ($call['usage']['output'] ?? 0);
			$row['cache_write_tokens'] += (int) ($call['usage']['cache_write'] ?? 0);
			$row['cache_read_tokens'] += (int) ($call['usage']['cache_read'] ?? 0);
			$row['cost_micro'] += pricing::cost_micro($call['model'], $call['usage'], $this->settings->custom_prices());
		}
		$row['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
		return $row;
	}

	/**
	 * $log carries the tokens and cost of the calls that succeeded before
	 * the failure: they are paid for, so every path logs them and the
	 * monthly cap counts them, even when the question will be tried again.
	 */
	protected function failed_call(array $job, array $log, gateway_error $e): string
	{
		$job_id = (int) $job['job_id'];
		$now = $this->now();
		$detail = mb_substr($e->getMessage(), 0, 255);
		if ($e->kind === gateway_error::AUTH)
		{
			// Every job would fail the same way: stop, keep the queue, tell
			// the admin once. The stored error holds the bot (limits and the
			// cron task check it) until the admin clears it or saves a new key.
			$this->store->update_job($job_id, ['next_try' => $now + 3600, 'reason' => 'API key or model refused']);
			$this->store->log($log + ['outcome' => 'paused', 'detail' => $detail]);
			if ((string) $this->settings->get('last_error') !== $e->getMessage())
			{
				$this->settings->set_error($e->getMessage());
				$this->log?->add('critical', $this->settings->bot_user_id(), '127.0.0.1', 'LOG_BEYONDBOT_API_ERROR', false, [$e->getMessage()]);
			}
			return 'paused';
		}
		$attempts = (int) $job['attempts'] + 1;
		if ($e->kind === gateway_error::RETRY && $attempts < self::MAX_ATTEMPTS)
		{
			$this->store->update_job($job_id, ['attempts' => $attempts, 'next_try' => $now + 60 * (2 ** $attempts), 'reason' => $detail]);
			$this->store->log($log + ['outcome' => 'retry', 'detail' => $detail]);
			return 'retry';
		}
		$this->store->update_job($job_id, ['attempts' => $attempts, 'status' => store::FAILED, 'reason' => $detail]);
		$this->store->log($log + ['outcome' => 'failed', 'detail' => $e->getMessage()]);
		return 'failed';
	}

	/** A short quote of the question, so readers see what the bot answers. */
	protected function quote(array $q): string
	{
		$words = trim(preg_replace('/\s+/u', ' ', (string) $q['own_words']) ?? '');
		if (mb_strlen($words) > 300)
		{
			$words = mb_substr($words, 0, 300) . '...';
		}
		$words = str_replace(['[', ']'], ['(', ')'], $words);
		$name = str_replace(['"', ']'], ['', ''], (string) $q['username']);
		return '[quote="' . $name . '" post_id=' . (int) $q['post_id'] . ' time=' . (int) $q['post_time'] . ' user_id=' . (int) $q['poster_id'] . ']'
			. $words . "[/quote]\n";
	}

	public static function visibility(int $v): string
	{
		switch ($v)
		{
			case 1:
				return 'approved';
			case 2:
				return 'deleted';
			default:
				return 'unapproved';
		}
	}
}
