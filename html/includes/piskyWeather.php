<?php
/*
 * PiSky weather provider bridge
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 *
 * This adapter interoperates with WeeWX over JSON; it does not include or
 * modify WeeWX code. Open-Meteo data is used under CC BY 4.0 and its API terms.
 */

function pisky_weather_defaults() {
	return array(
		"enabled" => true,
		"provider" => "open-meteo",
		"display_units" => "metric",
		"open_meteo" => array(
			"latitude" => "",
			"longitude" => "",
			"timezone" => "auto",
			"cache_seconds" => 300
		),
		"weewx" => array(
			"file" => "/var/lib/pisky/weather/current.json",
			"url" => "",
			"cache_seconds" => 10
		),
		// Metrics the visitor site may show. Absent or true means visible, so
		// an existing installation keeps showing everything after upgrading and
		// new readings appear without needing to be opted in.
		"public_metrics" => array()
	);
}

/*
 * The fixed readings every provider reports, in the order the interface shows
 * them. Supplementary observations are discovered at runtime instead, because
 * which ones exist depends entirely on the station's sensors.
 */
function pisky_weather_metric_registry() {
	return array(
		"temperature" => "Temperature",
		"apparent_temperature" => "Feels like",
		"humidity" => "Humidity",
		"dew_point" => "Dew point",
		"pressure" => "Pressure",
		"wind_speed" => "Wind speed",
		"wind_gust" => "Wind gust",
		"wind_direction" => "Wind direction",
		"rain" => "Rain",
		"cloud_cover" => "Cloud cover",
		"visibility" => "Visibility",
		"condition" => "Conditions"
	);
}

function pisky_weather_metric_visible($config, $id) {
	if (!isset($config["public_metrics"]) || !is_array($config["public_metrics"])) return true;
	// Only an explicit false hides a metric.
	if (!array_key_exists($id, $config["public_metrics"])) return true;
	return !empty($config["public_metrics"][$id]);
}

/*
 * Remove readings the host has hidden before the visitor site sees them.
 *
 * Hidden metrics are unset rather than nulled, so the interface can tell "the
 * host chose not to publish this" from "the sensor had nothing to report" and
 * hide the card entirely instead of showing an empty dash. Filtering happens
 * server-side so a hidden reading is genuinely absent from the public
 * response, not merely undisplayed.
 */
function pisky_weather_filter_public($data, $config) {
	if (!is_array($data) || empty($data["ok"])) return $data;
	$hidden = array();
	if (isset($data["current"]) && is_array($data["current"])) {
		foreach (array_keys(pisky_weather_metric_registry()) as $id) {
			if (pisky_weather_metric_visible($config, $id)) continue;
			unset($data["current"][$id]);
			$hidden[] = $id;
		}
	}
	if (isset($data["observations"]) && is_array($data["observations"])) {
		$kept = array();
		foreach ($data["observations"] as $observation) {
			$id = isset($observation["id"]) ? $observation["id"] : "";
			if ($id !== "" && !pisky_weather_metric_visible($config, $id)) {
				$hidden[] = $id;
				continue;
			}
			$kept[] = $observation;
		}
		$data["observations"] = array_values($kept);
	}
	$data["filtered"] = true;
	$data["hidden_count"] = count($hidden);
	return $data;
}

function pisky_weather_config_path() {
	$override = getenv("PISKY_WEATHER_CONFIG");
	if ($override !== false && trim($override) !== "") return $override;
	if (defined("ALLSKY_CONFIG")) return ALLSKY_CONFIG . "/pisky-weather.json";
	return dirname(__DIR__, 2) . "/config/pisky-weather.json";
}

function pisky_merge_config($defaults, $custom) {
	foreach ($custom as $key => $value) {
		if (isset($defaults[$key]) && is_array($defaults[$key]) && is_array($value)) {
			$defaults[$key] = pisky_merge_config($defaults[$key], $value);
		} else {
			$defaults[$key] = $value;
		}
	}
	return $defaults;
}

function pisky_weather_config() {
	$config = pisky_weather_defaults();
	$path = pisky_weather_config_path();
	if (is_readable($path)) {
		$decoded = json_decode(file_get_contents($path), true);
		if (is_array($decoded)) $config = pisky_merge_config($config, $decoded);
	}
	$provider = getenv("PISKY_WEATHER_PROVIDER");
	if ($provider !== false && trim($provider) !== "") $config["provider"] = trim($provider);
	return $config;
}

function pisky_decimal_coordinate($value) {
	if (is_int($value) || is_float($value)) return floatval($value);
	$value = strtoupper(trim(strval($value)));
	if ($value === "") return null;
	$negative = strpos($value, "S") !== false
		|| strpos($value, "W") !== false
		|| preg_match("/^\s*-/", $value);
	$number = preg_replace("/[^0-9.+-]/", "", $value);
	if ($number === "" || !is_numeric($number)) return null;
	$number = abs(floatval($number));
	return $negative ? -$number : $number;
}

function pisky_cache_path($provider) {
	$safe = preg_replace("/[^a-z0-9_-]/i", "-", $provider);
	return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "pisky-weather-" . $safe . ".json";
}

function pisky_read_cache($provider, $maxAge, $allowStale) {
	$path = pisky_cache_path($provider);
	if (!is_readable($path)) return null;
	if (!$allowStale && (time() - filemtime($path)) > $maxAge) return null;
	$data = json_decode(file_get_contents($path), true);
	return is_array($data) ? $data : null;
}

function pisky_write_cache($provider, $data) {
	@file_put_contents(
		pisky_cache_path($provider),
		json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
		LOCK_EX
	);
}

function pisky_http_json($url, &$error) {
	$error = "";
	if (!preg_match("#^https?://#i", $url)) {
		$error = "Weather URL must use HTTP or HTTPS.";
		return null;
	}

	$body = false;
	if (function_exists("curl_init")) {
		$curl = curl_init($url);
		curl_setopt_array($curl, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 4,
			CURLOPT_TIMEOUT => 7,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 2,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_USERAGENT => "PiSky/1.0 (+https://pisky.space)",
			CURLOPT_HTTPHEADER => array("Accept: application/json")
		));
		$body = curl_exec($curl);
		$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		if ($body === false) $error = curl_error($curl);
		curl_close($curl);
		if ($body === false) return null;
		if ($status < 200 || $status >= 300) {
			$error = "Weather service returned HTTP " . $status . ".";
			return null;
		}
	} else {
		$context = stream_context_create(array("http" => array(
			"timeout" => 7,
			"header" => "Accept: application/json\r\nUser-Agent: PiSky/1.0 (+https://pisky.space)\r\n"
		)));
		$body = @file_get_contents($url, false, $context);
		if ($body === false) $error = "Unable to contact the weather service.";
	}

	if ($body === false) return null;
	$decoded = json_decode($body, true);
	if (!is_array($decoded)) {
		$error = "Weather service returned invalid JSON.";
		return null;
	}
	return $decoded;
}

