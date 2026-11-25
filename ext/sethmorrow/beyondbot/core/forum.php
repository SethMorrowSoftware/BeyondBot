<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * Everything that touches phpBB's own posts and users.
 *
 * Reading goes through plain SELECTs on the board's tables; posting goes
 * through phpBB's own submit_post(), so a bot reply is an ordinary post:
 * counted, indexed for search, sent to topic subscribers, and editable
 * and deletable by moderators like any other.
 */
class forum
{
	protected \phpbb\db\driver\driver_interface $db;
	protected \phpbb\user $user;
	protected \phpbb\auth\auth $auth;
	protected $utils;
	protected string $root_path;
	protected string $php_ext;

	public function __construct(\phpbb\db\driver\driver_interface $db, \phpbb\user $user, \phpbb\auth\auth $auth,
		$text_formatter_utils, string $root_path, string $php_ext)
	{
		$this->db = $db;
		$this->user = $user;
		$this->auth = $auth;
		$this->utils = $text_formatter_utils;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * The post that called the bot, with its topic, forum and author.
	 */
	public function question(int $post_id): ?array
	{
		$sql = 'SELECT p.post_id, p.topic_id, p.forum_id, p.poster_id, p.post_visibility, p.post_time,
				p.post_text, p.bbcode_uid, t.topic_title, t.topic_status, t.topic_first_post_id,
				f.forum_name, u.username, u.user_type, u.user_posts, u.user_colour
			FROM ' . POSTS_TABLE . ' p
			JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = p.topic_id
			JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = p.forum_id
			JOIN ' . USERS_TABLE . ' u ON u.user_id = p.poster_id
			WHERE p.post_id = ' . $post_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$row)
		{
			return null;
		}
		$row['text'] = $this->source_text((string) $row['post_text'], (string) $row['bbcode_uid']);
		$row['own_words'] = str_starts_with(ltrim((string) $row['post_text']), '<')
			? mention::own_words($this->utils, (string) $row['post_text'])
			: $row['text'];
		return $row;
	}

	/** @return int[] the groups a member belongs to (not pending) */
	public function groups(int $user_id): array
	{
		$result = $this->db->sql_query('SELECT group_id FROM ' . USER_GROUP_TABLE . ' WHERE user_pending = 0 AND user_id = ' . $user_id);
		$ids = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$ids[] = (int) $row['group_id'];
		}
		$this->db->sql_freeresult($result);
		return $ids;
	}

	public function is_banned(int $user_id): bool
	{
		return (bool) $this->user->check_ban($user_id, false, false, true);
	}

	/**
	 * The topic's first post and up to $latest approved posts before the question,
	 * oldest first.
	 *
	 * @return list<array{post_id:int,author:string,time:int,text:string}>
	 */
	public function context(int $topic_id, int $first_post_id, int $question_post_id, int $latest = 8): array
	{
		$sql = 'SELECT p.post_id, p.post_time, p.post_text, p.bbcode_uid, p.post_username, u.username
			FROM ' . POSTS_TABLE . ' p
			LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = p.poster_id
			WHERE p.topic_id = ' . $topic_id . '
				AND p.post_visibility = ' . ITEM_APPROVED . '
				AND p.post_id < ' . $question_post_id . '
				AND p.post_id <> ' . $first_post_id . '
			ORDER BY p.post_time DESC, p.post_id DESC';
		$result = $this->db->sql_query_limit($sql, $latest);
		$rows = array_reverse($this->db->sql_fetchrowset($result) ?: []);
		$this->db->sql_freeresult($result);

		if ($first_post_id && $first_post_id !== $question_post_id)
		{
			$sql = 'SELECT p.post_id, p.post_time, p.post_text, p.bbcode_uid, p.post_username, u.username
				FROM ' . POSTS_TABLE . ' p
				LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = p.poster_id
				WHERE p.post_id = ' . $first_post_id . '
					AND p.post_visibility = ' . ITEM_APPROVED;
			$result = $this->db->sql_query($sql);
			$first = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if ($first)
			{
				array_unshift($rows, $first);
			}
		}

		$out = [];
		foreach ($rows as $r)
		{
			$out[] = [
				'post_id' => (int) $r['post_id'],
				'author' => (string) ($r['username'] ?: $r['post_username']),
				'time' => (int) $r['post_time'],
				'text' => $this->source_text((string) $r['post_text'], (string) $r['bbcode_uid']),
			];
		}
		return $out;
	}

