<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * Asks Claude one forum question, letting it look things up.
 *
 * Claude gets two read-only tools over the docs index. The loop is
 * bounded: after MAX_ROUNDS lookups it is asked to answer with what it
 * has (tool_choice none), so one question can never run up an open-ended
 * bill. Every call's usage is added up for the log and the spend cap.
 */
class responder
{
	public const MAX_ROUNDS = 4;
	public const RESULT_CHARS = 2500;   // per search hit
	public const CALL_CHARS = 14000;    // per tool result
	public const MAX_RECOVERY_TOKENS = 32000;
	public const ANSWER_NOW = 'You have used up the room for looking things up and thinking. Write your answer to the question now, from what you have already found. Keep it short; if something is still unsure, say so plainly.';

	public const REPORT_TOOL = 'record_bug_report';

	protected gateway $gateway;
	protected knowledge $knowledge;
	protected array $options;
	/** @var callable(array): string|null saves a bug report, returns what Claude is told */
	protected $reporter;

	/**
	 * @param array $options model, effort, max_tokens, system, and
	 *                       reporter: a callable that saves a bug report
	 *                       (when set, Claude gets the record_bug_report tool)
	 */
	public function __construct(gateway $gateway, knowledge $knowledge, array $options)
	{
		$this->gateway = $gateway;
		$this->knowledge = $knowledge;
		$this->reporter = $options['reporter'] ?? null;
		unset($options['reporter']);
		$this->options = $options + ['effort' => 'medium', 'max_tokens' => 8000];
	}

