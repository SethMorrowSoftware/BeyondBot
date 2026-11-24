<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * Decides whether one queued question may be answered now.
 *
 * Pure: the worker gathers the counts, this class only compares them,
 * so every limit is tested without a database. Checked before any call
 * to Claude; the strictest limit wins.
 *
 * Verdicts:
 *  - ANSWER         go ahead
 *  - SKIP           never answer this one (a reason says why)
 *  - USER_LIMIT     the asker used up their day; post the short note once
 *  - WAIT           not now, maybe later (board limit, spend cap, paused)
 */
class limits
{
	public const ANSWER = 'answer';
	public const SKIP = 'skip';
	public const USER_LIMIT = 'user_limit';
	public const WAIT = 'wait';

	/**
	 * @param array $s settings: enabled, has_key, api_error, has_knowledge, forum_ids[], group_ids[], min_posts,
	 *                 user_daily, topic_max, board_daily, month_cap_micro, blocked_topics[]
	 * @param array $q facts about the question: forum_id, topic_id, topic_locked,
	 *                 asker_is_bot, asker_ignored (banned/inactive/anonymous), asker_group_ids[],
	 *                 asker_posts, visibility ('approved'|'unapproved'|'deleted'), age_seconds
	 * @param array $c counts: user_answers_24h, topic_answers, board_answers_24h, month_spent_micro
	 * @return array{0:string,1:string} verdict and reason
	 */
	public static function decide(array $s, array $q, array $c): array
	{
		// Never-answer cases first: they hold whatever the board's state.
		if (!empty($q['asker_is_bot']))
		{
			return [self::SKIP, 'own post'];
		}
		if (($q['visibility'] ?? '') === 'deleted')
		{
			return [self::SKIP, 'post deleted'];
		}
		if (!in_array((int) $q['forum_id'], array_map('intval', $s['forum_ids'] ?? []), true))
		{
			return [self::SKIP, 'forum not allowed'];
		}
		if (in_array((int) $q['topic_id'], array_map('intval', $s['blocked_topics'] ?? []), true))
		{
			return [self::SKIP, 'topic blocked by a moderator'];
		}
		if (!empty($q['topic_locked']))
		{
			return [self::SKIP, 'topic locked'];
		}
		if (!empty($q['asker_ignored']))
		{
			return [self::SKIP, 'asker banned or inactive'];
		}
		$groups = array_map('intval', $s['group_ids'] ?? []);
		if ($groups && !array_intersect($groups, array_map('intval', $q['asker_group_ids'] ?? [])))
		{
			return [self::SKIP, 'asker not in an allowed group'];
		}
		if ((int) ($q['asker_posts'] ?? 0) < (int) ($s['min_posts'] ?? 0))
		{
			return [self::SKIP, 'asker has too few posts'];
		}
		if ((int) ($q['age_seconds'] ?? 0) > 86400)
		{
			// A question answered days late helps nobody and surprises everyone.
			return [self::SKIP, 'question older than a day'];
		}
		if ((int) ($c['topic_answers'] ?? 0) >= (int) ($s['topic_max'] ?? 0))
		{
			return [self::SKIP, 'topic limit reached'];
		}

		// Wait cases: the question is fine, the moment is not.
		if (($q['visibility'] ?? '') === 'unapproved')
		{
			return [self::WAIT, 'waiting for moderator approval'];
		}
		if (empty($s['enabled']))
		{
			return [self::WAIT, 'bot switched off'];
		}
		if (empty($s['has_key']))
		{
			return [self::WAIT, 'no API key'];
		}
		if (!empty($s['api_error']))
		{
			// The API refused the key, credit or model. Every call would
			// fail the same way, so nothing is sent until the admin
			// clears the error or saves a new key.
			return [self::WAIT, 'paused after an API error'];
		}
		if (empty($s['has_knowledge']))
		{
			return [self::WAIT, 'docs index not loaded yet'];
		}
		if ((int) ($c['month_spent_micro'] ?? 0) >= (int) ($s['month_cap_micro'] ?? 0))
		{
			return [self::WAIT, 'monthly spend cap reached'];
		}
		if ((int) ($c['board_answers_24h'] ?? 0) >= (int) ($s['board_daily'] ?? 0))
		{
			return [self::WAIT, 'board daily limit reached'];
		}

		// The asker's own limit last: the note it triggers is a post too.
		if ((int) ($c['user_answers_24h'] ?? 0) >= (int) ($s['user_daily'] ?? 0))
		{
			return [self::USER_LIMIT, 'asker daily limit reached'];
		}
		return [self::ANSWER, ''];
	}
}
