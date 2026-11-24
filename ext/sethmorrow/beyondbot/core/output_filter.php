<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * Makes Claude's reply safe and tidy to post.
 *
 * The reply still goes through phpBB's own BBCode parser afterwards,
 * so nothing here is the last line against HTML or script: phpBB
 * escapes all of that for any member's post. This class narrows what
 * the bot may post BELOW what a member may: only a few BBCodes, links
 * only to allowed sites, no images, no mention of itself (so it can
 * never call itself), and a length cap.
 */
class output_filter
{
	/** BBCodes the bot may use. Everything else is unwrapped to its text. */
	public const ALLOWED_TAGS = ['b', 'i', 'u', 'code', 'quote', 'list', '*', 'url'];

	public const DEFAULT_DOMAINS = [
		'openxtalk.org',
		'github.com',
		'sethmorrowsoftware.github.io',
	];

	/** @var string[] */
	protected array $domains;
	protected int $max_chars;
	protected string $bot_name;
	/** @var string[] board BBCodes allowed besides ALLOWED_TAGS (only ones this filter knows how to check) */
	protected array $board_tags;

	/** Board tags the filter can check. [dict] takes phpBB's SIMPLETEXT only. */
	public const BOARD_TAGS = ['dict'];

	public function __construct(array $domains = self::DEFAULT_DOMAINS, int $max_chars = 6000, string $bot_name = 'Beyond Bot', array $board_tags = [])
	{
		$this->board_tags = array_values(array_intersect(self::BOARD_TAGS, array_map('strtolower', $board_tags)));
		$this->domains = array_values(array_filter(array_map(
			static fn ($d) => strtolower(trim((string) $d)), $domains)));
		$this->max_chars = max(200, $max_chars);
		$this->bot_name = $bot_name;
	}

