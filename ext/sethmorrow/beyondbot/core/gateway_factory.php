<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * Makes the real gateway, loading the extension's vendor/ folder first.
 *
 * Invokable, so the worker takes it as a plain callable and tests pass
 * a closure that returns a fake.
 */
class gateway_factory
{
	protected string $vendor;

	public function __construct(string $root_path)
	{
		$this->vendor = rtrim($root_path, '/') . '/ext/sethmorrow/beyondbot/vendor/autoload.php';
	}

	public function __invoke(string $api_key, string $base_url = ''): gateway
	{
		if (!class_exists('\Anthropic\Client'))
		{
			if (!is_file($this->vendor))
			{
				throw new gateway_error(gateway_error::AUTH, 'The extension is missing its vendor/ folder. Install the release package, not the source.');
			}
			require_once $this->vendor;
		}
		return new sdk_gateway($api_key, $base_url);
	}
}
