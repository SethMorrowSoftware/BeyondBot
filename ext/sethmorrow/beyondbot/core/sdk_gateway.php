<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PaymentRequiredException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\RequestTimeoutException;

/**
 * The real gateway: the official Anthropic PHP SDK.
 *
 * The SDK and its few dependencies live in this extension's own vendor/
 * folder and are loaded only here, when the cron worker is about to call
 * Claude, so ordinary page views never load them.
 *
 * Every request:
 *  - adaptive thinking, effort from the admin panel;
 *  - the system prompt marked for prompt caching (tools and system are
 *    the same on every call, so they are read from cache at a tenth of
 *    the price while questions keep coming);
 *  - server-side refusal fallbacks ("default"): if the chosen model
 *    declines on policy grounds, the server retries on its default
 *    substitute rather than leaving the member with nothing.
 */
class sdk_gateway implements gateway
{
	public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

	protected Client $client;

	public function __construct(string $api_key, string $base_url = '', float $timeout = 120.0)
	{
		$this->client = new Client(
			apiKey: $api_key,
			baseUrl: $base_url !== '' ? $base_url : null,
			requestOptions: ['timeout' => $timeout, 'maxRetries' => 1],
		);
	}

	public function create(array $request): array
	{
		try
		{
			$response = $this->client->beta->messages->create(
				maxTokens: (int) $request['max_tokens'],
				messages: $request['messages'],
				model: $request['model'],
				system: [
					['type' => 'text', 'text' => $request['system'], 'cacheControl' => ['type' => 'ephemeral']],
				],
				thinking: ['type' => 'adaptive'],
				outputConfig: ['effort' => $request['effort']],
				tools: $request['tools'],
				toolChoice: ['type' => $request['tool_choice'] === 'none' ? 'none' : 'auto'],
				fallbacks: 'default',
				betas: [self::FALLBACK_BETA],
			);
		}
		catch (AuthenticationException | PermissionDeniedException | PaymentRequiredException $e)
		{
			throw new gateway_error(gateway_error::AUTH, 'The API key was refused: ' . $e->getMessage(), $e);
		}
		catch (NotFoundException $e)
		{
			// Almost always a model id the account cannot use: every job would fail the same way.
			throw new gateway_error(gateway_error::AUTH, 'Model not found: ' . $request['model'], $e);
		}
		catch (RateLimitException | InternalServerException | RequestTimeoutException | APIConnectionException $e)
		{
			throw new gateway_error(gateway_error::RETRY, get_class($e) . ': ' . $e->getMessage(), $e);
		}
		catch (\Throwable $e)
		{
			throw new gateway_error(gateway_error::FATAL, get_class($e) . ': ' . $e->getMessage(), $e);
		}

		$text = '';
		$calls = [];
		foreach ($response->content as $block)
		{
			if ($block->type === 'text')
			{
				$text .= $block->text;
			}
			else if ($block->type === 'tool_use')
			{
				$calls[] = ['id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
			}
		}
		$u = $response->usage;
		return [
			'stop_reason' => $response->stopReason,
			'content' => $response->content,
			'text' => $text,
			'tool_calls' => $calls,
			'usage' => [
				'input' => (int) $u->inputTokens,
				'output' => (int) $u->outputTokens,
				'cache_write' => (int) ($u->cacheCreationInputTokens ?? 0),
				'cache_read' => (int) ($u->cacheReadInputTokens ?? 0),
			],
			'model' => (string) $response->model,
		];
	}
}
