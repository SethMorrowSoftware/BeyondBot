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

	protected gateway $gateway;
	protected knowledge $knowledge;
	protected array $options;

	/**
	 * @param array $options model, effort, max_tokens, system
	 */
	public function __construct(gateway $gateway, knowledge $knowledge, array $options)
	{
		$this->gateway = $gateway;
		$this->knowledge = $knowledge;
		$this->options = $options + ['effort' => 'medium', 'max_tokens' => 8000];
	}

	public static function tools(): array
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
	 * @return array{text:string,stop_reason:?string,usage:array,model:string,queries:list<string>,rounds:int,calls:list<array>}
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
					'tools' => self::tools(),
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

		return [
			'text' => trim($result['text'] ?? ''),
			'stop_reason' => $result['stop_reason'] ?? null,
			'usage' => $usage,
			'model' => $model,
			'queries' => $queries,
			'rounds' => count($calls),
			'calls' => $calls,
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
		return ['Unknown tool "' . $name . '".', 'unknown tool: ' . $name];
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
