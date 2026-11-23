"""Tests for tools/build_knowledge.py, on small trees made in a temp folder.

Run: python3 -m unittest discover -s tools/tests
"""

import gzip
import hashlib
import json
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
import build_knowledge as bk  # noqa: E402

PUT_LCDOC = """Name: put

Type: command

Syntax: put <value> [{into | after | before} <container>]

Summary:
Places a value into a <container>, or shows it in the <message box>.

Example:
put "Hello" into field "Greeting"

Description:
Use the <put> command to set the contents of a <variable|variables> or field.

References: get (command), container (glossary)
"""

GLOSSARY_LCDOC = """Name: AIFF

Synonyms: .aiff, aiff

Type: glossary

Description:
Audio Interchange File Format. A <format> for sound files developed by
Apple Computer and often used on <Mac OS|Mac OS systems>.
"""

CHANGELOG = """# Changelog

Intro text.

## 0.3.0 (2026-12-01)

- New thing

## 0.2.9 (2026-11-25)

- Older thing

## 0.2.8 (2026-11-20)

- Oldest kept

## 0.2.7 (2026-11-10)

- Kept too

## 0.2.6 (2026-11-01)

- Dropped from the bundle
"""

SUITE_README = """# SodiumXT

Intro paragraph about sodium.

## Quick start: encrypt a message

Use sxEncrypt to encrypt.

```
# not a heading, inside a fence
put sxEncrypt(tKey, tText) into tBox
```
"""


def write(root, rel, text):
    path = os.path.join(root, rel)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(text)


