<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'ACP_BEYONDBOT_TITLE' => 'Beyond Bot',
	'ACP_BEYONDBOT_SETTINGS' => 'Settings',
	'ACP_BEYONDBOT_LOG' => 'Answer log',

	'LOG_BEYONDBOT_SETTINGS' => '<strong>Beyond Bot settings changed</strong>',
	'LOG_BEYONDBOT_API_ERROR' => '<strong>Beyond Bot paused: the Claude API refused its key or model</strong><br>» %s',
	'LOG_BEYONDBOT_KB_UPDATED' => '<strong>Beyond Bot docs index updated</strong><br>» %s',
	'LOG_BEYONDBOT_KB_ERROR' => '<strong>Beyond Bot could not update its docs index</strong><br>» %s',
]);
