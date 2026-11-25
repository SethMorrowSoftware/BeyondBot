<?php
use PHPUnit\Framework\TestCase;
use sethmorrow\beyondbot\core\gateway;
use sethmorrow\beyondbot\core\gateway_error;
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

	public function testAFailedCallCarriesThePaidCallsBeforeIt(): void
	{
		$g = new FakeGateway(function ($r, $n) {
			if ($n === 1)
			{
				return self::reply('tool_use', [['id' => 'a', 'name' => 'search_docs', 'input' => ['query' => 'q']]]);
			}
			throw new gateway_error(gateway_error::RETRY, 'overloaded');
		});
		try
		{
			$this->responder($g)->answer('Q');
			$this->fail('no exception');
		}
		catch (gateway_error $e)
		{
			$this->assertCount(1, $e->calls);
			$this->assertSame('claude-sonnet-5-5', $e->calls[0]['model']);
			$this->assertSame(10, $e->calls[0]['usage']['input']);
		}
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

	public function testAnAnswerCutOffByTheBudgetGetsOneMoreTryWithoutTools(): void
	{
		$g = new FakeGateway(fn ($r, $n) => match ($n) {
			1 => self::reply('tool_use', [['id' => 't1', 'name' => 'search_docs', 'input' => ['query' => 'pdf']]]),
			2 => self::reply('max_tokens'),
			default => self::reply('end_turn', [], 'Short answer.'),
		});
		$a = (new responder($g, new FakeKnowledge(), ['model' => 'claude-sonnet-5-5', 'system' => 'SYS', 'max_tokens' => 8000]))->answer('Q');
		$this->assertSame('Short answer.', $a['text']);
		$this->assertSame(3, $a['rounds'], 'every paid call is counted, the recovery one too');
		$this->assertTrue($a['recovered']);
		$last = $g->requests[2];
		$this->assertSame('none', $last['tool_choice']);
		$this->assertSame('low', $last['effort']);
		$this->assertSame(16000, $last['max_tokens']);
		$turn = end($last['messages'])['content'];
		$this->assertSame('tool_result', $turn[0]['type']);
		$this->assertSame(responder::ANSWER_NOW, end($turn)['text']);
	}

	public function testTheRecoveryBudgetIsCapped(): void
	{
		$g = new FakeGateway(fn ($r, $n) => $n === 1 ? self::reply('max_tokens') : self::reply('end_turn', [], 'ok'));
		(new responder($g, new FakeKnowledge(), ['model' => 'm', 'system' => 'S', 'max_tokens' => 30000]))->answer('Q');
		$this->assertSame(responder::MAX_RECOVERY_TOKENS, $g->requests[1]['max_tokens']);
		$this->assertStringEndsWith(responder::ANSWER_NOW, $g->requests[1]['messages'][0]['content']);
	}

	public function testARefusalIsNotRetried(): void
	{
		$g = new FakeGateway(fn ($r, $n) => self::reply('refusal'));
		$a = $this->responder($g)->answer('Q');
		$this->assertCount(1, $g->requests);
		$this->assertSame('refusal', $a['stop_reason']);
	}

	public function testAnAnswerIsNeverAskedForTwice(): void
	{
		$g = new FakeGateway(fn ($r, $n) => self::reply('end_turn', [], 'Done.'));
		$a = $this->responder($g)->answer('Q');
		$this->assertCount(1, $g->requests);
		$this->assertFalse($a['recovered']);
	}
	public function testTheReportToolIsOfferedOnlyWithAReporter(): void
	{
		$this->assertNotContains(responder::REPORT_TOOL, array_column(responder::tools(), 'name'));
		$t = responder::tools(true);
		$this->assertSame(['get_dictionary_entry', 'search_docs', responder::REPORT_TOOL], array_column($t, 'name'));
		$report = end($t)['inputSchema'];
		$this->assertFalse($report['additionalProperties']);
		$this->assertSame(array_keys($report['properties']), $report['required']);

		$g = new FakeGateway(fn () => self::reply('end_turn', [], 'ok'));
		$this->responder($g)->answer('Q');
		$this->assertNotContains(responder::REPORT_TOOL, array_column($g->requests[0]['tools'], 'name'), 'reports off: Claude never sees the tool');

		$g = new FakeGateway(fn () => self::reply('end_turn', [], 'ok'));
		(new responder($g, new FakeKnowledge(), ['model' => 'm', 'system' => 'S', 'reporter' => fn () => 'saved']))->answer('Q');
		$this->assertContains(responder::REPORT_TOOL, array_column($g->requests[0]['tools'], 'name'));
		$this->assertArrayNotHasKey('reporter', $g->requests[0], 'the callable never reaches the API');
	}

	public function testAReportIsSavedWhileAnswering(): void
	{
		$saved = [];
		$g = new FakeGateway(fn ($r, $n) => $n === 1
			? self::reply('tool_use', [['id' => 't1', 'name' => responder::REPORT_TOOL, 'input' => ['title' => 'Crash on save', 'summary' => 's']]])
			: self::reply('end_turn', [], 'Try this.'));
		$a = (new responder($g, new FakeKnowledge(), ['model' => 'm', 'system' => 'S', 'reporter' => function (array $in) use (&$saved) {
			$saved[] = $in;
			return 'Saved as report #4.';
		}]))->answer('Q');
		$this->assertSame('Try this.', $a['text']);
		$this->assertSame([['title' => 'Crash on save', 'summary' => 's']], $saved);
		$this->assertSame(['report: Saved as report #4.'], $a['queries']);
		$result = $g->requests[1]['messages'][2]['content'][0];
		$this->assertSame('tool_result', $result['type']);
		$this->assertStringContainsString('Saved as report #4.', json_encode($result));
	}

	public function testWithoutAReporterTheToolIsUnknown(): void
	{
		[, $label] = $this->responder(new FakeGateway(fn () => []))->run_tool(responder::REPORT_TOOL, ['title' => 'x']);
		$this->assertSame('unknown tool: ' . responder::REPORT_TOOL, $label);
	}

	public function testCaptureIsOneCallWithOnlyTheReportTool(): void
	{
		$saved = 0;
		$g = new FakeGateway(fn () => self::reply('tool_use', [
			['id' => 't1', 'name' => responder::REPORT_TOOL, 'input' => ['title' => 'Crash', 'summary' => 's']],
			['id' => 't2', 'name' => responder::REPORT_TOOL, 'input' => ['title' => 'Again', 'summary' => 's']],
		], '', 'claude-haiku-5-5'));
		$c = (new responder($g, new FakeKnowledge(), ['model' => 'claude-haiku-5-5', 'system' => 'CAP', 'effort' => 'low', 'max_tokens' => 2000,
			'reporter' => function () use (&$saved) { $saved++; return 'Saved as report #1.'; }]))->capture('TURN');
		$this->assertCount(1, $g->requests);
		$this->assertSame([responder::REPORT_TOOL], array_column($g->requests[0]['tools'], 'name'));
		$this->assertSame('CAP', $g->requests[0]['system']);
		$this->assertSame('TURN', $g->requests[0]['messages'][0]['content']);
		$this->assertTrue($c['reported']);
		$this->assertSame(1, $saved, 'one report per topic, however many calls');
		$this->assertSame('Saved as report #1.', $c['said']);
		$this->assertSame('claude-haiku-5-5', $c['calls'][0]['model']);
	}

	public function testCaptureOfANonBug(): void
	{
		$g = new FakeGateway(fn () => self::reply('end_turn', [], 'NO'));
		$c = (new responder($g, new FakeKnowledge(), ['model' => 'm', 'system' => 'S', 'reporter' => fn () => 'x']))->capture('T');
		$this->assertFalse($c['reported']);
		$this->assertSame('', $c['said']);
	}
}
