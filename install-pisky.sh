#!/bin/bash
#
# Unified PiSky alpha installer for Raspberry Pi OS Bookworm.
# Copyright (c) 2026 David Gilbert
# SPDX-License-Identifier: MIT

set -Eeuo pipefail
umask 022

PISKY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PISKY_ROOT
PISKY_VERSION="$(tr -d '[:space:]' < "${PISKY_ROOT}/PISKY_VERSION")"
readonly PISKY_VERSION
readonly WEEWX_VENV="/opt/pisky/weewx-venv"
readonly WEEWX_DATA="/var/lib/pisky/weewx-data"
readonly WEEWX_CONFIG="${WEEWX_DATA}/weewx.conf"
readonly WEEWX_OUTPUT="/var/lib/pisky/weather/current.json"
readonly WEEWX_INTERCEPTOR_COMMIT="43a36003b939a35500b723dee6593af061fcd353"
readonly WEEWX_INTERCEPTOR_URL="https://github.com/matthewwall/weewx-interceptor/archive/${WEEWX_INTERCEPTOR_COMMIT}.zip"
readonly BEAST_SPLITTER_REPOSITORY="https://github.com/flightaware/beast-splitter.git"
readonly BEAST_SPLITTER_COMMIT="eed4c0a0738e457c68bdeaca2aa78c893d3a484a"

WITH_CAMERA=true
WITH_WEATHER=true
WITH_WEEWX=true
WITH_ADSB=true
CAMERA_SELECTION_EXPLICIT=false
WEATHER_SELECTION_EXPLICIT=false
ADSB_SELECTION_EXPLICIT=false
SKIP_ALLSKY=false
# Set when the inherited camera installer did not finish, so the summary can
# say so plainly rather than reporting a clean install.
CAMERA_INSTALL_FAILED=false
ALLOW_UNSUPPORTED=false
DRY_RUN=false

usage()
{
	cat <<'EOF'
Usage: ./install-pisky.sh [options]

Installs PiSky on Raspberry Pi OS Bookworm. Run as the normal Pi user, not
with sudo; the installer asks for sudo only for system changes.

Options:
  --full               Enable Camera, Weather/WeeWX and Aircraft modules (default)
  --minimal            Enable only the Camera module
  --with-camera        Enable the sky-camera module
  --without-camera     Keep the PiSky web foundation but disable camera capture
  --with-weather       Enable free Open-Meteo weather without requiring WeeWX
  --without-weather    Disable weather and skip the local WeeWX engine
  --with-weewx         Install the optional local WeeWX engine
  --without-weewx      Do not install WeeWX
  --with-adsb          Install the local ADS-B decoder and receiver support
  --without-adsb       Do not install an ADS-B decoder
  --skip-allsky        Only finish PiSky components after Allsky is installed
  --allow-unsupported  Bypass OS/model checks (development only)
  --dry-run            Show the selected installation without changing the Pi
  --help               Show this help

FlightAware PiAware and Flightradar24 fr24feed are never installed or enabled
automatically. They remain optional outbound sharing destinations.
EOF
}

log()
{
	printf '\n[PiSky] %s\n' "$*"
}

die()
{
	printf '\n[PiSky] ERROR: %s\n' "$*" >&2
	exit 1
}

run_root()
{
	if [[ ${DRY_RUN} == "true" ]]; then
		printf '[dry-run] sudo'
		printf ' %q' "$@"
		printf '\n'
		return 0
	fi
	sudo "$@"
}

run_as_weewx()
{
	if [[ ${DRY_RUN} == "true" ]]; then
		printf '[dry-run] sudo -u weewx'
		printf ' %q' "$@"
		printf '\n'
		return 0
	fi
	sudo -u weewx "$@"
}

sed_escape()
{
	printf '%s' "${1}" | sed -e 's/[&|\\]/\\&/g'
}

#
# apt-get, but willing to wait for the package lock.
#
# Raspberry Pi OS runs apt-daily and unattended-upgrades on a timer, so a
# freshly booted Pi is very often already holding /var/lib/dpkg/lock-frontend
# when someone starts installing. Failing on that is unhelpful: the lock is
# released within a minute or two, and the correct behaviour is simply to
# queue behind it. DPkg::Lock::Timeout makes apt do exactly that.
#
apt_get()
{
	run_root env DEBIAN_FRONTEND=noninteractive \
		apt-get -o DPkg::Lock::Timeout=600 "$@"
}

# Report who holds the lock before the first long wait, so a stalled install
# looks like waiting rather than hanging.
report_package_lock()
{
	[[ ${DRY_RUN} != "true" ]] || return 0
	command -v fuser >/dev/null 2>&1 || return 0
	local holder
	holder="$(sudo fuser /var/lib/dpkg/lock-frontend 2>/dev/null | tr -d ' ')"
	[[ -n ${holder} ]] || return 0
	local name
	name="$(ps -o comm= -p "${holder}" 2>/dev/null || true)"
	log "Waiting for another package operation to finish (${name:-pid ${holder}})."
	printf 'Raspberry Pi OS installs updates in the background after boot.\n'
	printf 'PiSky will continue automatically once that finishes.\n\n'
}

