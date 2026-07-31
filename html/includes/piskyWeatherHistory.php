<?php
/*
 * PiSky weather history.
 *
 * PiSky keeps its own daily rollup so a station archives the readings it
 * actually measured, rather than a model's opinion of the weather over the
 * station. One JSON file per month is written under the weather state
 * directory and updated as observations arrive.
 *
 * Dates from before PiSky was installed have no rollup, so those are filled
 * from Open-Meteo's free archive service on demand and cached. Records are
 * labelled with their origin so the interface never presents reanalysis data
 * as a station measurement.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

include_once(__DIR__ . "/piskyWeather.php");
include_once(__DIR__ . "/piskyHistoryStore.php");

/*
 * Deliberately not /var/lib/pisky/weather: that directory holds WeeWX's own
 * current.json and is owned by root or weewx, so the web server cannot write
 * to it. History is written by the web request that serves weather, so it
 * needs a directory the web user owns.
 */
if (!defined("PISKY_HISTORY_DIR")) {
	define("PISKY_HISTORY_DIR", "/var/lib/pisky/history");
}

function pisky_history_dir() {
	$override = getenv("PISKY_WEATHER_HISTORY");
	if ($override !== false && trim($override) !== "") return rtrim(trim($override), "/\\");
	return PISKY_HISTORY_DIR;
}

/*
 * Which day a reading belongs to, in the station's own local time.
 *
 * date() would bucket by the web server's timezone, so an observation stamped
 * 06:00+10:00 would be filed against the previous day whenever the server runs
 * in UTC. The offset carried by observed_at is authoritative, because that is
 * the station's local time.
 */
function pisky_history_day_key($observedAt) {
	// date_create() returns false on a bad string rather than throwing. PHP 8.3
	// raises DateMalformedStringException from the constructor, and under
	// Xdebug decorating that exception can raise a further Error that a
	// catch (Exception) block will not hold, turning a bad timestamp into a
	// fatal. Avoiding the throw entirely is portable across both.
	if (is_string($observedAt) && trim($observedAt) !== "") {
		$date = date_create($observedAt);
		// Parsing can succeed and still yield nonsense: "0000-00-00" becomes a
		// negative year, which would name a month file history--00011.json. The
		// result is only accepted once it is a real calendar day.
		if ($date !== false && pisky_history_safe_day($date->format("Ymd")) !== "") {
			return $date->format("Ymd");
		}
	}
	return pisky_history_today();
}

/*
 * Today in the station's configured timezone, used to decide which days are
 * finished and therefore safe to fill from the historical archive.
 */
function pisky_history_today() {
	$config = pisky_weather_config();
	$zone = isset($config["open_meteo"]["timezone"])
		? trim(strval($config["open_meteo"]["timezone"])) : "";
	if ($zone === "" || $zone === "auto") {
		$zone = is_readable("/etc/timezone")
			? trim(file_get_contents("/etc/timezone")) : date_default_timezone_get();
	}
	$timezone = @timezone_open($zone);
	if ($timezone === false) return date("Ymd");
	$now = date_create("now", $timezone);
	return $now === false ? date("Ymd") : $now->format("Ymd");
}

function pisky_history_safe_day($day) {
	$day = strval($day);
	if (!preg_match("/^[0-9]{8}$/", $day)) return "";
	$date = DateTime::createFromFormat("!Ymd", $day);
	return $date && $date->format("Ymd") === $day ? $day : "";
}

function pisky_history_month_path($month) {
	return pisky_history_dir() . "/history-" . $month . ".json";
}

function pisky_history_read_month($month) {
	if (!preg_match("/^[0-9]{6}$/", $month)) return array();
	$path = pisky_history_month_path($month);
	if (!is_readable($path)) return array();
	$decoded = json_decode(file_get_contents($path), true);
	return is_array($decoded) ? $decoded : array();
}

function pisky_history_write_month($month, $days) {
	$directory = pisky_history_dir();
	if (!is_dir($directory) && !@mkdir($directory, 0755, true)) return false;
	$path = pisky_history_month_path($month);
	$temporary = $path . ".tmp";
	// Written atomically because the recorder runs from the web request that
	// happens to notice the day has advanced, and two can overlap.
	if (@file_put_contents($temporary, json_encode($days, JSON_PRETTY_PRINT), LOCK_EX) === false) {
		return false;
	}
	return @rename($temporary, $path);
}