function pisky_local_json_path_allowed($path, $kind) {
	$resolved = realpath($path);
	if ($resolved === false || !is_file($resolved)) return false;
	$roots = $kind === "flights"
		? array(
			"/run/dump1090-mutability", "/var/run/dump1090-mutability",
			"/run/dump1090-fa", "/var/run/dump1090-fa",
			"/run/readsb", "/var/run/readsb",
			"/run/dump1090", "/var/run/dump1090",
			"/run/beast-splitter",
			"/var/lib/pisky/flights"
		)
		: array("/var/lib/pisky/weather", "/var/lib/weewx", "/var/www/html/weewx");
	foreach ($roots as $root) {
		$resolvedRoot = realpath($root);
		if ($resolvedRoot === false) continue;
		$prefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		if (strpos($resolved, $prefix) === 0) return true;
	}
	return false;
}

function pisky_weather_description($code) {
	$descriptions = array(
		0 => "Clear sky",
		1 => "Mainly clear",
		2 => "Partly cloudy",
		3 => "Overcast",
		45 => "Fog",
		48 => "Rime fog",
		51 => "Light drizzle",
		53 => "Drizzle",
		55 => "Heavy drizzle",
		61 => "Light rain",
		63 => "Rain",
		65 => "Heavy rain",
		71 => "Light snow",
		73 => "Snow",
		75 => "Heavy snow",
		80 => "Rain showers",
		81 => "Rain showers",
		82 => "Heavy showers",
		95 => "Thunderstorm",
		96 => "Thunderstorm with hail",
		99 => "Severe thunderstorm"
	);
	return isset($descriptions[intval($code)]) ? $descriptions[intval($code)] : "Current conditions";
}

function pisky_dew_point($temperature, $humidity) {
	if (!is_numeric($temperature) || !is_numeric($humidity) || floatval($humidity) <= 0) return null;
	$a = 17.62;
	$b = 243.12;
	$gamma = log(floatval($humidity) / 100) + ($a * floatval($temperature)) / ($b + floatval($temperature));
	return round(($b * $gamma) / ($a - $gamma), 1);
}

function pisky_weather_unit_key($unit) {
	$unit = strtolower(trim(strval($unit)));
	$unit = str_replace("°", "", $unit);
	$unit = preg_replace("#[^a-z0-9/]+#", "", $unit);
	// A rate carries the same conversion factor as the depth it is measured in,
	// so "in/h" and "mm/hr" are matched as "in" and "mm". Without this an
	// imperial station's rain rate was left unconverted.
	if (preg_match("#^(mm|cm|in|inch|inches)/(h|hr|hour)$#", $unit, $matches)) {
		return $matches[1];
	}
	return $unit;
}

function pisky_weather_metric_value($value, $unit, $measurement) {
	if (!is_numeric($value)) return $value;
	$value = floatval($value);
	$unit = pisky_weather_unit_key($unit);

	if ($measurement === "temperature") {
		return in_array($unit, array("f", "degreef", "fahrenheit"), true)
			? ($value - 32) * 5 / 9 : $value;
	}
	if ($measurement === "pressure") {
		if (in_array($unit, array("inhg", "incheshg"), true)) {
			return $value * 33.8638866667;
		}
		if ($unit === "kpa") return $value * 10;
		return $value;
	}
	if ($measurement === "wind_speed") {
		if (in_array($unit, array("mph", "mile/h", "miles/h"), true)) {
			return $value * 1.609344;
		}
		if (in_array($unit, array("m/s", "mps", "meter/s", "meters/s"), true)) {
			return $value * 3.6;
		}
		if (in_array($unit, array("kt", "kts", "knot", "knots"), true)) {
			return $value * 1.852;
		}
		return $value;
	}
	if ($measurement === "rain") {
		if (in_array($unit, array("in", "inch", "inches"), true)) {
			return $value * 25.4;
		}
		if ($unit === "cm") return $value * 10;
		return $value;
	}
	if ($measurement === "visibility") {
		if (in_array($unit, array("mi", "mile", "miles"), true)) {
			return $value * 1.609344;
		}
		if (in_array($unit, array("m", "meter", "meters"), true)) {
			return $value / 1000;
		}
		return $value;
	}
	return $value;
}

/*
 * Inverse of pisky_weather_metric_value: express a metric value in $unit.
 * Needed when a reading from one provider is merged into another provider's
 * record, because the record is later converted for display using its own
 * units. Without this an Open-Meteo value in °C would be read as °F on a
 * station configured in imperial units.
 */
function pisky_weather_from_metric($value, $unit, $measurement) {
	if (!is_numeric($value)) return $value;
	$value = floatval($value);
	$unit = pisky_weather_unit_key($unit);

	if ($measurement === "temperature") {
		return in_array($unit, array("f", "degreef", "fahrenheit"), true)
			? ($value * 9 / 5) + 32 : $value;
	}
	if ($measurement === "pressure") {
		if (in_array($unit, array("inhg", "incheshg"), true)) return $value / 33.8638866667;
		if ($unit === "kpa") return $value / 10;
		return $value;
	}
	if ($measurement === "wind_speed") {
		if (in_array($unit, array("mph", "mile/h", "miles/h"), true)) return $value / 1.609344;
		if (in_array($unit, array("m/s", "mps", "meter/s", "meters/s"), true)) return $value / 3.6;
		if (in_array($unit, array("kt", "kts", "knot", "knots"), true)) return $value / 1.852;
		return $value;
	}
	if ($measurement === "rain") {
		if (in_array($unit, array("in", "inch", "inches"), true)) return $value / 25.4;
		if ($unit === "cm") return $value / 10;
		return $value;
	}
	if ($measurement === "visibility") {
		if (in_array($unit, array("mi", "mile", "miles"), true)) return $value / 1.609344;
		if (in_array($unit, array("m", "meter", "meters"), true)) return $value * 1000;
		return $value;
	}
	return $value;
}

