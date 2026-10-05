#!/bin/sh
# Runs `php -l` on every PHP file under the given paths; missing paths are skipped.
status=0
for path in "$@"; do
    [ -e "$path" ] || continue
    for file in $(find "$path" -name '*.php'); do
        out=$(php -l "$file" 2>&1) || { echo "$out"; status=1; }
    done
done
exit $status
