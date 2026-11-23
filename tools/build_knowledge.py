#!/usr/bin/env python3
"""Build Beyond Bot's knowledge bundle from the OXT-Beyond and xTalk Suite trees.

The bundle is what the forum extension searches when Claude calls its
search_docs and get_dictionary_entry tools. It is built here, on a CI
runner, because the forum's host has no Python and no vector database:
the extension only has to load rows into two tables and add up weights.

Output (in --out):
  beyondbot-knowledge.ndjson.gz the bundle as JSON Lines: a header with the
                                primer, then one line per chunk with its terms
  manifest.json                 format, sha256, size, counts, sources

The index is a precomputed BM25: for every chunk we keep the TOP_TERMS
terms with the highest BM25 weight, scaled to integers. At question time
the extension sums the weights of the query's terms per chunk. That is
the whole search, and it is portable to every database phpBB supports.

Python standard library only.
"""

import argparse
import datetime as _dt
import gzip
import hashlib
import json
import math
import os
import re
import sys
from collections import Counter

FORMAT = 1
TOP_TERMS = 64          # terms kept per chunk
MAX_CHUNK_CHARS = 6000  # a chunk longer than this is cut, with a pointer to the page
K1, B = 1.2, 0.75       # BM25 constants
TITLE_BOOST = 3         # title words count this many times

OXT_REPO = "SethMorrowSoftware/OpenXTalk-Beyond"
SUITE_REPO = "SethMorrowSoftware/xtalk-suite"

STOPWORDS = frozenset("""
a an and are as at be but by can do does for from has have how i if in into is
it its me my no not of on or our so than that the their them then there these
they this to was we were what when where which who why will with you your
""".split())

TOKEN_RE = re.compile(r"[a-z0-9_]+")


def tokenize(text):
    """Lower-case word tokens, as the PHP side tokenizes queries.

    Kept deliberately simple and mirrored exactly in
    knowledge_base::tokenize(): a token is [a-z0-9_]+, at least 2 long,
    not a stopword. No stemming: xTalk names are exact words.
    """
    return [t for t in TOKEN_RE.findall(text.lower()) if len(t) >= 2 and t not in STOPWORDS]


# ---------------------------------------------------------------- lcdoc

LCDOC_FIELD_RE = re.compile(r"^([A-Z][A-Za-z ]*):\s*(.*)$")


def parse_lcdoc(text):
    """Return {field: value} from an .lcdoc file.

    The format is "Field:" lines; a field's value runs until the next
    line that starts a known-shaped field at column 0. Values keep their
    line breaks. Unknown text before the first field is ignored.
    """
    fields = {}
    current = None
    buf = []
    for line in text.splitlines():
        m = LCDOC_FIELD_RE.match(line)
        if m and (not line.startswith(" ")) and len(m.group(1)) <= 24:
            if current is not None:
                fields[current] = "\n".join(buf).strip()
            current = m.group(1).strip()
            buf = [m.group(2)] if m.group(2) else []
        elif current is not None:
            buf.append(line)
    if current is not None:
        fields[current] = "\n".join(buf).strip()
    return fields


REF_RE = re.compile(r"<([^<>|\n]+)\|([^<>\n]+)>|<([^<>\n]+)>")


def plain_refs(text):
    """<put> -> put, <Mac OS|Mac OS systems> -> Mac OS systems."""
    def sub(m):
        if m.group(2):
            return m.group(2)
        return m.group(3)
    return REF_RE.sub(sub, text)


def cut(text, url):
    if len(text) <= MAX_CHUNK_CHARS:
        return text
    return text[:MAX_CHUNK_CHARS].rsplit("\n", 1)[0] + f"\n[... continues at {url}]"