function pisky_weather_convert($value, $fromUnit, $toUnit, $measurement) {
	return pisky_weather_from_metric(
		pisky_weather_metric_value($value, $fromUnit, $measurement),
		$toUnit,
		$measurement
	);
}

function pisky_weather_display_value($value, $unit, $measurement, $displayUnits) {
	$metric = pisky_weather_metric_value($value, $unit, $measurement);
	if (!is_numeric($metric)) return $metric;
	$metric = floatval($metric);
	if ($displayUnits !== "imperial") return round($metric, 4);

	if ($measurement === "temperature") return round(($metric * 9 / 5) + 32, 4);
	if ($measurement === "pressure") return round($metric / 33.8638866667, 4);
	if ($measurement === "wind_speed") return round($metric / 1.609344, 4);
	if ($measurement === "rain") return round($metric / 25.4, 4);
	if ($measurement === "visibility") return round($metric / 1.609344, 4);
	return round($metric, 4);
}

function pisky_weather_apply_display_units($data, $displayUnits) {
	if (!is_array($data) || empty($data["ok"]) || !isset($data["current"])
		|| !is_array($data["current"])) return $data;
	$displayUnits = $displayUnits === "imperial" ? "imperial" : "metric";
	$sourceUnits = isset($data["units"]) && is_array($data["units"])
		? $data["units"] : array();
	$mapping = array(
		"temperature" => "temperature",
		"apparent_temperature" => "temperature",
		"dew_point" => "temperature",
		"pressure" => "pressure",
		"wind_speed" => "wind_speed",
		"wind_gust" => "wind_speed",
		"rain" => "rain",
		"visibility" => "visibility"
	);
	foreach ($mapping as $field => $measurement) {
		if (!array_key_exists($field, $data["current"])) continue;
		$sourceUnit = isset($sourceUnits[$measurement])
			? $sourceUnits[$measurement] : "";
		$data["current"][$field] = pisky_weather_display_value(
			$data["current"][$field],
			$sourceUnit,
			$measurement,
			$displayUnits
		);
	}
	if (isset($data["forecast"]) && is_array($data["forecast"])) {
		$forecastUnits = isset($data["_supplement_units"]) && is_array($data["_supplement_units"])
			? $data["_supplement_units"] : $sourceUnits;
		foreach ($data["forecast"] as $index => $forecast) {
			if (!is_array($forecast) || !array_key_exists("temperature", $forecast)) continue;
			$data["forecast"][$index]["temperature"] = pisky_weather_display_value(
				$forecast["temperature"],
				isset($forecastUnits["temperature"]) ? $forecastUnits["temperature"] : "",
				"temperature",
				$displayUnits
			);
		}
	}
	if (isset($data["daily"]) && is_array($data["daily"])) {
		$dailyUnits = isset($data["_supplement_units"]) && is_array($data["_supplement_units"])
			? $data["_supplement_units"] : $sourceUnits;
		foreach ($data["daily"] as $index => $day) {
			if (!is_array($day)) continue;
			foreach (array(
				"temperature_max" => "temperature",
				"temperature_min" => "temperature",
				"apparent_temperature_max" => "temperature",
				"apparent_temperature_min" => "temperature",
				"wind_speed_max" => "wind_speed",
				"wind_gust_max" => "wind_speed",
				"rain" => "rain"
			) as $field => $measurement) {
				if (!array_key_exists($field, $day)) continue;
				$sourceUnit = isset($dailyUnits[$measurement])
					? $dailyUnits[$measurement] : "";
				$data["daily"][$index][$field] = pisky_weather_display_value(
					$day[$field],
					$sourceUnit,
					$measurement,
					$displayUnits
				);
			}
		}
	}
	if (isset($data["observations"]) && is_array($data["observations"])) {
		$observationMeasurements = array(
			"rain_rate" => "rain",
			"rain_today" => "rain",
			"rain_storm" => "rain",
			"rain_month" => "rain",
			"rain_year" => "rain",
			"evapotranspiration" => "rain",
			"wind_chill" => "temperature",
			"heat_index" => "temperature",
			"indoor_temperature" => "temperature",
			"soil_temperature" => "temperature",
			"leaf_temperature" => "temperature",
			"temperature_max" => "temperature",
			"temperature_min" => "temperature",
			"thw_index" => "temperature",
			"thsw_index" => "temperature",
			"wind_speed_max" => "wind_speed",
			"pressure_msl" => "pressure",
			"pressure_trend" => "pressure",
			"lightning_distance" => "visibility"
		);
		foreach ($data["observations"] as $index => $observation) {
			if (!is_array($observation) || !isset($observation["id"])
				|| !isset($observationMeasurements[$observation["id"]])) continue;
			$measurement = $observationMeasurements[$observation["id"]];
			$sourceUnit = isset($observation["unit"]) ? $observation["unit"] : "";
			$data["observations"][$index]["value"] = pisky_weather_display_value(
				isset($observation["value"]) ? $observation["value"] : null,
				$sourceUnit,
				$measurement,
				$displayUnits
			);
			if ($measurement === "temperature") {
				$data["observations"][$index]["unit"] = $displayUnits === "imperial" ? "°F" : "°C";
			} else if ($measurement === "rain") {
				$depth = $displayUnits === "imperial" ? "in" : "mm";
				// Rates keep their per-hour suffix; totals do not.
				$data["observations"][$index]["unit"] = $observation["id"] === "rain_rate"
					? $depth . "/h" : $depth;
			} else if ($measurement === "visibility") {
				$data["observations"][$index]["unit"] = $displayUnits === "imperial" ? "mi" : "km";
			} else if ($measurement === "wind_speed") {
				$data["observations"][$index]["unit"] = $displayUnits === "imperial" ? "mph" : "km/h";
			} else if ($measurement === "pressure") {
				$data["observations"][$index]["unit"] = $displayUnits === "imperial" ? "inHg" : "hPa";
			}
		}
	}
	$data["display_units"] = $displayUnits;
	unset($data["_supplement_units"]);
	$data["units"] = $displayUnits === "imperial"
		? array(
			"temperature" => "°F",
			"humidity" => "%",
			"pressure" => "inHg",
			"wind_speed" => "mph",
			"rain" => "in",
			"cloud_cover" => "%",
			"visibility" => "mi"
		)
		: array(
			"temperature" => "°C",
			"humidity" => "%",
			"pressure" => "hPa",
			"wind_speed" => "km/h",
			"rain" => "mm",
			"cloud_cover" => "%",
			"visibility" => "km"
		);
	return $data;
}

