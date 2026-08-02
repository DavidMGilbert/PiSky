#!/usr/bin/env bash
#
# Bring an installed station up to date with an updated checkout.
#
# Some of PiSky lives outside the checkout: the privileged helper is copied to
# /usr/local/sbin, the web server configuration is rendered into /etc/lighttpd,
# and the timers are units under /etc/systemd/system. None of those change when
# git pull updates the working tree, so a station could be running current PHP
# against a months-old helper and web configuration — which is exactly how
# stations ended up answering 404 for their own clean API routes, and how
# "Unknown command" appears for controls the interface has just started using.
#
# Re-running install-pisky.sh would also fix it, but that is a long, network-
# hungry operation that reinstalls packages and reconfigures services. This
# copies the pieces that go stale and nothing else, so it is safe to run at any
# time and finishes in seconds.
#
# Copyright (c) 2026 David Gilbert
# SPDX-License-Identifier: MIT

set -euo pipefail

PISKY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PISKY_ROOT

log() { printf '[PiSky] %s\n' "${1}"; }
die() { printf '[PiSky] %s\n' "${1}" >&2; exit 1; }

[[ ${EUID} -eq 0 ]] || die "Run this with sudo: sudo ./update-pisky.sh"
[[ -x ${PISKY_ROOT}/scripts/piskyctl ]] \
	|| die "scripts/piskyctl is missing from ${PISKY_ROOT}."

log "Updating the privileged helper."
install -m 0755 -o root -g root \
	"${PISKY_ROOT}/scripts/piskyctl" /usr/local/sbin/piskyctl

# The helper reads PISKY_ROOT from its own configuration, so everything below
# runs through it rather than duplicating what it already knows how to do.
log "Repairing the storage layout."
/usr/local/sbin/piskyctl ensure-storage

log "Re-applying the web server configuration."
/usr/local/sbin/piskyctl sync-web

# Timer units name an absolute path to the checkout, so they are re-rendered
# rather than copied.
install_unit() {
	local name="${1}"
	local source="${PISKY_ROOT}/config_repo/${name}.repo"
	[[ -r ${source} ]] || return 0
	local rendered
	rendered="$(mktemp)"
	sed -e "s|XX_PISKY_ROOT_XX|${PISKY_ROOT}|g" \
		-e "s|XX_PISKY_SAMPLE_INTERVAL_XX|300s|g" \
		"${source}" > "${rendered}"
	install -m 0644 -o root -g root -- "${rendered}" "/etc/systemd/system/${name}"
	rm -f -- "${rendered}"
}

log "Refreshing scheduled units."
for unit in pisky-sample.service pisky-sample.timer \
	pisky-beacon.service pisky-beacon.timer; do
	install_unit "${unit}"
done
systemctl daemon-reload

# The sampler's interval and whether the beacon runs at all are decided by
# configuration, so the helper is asked to reconcile them rather than this
# script guessing.
/usr/local/sbin/piskyctl sync-directory >/dev/null 2>&1 || true

installed_version="$(/usr/local/sbin/piskyctl status-json 2>/dev/null \
	| jq -r '.control_version // "unknown"' 2>/dev/null || echo unknown)"
log "Done. Privileged helper is now version ${installed_version}."
log "If the interface still reports an older one, reload the admin page."
