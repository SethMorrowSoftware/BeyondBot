#!/usr/bin/env bash
# Build the zip a forum admin uploads: ext/sethmorrow/beyondbot with its
# runtime libraries (vendor/) installed, dev files and VCS folders left
# out. Unzipped into a board's root it lands at ext/sethmorrow/beyondbot.
#
#   tools/package.sh [OUT_DIR]      (default: build/)
set -euo pipefail

repo=$(cd "$(dirname "$0")/.." && pwd)
out=${1:-$repo/build}
version=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["version"])' "$repo/ext/sethmorrow/beyondbot/composer.json")
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/ext/sethmorrow" "$out"
out=$(cd "$out" && pwd)
# Copy without any vendor/ already in the working tree: the package gets
# a clean install from the lock file.
tar -C "$repo/ext/sethmorrow" --exclude=beyondbot/vendor -cf - beyondbot | tar -C "$stage/ext/sethmorrow" -xf -
(
	cd "$stage/ext/sethmorrow/beyondbot"
	composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress -q
	# What the libraries ship for their own development is not needed to run them.
	find vendor -depth \( -name .git -o -name .github -o -name tests -o -name examples \
		-o -name scripts -o -name docs -o -name .changelog \) -type d -exec rm -rf {} +
	find vendor -name '*.md' ! -iname 'license*' -type f -delete
)
zip_name="beyondbot-$version.zip"
rm -f "$out/$zip_name"
(cd "$stage" && zip -qr -X "$out/$zip_name" ext)
(cd "$out" && sha256sum "$zip_name" > "$zip_name.sha256")
echo "$out/$zip_name"
