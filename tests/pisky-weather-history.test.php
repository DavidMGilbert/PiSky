<?php
/*
 * PiSky's daily weather rollup.
 *
 * History is written by whichever web request happens to serve weather, so
 * recording must be cheap, repeatable and safe to interleave. These checks
 * cover the accumulation rules — running extremes, means, and rain treated as
 * a running daily total rather than a sample to be summed — plus the date
 * guards that keep a crafted "day" parameter from reaching the filesystem.
 *
 * The Open-Meteo archive fetch is not exercised here because it needs the
 * network; its field names are verified against the live service separately.
 *
 * SPDX-License-Identifier: MIT
 */

$sandbox = sys_get_temp_dir() . "/pisky-history-" . getmypid();
@mkdir($sandbox, 0700, true);
putenv("PISKY_WEATHER_HISTORY=" . $sandbox);

include_once dirname(__DIR__) . "/html/includes/piskyWeatherHistory.php";

$failures = 0;

function pisky_expect($label, $actual, $expected, $tolerance = 0.001) {
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

function pisky_reading($time, $temperature, $rain, $gust = null) {
	return array(
		"ok" => true,
		"provider" => "weewx",
		"observed_at" => $time,
		"units" => array("temperature" => "°C", "rain" => "mm", "wind_speed" => "km/h"),
		"current" => array(
			"temperature" => $temperature,
			"humidity" => 60,
			"rain" => $rain,
			"wind_gust" => $gust,
			"condition" => "Mostly clear",
			"weather_code" => 1
		)
	);
}

// Three readings through one day.
pisky_history_record(pisky_reading("2026-07-28T06:00:00+10:00", 6.2, 0.0, 11.0));
pisky_history_record(pisky_reading("2026-07-28T14:00:00+10:00", 21.4, 0.4, 22.6));
pisky_history_record(pisky_reading("2026-07-28T20:00:00+10:00", 13.9, 0.4, 18.0));

$day = pisky_history_day("20260728");
pisky_expect("record exists", is_array($day), true);
pisky_expect("high is the maximum seen", $day["temperature_max"], 21.4);
pisky_expect("low is the minimum seen", $day["temperature_min"], 6.2);
pisky_expect("mean averages the samples", $day["temperature_avg"], 13.83, 0.01);
pisky_expect("peak gust retained", $day["wind_gust_max"], 22.6);
pisky_expect("origin marked as station", $day["origin"], "station");
pisky_expect("condition retained", $day["condition"], "Mostly clear");

// Rain is a running daily total, so the day's figure is the largest seen and
// must not accumulate to 0.8.
pisky_expect("rain not double counted", $day["rain_total"], 0.4);

// Re-recording the same values must stay idempotent for extremes.
pisky_history_record(pisky_reading("2026-07-28T21:00:00+10:00", 21.4, 0.4, 22.6));
$again = pisky_history_day("20260728");
pisky_expect("high unchanged on repeat", $again["temperature_max"], 21.4);
pisky_expect("rain unchanged on repeat", $again["rain_total"], 0.4);

// A second day must not disturb the first.
pisky_history_record(pisky_reading("2026-07-29T09:00:00+10:00", 9.9, 1.2, 30.0));
$first = pisky_history_day("20260728");
$second = pisky_history_day("20260729");
pisky_expect("first day intact", $first["temperature_max"], 21.4);
pisky_expect("second day recorded", $second["temperature_max"], 9.9);
pisky_expect("second day rain", $second["rain_total"], 1.2);

// Listing and search.
$all = pisky_history_days();
pisky_expect("both days listed", count($all), 2);
pisky_expect("newest first", $all[0]["date"], "20260729");
pisky_expect("month search", count(pisky_history_days("202607")), 2);
pisky_expect("exact day search", count(pisky_history_days("20260728")), 1);
pisky_expect("year search", count(pisky_history_days("2026")), 2);
pisky_expect("non-matching search", count(pisky_history_days("20250101")), 0);

// Date validation must reject anything that is not a real calendar day, so a
// crafted parameter can never be turned into a path.
foreach (array("", "2026072", "202607281", "abcdefgh", "../../etc", "20261301",
	"20260230", "00000000") as $bad) {
	pisky_expect("rejects " . var_export($bad, true), pisky_history_safe_day($bad), "");
}
pisky_expect("accepts a real date", pisky_history_safe_day("20260728"), "20260728");
pisky_expect("accepts a leap day", pisky_history_safe_day("20240229"), "20240229");
pisky_expect("rejects a non-leap 29 Feb", pisky_history_safe_day("20250229"), "");

// Day boundaries follow the station's offset, not the web server's timezone.
// Both of these are the same instant; the station calls it 28 July, and a
// server running in UTC must not file it against the 27th.
pisky_expect(
	"early morning stays on the station's day",
	pisky_history_day_key("2026-07-28T06:00:00+10:00"), "20260728"
);
pisky_expect(
	"late evening stays on the station's day",
	pisky_history_day_key("2026-07-28T23:30:00+10:00"), "20260728"
);
pisky_expect(
	"a negative offset is honoured too",
	pisky_history_day_key("2026-07-28T22:00:00-05:00"), "20260728"
);
// Bad timestamps must fall back to a real day rather than throwing or, worse,
// parsing into a nonsense one. "0000-00-00" parses to a negative year, which
// would name a month file history--00011.json.
foreach (array("not a date", "", "0000-00-00", "2026-13-45T99:99:99+10:00",
	"<script>", "0", "-1") as $bad) {
	$key = pisky_history_day_key($bad);
	pisky_expect(
		"bad stamp " . var_export($bad, true) . " yields a real day",
		pisky_history_safe_day($key), $key
	);
}

// An unrecorded future date must not be backfilled: the day has not happened.
pisky_expect("future date not invented", pisky_history_day(date("Ymd", time() + 172800)), null);

// A malformed month file must not fatal the reader.
file_put_contents($sandbox . "/history-202601.json", "{ this is not json");
pisky_expect("corrupt month tolerated", is_array(pisky_history_days()), true);

// Nor may a month file containing a bogus day key, because the interface
// formats those keys as dates.
file_put_contents($sandbox . "/history-202602.json", json_encode(array(
	"notaday" => array("date" => "notaday", "temperature_max" => 5),
	"20260229" => array("date" => "20260229", "temperature_max" => 6),
	"20260214" => array("date" => "20260214", "temperature_max" => 7)
)));
$february = pisky_history_days("202602");
pisky_expect("only the real February day survives", count($february), 1);
pisky_expect("and it is the valid one", $february[0]["date"], "20260214");

array_map("unlink", glob($sandbox . "/*") ?: array());
@rmdir($sandbox);

if ($failures > 0) {
	fwrite(STDERR, $failures . " weather history check(s) failed." . PHP_EOL);
	exit(1);
}

echo "PiSky weather history rollup passed." . PHP_EOL;
