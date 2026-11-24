<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * Keeps the docs index current from the published knowledge bundle.
 *
 * Weekly (or when an admin presses "Update now"): read manifest.json,
 * and if its sha256 differs from the loaded bundle's, download the
 * bundle, check its size and sha256, and load it into the idle
 * generation a slice at a time. Only when every chunk is in does the
 * search switch over. Resumable: each cron run continues where the
 * last one stopped, so a host's time limit cannot leave a broken index.
 *
 * Fails closed: a bundle that does not match its manifest, or a format
 * this code does not know, is refused and the current index stays.
 */
class kb_updater
{
	public const FORMAT = 1;
	public const MAX_BYTES = 50 * 1024 * 1024;
	public const RECHECK = 7 * 86400;
	public const RETRY_AFTER = 86400;

	protected \phpbb\config\config $config;
	protected \phpbb\config\db_text $config_text;
	protected knowledge_base $kb;
	protected \phpbb\db\driver\driver_interface $db;
	protected string $store_dir;
	/** @var callable(string,int):string */
	protected $fetch;

	public function __construct(\phpbb\config\config $config, \phpbb\config\db_text $config_text, knowledge_base $kb,
		\phpbb\db\driver\driver_interface $db, string $root_path, ?callable $fetch = null)
	{
		$this->config = $config;
		$this->config_text = $config_text;
		$this->kb = $kb;
		$this->db = $db;
		$this->store_dir = rtrim($root_path, '/') . '/store';
		$this->fetch = $fetch ?? [$this, 'http_get'];
	}

	public function state(): array
	{
		$s = json_decode((string) $this->config_text->get('beyondbot_kb_state'), true);
		return is_array($s) ? $s : ['phase' => 'idle'];
	}

	protected function save_state(array $s): void
	{
		$this->config_text->set('beyondbot_kb_state', json_encode($s));
	}

	/** Is there work for the cron task? */
	public function due(): bool
	{
		if (($this->state()['phase'] ?? 'idle') !== 'idle')
		{
			return true;
		}
		if ((string) $this->config['beyondbot_kb_url'] === '')
		{
			return false;
		}
		return !empty($this->config['beyondbot_kb_force'])
			|| time() - (int) $this->config['beyondbot_kb_checked'] >= self::RECHECK;
	}

	/**
	 * Do up to $budget seconds of work.
	 *
	 * @return string what happened, for the log
	 */
	public function step(float $budget = 20.0): string
	{
		$deadline = microtime(true) + $budget;
		$state = $this->state();
		try
		{
			if (($state['phase'] ?? 'idle') === 'idle')
			{
				$started = $this->start();
				if ($started === null)
				{
					return 'unchanged';
				}
				$state = $started;
			}
			return $this->load($state, $deadline);
		}
		catch (\Throwable $e)
		{
			// Keep what we have, try again tomorrow.
			$this->abandon($state);
			$this->config->set('beyondbot_kb_checked', time() - self::RECHECK + self::RETRY_AFTER);
			$this->config->set('beyondbot_kb_force', 0);
			throw $e;
		}
	}

	/** @return array|null the loading state, or null when the bundle is unchanged */
	protected function start(): ?array
	{
		$manifest_url = (string) $this->config['beyondbot_kb_url'];
		$manifest = json_decode(($this->fetch)($manifest_url, 64 * 1024), true);
		self::check_manifest($manifest);

		$this->config->set('beyondbot_kb_force', 0);
		if (hash_equals((string) $this->config['beyondbot_kb_sha'], $manifest['sha256']))
		{
			$this->config->set('beyondbot_kb_checked', time());
			return null;
		}

		$file_url = substr($manifest_url, 0, strrpos($manifest_url, '/') + 1) . $manifest['file'];
		$data = ($this->fetch)($file_url, self::MAX_BYTES);
		if (strlen($data) !== (int) $manifest['size'] || !hash_equals($manifest['sha256'], hash('sha256', $data)))
		{
			throw new \RuntimeException('The knowledge bundle does not match its manifest (size or sha256).');
		}
		if (!is_dir($this->store_dir) || !is_writable($this->store_dir))
		{
			throw new \RuntimeException('The store/ folder is not writable.');
		}
		$path = $this->store_dir . '/beyondbot-knowledge.ndjson.gz';
		if (file_put_contents($path, $data) !== strlen($data))
		{
			throw new \RuntimeException('Could not write ' . $path);
		}
		$gen = 1 - (int) $this->config['beyondbot_kb_gen'];
		$this->kb->clear($gen);
		$state = ['phase' => 'loading', 'file' => $path, 'line' => 0, 'gen' => $gen, 'manifest' => $manifest, 'primer' => ''];
		$this->save_state($state);
		return $state;
	}

