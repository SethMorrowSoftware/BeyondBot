<?php
/**
 * A stand-in for the Claude Messages API, for the end-to-end test.
 *
 * Run with PHP's built-in server: php -S 127.0.0.1:PORT fake_anthropic.php
 * Every request is appended to $FAKE_LOG (JSON lines) so the test can
 * check what Beyond Bot sent: headers, model, system prompt, tools, the
 * user turn, and that tool results came back.
 *
 * What it answers is chosen by a marker word in the question:
 *   MARKER_REFUSE  stop_reason "refusal"
 *   MARKER_AUTH    HTTP 401
 *   MARKER_RETRY   HTTP 529 overloaded
 *   MARKER_INJECT  an answer full of things the filter must remove
 *   (none)         a lookup of "put", then an answer that uses it
 */

$log = getenv('FAKE_LOG') ?: sys_get_temp_dir() . '/fake_anthropic.jsonl';
$body = file_get_contents('php://input');
$req = json_decode($body, true) ?: [];
$headers = function_exists('getallheaders') ? getallheaders() : [];
file_put_contents($log, json_encode([
	'path' => $_SERVER['REQUEST_URI'] ?? '',
	'headers' => array_change_key_case($headers, CASE_LOWER),
	'body' => $req,
]) . "\n", FILE_APPEND);

header('Content-Type: application/json');
header('request-id: req_fake');

$messages = $req['messages'] ?? [];
$first = $messages[0]['content'] ?? '';
$first = is_string($first) ? $first : json_encode($first);
$last = end($messages);
$is_tool_turn = is_array($last['content'] ?? null) && ($last['content'][0]['type'] ?? '') === 'tool_result';
$model = $req['model'] ?? 'claude-sonnet-5-5';

function reply(array $content, string $stop, string $model, array $usage = []): void
{
	echo json_encode([
		'id' => 'msg_' . bin2hex(random_bytes(4)),
		'type' => 'message',
		'role' => 'assistant',
		'model' => $model,
		'content' => $content,
		'stop_reason' => $stop,
		'stop_sequence' => null,
		'usage' => $usage + [
			'input_tokens' => 1000,
			'output_tokens' => 200,
			'cache_creation_input_tokens' => 0,
			'cache_read_input_tokens' => 3000,
		],
	]);
	exit;
}

function error_reply(int $status, string $type, string $message): void
{
	http_response_code($status);
	echo json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]);
	exit;
}

if (str_contains($first, 'MARKER_AUTH'))
{
	error_reply(401, 'authentication_error', 'invalid x-api-key');
}
if (str_contains($first, 'MARKER_RETRY'))
{
	header('x-should-retry: false');
	error_reply(529, 'overloaded_error', 'Overloaded');
}
// The budget runs out before any text: the bot asks once more, with no tools.
$asked_to_answer_now = str_contains(json_encode($last), 'Write your answer to the question now');
if (str_contains($first, 'MARKER_EMPTY'))
{
	if ($asked_to_answer_now && ($req['tool_choice']['type'] ?? '') === 'none' && !str_contains($first, 'MARKER_EMPTY_TWICE'))
	{
		reply([['type' => 'text', 'text' => 'Recovered answer: use [b]revShell[/b].']], 'end_turn', $model);
	}
	reply([['type' => 'thinking', 'thinking' => '...', 'signature' => 'sig_long']], 'max_tokens', $model, ['output_tokens' => 8000]);
}
if (str_contains($first, 'MARKER_REFUSE'))
{
	reply([], 'refusal', $model);
}
if (str_contains($first, 'MARKER_INJECT'))
{
	reply([['type' => 'text', 'text' =>
		"**Sure.** Here is the answer.\n\n"
		. "[img]https://evil.example/track.png[/img]\n"
		. "See [url=https://evil.example/phish]this page[/url] and [url=https://github.com/SethMorrowSoftware/OpenXTalk-Beyond]the source[/url].\n"
		. "Also ask @Beyond Bot again.\n"
		. "[code]put \"[img]x[/img]\" into field 1[/code]\n"
		. "Use [code]repeat for each line[/code] for speed.\n"
		. "Read [dict]open file[/dict] and [dict]the \"x\"[/dict].\n"
		. "Sort by tArray[each], then read tData[i] and keep going.\n"
		. "[size=200]big[/size] [color=red]red[/color]",
	]], 'end_turn', $model);
}

if (str_contains($first, 'MARKER_FAIL2') && $is_tool_turn)
{
	// The first call (a lookup) succeeded and is paid for; the second fails.
	header('x-should-retry: false');
	error_reply(529, 'overloaded_error', 'Overloaded');
}

if (!$is_tool_turn)
{
	// First call: think, then look up "put".
	reply([
		['type' => 'thinking', 'thinking' => '', 'signature' => 'sig_fake_1'],
		['type' => 'tool_use', 'id' => 'toolu_fake_1', 'name' => 'get_dictionary_entry', 'input' => ['name' => 'put']],
	], 'tool_use', $model, ['input_tokens' => 1500, 'output_tokens' => 80, 'cache_creation_input_tokens' => 2500, 'cache_read_input_tokens' => 0]);
}

$result = $last['content'][0]['content'] ?? '';
$result = is_string($result) ? $result : json_encode($result);
$found = str_contains($result, 'put (command)') ? 'FOUND_PUT_ENTRY' : 'NO_PUT_ENTRY';
reply([['type' => 'text', 'text' =>
	"Use the [b]put[/b] command:\n\n[code]put \"Hello\" into field \"Greeting\"[/code]\n\n"
	. "See [url=https://github.com/SethMorrowSoftware/OpenXTalk-Beyond/blob/main/docs/dictionary/command/put.lcdoc]put in the dictionary[/url]. {$found}",
]], 'end_turn', $model);
