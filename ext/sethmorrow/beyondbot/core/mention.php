<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * Finds "@Beyond Bot" in a post.
 *
 * Pure functions on strings, so they are tested without phpBB. The
 * listener first strips quotes and code from the stored post (a member
 * quoting someone else's mention, or showing the words in a code
 * sample, is not asking), then asks mentions().
 */
class mention
{
	/**
	 * Does this plain text call the bot?
	 *
	 * Accepts "@Beyond Bot", "@BeyondBot", "@Beyond_Bot" and "@beyond-bot"
	 * in any case, and the same for a configured bot name. The @ must not
	 * follow a word character (an e-mail address such as
	 * x@beyondbot.org is not a mention) and the name must end at a word
	 * boundary (so "@BeyondBotFan" is someone else).
	 */
	public static function mentions(string $text, string $bot_name = 'Beyond Bot'): bool
	{
		$pattern = self::pattern($bot_name);
		return $pattern !== '' && preg_match($pattern, $text) === 1;
	}

	/**
	 * The regex for a bot name: its words joined by an optional space,
	 * underscore or hyphen.
	 */
	public static function pattern(string $bot_name): string
	{
		$words = preg_split('/[\s_\-]+/u', trim($bot_name), -1, PREG_SPLIT_NO_EMPTY);
		if (!$words)
		{
			return '';
		}
		$quoted = array_map(static fn ($w) => preg_quote($w, '/'), $words);
		return '/(?<![\w@])@' . implode('[\s_\-]?', $quoted) . '(?![\w])/iu';
	}

	/**
	 * The words of a post a reader sees as the author's own: quotes and
	 * code removed, formatting removed.
	 *
	 * @param object $utils phpBB's text_formatter.utils service
	 *                      (remove_bbcode, clean_formatting)
	 */
	public static function own_words(object $utils, string $xml): string
	{
		foreach (['quote', 'code'] as $tag)
		{
			$xml = $utils->remove_bbcode($xml, $tag);
		}
		return $utils->clean_formatting($xml);
	}
}
