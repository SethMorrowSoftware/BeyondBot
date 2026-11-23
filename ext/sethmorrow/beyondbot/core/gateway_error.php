<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * A failed call, sorted by what the worker should do about it.
 *
 *  - RETRY:  rate limit, overload, timeout, server or network error; try again later
 *  - AUTH:   the key is wrong, revoked or out of credit; pause the bot, tell the admin
 *  - FATAL:  this request cannot succeed (bad request, too large); give up on the job
 */
class gateway_error extends \RuntimeException
{
	public const RETRY = 'retry';
	public const AUTH = 'auth';
	public const FATAL = 'fatal';

	public string $kind;

	public function __construct(string $kind, string $message, ?\Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
		$this->kind = $kind;
	}
}
