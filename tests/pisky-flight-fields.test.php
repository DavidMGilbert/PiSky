<?php
/*
 * PiSky must decode both ADS-B JSON dialects.
 *
 * dump1090-fa and readsb emit alt_baro / gs / baro_rate. Debian's
 * dump1090-mutability, which install-pisky.sh installs, emits
 * altitude / speed / vert_rate. Reading only the first dialect left
 * altitude and speed blank on a stock PiSky installation.
 *
 * SPDX-License-Identifier: MIT
 */

include_once dirname(__DIR__) . "/html/includes/piskyFlights.php";

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

$dialects = array(
	"dump1090-fa/readsb" => array(
		"alt_baro" => 18475, "gs" => 342.1, "baro_rate" => -640, "track" => 221.4
	),
	"dump1090-mutability" => array(
		"altitude" => 18475, "speed" => 342.1, "vert_rate" => -640, "track" => 221.4
	)
);

foreach ($dialects as $label => $sample) {
	$decoded = pisky_flights_decode_fields($sample);
	pisky_expect($label . " altitude_ft", $decoded["altitude_ft"], 18475.0);
	pisky_expect($label . " speed_knots", $decoded["speed_knots"], 342.1);
	pisky_expect($label . " vertical_rate", $decoded["vertical_rate"], -640.0);
	pisky_expect($label . " track", $decoded["track"], 221.4);
}

// Geometric fallbacks apply when the barometric fields are absent.
$geometric = pisky_flights_decode_fields(array("alt_geom" => 19000, "geom_rate" => 320));
pisky_expect("alt_geom fallback", $geometric["altitude_ft"], 19000.0);
pisky_expect("geom_rate fallback", $geometric["vertical_rate"], 320.0);

// Barometric readings win when a decoder supplies both dialects at once.
$both = pisky_flights_decode_fields(array(
	"alt_baro" => 12000, "altitude" => 999, "gs" => 300, "speed" => 111
));
pisky_expect("alt_baro precedence", $both["altitude_ft"], 12000.0);
pisky_expect("gs precedence", $both["speed_knots"], 300.0);

// Aircraft on the ground report a string altitude, which must survive intact
// so the interface can label it rather than rendering a misleading zero.
$ground = pisky_flights_decode_fields(array("altitude" => "ground", "speed" => 12));
pisky_expect("ground altitude passthrough", $ground["altitude_ft"], "ground");

// Absent fields stay null rather than collapsing to zero.
$empty = pisky_flights_decode_fields(array("hex" => "7c6b2d"));
pisky_expect("missing altitude", $empty["altitude_ft"], null);
pisky_expect("missing speed", $empty["speed_knots"], null);
pisky_expect("missing vertical rate", $empty["vertical_rate"], null);

if ($failures > 0) {
	fwrite(STDERR, $failures . " ADS-B field mapping check(s) failed." . PHP_EOL);
	exit(1);
}

echo "PiSky ADS-B field mapping passed." . PHP_EOL;