parse_options()
{
	while [[ $# -gt 0 ]]; do
		case "${1}" in
			--full)
				WITH_CAMERA=true
				WITH_WEATHER=true
				WITH_WEEWX=true
				WITH_ADSB=true
				CAMERA_SELECTION_EXPLICIT=true
				WEATHER_SELECTION_EXPLICIT=true
				ADSB_SELECTION_EXPLICIT=true
				;;
			--minimal)
				WITH_CAMERA=true
				WITH_WEATHER=false
				WITH_WEEWX=false
				WITH_ADSB=false
				CAMERA_SELECTION_EXPLICIT=true
				WEATHER_SELECTION_EXPLICIT=true
				ADSB_SELECTION_EXPLICIT=true
				;;
			--with-camera)
				WITH_CAMERA=true
				CAMERA_SELECTION_EXPLICIT=true
				;;
			--without-camera)
				WITH_CAMERA=false
				CAMERA_SELECTION_EXPLICIT=true
				;;
			--with-weather)
				WITH_WEATHER=true
				WEATHER_SELECTION_EXPLICIT=true
				;;
			--without-weather)
				WITH_WEATHER=false
				WITH_WEEWX=false
				WEATHER_SELECTION_EXPLICIT=true
				;;
			--with-weewx)
				WITH_WEATHER=true
				WITH_WEEWX=true
				WEATHER_SELECTION_EXPLICIT=true
				;;
			--without-weewx) WITH_WEEWX=false ;;
			--with-adsb)
				WITH_ADSB=true
				ADSB_SELECTION_EXPLICIT=true
				;;
			--without-adsb)
				WITH_ADSB=false
				ADSB_SELECTION_EXPLICIT=true
				;;
			--skip-allsky) SKIP_ALLSKY=true ;;
			--allow-unsupported) ALLOW_UNSUPPORTED=true ;;
			--dry-run) DRY_RUN=true ;;
			--help|-h)
				usage
				exit 0
				;;
			*)
				die "Unknown option '${1}'. Use --help."
				;;
		esac
		shift
	done
	[[ ${WITH_CAMERA} == "true" || ${WITH_WEATHER} == "true" || ${WITH_ADSB} == "true" ]] ||
		die "Select at least one PiSky observation module."
}

preserve_existing_module_selection()
{
	# Explicit 0 for the same reason as install_headless_web_foundation: a bare
	# return here carries the failed test's status and aborts a dry run.
	[[ ${DRY_RUN} != "true" ]] || return 0

	local value
	if [[ ${WEATHER_SELECTION_EXPLICIT} != "true" &&
		-r ${PISKY_ROOT}/config/pisky-weather.json ]]; then
		value="$(jq -r '.enabled // true' \
			"${PISKY_ROOT}/config/pisky-weather.json" 2>/dev/null || true)"
		[[ ${value} == "true" || ${value} == "false" ]] && WITH_WEATHER="${value}"
	fi
	if [[ ${ADSB_SELECTION_EXPLICIT} != "true" &&
		-r ${PISKY_ROOT}/config/pisky-flights.json ]]; then
		value="$(jq -r '.enabled // true' \
			"${PISKY_ROOT}/config/pisky-flights.json" 2>/dev/null || true)"
		[[ ${value} == "true" || ${value} == "false" ]] && WITH_ADSB="${value}"
	fi
	if [[ ${CAMERA_SELECTION_EXPLICIT} != "true" ]] &&
		sudo test -r /var/lib/pisky/content/site.json; then
		value="$(sudo jq -r '.modules.camera // true' \
			/var/lib/pisky/content/site.json 2>/dev/null || true)"
		[[ ${value} == "true" || ${value} == "false" ]] && WITH_CAMERA="${value}"
	fi

	[[ ${WITH_CAMERA} == "true" || ${WITH_WEATHER} == "true" || ${WITH_ADSB} == "true" ]] ||
		die "The saved configuration has no observation modules enabled. Select one with --with-camera, --with-weather or --with-adsb."
}

preflight()
{
	[[ ${EUID} -ne 0 ]] ||
		die "Run ./install-pisky.sh as your normal Pi user, not with sudo."
	command -v sudo >/dev/null 2>&1 || die "sudo is required."
	[[ -f ${PISKY_ROOT}/install.sh ]] || die "Run this script from a complete PiSky checkout."
	[[ ${PISKY_ROOT} != *"'"* ]] || die "The PiSky path cannot contain a single quote."

	local codename="unknown"
	local os_id="unknown"
	if [[ -r /etc/os-release ]]; then
		# shellcheck source=/dev/null
		source /etc/os-release
		codename="${VERSION_CODENAME:-unknown}"
		os_id="${ID:-unknown}"
	fi

	local model="unknown"
	if [[ -r /proc/device-tree/model ]]; then
		model="$(tr -d '\000' < /proc/device-tree/model)"
	fi
	local arch
	arch="$(uname -m)"

	if [[ ${ALLOW_UNSUPPORTED} != "true" ]]; then
		[[ ${codename} == "bookworm" ]] ||
			die "PiSky alpha supports Raspberry Pi OS Bookworm only (found ${codename})."
		[[ ${os_id} == "raspbian" || ${os_id} == "debian" ]] ||
			die "Unsupported operating system '${os_id}'."
		[[ ${model} == *"Raspberry Pi"* ]] ||
			die "A Raspberry Pi was not detected (found '${model}')."
		case "${arch}" in
			aarch64|armv7l) ;;
			*) die "Unsupported architecture '${arch}'." ;;
		esac
	fi

	log "PiSky ${PISKY_VERSION} preflight"
	printf '  OS: %s (%s)\n  Hardware: %s\n  Architecture: %s\n' \
		"${os_id}" "${codename}" "${model}" "${arch}"
	printf '  Camera: %s\n  Weather: %s\n  WeeWX engine: %s\n  Aircraft: %s\n' \
		"${WITH_CAMERA}" "${WITH_WEATHER}" "${WITH_WEEWX}" "${WITH_ADSB}"

	if [[ ${DRY_RUN} != "true" ]]; then
		sudo -v
		local available_kb
		available_kb="$(df -Pk "${PISKY_ROOT}" | awk 'NR == 2 {print $4}')"
		[[ ${available_kb:-0} -ge 4194304 ]] ||
			die "At least 4 GB of free storage is required."
	fi
}

