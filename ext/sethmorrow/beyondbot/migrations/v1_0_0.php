<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\migrations;

/**
 * First install: four tables, the settings (bot OFF, no forums, no key),
 * the admin panel, and the Beyond Bot member with its "AI helper" rank.
 *
 * Nothing here turns the bot on. An admin must add the key, choose the
 * forums and switch it on in the admin panel.
 */
class v1_0_0 extends \phpbb\db\migration\migration
{
	public const KB_URL = 'https://github.com/SethMorrowSoftware/BeyondBot/releases/download/knowledge/manifest.json';

	public static function depends_on()
	{
		return ['\phpbb\db\migration\data\v330\v330'];
	}

	public function effectively_installed()
	{
		return isset($this->config['beyondbot_kb_gen']);
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'beyondbot_queue' => [
					'COLUMNS' => [
						'job_id' => ['UINT', null, 'auto_increment'],
						'post_id' => ['UINT', 0],
						'topic_id' => ['UINT', 0],
						'forum_id' => ['UINT', 0],
						'user_id' => ['UINT', 0],
						'status' => ['VCHAR:16', ''],
						'attempts' => ['TINT:4', 0],
						'next_try' => ['TIMESTAMP', 0],
						'created' => ['TIMESTAMP', 0],
						'reason' => ['VCHAR_UNI', ''],
						'reply_post_id' => ['UINT', 0],
					],
					'PRIMARY_KEY' => 'job_id',
					'KEYS' => [
						'post_id' => ['UNIQUE', 'post_id'],
						'status_next' => ['INDEX', ['status', 'next_try']],
					],
				],
				$this->table_prefix . 'beyondbot_log' => [
					'COLUMNS' => [
						'log_id' => ['UINT', null, 'auto_increment'],
						'job_id' => ['UINT', 0],
						'post_id' => ['UINT', 0],
						'topic_id' => ['UINT', 0],
						'user_id' => ['UINT', 0],
						'log_time' => ['TIMESTAMP', 0],
						'model' => ['VCHAR:64', ''],
						'input_tokens' => ['UINT', 0],
						'output_tokens' => ['UINT', 0],
						'cache_write_tokens' => ['UINT', 0],
						'cache_read_tokens' => ['UINT', 0],
						'cost_micro' => ['BINT', 0],
						'duration_ms' => ['UINT', 0],
						'queries' => ['MTEXT_UNI', ''],
						'outcome' => ['VCHAR:32', ''],
						'detail' => ['VCHAR_UNI', ''],
						'reply_post_id' => ['UINT', 0],
					],
					'PRIMARY_KEY' => 'log_id',
					'KEYS' => [
						'log_time' => ['INDEX', 'log_time'],
						'user_outcome' => ['INDEX', ['user_id', 'outcome']],
						'topic_outcome' => ['INDEX', ['topic_id', 'outcome']],
					],
				],
				$this->table_prefix . 'beyondbot_chunks' => [
					'COLUMNS' => [
						'gen' => ['TINT:1', 0],
						'chunk_id' => ['UINT', 0],
						'kind' => ['VCHAR:16', ''],
						'title' => ['VCHAR_UNI', ''],
						'lookup_key' => ['VCHAR_UNI:100', ''],
						'url' => ['VCHAR', ''],
						'body' => ['MTEXT_UNI', ''],
					],
					'PRIMARY_KEY' => ['gen', 'chunk_id'],
					'KEYS' => [
						'gen_key' => ['INDEX', ['gen', 'lookup_key']],
					],
				],
				$this->table_prefix . 'beyondbot_terms' => [
					'COLUMNS' => [
						'gen' => ['TINT:1', 0],
						'term' => ['VCHAR:64', ''],
						'chunk_id' => ['UINT', 0],
						'weight' => ['UINT', 0],
					],
					'PRIMARY_KEY' => ['gen', 'term', 'chunk_id'],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'beyondbot_queue',
				$this->table_prefix . 'beyondbot_log',
				$this->table_prefix . 'beyondbot_chunks',
				$this->table_prefix . 'beyondbot_terms',
			],
		];
	}

	public function update_data()
	{
		return [
			['config.add', ['beyondbot_enabled', 0]],
			['config.add', ['beyondbot_forum_ids', '']],
			['config.add', ['beyondbot_user_id', 0]],
			['config.add', ['beyondbot_name', 'Beyond Bot']],
			['config.add', ['beyondbot_model', 'claude-sonnet-5-5']],
			['config.add', ['beyondbot_custom_model', '']],
			['config.add', ['beyondbot_custom_price_in', '0']],
			['config.add', ['beyondbot_custom_price_out', '0']],
			['config.add', ['beyondbot_effort', 'medium']],
			['config.add', ['beyondbot_max_tokens', 8000]],
			['config.add', ['beyondbot_max_chars', 6000]],
			['config.add', ['beyondbot_group_ids', '']],
			['config.add', ['beyondbot_min_posts', 5]],
			['config.add', ['beyondbot_user_daily', 5]],
			['config.add', ['beyondbot_topic_max', 3]],
			['config.add', ['beyondbot_board_daily', 100]],
			['config.add', ['beyondbot_month_cap', '50']],
			['config.add', ['beyondbot_blocked_topics', '']],
			['config.add', ['beyondbot_domains', 'openxtalk.org github.com sethmorrowsoftware.github.io']],
			['config.add', ['beyondbot_api_base', '']],
			['config.add', ['beyondbot_last_error', '']],
			['config.add', ['beyondbot_batch', 3]],
			['config.add', ['beyondbot_run_budget', 90]],
			['config.add', ['beyondbot_queue_last_run', 0, true]],
			['config.add', ['beyondbot_queue_lock', '0', true]],
			['config.add', ['beyondbot_kb_lock', '0', true]],
			['config.add', ['beyondbot_kb_url', self::KB_URL]],
			['config.add', ['beyondbot_kb_gen', 0]],
			['config.add', ['beyondbot_kb_sha', '']],
			['config.add', ['beyondbot_kb_built', '']],
			['config.add', ['beyondbot_kb_release', '']],
			['config.add', ['beyondbot_kb_chunks', 0]],
			['config.add', ['beyondbot_kb_checked', 0]],
			['config.add', ['beyondbot_kb_force', 1]],

			['config_text.add', ['beyondbot_api_key', '']],
			['config_text.add', ['beyondbot_primer', '']],
			['config_text.add', ['beyondbot_extra', '']],
			['config_text.add', ['beyondbot_kb_state', '{"phase":"idle"}']],

			['module.add', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_BEYONDBOT_TITLE']],
			['module.add', ['acp', 'ACP_BEYONDBOT_TITLE', [
				'module_basename' => '\sethmorrow\beyondbot\acp\main_module',
				'modes' => ['settings', 'log'],
			]]],

			['custom', [[$this, 'create_bot_user']]],
		];
	}

	public function revert_data()
	{
		// The Beyond Bot member and its posts stay: deleting the extension
		// must not delete answers members have read and replied to.
		return [
			['config_text.remove', ['beyondbot_api_key']],
			['config_text.remove', ['beyondbot_primer']],
			['config_text.remove', ['beyondbot_extra']],
			['config_text.remove', ['beyondbot_kb_state']],
		];
	}

	/** The REGISTERED group's id, or 0. */
	protected function registered_group(): int
	{
		$sql = 'SELECT group_id FROM ' . $this->table_prefix . "groups WHERE group_name = 'REGISTERED' AND group_type = " . GROUP_SPECIAL;
		$result = $this->db->sql_query($sql);
		$id = (int) $this->db->sql_fetchfield('group_id');
		$this->db->sql_freeresult($result);
		return $id;
	}

	/**
	 * Create (or adopt) the Beyond Bot member: a normal member in Registered
	 * users, with no password (nobody can log in as it) and no e-mail, and
	 * a special rank "AI helper" so every post says what it is.
	 */
	public function create_bot_user()
	{
		$registered = $this->registered_group();
		$this->config->set('beyondbot_group_ids', (string) $registered);

		$name = 'Beyond Bot';
		$clean = utf8_clean_string($name);
		$sql = 'SELECT user_id FROM ' . $this->table_prefix . "users WHERE username_clean = '" . $this->db->sql_escape($clean) . "'";
		$result = $this->db->sql_query($sql);
		$user_id = (int) $this->db->sql_fetchfield('user_id');
		$this->db->sql_freeresult($result);

		// The rank, reused if an admin already made one of this name.
		$rank_title = 'AI helper';
		$sql = 'SELECT rank_id FROM ' . $this->table_prefix . "ranks WHERE rank_title = '" . $this->db->sql_escape($rank_title) . "' AND rank_special = 1";
		$result = $this->db->sql_query($sql);
		$rank_id = (int) $this->db->sql_fetchfield('rank_id');
		$this->db->sql_freeresult($result);
		if (!$rank_id)
		{
			$this->db->sql_query('INSERT INTO ' . $this->table_prefix . 'ranks ' . $this->db->sql_build_array('INSERT', [
				'rank_title' => $rank_title,
				'rank_min' => 0,
				'rank_special' => 1,
				'rank_image' => '',
			]));
			$rank_id = (int) $this->db->sql_nextid();
		}

		if (!$user_id)
		{
			if (!function_exists('user_add'))
			{
				include $this->phpbb_root_path . 'includes/functions_user.' . $this->php_ext;
			}
			$user_id = (int) user_add([
				'username' => $name,
				'user_password' => '',
				'user_email' => '',
				'group_id' => $registered,
				'user_type' => USER_NORMAL,
				'user_rank' => $rank_id,
				'user_regdate' => time(),
				'user_lang' => (string) $this->config['default_lang'],
				'user_allow_pm' => 0,
				'user_allow_viewemail' => 0,
				'user_allow_massemail' => 0,
				'user_notify' => 0,
			]);
		}
		else
		{
			$this->db->sql_query('UPDATE ' . $this->table_prefix . 'users SET user_rank = ' . $rank_id . ' WHERE user_id = ' . $user_id);
		}
		$this->config->set('beyondbot_user_id', $user_id);
	}
}
