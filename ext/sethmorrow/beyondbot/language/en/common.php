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
	// Posted under every answer: says plainly that it is AI and may be wrong.
	'BEYONDBOT_FOOTER' => '[size=85][i]Answer written by AI (Beyond Bot) from the OXT-Beyond and xTalk Suite docs. It can be wrong: check before you rely on it.[/i][/size]',
	'BEYONDBOT_LIMIT_NOTE' => [
		1 => 'You have asked Beyond Bot %d question today, the daily limit. Please ask again tomorrow, or wait for another member to answer.',
		2 => 'You have asked Beyond Bot %d questions today, the daily limit. Please ask again tomorrow, or wait for another member to answer.',
	],
]);
