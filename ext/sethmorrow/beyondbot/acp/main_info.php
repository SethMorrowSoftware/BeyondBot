<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\acp;

class main_info
{
	public function module()
	{
		return [
			'filename' => '\sethmorrow\beyondbot\acp\main_module',
			'title' => 'ACP_BEYONDBOT_TITLE',
			'modes' => [
				'settings' => [
					'title' => 'ACP_BEYONDBOT_SETTINGS',
					'auth' => 'ext_sethmorrow/beyondbot && acl_a_board',
					'cat' => ['ACP_BEYONDBOT_TITLE'],
				],
				'reports' => [
					'title' => 'ACP_BEYONDBOT_REPORTS',
					'auth' => 'ext_sethmorrow/beyondbot && acl_a_board',
					'cat' => ['ACP_BEYONDBOT_TITLE'],
				],
				'log' => [
					'title' => 'ACP_BEYONDBOT_LOG',
					'auth' => 'ext_sethmorrow/beyondbot && acl_a_viewlogs',
					'cat' => ['ACP_BEYONDBOT_TITLE'],
				],
			],
		];
	}
}
