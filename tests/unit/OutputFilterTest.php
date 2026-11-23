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
}