function pisky_moon_phase($timestamp) {
	// Mean new moon: 2000-01-06 18:14 UTC; synodic month in mean solar days.
	$cycle = 29.53058867;
	$epoch = 947182440;
	$age = fmod(($timestamp - $epoch) / 86400, $cycle);
	if ($age < 0) $age += $cycle;
	$fraction = $age / $cycle;
	$illumination = (1 - cos(2 * M_PI * $fraction)) / 2;
	$labels = array(
		"New moon", "Waxing crescent", "First quarter", "Waxing gibbous",
		"Full moon", "Waning gibbous", "Last quarter", "Waning crescent"
	);
	$index = intval(floor(($fraction * 8) + 0.5)) % 8;
	return array(
		"phase" => $labels[$index],
		"age_days" => round($age, 1),
		"illumination" => round($illumination * 100),
		"waxing" => $fraction < 0.5
	);
}

/*
 * Several useful Open-Meteo measurements (UV, radiation, soil, freezing level)
 * exist only on the hourly endpoint. Pick the sample covering the current hour
 * so they can be presented and used to fill gaps in a local station's feed.
 */
/*
 * Turn an Open-Meteo timestamp into a real instant.
 *
 * Open-Meteo returns times already converted to the requested timezone but
 * written without any offset, for example "2026-07-29T22:15", and reports the
 * offset separately as utc_offset_seconds. Passing that string to strtotime
 * makes PHP read it in its own timezone, which on a Pi is usually UTC, so an
 * Australian station's reading landed ten or eleven hours late and reported
 * tomorrow's date. The offset has to be applied explicitly.
 */
function pisky_open_meteo_time($raw, $value) {
	if (!is_string($value) || trim($value) === "") return null;
	$offset = isset($raw["utc_offset_seconds"]) && is_numeric($raw["utc_offset_seconds"])
		? intval($raw["utc_offset_seconds"]) : 0;
	// Already carries a zone, so it can be trusted as written.
	if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($value))) {
		$parsed = strtotime($value);
		return $parsed === false ? null : $parsed;
	}
	$parsed = strtotime($value . " UTC");
	return $parsed === false ? null : $parsed - $offset;
}

function pisky_open_meteo_hourly_index($raw) {
	if (!isset($raw["hourly"]["time"]) || !is_array($raw["hourly"]["time"])) return null;
	$now = time();
	$best = null;
	$bestGap = null;
	foreach ($raw["hourly"]["time"] as $index => $time) {
		$timestamp = pisky_open_meteo_time($raw, $time);
		if ($timestamp === null) continue;
		$gap = abs($timestamp - $now);
		if ($bestGap === null || $gap < $bestGap) {
			$bestGap = $gap;
			$best = $index;
		}
	}
	// Beyond a couple of hours the sample no longer describes now.
	return $bestGap !== null && $bestGap <= 7200 ? $best : null;
}

function pisky_open_meteo_hourly_value($raw, $field, $index) {
	if ($index === null || !isset($raw["hourly"][$field][$index])) return null;
	$value = $raw["hourly"][$field][$index];
	return is_numeric($value) ? floatval($value) : null;
}

/*
 * Air quality lives on a separate Open-Meteo service. It is requested only to
 * fill fields a local station has not supplied, and a failure here must never
 * fail the weather read, so errors are swallowed.
 */
function pisky_open_meteo_air_quality($latitude, $longitude, $timezone) {
	$query = http_build_query(array(
		"latitude" => $latitude,
		"longitude" => $longitude,
		"current" => "pm10,pm2_5,carbon_monoxide,nitrogen_dioxide,ozone,"
			. "sulphur_dioxide,european_aqi,us_aqi",
		"timezone" => $timezone !== "" ? $timezone : "auto"
	));
	$error = "";
	$raw = pisky_http_json(
		"https://air-quality-api.open-meteo.com/v1/air-quality?" . $query, $error
	);
	return isset($raw["current"]) && is_array($raw["current"]) ? $raw["current"] : array();
}

/*
 * Build the labelled supplementary readings Open-Meteo can provide. These use
 * the same shape as the WeeWX observation list so the interface renders both
 * identically and so missing station readings can be filled from here.
 */
function pisky_open_meteo_observations($raw, $current, $index, $airQuality) {
	$definitions = array(
		// Open-Meteo reports precipitation accumulated over the preceding hour,
		// which is an intensity in mm/h. Stations report rain rate directly, so
		// exposing it under the same identifier lets either provider fill it.
		array("rain_rate", "Rain rate",
			isset($current["precipitation"]) ? $current["precipitation"] : null, "mm/h"),
		array("uv", "UV index", pisky_open_meteo_hourly_value($raw, "uv_index", $index), ""),
		array("solar_radiation", "Solar radiation",
			pisky_open_meteo_hourly_value($raw, "shortwave_radiation", $index), "W/m²"),
		array("evapotranspiration", "Evapotranspiration",
			pisky_open_meteo_hourly_value($raw, "et0_fao_evapotranspiration", $index), "mm"),
		array("soil_temperature", "Soil temperature",
			pisky_open_meteo_hourly_value($raw, "soil_temperature_6cm", $index), "°C"),
		array("soil_moisture", "Soil moisture",
			pisky_open_meteo_hourly_value($raw, "soil_moisture_3_9cm", $index), "m³/m³"),
		array("freezing_level", "Freezing level",
			pisky_open_meteo_hourly_value($raw, "freezing_level_height", $index), "m"),
		array("snowfall", "Snowfall",
			isset($current["snowfall"]) ? $current["snowfall"] : null, "cm"),
		array("showers", "Showers",
			isset($current["showers"]) ? $current["showers"] : null, "mm"),
		array("cloud_cover_low", "Low cloud",
			isset($current["cloud_cover_low"]) ? $current["cloud_cover_low"] : null, "%"),
		array("cloud_cover_mid", "Mid cloud",
			isset($current["cloud_cover_mid"]) ? $current["cloud_cover_mid"] : null, "%"),
		array("cloud_cover_high", "High cloud",
			isset($current["cloud_cover_high"]) ? $current["cloud_cover_high"] : null, "%"),
		array("pressure_msl", "Pressure at sea level",
			isset($current["pressure_msl"]) ? $current["pressure_msl"] : null, "hPa"),
		array("pm2_5", "PM2.5", isset($airQuality["pm2_5"]) ? $airQuality["pm2_5"] : null, "µg/m³"),
		array("pm10", "PM10", isset($airQuality["pm10"]) ? $airQuality["pm10"] : null, "µg/m³"),
		array("air_quality_index", "Air quality index",
			isset($airQuality["european_aqi"]) ? $airQuality["european_aqi"] : null, ""),
		array("ozone", "Ozone", isset($airQuality["ozone"]) ? $airQuality["ozone"] : null, "µg/m³"),
		array("nitrogen_dioxide", "Nitrogen dioxide",
			isset($airQuality["nitrogen_dioxide"]) ? $airQuality["nitrogen_dioxide"] : null, "µg/m³")
	);
	$observations = array();
	foreach ($definitions as $definition) {
		if ($definition[2] === null || $definition[2] === "") continue;
		$observations[] = array(
			"id" => $definition[0],
			"label" => $definition[1],
			"value" => $definition[2],
			"unit" => $definition[3]
		);
	}
	return $observations;
}

