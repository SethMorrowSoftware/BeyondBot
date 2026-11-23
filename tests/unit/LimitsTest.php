<?php
use PHPUnit\Framework\TestCase;
use sethmorrow\beyondbot\core\limits;

final class LimitsTest extends TestCase
{
	private function s(array $o = []): array
	{
		return $o + ['enabled' => true, 'has_key' => true, 'has_knowledge' => true, 'forum_ids' => [2, 5],
			'group_ids' => [], 'min_posts' => 5, 'user_daily' => 5, 'topic_max' => 3, 'board_daily' => 100,
			'month_cap_micro' => 50000000, 'blocked_topics' => []];
	}

	private function q(array $o = []): array
	{
		return $o + ['forum_id' => 2, 'topic_id' => 10, 'topic_locked' => false, 'asker_is_bot' => false,
			'asker_ignored' => false, 'asker_group_ids' => [2], 'asker_posts' => 20, 'visibility' => 'approved',
			'age_seconds' => 60];
	}

	private function c(array $o = []): array
	{
		return $o + ['user_answers_24h' => 0, 'topic_answers' => 0, 'board_answers_24h' => 0, 'month_spent_micro' => 0];
	}

	public function testAnswersByDefault(): void
	{
		$this->assertSame([limits::ANSWER, ''], limits::decide($this->s(), $this->q(), $this->c()));
	}

	public static function cases(): array
	{
		return [
			'own post'        => [[], ['asker_is_bot' => true], [], limits::SKIP, 'own post'],
			'deleted'         => [[], ['visibility' => 'deleted'], [], limits::SKIP, 'post deleted'],
			'other forum'     => [[], ['forum_id' => 3], [], limits::SKIP, 'forum not allowed'],
			'no forums'       => [['forum_ids' => []], [], [], limits::SKIP, 'forum not allowed'],
			'blocked topic'   => [['blocked_topics' => [10]], [], [], limits::SKIP, 'topic blocked by a moderator'],
			'locked'          => [[], ['topic_locked' => true], [], limits::SKIP, 'topic locked'],
			'banned'          => [[], ['asker_ignored' => true], [], limits::SKIP, 'asker banned or inactive'],
			'group'           => [['group_ids' => [7]], [], [], limits::SKIP, 'asker not in an allowed group'],
			'few posts'       => [[], ['asker_posts' => 4], [], limits::SKIP, 'asker has too few posts'],
			'old'             => [[], ['age_seconds' => 86401], [], limits::SKIP, 'question older than a day'],
			'topic limit'     => [[], [], ['topic_answers' => 3], limits::SKIP, 'topic limit reached'],
			'unapproved'      => [[], ['visibility' => 'unapproved'], [], limits::WAIT, 'waiting for moderator approval'],
			'off'             => [['enabled' => false], [], [], limits::WAIT, 'bot switched off'],
			'no key'          => [['has_key' => false], [], [], limits::WAIT, 'no API key'],
			'no docs'         => [['has_knowledge' => false], [], [], limits::WAIT, 'docs index not loaded yet'],
			'cap'             => [[], [], ['month_spent_micro' => 50000000], limits::WAIT, 'monthly spend cap reached'],
			'cap zero'        => [['month_cap_micro' => 0], [], [], limits::WAIT, 'monthly spend cap reached'],
			'board'           => [[], [], ['board_answers_24h' => 100], limits::WAIT, 'board daily limit reached'],
			'user'            => [[], [], ['user_answers_24h' => 5], limits::USER_LIMIT, 'asker daily limit reached'],
		];
	}

	/** @dataProvider cases */
	public function testVerdicts(array $s, array $q, array $c, string $verdict, string $reason): void
	{
		$this->assertSame([$verdict, $reason], limits::decide($this->s($s), $this->q($q), $this->c($c)));
	}

	public function testExactlyAtLimitsStillAnswers(): void
	{
		$this->assertSame(limits::ANSWER, limits::decide($this->s(),
			$this->q(['asker_posts' => 5, 'age_seconds' => 86400]),
			$this->c(['user_answers_24h' => 4, 'topic_answers' => 2, 'board_answers_24h' => 99, 'month_spent_micro' => 49999999]))[0]);
	}

	public function testSkipBeatsWait(): void
	{
		// A post that must never be answered is closed, not left waiting for the bot to be switched on.
		$this->assertSame(limits::SKIP, limits::decide($this->s(['enabled' => false]), $this->q(['forum_id' => 9]), $this->c())[0]);
	}

	public function testAllowedGroupMatches(): void
	{
		$this->assertSame(limits::ANSWER, limits::decide($this->s(['group_ids' => ['7', 2]]), $this->q(['asker_group_ids' => ['2']]), $this->c())[0]);
	}
}
