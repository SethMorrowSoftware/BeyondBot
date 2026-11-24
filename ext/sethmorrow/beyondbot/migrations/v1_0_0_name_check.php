<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\migrations;

/**
 * Runs before anything is installed: stops the install if a member
 * named Beyond Bot already exists that someone can log in to.
 *
 * It is its own migration, with no tables and no settings, so a refusal
 * leaves the board exactly as it was (phpBB would otherwise have to undo
 * half an install). v1_0_0 makes the same check again when it creates
 * the member.
 */
class v1_0_0_name_check extends \phpbb\db\migration\migration
{
	public const NAME = 'Beyond Bot';

	public static function depends_on()
	{
		return ['\phpbb\db\migration\data\v330\v330'];
	}

	public function update_data()
	{
		return [
			['custom', [[$this, 'check_name']]],
		];
	}

	public function check_name()
	{
		self::refuse_if_taken($this->db, $this->table_prefix);
	}

	/**
	 * The id of this extension's own bot member left from an earlier
	 * install (no password, no e-mail, not a founder: nobody can log in
	 * to it), or 0 if there is no member of the name. Throws if a member
	 * of the name is anyone else's.
	 */
	public static function refuse_if_taken(\phpbb\db\driver\driver_interface $db, string $table_prefix): int
	{
		$clean = utf8_clean_string(self::NAME);
		$sql = 'SELECT user_id, user_password, user_email, user_type FROM ' . $table_prefix . "users WHERE username_clean = '" . $db->sql_escape($clean) . "'";
		$result = $db->sql_query($sql);
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if (!$row)
		{
			return 0;
		}
		if ((string) $row['user_password'] !== '' || (string) $row['user_email'] !== '' || (int) $row['user_type'] === USER_FOUNDER)
		{
			// phpBB loads an extension's language files only once it is
			// enabled, which this is not yet: load the message's file here,
			// or the admin would see only its key.
			global $phpbb_container;
			if (isset($phpbb_container) && $phpbb_container->has('language'))
			{
				$phpbb_container->get('language')->add_lang('info_acp_beyondbot', 'sethmorrow/beyondbot');
			}
			throw new \phpbb\db\migration\exception('BEYONDBOT_NAME_TAKEN', self::NAME);
		}
		return (int) $row['user_id'];
	}
}