function pisky_open_meteo($config, $settings) {
	$latitude = pisky_decimal_coordinate($config["latitude"]);
	$longitude = pisky_decimal_coordinate($config["longitude"]);
	if ($latitude === null && isset($settings["latitude"])) $latitude = pisky_decimal_coordinate($settings["latitude"]);
	if ($longitude === null && isset($settings["longitude"])) $longitude = pisky_decimal_coordinate($settings["longitude"]);

	if ($latitude === null || $longitude === null) {
		return array("ok" => false, "error" => "Set latitude and longitude in Camera Settings to enable weather.");
	}
	if (abs($latitude) > 90 || abs($longitude) > 180) {
		return array("ok" => false, "error" => "Camera latitude or longitude is outside the valid range.");
	}

	$currentFields = implode(",", array(
		"temperature_2m", "relative_humidity_2m", "apparent_temperature",
		"dew_point_2m", "precipitation", "rain", "showers", "snowfall",
		"weather_code", "cloud_cover", "cloud_cover_low", "cloud_cover_mid",
		"cloud_cover_high", "surface_pressure", "pressure_msl",
		"wind_speed_10m", "wind_direction_10m", "wind_gusts_10m", "visibility",
		"is_day"
	));
	$hourlyFields = implode(",", array(
		"temperature_2m", "precipitation_probability", "cloud_cover",
		"weather_code", "uv_index", "shortwave_radiation",
		"et0_fao_evapotranspiration", "soil_temperature_6cm",
		"soil_moisture_3_9cm", "freezing_level_height", "visibility"
	));
	$dailyFields = implode(",", array(
		"weather_code", "temperature_2m_max", "temperature_2m_min",
		"apparent_temperature_max", "apparent_temperature_min",
		"sunrise", "sunset", "daylight_duration", "sunshine_duration",
		"uv_index_max", "precipitation_sum", "precipitation_probability_max",
		"wind_speed_10m_max", "wind_gusts_10m_max"
	));
	$query = http_build_query(array(
		"latitude" => $latitude,
		"longitude" => $longitude,
		"current" => $currentFields,
		"hourly" => $hourlyFields,
		"daily" => $dailyFields,
		"forecast_days" => 7,
		"timezone" => isset($config["timezone"]) ? $config["timezone"] : "auto"
	));
	$error = "";
	$raw = pisky_http_json("https://api.open-meteo.com/v1/forecast?" . $query, $error);
	if ($raw === null || !isset($raw["current"])) {
		return array("ok" => false, "error" => $error !== "" ? $error : "Open-Meteo data is unavailable.");
	}

	$current = $raw["current"];
	$hourlyIndex = pisky_open_meteo_hourly_index($raw);
	$visibility = isset($current["visibility"])
		? floatval($current["visibility"]) / 1000
		: (function () use ($raw, $hourlyIndex) {
			$metres = pisky_open_meteo_hourly_value($raw, "visibility", $hourlyIndex);
			return $metres === null ? null : $metres / 1000;
		})();
	$dewPoint = isset($current["dew_point_2m"])
		? $current["dew_point_2m"]
		: pisky_dew_point($current["temperature_2m"], $current["relative_humidity_2m"]);
	$airQuality = pisky_open_meteo_air_quality(
		$latitude, $longitude, isset($config["timezone"]) ? $config["timezone"] : "auto"
	);

	$forecast = array();
	if (isset($raw["hourly"]["time"]) && is_array($raw["hourly"]["time"])) {
		$now = time();
		foreach ($raw["hourly"]["time"] as $index => $time) {
			$timestamp = pisky_open_meteo_time($raw, $time);
			if ($timestamp === null || $timestamp < $now) continue;
			$forecast[] = array(
				"time" => date(DATE_ATOM, $timestamp),
				"temperature" => isset($raw["hourly"]["temperature_2m"][$index]) ? $raw["hourly"]["temperature_2m"][$index] : null,
				"precipitation_probability" => isset($raw["hourly"]["precipitation_probability"][$index]) ? $raw["hourly"]["precipitation_probability"][$index] : null,
				"cloud_cover" => isset($raw["hourly"]["cloud_cover"][$index]) ? $raw["hourly"]["cloud_cover"][$index] : null,
				"condition" => isset($raw["hourly"]["weather_code"][$index]) ? pisky_weather_description($raw["hourly"]["weather_code"][$index]) : null
			);
			if (count($forecast) >= 12) break;
		}
	}

	$daily = array();
	if (isset($raw["daily"]["time"]) && is_array($raw["daily"]["time"])) {
		foreach ($raw["daily"]["time"] as $index => $date) {
			$code = isset($raw["daily"]["weather_code"][$index])
				? $raw["daily"]["weather_code"][$index] : null;
			$daily[] = array(
				"date" => $date,
				"condition" => $code !== null ? pisky_weather_description($code) : "Forecast",
				"weather_code" => $code,
				"temperature_max" => isset($raw["daily"]["temperature_2m_max"][$index]) ? $raw["daily"]["temperature_2m_max"][$index] : null,
				"temperature_min" => isset($raw["daily"]["temperature_2m_min"][$index]) ? $raw["daily"]["temperature_2m_min"][$index] : null,
				"apparent_temperature_max" => isset($raw["daily"]["apparent_temperature_max"][$index]) ? $raw["daily"]["apparent_temperature_max"][$index] : null,
				"apparent_temperature_min" => isset($raw["daily"]["apparent_temperature_min"][$index]) ? $raw["daily"]["apparent_temperature_min"][$index] : null,
				"sunrise" => isset($raw["daily"]["sunrise"][$index]) ? $raw["daily"]["sunrise"][$index] : null,
				"sunset" => isset($raw["daily"]["sunset"][$index]) ? $raw["daily"]["sunset"][$index] : null,
				"daylight_seconds" => isset($raw["daily"]["daylight_duration"][$index]) ? $raw["daily"]["daylight_duration"][$index] : null,
				"sunshine_seconds" => isset($raw["daily"]["sunshine_duration"][$index]) ? $raw["daily"]["sunshine_duration"][$index] : null,
				"uv_index_max" => isset($raw["daily"]["uv_index_max"][$index]) ? $raw["daily"]["uv_index_max"][$index] : null,
				"rain" => isset($raw["daily"]["precipitation_sum"][$index]) ? $raw["daily"]["precipitation_sum"][$index] : null,
				"precipitation_probability" => isset($raw["daily"]["precipitation_probability_max"][$index]) ? $raw["daily"]["precipitation_probability_max"][$index] : null,
				"wind_speed_max" => isset($raw["daily"]["wind_speed_10m_max"][$index]) ? $raw["daily"]["wind_speed_10m_max"][$index] : null,
				"wind_gust_max" => isset($raw["daily"]["wind_gusts_10m_max"][$index]) ? $raw["daily"]["wind_gusts_10m_max"][$index] : null
			);
		}
	}
	$astronomyTimestamp = pisky_open_meteo_time($raw, isset($current["time"]) ? $current["time"] : "");
	if ($astronomyTimestamp === null) $astronomyTimestamp = time();
	$today = isset($daily[0]) ? $daily[0] : array();
	$astronomy = array_merge(
		array(
			"sunrise" => isset($today["sunrise"]) ? $today["sunrise"] : null,
			"sunset" => isset($today["sunset"]) ? $today["sunset"] : null,
			"daylight_seconds" => isset($today["daylight_seconds"]) ? $today["daylight_seconds"] : null
		),
		array("moon" => pisky_moon_phase($astronomyTimestamp))
	);

	return array(
		"ok" => true,
		"provider" => "open-meteo",
		"source" => "Open-Meteo",
		"source_url" => "https://open-meteo.com/",
		"attribution" => "Weather data by Open-Meteo.com · CC BY 4.0",
		"observed_at" => date(DATE_ATOM, $astronomyTimestamp),
		"fetched_at" => date(DATE_ATOM),
		"location" => array(
			"timezone" => isset($raw["timezone"]) ? $raw["timezone"] : "auto",
			"latitude" => $latitude,
			"longitude" => $longitude
		),
		"units" => array(
			"temperature" => "°C",
			"humidity" => "%",
			"pressure" => "hPa",
			"wind_speed" => isset($raw["current_units"]["wind_speed_10m"]) ? $raw["current_units"]["wind_speed_10m"] : "km/h",
			"rain" => "mm",
			"cloud_cover" => "%",
			"visibility" => "km"
		),
		"current" => array(
			"temperature" => isset($current["temperature_2m"]) ? $current["temperature_2m"] : null,
			"apparent_temperature" => isset($current["apparent_temperature"]) ? $current["apparent_temperature"] : null,
			"dew_point" => $dewPoint,
			"humidity" => isset($current["relative_humidity_2m"]) ? $current["relative_humidity_2m"] : null,
			"pressure" => isset($current["surface_pressure"]) ? $current["surface_pressure"] : null,
			"wind_speed" => isset($current["wind_speed_10m"]) ? $current["wind_speed_10m"] : null,
			"wind_gust" => isset($current["wind_gusts_10m"]) ? $current["wind_gusts_10m"] : null,
			"wind_direction" => isset($current["wind_direction_10m"]) ? $current["wind_direction_10m"] : null,
			"rain" => isset($current["rain"]) ? $current["rain"] : (isset($current["precipitation"]) ? $current["precipitation"] : null),
			"cloud_cover" => isset($current["cloud_cover"]) ? $current["cloud_cover"] : null,
			"visibility" => $visibility,
			"weather_code" => isset($current["weather_code"]) ? $current["weather_code"] : null,
			"is_day" => isset($current["is_day"]) ? intval($current["is_day"]) === 1 : null,
			"condition" => pisky_weather_description(isset($current["weather_code"]) ? $current["weather_code"] : -1)
		),
		"forecast" => $forecast,
		"daily" => $daily,
		"astronomy" => $astronomy,
		"observations" => pisky_open_meteo_observations($raw, $current, $hourlyIndex, $airQuality)
	);
}

