<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * The admin panel's settings, read in one place.
 *
 * The API key is in config_text (never cached in the board's config
 * cache, never sent to a template); everything else is plain config.
 */
class settings
{
	protected \phpbb\config\config $config;
	protected \phpbb\config\db_text $config_text;

	public function __construct(\phpbb\config\config $config, \phpbb\config\db_text $config_text)
	{
		$this->config = $config;
		$this->config_text = $config_text;
	}

	public static function id_list(string $csv): array
	{
		$ids = [];
		foreach (preg_split('/[\s,]+/', $csv, -1, PREG_SPLIT_NO_EMPTY) as $id)
		{
			if (ctype_digit($id) && (int) $id > 0)
			{
				$ids[] = (int) $id;
			}
		}
		return array_values(array_unique($ids));
	}

	public function enabled(): bool
	{
		return (bool) $this->config['beyondbot_enabled'];
	}

	public function forum_ids(): array
	{
		return self::id_list((string) $this->config['beyondbot_forum_ids']);
	}

	public function bot_user_id(): int
	{
		return (int) $this->config['beyondbot_user_id'];
	}

	public function bot_name(): string
	{
		return (string) ($this->config['beyondbot_name'] ?: 'Beyond Bot');
	}

	public function api_key(): string
	{
		return trim((string) $this->config_text->get('beyondbot_api_key'));
	}

	public function model(): string
	{
		$custom = trim((string) $this->config['beyondbot_custom_model']);
		return $custom !== '' ? $custom : (string) $this->config['beyondbot_model'];
	}

	public function custom_prices(): ?array
	{
		$in = (float) $this->config['beyondbot_custom_price_in'];
		$out = (float) $this->config['beyondbot_custom_price_out'];
		return ($in > 0 && $out > 0) ? [$in, $out] : null;
	}

	public function system_prompt(array $board_tags = []): string
	{
		return prompt::system(
			(string) $this->config_text->get('beyondbot_primer'),
			(string) $this->config_text->get('beyondbot_extra'),
			$board_tags
		);
	}

	public function domains(): array
	{
		$list = preg_split('/[\s,]+/', (string) $this->config['beyondbot_domains'], -1, PREG_SPLIT_NO_EMPTY);
		return $list ?: output_filter::DEFAULT_DOMAINS;
	}

	public function set_error(string $message): void
	{
		$this->config->set('beyondbot_last_error', mb_substr($message, 0, 255));
	}

	public function get(string $name)
	{
		return $this->config['beyondbot_' . $name];
	}

	/** The shape limits::decide() reads. */
	public function for_limits(): array
	{
		return [
			'enabled' => $this->enabled(),
			'has_key' => $this->api_key() !== '',
			'api_error' => (string) $this->config['beyondbot_last_error'] !== '',
			'has_knowledge' => (int) $this->config['beyondbot_kb_chunks'] > 0
				&& trim((string) $this->config_text->get('beyondbot_primer')) !== '',
			'forum_ids' => $this->forum_ids(),
			'group_ids' => self::id_list((string) $this->config['beyondbot_group_ids']),
			'min_posts' => (int) $this->config['beyondbot_min_posts'],
			'user_daily' => (int) $this->config['beyondbot_user_daily'],
			'topic_max' => (int) $this->config['beyondbot_topic_max'],
			'board_daily' => (int) $this->config['beyondbot_board_daily'],
			'month_cap_micro' => (int) round(((float) $this->config['beyondbot_month_cap']) * 1000000),
			'blocked_topics' => self::id_list((string) $this->config['beyondbot_blocked_topics']),
		];
	}
}