def dictionary_chunk(path, rel, ref):
    with open(path, encoding="utf-8", errors="replace") as f:
        fields = parse_lcdoc(f.read())
    name = fields.get("Name", "").strip()
    kind = fields.get("Type", "").strip() or "entry"
    if not name:
        return None
    url = f"https://github.com/{OXT_REPO}/blob/{ref}/{rel}"
    parts = [f"{name} ({kind})"]
    for key in ("Syntax", "Summary", "Synonyms", "Introduced", "OS", "Platforms",
                "Example", "Parameters", "The result", "It", "Value", "Description",
                "References"):
        if fields.get(key):
            parts.append(f"{key}:\n{plain_refs(fields[key])}")
    return {
        "kind": "glossary" if kind == "glossary" else "dictionary",
        "title": f"{name} ({kind})",
        "key": name.lower(),
        "url": url,
        "text": cut("\n\n".join(parts), url),
    }


# ---------------------------------------------------------------- markdown

HEADING_RE = re.compile(r"^(#{1,3})\s+(.*?)\s*#*\s*$")


def markdown_chunks(text, doc_title, url, kind):
    """Split Markdown at #, ## and ### headings into chunks.

    Each chunk's title is "doc title > heading". A section longer than
    MAX_CHUNK_CHARS is split on blank lines so nothing is silently lost.
    Fenced code blocks are kept whole: a heading-shaped line inside a
    fence is not a heading.
    """
    sections = []
    heading = None
    buf = []
    in_fence = False
    for line in text.splitlines():
        if line.lstrip().startswith("```"):
            in_fence = not in_fence
        m = None if in_fence else HEADING_RE.match(line)
        if m:
            if buf and "".join(buf).strip():
                sections.append((heading, "\n".join(buf).strip()))
            heading = m.group(2)
            buf = []
        else:
            buf.append(line)
    if buf and "".join(buf).strip():
        sections.append((heading, "\n".join(buf).strip()))

    chunks = []
    for heading, body in sections:
        title = f"{doc_title} > {heading}" if heading and heading != doc_title else doc_title
        pieces = [body]
        if len(body) > MAX_CHUNK_CHARS:
            pieces, cur = [], ""
            for para in body.split("\n\n"):
                if cur and len(cur) + len(para) + 2 > MAX_CHUNK_CHARS:
                    pieces.append(cur)
                    cur = para
                else:
                    cur = f"{cur}\n\n{para}" if cur else para
            if cur:
                pieces.append(cur)
            pieces = [p[:MAX_CHUNK_CHARS] for p in pieces]
        for i, piece in enumerate(pieces):
            chunks.append({
                "kind": kind,
                "title": title if len(pieces) == 1 else f"{title} (part {i + 1})",
                "key": "",
                "url": url,
                "text": piece,
            })
    return chunks


def md_title(text, fallback):
    for line in text.splitlines():
        m = HEADING_RE.match(line)
        if m and len(m.group(1)) == 1:
            return m.group(2)
    return fallback


# ---------------------------------------------------------------- walking

def walk(root, sub, exts):
    base = os.path.join(root, sub)
    if not os.path.isdir(base):
        return
    for dirpath, dirnames, filenames in os.walk(base):
        dirnames[:] = sorted(d for d in dirnames if not d.startswith(".") and d != "images")
        for name in sorted(filenames):
            if os.path.splitext(name)[1].lower() in exts:
                full = os.path.join(dirpath, name)
                yield full, os.path.relpath(full, root).replace(os.sep, "/")


def oxt_chunks(root, ref):
    chunks = []
    for sub in ("docs/dictionary", "docs/glossary", "docs/lcb"):
        for full, rel in walk(root, sub, {".lcdoc"}):
            c = dictionary_chunk(full, rel, ref)
            if c:
                chunks.append(c)
    md_sources = [
        ("docs/guides", "guide"),
        ("docs/lcb", "guide"),
        ("docs/cheat-sheet", "guide"),
        ("docs/notes", "release-note"),
    ]
    for sub, kind in md_sources:
        for full, rel in walk(root, sub, {".md"}):
            with open(full, encoding="utf-8", errors="replace") as f:
                text = f.read()
            url = f"https://github.com/{OXT_REPO}/blob/{ref}/{rel}"
            title = md_title(text, os.path.splitext(os.path.basename(rel))[0])
            chunks.extend(markdown_chunks(text, f"OXT-Beyond: {title}", url, kind))
    for rel, kind in (("README.md", "guide"), ("SUPPORT.md", "guide"),
                      ("BUILDING.md", "guide"), ("CHANGELOG.md", "changelog")):
        full = os.path.join(root, rel)
        if os.path.isfile(full):
            with open(full, encoding="utf-8", errors="replace") as f:
                text = f.read()
            if kind == "changelog":
                text = recent_changelog(text, releases=4)
            url = f"https://github.com/{OXT_REPO}/blob/{ref}/{rel}"
            chunks.extend(markdown_chunks(text, f"OXT-Beyond {os.path.splitext(rel)[0]}", url, kind))
    return chunks


