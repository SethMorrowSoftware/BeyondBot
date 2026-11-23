<?php
use PHPUnit\Framework\TestCase;
use sethmorrow\beyondbot\core\gateway;
use sethmorrow\beyondbot\core\knowledge;
use sethmorrow\beyondbot\core\responder;

final class FakeGateway implements gateway
{
	public array $requests = [];
	/** @param callable(array,int):array $script */
	public function __construct(private $script) {}
	public function create(array $request): array
	{
		$this->requests[] = $request;
		return ($this->script)($request, count($this->requests));
	}
}

final class FakeKnowledge implements knowledge
{
	public function search(string $query, int $limit = 8): array
	{
		return $query === 'nothing' ? [] : [['title' => "Hit for $query", 'url' => 'https://github.com/x', 'text' => str_repeat('t', 3000)]];
	}
	public function entry(string $name): ?array
	{
		return $name === 'put' ? ['title' => 'put (command)', 'url' => 'https://github.com/put', 'text' => 'Syntax: put'] : null;
	}
}

final class ResponderTest extends TestCase
{
	private static function reply(string $stop, array $calls = [], string $text = '', string $model = 'claude-sonnet-5-5'): array
	{
		$content = [];
		foreach ($calls as $c)
		{
			$content[] = ['type' => 'tool_use'] + $c;
		}
		return ['stop_reason' => $stop, 'content' => $content, 'text' => $text, 'tool_calls' => $calls,
			'usage' => ['input' => 10, 'output' => 2, 'cache_write' => 0, 'cache_read' => 5], 'model' => $model];
	}

	private function responder(FakeGateway $g): responder
	{
		return new responder($g, new FakeKnowledge(), ['model' => 'claude-sonnet-5-5', 'system' => 'SYS']);
	}

	public function testOneLookupThenAnswer(): void
	{
		$g = new FakeGateway(fn ($r, $n) => $n === 1
			? self::reply('tool_use', [['id' => 't1', 'name' => 'get_dictionary_entry', 'input' => ['name' => 'put']]])
			: self::reply('end_turn', [], ' Use put. '));
		$a = $this->responder($g)->answer('Q');
		$this->assertSame('Use put.', $a['text']);
		$this->assertSame(2, $a['rounds']);
		$this->assertSame(['input' => 20, 'output' => 4, 'cache_write' => 0, 'cache_read' => 10], $a['usage']);
		$this->assertSame(['entry: put'], $a['queries']);
		$second = $g->requests[1]['messages'];
		$this->assertCount(3, $second);
		$this->assertSame('assistant', $second[1]['role']);
		$this->assertSame('t1', $second[2]['content'][0]['toolUseID']);
		$this->assertStringContainsString('put (command)', $second[2]['content'][0]['content']);
		$this->assertSame('SYS', $g->requests[0]['system']);
		$this->assertSame('medium', $g->requests[0]['effort']);
		$this->assertSame(8000, $g->requests[0]['max_tokens']);
	}

	public function testLookupsAreBounded(): void
	{
		$g = new FakeGateway(fn ($r) => $r['tool_choice'] === 'none'
			? self::reply('end_turn', [], 'final')
			: self::reply('tool_use', [['id' => 'x', 'name' => 'search_docs', 'input' => ['query' => 'loop']]]));
		$a = $this->responder($g)->answer('Q');
		$this->assertSame(responder::MAX_ROUNDS + 1, count($g->requests));
		$this->assertSame('none', end($g->requests)['tool_choice']);
		$this->assertSame('auto', $g->requests[0]['tool_choice']);
		$this->assertSame('final', $a['text']);
	}

	public function testEachCallIsRecordedWithItsOwnModel(): void
	{
		$g = new FakeGateway(fn ($r, $n) => $n === 1
			? self::reply('tool_use', [['id' => 'a', 'name' => 'search_docs', 'input' => ['query' => 'q']]])
			: self::reply('end_turn', [], 'x', 'claude-opus-5'));
		$a = $this->responder($g)->answer('Q');
		$this->assertSame(['claude-sonnet-5-5', 'claude-opus-5'], array_column($a['calls'], 'model'));
		$this->assertSame('claude-opus-5', $a['model']);
	}

	public function testToolResults(): void
	{
		$r = $this->responder(new FakeGateway(fn () => []));
		[$text, $label] = $r->run_tool('get_dictionary_entry', ['name' => 'nope']);
		$this->assertStringStartsWith('No dictionary entry is named "nope". Closest matches', $text);
		$this->assertSame('entry: nope (not found)', $label);
		[$text, $label] = $r->run_tool('search_docs', ['query' => 'nothing']);
		$this->assertStringStartsWith('Nothing in the docs matched', $text);
		$this->assertSame('search: nothing (0)', $label);
		[$text] = $r->run_tool('search_docs', ['query' => 'loop']);
		$this->assertStringContainsString("[... more at the link]", $text);
		[$text, $label] = $r->run_tool('rm_rf', []);
		$this->assertSame('unknown tool: rm_rf', $label);
	}

	public function testToolSchemasAreStrict(): void
	{
		foreach (responder::tools() as $t)
		{
			$this->assertFalse($t['inputSchema']['additionalProperties']);
			$this->assertNotEmpty($t['inputSchema']['required']);
		}
		$this->assertSame(['get_dictionary_entry', 'search_docs'], array_column(responder::tools(), 'name'));
	}
}
