<?php
use PHPUnit\Framework\TestCase;
use sethmorrow\beyondbot\core\kb_updater;
use sethmorrow\beyondbot\core\knowledge_base;

final class KnowledgeTest extends TestCase
{
	public function testTokenizerMatchesTheBuilder(): void
	{
		$this->assertSame(['put', 'value', 'field_1'], knowledge_base::tokenize('How do I put a Value into Field_1?'));
		// The stopword list must be the builder's, word for word: a query
		// word dropped on one side and not the other never matches.
		$py = file_get_contents(__DIR__ . '/../../tools/build_knowledge.py');
		$this->assertSame(1, preg_match('/STOPWORDS = frozenset\("""(.*?)"""/s', $py, $m));
		$words = preg_split('/\s+/', trim($m[1]));
		sort($words);
		$php = knowledge_base::STOPWORDS;
		sort($php);
		$this->assertSame($words, $php);
		$this->assertSame(1, preg_match('/TOKEN_RE = re\.compile\(r"\[a-z0-9_\]\+"\)/', $py));
	}

	public function testTokenizerAgainstPython(): void
	{
		$sample = "The mouseUp handler's 2nd LINE of a Ünïcode-text, sxEncrypt() & b2kWorld_3 a x";
		$out = shell_exec('python3 -c ' . escapeshellarg('import sys; sys.path.insert(0, "tools"); import build_knowledge as b; print(" ".join(b.tokenize(sys.argv[1])))') . ' ' . escapeshellarg($sample) . ' 2>/dev/null');
		if ($out === null || $out === false)
		{
			$this->markTestSkipped('python3 not available');
		}
		$this->assertSame(trim($out), implode(' ', knowledge_base::tokenize($sample)));
	}

	private function manifest(array $o = []): array
	{
		return $o + ['format' => 1, 'file' => 'beyondbot-knowledge.ndjson.gz', 'sha256' => str_repeat('a', 64), 'size' => 1000, 'chunks' => 10];
	}

	public function testGoodManifest(): void
	{
		kb_updater::check_manifest($this->manifest());
		$this->addToAssertionCount(1);
	}

	public static function bad(): array
	{
		return [
			'not json'   => [null],
			'format'     => [['format' => 2]],
			'path'       => [['file' => '../../config.php']],
			'slash'      => [['file' => 'a/b.gz']],
			'sha'        => [['sha256' => 'abc']],
			'sha upper'  => [['sha256' => str_repeat('A', 64)]],
			'size 0'     => [['size' => 0]],
			'size huge'  => [['size' => kb_updater::MAX_BYTES + 1]],
			'no chunks'  => [['chunks' => 0]],
		];
	}

	/** @dataProvider bad */
	public function testBadManifestsAreRefused(?array $o): void
	{
		$this->expectException(\RuntimeException::class);
		kb_updater::check_manifest($o === null ? 'x' : $this->manifest($o));
	}
}
