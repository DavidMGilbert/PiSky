<?php
/*
 * PiSky restricted WebUI administration bridge.
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

if (!defined("PISKY_CONTROL")) {
	define("PISKY_CONTROL", "/usr/local/sbin/piskyctl");
}
if (!defined("PISKY_INCOMING")) {
	define("PISKY_INCOMING", "/var/lib/pisky/incoming");
}

function pisky_admin_run($arguments, &$output, &$exitCode) {
	$output = array();
	$exitCode = 1;
	if (!is_executable(PISKY_CONTROL)) {
		$output[] = "PiSky control helper is not installed.";
		return false;
	}

	$command = "sudo -n -- " . escapeshellarg(PISKY_CONTROL);
	foreach ($arguments as $argument) {
		$command .= " " . escapeshellarg(strval($argument));
	}
	exec($command . " 2>&1", $output, $exitCode);
	return $exitCode === 0;
}

function pisky_admin_stage($contents, $suffix, &$error) {
	$error = "";
	if (!is_dir(PISKY_INCOMING) || !is_writable(PISKY_INCOMING)) {
		$error = "PiSky's secure incoming directory is unavailable. Run the PiSky installer.";
		return null;
	}
	try {
		$random = bin2hex(random_bytes(10));
	} catch (Exception $exception) {
		$random = str_replace(".", "", uniqid("", true));
	}
	$safeSuffix = preg_replace("/[^a-z0-9.-]/i", "", $suffix);
	$path = PISKY_INCOMING . "/web-" . $random . "-" . $safeSuffix;
	$written = @file_put_contents($path, $contents, LOCK_EX);
	if ($written === false) {
		$error = "Unable to stage the configuration securely.";
		return null;
	}
	@chmod($path, 0600);
	return $path;
}

function pisky_admin_apply_configs($weather, $flights, &$message) {
	$message = "";
	$weatherJson = json_encode(
		$weather,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n";
	$flightsJson = json_encode(
		$flights,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n";
	if ($weatherJson === false || $flightsJson === false) {
		$message = "Unable to encode the PiSky configuration.";
		return false;
	}

	$error = "";
	$weatherPath = pisky_admin_stage($weatherJson, "weather.json", $error);
	if ($weatherPath === null) {
		$message = $error;
		return false;
	}
	$flightsPath = pisky_admin_stage($flightsJson, "flights.json", $error);
	if ($flightsPath === null) {
		@unlink($weatherPath);
		$message = $error;
		return false;
	}

	$output = array();
	$exitCode = 1;
	$ok = pisky_admin_run(
		array("apply-config-set", $weatherPath, $flightsPath),
		$output,
		$exitCode
	);
	@unlink($weatherPath);
	@unlink($flightsPath);
	$message = trim(implode("\n", $output));
	if ($message === "") {
		$message = $ok ? "PiSky configuration saved." : "PiSky configuration could not be saved.";
	}
	return $ok;
}

/*
 * Save the remote history database settings.
 *
 * The password is written through piskyctl rather than by the web user, and an
 * empty value tells it to keep whatever is already stored, so the interface
 * never has to read a secret back in order to render the form.
 */