	/**
	 * @return string|null the text to post, or null when nothing worth posting is left
	 */
	public function clean(string $text): ?string
	{
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		// Control characters other than newline and tab never belong in a post.
		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
		$text = $this->markdown_to_bbcode($text);
		$text = $this->inline_code_to_bold($text);

		// Work outside [code] blocks only: code is shown as written.
		$parts = preg_split('#(\[code(?:=[^\]]*)?\].*?\[/code\])#is', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		foreach ($parts as $i => $part)
		{
			if ($i % 2 === 1)
			{
				continue;
			}
			// Until nothing changes: removing one tag can join the pieces
			// around it into a new one ("[ur[color]l=..."), and a link
			// dropped to its label can do the same. Every pass that
			// changes anything makes the text shorter, so this ends.
			do
			{
				$before = $part;
				$part = $this->unwrap_disallowed_tags($part);
				$part = $this->filter_urls($part);
			}
			while ($part !== $before);
			$part = $this->check_dict($part);
			$part = $this->defuse_self_mention($part);
			$parts[$i] = $part;
		}
		$text = trim(implode('', $parts));
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
		$text = $this->limit_length($text);

		$visible = trim(preg_replace('#\[/?[a-z*]+(?:=[^\]]*)?\]#i', '', $text) ?? '');
		if ($visible === '')
		{
			return null;
		}
		return $text;
	}

	/** Claude is told to write BBCode; this catches the Markdown habits that slip through. */
	protected function markdown_to_bbcode(string $text): string
	{
		// ```lang ... ``` fences become [code]
		$text = preg_replace('/^```[^\n]*\n(.*?)\n```[ \t]*$/ms', "[code]\$1[/code]", $text) ?? $text;
		$parts = preg_split('#(\[code(?:=[^\]]*)?\].*?\[/code\])#is', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		foreach ($parts as $i => $part)
		{
			if ($i % 2 === 1)
			{
				continue;
			}
			$part = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '[b]$1[/b]', $part) ?? $part;
			$part = preg_replace('/^#{1,6}\s+(.+)$/m', '[b]$1[/b]', $part) ?? $part;
			$part = preg_replace('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/', '[url=$2]$1[/url]', $part) ?? $part;
			$parts[$i] = $part;
		}
		return implode('', $parts);
	}

	/**
	 * phpBB has no inline code: [code] is always a full-width box with a
	 * "Code: Select all" bar, so a name in [code] mid-sentence breaks the
	 * line into boxes. A one-line [code] that shares its line with other
	 * words becomes [b]; one on a line of its own stays a code block.
	 */
	protected function inline_code_to_bold(string $text): string
	{
		return preg_replace_callback('#\[code\]([^\n]*?)\[/code\]#i', static function ($m) use ($text) {
			[$whole, $at] = $m[0];
			$line_start = strrpos(substr($text, 0, $at), "\n");
			$before = substr($text, $line_start === false ? 0 : $line_start + 1, $at - ($line_start === false ? 0 : $line_start + 1));
			$end = $at + strlen($whole);
			$line_end = strpos($text, "\n", $end);
			$after = substr($text, $end, ($line_end === false ? strlen($text) : $line_end) - $end);
			$inline = trim($before) !== '' || trim($after) !== '';
			$inner = trim($m[1][0]);
			return ($inline && $inner !== '') ? '[b]' . $inner . '[/b]' : $whole;
		}, $text, -1, $count, PREG_OFFSET_CAPTURE) ?? $text;
	}

	public function url_allowed(string $url): bool
	{
		$parts = parse_url(trim($url));
		if (!$parts || !isset($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true))
		{
			return false;
		}
		if (isset($parts['user']) || isset($parts['pass']))
		{
			return false;
		}
		$host = strtolower($parts['host']);
		foreach ($this->domains as $d)
		{
			if ($host === $d || str_ends_with($host, '.' . $d))
			{
				return true;
			}
		}
		return false;
	}

	protected function filter_urls(string $text): string
	{
		// [url=X]label[/url]
		$text = preg_replace_callback('#\[url=([^\]]*)\](.*?)\[/url\]#is', function ($m) {
			$url = trim($m[1], " \"'");
			return $this->url_allowed($url) ? '[url=' . $url . ']' . $m[2] . '[/url]' : $m[2];
		}, $text) ?? $text;
		// [url]X[/url]
		$text = preg_replace_callback('#\[url\](.*?)\[/url\]#is', function ($m) {
			return $this->url_allowed($m[1]) ? '[url]' . $m[1] . '[/url]' : $m[1];
		}, $text) ?? $text;
		return $text;
	}

	protected function unwrap_disallowed_tags(string $text): string
	{
		return preg_replace_callback('#\[(/?)([a-z*][a-z0-9_*]*)(=[^\]]*)?\]#i', function ($m) {
			$name = strtolower($m[2]);
			if (in_array($name, self::ALLOWED_TAGS, true) || in_array($name, $this->board_tags, true))
			{
				return $m[0];
			}
			// [img]x[/img], [attachment=0]..: drop the markup, keep the words.
			return '';
		}, $text) ?? $text;
	}

	/**
	 * [dict]word[/dict] renders only when the word is phpBB SIMPLETEXT
	 * (letters, digits, spaces and - + . , _); anything else would show the
	 * raw tag, so it becomes bold. A [dict] left unclosed or empty is dropped.
	 */
	protected function check_dict(string $text): string
	{
		if (!in_array('dict', $this->board_tags, true))
		{
			return $text;
		}
		$kept = [];
		$text = preg_replace_callback('#\[dict\]([^\[\]]*?)\[/dict\]#i', static function ($m) use (&$kept) {
			$word = trim($m[1]);
			if ($word === '')
			{
				return '';
			}
			if (!preg_match('/^[A-Za-z0-9 \-+.,_]+$/', $word))
			{
				return '[b]' . $word . '[/b]';
			}
			$kept[] = '[dict]' . $word . '[/dict]';
			return "\x01" . (count($kept) - 1) . "\x01";
		}, $text) ?? $text;
		// Strays with no partner, or wrapped around other tags, are dropped.
		$text = preg_replace('#\[/?dict\]#i', '', $text) ?? $text;
		return preg_replace_callback('/\x01(\d+)\x01/', static fn ($m) => $kept[(int) $m[1]], $text) ?? $text;
	}

	/** "@Beyond Bot" in the bot's own reply would be a mention; drop the @. */
	protected function defuse_self_mention(string $text): string
	{
		$pattern = mention::pattern($this->bot_name);
		if ($pattern === '')
		{
			return $text;
		}
		return preg_replace_callback($pattern, static fn ($m) => substr($m[0], 1), $text) ?? $text;
	}

	protected function limit_length(string $text): string
	{
		if (mb_strlen($text) <= $this->max_chars)
		{
			return $text;
		}
		$cut = mb_substr($text, 0, $this->max_chars);
		$para = mb_strrpos($cut, "\n\n");
		if ($para !== false && $para > $this->max_chars / 2)
		{
			$cut = mb_substr($cut, 0, $para);
		}
		// A tag the cut split in two ("[url=https://git") is dropped whole.
		$cut = preg_replace('/\[[^\]\n]*$/', '', $cut) ?? $cut;
		// Close what the cut left open, innermost first.
		foreach (['code', 'quote', 'list', 'url', 'u', 'i', 'b'] as $tag)
		{
			$open = preg_match_all('#\[' . $tag . '(?:=[^\]]*)?\]#i', $cut);
			$close = preg_match_all('#\[/' . $tag . '\]#i', $cut);
			if ($tag === 'url' && $open > $close)
			{
				// A half link is worse than none: drop the unfinished one.
				$pos = mb_strrpos(strtolower($cut), '[url');
				$cut = mb_substr($cut, 0, $pos);
				continue;
			}
			for ($n = $close; $n < $open; $n++)
			{
				$cut .= '[/' . $tag . ']';
			}
		}
		return rtrim($cut) . "\n\n[i](This answer was cut short.)[/i]";
	}
}
