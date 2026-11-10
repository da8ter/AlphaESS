#!/usr/bin/env bash
# Prüfstand Alpha ESS: alle Prüfungen ohne Symcon, ohne Netz, ohne Anlage.
#
#   tests/run.sh
#
# Bausteine: der Symcon-Kern im Speicher der Nachbarbibliothek (../LGThinQ/tests/sdk),
# tests/fake (ModBus Gateway, Open API), tests/fixtures (Registerkarte einer gedachten Anlage).
# Werkzeuge für Messungen an echten Systemen liegen in tests/tools.
set -euo pipefail
cd "$(dirname "$0")/.."
PHP="${PHP:-$(command -v php || echo /opt/homebrew/bin/php)}"

while IFS= read -r -d '' f; do
    "$PHP" -l "$f" >/dev/null
done < <(find . -name '*.php' -not -path './.git/*' -print0)
while IFS= read -r -d '' f; do
    "$PHP" -r 'json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR);' "$f"
done < <(find . -name '*.json' -not -path './.git/*' -print0)
echo "Syntax und JSON in Ordnung."

status=0
for t in logic system devices meta; do
    echo
    echo "### ${t}_test.php"
    if ! out=$("$PHP" "tests/${t}_test.php" 2>&1); then
        echo "$out" | grep -E '^FAIL' || echo "$out" | tail -5
        status=1
        continue
    fi
    echo "$out" | tail -1
done
exit $status
