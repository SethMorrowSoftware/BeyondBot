<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * One call to Claude's Messages API.
 *
 * The responder talks to this interface, never to the SDK, so its tool
 * loop is tested with a scripted fake and costs nothing to run.
 */
interface gateway
{
	/**
	 * @param array $request model, max_tokens, system (string), tools (array),
	 *                       messages (array), effort (string), tool_choice ('auto'|'none')
	 * @return array{
	 *   stop_reason: string|null,
	 *   content: mixed,
	 *   text: string,
	 *   tool_calls: list<array{id:string,name:string,input:array}>,
	 *   usage: array{input:int,output:int,cache_write:int,cache_read:int},
	 *   model: string
	 * } content is the assistant turn as the API returned it, passed back unchanged
	 * @throws gateway_error
	 */
	public function create(array $request): array;
}
