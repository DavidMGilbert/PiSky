<?php
/*
 * Remote history storage.
 *
 * The remote database is optional and must never become a dependency. Every
 * read and write has to fail quietly when it is unconfigured or unreachable,
 * because a station losing its network must carry on recording daily
 * summaries locally rather than failing to serve weather at all.
 *
 * A live connection is not exercised here; that needs a real server. What is
 * checked is the decision-making around it, which is where a mistake would
 * silently disable local recording or leak a password.
 *
 * SPDX-License-Identifier: MIT
 */

$sandbox = sys_get_temp_dir() . "/pisky-store-" . getmypid();
@mkdir($sandbox, 0700, true);
putenv("PISKY_HISTORY_SETTINGS=" . $sandbox . "/history.json");
putenv("PISKY_WEATHER_HISTORY=" . $sandbox);

include_once dirname(__DIR__) . "/html/includes/piskyWeatherHistory.php";

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

function pisky_write_settings($path, $overrides) {
	file_put_contents($path, json_encode(array_merge(array(
		"enabled" => true, "driver" => "mysql", "host" => "db.invalid",
		"port" => 3306, "database" => "pisky", "user" => "station",
		"password" => "s3cret", "tls" => true, "station" => "lachlan",
		"sample_interval_seconds" => 300, "retain_days" => 400
	), $overrides)));
}

$settingsPath = $sandbox . "/history.json";

// With no settings file at all, nothing is configured and nothing throws.
pisky_expect("unconfigured by default", pisky_history_remote_configured(), false);
$error = "";
pisky_expect("samples empty when unconfigured", pisky_history_samples("20260728", $error), array());
pisky_expect("sample write refused when unconfigured",
	pisky_history_store_sample(array("ok" => true, "current" => array("temperature" => 5))), false);
pisky_expect("rollup write refused when unconfigured",
	pisky_history_store_rollup("20260728", array()), false);
pisky_expect("prune refused when unconfigured", pisky_history_prune(), false);

// Defaults must be safe: disabled, and not silently pointing anywhere.
$defaults = pisky_history_settings();
pisky_expect("default disabled", $defaults["enabled"], false);
pisky_expect("default host empty", $defaults["host"], "");
pisky_expect("default station", $defaults["station"], "default");

// Present but disabled is still not configured.
pisky_write_settings($settingsPath, array("enabled" => false));
pisky_expect("disabled is not configured", pisky_history_remote_configured(), false);

// Enabled but missing connection details is not configured either, so a
// half-filled form cannot put the recorder into a failing state.
foreach (array("host", "database", "user") as $missing) {
	pisky_write_settings($settingsPath, array($missing => ""));
	pisky_expect("missing " . $missing . " is not configured",
		pisky_history_remote_configured(), false);
}

// Fully specified and enabled counts as configured.
pisky_write_settings($settingsPath, array());
pisky_expect("complete settings are configured", pisky_history_remote_configured(), true);

// The password must never appear in anything the interface can render.
$public = pisky_history_settings_public();
pisky_expect("password removed", array_key_exists("password", $public), false);
pisky_expect("password presence reported", $public["password_set"], true);
pisky_expect("host still shown", $public["host"], "db.invalid");
pisky_write_settings($settingsPath, array("password" => ""));
pisky_expect("absent password reported", pisky_history_settings_public()["password_set"], false);

// Station identifiers are used in queries and must be reduced to a safe form.
pisky_write_settings($settingsPath, array("station" => "../../etc/passwd"));
pisky_expect("station sanitised", pisky_history_station(), "....etcpasswd");
pisky_write_settings($settingsPath, array("station" => ""));
pisky_expect("empty station falls back", pisky_history_station(), "default");
pisky_write_settings($settingsPath, array("station" => str_repeat("a", 200)));
pisky_expect("station length bounded", strlen(pisky_history_station()), 64);

// Recording locally must succeed even though the configured host does not
// exist. This is the property that keeps a station working offline.
pisky_write_settings($settingsPath, array("host" => "does-not-resolve.invalid"));
$reading = array(
	"ok" => true, "provider" => "weewx",
	"observed_at" => "2026-07-28T14:00:00+10:00",
	"units" => array("temperature" => "°C"),
	"current" => array("temperature" => 21.4, "humidity" => 60, "rain" => 0.4)
);
pisky_expect("local rollup still written", pisky_history_record($reading), true);
$day = pisky_history_day("20260728");
pisky_expect("local rollup readable", is_array($day), true);
pisky_expect("local high recorded", $day["temperature_max"], 21.4);

// Every sample field must be a plain identifier, since they become column
// names in generated SQL.
foreach (pisky_history_sample_fields() as $field) {
	if (preg_match("/^[a-z][a-z0-9_]*$/", $field)) continue;
	$failures++;
	fwrite(STDERR, "unsafe sample column name: " . $field . PHP_EOL);
}

array_map("unlink", glob($sandbox . "/*") ?: array());
@rmdir($sandbox);

if ($failures > 0) {
	fwrite(STDERR, $failures . " history store check(s) failed." . PHP_EOL);
	exit(1);
}

echo "PiSky history store passed." . PHP_EOL;
