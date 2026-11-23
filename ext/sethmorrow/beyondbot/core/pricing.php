<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * What a call costs, from the token counts the API returns.
 *
 * Prices are US dollars per million tokens (Anthropic's list prices,
 * 2026-11). Cache writes are 1.25 times the input price (5-minute
 * cache), cache reads 0.1 times, except where the list price says
 * otherwise. A model not in the table is charged at the admin's custom
 * prices, and if those are empty at the dearest model's prices, so the
 * monthly cap can only stop too early, never too late.
 *
 * Costs are kept in micro-dollars (integers) so sums do not drift.
 */
class pricing
{
	/** model id => [input, output, cache write, cache read] per million tokens */
	public const PRICES = [
		'claude-opus-5-5'   => [4.00, 20.00, 5.00, 0.20],
		'claude-sonnet-5-5' => [2.00, 10.00, 2.50, 0.10],
		'claude-haiku-5-5'  => [0.10, 0.50, 0.125, 0.01],
		'claude-fable-5-2'  => [8.00, 40.00, 10.00, 0.20],
		// Fallback targets the server may route a refused request to.
		'claude-opus-5'     => [5.00, 25.00, 6.25, 0.50],
		'claude-opus-4-8'   => [5.00, 25.00, 6.25, 0.50],
		'claude-sonnet-5'   => [2.00, 10.00, 2.50, 0.20],
	];

	/** The models the admin panel offers, in order. */
	public const CHOICES = [
		'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (recommended)',
		'claude-opus-5-5'   => 'Claude Opus 5.5 (strongest, about twice the cost)',
		'claude-haiku-5-5'  => 'Claude Haiku 5.5 (cheapest)',
		'claude-fable-5-2'  => 'Claude Fable 5.2 (most capable, about four times the cost)',
	];

	/**
	 * @param array{input:int,output:int,cache_write:int,cache_read:int} $usage
	 * @param array{0:float,1:float}|null $custom input and output price for an unlisted model
	 * @return int micro-dollars, rounded up
	 */
	public static function cost_micro(string $model, array $usage, ?array $custom = null): int
	{
		[$in, $out, $cw, $cr] = self::prices($model, $custom);
		// tokens * dollars-per-million = micro-dollars
		$micro = ($usage['input'] ?? 0) * $in
			+ ($usage['output'] ?? 0) * $out
			+ ($usage['cache_write'] ?? 0) * $cw
			+ ($usage['cache_read'] ?? 0) * $cr;
		return (int) ceil($micro);
	}

	/** @return array{0:float,1:float,2:float,3:float} */
	public static function prices(string $model, ?array $custom = null): array
	{
		foreach (self::PRICES as $id => $p)
		{
			if ($model === $id)
			{
				return $p;
			}
		}
		if ($custom && ($custom[0] ?? 0) > 0 && ($custom[1] ?? 0) > 0)
		{
			$in = (float) $custom[0];
			$out = (float) $custom[1];
			return [$in, $out, $in * 1.25, $in * 0.1];
		}
		$max = [0, 0, 0, 0];
		foreach (self::PRICES as $p)
		{
			for ($i = 0; $i < 4; $i++)
			{
				$max[$i] = max($max[$i], $p[$i]);
			}
		}
		return $max;
	}

	public static function dollars(int $micro): string
	{
		return '$' . number_format($micro / 1000000, 4);
	}
}
