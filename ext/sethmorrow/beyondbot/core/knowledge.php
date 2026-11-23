<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * What Claude's two tools read: the docs index.
 */
interface knowledge
{
	/**
	 * @return list<array{title:string,url:string,kind:string,text:string}> best first
	 */
	public function search(string $query, int $limit = 8): array;

	/**
	 * One dictionary or glossary entry by its exact name, any case.
	 *
	 * @return array{title:string,url:string,kind:string,text:string}|null
	 */
	public function entry(string $name): ?array;
}