function pisky_scalar($value) {
	if (is_array($value)) {
		if (isset($value["value"])) return $value["value"];
		if (isset($value[0])) return $value[0];
		return null;
	}
	return $value;
}

function pisky_first_value($data, $keys, $default) {
	foreach ($keys as $key) {
		if (isset($data[$key])) return pisky_scalar($data[$key]);
	}
	return $default;
}

function pisky_weewx_observations($current, $units) {
	$definitions = array(
		array("uv", "UV index", array("uv", "UV"), ""),
		array("solar_radiation", "Solar radiation", array("solar_radiation", "radiation"), "W/m²"),
		array("rain_rate", "Rain rate", array("rain_rate", "rainRate"), isset($units["rain_rate"]) ? $units["rain_rate"] : ""),
		array("rain_today", "Rain today", array("rain_today", "dayRain", "rainTotal"), isset($units["rain"]) ? $units["rain"] : ""),
		array("wind_chill", "Wind chill", array("wind_chill", "windchill"), isset($units["temperature"]) ? $units["temperature"] : ""),
		array("heat_index", "Heat index", array("heat_index", "heatindex"), isset($units["temperature"]) ? $units["temperature"] : ""),
		array("indoor_temperature", "Indoor temperature", array("indoor_temperature", "inTemp"), isset($units["temperature"]) ? $units["temperature"] : ""),
		array("indoor_humidity", "Indoor humidity", array("indoor_humidity", "inHumidity"), "%"),
		array("lightning_distance", "Lightning distance", array("lightning_distance", "lightning_distance_km"), "km"),
		array("lightning_count", "Lightning strikes", array("lightning_count", "lightning_strike_count"), ""),
		array("soil_moisture", "Soil moisture", array("soil_moisture", "soilMoist1"), "%"),
		array("soil_temperature", "Soil temperature", array("soil_temperature", "soilTemp1"), isset($units["temperature"]) ? $units["temperature"] : ""),
		array("leaf_wetness", "Leaf wetness", array("leaf_wetness", "leafWet1"), ""),
		array("leaf_temperature", "Leaf temperature", array("leaf_temperature", "leafTemp1"), isset($units["temperature"]) ? $units["temperature"] : ""),
		array("evapotranspiration", "Evapotranspiration", array("evapotranspiration", "ET"), isset($units["rain"]) ? $units["rain"] : ""),
		array("rain_storm", "Storm rain", array("rain_storm", "stormRain"), isset($units["rain"]) ? $units["rain"] : ""),
		array("rain_month", "Rain this month", array("rain_month", "monthRain"), isset($units["rain"]) ? $units["rain"] : ""),
		array("rain_year", "Rain this year", array("rain_year", "yearRain"), isset($units["rain"]) ? $units["rain"] : ""),
		array("temperature_max", "Today's high", array("temperature_max", "outTempMax", "dayTempMax"), isset($units["temperature"]) ? $units["temperature"] : ""),
		array("temperature_min", "Today's low", array("temperature_min", "outTempMin", "dayTempMin"), isset($units["temperature"]) ? $units["temperature"] : ""),
		array("wind_speed_max", "Peak gust today", array("wind_speed_max", "windGustMax", "dayWindGustMax"), isset($units["wind_speed"]) ? $units["wind_speed"] : ""),
		array("pressure_trend", "Pressure trend", array("pressure_trend", "barometerRate", "trend"), isset($units["pressure"]) ? $units["pressure"] : ""),
		array("cloud_base", "Cloud base", array("cloud_base", "cloudbase"), ""),
		array("thw_index", "THW index", array("thw_index", "thwIndex"), isset($units["temperature"]) ? $units["temperature"] : ""),
		array("thsw_index", "THSW index", array("thsw_index", "thswIndex"), isset($units["temperature"]) ? $units["temperature"] : ""),
		array("air_density", "Air density", array("air_density", "airDensity"), "kg/m³"),
		array("snow_depth", "Snow depth", array("snow_depth", "snowDepth"), ""),
		array("co2", "CO₂", array("co2", "CO2"), "ppm"),
		array("voc", "VOC index", array("voc", "vocIndex"), ""),
		array("pm2_5", "PM2.5", array("pm2_5", "pm2_5_atm", "pm2_5_aqi"), "µg/m³"),
		array("pm10", "PM10", array("pm10", "pm10_0_atm"), "µg/m³"),
		array("air_quality_index", "Air quality index", array("air_quality_index", "aqi"), ""),
		array("battery_status", "Sensor battery", array("battery_status", "txBatteryStatus", "consBatteryVoltage"), ""),
		array("signal_quality", "Sensor signal", array("signal_quality", "rxCheckPercent"), "%")
	);
	$observations = array();
	foreach ($definitions as $definition) {
		$value = pisky_first_value($current, $definition[2], null);
		if ($value === null || $value === "") continue;
		$observations[] = array(
			"id" => $definition[0],
			"label" => $definition[1],
			"value" => $value,
			"unit" => $definition[3]
		);
	}
	return $observations;
}

