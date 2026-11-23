<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\cron\task;

use sethmorrow\beyondbot\core\kb_updater;

/**
 * Keeps the docs index current: weekly, or right after "Update now".
 * Each run does at most about 20 seconds of loading and picks up where
 * the last one stopped.
 */
class refresh_knowledge extends \phpbb\cron\task\base
{
	protected \phpbb\config\config $config;
	protected \phpbb\db\driver\driver_interface $db;
	protected kb_updater $updater;
	protected \phpbb\log\log_interface $log;

	public function __construct(\phpbb\config\config $config, \phpbb\db\driver\driver_interface $db, kb_updater $updater, \phpbb\log\log_interface $log)
	{
		$this->config = $config;
		$this->db = $db;
		$this->updater = $updater;
		$this->log = $log;
	}

	public function is_runnable()
	{
		return (string) $this->config['beyondbot_kb_url'] !== '';
	}

	public function should_run()
	{
		return $this->updater->due();
	}

	public function run()
	{
		$lock = new \phpbb\lock\db('beyondbot_kb_lock', $this->config, $this->db);
		if (!$lock->acquire())
		{
			return;
		}
		try
		{
			$status = $this->updater->step(20.0);
			if (str_starts_with($status, 'loaded'))
			{
				$this->log->add('admin', ANONYMOUS, '127.0.0.1', 'LOG_BEYONDBOT_KB_UPDATED', false, [$status]);
			}
		}
		catch (\Throwable $e)
		{
			$this->log->add('critical', ANONYMOUS, '127.0.0.1', 'LOG_BEYONDBOT_KB_ERROR', false, [$e->getMessage()]);
		}
		finally
		{
			$lock->release();
		}
	}
}