def recent_changelog(text, releases):
    """Keep the changelog's head and its newest `releases` release sections.

    The full changelog is long and old entries would crowd out current
    ones in search; the bot is told to link the full file for history.
    """
    out, seen = [], 0
    for line in text.splitlines():
        if line.startswith("## "):
            seen += 1
            if seen > releases:
                break
        out.append(line)
    return "\n".join(out)


def suite_chunks(root, ref):
    chunks = []
    for full, rel in walk(root, "", {".md"}):
        parts = rel.split("/")
        # Member and suite docs, and every member's README. Skip tests,
        # tools, vendored third-party trees and agent notes: they are
        # not user documentation.
        if parts[0] in ("tests", "tools", ".github"):
            continue
        if any(p in ("third_party", "third-party", "vendor", "external", "node_modules", "build")
               for p in parts):
            continue
        name = parts[-1]
        if name in ("CLAUDE.md", "AGENTS.md"):
            continue
        if not (name == "README.md" or "docs" in parts[:-1]):
            continue
        with open(full, encoding="utf-8", errors="replace") as f:
            text = f.read()
        url = f"https://github.com/{SUITE_REPO}/blob/{ref}/{rel}"
        member = parts[0] if len(parts) > 1 else "suite"
        title = md_title(text, os.path.splitext(name)[0])
        prefix = "xTalk Suite" if member in ("suite", "docs") else f"xTalk Suite {member}"
        chunks.extend(markdown_chunks(text, f"{prefix}: {title}", url, "suite"))
    return chunks


def current_release(oxt_root):
    """The newest release heading in OXT-Beyond's CHANGELOG, e.g. '0.2.8-dp.2.1 (2026-11-21, pre-release)'."""
    path = os.path.join(oxt_root, "CHANGELOG.md")
    if not os.path.isfile(path):
        return "unknown"
    with open(path, encoding="utf-8", errors="replace") as f:
        for line in f:
            if line.startswith("## "):
                return line[3:].strip()
    return "unknown"


# ---------------------------------------------------------------- index

def build_index(chunks):
    """Precomputed BM25: [term, chunk_index, weight*1000] for each chunk's top terms."""
    docs = []
    df = Counter()
    for c in chunks:
        toks = tokenize(c["text"]) + tokenize(c["title"]) * TITLE_BOOST
        tf = Counter(toks)
        docs.append((tf, len(toks)))
        df.update(tf.keys())
    n = len(chunks)
    avgdl = (sum(dl for _, dl in docs) / n) if n else 1.0
    rows = []
    for i, (tf, dl) in enumerate(docs):
        scored = []
        for term, f in tf.items():
            idf = math.log(1 + (n - df[term] + 0.5) / (df[term] + 0.5))
            w = idf * (f * (K1 + 1)) / (f + K1 * (1 - B + B * dl / avgdl))
            scored.append((w, term))
        scored.sort(key=lambda x: (-x[0], x[1]))
        for w, term in scored[:TOP_TERMS]:
            weight = int(round(w * 1000))
            if weight > 0:
                rows.append([term[:64], i, weight])
    return rows


