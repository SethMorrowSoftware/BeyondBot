<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\acp;

use sethmorrow\beyondbot\core\pricing;
use sethmorrow\beyondbot\core\settings;
use sethmorrow\beyondbot\core\store;

/**
 * The admin panel: settings and the answer log.
 *
 * The API key is write-only here: the page shows its last four
 * characters, never the key, and an empty box on save keeps the old one.
 */
class main_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

	public function main($id, $mode)
	{
		global $phpbb_container;

		$language = $phpbb_container->get('language');
		$language->add_lang('common', 'sethmorrow/beyondbot');
		$language->add_lang('acp', 'sethmorrow/beyondbot');

		if ($mode === 'log')
		{
			$this->log_page($phpbb_container, $language);
			return;
		}
		$this->settings_page($phpbb_container, $language);
	}

	protected function settings_page($c, $language)
	{
		global $phpbb_root_path, $phpEx;

		$config = $c->get('config');
		$config_text = $c->get('config_text');
		$request = $c->get('request');
		$template = $c->get('template');
		$user = $c->get('user');
		$phpbb_log = $c->get('log');
		/** @var store $store */
		$store = $c->get('sethmorrow.beyondbot.store');

		$this->tpl_name = '@sethmorrow_beyondbot/acp_beyondbot_settings';
		$this->page_title = $language->lang('ACP_BEYONDBOT_SETTINGS');
		add_form_key('sethmorrow_beyondbot');
		$errors = [];

		if ($request->is_set_post('update_docs') || $request->is_set_post('clear_error') || $request->is_set_post('submit'))
		{
			if (!check_form_key('sethmorrow_beyondbot'))
			{
				trigger_error($language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}
		}
		if ($request->is_set_post('update_docs'))
		{
			$config->set('beyondbot_kb_force', 1);
			trigger_error($language->lang('ACP_BEYONDBOT_DOCS_QUEUED') . adm_back_link($this->u_action));
		}
		if ($request->is_set_post('clear_error'))
		{
			$config->set('beyondbot_last_error', '');
			trigger_error($language->lang('ACP_BEYONDBOT_ERROR_CLEARED') . adm_back_link($this->u_action));
		}

		if ($request->is_set_post('submit'))
		{
			$model = $request->variable('beyondbot_model', 'claude-sonnet-5-5');
			if (!isset(pricing::CHOICES[$model]))
			{
				$model = 'claude-sonnet-5-5';
			}
			$custom_model = trim($request->variable('beyondbot_custom_model', ''));
			if ($custom_model !== '' && !preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $custom_model))
			{
				$errors[] = $language->lang('ACP_BEYONDBOT_BAD_MODEL');
			}
			$effort = $request->variable('beyondbot_effort', 'medium');
			if (!in_array($effort, self::EFFORTS, true))
			{
				$effort = 'medium';
			}
			$kb_url = trim($request->variable('beyondbot_kb_url', ''));
			if ($kb_url !== '' && !preg_match('#^https://[^\s]+/manifest\.json$#', $kb_url))
			{
				$errors[] = $language->lang('ACP_BEYONDBOT_BAD_KB_URL');
			}
			$api_base = trim($request->variable('beyondbot_api_base', ''));
			if ($api_base !== '' && !preg_match('#^https?://[^\s]+$#', $api_base))
			{
				$errors[] = $language->lang('ACP_BEYONDBOT_BAD_API_BASE');
			}
			$forum_ids = array_map('intval', $request->variable('beyondbot_forum_ids', [0]));
			$group_ids = array_map('intval', $request->variable('beyondbot_group_ids', [0]));
			$enabled = $request->variable('beyondbot_enabled', 0);
			$new_key = trim($request->variable('beyondbot_api_key', '', true));

			if ($new_key !== '' && !preg_match('/^[A-Za-z0-9_\-]{20,300}$/', $new_key))
			{
				$errors[] = $language->lang('ACP_BEYONDBOT_BAD_KEY');
			}

			if (!$errors)
			{
				$config->set('beyondbot_enabled', $enabled ? 1 : 0);
				$config->set('beyondbot_forum_ids', implode(',', array_filter(array_unique($forum_ids))));
				$config->set('beyondbot_group_ids', implode(',', array_filter(array_unique($group_ids))));
				$config->set('beyondbot_model', $model);
				$config->set('beyondbot_custom_model', $custom_model);
				$config->set('beyondbot_custom_price_in', (string) max(0, (float) $request->variable('beyondbot_custom_price_in', '0')));
				$config->set('beyondbot_custom_price_out', (string) max(0, (float) $request->variable('beyondbot_custom_price_out', '0')));
				$config->set('beyondbot_effort', $effort);
				$config->set('beyondbot_max_tokens', min(64000, max(1024, $request->variable('beyondbot_max_tokens', 8000))));
				$config->set('beyondbot_max_chars', min(60000, max(500, $request->variable('beyondbot_max_chars', 6000))));
				$config->set('beyondbot_min_posts', max(0, $request->variable('beyondbot_min_posts', 5)));
				$config->set('beyondbot_user_daily', max(0, $request->variable('beyondbot_user_daily', 5)));
				$config->set('beyondbot_topic_max', max(0, $request->variable('beyondbot_topic_max', 3)));
				$config->set('beyondbot_board_daily', max(0, $request->variable('beyondbot_board_daily', 100)));
				$config->set('beyondbot_month_cap', (string) max(0, (float) $request->variable('beyondbot_month_cap', '50')));
				$config->set('beyondbot_blocked_topics', implode(',', settings::id_list($request->variable('beyondbot_blocked_topics', ''))));
				$config->set('beyondbot_domains', implode(' ', array_filter(preg_split('/[\s,]+/', strtolower($request->variable('beyondbot_domains', ''))))));
				$config->set('beyondbot_kb_url', $kb_url);
				$config->set('beyondbot_api_base', $api_base);
				$config_text->set('beyondbot_extra', $request->variable('beyondbot_extra', '', true));
				if ($new_key !== '')
				{
					$config_text->set('beyondbot_api_key', $new_key);
					$config->set('beyondbot_last_error', '');
				}
				if ($request->variable('beyondbot_remove_key', 0))
				{
					$config_text->set('beyondbot_api_key', '');
				}
				$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_BEYONDBOT_SETTINGS');
				trigger_error($language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
			}
		}

		$key = trim((string) $config_text->get('beyondbot_api_key'));
		$forum_ids = settings::id_list((string) $config['beyondbot_forum_ids']);
		$group_ids = settings::id_list((string) $config['beyondbot_group_ids']);

		if (!function_exists('make_forum_select'))
		{
			include $phpbb_root_path . 'includes/functions_admin.' . $phpEx;
		}
		$models = '';
		foreach (pricing::CHOICES as $value => $label)
		{
			$models .= '<option value="' . $value . '"' . ($value === $config['beyondbot_model'] ? ' selected="selected"' : '') . '>' . htmlspecialchars($label) . '</option>';
		}
		$efforts = '';
		foreach (self::EFFORTS as $e)
		{
			$efforts .= '<option value="' . $e . '"' . ($e === $config['beyondbot_effort'] ? ' selected="selected"' : '') . '>' . $language->lang('ACP_BEYONDBOT_EFFORT_' . strtoupper($e)) . '</option>';
		}

		$now = time();
		$spent = $store->month_spent($now);
		$bot_id = (int) $config['beyondbot_user_id'];
		$template->assign_vars([
			'ERRORS' => $errors ? implode('<br>', $errors) : '',
			'U_ACTION' => $this->u_action,
			'BEYONDBOT_ENABLED' => (bool) $config['beyondbot_enabled'],
			'BEYONDBOT_KEY_SET' => $key !== '',
			'BEYONDBOT_KEY_HINT' => $key !== '' ? '...' . substr($key, -4) : '',
			'S_FORUM_OPTIONS' => make_forum_select($forum_ids, false, false, true, true, false, false),
			'S_GROUP_OPTIONS' => $this->group_options($c, $group_ids),
			'S_MODEL_OPTIONS' => $models,
			'S_EFFORT_OPTIONS' => $efforts,
			'BEYONDBOT_CUSTOM_MODEL' => $config['beyondbot_custom_model'],
			'BEYONDBOT_CUSTOM_PRICE_IN' => $config['beyondbot_custom_price_in'],
			'BEYONDBOT_CUSTOM_PRICE_OUT' => $config['beyondbot_custom_price_out'],
			'BEYONDBOT_MAX_TOKENS' => (int) $config['beyondbot_max_tokens'],
			'BEYONDBOT_MAX_CHARS' => (int) $config['beyondbot_max_chars'],
			'BEYONDBOT_MIN_POSTS' => (int) $config['beyondbot_min_posts'],
			'BEYONDBOT_USER_DAILY' => (int) $config['beyondbot_user_daily'],
			'BEYONDBOT_TOPIC_MAX' => (int) $config['beyondbot_topic_max'],
			'BEYONDBOT_BOARD_DAILY' => (int) $config['beyondbot_board_daily'],
			'BEYONDBOT_MONTH_CAP' => $config['beyondbot_month_cap'],
			'BEYONDBOT_BLOCKED_TOPICS' => $config['beyondbot_blocked_topics'],
			'BEYONDBOT_DOMAINS' => $config['beyondbot_domains'],
			'BEYONDBOT_KB_URL' => $config['beyondbot_kb_url'],
			'BEYONDBOT_API_BASE' => $config['beyondbot_api_base'],
			'BEYONDBOT_EXTRA' => (string) $config_text->get('beyondbot_extra'),

			'STATUS_SPENT' => pricing::dollars($spent),
			'STATUS_CAP' => '$' . number_format((float) $config['beyondbot_month_cap'], 2),
			'STATUS_PENDING' => $store->pending_count($now + 86400),
			'STATUS_KB_CHUNKS' => (int) $config['beyondbot_kb_chunks'],
			'STATUS_KB_BUILT' => (string) $config['beyondbot_kb_built'],
			'STATUS_KB_RELEASE' => (string) $config['beyondbot_kb_release'],
			'STATUS_KB_STATE' => (string) (json_decode((string) $config_text->get('beyondbot_kb_state'), true)['phase'] ?? 'idle'),
			'STATUS_LAST_ERROR' => (string) $config['beyondbot_last_error'],
			'STATUS_BOT_NAME' => (string) $config['beyondbot_name'],
			'U_BOT_PROFILE' => $bot_id ? append_sid("{$phpbb_root_path}memberlist.$phpEx", 'mode=viewprofile&amp;u=' . $bot_id) : '',
			'STATUS_CRON_LAST' => (int) $config['beyondbot_queue_last_run'] ? $user->format_date((int) $config['beyondbot_queue_last_run']) : '',
		]);
	}

	protected function group_options($c, array $selected): string
	{
		$db = $c->get('dbal.conn');
		$language = $c->get('language');
		$result = $db->sql_query('SELECT group_id, group_name, group_type FROM ' . GROUPS_TABLE . ' ORDER BY group_type DESC, group_name ASC');
		$out = '';
		while ($row = $db->sql_fetchrow($result))
		{
			if ($row['group_name'] === 'GUESTS' || $row['group_name'] === 'BOTS')
			{
				continue;
			}
			$name = (int) $row['group_type'] === GROUP_SPECIAL ? $language->lang('G_' . $row['group_name']) : $row['group_name'];
			$out .= '<option value="' . (int) $row['group_id'] . '"' . (in_array((int) $row['group_id'], $selected, true) ? ' selected="selected"' : '') . '>' . htmlspecialchars($name, ENT_COMPAT, 'UTF-8') . '</option>';
		}
		$db->sql_freeresult($result);
		return $out;
	}

	protected function log_page($c, $language)
	{
		global $phpbb_root_path, $phpEx;

		$request = $c->get('request');
		$template = $c->get('template');
		$user = $c->get('user');
		$pagination = $c->get('pagination');
		/** @var store $store */
		$store = $c->get('sethmorrow.beyondbot.store');

		$this->tpl_name = '@sethmorrow_beyondbot/acp_beyondbot_log';
		$this->page_title = $language->lang('ACP_BEYONDBOT_LOG');

		$per_page = 50;
		$start = max(0, $request->variable('start', 0));
		$total = $store->log_total();
		$names = [];
		$rows = $store->recent_log($per_page, $start);
		$ids = array_unique(array_filter(array_map(static fn ($r) => (int) $r['user_id'], $rows)));
		if ($ids)
		{
			$db = $c->get('dbal.conn');
			$result = $db->sql_query('SELECT user_id, username FROM ' . USERS_TABLE . ' WHERE ' . $db->sql_in_set('user_id', $ids));
			while ($u = $db->sql_fetchrow($result))
			{
				$names[(int) $u['user_id']] = $u['username'];
			}
			$db->sql_freeresult($result);
		}
		foreach ($rows as $r)
		{
			$queries = json_decode((string) $r['queries'], true);
			$template->assign_block_vars('log', [
				'TIME' => $user->format_date((int) $r['log_time']),
				'ASKER' => $names[(int) $r['user_id']] ?? '',
				'U_POST' => $r['post_id'] ? append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'p=' . (int) $r['post_id']) . '#p' . (int) $r['post_id'] : '',
				'U_REPLY' => $r['reply_post_id'] ? append_sid("{$phpbb_root_path}viewtopic.$phpEx", 'p=' . (int) $r['reply_post_id']) . '#p' . (int) $r['reply_post_id'] : '',
				'OUTCOME' => $r['outcome'],
				'DETAIL' => $r['detail'],
				'MODEL' => $r['model'],
				'TOKENS' => $r['model'] ? sprintf('%d in, %d cached, %d written to cache, %d out', $r['input_tokens'], $r['cache_read_tokens'], $r['cache_write_tokens'], $r['output_tokens']) : '',
				'COST' => $r['model'] ? pricing::dollars((int) $r['cost_micro']) : '',
				'SECONDS' => $r['duration_ms'] ? number_format($r['duration_ms'] / 1000, 1) : '',
				'QUERIES' => is_array($queries) ? implode(' | ', $queries) : '',
			]);
		}
		$pagination->generate_template_pagination($this->u_action, 'pagination', 'start', $total, $per_page, $start);
		$template->assign_vars([
			'TOTAL' => $total,
			'MONTH_SPENT' => pricing::dollars($store->month_spent(time())),
		]);
	}
}
