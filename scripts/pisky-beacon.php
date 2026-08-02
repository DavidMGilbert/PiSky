<?php
/*
 * PiSky shared-map beacon.
 *
 * Tells pisky.space that this station exists and where to read it. That is the
 * whole message: no name, no coordinates, no readings. The directory then
 * fetches this station's own /api/v1/station and draws whatever it finds
 * there, which means the map can never show something this station is not
 * already publishing, and a host who stops publishing stops appearing without
 * having to ask anyone to take a pin down.
 *
 * Run by a systemd timer, so listing keeps working on a station nobody is
 * looking at. Every failure is quiet and recorded rather than raised: an
 * unreachable directory is not a fault of the observatory.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

if (PHP_SAPI !== "cli") {
	http_response_code(404);
	exit(1);
}

require_once __DIR__ . "/../html/includes/piskySite.php";

$directory = pisky_directory_config();

// Nothing to announce. Exiting successfully keeps the timer alive, so turning
// the listing back on does not also mean re-enabling a unit.
if (empty($directory["enabled"])) {
	exit(0);
}

$endpoint = isset($directory["endpoint"]) ? trim(strval($directory["endpoint"])) : "";
if (!preg_match('#^https://[A-Za-z0-9.\-]+(:\d{1,5})?(/\S*)?$#', $endpoint)) {
	pisky_beacon_record("The directory address must be an https URL.");
	exit(0);
}

$identifier = pisky_directory_station_id($directory);
$url = pisky_site_public_url();

if ($identifier === "") {
	pisky_beacon_record("No station identifier yet. Save the listing in PiSky Setup.");
	exit(0);
}

/*
 * The address has to be one the directory can actually reach. A station only
 * ever seen at its .local name or a private address cannot be verified from
 * the internet, and announcing it would put an unreachable pin on the map.
 */
if (!pisky_directory_reachable_url($url)) {
	pisky_beacon_record("Set a reachable public address in PiSky Setup before listing.");
	exit(0);
}

$body = json_encode(array("station_id" => $identifier, "url" => $url));
$context = stream_context_create(array(
	"http" => array(
		"method" => "POST",
		"header" => "Content-Type: application/json\r\n"
			. "Accept: application/json\r\n"
			. "User-Agent: PiSky-Station/1.0 (+https://pisky.space)\r\n",
		"content" => $body,
		"timeout" => 10,
		// A rejection is an answer worth recording, not a transport failure.
		"ignore_errors" => true
	),
	"ssl" => array("verify_peer" => true, "verify_peer_name" => true)
));

$response = @file_get_contents($endpoint, false, $context);
$status = 0;
if (isset($http_response_header[0])
	&& preg_match('#\s(\d{3})\s#', $http_response_header[0], $matches)) {
	$status = intval($matches[1]);
}

if ($response === false) {
	pisky_beacon_record("The directory could not be reached.");
	exit(0);
}

$decoded = json_decode($response, true);
// Long enough for the directory's own diagnosis to survive intact. Truncating
// it mid-sentence hid the part naming what to fix.
$message = is_array($decoded) && isset($decoded["message"])
	? substr(strval($decoded["message"]), 0, 400) : "";

if ($status >= 200 && $status < 300) {
	pisky_beacon_record($message !== "" ? $message : "Listed on the shared map.", true);
} else {
	pisky_beacon_record(
		$message !== "" ? $message : "The directory declined the beacon (HTTP " . $status . ")."
	);
}

exit(0);

/*
 * Records the outcome so PiSky Setup can show it. Written back through the
 * site configuration, which is the only file this script is allowed to touch.
 */
function pisky_beacon_record($message, $listed = false) {
	$config = pisky_site_config();
	if (!isset($config["directory"]) || !is_array($config["directory"])) {
		$config["directory"] = array();
	}
	$config["directory"]["last_beacon"] = gmdate("c");
	$config["directory"]["last_result"] = $message;
	$config["directory"]["last_ok"] = $listed ? true : false;
	$error = "";
	pisky_site_write($config, $error);
}
?>
