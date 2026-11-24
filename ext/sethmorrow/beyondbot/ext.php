<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot;

class ext extends \phpbb\extension\base
{
	/**
	 * PHP 8.1 or newer (the Anthropic SDK needs it), phpBB 3.3, and the
	 * bundled vendor/ folder (the release package has it; a bare source
	 * checkout does not until `composer install` is run in it).
	 */
	public function is_enableable()
	{
		return PHP_VERSION_ID >= 80100
			&& phpbb_version_compare(PHPBB_VERSION, '3.3.0', '>=')
			&& phpbb_version_compare(PHPBB_VERSION, '4.0.0-dev', '<')
			&& extension_loaded('curl')
			&& function_exists('gzopen')
			&& is_file(__DIR__ . '/vendor/autoload.php');
	}
}