function pisky_admin_apply_history($settings, &$message) {
	$message = "";
	$encoded = json_encode(
		$settings,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n";
	if ($encoded === false) {
		$message = "The history settings could not be encoded.";
		return false;
	}
	$error = "";
	$path = pisky_admin_stage($encoded, "history.json", $error);
	if ($path === null) {
		$message = $error;
		return false;
	}
	$output = array();
	$exitCode = 1;
	$ok = pisky_admin_run(array("apply-history", $path), $output, $exitCode);
	@unlink($path);
	$message = trim(implode("\n", $output));
	if ($message === "") {
		$message = $ok
			? "History database settings saved."
			: "History database settings could not be saved.";
	}
	return $ok;
}

/* Stored settings without the password, for rendering the form. */
function pisky_admin_read_history(&$error) {
	$error = "";
	$output = array();
	$exitCode = 1;
	if (!pisky_admin_run(array("read-history"), $output, $exitCode)) {
		$error = "The history settings could not be read.";
		return array("configured" => false);
	}
	$decoded = json_decode(trim(implode("\n", $output)), true);
	return is_array($decoded) ? $decoded : array("configured" => false);
}

/*
 * The control surface this interface expects.
 *
 * piskyctl is copied to /usr/local/sbin by the installer, so pulling new code
 * without re-running it leaves the helper behind. The interface then calls
 * commands the installed helper has never heard of, and the host sees
 * "Unknown command" with nothing to act on. Comparing versions turns that into
 * a statement of what happened and what to do.
 */
if (!defined("PISKY_REQUIRED_CONTROL_VERSION")) {
	define("PISKY_REQUIRED_CONTROL_VERSION", 6);
}

function pisky_admin_control_version() {
	$output = array();
	$exitCode = 1;
	if (!pisky_admin_run(array("status-json"), $output, $exitCode)) return null;
	$decoded = json_decode(trim(implode("\n", $output)), true);
	return is_array($decoded) && isset($decoded["control_version"])
		? intval($decoded["control_version"]) : null;
}

/* Empty when the helper is current, otherwise an explanation. */
function pisky_admin_control_warning() {
	$installed = pisky_admin_control_version();
	if ($installed === null || $installed >= PISKY_REQUIRED_CONTROL_VERSION) return "";
	return "The PiSky privileged helper on this system is version " . $installed
		. ", but this interface needs version " . PISKY_REQUIRED_CONTROL_VERSION
		. ". Newer controls will fail until it is updated. From the PiSky"
		. " folder run: sudo ./update-pisky.sh";
}

function pisky_admin_status(&$error) {
	$error = "";
	$output = array();
	$exitCode = 1;
	if (!pisky_admin_run(array("status-json"), $output, $exitCode)) {
		$error = trim(implode("\n", $output));
		return null;
	}
	$decoded = json_decode(implode("\n", $output), true);
	if (!is_array($decoded)) {
		$error = "PiSky returned an invalid component-status response.";
		return null;
	}
	return $decoded;
}

function pisky_admin_service($service, $operation, &$message) {
	$allowedServices = array(
		"allsky", "weewx", "dump1090-mutability", "dump1090-fa",
		"readsb", "beast-splitter", "piaware", "fr24feed"
	);
	$allowedOperations = array(
		"start", "stop", "restart", "enable-start", "disable-stop"
	);
	if (!in_array($service, $allowedServices, true)
		|| !in_array($operation, $allowedOperations, true)) {
		$message = "That service operation is not permitted.";
		return false;
	}
	$output = array();
	$exitCode = 1;
	$ok = pisky_admin_run(
		array("service", $service, $operation),
		$output,
		$exitCode
	);
	$message = trim(implode("\n", $output));
	if ($message === "") $message = $ok ? "Service updated." : "Service update failed.";
	return $ok;
}

function pisky_admin_read_weewx(&$error) {
	$error = "";
	$output = array();
	$exitCode = 1;
	if (!pisky_admin_run(array("read-weewx"), $output, $exitCode)) {
		$error = trim(implode("\n", $output));
		return null;
	}
	return implode("\n", $output) . "\n";
}

function pisky_admin_apply_weewx($contents, &$message) {
	$error = "";
	$path = pisky_admin_stage($contents, "weewx.conf", $error);
	if ($path === null) {
		$message = $error;
		return false;
	}
	$output = array();
	$exitCode = 1;
	$ok = pisky_admin_run(array("apply-weewx", $path), $output, $exitCode);
	@unlink($path);
	$message = trim(implode("\n", $output));
	if ($message === "") {
		$message = $ok ? "WeeWX configuration saved." : "WeeWX configuration could not be saved.";
	}
	return $ok;
}

function pisky_admin_configure_weewx_station($settings, &$message) {
	$encoded = json_encode(
		$settings,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n";
	if ($encoded === false) {
		$message = "Unable to encode the weather-station preset.";
		return false;
	}

	$error = "";
	$path = pisky_admin_stage($encoded, "weewx-station.json", $error);
	if ($path === null) {
		$message = $error;
		return false;
	}
	$output = array();
	$exitCode = 1;
	$ok = pisky_admin_run(
		array("configure-weewx-station", $path),
		$output,
		$exitCode
	);
	@unlink($path);
	$message = trim(implode("\n", $output));
	if ($message === "") {
		$message = $ok
			? "WeeWX station preset saved."
			: "The WeeWX station preset could not be saved.";
	}
	return $ok;
}

function pisky_admin_test_weewx_station(&$message) {
	$output = array();
	$exitCode = 1;
	$ok = pisky_admin_run(
		array("test-weewx-station"),
		$output,
		$exitCode
	);
	$message = trim(implode("\n", $output));
	if ($message === "") {
		$message = $ok
			? "The WeeWX station connection is healthy."
			: "PiSky could not confirm the WeeWX station connection.";
	}
	return $ok;
}
?>
