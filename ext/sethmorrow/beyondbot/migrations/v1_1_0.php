<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\migrations;

/**
 * 1.1.0: bug reports.
 *
 * A table of reports the bot saved, a "kind" on the queue (an answer, or
 * a report to capture from a new topic in a bug forum), the settings,
 * and the ACP page. Reports are on, auto-capture is off (no forums
 * ticked) and nothing is sent anywhere: an admin turns a report into a
 * GitHub issue by hand, from the ACP page.
 */
class v1_1_0 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\sethmorrow\beyondbot\migrations\v1_0_0'];
	}

	public function effectively_installed()
	{
		return isset($this->config['beyondbot_reports_enabled']);
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'beyondbot_reports' => [
					'COLUMNS' => [
						'report_id' => ['UINT', null, 'auto_increment'],
						'topic_id' => ['UINT', 0],
						'post_id' => ['UINT', 0],
						'forum_id' => ['UINT', 0],
						'user_id' => ['UINT', 0],
						'source' => ['VCHAR:16', ''],
						'created' => ['TIMESTAMP', 0],
						'updated' => ['TIMESTAMP', 0],
						'status' => ['VCHAR:16', 'new'],
						'component' => ['VCHAR_UNI:64', ''],
						'title' => ['VCHAR_UNI', ''],
						'summary' => ['MTEXT_UNI', ''],
						'steps' => ['MTEXT_UNI', ''],
						'expected' => ['TEXT_UNI', ''],
						'actual' => ['TEXT_UNI', ''],
						'platform' => ['VCHAR_UNI:100', ''],
						'version' => ['VCHAR_UNI:64', ''],
						'note' => ['TEXT_UNI', ''],
						'dup_of' => ['UINT', 0],
					],
					'PRIMARY_KEY' => 'report_id',
					'KEYS' => [
						'topic_id' => ['UNIQUE', 'topic_id'],
						'status' => ['INDEX', 'status'],
						'created' => ['INDEX', 'created'],
					],
				],
			],
			'add_columns' => [
				$this->table_prefix . 'beyondbot_queue' => [
					'kind' => ['VCHAR:16', ''],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_columns' => [
				$this->table_prefix . 'beyondbot_queue' => ['kind'],
			],
			'drop_tables' => [
				$this->table_prefix . 'beyondbot_reports',
			],
		];
	}

	public function update_data()
	{
		return [
			['config.add', ['beyondbot_reports_enabled', 1]],
			['config.add', ['beyondbot_reports_daily', 20]],
			['config.add', ['beyondbot_report_forum_ids', '']],
			['config.add', ['beyondbot_report_model', 'claude-haiku-5-5']],
			['config.add', ['beyondbot_report_repo', 'SethMorrowSoftware/OpenXTalk-Beyond']],

			['module.add', ['acp', 'ACP_BEYONDBOT_TITLE', [
				'module_basename' => '\sethmorrow\beyondbot\acp\main_module',
				'modes' => ['reports'],
			]]],
		];
	}
}
