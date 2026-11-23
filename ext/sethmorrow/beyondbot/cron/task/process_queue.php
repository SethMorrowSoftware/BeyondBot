<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\cron\task;

use sethmorrow\beyondbot\core\store;
use sethmorrow\beyondbot\core\worker;

/**
 * Answers queued questions.
 *
 * Best run by the server's own cron (php bin/phpbbcli.php cron:run, every
 * minute): a Claude call takes seconds to a minute, too long to hang on a
 * visitor's page view, which is how phpBB's built-in cron runs.
 * A database lock keeps two runs from answering the same question twice.
 */
class process_queue extends \phpbb\cron\task\base
{
	protected \phpbb\config\config $config;
	protected \phpbb\db\driver\driver_interface $db;
	protected store $store;
	protected worker $worker;

	public function __construct(\phpbb\config\config $config, \phpbb\db\driver\driver_interface $db, store $store, worker $worker)
	{
		$this->config = $config;
		$this->db = $db;
		$this->store = $store;
		$this->worker = $worker;
	}

	public function is_runnable()
	{
		return (bool) $this->config['beyondbot_enabled'] && (int) $this->config['beyondbot_user_id'] > 0;
	}

	public function should_run()
	{
		return time() - (int) $this->config['beyondbot_queue_last_run'] >= 50
			&& $this->store->pending_count(time()) > 0;
	}

	public function run()
	{
		$lock = new \phpbb\lock\db('beyondbot_queue_lock', $this->config, $this->db);
		if (!$lock->acquire())
		{
			return;
		}
		try
		{
			$this->config->set('beyondbot_queue_last_run', time(), false);
			$this->worker->run(max(1, (int) $this->config['beyondbot_batch']), (float) max(20, (int) $this->config['beyondbot_run_budget']));
		}
		finally
		{
			$lock->release();
		}
	}
}
