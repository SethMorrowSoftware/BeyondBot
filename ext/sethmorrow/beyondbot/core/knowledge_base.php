<?php
/**
 * Beyond Bot
 *
 * @copyright (c) 2026 Seth Morrow
 * @license GNU General Public License, version 2 (GPL-2.0-only)
 */

namespace sethmorrow\beyondbot\core;

/**
 * The docs index, in two tables:
 *
 *   beyondbot_chunks  (gen, chunk_id, kind, title, lookup_key, url, body)
 *   beyondbot_terms   (gen, term, chunk_id, weight)
 *
 * Term weights are BM25, computed when the bundle was built
 * (tools/build_knowledge.py), so a search is one indexed SELECT that adds
 * them up: portable to MySQL, PostgreSQL and SQLite alike.
 *
 * Two generations live side by side: an update loads the idle one and
 * then flips beyondbot_kb_gen, so a search never sees half an index.
 */
class knowledge_base implements knowledge
{
	public const MAX_QUERY_TERMS = 12;

	/** Mirrors tools/build_knowledge.py STOPWORDS exactly. */
	public const STOPWORDS = ['a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'can', 'do', 'does',
		'for', 'from', 'has', 'have', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'me', 'my', 'no',
		'not', 'of', 'on', 'or', 'our', 'so', 'than', 'that', 'the', 'their', 'them', 'then', 'there',
		'these', 'they', 'this', 'to', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'who', 'why',
		'will', 'with', 'you', 'your'];

	protected \phpbb\db\driver\driver_interface $db;
	protected \phpbb\config\config $config;
	protected string $chunks_table;
	protected string $terms_table;

	public function __construct(\phpbb\db\driver\driver_interface $db, \phpbb\config\config $config, string $table_prefix)
	{
		$this->db = $db;
		$this->config = $config;
		$this->chunks_table = $table_prefix . 'beyondbot_chunks';
		$this->terms_table = $table_prefix . 'beyondbot_terms';
	}

	/** Same rules as the builder: [a-z0-9_]+, length 2 or more, no stopwords. */
	public static function tokenize(string $text): array
	{
		preg_match_all('/[a-z0-9_]+/', strtolower($text), $m);
		$out = [];
		foreach ($m[0] as $t)
		{
			if (strlen($t) >= 2 && !in_array($t, self::STOPWORDS, true))
			{
				$out[] = $t;
			}
		}
		return $out;
	}

	protected function gen(): int
	{
		return (int) $this->config['beyondbot_kb_gen'];
	}

	public function search(string $query, int $limit = 8): array
	{
		$terms = array_slice(array_values(array_unique(self::tokenize($query))), 0, self::MAX_QUERY_TERMS);
		$terms = array_map(static fn ($t) => substr($t, 0, 64), $terms);
		$ids = [];

		// An exact entry name ("answer", "the date") goes first.
		$exact = $this->entry($query);
		if ($exact)
		{
			$ids[] = (int) $exact['chunk_id'];
		}
		if ($terms)
		{
			$sql = 'SELECT chunk_id, SUM(weight) AS score
				FROM ' . $this->terms_table . '
				WHERE gen = ' . $this->gen() . '
					AND ' . $this->db->sql_in_set('term', $terms) . '
				GROUP BY chunk_id
				ORDER BY score DESC, chunk_id ASC';
			$result = $this->db->sql_query_limit($sql, $limit + 1);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$ids[] = (int) $row['chunk_id'];
			}
			$this->db->sql_freeresult($result);
		}
		$ids = array_slice(array_values(array_unique($ids)), 0, $limit);
		if (!$ids)
		{
			return [];
		}
		$rows = [];
		$sql = 'SELECT chunk_id, kind, title, url, body
			FROM ' . $this->chunks_table . '
			WHERE gen = ' . $this->gen() . '
				AND ' . $this->db->sql_in_set('chunk_id', $ids);
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[(int) $row['chunk_id']] = self::hit($row);
		}
		$this->db->sql_freeresult($result);
		$out = [];
		foreach ($ids as $id)
		{
			if (isset($rows[$id]))
			{
				$out[] = $rows[$id];
			}
		}
		return $out;
	}

	public function entry(string $name): ?array
	{
		$name = mb_strtolower(trim($name));
		$candidates = [$name];
		if (str_starts_with($name, 'the '))
		{
			$candidates[] = substr($name, 4);
		}
		foreach ($candidates as $key)
		{
			if ($key === '' || mb_strlen($key) > 100)
			{
				continue;
			}
			// Dictionary entries before glossary ones of the same name.
			$sql = 'SELECT chunk_id, kind, title, url, body
				FROM ' . $this->chunks_table . '
				WHERE gen = ' . $this->gen() . "
					AND lookup_key = '" . $this->db->sql_escape($key) . "'
				ORDER BY kind ASC, chunk_id ASC";
			$result = $this->db->sql_query_limit($sql, 1);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if ($row)
			{
				return self::hit($row);
			}
		}
		return null;
	}

	protected static function hit(array $row): array
	{
		return [
			'chunk_id' => (int) $row['chunk_id'],
			'title' => (string) $row['title'],
			'url' => (string) $row['url'],
			'kind' => (string) $row['kind'],
			'text' => (string) $row['body'],
		];
	}

	public function count(?int $gen = null): int
	{
		$sql = 'SELECT COUNT(chunk_id) AS n FROM ' . $this->chunks_table . ' WHERE gen = ' . ($gen ?? $this->gen());
		$result = $this->db->sql_query($sql);
		$n = (int) $this->db->sql_fetchfield('n');
		$this->db->sql_freeresult($result);
		return $n;
	}

	public function clear(int $gen): void
	{
		$this->db->sql_query('DELETE FROM ' . $this->terms_table . ' WHERE gen = ' . $gen);
		$this->db->sql_query('DELETE FROM ' . $this->chunks_table . ' WHERE gen = ' . $gen);
	}

	/**
	 * Store one chunk line of a bundle into a generation.
	 */
	public function insert_chunk(int $gen, array $c): void
	{
		$this->db->sql_query('INSERT INTO ' . $this->chunks_table . ' ' . $this->db->sql_build_array('INSERT', [
			'gen' => $gen,
			'chunk_id' => (int) $c['id'],
			'kind' => substr((string) $c['kind'], 0, 16),
			'title' => mb_substr((string) $c['title'], 0, 255),
			'lookup_key' => mb_substr((string) ($c['key'] ?? ''), 0, 100),
			'url' => substr((string) $c['url'], 0, 255),
			'body' => (string) $c['text'],
		]));
		$rows = [];
		foreach ($c['terms'] ?? [] as $t)
		{
			$rows[] = [
				'gen' => $gen,
				'term' => substr((string) $t[0], 0, 64),
				'chunk_id' => (int) $c['id'],
				'weight' => max(1, (int) $t[1]),
			];
		}
		if ($rows)
		{
			$this->db->sql_multi_insert($this->terms_table, $rows);
		}
	}
}