function pisky_weewx($config) {
	$raw = null;
	$error = "";
	$url = isset($config["url"]) ? trim($config["url"]) : "";
	$file = isset($config["file"]) ? trim($config["file"]) : "";

	if ($url !== "") {
		$raw = pisky_http_json($url, $error);
	} else if ($file !== "") {
		if (!pisky_local_json_path_allowed($file, "weather") || !is_readable($file)) {
			$error = "WeeWX JSON file is outside PiSky's allowed weather folders or is not readable.";
		} else {
			$raw = json_decode(file_get_contents($file), true);
			if (!is_array($raw)) $error = "WeeWX JSON file contains invalid JSON.";
		}
	} else {
		$error = "Configure a WeeWX JSON file or URL.";
	}

	if (!is_array($raw)) return array("ok" => false, "error" => $error);
	$current = isset($raw["current"]) && is_array($raw["current"]) ? $raw["current"] : $raw;
	$units = isset($raw["units"]) && is_array($raw["units"]) ? $raw["units"] : array();

	$temperature = pisky_first_value($current, array("temperature", "outTemp", "temp"), null);
	$humidity = pisky_first_value($current, array("humidity", "outHumidity"), null);
	$weatherCode = pisky_first_value($current, array("weather_code", "weatherCode"), null);
	$condition = pisky_first_value($current, array("condition", "weather"), null);
	if ($condition === null && $weatherCode !== null) $condition = pisky_weather_description($weatherCode);

	$observed = pisky_first_value($raw, array("observed_at", "dateTime", "timestamp"), time());
	if (is_numeric($observed)) $observed = date(DATE_ATOM, intval($observed));
	else if (strtotime($observed) !== false) $observed = date(DATE_ATOM, strtotime($observed));
	else $observed = date(DATE_ATOM);

	return array(
		"ok" => true,
		"provider" => "weewx",
		"source" => isset($raw["station_name"]) ? $raw["station_name"] : "Local WeeWX station",
		"source_url" => "https://weewx.com/",
		"attribution" => "Live observations from the local WeeWX station",
		"observed_at" => $observed,
		"fetched_at" => date(DATE_ATOM),
		"location" => isset($raw["location"]) ? $raw["location"] : null,
		"units" => array(
			"temperature" => isset($units["temperature"]) ? $units["temperature"] : "°C",
			"humidity" => isset($units["humidity"]) ? $units["humidity"] : "%",
			"pressure" => isset($units["pressure"]) ? $units["pressure"] : "hPa",
			"wind_speed" => isset($units["wind_speed"]) ? $units["wind_speed"] : "km/h",
			"rain" => isset($units["rain"]) ? $units["rain"] : "mm",
			"cloud_cover" => isset($units["cloud_cover"]) ? $units["cloud_cover"] : "%",
			"visibility" => isset($units["visibility"]) ? $units["visibility"] : "km"
		),
		"current" => array(
			"temperature" => $temperature,
			"apparent_temperature" => pisky_first_value($current, array("apparent_temperature", "appTemp", "heatindex", "windchill"), null),
			"dew_point" => pisky_first_value($current, array("dew_point", "dewpoint"), pisky_dew_point($temperature, $humidity)),
			"humidity" => $humidity,
			"pressure" => pisky_first_value($current, array("pressure", "barometer"), null),
			"wind_speed" => pisky_first_value($current, array("wind_speed", "windSpeed"), null),
			"wind_gust" => pisky_first_value($current, array("wind_gust", "windGust"), null),
			"wind_direction" => pisky_first_value($current, array("wind_direction", "windDir"), null),
			// Accumulation only. rainRate is a different quantity in different
			// units, and falling back to it here reported an intensity under a
			// label meaning "how much has fallen". It is surfaced separately as
			// the rain_rate observation.
			"rain" => pisky_first_value($current, array("rain", "dayRain", "rainTotal"), null),
			"cloud_cover" => pisky_first_value($current, array("cloud_cover", "cloudCover"), null),
			"visibility" => pisky_first_value($current, array("visibility"), null),
			"weather_code" => $weatherCode,
			"condition" => $condition !== null ? $condition : "Local station observation"
		),
		"forecast" => isset($raw["forecast"]) && is_array($raw["forecast"]) ? $raw["forecast"] : array(),
		"daily" => isset($raw["daily"]) && is_array($raw["daily"]) ? $raw["daily"] : array(),
		"astronomy" => isset($raw["astronomy"]) && is_array($raw["astronomy"]) ? $raw["astronomy"] : array(),
		"observations" => pisky_weewx_observations($current, $units)
	);
}