	/**
	 * The board's own BBCodes this extension knows how to use: [dict] from
	 * the OXT stack tools extension, only in the form it checks
	 * ([dict]{SIMPLETEXT}[/dict]), so a different tag of that name is left alone.
	 *
	 * @return string[]
	 */
	public function board_tags(): array
	{
		$result = $this->db->sql_query('SELECT bbcode_tag, bbcode_match FROM ' . BBCODES_TABLE . " WHERE bbcode_tag = 'dict'");
		$tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			if (preg_replace('/\s+/', '', strtolower((string) $row['bbcode_match'])) === '[dict]{simpletext}[/dict]')
			{
				$tags[] = 'dict';
			}
		}
		$this->db->sql_freeresult($result);
		return $tags;
	}

	/** Whether any visible post came after this one in its topic. */
	public function has_later_posts(int $topic_id, int $post_id): bool
	{
		$result = $this->db->sql_query_limit('SELECT post_id FROM ' . POSTS_TABLE . '
			WHERE topic_id = ' . $topic_id . '
				AND post_visibility = ' . ITEM_APPROVED . '
				AND post_id > ' . $post_id, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return (bool) $row;
	}

	/** A stored post back to the BBCode its author typed. */
	public function source_text(string $stored, string $uid = ''): string
	{
		if (str_starts_with(ltrim($stored), '<'))
		{
			return html_entity_decode($this->utils->unparse($stored), ENT_QUOTES, 'UTF-8');
		}
		// A post from before phpBB 3.2 that the reparser has not reached yet.
		if ($uid !== '')
		{
			$stored = str_replace(':' . $uid, '', $stored);
		}
		$stored = preg_replace('#<!-- [a-z] --><a [^>]*href="([^"]*)"[^>]*>.*?</a><!-- [a-z] -->#i', '$1', $stored) ?? $stored;
		$stored = preg_replace('#<!-- s(.*?) --><img [^>]*><!-- s\1 -->#', '$1', $stored) ?? $stored;
		return html_entity_decode(strip_tags($stored), ENT_QUOTES, 'UTF-8');
	}

	public function user_row(int $user_id): ?array
	{
		$result = $this->db->sql_query('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id = ' . $user_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ?: null;
	}

	/**
	 * Post a reply as the bot, through phpBB's own submit_post().
	 *
	 * submit_post() works on the global user, so the bot's identity is
	 * put on for the call and the previous one restored after, whatever
	 * happens. BBCode yes; magic URLs, smilies, [img] and [flash] no.
	 *
	 * @return int the new post's id
	 */
	public function post_reply(int $bot_user_id, array $question, string $text): int
	{
		if (!function_exists('submit_post'))
		{
			include_once $this->root_path . 'includes/functions_posting.' . $this->php_ext;
		}
		if (!function_exists('generate_text_for_storage'))
		{
			include_once $this->root_path . 'includes/functions_content.' . $this->php_ext;
		}
		$bot = $this->user_row($bot_user_id);
		if (!$bot)
		{
			throw new \RuntimeException('The Beyond Bot user (id ' . $bot_user_id . ') does not exist.');
		}

		$saved_data = $this->user->data;
		$saved_ip = $this->user->ip ?? '';
		$saved_page = $this->user->page;
		try
		{
			// From the system cron (phpbbcli) there is no web session, so
			// the global user lacks the keys a session sets; submit_post()
			// and other extensions' posting listeners read them, and PHP
			// logged a warning for each missing one on every answer.
			$this->user->data = array_merge(is_array($saved_data) ? $saved_data : [], $bot, [
				'is_registered' => true,
				'is_bot' => false,
			]);
			$this->user->ip = $saved_ip !== '' ? $saved_ip : '127.0.0.1';
			$this->user->page = (is_array($saved_page) ? $saved_page : []) + [
				'page_name' => 'posting.' . $this->php_ext,
				'page_dir' => '',
				'query_string' => '',
				'script_path' => '/',
				'root_script_path' => '/',
				'page' => 'posting.' . $this->php_ext,
				'forum' => (int) $question['forum_id'],
			];
			$this->auth->acl($this->user->data);

			$text = utf8_normalize_nfc($text);
			$uid = $bitfield = '';
			$flags = 0;
			generate_text_for_storage($text, $uid, $bitfield, $flags, true, false, false, false, false, true, true);

			$subject = 'Re: ' . $question['topic_title'];
			$data = [
				'forum_id' => (int) $question['forum_id'],
				'topic_id' => (int) $question['topic_id'],
				'forum_name' => $question['forum_name'],
				'topic_title' => $question['topic_title'],
				'icon_id' => 0,
				'enable_bbcode' => true,
				'enable_smilies' => false,
				'enable_urls' => false,
				'enable_sig' => true,
				'message' => $text,
				'message_md5' => md5($text),
				'bbcode_bitfield' => $bitfield,
				'bbcode_uid' => $uid,
				'post_edit_locked' => 0,
				'notify_set' => false,
				'notify' => false,
				'post_time' => 0,
				'enable_indexing' => true,
				'force_approved_state' => ITEM_APPROVED,
				'force_visibility' => ITEM_APPROVED,
				'attachment_data' => [],
				'filename_data' => [],
			];
			$poll = [];
			submit_post('reply', $subject, $bot['username'], POST_NORMAL, $poll, $data);
			return (int) ($data['post_id'] ?? 0);
		}
		finally
		{
			$this->user->data = $saved_data;
			$this->user->ip = $saved_ip;
			$this->user->page = $saved_page;
			if (is_array($saved_data) && isset($saved_data['user_permissions'], $saved_data['user_type']))
			{
				$this->auth->acl($saved_data);
			}
			else
			{
				// No session user to go back to (the system cron): drop the
				// bot's permissions rather than rebuild them for nobody.
				$this->auth->acl = [];
			}
		}
	}
}