allsky_is_installed()
{
	local status_file="${PISKY_ROOT}/config/logs/install_status.txt"
	local legacy_status_file="${PISKY_ROOT}/logs/install_status.txt"
	local status=""

	[[ -f ${PISKY_ROOT}/config/settings.json ]] || return 1
	[[ -f ${PISKY_ROOT}/config/options.json ]] || return 1
	[[ -f /etc/systemd/system/allsky.service || -f /lib/systemd/system/allsky.service ]] ||
		return 1

	if [[ ! -s ${status_file} && -s ${legacy_status_file} ]]; then
		status_file="${legacy_status_file}"
	fi
	if [[ -s ${status_file} ]]; then
		status="$(sed -n -E \
			"s/^STATUS_INSTALLATION=['\"]?([^'\"]*)['\"]?$/\1/p" \
			"${status_file}" | tail -n 1)"
		case "${status}" in
			"OK"|"Rebooting to finish installation"|"Did not reboot to finish installation"|"User elected not to reboot")
				;;
			*)
				return 1
				;;
		esac
	fi
	return 0
}

install_allsky()
{
	if [[ ${WITH_CAMERA} != "true" ]]; then
		if allsky_is_installed; then
			log "Preserving the installed camera foundation with capture disabled."
		else
			log "Camera capability is disabled; skipping camera detection and the Allsky installer."
		fi
		return
	fi

	if [[ ${SKIP_ALLSKY} == "true" ]]; then
		allsky_is_installed ||
			die "--skip-allsky was used, but an Allsky installation was not detected."
		log "Using the existing Allsky installation."
		return
	fi

	if allsky_is_installed; then
		log "Allsky is already installed; preserving its configuration."
		return
	fi

	if [[ ${DRY_RUN} == "true" ]]; then
		log "Would run the inherited interactive Allsky installer."
		return
	fi

	log "Starting the inherited Allsky camera installer."
	printf 'When Allsky asks whether to reboot now, choose No so PiSky can install\n'
	printf 'its weather and receiver components first. PiSky will recommend one reboot\n'
	printf 'after the complete unified installation.\n\n'
	"${PISKY_ROOT}/install.sh"

	allsky_is_installed || {
		# Weather, aircraft and the web interface do not depend on the camera,
		# so a camera that did not finish installing must not prevent the rest
		# of PiSky being installed. Aborting here left systems with no
		# privileged helper, no configuration and no content storage, which
		# then failed in ways that pointed nowhere near the camera.
		printf '\nAllsky has not reached a completed installation state.\n'
		if [[ ${WITH_WEATHER} == "true" || ${WITH_ADSB} == "true" ]]; then
			WITH_CAMERA=false
			CAMERA_INSTALL_FAILED=true
			printf 'Continuing without the camera so the remaining PiSky components\n'
			printf 'are installed. Resolve the error shown by the Allsky installer,\n'
			printf 'then re-run:\n'
			printf '  cd %q && ./install-pisky.sh\n\n' "${PISKY_ROOT}"
			return
		fi
		printf 'No other modules are enabled, so there is nothing further to install.\n'
		printf 'Resolve the error shown by its installer, then run:\n'
		printf '  cd %q && ./install-pisky.sh\n' "${PISKY_ROOT}"
		exit 20
	}
	log "Allsky files are installed; continuing with PiSky without restarting its installer."
}

