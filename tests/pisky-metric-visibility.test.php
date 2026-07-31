<?php
/*
 * Public metric visibility.
 *
 * A host can switch individual readings off for the visitor site. Hiding must
 * remove the reading from the response rather than merely stop rendering it,
 * and it must stay distinguishable from a reading the sensors could not
 * supply — the first should make the card disappear, the second should still
 * show its dash.
 *
 * SPDX-License-Identifier: MIT
 */

include_once dirname(__DIR__) . "/html/includes/piskyWeather.php";

$failures = 0;

function pisky_expect($label, $actual, $expected) {
	global $failures;
	if ($actual === $expected) return;
	$failures++;
	fwrite(STDERR, sprintf(
		"%s: expected %s, got %s%s",
		$label, var_export($expected, true), var_export($actual, true), PHP_EOL
	));
}

function pisky_sample() {
	return array(
		"ok" => true,
		"current" => array(
			"temperature" => 14.8,
			"humidity" => 72,
			"pressure" => 1018.4,
			"wind_speed" => 7.2,
			"cloud_cover" => 40,
			"visibility" => null,
			"condition" => "Partly cloudy"
		),
		"observations" => array(
			array("id" => "uv", "label" => "UV index", "value" => 3.4),
			array("id" => "pm2_5", "label" => "PM2.5", "value" => 6.2),
			array("id" => "indoor_temperature", "label" => "Indoor", "value" => 21.0)
		)
	);
}

// Nothing configured: everything is published.
$open = pisky_weather_filter_public(pisky_sample(), array());
pisky_expect("default publishes temperature", array_key_exists("temperature", $open["current"]), true);
pisky_expect("default publishes all observations", count($open["observations"]), 3);
pisky_expect("default hides nothing", $open["hidden_count"], 0);

// An explicit true is also visible; only false hides.
$explicit = pisky_weather_filter_public(pisky_sample(), array(
	"public_metrics" => array("humidity" => true)
));
pisky_expect("explicit true stays visible", array_key_exists("humidity", $explicit["current"]), true);

// Hiding removes the key entirely, so the client can tell it apart from null.
$config = array("public_metrics" => array(
	"humidity" => false, "pressure" => false, "indoor_temperature" => false
));
$filtered = pisky_weather_filter_public(pisky_sample(), $config);
pisky_expect("hidden humidity is absent", array_key_exists("humidity", $filtered["current"]), false);
pisky_expect("hidden pressure is absent", array_key_exists("pressure", $filtered["current"]), false);
pisky_expect("visible temperature remains", $filtered["current"]["temperature"], 14.8);
pisky_expect("visible cloud cover remains", $filtered["current"]["cloud_cover"], 40);

// A reading the sensors could not supply stays present and null, so the
// interface still shows a dash rather than removing the card.
pisky_expect("unavailable visibility still present",
	array_key_exists("visibility", $filtered["current"]), true);
pisky_expect("unavailable visibility is null", $filtered["current"]["visibility"], null);

// Observations are filtered by the same identifiers.
$ids = array();
foreach ($filtered["observations"] as $observation) $ids[] = $observation["id"];
pisky_expect("indoor temperature hidden", in_array("indoor_temperature", $ids, true), false);
pisky_expect("uv still published", in_array("uv", $ids, true), true);
pisky_expect("pm2_5 still published", in_array("pm2_5", $ids, true), true);
pisky_expect("observation list is re-indexed", array_keys($filtered["observations"]), array(0, 1));
pisky_expect("hidden count reported", $filtered["hidden_count"], 3);

// The helper itself.
pisky_expect("absent key is visible", pisky_weather_metric_visible(array(), "uv"), true);
pisky_expect("true is visible",
	pisky_weather_metric_visible(array("public_metrics" => array("uv" => true)), "uv"), true);
pisky_expect("false is hidden",
	pisky_weather_metric_visible(array("public_metrics" => array("uv" => false)), "uv"), false);
pisky_expect("unknown metric defaults visible",
	pisky_weather_metric_visible(array("public_metrics" => array("uv" => false)), "solar_radiation"), true);

// A failed reading must pass through untouched rather than being rewritten.
$failed = pisky_weather_filter_public(array("ok" => false, "error" => "boom"), $config);
pisky_expect("failed payload untouched", $failed["error"], "boom");
pisky_expect("failed payload not marked filtered", isset($failed["filtered"]), false);

// Every registry entry must be filterable, or a metric could be toggled in the
// interface and still leak to the visitor site.
$all = array();
foreach (array_keys(pisky_weather_metric_registry()) as $id) $all[$id] = false;
$everything = pisky_weather_filter_public(pisky_sample(), array("public_metrics" => $all));
foreach (array_keys(pisky_weather_metric_registry()) as $id) {
	if (!array_key_exists($id, $everything["current"])) continue;
	$failures++;
	fwrite(STDERR, "registry metric " . $id . " survived being hidden." . PHP_EOL);
}

if ($failures > 0) {
	fwrite(STDERR, $failures . " metric visibility check(s) failed." . PHP_EOL);
	exit(1);
}

echo "PiSky public metric visibility passed." . PHP_EOL;
