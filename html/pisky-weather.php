<?php
/*
 * PiSky normalized weather API
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");
header("X-Content-Type-Options: nosniff");

include_once(__DIR__ . "/includes/functions.php");
include_once(__DIR__ . "/includes/status_messages.php");
include_once(__DIR__ . "/includes/piskyWeather.php");
include_once(__DIR__ . "/includes/piskyWeatherHistory.php");

$status = new StatusMessages();
$lastChangedName = "lastchanged";
initialize_variables();

$weather = pisky_get_weather($settings_array);
// Fold the reading into the daily rollup that feeds the archive. History is a
// side effect of serving weather, so a recording failure must not affect the
// response. Recording happens before any filtering, because what the host
// chooses to publish should not change what the station archives.
if (!empty($weather["ok"])) pisky_history_record($weather);

// The administration interface asks for the unfiltered response so its
// visibility toggles can show live values for metrics that are currently
// hidden. That is honoured only behind the same boundary that protects the
// rest of the administration area: a valid session, or an installation that
// has deliberately disabled login and is therefore already open. Every other
// caller gets the filtered response, so a hidden reading is genuinely absent
// rather than merely not rendered.
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$wantsAdminScope = isset($_GET["scope"]) && $_GET["scope"] === "admin";
$maySeeHidden = !empty($_SESSION["pisky_authenticated"])
	|| (isset($useLogin) && $useLogin === false);
if (!$wantsAdminScope || !$maySeeHidden) {
	$weather = pisky_weather_filter_public($weather, pisky_weather_config());
}

if (empty($weather["ok"])) http_response_code(503);
echo json_encode($weather, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