install_headless_web_foundation()
{
	# These must return 0 explicitly. A bare "return" yields the status of the
	# last command, which for a failed [[ ]] test is 1, and under set -e that
	# aborts the whole installer at the point of an ordinary early exit.
	[[ ${WITH_CAMERA} != "true" ]] || return 0
	allsky_is_installed && return 0

	log "Installing the camera-free PiSky web foundation."
	local web_group="www-data"
	local user_group
	user_group="$(id -gn)"
	run_root install -d -m 0775 -o "${USER}" -g "${web_group}" \
		"${PISKY_ROOT}/config" "${PISKY_ROOT}/config/logs" \
		"${PISKY_ROOT}/tmp" "${PISKY_ROOT}/images"
	run_root install -d -m 0755 -o "${USER}" -g "${web_group}" \
		"${PISKY_ROOT}/html/support"

	if [[ ! -f ${PISKY_ROOT}/config/settings.json ]]; then
		run_root install -m 0660 -o "${USER}" -g "${web_group}" \
			"${PISKY_ROOT}/config_repo/pisky-headless-settings.json.repo" \
			"${PISKY_ROOT}/config/settings.json"
	fi
	if [[ ! -f ${PISKY_ROOT}/config/status.json ]]; then
		local status_temporary
		status_temporary="$(mktemp)"
		trap 'rm -f -- "${status_temporary:-}"' RETURN
		printf '{\n  "status": "Camera disabled",\n  "timestamp": "%s"\n}\n' \
			"$(date '+%Y-%m-%d %H:%M:%S')" > "${status_temporary}"
		run_root install -m 0660 -o "${USER}" -g "${web_group}" \
			"${status_temporary}" "${PISKY_ROOT}/config/status.json"
		rm -f -- "${status_temporary}"
		trap - RETURN
	fi
	run_root install -m 0660 -o "${USER}" -g "${web_group}" /dev/null \
		"${PISKY_ROOT}/config/messages.txt"

	local defines_temporary
	defines_temporary="$(mktemp)"
	trap 'rm -f -- "${defines_temporary:-}"' RETURN
	sed \
		-e "s|XX_HOME_XX|$(sed_escape "${HOME}")|g" \
		-e "s|XX_ALLSKY_HOME_XX|$(sed_escape "${PISKY_ROOT}")|g" \
		-e "s|XX_ALLSKY_CONFIG_XX|$(sed_escape "${PISKY_ROOT}/config")|g" \
		-e "s|XX_ALLSKY_SCRIPTS_XX|$(sed_escape "${PISKY_ROOT}/scripts")|g" \
		-e "s|XX_ALLSKY_UTILITIES_XX|$(sed_escape "${PISKY_ROOT}/scripts/utilities")|g" \
		-e "s|XX_ALLSKY_TMP_XX|$(sed_escape "${PISKY_ROOT}/tmp")|g" \
		-e "s|XX_ALLSKY_IMAGES_XX|$(sed_escape "${PISKY_ROOT}/images")|g" \
		-e "s|XX_ALLSKY_MESSAGES_XX|$(sed_escape "${PISKY_ROOT}/config/messages.txt")|g" \
		-e "s|XX_ALLSKY_CHECK_LOG_XX|$(sed_escape "${PISKY_ROOT}/config/logs/checkAllsky.html")|g" \
		-e "s|XX_ALLSKY_PRIOR_DIR_XX|$(sed_escape "${PISKY_ROOT}-OLD")|g" \
		-e "s|XX_ALLSKY_OLD_REMINDER_XX|$(sed_escape "${PISKY_ROOT}/config/logs/allsky-OLD_reminder.txt")|g" \
		-e "s|XX_ALLSKY_POST_INSTALL_ACTIONS_XX|$(sed_escape "${PISKY_ROOT}/config/logs/post-installation_actions.txt")|g" \
		-e "s|XX_ALLSKY_ABORTS_DIR_XX|$(sed_escape "${PISKY_ROOT}/tmp/aborts")|g" \
		-e "s|XX_ALLSKY_WEBUI_XX|$(sed_escape "${PISKY_ROOT}/html")|g" \
		-e "s|XX_ALLSKY_SUPPORT_DIR_XX|$(sed_escape "${PISKY_ROOT}/html/support")|g" \
		-e "s|XX_ALLSKY_WEBSITE_XX|$(sed_escape "${PISKY_ROOT}/html/allsky")|g" \
		-e "s|XX_ALLSKY_WEBSITE_LOCAL_CONFIG_NAME_XX|configuration.json|g" \
		-e "s|XX_ALLSKY_WEBSITE_REMOTE_CONFIG_NAME_XX|remote_configuration.json|g" \
		-e "s|XX_ALLSKY_WEBSITE_LOCAL_CONFIG_XX|$(sed_escape "${PISKY_ROOT}/html/allsky/configuration.json")|g" \
		-e "s|XX_ALLSKY_WEBSITE_REMOTE_CONFIG_XX|$(sed_escape "${PISKY_ROOT}/config/remote_configuration.json")|g" \
		-e "s|XX_ALLSKY_OVERLAY_XX|$(sed_escape "${PISKY_ROOT}/config/overlay")|g" \
		-e "s|XX_ALLSKY_ENV_XX|$(sed_escape "${PISKY_ROOT}/config/env.json")|g" \
		-e "s|XX_IMG_DIR_XX|/current|g" \
		-e "s|XX_ALLSKY_MYFILES_DIR_XX|$(sed_escape "${PISKY_ROOT}/config/myFiles")|g" \
		-e "s|XX_MY_OVERLAY_TEMPLATES_XX|$(sed_escape "${PISKY_ROOT}/config/overlay/myTemplates")|g" \
		-e "s|XX_ALLSKY_MODULES_XX|$(sed_escape "${PISKY_ROOT}/config/modules")|g" \
		-e "s|XX_ALLSKY_MODULE_LOCATION_XX|/opt/allsky|g" \
		-e "s|XX_ALLSKY_OWNER_XX|$(sed_escape "${USER}")|g" \
		-e "s|XX_ALLSKY_GROUP_XX|$(sed_escape "${user_group}")|g" \
		-e "s|XX_WEBSERVER_OWNER_XX|www-data|g" \
		-e "s|XX_WEBSERVER_GROUP_XX|www-data|g" \
		-e "s|XX_ALLSKY_REPO_XX|$(sed_escape "${PISKY_ROOT}/config_repo")|g" \
		-e "s|XX_GITHUB_ROOT_XX|https://github.com/AllskyTeam|g" \
		-e "s|XX_GITHUB_ALLSKY_REPO_XX|allsky|g" \
		-e "s|XX_GITHUB_ALLSKY_MODULES_REPO_XX|allsky-modules|g" \
		-e "s|XX_ALLSKY_VERSION_XX|$(sed_escape "$(head -n 1 "${PISKY_ROOT}/version")")|g" \
		-e "s|XX_ALLSKY_STATUS_XX|$(sed_escape "${PISKY_ROOT}/config/status.json")|g" \
		-e "s|XX_ALLSKY_STATUS_INSTALLING_XX|Installing...|g" \
		-e "s|XX_ALLSKY_STATUS_NOT_RUNNING_XX|Not Running|g" \
		-e "s|XX_ALLSKY_STATUS_RUNNING_XX|Running|g" \
		-e "s|XX_ALLSKY_STATUS_NEEDS_CONFIGURATION_XX|Camera settings need configuring|g" \
		-e "s|XX_ALLSKY_STATUS_NEEDS_REVIEW_XX|Camera settings need review|g" \
		-e "s|XX_RASPI_CONFIG_XX|$(sed_escape "${PISKY_ROOT}/config")|g" \
		-e "s|XX_NEED_TO_UPDATE_XX||g" \
		-e "s|XX_EXIT_PARTIAL_OK_XX|100|g" \
		"${PISKY_ROOT}/config_repo/allskyDefines.inc.repo" > "${defines_temporary}"
	run_root install -m 0644 -o "${USER}" -g "${web_group}" \
		"${defines_temporary}" "${PISKY_ROOT}/html/allskyDefines.inc"
	rm -f -- "${defines_temporary}"
	trap - RETURN

	local lighttpd_temporary
	lighttpd_temporary="$(mktemp)"
	trap 'rm -f -- "${lighttpd_temporary:-}"' RETURN
	sed \
		-e "s|XX_PISKY_WEBROOT_XX|$(sed_escape "${PISKY_ROOT}/html")|g" \
		-e "s|XX_PISKY_ROOT_XX|$(sed_escape "${PISKY_ROOT}")|g" \
		-e "s|XX_PISKY_TMP_XX|$(sed_escape "${PISKY_ROOT}/tmp")|g" \
		-e "s|XX_PISKY_IMAGES_XX|$(sed_escape "${PISKY_ROOT}/images")|g" \
		"${PISKY_ROOT}/config_repo/pisky-lighttpd.conf.repo" > "${lighttpd_temporary}"
	if [[ -f /etc/lighttpd/lighttpd.conf &&
		! -f /etc/lighttpd/lighttpd.conf.pre-pisky ]]; then
		run_root cp --preserve=mode,ownership \
			/etc/lighttpd/lighttpd.conf /etc/lighttpd/lighttpd.conf.pre-pisky
	fi
	run_root install -m 0644 -o root -g root \
		"${lighttpd_temporary}" /etc/lighttpd/lighttpd.conf
	rm -f -- "${lighttpd_temporary}"
	trap - RETURN
	run_root lighty-enable-mod fastcgi-php-fpm
	run_root systemctl enable --now lighttpd.service
}

