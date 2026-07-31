<?php
/*
 * Smoke-test the unauthenticated PiSky Setup render path.
 * SPDX-License-Identifier: MIT
 */

$_SERVER["REQUEST_METHOD"] = "GET";
$useLogin = false;

include dirname(__DIR__) . "/html/includes/piskySetup.php";

ob_start();
DisplayPiSkySetup();
$html = ob_get_clean();

$required = array(
	"PiSky setup",
	"Privileged controls are locked",
	"Save PiSky setup",
	'name="weather_display_units"',
	"Imperial — °F, inHg, mph, inches and miles",
	"Local ADS-B",
	"Mode-S Beast or compatible serial receiver",
	'name="beast_output_format"',
	"Beast Classic (most serial receivers)",
	"coverage map beneath the radar",
	'name="coverage_map_latitude"',
	'name="coverage_map_longitude"',
	'name="receiver_type"',
	'name="beast_serial_device"',
	"Connect your weather station",
	"Ecowitt / Fine Offset custom server",
	"Station sends to",
	'name="weewx_station_preset"',
	'name="weewx_station_port"',
	'name="weewx_use_as_provider"',
	"Check for live weather data"
);
foreach ($required as $needle) {
	if (strpos($html, $needle) === false) {
		fwrite(STDERR, "Missing setup render marker: " . $needle . PHP_EOL);
		exit(1);
	}
}

echo "PiSky setup GET render passed." . PHP_EOL;