class Trees(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        t = self.tmp.name
        self.oxt = os.path.join(t, "oxt")
        self.suite = os.path.join(t, "suite")
        write(self.oxt, "docs/dictionary/command/put.lcdoc", PUT_LCDOC)
        write(self.oxt, "docs/glossary/a/AIFF.lcdoc", GLOSSARY_LCDOC)
        write(self.oxt, "docs/guides/Processing Text.md", "# Processing Text\n\n## Chunks\n\nUse word, line and item.\n")
        write(self.oxt, "CHANGELOG.md", CHANGELOG)
        write(self.suite, "sodiumxt/README.md", SUITE_README)
        write(self.suite, "sodiumxt/docs/api.md", "# API\n\n## sxEncrypt\n\nEncrypts.\n")
        write(self.suite, "sodiumxt/CLAUDE.md", "# agent notes\n\nnot for users\n")
        write(self.suite, "tests/README.md", "# tests\n\nnot for users\n")
        write(self.suite, "sodiumxt/third_party/lib/README.md", "# vendored\n\nnot ours\n")
        write(self.suite, "sodiumxt/src/notes.md", "# source notes\n\nnot docs\n")
        self.primer = os.path.join(t, "primer.md")
        write(t, "primer.md", "Release {{OXT_RELEASE}}.")

    def tearDown(self):
        self.tmp.cleanup()

    def build(self):
        return bk.build(self.oxt, self.suite, "abc123", "def456", self.primer, built_at="2026-01-01T00:00:00Z")

    def test_dictionary_entry_is_one_chunk_with_key_and_link(self):
        b = self.build()
        put = [c for c in b["chunks"] if c["key"] == "put"]
        self.assertEqual(len(put), 1)
        c = put[0]
        self.assertEqual(c["kind"], "dictionary")
        self.assertEqual(c["title"], "put (command)")
        self.assertEqual(c["url"], "https://github.com/SethMorrowSoftware/OpenXTalk-Beyond/blob/abc123/docs/dictionary/command/put.lcdoc")
        self.assertIn('put "Hello" into field "Greeting"', c["text"])
        # <refs> are made plain, <a|b> keeps the shown words
        self.assertIn("contents of a variables or field", c["text"])
        self.assertNotIn("<", c["text"].split("Syntax:")[0])

    def test_glossary_kind_and_aliases(self):
        b = self.build()
        aiff = [c for c in b["chunks"] if c["key"] == "aiff"][0]
        self.assertEqual(aiff["kind"], "glossary")
        self.assertIn("Mac OS systems", aiff["text"])
        self.assertNotIn("Mac OS|", aiff["text"])

    def test_markdown_split_by_heading_and_fence_respected(self):
        b = self.build()
        titles = [c["title"] for c in b["chunks"] if c["kind"] == "suite"]
        self.assertIn("xTalk Suite sodiumxt: SodiumXT > Quick start: encrypt a message", titles)
        quick = [c for c in b["chunks"] if c["title"].endswith("Quick start: encrypt a message")][0]
        self.assertIn("# not a heading, inside a fence", quick["text"])

    def test_suite_skips_agent_notes_tests_vendored_and_source(self):
        b = self.build()
        text = " ".join(c["text"] for c in b["chunks"])
        for word in ("agent notes", "not for users", "not ours", "not docs"):
            self.assertNotIn(word, text)

    def test_changelog_keeps_four_newest_releases(self):
        b = self.build()
        log = " ".join(c["text"] for c in b["chunks"] if c["kind"] == "changelog")
        self.assertIn("New thing", log)
        self.assertIn("Kept too", log)
        self.assertNotIn("Dropped from the bundle", log)

    def test_primer_gets_current_release(self):
        b = self.build()
        self.assertEqual(b["oxt_release"], "0.3.0 (2026-12-01)")
        self.assertEqual(b["primer"], "Release 0.3.0 (2026-12-01).")

    def test_index_finds_the_right_chunk(self):
        b = self.build()
        scores = {}
        for term, chunk, weight in b["terms"]:
            if term in bk.tokenize("encrypt sodium"):
                scores[chunk] = scores.get(chunk, 0) + weight
        best = max(scores, key=scores.get)
        self.assertIn("SodiumXT", b["chunks"][best]["title"])
        for term, chunk, weight in b["terms"]:
            self.assertIsInstance(weight, int)
            self.assertGreater(weight, 0)
            self.assertLess(chunk, len(b["chunks"]))

    def test_ids_are_positions_and_build_is_deterministic(self):
        a, b = self.build(), self.build()
        self.assertEqual([c["id"] for c in a["chunks"]], list(range(len(a["chunks"]))))
        out1, out2 = os.path.join(self.tmp.name, "o1"), os.path.join(self.tmp.name, "o2")
        m1, m2 = bk.write(a, out1), bk.write(b, out2)
        self.assertEqual(m1["sha256"], m2["sha256"])

    def test_builder_fingerprint_follows_the_primer(self):
        a = self.build()["builder"]
        self.assertRegex(a, r"^[0-9a-f]{16}$")
        self.assertEqual(a, self.build()["builder"])
        write(self.tmp.name, "primer.md", "Another primer {{OXT_RELEASE}}.")
        self.assertNotEqual(a, self.build()["builder"])
        m = bk.write(self.build(), os.path.join(self.tmp.name, "o3"))
        self.assertEqual(m["builder"], self.build()["builder"])

    def test_manifest_hash_matches_file(self):
        out = os.path.join(self.tmp.name, "out")
        m = bk.write(self.build(), out)
        with open(os.path.join(out, m["file"]), "rb") as f:
            data = f.read()
        self.assertEqual(hashlib.sha256(data).hexdigest(), m["sha256"])
        self.assertEqual(len(data), m["size"])
        self.assertEqual(m["file"], "beyondbot-knowledge-%s.ndjson.gz" % m["sha256"][:16])
        # the name passes the extension's own check (kb_updater::check_manifest)
        self.assertRegex(m["file"], r"^[a-z0-9][a-z0-9._-]*$")
        lines = gzip.decompress(data).decode("utf-8").splitlines()
        self.assertEqual(len(lines), m["lines"])
        header = json.loads(lines[0])["header"]
        self.assertEqual(header["format"], bk.FORMAT)
        self.assertEqual(header["chunks"], m["chunks"])
        self.assertIn("primer", header)
        chunks = [json.loads(l)["chunk"] for l in lines[1:]]
        self.assertEqual(len(chunks), m["chunks"])
        self.assertEqual(sum(len(c["terms"]) for c in chunks), m["terms"])
        self.assertEqual([c["id"] for c in chunks], list(range(m["chunks"])))


class Units(unittest.TestCase):
    def test_tokenize_matches_php_rules(self):
        self.assertEqual(bk.tokenize("How do I put a Value into Field_1?"),
                         ["put", "value", "field_1"])

    def test_long_section_is_split_not_lost(self):
        body = "\n\n".join(f"para {i} " + "x" * 900 for i in range(20))
        chunks = bk.markdown_chunks("# T\n\n## Big\n\n" + body, "Doc", "u", "guide")
        joined = "".join(c["text"] for c in chunks)
        for i in range(20):
            self.assertIn(f"para {i} ", joined)
        self.assertTrue(all(len(c["text"]) <= bk.MAX_CHUNK_CHARS for c in chunks))
        self.assertGreater(len(chunks), 1)

    def test_long_dictionary_entry_points_to_page(self):
        text = bk.cut("line\n" * 3000, "https://example/x")
        self.assertLessEqual(len(text), bk.MAX_CHUNK_CHARS + 60)
        self.assertTrue(text.endswith("[... continues at https://example/x]"))


if __name__ == "__main__":
    unittest.main()