	public static function check_manifest($m): void
	{
		if (!is_array($m))
		{
			throw new \RuntimeException('The knowledge manifest is not JSON.');
		}
		if ((int) ($m['format'] ?? 0) !== self::FORMAT)
		{
			throw new \RuntimeException('The knowledge bundle has format ' . (int) ($m['format'] ?? 0) . '; this version of Beyond Bot reads format ' . self::FORMAT . '. Update the extension.');
		}
		if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', (string) ($m['file'] ?? '')))
		{
			throw new \RuntimeException('The knowledge manifest names a bad file.');
		}
		if (!preg_match('/^[0-9a-f]{64}$/', (string) ($m['sha256'] ?? '')))
		{
			throw new \RuntimeException('The knowledge manifest has no valid sha256.');
		}
		$size = (int) ($m['size'] ?? 0);
		if ($size <= 0 || $size > self::MAX_BYTES)
		{
			throw new \RuntimeException('The knowledge bundle size is out of range.');
		}
		if ((int) ($m['chunks'] ?? 0) <= 0)
		{
			throw new \RuntimeException('The knowledge manifest lists no chunks.');
		}
	}

	protected function load(array $state, float $deadline): string
	{
		$gz = @gzopen($state['file'], 'rb');
		if (!$gz)
		{
			throw new \RuntimeException('Could not open the downloaded bundle.');
		}
		$line_no = 0;
		$skip = (int) $state['line'];
		$done = false;
		$batch = 0;
		$this->db->sql_transaction('begin');
		while (true)
		{
			$line = gzgets($gz, 4 * 1024 * 1024);
			if ($line === false)
			{
				$done = true;
				break;
			}
			$line_no++;
			if ($line_no <= $skip || trim($line) === '')
			{
				continue;
			}
			$row = json_decode($line, true);
			if (!is_array($row))
			{
				throw new \RuntimeException('Line ' . $line_no . ' of the bundle is not JSON.');
			}
			if (isset($row['header']))
			{
				if ((int) ($row['header']['format'] ?? 0) !== self::FORMAT)
				{
					throw new \RuntimeException('The bundle header has an unknown format.');
				}
				$state['primer'] = (string) ($row['header']['primer'] ?? '');
				$state['built_at'] = (string) ($row['header']['built_at'] ?? '');
				$state['oxt_release'] = (string) ($row['header']['oxt_release'] ?? '');
			}
			else if (isset($row['chunk']))
			{
				$this->kb->insert_chunk((int) $state['gen'], $row['chunk']);
			}
			$state['line'] = $line_no;
			if (++$batch % 100 === 0)
			{
				$this->db->sql_transaction('commit');
				$this->save_state($state);
				if (microtime(true) >= $deadline)
				{
					break;
				}
				$this->db->sql_transaction('begin');
			}
		}
		gzclose($gz);
		if ($batch % 100 !== 0 || $done)
		{
			$this->db->sql_transaction('commit');
		}
		$this->save_state($state);
		if (!$done)
		{
			return 'loading: ' . $state['line'] . ' of ' . ((int) $state['manifest']['chunks'] + 1) . ' lines';
		}
		return $this->finish($state);
	}

	protected function finish(array $state): string
	{
		$gen = (int) $state['gen'];
		$expected = (int) $state['manifest']['chunks'];
		$got = $this->kb->count($gen);
		if ($got !== $expected || $state['primer'] === '')
		{
			throw new \RuntimeException("The bundle loaded {$got} of {$expected} chunks; keeping the current index.");
		}
		$old = (int) $this->config['beyondbot_kb_gen'];
		$this->config_text->set('beyondbot_primer', $state['primer']);
		$this->config->set('beyondbot_kb_gen', $gen);
		$this->config->set('beyondbot_kb_sha', $state['manifest']['sha256']);
		$this->config->set('beyondbot_kb_built', substr((string) ($state['built_at'] ?? ''), 0, 32));
		$this->config->set('beyondbot_kb_release', mb_substr((string) ($state['oxt_release'] ?? ''), 0, 100));
		$this->config->set('beyondbot_kb_chunks', $got);
		$this->config->set('beyondbot_kb_checked', time());
		$this->kb->clear($old);
		@unlink($state['file']);
		$this->save_state(['phase' => 'idle']);
		return "loaded {$got} chunks";
	}

	protected function abandon(array $state): void
	{
		if (!empty($state['file']))
		{
			@unlink($state['file']);
		}
		if (isset($state['gen']) && (int) $state['gen'] !== (int) $this->config['beyondbot_kb_gen'])
		{
			$this->kb->clear((int) $state['gen']);
		}
		$this->save_state(['phase' => 'idle']);
	}

	/** Plain HTTPS GET with a size cap; follows the redirect GitHub release downloads use. */
	public function http_get(string $url, int $max_bytes): string
	{
		if (!preg_match('#^https://#i', $url))
		{
			throw new \RuntimeException('Knowledge URLs must be https.');
		}
		if (!function_exists('curl_init'))
		{
			throw new \RuntimeException('PHP cURL is needed to download the knowledge bundle.');
		}
		$buf = '';
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_USERAGENT => 'BeyondBot-phpBB',
			CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, $max_bytes) {
				$buf .= $chunk;
				return strlen($buf) > $max_bytes ? 0 : strlen($chunk);
			},
		]);
		$ok = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($ok === false || $status !== 200)
		{
			throw new \RuntimeException("Download failed ({$status}) {$url}: {$err}");
		}
		return $buf;
	}
}
