<?php
use PHPUnit\Framework\TestCase;
use sethmorrow\beyondbot\core\reports;

final class ReportsTest extends TestCase
{
	private static function input(array $over = []): array
	{
		return $over + [
			'title' => 'launch document fails on html5',
			'component' => 'web player',
			'summary' => 'It does nothing.',
			'steps' => "1. launch document \"a.pdf\"\n2. nothing",
			'expected' => 'The PDF opens.',
			'actual' => 'Nothing happens.',
			'platform' => 'Chrome on Windows 11',
			'version' => '0.2.8',
		];
	}

	public function testCleanKeepsKnownFieldsOnly(): void
	{
		$r = reports::clean(self::input(['evil' => 'x', 'status' => 'done']));
		$this->assertSame(array_keys(reports::FIELDS), array_keys($r));
		$this->assertSame("1. launch document \"a.pdf\"\n2. nothing", $r['steps'], 'long fields keep their lines');
	}

	public function testCleanFlattensOneLineFieldsAndStripsControls(): void
	{
		$r = reports::clean(self::input(['title' => "Crash\n\ton  save\x07 ", 'summary' => "a\r\nb\x00"]));
		$this->assertSame('Crash on save', $r['title']);
		$this->assertSame("a\nb", $r['summary']);
	}

	public function testCleanCutsEachField(): void
	{
		$r = reports::clean(self::input(['title' => str_repeat('é', 500), 'summary' => str_repeat('s', 9000)]));
		$this->assertSame(reports::FIELDS['title'], mb_strlen($r['title']));
		$this->assertSame(reports::FIELDS['summary'], mb_strlen($r['summary']));
	}

	public function testCleanRefusesAReportWithoutTitleOrSummary(): void
	{
		$this->assertNull(reports::clean(self::input(['title' => 'ab'])));
		$this->assertNull(reports::clean(self::input(['summary' => '  '])));
		$this->assertNull(reports::clean(['title' => ['an array'], 'summary' => 'x']));
		$this->assertNotNull(reports::clean(['title' => 'Crash', 'summary' => 'x']), 'the other fields may be missing');
	}

	public function testAlike(): void
	{
		$this->assertTrue(reports::alike('Crash on save!', 'crash  on SAVE'));
		$this->assertTrue(reports::alike('launch document fails on html5', 'launch document fails in html5'));
		$this->assertFalse(reports::alike('Crash on save', 'Wrong sort order in the dictionary'));
		$this->assertFalse(reports::alike('!!!', '???'), 'nothing left to compare is never a match');
	}

	public function testIssueBody(): void
	{
		$body = reports::issue_body(reports::clean(self::input(['expected' => ''])), 'https://board/viewtopic.php?p=7#p7', 4000);
		$this->assertStringContainsString("### Summary\n\nIt does nothing.", $body);
		$this->assertStringContainsString('### Steps to reproduce', $body);
		$this->assertStringNotContainsString('### Expected', $body, 'empty sections are left out');
		$this->assertStringContainsString("- Component: web player\n- Platform: Chrome on Windows 11\n- Version: 0.2.8", $body);
		$this->assertStringContainsString('Reported on the forum: https://board/viewtopic.php?p=7#p7', $body);
	}

	public function testGithubUrlIsGithubsOwnFormFilledIn(): void
	{
		$url = reports::github_url('SethMorrowSoftware/OpenXTalk-Beyond', reports::clean(self::input()), 'https://board/viewtopic.php?p=7#p7');
		$this->assertStringStartsWith('https://github.com/SethMorrowSoftware/OpenXTalk-Beyond/issues/new?title=launch%20document', $url);
		parse_str(parse_url($url, PHP_URL_QUERY), $q);
		$this->assertSame('launch document fails on html5', $q['title']);
		$this->assertStringContainsString('Nothing happens.', $q['body']);
	}

	public function testGithubUrlRefusesABadRepository(): void
	{
		foreach (['', 'nobody', 'a/b/c', 'evil.com/x?y', 'a b/c'] as $repo)
		{
			$this->assertSame('', reports::github_url($repo, reports::clean(self::input()), 'u'), $repo);
		}
	}

	public function testGithubUrlStaysShortEnough(): void
	{
		$long = str_repeat('ü ', 1999);
		$r = reports::clean(self::input(['summary' => $long, 'steps' => $long, 'expected' => $long, 'actual' => $long]));
		$url = reports::github_url('o/r', $r, 'u');
		$this->assertLessThanOrEqual(7500, strlen($url));
		parse_str(parse_url($url, PHP_URL_QUERY), $q);
		$this->assertStringContainsString('[...]', $q['body']);
	}
}
