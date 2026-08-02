<?php
/*
 * PiSky public data API, version 1.
 *
 * Routed by ?resource= so it works on any web server without rewrite support.
 * The installed lighttpd configuration additionally maps /api/v1/<resource>
 * onto this file, so both forms are valid and documented.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

include_once(dirname(__DIR__, 2) . "/includes/functions.php");
include_once(dirname(__DIR__, 2) . "/includes/status_messages.php");
include_once(dirname(__DIR__, 2) . "/includes/piskyApi.php");

$status = new StatusMessages();
$lastChangedName = "lastchanged";
initialize_variables();

header("Content-Type: application/json; charset=utf-8");
header("X-Content-Type-Options: nosniff");
header("Cache-Control: public, max-age=5");

$settings = pisky_api_settings();
pisky_api_cors($settings);

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "OPTIONS") {
	http_response_code(204);
	exit;
}
if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") {
	header("Allow: GET, OPTIONS");
	pisky_api_error("The PiSky API is read-only.", 405);
}
/*
 * Listing on the shared map is itself consent to be read, and it is read
 * through this resource. Requiring the public API to be switched on as well
 * made opting in on the Station tab do nothing, with the failure surfacing
 * only as an unreachable station on a website the host does not control.
 *
 * Only the station resource is admitted this way. It carries identity,
 * capabilities and the position the host chose to publish — nothing the
 * listing does not already put on a public map — while weather, aircraft,
 * history and the sky image stay behind the API switch.
 */
// PATH_INFO is used when the server supplies it, otherwise the query
// parameter. Resolved before the switch below needs it, because whether this
// request is allowed at all depends on which resource was asked for.
$resource = "";
if (isset($_SERVER["PATH_INFO"]) && $_SERVER["PATH_INFO"] !== "") {
	$resource = trim(strval($_SERVER["PATH_INFO"]), "/");
} else if (isset($_GET["resource"]) && !is_array($_GET["resource"])) {
	$resource = trim(strval($_GET["resource"]));
}
$resource = strtolower(preg_replace("/[^a-z0-9_-]/i", "", $resource));

$listed = !empty(pisky_directory_config()["enabled"]);

if (empty($settings["enabled"]) && !($listed && $resource === "station")) {
	pisky_api_error("This station has not enabled its public API.", 404);
}

$authenticated = pisky_api_key_valid(pisky_api_key());
$remaining = 0;
$limit = 0;
$withinLimit = pisky_api_rate_limit($settings, $authenticated, $remaining, $limit);
header("X-RateLimit-Limit: " . $limit);
header("X-RateLimit-Remaining: " . $remaining);
if (!$withinLimit) {
	header("Retry-After: " . (3600 - (time() % 3600)));
	pisky_api_error(
		"Rate limit reached. Supply an API key for a higher allowance.", 429
	);
}

switch ($resource) {
	case "":
	case "index":
		pisky_api_send(array(
			"ok" => true,
			"api_version" => PISKY_API_VERSION,
			"resources" => array("station", "weather", "aircraft", "sky", "history"),
			"documentation" => "https://www.pisky.space/docs/api",
			"rate_limit" => array("limit" => $limit, "remaining" => $remaining)
		));
		break;
	case "station":  pisky_api_send(pisky_api_station($settings_array)); break;
	case "weather":  pisky_api_send(pisky_api_weather($settings_array)); break;
	case "aircraft": pisky_api_send(pisky_api_aircraft($settings_array)); break;
	case "sky":      pisky_api_send(pisky_api_sky($settings_array)); break;
	case "history":  pisky_api_send(pisky_api_history($settings_array)); break;
	default:
		pisky_api_error("Unknown resource '" . $resource . "'.", 404);
}
?>