install_base()
{
	log "Installing PiSky core dependencies and configuration."
	report_package_lock
	apt_get update
	# php-mysql is only exercised when a host points PiSky at a remote history
	# database, but installing it up front means enabling that later needs no
	# shell access.
	apt_get install -y \
		jq ca-certificates curl python3 lighttpd php-fpm php-gd php-mysql \
		avahi-daemon

	local web_group="www-data"
	getent group "${web_group}" >/dev/null 2>&1 ||
		die "The Allsky web-server group '${web_group}' does not exist."
	install_headless_web_foundation

	# /etc/pisky must exist before pisky.conf can be written, and piskyctl
	# needs that file before it will run, so this one directory is created
	# directly. Everything else comes from piskyctl's own layout definition
	# below, so the installer and the repair action cannot drift apart.
	run_root install -d -m 0755 /etc/pisky
	run_root install -m 0755 "${PISKY_ROOT}/scripts/piskyctl" /usr/local/sbin/piskyctl

	local rendered
	rendered="$(mktemp)"
	trap 'rm -f -- "${rendered:-}"' RETURN
	sed \
		-e "s|XX_PISKY_ROOT_XX|$(sed_escape "${PISKY_ROOT}")|g" \
		-e "s|XX_ALLSKY_CONFIG_XX|$(sed_escape "${PISKY_ROOT}/config")|g" \
		-e "s|XX_ALLSKY_OWNER_XX|$(sed_escape "${USER}")|g" \
		-e "s|XX_PISKY_VERSION_XX|$(sed_escape "${PISKY_VERSION}")|g" \
		"${PISKY_ROOT}/config_repo/pisky.conf.repo" > "${rendered}"
	run_root install -m 0640 -o root -g root "${rendered}" /etc/pisky/pisky.conf
	run_root install -m 0440 -o root -g root \
		"${PISKY_ROOT}/config_repo/pisky.sudoers.repo" /etc/sudoers.d/pisky
	run_root visudo -cf /etc/sudoers.d/pisky
	rm -f -- "${rendered}"
	trap - RETURN

	# The sampler records weather on a fixed schedule rather than relying on
	# someone loading a page, so the recorded series is even whether or not the
	# station is being watched.
	local sampler
	sampler="$(mktemp)"
	sed -e "s|XX_PISKY_ROOT_XX|$(sed_escape "${PISKY_ROOT}")|g" 		"${PISKY_ROOT}/config_repo/pisky-sample.service.repo" > "${sampler}"
	run_root install -m 0644 -o root -g root "${sampler}" 		/etc/systemd/system/pisky-sample.service
	sed -e "s|XX_PISKY_SAMPLE_INTERVAL_XX|300s|g" 		"${PISKY_ROOT}/config_repo/pisky-sample.timer.repo" > "${sampler}"
	run_root install -m 0644 -o root -g root "${sampler}" 		/etc/systemd/system/pisky-sample.timer
	rm -f -- "${sampler}"

	# pisky.conf now exists, so piskyctl can create the rest of the layout.
	# Doing it here rather than inline means the administration interface can
	# repair exactly the same set of directories later without SSH.
	run_root /usr/local/sbin/piskyctl ensure-storage
	run_root systemctl daemon-reload
	run_root systemctl enable --now pisky-sample.timer

	if [[ ! -f ${PISKY_ROOT}/config/pisky-weather.json ]]; then
		run_root install -m 0660 -o "${USER}" -g "${web_group}" \
			"${PISKY_ROOT}/config_repo/pisky-weather.json.repo" \
			"${PISKY_ROOT}/config/pisky-weather.json"
	else
		local merged_weather
		merged_weather="$(mktemp)"
		trap 'rm -f -- "${merged_weather:-}"' RETURN
		jq -s '.[0] * .[1]' \
			"${PISKY_ROOT}/config_repo/pisky-weather.json.repo" \
			"${PISKY_ROOT}/config/pisky-weather.json" > "${merged_weather}"
		run_root install -m 0660 -o "${USER}" -g "${web_group}" \
			"${merged_weather}" "${PISKY_ROOT}/config/pisky-weather.json"
		rm -f -- "${merged_weather}"
		trap - RETURN
	fi
	if [[ ! -f ${PISKY_ROOT}/config/pisky-flights.json ]]; then
		run_root install -m 0660 -o "${USER}" -g "${web_group}" \
			"${PISKY_ROOT}/config_repo/pisky-flights.json.repo" \
			"${PISKY_ROOT}/config/pisky-flights.json"
	else
		local merged_flights
		merged_flights="$(mktemp)"
		trap 'rm -f -- "${merged_flights:-}"' RETURN
		jq -s '.[0] * .[1]' \
			"${PISKY_ROOT}/config_repo/pisky-flights.json.repo" \
			"${PISKY_ROOT}/config/pisky-flights.json" > "${merged_flights}"
		run_root install -m 0660 -o "${USER}" -g "${web_group}" \
			"${merged_flights}" "${PISKY_ROOT}/config/pisky-flights.json"
		rm -f -- "${merged_flights}"
		trap - RETURN
	fi
	run_root chgrp "${web_group}" \
		"${PISKY_ROOT}/config/pisky-weather.json" \
		"${PISKY_ROOT}/config/pisky-flights.json"
	run_root chmod 0660 \
		"${PISKY_ROOT}/config/pisky-weather.json" \
		"${PISKY_ROOT}/config/pisky-flights.json"
}

