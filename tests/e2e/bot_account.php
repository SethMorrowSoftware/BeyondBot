<?php
/**
 * Prints, as JSON, what a board knows about the Beyond Bot member:
 * how many members have the name, its id, the id the extension's
 * config points at, and whether the extension is enabled. run.sh uses
 * it around a purge and re-install. With "lock-out" as the second
 * argument it first gives the member a password and e-mail, making it
 * an ordinary account someone could log in to; with "rename" it renames
 * that member out of the way, as the refusal message asks an admin to.
 *
 *   php bot_account.php BOARD_DIR [lock-out|rename]
 */
define('IN_PHPBB', true);
$phpbb_root_path = rtrim($argv[1], '/') . '/';
$phpEx = 'php';
chdir($phpbb_root_path);
require $phpbb_root_path . 'common.php';

$clean = $db->sql_escape(utf8_clean_string('Beyond Bot'));
if (($argv[2] ?? '') === 'lock-out')
{
	$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_password = '" . $db->sql_escape(password_hash('x', PASSWORD_BCRYPT)) . "', user_email = 'someone@example.org' WHERE username_clean = '$clean'");
}
if (($argv[2] ?? '') === 'rename')
{
	$db->sql_query('UPDATE ' . USERS_TABLE . " SET username = 'Beyond Bot Fan', username_clean = '" . $db->sql_escape(utf8_clean_string('Beyond Bot Fan')) . "' WHERE username_clean = '$clean'");
}
$result = $db->sql_query('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean = '$clean'");
$ids = array_map('intval', array_column($db->sql_fetchrowset($result), 'user_id'));
$db->sql_freeresult($result);
$result = $db->sql_query('SELECT ext_active FROM ' . EXT_TABLE . " WHERE ext_name = 'sethmorrow/beyondbot'");
$active = (int) $db->sql_fetchfield('ext_active');
$db->sql_freeresult($result);
echo json_encode(['ids' => $ids, 'config_user_id' => (int) ($config['beyondbot_user_id'] ?? 0), 'enabled' => $active === 1]), "\n";
