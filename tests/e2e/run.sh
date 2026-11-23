#!/usr/bin/env bash
# Beyond Bot end-to-end test: a fresh phpBB board on SQLite, the
# extension enabled through phpBB's own CLI, a docs bundle built from
# real OXT-Beyond and xTalk Suite checkouts, and a stand-in for the
# Claude API, so nothing is spent. Every run starts from a new board:
# the test leaves posts, limits and logs behind on purpose.
#
#   tests/e2e/run.sh PHPBB_DIR OXT_DIR SUITE_DIR [WORK_DIR]
#
# PHPBB_DIR is an unpacked, never-installed phpBB 3.3 (for example
# `composer create-project phpbb/phpbb:3.3.19 PHPBB_DIR`). It is copied,
# never changed. BEYONDBOT_ZIP, if set, is a release zip (tools/package.sh)
# installed in place of the working tree, so CI tests what ships.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$here/../.." && pwd)
phpbb=${1:?PHPBB_DIR}
oxt=${2:?OXT_DIR}
suite=${3:?SUITE_DIR}
work=${4:-$(mktemp -d)}
port=${FAKE_PORT:-8765}

mkdir -p "$work"
work=$(cd "$work" && pwd)
rm -rf "$work/board" "$work/kb" "$work/board.sqlite3" "$work/fake.jsonl"

echo "== building the docs bundle"
python3 "$repo/tools/build_knowledge.py" --oxt "$oxt" --suite "$suite" --out "$work/kb" >/dev/null

echo "== installing a fresh board"
cp -a "$phpbb" "$work/board"
cat > "$work/install.yml" <<YML
installer:
    admin:
        name: admin
        password: adminadmin
        email: admin@example.org
    board:
        lang: en
        name: Test Board
        description: Beyond Bot end-to-end test
    database:
        dbms: sqlite3
        dbhost: $work/board.sqlite3
        dbport: ~
        dbuser: ~
        dbpasswd: ~
        dbname: ~
        table_prefix: phpbb_
    email:
        enabled: false
        smtp_delivery: ~
        smtp_host: ~
        smtp_port: ~
        smtp_auth: ~
        smtp_user: ~
        smtp_pass: ~
    server:
        cookie_secure: false
        server_protocol: http://
        force_server_vars: false
        server_name: localhost
        server_port: 80
        script_path: /
    extensions: []
YML
php "$work/board/install/phpbbcli.php" install "$work/install.yml" >"$work/install.log" 2>&1 \
	|| { cat "$work/install.log"; exit 1; }
# phpBB shows "board unavailable" while install/ exists, as on a real host.
rm -rf "$work/board/install"
if [ -n "${BEYONDBOT_ZIP:-}" ]; then
	(cd "$work/board" && unzip -q "$BEYONDBOT_ZIP")
else
	mkdir -p "$work/board/ext/sethmorrow"
	ln -s "$repo/ext/sethmorrow/beyondbot" "$work/board/ext/sethmorrow/beyondbot"
fi
php "$work/board/bin/phpbbcli.php" extension:enable sethmorrow/beyondbot

echo "== starting the stand-in Claude API on port $port"
FAKE_LOG="$work/fake.jsonl" php -S "127.0.0.1:$port" "$here/fake_anthropic.php" >"$work/fake.out" 2>&1 &
fake=$!
trap 'kill $fake 2>/dev/null || true' EXIT
for _ in $(seq 50); do
	curl -s -o /dev/null "http://127.0.0.1:$port/" && break
	sleep 0.1
done

echo "== running"
php "$here/e2e.php" "$work/board" "$work/kb" "http://127.0.0.1:$port" "$work/fake.jsonl"