station_coordinate()
{
	local field="${1}"
	local value="0"
	if [[ -r ${PISKY_ROOT}/config/settings.json ]]; then
		value="$(jq -r --arg field "${field}" '.[$field] // 0' \
			"${PISKY_ROOT}/config/settings.json" 2>/dev/null || echo 0)"
	fi
	[[ ${value} =~ ^-?[0-9]+([.][0-9]+)?$ ]] || value="0"
	printf '%s' "${value}"
}

ensure_weewx_user()
{
	if ! getent group weewx >/dev/null 2>&1; then
		run_root groupadd --system weewx
	fi
	if ! getent passwd weewx >/dev/null 2>&1; then
		run_root useradd --system --gid weewx --home-dir "${WEEWX_DATA}" \
			--shell /usr/sbin/nologin weewx
	fi
	for group in dialout plugdev; do
		if getent group "${group}" >/dev/null 2>&1; then
			run_root usermod -a -G "${group}" weewx
		fi
	done
}

install_weewx_interceptor()
{
	local interceptor="${WEEWX_DATA}/bin/user/interceptor.py"
	if [[ -f ${interceptor} ]]; then
		log "WeeWX Interceptor station presets are already installed."
		return
	fi
	if [[ ${DRY_RUN} == "true" ]]; then
		log "Would install pinned WeeWX Interceptor ${WEEWX_INTERCEPTOR_COMMIT:0:12}."
		return
	fi

	log "Installing pinned WeeWX Interceptor station presets."
	run_as_weewx env HOME="${WEEWX_DATA}" \
		"${WEEWX_VENV}/bin/weectl" extension install \
		"${WEEWX_INTERCEPTOR_URL}" \
		--config="${WEEWX_CONFIG}" --yes
	[[ -f ${interceptor} ]] ||
		die "WeeWX Interceptor did not install into the PiSky station directory."
}

