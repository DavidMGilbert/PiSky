<?php
/*
 * PiSky public data API.
 *
 * Read-only access to what the station already publishes. It deliberately
 * exposes nothing beyond the visitor site: every response passes through the
 * same public-metric filter, so a reading switched off in the administration
 * interface is absent here too. There is no write surface at all.
 *
 * Access is open, because the data is already public on the station's own
 * pages and requiring a key to read a public web page helps nobody. Keys exist
 * only to raise rate limits for callers who need to poll harder than an
 * anonymous visitor should.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

include_once(__DIR__ . "/piskyWeather.php");
include_once(__DIR__ . "/piskyWeatherHistory.php");
include_once(__DIR__ . "/piskyFlights.php");
include_once(__DIR__ . "/piskySite.php");

if (!defined("PISKY_API_VERSION")) define("PISKY_API_VERSION", "1");
if (!defined("PISKY_API_KEYS")) define("PISKY_API_KEYS", "/etc/pisky/api-keys.json");
if (!defined("PISKY_API_STATE")) define("PISKY_API_STATE", "/var/lib/pisky/api");

function pisky_api_settings() {
	$site = pisky_site_config();
	$api = isset($site["api"]) && is_array($site["api"]) ? $site["api"] : array();
	return array(
		"enabled" => array_key_exists("enabled", $api) ? !empty($api["enabled"]) : true,
		// Empty means same-origin only. "*" opens it to any site.
		"origins" => isset($api["origins"]) && is_array($api["origins"]) ? $api["origins"] : array(),
		"anonymous_per_hour" => isset($api["anonymous_per_hour"])
			? max(10, min(10000, intval($api["anonymous_per_hour"]))) : 600,
		"key_per_hour" => isset($api["key_per_hour"])
			? max(10, min(100000, intval($api["key_per_hour"]))) : 6000
	);
}

/*
 * Cross-origin access is opt-in per origin. Reflecting an allow-listed origin
 * rather than answering "*" keeps the allow-list meaningful, and the response
 * carries no credentials either way.
 */
function pisky_api_cors($settings) {
	$origin = isset($_SERVER["HTTP_ORIGIN"]) ? trim($_SERVER["HTTP_ORIGIN"]) : "";
	if ($origin === "") return;
	$allowed = $settings["origins"];
	if (in_array("*", $allowed, true)) {
		header("Access-Control-Allow-Origin: *");
	} else if (in_array($origin, $allowed, true)) {
		header("Access-Control-Allow-Origin: " . $origin);
		header("Vary: Origin");
	} else {
		return;
	}
	header("Access-Control-Allow-Methods: GET, OPTIONS");
	header("Access-Control-Allow-Headers: Accept, X-PiSky-Key");
	header("Access-Control-Max-Age: 600");
}

function pisky_api_key() {
	if (isset($_SERVER["HTTP_X_PISKY_KEY"])) return trim($_SERVER["HTTP_X_PISKY_KEY"]);
	if (isset($_GET["key"]) && !is_array($_GET["key"])) return trim($_GET["key"]);
	return "";
}

/*
 * Keys are stored hashed, so the file is not itself a list of usable
 * credentials, and compared in constant time.
 */
function pisky_api_key_valid($key) {
	if ($key === "" || !is_readable(PISKY_API_KEYS)) return false;
	$decoded = json_decode(file_get_contents(PISKY_API_KEYS), true);
	if (!is_array($decoded) || !isset($decoded["keys"]) || !is_array($decoded["keys"])) return false;
	$candidate = hash("sha256", $key);
	foreach ($decoded["keys"] as $entry) {
		if (!isset($entry["hash"]) || !empty($entry["revoked"])) continue;
		if (hash_equals(strval($entry["hash"]), $candidate)) return true;
	}
	return false;
}

/*
 * Fixed-window counter per caller per hour.
 *
 * Deliberately simple: a Pi serving a personal station does not need a sliding
 * window, and a counter that costs one small file write per request is cheaper
 * than anything cleverer. Failure to write is not fatal, since dropping a
 * request because the counter could not be updated would be worse than serving
 * it.
 */
function pisky_api_rate_limit($settings, $authenticated, &$remaining, &$limit) {
	$limit = $authenticated ? $settings["key_per_hour"] : $settings["anonymous_per_hour"];
	$remaining = $limit;
	$directory = PISKY_API_STATE;
	if (!is_dir($directory)) return true;

	$caller = $authenticated
		? "key-" . substr(hash("sha256", pisky_api_key()), 0, 16)
		: "ip-" . hash("sha256", isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "unknown");
	$window = gmdate("YmdH");
	$path = $directory . "/" . $caller . "-" . $window . ".count";

	$count = 0;
	$handle = @fopen($path, "c+");
	if ($handle === false) return true;
	if (flock($handle, LOCK_EX)) {
		$contents = stream_get_contents($handle);
		$count = intval(trim(strval($contents)));
		$count++;
		ftruncate($handle, 0);
		rewind($handle);
		fwrite($handle, strval($count));
		fflush($handle);
		flock($handle, LOCK_UN);
	}
	fclose($handle);

	// Opportunistically drop counters from previous windows.
	if (mt_rand(1, 50) === 1) {
		foreach (glob($directory . "/*.count") ?: array() as $stale) {
			if (strpos(basename($stale), "-" . $window . ".count") === false
				&& @filemtime($stale) < time() - 7200) {
				@unlink($stale);
			}
		}
	}

	$remaining = max(0, $limit - $count);
	return $count <= $limit;
}

function pisky_api_send($payload, $status = 200) {
	http_response_code($status);
	echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	exit;
}

