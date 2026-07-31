<?php
/*
 * A weather station measures what its sensors can reach. Cloud cover,
 * visibility and a weather code almost never come from a WeeWX station, so
 * PiSky fills those from Open-Meteo while leaving every real station reading
 * untouched.
 *
 * The subtle part is units. Open-Meteo answers in metric, the station may be
 * configured in imperial, and the merged record is later converted for display
 * using the station's units. A borrowed value must therefore be expressed in
 * the station's units first, or 24 km becomes 24 miles.
 *
 * SPDX-License-Identifier: MIT
 */

include_once dirname(__DIR__) . "/html/includes/piskyWeather.php";

$failures = 0;

function pisky_expect($label, $actual, $expected, $tolerance = 0.01) {
	global $failures;
	$ok = is_numeric($expected) && is_numeric($actual)
		? abs(floatval($actual) - floatval($expected)) <= $tolerance
		: $actual === $expected;
	if ($ok) return;
	$failures++;
	fwrite(STDERR, sprintf(
		"%s: expected %s, got %s%s",
		$label, var_export($expected, true), var_export($actual, true), PHP_EOL
	));
}

// An imperial WeeWX station: real temperature and wind, no sky observations.
$station = array(
	"ok" => true,
	"provider" => "weewx",
	"units" => array(
		"temperature" => "°F", "humidity" => "%", "pressure" => "inHg",
		"wind_speed" => "mph", "rain" => "in", "cloud_cover" => "%",
		"visibility" => "mi"
	),
	"current" => array(
		"temperature" => 58.6,
		"humidity" => 72,
		"wind_speed" => 4.5,
		"wind_gust" => null,
		"pressure" => 30.07,
		"dew_point" => null,
		"apparent_temperature" => null,
		"cloud_cover" => null,
		"visibility" => null,
		"weather_code" => null,
		"condition" => "Local station observation"
	),
	"observations" => array(
		array("id" => "rain_today", "label" => "Rain today", "value" => 0.12, "unit" => "in")
	),
	"forecast" => array(), "daily" => array(), "astronomy" => array()
);

// Open-Meteo, always metric.
$openMeteo = array(
	"ok" => true,
	"provider" => "open-meteo",
	"units" => array(
		"temperature" => "°C", "humidity" => "%", "pressure" => "hPa",
		"wind_speed" => "km/h", "rain" => "mm", "cloud_cover" => "%",
		"visibility" => "km"
	),
	"current" => array(
		"temperature" => 14.8, "humidity" => 70, "dew_point" => 9.7,
		"apparent_temperature" => 13.9, "pressure" => 1018.4,
		"wind_gust" => 18.5, "cloud_cover" => 40, "visibility" => 24.0,
		"weather_code" => 2, "is_day" => true, "condition" => "Partly cloudy"
	),
	"observations" => array(
		array("id" => "uv", "label" => "UV index", "value" => 3.4, "unit" => ""),
		array("id" => "rain_today", "label" => "Rain today", "value" => 9.9, "unit" => "mm"),
		array("id" => "pm2_5", "label" => "PM2.5", "value" => 6.2, "unit" => "µg/m³")
	),
	"forecast" => array(array("time" => "2026-07-29T12:00:00Z", "temperature" => 15.0)),
	"daily" => array(), "astronomy" => array("sunrise" => "07:01")
);

$merged = pisky_weather_supplement_local($station, $openMeteo);

// Station readings must survive untouched.
pisky_expect("station temperature kept", $merged["current"]["temperature"], 58.6);
pisky_expect("station wind kept", $merged["current"]["wind_speed"], 4.5);
pisky_expect("station pressure kept", $merged["current"]["pressure"], 30.07);

// Unitless gaps copy straight across.
pisky_expect("cloud cover filled", $merged["current"]["cloud_cover"], 40);
pisky_expect("weather code filled", $merged["current"]["weather_code"], 2);
pisky_expect("condition filled", $merged["current"]["condition"], "Partly cloudy");

// Values with units must arrive in the station's units, not Open-Meteo's.
pisky_expect("visibility converted to miles", $merged["current"]["visibility"], 14.9129);
pisky_expect("dew point converted to F", $merged["current"]["dew_point"], 49.46);
pisky_expect("apparent temperature converted to F", $merged["current"]["apparent_temperature"], 57.02);
pisky_expect("wind gust converted to mph", $merged["current"]["wind_gust"], 11.4954);

// The station's own rain total wins over Open-Meteo's.
$byId = array();
foreach ($merged["observations"] as $observation) $byId[$observation["id"]] = $observation;
pisky_expect("station rain_today wins", $byId["rain_today"]["value"], 0.12);
pisky_expect("station rain_today unit kept", $byId["rain_today"]["unit"], "in");
pisky_expect("uv supplemented", $byId["uv"]["value"], 3.4);
pisky_expect("pm2_5 supplemented", $byId["pm2_5"]["value"], 6.2);
pisky_expect("supplemented flag set", $byId["uv"]["supplemented"], true);

// Provenance must be reported so the interface can attribute honestly.
foreach (array("cloud_cover", "visibility", "weather_code", "uv") as $field) {
	if (!in_array($field, $merged["supplemented_fields"], true)) {
		$failures++;
		fwrite(STDERR, "supplemented_fields is missing " . $field . PHP_EOL);
	}
}
if (in_array("temperature", $merged["supplemented_fields"], true)) {
	$failures++;
	fwrite(STDERR, "a real station reading was wrongly marked supplemented." . PHP_EOL);
}

// Converting the merged record for imperial display must not shift the
// borrowed values again.
$displayed = pisky_weather_apply_display_units($merged, "imperial");
pisky_expect("visibility stable in imperial display", $displayed["current"]["visibility"], 14.9129);
pisky_expect("temperature stable in imperial display", $displayed["current"]["temperature"], 58.6);

// The same record shown in metric must convert back to real metric values.
$metric = pisky_weather_apply_display_units($merged, "metric");
pisky_expect("visibility back to km", $metric["current"]["visibility"], 24.0);
pisky_expect("temperature back to C", $metric["current"]["temperature"], 14.78, 0.05);

if ($failures > 0) {
	fwrite(STDERR, $failures . " weather supplement check(s) failed." . PHP_EOL);
	exit(1);
}

echo "PiSky weather supplement and unit handling passed." . PHP_EOL;