install_weewx()
{
	[[ ${WITH_WEEWX} == "true" ]] || {
		log "Skipping optional WeeWX engine."
		return
	}

	log "Installing WeeWX 5.x in an isolated Python environment."
	apt_get install -y python3-venv python3-pip
	ensure_weewx_user
	run_root install -d -m 0755 /opt/pisky
	if [[ ! -x ${WEEWX_VENV}/bin/weewxd ]]; then
		run_root python3 -m venv "${WEEWX_VENV}"
		run_root "${WEEWX_VENV}/bin/python" -m pip install --upgrade pip
		run_root "${WEEWX_VENV}/bin/python" -m pip install \
			"weewx>=5.4,<6" pyserial pyusb
	fi

	run_root install -d -m 0755 -o weewx -g weewx "${WEEWX_DATA}"
	if [[ ! -f ${WEEWX_CONFIG} ]]; then
		local latitude longitude
		latitude="$(station_coordinate latitude)"
		longitude="$(station_coordinate longitude)"
		run_as_weewx env HOME="${WEEWX_DATA}" \
			"${WEEWX_VENV}/bin/weectl" station create "${WEEWX_DATA}" \
			--no-prompt \
			--driver=weewx.drivers.simulator \
			--location="PiSky weather station" \
			--altitude="0,meter" \
			--latitude="${latitude}" \
			--longitude="${longitude}" \
			--units=metricwx \
			--html-root=/var/www/html/weewx
	fi

	run_root install -d -m 0755 -o weewx -g weewx \
		"${WEEWX_DATA}/bin" "${WEEWX_DATA}/bin/user" \
		/var/lib/pisky/weather /var/www/html/weewx
	install_weewx_interceptor
	run_root install -m 0644 -o weewx -g weewx \
		"${PISKY_ROOT}/integrations/weewx/pisky_json.py" \
		"${WEEWX_DATA}/bin/user/pisky_json.py"
	run_root "${WEEWX_VENV}/bin/python" \
		"${PISKY_ROOT}/integrations/weewx/configure_pisky.py" \
		--config "${WEEWX_CONFIG}" --output "${WEEWX_OUTPUT}"
	run_root chown weewx:weewx "${WEEWX_CONFIG}"
	run_root chmod 0640 "${WEEWX_CONFIG}"

	local rendered
	rendered="$(mktemp)"
	trap 'rm -f -- "${rendered:-}"' RETURN
	sed \
		-e "s|XX_WEEWX_VENV_XX|$(sed_escape "${WEEWX_VENV}")|g" \
		-e "s|XX_WEEWX_CONFIG_XX|$(sed_escape "${WEEWX_CONFIG}")|g" \
		-e "s|XX_WEEWX_DATA_XX|$(sed_escape "${WEEWX_DATA}")|g" \
		"${PISKY_ROOT}/config_repo/pisky-weewx.service.repo" > "${rendered}"
	run_root install -m 0644 "${rendered}" /etc/systemd/system/pisky-weewx.service
	rm -f -- "${rendered}"
	trap - RETURN
	run_root systemctl daemon-reload
	run_root systemctl disable pisky-weewx.service >/dev/null 2>&1 || true
	log "WeeWX is installed but disabled until its station driver is configured in PiSky Setup."
}

install_beast_splitter()
{
	if command -v beast-splitter >/dev/null 2>&1; then
		log "Mode-S Beast support is already installed."
		return
	fi
	if [[ ${DRY_RUN} == "true" ]]; then
		log "Would build FlightAware beast-splitter ${BEAST_SPLITTER_COMMIT:0:12}."
		return
	fi

	log "Building pinned Mode-S Beast GPS support."
	apt_get install -y \
		git build-essential debhelper fakeroot \
		libboost-system-dev libboost-program-options-dev libboost-regex-dev

	local build_parent build_dir package
	build_parent="$(mktemp -d)"
	trap 'rm -rf -- "${build_parent:-}"' RETURN
	build_dir="${build_parent}/beast-splitter"
	git clone --quiet "${BEAST_SPLITTER_REPOSITORY}" "${build_dir}"
	git -C "${build_dir}" checkout --quiet "${BEAST_SPLITTER_COMMIT}"
	(
		cd "${build_dir}"
		dpkg-buildpackage -b -uc -us
	)
	package="$(find "${build_parent}" -maxdepth 1 -type f \
		-name 'beast-splitter_*_*.deb' -print -quit)"
	[[ -n ${package} ]] || die "The beast-splitter Debian package was not created."
	run_root dpkg -i "${package}"
	rm -rf -- "${build_parent}"
	trap - RETURN
}

grant_beast_serial_access()
{
	# beast-splitter.service runs as User=beast, and the package udev rule only
	# grants that user access to /dev/beast, for receivers whose USB model string
	# matches "Mode-S_Beast*". PiSky also allows /dev/ttyUSB* and /dev/ttyACM* to
	# be selected in Setup, and those nodes are root:dialout. Without dialout the
	# service fails with "i/o error: Permission denied" and no data ever reaches
	# the decoder.
	getent passwd beast >/dev/null 2>&1 || {
		log "The beast-splitter service account is missing; skipping serial access."
		return
	}
	for group in dialout plugdev; do
		if getent group "${group}" >/dev/null 2>&1; then
			run_root usermod -a -G "${group}" beast
		fi
	done
}

