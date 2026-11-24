<?php
use PHPUnit\Framework\TestCase;
use sethmorrow\beyondbot\acp\checklist;

final class ChecklistTest extends TestCase
{
	/** Rows shaped as make_forum_select(..., $return_array = true) returns them. */
	private function rows(): array
	{
		return [
			1 => ['forum_id' => 1, 'forum_name' => 'Projects', 'padding' => '', 'selected' => false, 'disabled' => true],
			35 => ['forum_id' => 35, 'forum_name' => 'OXT-Beyond', 'padding' => '&nbsp; &nbsp;', 'selected' => true, 'disabled' => false],
			37 => ['forum_id' => 37, 'forum_name' => 'Windows', 'padding' => '&nbsp; &nbsp;&nbsp; &nbsp;', 'selected' => false, 'disabled' => false],
			45 => ['forum_id' => 45, 'forum_name' => 'Bugs &amp; Features', 'padding' => '&nbsp; &nbsp;&nbsp; &nbsp;', 'selected' => true, 'disabled' => false],
		];
	}

	public function testEveryPostableForumIsItsOwnCheckbox(): void
	{
		$html = checklist::render('beyondbot_forum_ids', checklist::forum_items($this->rows()));
		// Each forum can be ticked on its own: no <select> whose plain click clears the rest.
		$this->assertStringNotContainsString('<select', $html);
		$this->assertSame(3, substr_count($html, 'type="checkbox"'));
		$this->assertSame(3, substr_count($html, 'name="beyondbot_forum_ids[]"'));
		$this->assertStringContainsString('value="35" checked="checked"', $html);
		$this->assertStringContainsString('value="45" checked="checked"', $html);
		$this->assertStringContainsString('value="37">', $html);
	}

	public function testCategoriesAreHeadingsNotChoices(): void
	{
		$html = checklist::render('beyondbot_forum_ids', checklist::forum_items($this->rows()));
		$this->assertStringContainsString('<strong>Projects</strong>', $html);
		$this->assertStringNotContainsString('value="1"', $html);
	}

	public function testSubforumsAreIndentedByDepth(): void
	{
		$items = checklist::forum_items($this->rows());
		$this->assertSame([0, 1, 2, 2], array_column($items, 'depth'));
		$html = checklist::render('beyondbot_forum_ids', $items);
		$this->assertStringContainsString('margin-left: 3em', $html);
	}

	public function testLabelsAreNotEscapedTwice(): void
	{
		// phpBB stores forum_name already escaped; the label is shown as stored.
		$html = checklist::render('x', checklist::forum_items($this->rows()));
		$this->assertStringContainsString('Bugs &amp; Features', $html);
		$this->assertStringNotContainsString('&amp;amp;', $html);
	}

	public function testGroupItems(): void
	{
		$html = checklist::render('beyondbot_group_ids', [
			['id' => 2, 'label' => 'Registered users', 'checked' => false],
			['id' => 5, 'label' => 'Administrators', 'checked' => true],
		]);
		$this->assertStringContainsString('name="beyondbot_group_ids[]" value="5" checked="checked"', $html);
		$this->assertStringContainsString('name="beyondbot_group_ids[]" value="2">', $html);
	}
}
