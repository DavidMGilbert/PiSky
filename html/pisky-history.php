<?php
/*
 * PiSky weather history endpoint.
 *
 * Serves the daily rollup for a date and, when a remote database is
 * configured, the intraday samples behind it. Samples are what the graphs are
 * drawn from; without a database the response still carries the rollup, and
 * the interface shows the summary instead of a curve.
 *
 * Readings the host has hidden from the visitor site are removed here too, so
 * history cannot be used to read a metric that is switched off on the live
 * page.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");
header("X-Content-Type-Options: nosniff");

include_once(__DIR__ . "/includes/functions.php");
include_once(__DIR__ . "/includes/status_messages.php");
include_once(__DIR__ . "/includes/piskyWeatherHistory.php");

$status = new StatusMessages();
$lastChangedName = "lastchanged";
initialize_variables();

$requestedDay = isset($_GET["day"]) && !is_array($_GET["day"])
	? pisky_history_safe_day($_GET["day"]) : "";
if ($requestedDay === "") {
	http_response_code(400);
	echo json_encode(array("ok" => false, "error" => "A valid day is required."));
	exit;
}

$config = pisky_weather_config();
if (empty($config["enabled"])) {
	http_response_code(503);
	echo json_encode(array("ok" => false, "error" => "Weather integration is disabled."));
	exit;
}

$rollup = pisky_history_day($requestedDay, $settings_array);
$sampleError = "";
$samples = pisky_history_samples($requestedDay, $sampleError);

/*
 * Apply the same visibility rules the live weather endpoint uses. A metric the
 * host has switched off must not reappear as a plotted line, and an
 * administrator gets the unfiltered series so hidden readings can still be
 * reviewed behind the login.
 */
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$wantsAdminScope = isset($_GET["scope"]) && $_GET["scope"] === "admin";
$maySeeHidden = !empty($_SESSION["pisky_authenticated"])
	|| (isset($useLogin) && $useLogin === false);
if (!$wantsAdminScope || !$maySeeHidden) {
	$hidden = array();
	foreach (pisky_history_sample_fields() as $field) {
		if (pisky_weather_metric_visible($config, $field)) continue;
		$hidden[$field] = true;
	}
	if (count($hidden)) {
		foreach ($samples as $index => $sample) {
			foreach (array_keys($hidden) as $field) unset($samples[$index][$field]);
		}
	}
	// The rollup mirrors the same measurements under different names.
	$rollupHidden = array(
		"temperature" => array("temperature_min", "temperature_max", "temperature_avg"),
		"humidity" => array("humidity_avg"),
		"pressure" => array("pressure_avg"),
		"wind_speed" => array("wind_speed_avg", "wind_gust_max"),
		"rain" => array("rain_total")
	);
	foreach ($rollupHidden as $metric => $keys) {
		if (pisky_weather_metric_visible($config, $metric)) continue;
		foreach ($keys as $key) unset($rollup[$key]);
	}
}

echo json_encode(array(
	"ok" => true,
	"day" => $requestedDay,
	"rollup" => $rollup,
	"samples" => $samples,
	// Absent samples are normal rather than an error: it means no database is
	// configured, so the interface should offer the summary and not a graph.
	"sample_count" => count($samples),
	"detail_available" => count($samples) > 1,
	"fields" => pisky_history_sample_fields()
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
