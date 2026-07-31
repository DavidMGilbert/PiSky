<?php
/*
 * PiSky scheduled weather sampler.
 *
 * Records one weather sample and updates the day's rollup. Run from a systemd
 * timer rather than from a web request, because sampling driven by page loads
 * is not sampling at all: a station nobody visits records nothing, and a busy
 * one records only while it is being watched. Graphs need an even series
 * regardless of who is looking.
 *
 * Safe to run more often than the configured interval. Samples are aligned to
 * interval boundaries and written with INSERT IGNORE, so an extra run inside
 * the same slot is discarded by the database rather than distorting the
 * series.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

if (PHP_SAPI !== "cli") {
	fwrite(STDERR, "pisky-sample.php is a command-line tool." . PHP_EOL);
	exit(1);
}

$root = dirname(__DIR__);
require_once $root . "/html/includes/functions.php";
require_once $root . "/html/includes/status_messages.php";

$status = new StatusMessages();
$lastChangedName = "lastchanged";
initialize_variables();

require_once $root . "/html/includes/piskyWeatherHistory.php";

$config = pisky_weather_config();
if (empty($config["enabled"])) {
	fwrite(STDOUT, "Weather is disabled; nothing to record." . PHP_EOL);
	exit(0);
}

$weather = pisky_get_weather($settings_array);
if (empty($weather["ok"])) {
	// A provider being briefly unavailable is expected and not a failure worth
	// alerting on; systemd would otherwise mark the timer's unit failed and
	// stop reporting anything useful.
	fwrite(STDOUT, "No reading available: "
		. (isset($weather["error"]) ? $weather["error"] : "unknown reason") . PHP_EOL);
	exit(0);
}

$recorded = pisky_history_record($weather);
$sampled = pisky_history_remote_configured();

fwrite(STDOUT, sprintf(
	"Recorded %s rollup%s at %s.%s",
	$recorded ? "local" : "no",
	$sampled ? " and remote sample" : "",
	isset($weather["observed_at"]) ? $weather["observed_at"] : date(DATE_ATOM),
	PHP_EOL
));

// Pruning old detail is cheap and only meaningful where a remote store exists.
if ($sampled) pisky_history_prune();
exit(0);
?>
