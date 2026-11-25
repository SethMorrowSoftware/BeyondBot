# Beyond Bot

A phpBB 3.3 extension, by Seth Morrow, that adds a forum member called
**Beyond Bot**. When someone writes `@Beyond Bot` in a post in one of the
forums you choose, it answers in the topic, using Claude and the
OpenXTalk-Beyond and xTalk Suite documentation.

- It answers only in the forums you pick (for openxtalk.org, the
  OXT-Beyond sections), only to members with enough posts, and only
  within the daily, per-topic and monthly spend limits you set.
- It looks things up before it answers: Claude gets two read-only tools
  over a docs index built from the OXT-Beyond dictionary, glossary,
  guides and changelog and every xTalk Suite member's docs, and links
  what it used.
- Every answer quotes the question, ends with a line saying it was
  written by AI, and is logged in the admin panel with its model, tokens,
  cost and the docs it looked up.
- The model is a setting: Sonnet 5.5 by default, Opus 5.5, Haiku 5.5 or
  Fable 5.2 from a list, or any other model id with its prices.
- It saves bug reports for the maintainers: when a member tells it about
  a bug in OXT-Beyond or an xTalk Suite extension, it files a short
  report on the ACP's **Bug reports** page as well as answering. Each
  report has a status, a note, and a **Create GitHub issue** button that
  opens GitHub's own new-issue form filled in, so the board never holds
  a GitHub token. It can also read every new topic in forums you tick
  (a Bug Reports section, say) with a cheap model, mention or not.

## How it works

1. A member's post that mentions `@Beyond Bot` (outside quotes and code)
   in an allowed forum is put on a queue. Posting is never slowed down.
2. phpBB's cron works the queue: it checks the limits, gathers the topic,
   and asks Claude, letting it look up dictionary entries and search the
   docs (at most four rounds of lookups per question).
3. The answer is cleaned (a short list of BBCodes, links only to allowed
   sites, no images, no mention of itself) and posted as Beyond Bot.

Forum posts are treated as material to answer, never as instructions:
they are fenced off in the prompt, the bot's only tool that writes
anything is the one that saves a bug report to its own table (shown to
admins only, every field escaped), and its output is filtered before it
is posted.

## Installing

Needs phpBB 3.3, PHP 8.1 or later with the curl and zlib extensions, and
a Claude API key from [console.anthropic.com](https://console.anthropic.com).

1. Download `beyondbot-<version>.zip` from the
   [releases](https://github.com/SethMorrowSoftware/BeyondBot/releases)
   and unzip it in the board's root folder, so that it lands in
   `ext/sethmorrow/beyondbot/`. (The zip carries its libraries; there is
   nothing to run with Composer.)
2. In the ACP, **Customise > Manage extensions**, enable **Beyond Bot**.
   This creates the member "Beyond Bot" (rank "AI helper", no password,
   nobody can log in as it). The bot starts switched **off**. If a
   member named "Beyond Bot" already exists and can log in, the install
   stops and says so: rename that member first.
3. Open **Extensions > Beyond Bot > Settings**:
   - paste the API key (it is stored in the database, never shown again
     and never sent anywhere but Anthropic's API),
   - pick the **Forums it answers in**,
   - check the limits and the **Monthly spend cap**,
   - wait for **Docs index** in the status panel to show it is loaded
     (it loads over the next few cron runs),
   - then tick **Beyond Bot is on**.

Recommended:

- **Run phpBB's cron from the system**, so answers do not wait for page
  views: in the ACP set **Server settings > Run periodic tasks from
  system cron** to Yes and add
  `* * * * * php /path/to/board/bin/phpbbcli.php cron:run` to the
  host's crontab.
- **Set a spend limit in the Claude Console as well**, on a workspace
  used only by this key. The extension's monthly cap is computed from
  the token counts each answer reports; the Console limit is the one
  nothing on the forum can get around.
- Say in the forum's privacy policy that posts in the bot's topics are
  sent to Anthropic to be answered.

## Settings worth knowing

| Setting | Default | |
|---|---|---|
| Forums it answers in | none | the bot does nothing until you pick some |
| Posts a member needs first | 5 | keeps new accounts from spending your money |
| Answers per member per day | 5 | past it, the member gets one short note a day |
| Answers per topic | 3 | |
| Answers per day, whole board | 100 | |
| Monthly spend cap | $50 | answers wait (they are not lost) until next month or a higher cap |
| Model | Sonnet 5.5 | Opus 5.5 costs about twice as much per token |
| Effort | Medium | how hard the model thinks; higher costs more |
| Sites answers may link to | openxtalk.org, github.com, sethmorrowsoftware.github.io | |
| Docs bundle | this repository's `knowledge` release | rebuilt weekly from OXT-Beyond and the xTalk Suite |
| Save bug reports | on | only where the bot answers, unless you tick forums below |
| Also read every new topic in | none | each new topic there costs about a tenth of a cent on Haiku 5.5 |
| New reports per day, whole board | 20 | one report per topic; later posts fill it in |
| GitHub repository for issues | SethMorrowSoftware/OpenXTalk-Beyond | where the Create GitHub issue button points |

If the API refuses the key (revoked, out of credit), the bot pauses, the
status panel and the board's error log say why, and waiting questions are
tried again once you fix the key and press **Clear the error**.

## The docs bundle

`tools/build_knowledge.py` reads an OXT-Beyond checkout and an
xtalk-suite checkout and writes a gzipped NDJSON bundle with a
precomputed search index, plus `manifest.json` (size and SHA-256).
`.github/workflows/knowledge.yml` rebuilds it every Monday and publishes
it on the `knowledge` release when the sources changed. Forums check
the manifest about once a week, download only a changed bundle, verify
it, and load it in steps across cron runs; the old index answers until
the new one is complete. `tools/primer.md` is Beyond Bot's standing
instructions; it travels in the bundle.

## Development

```sh
python3 -m unittest discover -s tools/tests      # the bundle builder
composer install && vendor/bin/phpunit            # the extension's pure parts
# a real phpBB board on SQLite, with a stand-in for the Claude API:
composer create-project phpbb/phpbb:3.3.* /tmp/phpbb
tests/e2e/run.sh /tmp/phpbb path/to/OpenXTalk-Beyond path/to/xtalk-suite
tools/package.sh                                  # the release zip, in build/
```

The end-to-end test installs a fresh board, enables the extension
through phpBB's own CLI, posts as real members and checks every reply,
limit, refusal, retry, cost and log line. It never calls Claude: it
talks to `tests/e2e/fake_anthropic.php`, which also records exactly what
would have been sent. CI runs it on the release zip.

## Licence

GPL-2.0-only, as phpBB extensions are. Copyright (c) 2026 Seth Morrow.