/*
 * Fields a weather station physically cannot measure. A WeeWX station reports
 * temperature, wind and rain, but almost never cloud cover, visibility or a
 * weather code, and only some report UV or air quality. Those gaps are filled
 * from Open-Meteo so the interface is complete, while every reading the station
 * did supply is left untouched. Filled fields are listed in supplemented_fields
 * so the interface can attribute them honestly.
 */
function pisky_weather_supplement_local($local, $forecast) {
	if (empty($local["ok"]) || empty($forecast["ok"])) return $local;
	foreach (array("forecast", "daily", "astronomy") as $field) {
		if (!isset($local[$field]) || !is_array($local[$field]) || count($local[$field]) === 0) {
			$local[$field] = isset($forecast[$field]) ? $forecast[$field] : array();
		}
	}
	if ((!isset($local["location"]) || !is_array($local["location"]))
		&& isset($forecast["location"])) {
		$local["location"] = $forecast["location"];
	}

	$supplemented = array();
	$localUnits = isset($local["units"]) && is_array($local["units"]) ? $local["units"] : array();
	$forecastUnits = isset($forecast["units"]) && is_array($forecast["units"])
		? $forecast["units"] : array();
	// Measurement per fillable field; null means the value carries no unit.
	$fillable = array(
		"cloud_cover" => null,
		"visibility" => "visibility",
		"weather_code" => null,
		"condition" => null,
		"is_day" => null,
		"apparent_temperature" => "temperature",
		"dew_point" => "temperature",
		"pressure" => "pressure",
		"wind_gust" => "wind_speed"
	);
	foreach ($fillable as $field => $measurement) {
		$missing = !isset($local["current"][$field])
			|| $local["current"][$field] === null
			|| $local["current"][$field] === ""
			|| ($field === "condition" && $local["current"][$field] === "Local station observation");
		if (!$missing || !isset($forecast["current"][$field])
			|| $forecast["current"][$field] === null) continue;
		$value = $forecast["current"][$field];
		if ($measurement !== null) {
			// Express the borrowed reading in the station's own units, because
			// the merged record is converted for display using those units.
			$value = pisky_weather_convert(
				$value,
				isset($forecastUnits[$measurement]) ? $forecastUnits[$measurement] : "",
				isset($localUnits[$measurement]) ? $localUnits[$measurement] : "",
				$measurement
			);
		}
		$local["current"][$field] = $value;
		$supplemented[] = $field;
	}

	// Merge supplementary readings, keeping the station's own measurement
	// whenever it published one for the same quantity.
	$existing = array();
	if (isset($local["observations"]) && is_array($local["observations"])) {
		foreach ($local["observations"] as $observation) {
			if (isset($observation["id"])) $existing[$observation["id"]] = true;
		}
	} else {
		$local["observations"] = array();
	}
	if (isset($forecast["observations"]) && is_array($forecast["observations"])) {
		foreach ($forecast["observations"] as $observation) {
			if (!isset($observation["id"]) || isset($existing[$observation["id"]])) continue;
			$observation["supplemented"] = true;
			$local["observations"][] = $observation;
			$supplemented[] = $observation["id"];
		}
	}
	$local["supplemented_fields"] = $supplemented;
	$local["forecast_source"] = "Open-Meteo";
	$local["forecast_source_url"] = "https://open-meteo.com/";
	$local["_supplement_units"] = isset($forecast["units"]) ? $forecast["units"] : array();
	$local["attribution"] = "Live observations from the local WeeWX station; forecast by Open-Meteo.com (CC BY 4.0)";
	return $local;
}

function pisky_get_weather($settings) {
	$config = pisky_weather_config();
	if (empty($config["enabled"])) return array("ok" => false, "error" => "Weather integration is disabled.");

	$provider = strtolower(trim(isset($config["provider"]) ? $config["provider"] : "open-meteo"));
	if ($provider !== "weewx") $provider = "open-meteo";
	$displayUnits = isset($config["display_units"])
		&& strtolower(trim(strval($config["display_units"]))) === "imperial"
		? "imperial" : "metric";
	$providerConfig = isset($config[$provider === "weewx" ? "weewx" : "open_meteo"])
		? $config[$provider === "weewx" ? "weewx" : "open_meteo"]
		: array();
	$maxAge = isset($providerConfig["cache_seconds"]) ? max(5, intval($providerConfig["cache_seconds"])) : 60;
	$cacheKey = $provider . "-" . $displayUnits;

	$cached = pisky_read_cache($cacheKey, $maxAge, false);
	if ($cached !== null) return $cached;

	if ($provider === "weewx") {
		$data = pisky_weewx($providerConfig);
		if (!empty($data["ok"])) {
			$forecastConfig = isset($config["open_meteo"]) && is_array($config["open_meteo"])
				? $config["open_meteo"] : array();
			$data = pisky_weather_supplement_local(
				$data,
				pisky_open_meteo($forecastConfig, $settings)
			);
		}
	} else {
		$data = pisky_open_meteo($providerConfig, $settings);
	}

	if (!empty($data["ok"])) {
		$data = pisky_weather_apply_display_units($data, $displayUnits);
		pisky_write_cache($cacheKey, $data);
		return $data;
	}

	$stale = pisky_read_cache($cacheKey, $maxAge, true);
	if ($stale !== null) {
		$stale["stale"] = true;
		$stale["warning"] = isset($data["error"]) ? $data["error"] : "Using cached weather data.";
		return $stale;
	}
	return $data;
}
?>
