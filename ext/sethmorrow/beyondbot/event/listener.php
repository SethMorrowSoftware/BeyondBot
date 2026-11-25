<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\event;

use sethmorrow\beyondbot\core\mention;
use sethmorrow\beyondbot\core\settings;
use sethmorrow\beyondbot\core\store;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Notices "@Beyond Bot" in a new or edited post and queues it, and (with
 * bug reports on) queues each new topic in a report-capture forum.
 *
 * Cheap on purpose: no network, no Claude, one SELECT and at most one
 * INSERT, and only for posts in the allowed forums. Everything else
 * (approval, limits, the asker's standing) is checked by the worker,
 * from the database, when the job runs.
 */
class listener implements EventSubscriberInterface
{
	protected settings $settings;
	protected store $store;
	protected $utils;
	protected \phpbb\user $user;

	public function __construct(settings $settings, store $store, $text_formatter_utils, \phpbb\user $user)
	{
		$this->settings = $settings;
		$this->store = $store;
		$this->utils = $text_formatter_utils;
		$this->user = $user;
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.submit_post_end' => 'post_submitted',
		];
	}

	public function post_submitted($event): void
	{
		if (!$this->settings->enabled())
		{
			return;
		}
		$data = $event['data'];
		$forum_id = (int) ($data['forum_id'] ?? 0);
		$answers_here = in_array($forum_id, $this->settings->forum_ids(), true);
		// A new topic (not a reply or an edit) in a forum ticked for bug
		// report capture is read once for a report, mention or not.
		$capture_here = $this->settings->reports_enabled()
			&& ($event['mode'] ?? '') === 'post'
			&& in_array($forum_id, $this->settings->report_forum_ids(), true);
		if (!$answers_here && !$capture_here)
		{
			return;
		}
		$poster = (int) ($data['poster_id'] ?? 0) ?: (int) ($this->user->data['user_id'] ?? 0);
		if ($poster === $this->settings->bot_user_id() || (int) ($this->user->data['user_id'] ?? 0) === $this->settings->bot_user_id())
		{
			return;
		}
		$xml = (string) ($data['message'] ?? '');
		if ($xml === '' || !str_starts_with(ltrim($xml), '<'))
		{
			return;
		}
		// A mention gets an answer (which can also save a report), so it
		// wins; the post's queue row is unique either way.
		if ($answers_here && mention::mentions(mention::own_words($this->utils, $xml), $this->settings->bot_name()))
		{
			$this->store->enqueue((int) $data['post_id'], (int) $data['topic_id'], $forum_id, $poster, time());
			return;
		}
		if ($capture_here)
		{
			$this->store->enqueue((int) $data['post_id'], (int) $data['topic_id'], $forum_id, $poster, time(), store::KIND_REPORT);
		}
	}
}
