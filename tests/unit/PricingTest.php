<?php
use PHPUnit\Framework\TestCase;
use sethmorrow\beyondbot\core\pricing;

final class PricingTest extends TestCase
{
	public function testListedModel(): void
	{
		$u = ['input' => 1000000, 'output' => 1000000, 'cache_write' => 1000000, 'cache_read' => 1000000];
		$this->assertSame((int) ((2 + 10 + 2.5 + 0.1) * 1e6), pricing::cost_micro('claude-sonnet-5-5', $u));
		$this->assertSame((int) ((4 + 20 + 5 + 0.2) * 1e6), pricing::cost_micro('claude-opus-5-5', $u));
	}

	public function testRoundsUp(): void
	{
		$this->assertSame(1, pricing::cost_micro('claude-haiku-5-5', ['input' => 1, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0]));
		$this->assertSame(0, pricing::cost_micro('claude-haiku-5-5', ['input' => 0, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0]));
	}

	public function testCustomPrices(): void
	{
		$this->assertEqualsWithDelta([3.0, 15.0, 3.75, 0.3], pricing::prices('claude-new', [3, 15]), 1e-9);
		// Half-filled custom prices are ignored: the dearest prices apply.
		$this->assertSame(pricing::prices('claude-new'), pricing::prices('claude-new', [3, 0]));
	}

	public function testUnknownModelIsChargedAtTheDearestOfEachColumn(): void
	{
		$p = pricing::prices('claude-unknown');
		foreach (pricing::PRICES as $id => $row)
		{
			for ($i = 0; $i < 4; $i++)
			{
				$this->assertGreaterThanOrEqual($row[$i], $p[$i], "$id column $i");
			}
		}
	}

	public function testEveryChoiceIsPriced(): void
	{
		foreach (array_keys(pricing::CHOICES) as $id)
		{
			$this->assertArrayHasKey($id, pricing::PRICES);
		}
	}

	public function testDollars(): void
	{
		$this->assertSame('$0.0123', pricing::dollars(12345));
		$this->assertSame('$50.0000', pricing::dollars(50000000));
	}
}
