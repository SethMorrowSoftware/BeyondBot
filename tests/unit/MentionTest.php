<?php
use PHPUnit\Framework\TestCase;
use sethmorrow\beyondbot\core\mention;

final class MentionTest extends TestCase
{
	public static function calls(): array
	{
		return [
			['@Beyond Bot how do I sort lines?', true],
			['hey @beyondbot', true],
			['@Beyond_Bot?', true],
			['(@beyond-bot)', true],
			['@BEYOND BOT!', true],
			["line one\n@Beyond Bot", true],
			['mail me at x@beyondbot.org', false],
			['@BeyondBotFan said so', false],
			['Beyond Bot without the at', false],
			['@@Beyond Bot', false],
			['@Beyond  Bot (two spaces)', false],
		];
	}

	/** @dataProvider calls */
	public function testMentions(string $text, bool $expected): void
	{
		$this->assertSame($expected, mention::mentions($text));
	}

	public function testOtherBotName(): void
	{
		$this->assertTrue(mention::mentions('@Helper', 'Helper'));
		$this->assertFalse(mention::mentions('@Beyond Bot', 'Helper'));
		$this->assertSame('', mention::pattern('   '));
		$this->assertFalse(mention::mentions('@anything', ''));
	}

	public function testOwnWordsDropsQuoteAndCode(): void
	{
		$utils = new class {
			public array $removed = [];
			public function remove_bbcode($xml, $tag) { $this->removed[] = $tag; return $xml; }
			public function clean_formatting($xml) { return 'clean:' . $xml; }
		};
		$this->assertSame('clean:<r/>', mention::own_words($utils, '<r/>'));
		$this->assertSame(['quote', 'code'], $utils->removed);
	}
}
