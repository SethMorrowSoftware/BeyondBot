<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\acp;

/**
 * A list of checkboxes for the settings page, in place of a multiple
 * <select>: a plain click on one of those clears every other choice, which
 * admins trying to pick a forum and its subforums ran into on the live board.
 */
class checklist
{
	/**
	 * @param string $name  the form field; posted as $name[]
	 * @param array  $items each ['id' => int, 'label' => HTML already escaped,
	 *                      'checked' => bool, 'heading' => bool (a category:
	 *                      shown, not choosable), 'depth' => int]
	 */
	public static function render(string $name, array $items): string
	{
		$out = '';
		foreach ($items as $item)
		{
			$indent = max(0, (int) ($item['depth'] ?? 0)) * 1.5;
			$style = $indent > 0 ? ' style="margin-left: ' . $indent . 'em"' : '';
			if (!empty($item['heading']))
			{
				$out .= '<div class="beyondbot-check-heading"' . $style . '><strong>' . $item['label'] . '</strong></div>';
				continue;
			}
			$id = (int) $item['id'];
			$out .= '<div' . $style . '><label><input type="checkbox" class="radio" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '[]" value="' . $id . '"'
				. (!empty($item['checked']) ? ' checked="checked"' : '') . '> ' . $item['label'] . '</label></div>';
		}
		return $out;
	}

	/**
	 * Rows from make_forum_select(..., $return_array = true) as items. Its
	 * 'padding' is a run of "&nbsp; &nbsp;" per level; a disabled row is a
	 * category or link, which cannot hold posts. forum_name is stored escaped.
	 */
	public static function forum_items(array $rows): array
	{
		$items = [];
		foreach ($rows as $row)
		{
			$items[] = [
				'id' => (int) $row['forum_id'],
				'label' => (string) $row['forum_name'],
				'checked' => !empty($row['selected']),
				'heading' => !empty($row['disabled']),
				'depth' => substr_count((string) ($row['padding'] ?? ''), '&nbsp; &nbsp;'),
			];
		}
		return $items;
	}
}