function pisky_history_extremes($existing, $key, $value) {
	if (!is_numeric($value)) return $existing;
	$value = floatval($value);
	if (!is_array($existing)) $existing = array();
	if (!isset($existing[$key . "_min"]) || $value < $existing[$key . "_min"]) {
		$existing[$key . "_min"] = $value;
	}
	if (!isset($existing[$key . "_max"]) || $value > $existing[$key . "_max"]) {
		$existing[$key . "_max"] = $value;
	}
	$countKey = $key . "_count";
	$sumKey = $key . "_sum";
	$existing[$sumKey] = (isset($existing[$sumKey]) ? $existing[$sumKey] : 0) + $value;
	$existing[$countKey] = (isset($existing[$countKey]) ? $existing[$countKey] : 0) + 1;
	$existing[$key . "_avg"] = round($existing[$sumKey] / $existing[$countKey], 2);
	return $existing;
}

/*
 * Fold the current reading into today's rollup. Called opportunistically from
 * the weather endpoint, so it must be cheap and must never throw: a failure to
 * record history is not a reason to fail a weather request.
 */
function pisky_history_record($weather) {
	if (empty($weather["ok"]) || !isset($weather["current"])) return false;
	$observedAt = isset($weather["observed_at"]) ? $weather["observed_at"] : "";
	$day = pisky_history_day_key($observedAt);
	$month = substr($day, 0, 6);

	$days = pisky_history_read_month($month);
	$record = isset($days[$day]) && is_array($days[$day]) ? $days[$day] : array(
		"date" => $day,
		"source" => isset($weather["provider"]) ? $weather["provider"] : "unknown",
		"units" => isset($weather["units"]) ? $weather["units"] : array()
	);

	$current = $weather["current"];
	foreach (array(
		"temperature", "humidity", "pressure", "wind_speed", "dew_point"
	) as $field) {
		if (!isset($current[$field])) continue;
		$record = pisky_history_extremes($record, $field, $current[$field]);
	}
	if (isset($current["wind_gust"]) && is_numeric($current["wind_gust"])) {
		$gust = floatval($current["wind_gust"]);
		if (!isset($record["wind_gust_max"]) || $gust > $record["wind_gust_max"]) {
			$record["wind_gust_max"] = $gust;
		}
	}
	// Rain is reported as a running daily total by most stations, so the
	// largest value seen during the day is the day's total.
	if (isset($current["rain"]) && is_numeric($current["rain"])) {
		$rain = floatval($current["rain"]);
		if (!isset($record["rain_total"]) || $rain > $record["rain_total"]) {
			$record["rain_total"] = $rain;
		}
	}
	if (isset($current["condition"]) && $current["condition"] !== "") {
		$record["condition"] = $current["condition"];
	}
	if (isset($current["weather_code"])) $record["weather_code"] = $current["weather_code"];
	// Keep the station's own offset rather than restamping in server time.
	$record["updated_at"] = $observedAt !== "" ? $observedAt : date(DATE_ATOM);
	$record["origin"] = "station";

	$days[$day] = $record;
	$written = pisky_history_write_month($month, $days);

	// The local rollup is the authority and is always written first. A remote
	// database, when one is configured, additionally receives the intraday
	// sample that makes graphs possible and a mirror of the rollup so the
	// record outlives the SD card. Neither is allowed to affect the outcome:
	// an unreachable host must not stop the station recording locally.
	if (pisky_history_remote_configured()) {
		pisky_history_store_sample($weather);
		pisky_history_store_rollup($day, $record);
	}
	return $written;
}

function pisky_history_summarise($record) {
	if (!is_array($record)) return null;
	return array(
		"date" => isset($record["date"]) ? $record["date"] : null,
		"origin" => isset($record["origin"]) ? $record["origin"] : "station",
		"source" => isset($record["source"]) ? $record["source"] : null,
		"units" => isset($record["units"]) ? $record["units"] : array(),
		"condition" => isset($record["condition"]) ? $record["condition"] : null,
		"weather_code" => isset($record["weather_code"]) ? $record["weather_code"] : null,
		"temperature_max" => isset($record["temperature_max"]) ? $record["temperature_max"] : null,
		"temperature_min" => isset($record["temperature_min"]) ? $record["temperature_min"] : null,
		"temperature_avg" => isset($record["temperature_avg"]) ? $record["temperature_avg"] : null,
		"humidity_avg" => isset($record["humidity_avg"]) ? $record["humidity_avg"] : null,
		"pressure_avg" => isset($record["pressure_avg"]) ? $record["pressure_avg"] : null,
		"wind_speed_avg" => isset($record["wind_speed_avg"]) ? $record["wind_speed_avg"] : null,
		"wind_gust_max" => isset($record["wind_gust_max"]) ? $record["wind_gust_max"] : null,
		"rain_total" => isset($record["rain_total"]) ? $record["rain_total"] : null
	);
}

/*
 * Open-Meteo's archive service covers dates PiSky was not running for. Results
 * are cached into the same monthly file under an "archive" origin so a given
 * date is only fetched once.
 */
