#!/usr/bin/env bash
# Builds build/smtpfast.zip with only the files the plugin needs at runtime.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
version="$(sed -n 's/^ \* Version: *//p' "$root/smtpfast.php" | head -1)"
stable="$(sed -n 's/^Stable tag: *//p' "$root/readme.txt" | head -1)"

constant="$(sed -n "s/^define( 'SMTPFAST_VERSION', '\(.*\)' );/\1/p" "$root/smtpfast.php")"

if [ "$version" != "$stable" ] || [ "$version" != "$constant" ]; then
	echo "Versions differ: header $version, SMTPFAST_VERSION $constant, Stable tag $stable." >&2
	exit 1
fi

rm -rf "$root/build"
mkdir -p "$root/build/smtpfast"
rsync -a --exclude-from="$root/.distignore" "$root/" "$root/build/smtpfast/"
(cd "$root/build" && zip -qr smtpfast.zip smtpfast)
echo "$root/build/smtpfast.zip ($version)"
