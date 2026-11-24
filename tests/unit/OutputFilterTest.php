<?php
use PHPUnit\Framework\TestCase;
use sethmorrow\beyondbot\core\output_filter;

final class OutputFilterTest extends TestCase
{
	private function f(int $max = 6000): output_filter
	{
		return new output_filter(output_filter::DEFAULT_DOMAINS, $max);
	}

	public function testPlainTextPassesThrough(): void
	{
		$this->assertSame("Use [b]put[/b].\n\nDone.", $this->f()->clean("Use [b]put[/b].\r\n\r\n\r\n\r\nDone."));
	}

	public function testNothingVisibleIsNull(): void
	{
		$this->assertNull($this->f()->clean(''));
		$this->assertNull($this->f()->clean("  [b][/b] [img][/img] [i] [/i]\n"));
	}

	public function testImagesAndOtherTagsAreUnwrapped(): void
	{
		$out = $this->f()->clean('[img]https://evil.example/x.png[/img] [color=red]red[/color] [size=200]big[/size] [attachment=0]a[/attachment]');
		$this->assertStringNotContainsString('[img', $out);
		$this->assertStringNotContainsString('[color', $out);
		$this->assertStringNotContainsString('[size', $out);
		$this->assertStringContainsString('red', $out);
	}

	public function testLinks(): void
	{
		$f = $this->f();
		$this->assertTrue($f->url_allowed('https://github.com/SethMorrowSoftware/xtalk-suite'));
		$this->assertTrue($f->url_allowed('https://www.openxtalk.org/forum/'));
		$this->assertTrue($f->url_allowed('https://sethmorrowsoftware.github.io/OpenXTalk-Beyond/dictionary/'));
		$this->assertFalse($f->url_allowed('https://evilgithub.com/'));
		$this->assertFalse($f->url_allowed('https://github.com.evil.example/'));
		$this->assertFalse($f->url_allowed('https://user:pw@github.com/'));
		$this->assertFalse($f->url_allowed('javascript:alert(1)'));
		$this->assertFalse($f->url_allowed('ftp://github.com/'));
		$out = $f->clean('[url=https://evil.example/]click[/url] and [url]https://evil.example/2[/url] and [url=https://github.com/x]ok[/url]');
		$this->assertSame('click and https://evil.example/2 and [url=https://github.com/x]ok[/url]', $out);
	}

	public static function smuggledLinks(): array
	{
		return [
			'tag split by a removed tag' => ['[ur[color]l=https://evil.example/phishing]click[/ur[color]l]'],
			'split with an image tag'    => ['[ur[img][/img]l=https://evil.example/p]x[/ur[img][/img]l]'],
			'joined by a dropped link'   => ['[ur[url=https://evil.example/a]l=https://evil.example/b]click[/u[/url]rl]'],
			'bare form'                  => ['[ur[size=1]l]https://evil.example/c[/ur[size=1]l]'],
			'nested twice'               => ['[u[i[color]]rl=https://evil.example/d]x[/url]'],
		];
	}

	/** @dataProvider smuggledLinks */
	public function testNoLinkToAnotherSiteCanBeSmuggledIn(string $text): void
	{
		$out = (string) $this->f()->clean($text);
		$this->assertDoesNotMatchRegularExpression('#\[url[=\]]\s*[\'"]?https?://evil#i', $out, $out);
	}

	public function testMarkdownBecomesBBCode(): void
	{
		$out = $this->f()->clean("## Heading\n\n**bold** and [the docs](https://github.com/a)\n\n```xtalk\nput **x** into y\n```");
		$this->assertStringContainsString('[b]Heading[/b]', $out);
		$this->assertStringContainsString('[b]bold[/b]', $out);
		$this->assertStringContainsString('[url=https://github.com/a]the docs[/url]', $out);
		$this->assertStringContainsString("[code]put **x** into y[/code]", $out);
	}

	public function testCodeIsLeftAlone(): void
	{
		$code = "[code]put \"[img]x[/img] @Beyond Bot\" into t -- [url=https://evil.example]x[/url][/code]";
		$this->assertSame($code, $this->f()->clean($code));
	}

	public function testSelfMentionIsDefused(): void
	{
		$out = $this->f()->clean('Ask @Beyond Bot or @beyondbot again.');
		$this->assertSame('Ask Beyond Bot or beyondbot again.', $out);
	}

	public function testControlCharactersAreDropped(): void
	{
		$this->assertSame("ab\tc", $this->f()->clean("a\x00b\x07\tc\x1B"));
	}

	public function testLongAnswersAreCutAndClosed(): void
	{
		$text = '[b]' . str_repeat('word ', 200) . "\n\n" . '[quote]' . str_repeat('more ', 200);
		$out = $this->f(300)->clean($text);
		$this->assertStringEndsWith('(This answer was cut short.)[/i]', $out);
		$this->assertSame(substr_count($out, '[b]'), substr_count($out, '[/b]'));
		$this->assertSame(substr_count($out, '[quote]'), substr_count($out, '[/quote]'));
	}

	public function testCutNeverLeavesHalfALink(): void
	{
		$text = str_repeat('x', 280) . ' [url=https://github.com/abc]a long label that runs past the cut[/url]';
		$out = $this->f(300)->clean($text);
		$this->assertStringNotContainsString('[url', $out);
		// Cut inside the label instead of inside the tag.
		$out = $this->f(300)->clean(str_repeat('x', 250) . ' [url=https://github.com/a]' . str_repeat('label ', 20) . '[/url]');
		$this->assertStringNotContainsString('[url', $out);
		$this->assertStringNotContainsString('[/url]', $out);
	}