function pisky_history_backfill($day, $latitude, $longitude, $timezone) {
	$day = pisky_history_safe_day($day);
	if ($day === "" || $latitude === null || $longitude === null) return null;
	$date = DateTime::createFromFormat("!Ymd", $day)->format("Y-m-d");
	$query = http_build_query(array(
		"latitude" => $latitude,
		"longitude" => $longitude,
		"start_date" => $date,
		"end_date" => $date,
		"daily" => "weather_code,temperature_2m_max,temperature_2m_min,"
			. "temperature_2m_mean,precipitation_sum,wind_speed_10m_max,"
			. "wind_gusts_10m_max,relative_humidity_2m_mean,"
			. "surface_pressure_mean,sunrise,sunset",
		"timezone" => $timezone !== "" ? $timezone : "auto"
	));
	$error = "";
	$raw = pisky_http_json(
		"https://archive-api.open-meteo.com/v1/archive?" . $query, $error
	);
	if (!isset($raw["daily"]["time"][0])) return null;

	$pick = function ($field) use ($raw) {
		return isset($raw["daily"][$field][0]) ? $raw["daily"][$field][0] : null;
	};
	$code = $pick("weather_code");
	return array(
		"date" => $day,
		"origin" => "archive",
		"source" => "open-meteo-archive",
		"units" => array(
			"temperature" => "°C", "humidity" => "%", "pressure" => "hPa",
			"wind_speed" => "km/h", "rain" => "mm"
		),
		"condition" => $code !== null ? pisky_weather_description($code) : null,
		"weather_code" => $code,
		"temperature_max" => $pick("temperature_2m_max"),
		"temperature_min" => $pick("temperature_2m_min"),
		"temperature_avg" => $pick("temperature_2m_mean"),
		"humidity_avg" => $pick("relative_humidity_2m_mean"),
		"pressure_avg" => $pick("surface_pressure_mean"),
		"wind_speed_avg" => $pick("wind_speed_10m_max"),
		"wind_gust_max" => $pick("wind_gusts_10m_max"),
		"rain_total" => $pick("precipitation_sum"),
		"sunrise" => $pick("sunrise"),
		"sunset" => $pick("sunset")
	);
}

function pisky_history_day($day, $settings = array()) {
	$day = pisky_history_safe_day($day);
	if ($day === "") return null;
	$month = substr($day, 0, 6);
	$days = pisky_history_read_month($month);
	if (isset($days[$day])) return pisky_history_summarise($days[$day]);

	// Never backfill today or later: the day is still accumulating and the
	// station's own rollup is the authority for it.
	if ($day >= pisky_history_today()) return null;

	$config = pisky_weather_config();
	$openMeteo = isset($config["open_meteo"]) ? $config["open_meteo"] : array();
	$latitude = pisky_decimal_coordinate(isset($openMeteo["latitude"]) ? $openMeteo["latitude"] : "");
	$longitude = pisky_decimal_coordinate(isset($openMeteo["longitude"]) ? $openMeteo["longitude"] : "");
	if ($latitude === null && isset($settings["latitude"])) {
		$latitude = pisky_decimal_coordinate($settings["latitude"]);
	}
	if ($longitude === null && isset($settings["longitude"])) {
		$longitude = pisky_decimal_coordinate($settings["longitude"]);
	}
	$record = pisky_history_backfill(
		$day, $latitude, $longitude,
		isset($openMeteo["timezone"]) ? $openMeteo["timezone"] : "auto"
	);
	if ($record === null) return null;
	$days[$day] = $record;
	pisky_history_write_month($month, $days);
	return pisky_history_summarise($record);
}

/*
 * Recorded days, newest first, optionally narrowed by a YYYY, YYYYMM or
 * YYYYMMDD fragment. Only months PiSky has written are scanned; archive
 * backfill happens when a specific day is opened.
 */
function pisky_history_days($search = "", $limit = 120) {
	$directory = pisky_history_dir();
	if (!is_dir($directory)) return array();
	$digits = preg_replace("/[^0-9]/", "", strval($search));
	$records = array();
	foreach (glob($directory . "/history-[0-9][0-9][0-9][0-9][0-9][0-9].json") ?: array() as $path) {
		$month = substr(basename($path), 8, 6);
		if ($digits !== "" && strlen($digits) <= 6
			&& strpos($month, substr($digits, 0, min(6, strlen($digits)))) !== 0) continue;
		$days = pisky_history_read_month($month);
		foreach ($days as $day => $record) {
			// Keys come from a file on disk, and the interface formats them as
			// dates. Anything that is not a real calendar day is skipped rather
			// than being allowed to reach a date formatter.
			if (pisky_history_safe_day($day) === "") continue;
			if ($digits !== "" && strpos($day, $digits) !== 0) continue;
			$summary = pisky_history_summarise($record);
			if ($summary !== null) $records[$day] = $summary;
		}
	}
	krsort($records);
	return array_slice(array_values($records), 0, max(1, intval($limit)));
}
?>
