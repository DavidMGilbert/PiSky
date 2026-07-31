#!/usr/bin/env bash
#
# Local mirror of .github/workflows/validate.yml. Run from the repository root:
#
#   ./validate-local.sh
#
# Works on Linux/macOS and under Git Bash on Windows. Steps whose tool is not
# installed report SKIP rather than failing, so a partial toolchain still gives
# useful coverage. Keep this in step with validate.yml when CI changes.

set -uo pipefail

# Git Bash does not always inherit the winget shim directory.
winget_links="/c/Users/${USERNAME:-$USER}/AppData/Local/Microsoft/WinGet/Links"
if [ -d "$winget_links" ]; then
	PATH="${PATH}:${winget_links}"
	export PATH
fi

# CI calls python3. On Windows several interpreters can be on PATH and only one
# of them has configobj, so prefer whichever can actually import it.
PY=""
for candidate in python3 python; do
	command -v "$candidate" >/dev/null 2>&1 || continue
	[ -n "$PY" ] || PY="$candidate"
	if "$candidate" -c 'import configobj' >/dev/null 2>&1; then
		PY="$candidate"
		break
	fi
done
if [ -z "$PY" ]; then
	printf 'No python interpreter found on PATH.\n' >&2
	exit 1
fi

err=0
step() { printf '\n\033[1m== %s\033[0m\n' "$1"; }
check() {
	if "$@"; then
		printf '   ok    %s\n' "$*"
	else
		printf '   FAIL  %s\n' "$*"
		err=$((err + 1))
	fi
}

step "Shell syntax"
check bash -n install-pisky.sh
check bash -n scripts/piskyctl

step "ShellCheck"
if command -v shellcheck >/dev/null 2>&1; then
	check shellcheck install-pisky.sh scripts/piskyctl
else
	printf '   SKIP  shellcheck not installed\n'
fi

step "PHP lint"
php_bad=0
while IFS= read -r -d '' f; do
	php -l "$f" >/dev/null 2>&1 || { printf '   FAIL  php -l %s\n' "$f"; php_bad=1; }
done < <(find html -type f -name '*.php' -print0)
if [ "$php_bad" -eq 0 ]; then printf '   ok    php -l (all html/**.php)\n'; else err=$((err + 1)); fi

step "PHP tests"
for t in tests/pisky-auth.test.php \
	tests/pisky-flight-fields.test.php \
	tests/pisky-installer.test.php \
	tests/pisky-setup-render.test.php \
	tests/pisky-site.test.php \
	tests/pisky-weather-units.test.php \
	tests/pisky-weather-supplement.test.php \
	tests/pisky-weather-history.test.php \
	tests/pisky-metric-visibility.test.php \
	tests/pisky-history-store.test.php; do
	check php "$t"
done

step "JavaScript"
for f in html/js/pisky-context.js html/js/pisky-theme.js \
	html/js/pisky-weather.js html/js/pisky-flights.js \
	html/js/pisky-weather-icons.js html/js/pisky-charts.js \
	html/js/pisky-history.js; do
	check node --check "$f"
done
check node tests/pisky-context.test.cjs
check node tests/pisky-navigation.test.cjs
check node tests/pisky-weather-icons.test.cjs

step "Python"
check "$PY" -m py_compile \
	integrations/weewx/pisky_json.py \
	integrations/weewx/configure_pisky.py \
	integrations/weewx/configure_station.py
check "$PY" tests/pisky-weewx-presets.test.py

step "JSON templates"
if command -v jq >/dev/null 2>&1; then
	for f in config_repo/pisky-weather.json.repo \
		config_repo/pisky-flights.json.repo \
		config_repo/pisky-headless-settings.json.repo \
		config_repo/aircraft.example.json \
		config_repo/weewx-current.example.json; do
		check jq empty "$f"
	done
else
	printf '   SKIP  jq not installed\n'
fi

step "Whitespace"
check git diff --check

printf '\n'
if [ "$err" -eq 0 ]; then
	printf '\033[32mAll validation steps passed.\033[0m\n'
else
	printf '\033[31m%d validation step(s) failed.\033[0m\n' "$err"
fi
exit "$err"
