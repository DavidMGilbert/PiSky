<?php
/*
 * Validate PiSky's host-wide weather display-unit conversions.
 * SPDX-License-Identifier: MIT
 */

require_once dirname(__DIR__) . "/html/includes/piskyWeather.php";

function pisky_test_close($actual, $expected, $tolerance, $label) {
	if (!is_numeric($actual) || abs(floatval($actual) - $expected) > $tolerance) {
		fwrite(
			STDERR,
			$label . ": expected " . $expected . ", received " . var_export($actual, true) . PHP_EOL
		);
		exit(1);
	}
}

$metric = array(
	"ok" => true,
	"units" => array(
		"temperature" => "°C",
		"pressure" => "hPa",
		"wind_speed" => "km/h",
		"rain" => "mm",
		"visibility" => "km"
	),
	"current" => array(
		"temperature" => 20,
		"apparent_temperature" => 21,
		"dew_point" => 10,
		"pressure" => 1013.25,
		"wind_speed" => 10,
		"wind_gust" => 20,
		"rain" => 25.4,
		"visibility" => 10
	),
	"forecast" => array(array("temperature" => 5)),
	"daily" => array(array(
		"temperature_max" => 25,
		"temperature_min" => 10,
		"rain" => 12.7,
		"wind_speed_max" => 16.09344
	))
);
$imperial = pisky_weather_apply_display_units($metric, "imperial");
pisky_test_close($imperial["current"]["temperature"], 68, 0.001, "Celsius to Fahrenheit");
pisky_test_close($imperial["current"]["pressure"], 29.9213, 0.001, "hPa to inHg");
pisky_test_close($imperial["current"]["wind_speed"], 6.2137, 0.001, "km/h to mph");
pisky_test_close($imperial["current"]["rain"], 1, 0.001, "mm to inches");
pisky_test_close($imperial["current"]["visibility"], 6.2137, 0.001, "km to miles");
pisky_test_close($imperial["forecast"][0]["temperature"], 41, 0.001, "forecast conversion");
pisky_test_close($imperial["daily"][0]["temperature_max"], 77, 0.001, "daily high conversion");
pisky_test_close($imperial["daily"][0]["rain"], 0.5, 0.001, "daily rain conversion");
pisky_test_close($imperial["daily"][0]["wind_speed_max"], 10, 0.001, "daily wind conversion");
if ($imperial["units"]["temperature"] !== "°F"
	|| $imperial["units"]["pressure"] !== "inHg") {
	fwrite(STDERR, "Imperial unit labels were not applied." . PHP_EOL);
	exit(1);
}

$sourceImperial = array(
	"ok" => true,
	"units" => array(
		"temperature" => "°F",
		"pressure" => "inHg",
		"wind_speed" => "mph",
		"rain" => "in",
		"visibility" => "mile"
	),
	"current" => array(
		"temperature" => 68,
		"pressure" => 29.92,
		"wind_speed" => 10,
		"rain" => 1,
		"visibility" => 6.2
	)
);
$convertedMetric = pisky_weather_apply_display_units($sourceImperial, "metric");
pisky_test_close($convertedMetric["current"]["temperature"], 20, 0.001, "Fahrenheit to Celsius");
pisky_test_close($convertedMetric["current"]["pressure"], 1013.21, 0.05, "inHg to hPa");
pisky_test_close($convertedMetric["current"]["wind_speed"], 16.0934, 0.001, "mph to km/h");
pisky_test_close($convertedMetric["current"]["rain"], 25.4, 0.001, "inches to mm");
pisky_test_close($convertedMetric["current"]["visibility"], 9.9779, 0.001, "miles to km");

echo "PiSky metric and imperial weather conversions passed." . PHP_EOL;