	/** The tool that saves a bug report. It writes only to the board's own reports table. */
	public static function report_tool(): array
	{
		$text = static fn (string $d) => ['type' => 'string', 'description' => $d];
		return [
			'name' => self::REPORT_TOOL,
			'description' => 'Save a bug report for the OXT-Beyond maintainers to look at later. Use it only when a post describes a bug in OXT-Beyond or an xTalk Suite extension: something that does not work as the docs say, a crash, a wrong result, a broken build or download. Not for questions, how-to help, feature requests or a mistake in the member\'s own script. Use it at most once per answer. It does not reply to anyone; still answer the member as usual.',
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'title' => $text('A short, specific title, as a GitHub issue title (under 100 characters).'),
					'component' => $text('The part affected: IDE, engine, web player, Android, standalone builder, dictionary or docs, or the extension name such as curlxt.'),
					'summary' => $text('What goes wrong, in two to five plain sentences.'),
					'steps' => $text('Steps or a short script that shows it, if the post gives them. Empty if not.'),
					'expected' => $text('What should happen. Empty if not clear.'),
					'actual' => $text('What happens instead, with any error text. Empty if not clear.'),
					'platform' => $text('Operating system and device, if the post says. Empty if not.'),
					'version' => $text('OXT-Beyond or extension version, if the post says. Empty if not.'),
				],
				'required' => ['title', 'component', 'summary', 'steps', 'expected', 'actual', 'platform', 'version'],
				'additionalProperties' => false,
			],
		];
	}

	/** @return list<array> the docs tools, plus the report tool when $with_report */
	public static function tools(bool $with_report = false): array
	{
		$tools = self::docs_tools();
		if ($with_report)
		{
			$tools[] = self::report_tool();
		}
		return $tools;
	}

	protected static function docs_tools(): array
	{
		return [
			[
				'name' => 'get_dictionary_entry',
				'description' => 'Get one entry of the OXT-Beyond dictionary or glossary by its exact name: a command, function, property, message, keyword, constant, operator or glossary term, for example "put", "the date", "answer", "mouseUp" or "behavior". Returns the syntax, summary, examples, description and a link. Use it for every language element you mention in an answer.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'name' => ['type' => 'string', 'description' => 'The exact name, as the dictionary spells it.'],
					],
					'required' => ['name'],
					'additionalProperties' => false,
				],
			],
			[
				'name' => 'search_docs',
				'description' => 'Search the OXT-Beyond documentation (dictionary, glossary, guides, LiveCode Builder docs, release notes, changelog) and the xTalk Suite extension docs (sodiumxt, curlxt, box2dxt and the rest). Keyword search: use the words the docs would use, such as handler names, property names or extension names. Returns the best matching pieces with links. Search again with other words if the first results miss.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'query' => ['type' => 'string', 'description' => 'Keywords to search for.'],
					],
					'required' => ['query'],
					'additionalProperties' => false,
				],
			],
		];
	}

	/**
	 * @return array{text:string,blocks:string,recovered:bool,stop_reason:?string,usage:array,model:string,queries:list<string>,rounds:int,calls:list<array>}
	 */
	public function answer(string $user_turn): array
	{
		$messages = [['role' => 'user', 'content' => $user_turn]];
		$usage = ['input' => 0, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0];
		$queries = [];
		$calls = [];
		$model = $this->options['model'];
		$result = null;

		for ($round = 0; $round <= self::MAX_ROUNDS; $round++)
		{
			$last = ($round === self::MAX_ROUNDS);
			try
			{
				$result = $this->gateway->create([
					'model' => $this->options['model'],
					'max_tokens' => (int) $this->options['max_tokens'],
					'effort' => $this->options['effort'],
					'system' => $this->options['system'],
					'tools' => self::tools($this->reporter !== null),
					'tool_choice' => $last ? 'none' : 'auto',
					'messages' => $messages,
				]);
			}
			catch (gateway_error $e)
			{
				$e->calls = $calls;
				throw $e;
			}
			foreach ($usage as $k => $v)
			{
				$usage[$k] = $v + (int) ($result['usage'][$k] ?? 0);
			}
			// Each call is priced on the model that actually answered it: a
			// refusal fallback may have moved it to another model.
			$calls[] = ['model' => $result['model'], 'usage' => $result['usage']];
			$model = $result['model'];

			if ($result['stop_reason'] !== 'tool_use' || !$result['tool_calls'])
			{
				break;
			}
			$tool_results = [];
			foreach ($result['tool_calls'] as $call)
			{
				[$content, $label] = $this->run_tool($call['name'], (array) $call['input']);
				$queries[] = $label;
				$tool_results[] = [
					'type' => 'tool_result',
					'toolUseID' => $call['id'],
					'content' => $content,
				];
			}
			$messages[] = ['role' => 'assistant', 'content' => $result['content']];
			$messages[] = ['role' => 'user', 'content' => $tool_results];
		}

		// Nothing to post, though Claude did not decline: it spent its
		// whole budget on thinking and lookups (stop_reason "max_tokens"),
		// as a hard question did on the live board. One more call, with no
		// tools, less thinking and twice the room, asks for the answer
		// from what it has found. The paid calls so far are kept either way.
		$recovered = false;
		if (trim($result['text'] ?? '') === '' && ($result['stop_reason'] ?? '') !== 'refusal')
		{
			$recovered = true;
			$messages = self::with_nudge($messages, self::ANSWER_NOW);
			try
			{
				$result = $this->gateway->create([
					'model' => $this->options['model'],
					'max_tokens' => min(self::MAX_RECOVERY_TOKENS, 2 * (int) $this->options['max_tokens']),
					'effort' => 'low',
					'system' => $this->options['system'],
					'tools' => self::tools($this->reporter !== null),
					'tool_choice' => 'none',
					'messages' => $messages,
				]);
			}
			catch (gateway_error $e)
			{
				$e->calls = $calls;
				throw $e;
			}
			foreach ($usage as $k => $v)
			{
				$usage[$k] = $v + (int) ($result['usage'][$k] ?? 0);
			}
			$calls[] = ['model' => $result['model'], 'usage' => $result['usage']];
			$model = $result['model'];
		}

		return [
			'text' => trim($result['text'] ?? ''),
			// What the last reply was made of ("thinking,text"), for the log:
			// it tells an answer that never came from one the filter removed.
			'blocks' => implode(',', array_map(static fn ($b) => (string) (is_array($b) ? ($b['type'] ?? '?') : ($b->type ?? '?')), (array) ($result['content'] ?? []))),
			'recovered' => $recovered,
			'stop_reason' => $result['stop_reason'] ?? null,
			'usage' => $usage,
			'model' => $model,
			'queries' => $queries,
			'rounds' => count($calls),
			'calls' => $calls,
		];
	}

	/**
	 * Read one new topic in a bug forum and save it as a report if it is
	 * one. A single call with only the report tool: no docs lookups and
	 * no reply, so it stays cheap (the admin picks a small model for it).
	 *
	 * @return array{reported:bool,said:string,stop_reason:?string,usage:array,model:string,calls:list<array>}
	 */
	public function capture(string $user_turn): array
	{
		$result = $this->gateway->create([
			'model' => $this->options['model'],
			'max_tokens' => (int) $this->options['max_tokens'],
			'effort' => $this->options['effort'],
			'system' => $this->options['system'],
			'tools' => [self::report_tool()],
			'tool_choice' => 'auto',
			'messages' => [['role' => 'user', 'content' => $user_turn]],
		]);
		$said = '';
		$reported = false;
		foreach ($result['tool_calls'] ?? [] as $call)
		{
			if ($call['name'] === self::REPORT_TOOL && $this->reporter !== null && !$reported)
			{
				$said = (string) ($this->reporter)((array) $call['input']);
				$reported = true;
			}
		}
		return [
			'reported' => $reported,
			'said' => $said,
			'stop_reason' => $result['stop_reason'] ?? null,
			'usage' => $result['usage'],
			'model' => $result['model'],
			'calls' => [['model' => $result['model'], 'usage' => $result['usage']]],
		];
	}

	/**
	 * @return array{0:string,1:string} tool result text and a log label
	 */
	public function run_tool(string $name, array $input): array
	{
		if ($name === 'get_dictionary_entry')
		{
			$term = trim((string) ($input['name'] ?? ''));
			$entry = $term === '' ? null : $this->knowledge->entry($term);
			if (!$entry)
			{
				// Fall back to a search, so a near miss ("put into") still helps.
				$hits = $term === '' ? [] : $this->knowledge->search($term, 3);
				$text = "No dictionary entry is named \"{$term}\".";
				if ($hits)
				{
					$text .= " Closest matches:\n\n" . self::format_hits($hits);
				}
				return [self::clip($text), 'entry: ' . $term . ' (not found)'];
			}
			return [self::clip(self::format_hits([$entry])), 'entry: ' . $term];
		}
		if ($name === 'search_docs')
		{
			$query = trim((string) ($input['query'] ?? ''));
			$hits = $query === '' ? [] : $this->knowledge->search($query, 8);
			if (!$hits)
			{
				return ['Nothing in the docs matched "' . $query . '". Try other words, or say you could not find it.', 'search: ' . $query . ' (0)'];
			}
			return [self::clip(self::format_hits($hits)), 'search: ' . $query . ' (' . count($hits) . ')'];
		}
		if ($name === self::REPORT_TOOL && $this->reporter !== null)
		{
			$said = (string) ($this->reporter)($input);
			return [$said, 'report: ' . mb_substr($said, 0, 60)];
		}
		return ['Unknown tool "' . $name . '".', 'unknown tool: ' . $name];
	}

	/** The conversation with a short instruction added to its last (user) turn. */
	protected static function with_nudge(array $messages, string $nudge): array
	{
		$i = count($messages) - 1;
		$content = $messages[$i]['content'];
		if (is_string($content))
		{
			$messages[$i]['content'] = $content . "\n\n" . $nudge;
		}
		else
		{
			$messages[$i]['content'][] = ['type' => 'text', 'text' => $nudge];
		}
		return $messages;
	}

	public static function format_hits(array $hits): string
	{
		$parts = [];
		foreach ($hits as $h)
		{
			$text = $h['text'];
			if (mb_strlen($text) > self::RESULT_CHARS)
			{
				$text = mb_substr($text, 0, self::RESULT_CHARS) . "\n[... more at the link]";
			}
			$parts[] = '### ' . $h['title'] . "\nLink: " . $h['url'] . "\n\n" . $text;
		}
		return implode("\n\n---\n\n", $parts);
	}

	protected static function clip(string $text): string
	{
		return mb_strlen($text) > self::CALL_CHARS ? mb_substr($text, 0, self::CALL_CHARS) . "\n[... results cut]" : $text;
	}
}