install_adsb()
{
	[[ ${WITH_ADSB} == "true" ]] || {
		log "Skipping optional local ADS-B decoder."
		return
	}

	log "Installing Debian's local ADS-B decoder and RTL-SDR support."
	apt_get install -y \
		dump1090-mutability rtl-sdr
	install_beast_splitter
	grant_beast_serial_access
	run_root install -d -m 0755 /var/lib/pisky/flights
	run_root install -d -m 0755 /etc/systemd/system/beast-splitter.service.d
	run_root install -m 0644 \
		"${PISKY_ROOT}/config_repo/pisky-beast-splitter.override.repo" \
		/etc/systemd/system/beast-splitter.service.d/pisky.conf
	run_root systemctl daemon-reload
	run_root /usr/local/sbin/piskyctl sync-adsb
	log "RTL-SDR is selected initially. Mode-S Beast GPS can be selected in PiSky Setup."
}

configure_module_selection()
{
	if [[ ${DRY_RUN} == "true" ]]; then
		log "Would enable Camera=${WITH_CAMERA}, Weather=${WITH_WEATHER}, Aircraft=${WITH_ADSB}."
		return
	fi

	local weather="${PISKY_ROOT}/config/pisky-weather.json"
	local flights="${PISKY_ROOT}/config/pisky-flights.json"
	[[ -r ${weather} && -r ${flights} ]] ||
		die "PiSky module configuration templates are missing."
	local weather_temporary flights_temporary site_temporary existing_site user_group
	weather_temporary="$(mktemp)"
	flights_temporary="$(mktemp)"
	site_temporary="$(mktemp)"
	existing_site="$(mktemp)"
	user_group="$(id -gn)"
	trap 'rm -f -- "${weather_temporary:-}" "${flights_temporary:-}" "${site_temporary:-}" "${existing_site:-}"' RETURN

	jq --argjson enabled "${WITH_WEATHER}" '.enabled = $enabled' \
		"${weather}" > "${weather_temporary}"
	jq --argjson enabled "${WITH_ADSB}" '.enabled = $enabled' \
		"${flights}" > "${flights_temporary}"
	if sudo test -r /var/lib/pisky/content/site.json; then
		run_root install -m 0600 -o "${USER}" -g "${user_group}" \
			/var/lib/pisky/content/site.json "${existing_site}"
		jq --argjson enabled "${WITH_CAMERA}" \
			'.modules = ((.modules // {}) + {camera: $enabled})' \
			"${existing_site}" > "${site_temporary}"
	else
		printf '{\n  "modules": {\n    "camera": %s\n  }\n}\n' \
			"${WITH_CAMERA}" > "${site_temporary}"
	fi

	run_root install -m 0660 -o "${USER}" -g www-data \
		"${weather_temporary}" "${weather}"
	run_root install -m 0660 -o "${USER}" -g www-data \
		"${flights_temporary}" "${flights}"
	run_root install -m 0640 -o www-data -g www-data \
		"${site_temporary}" /var/lib/pisky/content/site.json
	run_root /usr/local/sbin/piskyctl sync-adsb

	if [[ ${WITH_CAMERA} != "true" ]]; then
		run_root systemctl disable --now allsky.service allskyperiodic.service || true
	fi
	trap - RETURN
	rm -f -- "${weather_temporary}" "${flights_temporary}" "${site_temporary}" "${existing_site}"
	log "Enabled Camera=${WITH_CAMERA}, Weather=${WITH_WEATHER}, Aircraft=${WITH_ADSB}."
}

finish_install()
{
	run_root systemctl daemon-reload
	local address
	address="$(hostname -I 2>/dev/null | awk '{print $1}')"
	address="${address:-pisky.local}"

	if [[ ${CAMERA_INSTALL_FAILED} == "true" ]]; then
		log "PiSky ${PISKY_VERSION} installed, but the camera did not."
		printf 'The camera was left disabled because its installer did not finish.\n'
		printf 'Everything else below is installed and working.\n\n'
	else
		log "PiSky ${PISKY_VERSION} installation is complete."
	fi
	printf 'Open the public observatory at:\n'
	printf '  http://%s/\n\n' "${address}"
	printf 'Open the authenticated setup page at:\n'
	printf '  http://%s/admin/?page=pisky_setup\n\n' "${address}"
	printf 'Enabled modules: Camera=%s, Weather=%s, Aircraft=%s\n' \
		"${WITH_CAMERA}" "${WITH_WEATHER}" "${WITH_ADSB}"
	if [[ ${WITH_WEATHER} == "true" ]]; then
		printf 'Open-Meteo is the default weather source. Configure WeeWX before enabling its service.\n'
	fi
	if [[ ${WITH_ADSB} == "true" ]]; then
		printf 'FlightAware and Flightradar24 sharing remain opt-in.\n'
	fi
	if [[ ${WITH_CAMERA} == "true" || ${WITH_ADSB} == "true" || ${WITH_WEEWX} == "true" ]]; then
		printf 'A reboot is recommended after hardware and receiver setup.\n'
	fi
}

main()
{
	parse_options "$@"
	preflight
	preserve_existing_module_selection
	install_allsky
	install_base
	install_weewx
	install_adsb
	configure_module_selection
	finish_install
}

main "$@"