function pisky_api_error($message, $status) {
	pisky_api_send(array("ok" => false, "error" => $message), $status);
}

/* Station identity and what it publishes. */
function pisky_api_station($settings_array) {
	$site = pisky_site_config();
	$capabilities = pisky_site_capabilities();
	$flights = pisky_flights_config();
	$coverage = isset($flights["coverage_map"]) ? $flights["coverage_map"] : array();
	$location = null;
	// Coordinates are published only where the host already publishes them.
	if (!empty($coverage["enabled"]) && !empty($coverage["public"])) {
		$latitude = pisky_decimal_coordinate(
			isset($coverage["latitude"]) && $coverage["latitude"] !== ""
				? $coverage["latitude"] : (isset($flights["latitude"]) ? $flights["latitude"] : "")
		);
		$longitude = pisky_decimal_coordinate(
			isset($coverage["longitude"]) && $coverage["longitude"] !== ""
				? $coverage["longitude"] : (isset($flights["longitude"]) ? $flights["longitude"] : "")
		);
		if ($latitude !== null && $longitude !== null) {
			$location = array("latitude" => $latitude, "longitude" => $longitude);
		}
	}
	$name = trim(strval(getVariableOrDefault($settings_array, "location", "")));
	$payload = array(
		"ok" => true,
		"name" => $name !== "" ? $name : "PiSky Observatory",
		"tagline" => isset($site["tagline"]) ? $site["tagline"] : "",
		"capabilities" => $capabilities,
		"location" => $location,
		"software" => array("name" => "PiSky", "api_version" => PISKY_API_VERSION)
	);

	/*
	 * Listing on the shared map.
	 *
	 * The station announces its address to pisky.space and the map reads this
	 * block back to decide what to draw, so this is the single authority on
	 * whether the station is listed and where. Absent means not listed: a host
	 * who switches it off disappears at the next check without having to ask
	 * anyone to remove anything.
	 */
	$directory = pisky_directory_config();
	if (!empty($directory["enabled"])) {
		$identifier = pisky_directory_station_id($directory);
		$precision = pisky_directory_precision($directory);
		/*
		 * The station position is written to both the weather and the aircraft
		 * configuration by Setup, but only one of them is guaranteed to hold
		 * it: a station that has never saved the aircraft section, or has the
		 * module switched off, has coordinates under weather alone. Reading
		 * only the aircraft copy meant such a station published no position and
		 * so could never be placed, while its own pages showed the location
		 * perfectly well.
		 */
		$weatherConfig = pisky_weather_config();
		$openMeteo = isset($weatherConfig["open_meteo"]) && is_array($weatherConfig["open_meteo"])
			? $weatherConfig["open_meteo"] : array();
		$latitude = pisky_decimal_coordinate(
			isset($flights["latitude"]) && $flights["latitude"] !== ""
				? $flights["latitude"]
				: (isset($openMeteo["latitude"]) ? $openMeteo["latitude"] : "")
		);
		$longitude = pisky_decimal_coordinate(
			isset($flights["longitude"]) && $flights["longitude"] !== ""
				? $flights["longitude"]
				: (isset($openMeteo["longitude"]) ? $openMeteo["longitude"] : "")
		);
		// Without both a position and an identity there is nothing to place.
		if ($identifier !== "" && $latitude !== null && $longitude !== null) {
			$payload["directory"] = array(
				"listed" => true,
				"station_id" => $identifier,
				"precision" => $precision,
				"latitude" => pisky_directory_round($latitude, $precision),
				"longitude" => pisky_directory_round($longitude, $precision),
				"url" => pisky_site_public_url()
			);
		}
	}

	return $payload;
}

function pisky_api_weather($settings_array) {
	$weather = pisky_get_weather($settings_array);
	if (empty($weather["ok"])) {
		pisky_api_error(isset($weather["error"]) ? $weather["error"] : "Weather is unavailable.", 503);
	}
	return pisky_weather_filter_public($weather, pisky_weather_config());
}

function pisky_api_aircraft($settings_array) {
	$flights = pisky_get_flights($settings_array);
	if (empty($flights["ok"])) {
		pisky_api_error(isset($flights["error"]) ? $flights["error"] : "Aircraft data is unavailable.", 503);
	}
	return $flights;
}

function pisky_api_sky($settings_array) {
	$capabilities = pisky_site_capabilities();
	if (empty($capabilities["camera"])) {
		pisky_api_error("This station does not publish a sky camera.", 404);
	}
	$name = getVariableOrDefault($settings_array, "filename", "image.jpg");
	$path = (defined("ALLSKY_TMP") ? ALLSKY_TMP : "") . "/" . basename($name);
	return array(
		"ok" => true,
		"image_url" => "/" . ltrim(strval($name), "/"),
		"captured_at" => is_readable($path) ? date(DATE_ATOM, filemtime($path)) : null
	);
}

function pisky_api_history($settings_array) {
	$day = isset($_GET["day"]) && !is_array($_GET["day"])
		? pisky_history_safe_day($_GET["day"]) : "";
	if ($day === "") pisky_api_error("A valid day in YYYYMMDD form is required.", 400);
	$config = pisky_weather_config();
	$rollup = pisky_history_day($day, $settings_array);
	$error = "";
	$samples = pisky_history_samples($day, $error);
	foreach (pisky_history_sample_fields() as $field) {
		if (pisky_weather_metric_visible($config, $field)) continue;
		foreach ($samples as $index => $sample) unset($samples[$index][$field]);
	}
	return array(
		"ok" => true, "day" => $day, "rollup" => $rollup,
		"samples" => $samples, "sample_count" => count($samples)
	);
}
?>