def build(oxt_root, suite_root, oxt_ref, suite_ref, primer_path, built_at=None):
    chunks = []
    if oxt_root:
        chunks.extend(oxt_chunks(oxt_root, oxt_ref))
    if suite_root:
        chunks.extend(suite_chunks(suite_root, suite_ref))
    # Deterministic order and ids: the same trees give the same bundle.
    chunks.sort(key=lambda c: (c["kind"], c["title"], c["url"]))
    for i, c in enumerate(chunks):
        c["id"] = i
    with open(primer_path, encoding="utf-8") as f:
        primer = f.read()
    release = current_release(oxt_root) if oxt_root else "unknown"
    primer = primer.replace("{{OXT_RELEASE}}", release)
    # Fingerprint of how the bundle was made (this builder and the
    # primer), so the publish job can tell "same sources, same builder"
    # from a change worth sending to every forum.
    with open(os.path.abspath(__file__), "rb") as f:
        builder = hashlib.sha256(f.read() + primer.encode("utf-8")).hexdigest()[:16]
    return {
        "format": FORMAT,
        "built_at": built_at or _dt.datetime.now(_dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "sources": [
            {"repo": OXT_REPO, "ref": oxt_ref},
            {"repo": SUITE_REPO, "ref": suite_ref},
        ],
        "oxt_release": release,
        "builder": builder,
        "primer": primer,
        "chunks": chunks,
        "terms": build_index(chunks),
    }


def ndjson_lines(bundle):
    """The bundle as JSON Lines: one header line, then one line per chunk.

    Each chunk line carries its own terms, so the forum side can import
    the bundle a line at a time, resume after a time limit by counting
    lines, and never hold the whole thing in memory (a shared host may
    give PHP 128 MB).
    """
    terms_by_chunk = {}
    for term, chunk, weight in bundle["terms"]:
        terms_by_chunk.setdefault(chunk, []).append([term, weight])
    header = {k: bundle[k] for k in ("format", "built_at", "sources", "oxt_release", "primer")}
    header["chunks"] = len(bundle["chunks"])
    header["terms"] = len(bundle["terms"])
    yield json.dumps({"header": header}, ensure_ascii=False, separators=(",", ":"), sort_keys=True)
    for c in bundle["chunks"]:
        line = dict(c)
        line["terms"] = terms_by_chunk.get(c["id"], [])
        yield json.dumps({"chunk": line}, ensure_ascii=False, separators=(",", ":"), sort_keys=True)


def write(bundle, out_dir):
    os.makedirs(out_dir, exist_ok=True)
    raw = ("\n".join(ndjson_lines(bundle)) + "\n").encode("utf-8")
    # mtime=0 keeps the gzip byte-identical for identical input.
    gz = gzip.compress(raw, compresslevel=9, mtime=0)
    sha = hashlib.sha256(gz).hexdigest()
    # Named by content: publishing a new bundle never replaces a file a
    # forum may be downloading under an older manifest.
    name = f"beyondbot-knowledge-{sha[:16]}.ndjson.gz"
    with open(os.path.join(out_dir, name), "wb") as f:
        f.write(gz)
    manifest = {
        "format": bundle["format"],
        "built_at": bundle["built_at"],
        "sources": bundle["sources"],
        "builder": bundle["builder"],
        "oxt_release": bundle["oxt_release"],
        "chunks": len(bundle["chunks"]),
        "terms": len(bundle["terms"]),
        "lines": len(bundle["chunks"]) + 1,
        "file": name,
        "size": len(gz),
        "sha256": sha,
    }
    with open(os.path.join(out_dir, "manifest.json"), "w", encoding="utf-8") as f:
        json.dump(manifest, f, indent=2, sort_keys=True)
        f.write("\n")
    return manifest


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    p = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    p.add_argument("--oxt", help="OpenXTalk-Beyond checkout")
    p.add_argument("--suite", help="xtalk-suite checkout")
    p.add_argument("--oxt-ref", default="main")
    p.add_argument("--suite-ref", default="main")
    p.add_argument("--primer", default=os.path.join(here, "primer.md"))
    p.add_argument("--out", required=True)
    a = p.parse_args(argv)
    if not a.oxt and not a.suite:
        p.error("give --oxt, --suite or both")
    bundle = build(a.oxt, a.suite, a.oxt_ref, a.suite_ref, a.primer)
    m = write(bundle, a.out)
    kinds = Counter(c["kind"] for c in bundle["chunks"])
    print(f"{m['chunks']} chunks ({', '.join(f'{k} {v}' for k, v in sorted(kinds.items()))}), "
          f"{m['terms']} term rows, {m['size']} bytes, sha256 {m['sha256']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
