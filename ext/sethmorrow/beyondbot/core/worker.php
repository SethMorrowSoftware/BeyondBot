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
	protected ?reports $reports;

	public function __construct(settings $settings, store $store, forum $forum, knowledge $knowledge,
		\phpbb\language\language $language, ?\phpbb\log\log_interface $log, callable $gateway_factory, ?callable $clock = null, ?reports $reports = null)
	{
		$this->reports = $reports;
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
		if (($job['kind'] ?? '') === store::KIND_REPORT)
		{
			return $this->capture($job, $q);
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
		$board_tags = $this->forum->board_tags();
		$responder = new responder(
			($this->gateway_factory)($this->settings->api_key(), (string) $this->settings->get('api_base')),
			$this->knowledge,
			[
				'model' => $this->settings->model(),
				'effort' => (string) ($this->settings->get('effort') ?: 'medium'),
				'max_tokens' => max(1024, (int) $this->settings->get('max_tokens')),
				'system' => $this->settings->system_prompt($board_tags),
				'reporter' => $this->reporter($q, 'mention'),
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
		$filter = new output_filter($this->settings->domains(), max(500, (int) $this->settings->get('max_chars')), $this->settings->bot_name(), $board_tags);
		$text = $filter->clean($r['text']);
		if ($text === null)
		{
			// The member asked and the calls were paid for: say so in the
			// topic rather than leave them waiting for a reply that never
			// comes. The note has no footer: it is not an answer.
			try
			{
				$reply_id = $this->forum->post_reply($this->settings->bot_user_id(), $q,
					$this->quote($q) . $this->language->lang('BEYONDBOT_NO_ANSWER'));
			}
			catch (\Throwable $e)
			{
				$reply_id = 0;
			}
			$this->store->update_job($job_id, ['status' => store::SKIPPED, 'reason' => 'empty answer', 'reply_post_id' => $reply_id]);
			$this->store->log($log + ['outcome' => 'empty', 'reply_post_id' => $reply_id, 'detail' => self::ending($r) . '; nothing to post after filtering; ' . ($reply_id ? 'a short note was posted' : 'the note could not be posted')]);
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
		$this->store->log($log + ['outcome' => store::ANSWERED, 'reply_post_id' => $reply_id, 'detail' => self::ending($r)]);
		return 'answered';
	}

	/**
	 * The callable that saves a report for this question's topic, or null
	 * when reports are off (then Claude never sees the tool).
	 */
	protected function reporter(array $q, string $source): ?callable
	{
		if (!$this->reports || !$this->settings->reports_enabled())
		{
			return null;
		}
		return function (array $input) use ($q, $source): string {
			$fields = reports::clean($input);
			if ($fields === null)
			{
				return 'Not saved: a report needs a title and a summary.';
			}
			$r = $this->reports->save($fields, [
				'topic_id' => (int) $q['topic_id'],
				'post_id' => (int) $q['post_id'],
				'forum_id' => (int) $q['forum_id'],
				'user_id' => (int) $q['poster_id'],
				'source' => $source,
			], $this->now(), $this->settings->reports_daily());
			if (isset($r['error']))
			{
				return 'Not saved: ' . $r['error'] . '. Answer the member as usual.';
			}
			return ($r['updated'] ? 'Updated report #' : 'Saved as report #') . $r['id']
				. ($r['dup_of'] ? ' (it may repeat report #' . $r['dup_of'] . ')' : '')
				. '. The maintainers will see it. Now answer the member as usual, without mentioning the report.';
		};
	}

	/**
	 * A new topic in a bug forum, read once for a report. No reply is
	 * posted. The member limits do not apply (nobody asked the bot), but
	 * the switch, the key, the spend cap and the daily report limit do.
	 */
	protected function capture(array $job, array $q): string
	{
		$job_id = (int) $job['job_id'];
		$now = $this->now();
		$skip = function (string $reason) use ($job_id): string {
			$this->store->update_job($job_id, ['status' => store::SKIPPED, 'reason' => $reason]);
			return 'skipped';
		};
		if (!$this->reports || !$this->settings->reports_enabled() || !$this->settings->enabled())
		{
			return $skip('reports are off');
		}
		if (!in_array((int) $q['forum_id'], $this->settings->report_forum_ids(), true))
		{
			return $skip('not a report forum any more');
		}
		$visibility = self::visibility((int) $q['post_visibility']);
		if ($visibility === 'deleted')
		{
			return $skip('post deleted');
		}
		if ($visibility === 'unapproved')
		{
			$this->store->update_job($job_id, ['next_try' => $now + 600, 'reason' => 'waiting for moderator approval']);
			return 'waiting';
		}
		if ($this->settings->api_key() === '' || (string) $this->settings->get('last_error') !== '')
		{
			$this->store->update_job($job_id, ['next_try' => $now + 3600, 'reason' => 'no working API key']);
			return 'waiting';
		}
		$cap = $this->settings->for_limits()['month_cap_micro'];
		if ($this->store->month_spent($now) >= $cap)
		{
			$this->store->update_job($job_id, ['next_try' => $now + 3600, 'reason' => 'monthly spend cap reached']);
			return 'waiting';
		}
		if ($this->reports->for_topic((int) $q['topic_id']) === null && $this->reports->count_since($now - 86400) >= $this->settings->reports_daily())
		{
			return $skip('daily report limit reached');
		}

		$started = microtime(true);
		$base_log = ['job_id' => $job_id, 'post_id' => (int) $q['post_id'], 'topic_id' => (int) $q['topic_id'], 'user_id' => (int) $q['poster_id'], 'log_time' => $now];
		$responder = new responder(
			($this->gateway_factory)($this->settings->api_key(), (string) $this->settings->get('api_base')),
			$this->knowledge,
			[
				'model' => $this->settings->report_model(),
				'effort' => 'low',
				'max_tokens' => 2000,
				'system' => prompt::CAPTURE_SYSTEM,
				'reporter' => $this->reporter($q, 'forum'),
			]
		);
		$turn = prompt::capture_turn(
			['title' => $q['topic_title'], 'forum_name' => $q['forum_name']],
			['post_id' => (int) $q['post_id'], 'author' => $q['username'], 'text' => $q['text']]
		);
		try
		{
			$r = $responder->capture($turn);
		}
		catch (gateway_error $e)
		{
			return $this->failed_call($job, $base_log + $this->usage_log([], $started), $e);
		}
		$log = $base_log + $this->usage_log($r['calls'], $started);
		$this->store->update_job($job_id, ['status' => $r['reported'] ? store::DONE : store::SKIPPED, 'reason' => $r['reported'] ? '' : 'not a bug report']);
		$this->store->log($log + ['outcome' => $r['reported'] ? 'report' : 'no_report', 'detail' => $r['reported'] ? $r['said'] : 'read; not a bug report']);
		return $r['reported'] ? 'reported' : 'skipped';
	}

	/** How the last call ended, for the log: "end_turn; thinking,text; 1520 chars", plus "after a retry". */
	protected static function ending(array $r): string
	{
		return (string) ($r['stop_reason'] ?? '') . '; ' . ($r['blocks'] ?? '') . '; ' . mb_strlen((string) ($r['text'] ?? '')) . ' chars'
			. (!empty($r['recovered']) ? '; after a retry' : '');
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

	/**
	 * A short quote of the question, so readers see what the bot answers
	 * once the topic has moved on. When the question is still the last post,
	 * the reply sits right under it and a quote would only repeat it.
	 */
	protected function quote(array $q): string
	{
		if (!$this->forum->has_later_posts((int) $q['topic_id'], (int) $q['post_id']))
		{
			return '';
		}
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