	public function testCodeInsideASentenceBecomesBold(): void
	{
		// The live board showed each of these as a full-width code box mid-bullet.
		$in = "Some notes:\n[list]\n[*]Use [code]repeat for each line[/code] rather than [code]line i of[/code]. It is faster.\n"
			. "[*]For non-ASCII text, read with [code]binfile:[/code] and convert.\n[/list]\n"
			. "If you only want the file, use [code]put URL (\"file:\" & tPath) into field 1[/code].";
		$out = $this->f()->clean($in);
		$this->assertStringContainsString('[*]Use [b]repeat for each line[/b] rather than [b]line i of[/b]. It is faster.', $out);
		$this->assertStringContainsString('read with [b]binfile:[/b] and convert', $out);
		$this->assertStringContainsString('use [b]put URL ("file:" & tPath) into field 1[/b].', $out);
		$this->assertStringNotContainsString('[code]', $out);
	}

	public function testCodeOnItsOwnLineStaysABlock(): void
	{
		$block = "[code]\non mouseUp\n   put tX into field 1\nend mouseUp\n[/code]";
		$in = "Try this:\n\n[code]put \"Hello\" into field \"Greeting\"[/code]\n\nOr:\n" . $block . "\nDone.";
		$out = $this->f()->clean($in);
		$this->assertStringContainsString("\n[code]put \"Hello\" into field \"Greeting\"[/code]\n", $out);
		$this->assertStringContainsString("[code]\non mouseUp", $out);
		$this->assertStringContainsString("end mouseUp\n[/code]", $out);
	}

	public function testEmptyInlineCodeIsLeftForTheRestOfTheFilter(): void
	{
		$out = $this->f()->clean('Use [code][/code] here and [code] [/code] there.');
		$this->assertStringNotContainsString('[b][/b]', $out);
	}

	public function testDictIsKeptOnlyWhenTheBoardHasIt(): void
	{
		$in = 'Use [dict]open file[/dict] to start.';
		$this->assertSame('Use open file to start.', $this->f()->clean($in));
		$with = new output_filter(output_filter::DEFAULT_DOMAINS, 6000, 'Beyond Bot', ['dict']);
		$this->assertSame('Use [dict]open file[/dict] to start.', $with->clean($in));
	}

	public function testDictTakesOnlyWhatTheBoardTagRenders(): void
	{
		$f = new output_filter(output_filter::DEFAULT_DOMAINS, 6000, 'Beyond Bot', ['dict', 'img']);
		// phpBB's SIMPLETEXT: letters, digits, spaces and - + . , _
		$this->assertSame('[dict]textDecode[/dict] and [b]the "x" of[/b] and [b]put()[/b].',
			$f->clean('[dict]textDecode[/dict] and [dict]the "x" of[/dict] and [dict]put()[/dict].'));
		// empty, unclosed, stray and wrapped forms leave no raw tag behind
		$this->assertSame('a b c [b]d[/b]', $f->clean('a [dict][/dict]b [dict]c [b]d[/b][/dict]'));
		$this->assertSame('x y', $f->clean('x [dict]y'));
		// a board tag the filter cannot check is never allowed
		$this->assertSame('pic', $f->clean('[img]pic[/img]'));
		// inside code it is code
		$this->assertStringContainsString("[code]\nput [dict]x[/dict]\n[/code]", $f->clean("See:\n[code]\nput [dict]x[/dict]\n[/code]"));
	}

	public function testArrayKeysInProseAreKeptAndCannotFormat(): void
	{
		$z = output_filter::DEFUSE;
		$out = $this->f()->clean("Inside the loop, tArray[each] is that key's value.\n\nRead tData[i] and tArr[b] here. A lone [/u] too.");
		$this->assertStringContainsString('tArray[' . $z . 'each]', $out);
		$this->assertStringContainsString('tData[' . $z . 'i]', $out);
		$this->assertStringContainsString('tArr[' . $z . 'b]', $out);
		$this->assertStringContainsString('[' . $z . '/u]', $out);
		// Nothing left that phpBB would read as an unpaired tag: only the
		// empty bold pairs themselves. (The e2e test renders it in phpBB.)
		$this->assertDoesNotMatchRegularExpression('#\[(i|u)\]#', $out);
		$this->assertSame(substr_count($out, '[b]'), substr_count($out, '[/b]'));
		$this->assertStringNotContainsString("\u{200B}", $out, 'nothing invisible that a reader would copy');
		// What a reader sees is the text as written.
		$this->assertSame("Inside the loop, tArray[each] is that key's value.\n\nRead tData[i] and tArr[b] here. A lone [/u] too.", str_replace($z, '', $out));
	}

	public function testPairedTagsAndListsStay(): void
	{
		$in = "Use [b]put[/b] and [i]get[/i].\n[list]\n[*]one\n[*]two with tList[*] inside\n[/list]\nA stray [*] here.";
		$out = $this->f()->clean($in);
		$this->assertStringContainsString('[b]put[/b] and [i]get[/i]', $out);
		$this->assertStringContainsString("[list]\n[*]one\n[*]two", $out);
		$this->assertStringContainsString('A stray [' . output_filter::DEFUSE . '*] here.', $out);
	}

	public function testAQuoteAroundCodeStaysPaired(): void
	{
		$in = "[quote]Try this:\n[code]put tA[i] into x[/code]\n[/quote]\nDone.";
		$this->assertSame($in, $this->f()->clean($in));
	}

	public function testUnknownTagsAreDefusedNotDropped(): void
	{
		$out = $this->f()->clean('Set tOpts[timeout] and [spoiler]x[/spoiler].');
		$this->assertSame('Set tOpts[' . output_filter::DEFUSE . 'timeout] and x.', $out);
	}
}
