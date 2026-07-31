<?php
/*
 * PiSky local ADS-B provider bridge
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 *
 * Reads the local JSON output produced by dump1090-fa, readsb, or a compatible
 * decoder. FlightAware and Flightradar24 are optional sharing destinations;
 * their hosted flight data is not accessed by this adapter.
 */

include_once(__DIR__ . "/piskyWeather.php");

function pisky_flights_defaults() {
	return array(
		"enabled" => true,
		"decoder" => "Local ADS-B receiver",
		"aircraft_file" => "/run/dump1090-mutability/aircraft.json",
		"receiver_file" => "",
		"aircraft_url" => "",
		"latitude" => "",
		"longitude" => "",
		"range_km" => 160,
		"max_aircraft" => 60,
		"max_seen_seconds" => 15,
		"receiver" => array(
			"type" => "rtl-sdr",
			"rtl_sdr" => array("device" => "0", "gain" => "max", "ppm" => 0),
			"beast" => array(
				"serial_device" => "/dev/beast",
				"baud" => "auto",
				"output_format" => "beast-classic"
			)
		),
		"decoder_options" => array(
			"fix_crc" => true,
			"max_range_nm" => 300,
			"json_interval_seconds" => 1,
			"location_accuracy" => "none"
		),
		"network" => array(
			"bind_address" => "127.0.0.1",
			"raw_input_port" => 30001,
			"raw_output_port" => 30002,
			"sbs_output_port" => 30003,
			"beast_input_port" => 30004,
			"beast_output_port" => 30005
		),
		"coverage_map" => array(
			"enabled" => true,
			"zoom" => 8,
			"public" => false,
			"latitude" => "",
			"longitude" => ""
		),
		"sharing" => array(
			"flightaware" => array("enabled" => false, "site_id" => ""),
			"flightradar24" => array("enabled" => false, "radar_id" => "")
		)
	);
}

function pisky_flights_config_path() {
	$override = getenv("PISKY_FLIGHTS_CONFIG");
	if ($override !== false && trim($override) !== "") return $override;
	if (defined("ALLSKY_CONFIG")) return ALLSKY_CONFIG . "/pisky-flights.json";
	return dirname(__DIR__, 2) . "/config/pisky-flights.json";
}

function pisky_flights_config() {
	$config = pisky_flights_defaults();
	$path = pisky_flights_config_path();
	if (is_readable($path)) {
		$decoded = json_decode(file_get_contents($path), true);
		if (is_array($decoded)) $config = pisky_merge_config($config, $decoded);
	}
	return $config;
}

function pisky_flights_bool($value) {
	if (is_bool($value)) return $value;
	return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function pisky_flights_read_json_file($paths, &$usedPath) {
	$usedPath = "";
	foreach (array_unique($paths) as $path) {
		$path = trim(strval($path));
		if ($path === "" || !pisky_local_json_path_allowed($path, "flights")
			|| !is_readable($path)) continue;
		$decoded = json_decode(file_get_contents($path), true);
		if (is_array($decoded)) {
			$usedPath = $path;
			return $decoded;
		}
	}
	return null;
}

function pisky_flights_source($config, &$error, &$usedPath) {
	$error = "";
	$usedPath = "";
	$url = isset($config["aircraft_url"]) ? trim($config["aircraft_url"]) : "";
	if ($url !== "") {
		$data = pisky_http_json($url, $error);
		if ($data === null && $error === "") $error = "The ADS-B URL returned invalid JSON.";
		return $data;
	}

	$configured = isset($config["aircraft_file"]) ? $config["aircraft_file"] : "";
	$paths = array(
		$configured,
		"/run/dump1090-mutability/aircraft.json",
		"/var/run/dump1090-mutability/aircraft.json",
		"/run/dump1090-fa/aircraft.json",
		"/var/run/dump1090-fa/aircraft.json",
		"/run/readsb/aircraft.json",
		"/var/run/readsb/aircraft.json",
		"/run/dump1090/aircraft.json",
		"/var/run/dump1090/aircraft.json"
	);
	$data = pisky_flights_read_json_file($paths, $usedPath);
	if ($data === null) {
		$error = "No readable local aircraft.json was found. Install or configure dump1090-mutability, dump1090-fa, or readsb.";
	}
	return $data;
}

function pisky_flights_receiver($config, $aircraftPath) {
	$configured = isset($config["receiver_file"]) ? $config["receiver_file"] : "";
	$besideAircraft = $aircraftPath !== "" ? dirname($aircraftPath) . "/receiver.json" : "";
	$used = "";
	return pisky_flights_read_json_file(array(
		$configured,
		$besideAircraft,
		"/run/dump1090-mutability/receiver.json",
		"/var/run/dump1090-mutability/receiver.json",
		"/run/dump1090-fa/receiver.json",
		"/var/run/dump1090-fa/receiver.json",
		"/run/readsb/receiver.json",
		"/var/run/readsb/receiver.json",
		"/run/beast-splitter/status.json"
	), $used);
}

function pisky_flights_distance_bearing($fromLat, $fromLon, $toLat, $toLon) {
	$earthRadiusKm = 6371.0088;
	$lat1 = deg2rad($fromLat);
	$lat2 = deg2rad($toLat);
	$deltaLat = deg2rad($toLat - $fromLat);
	$deltaLon = deg2rad($toLon - $fromLon);
	$a = sin($deltaLat / 2) * sin($deltaLat / 2)
		+ cos($lat1) * cos($lat2) * sin($deltaLon / 2) * sin($deltaLon / 2);
	$distance = $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));

	$y = sin($deltaLon) * cos($lat2);
	$x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($deltaLon);
	$bearing = fmod(rad2deg(atan2($y, $x)) + 360, 360);
	return array(round($distance, 1), round($bearing, 1));
}

function pisky_flights_numeric($value) {
	return is_numeric($value) ? floatval($value) : null;
}

function pisky_flights_value($aircraft, $keys, $default) {
	foreach ($keys as $key) {
		if (isset($aircraft[$key])) return $aircraft[$key];
	}
	return $default;
}

/*
 * Normalise the decoder-specific field names for one aircraft.
 *
 * dump1090-fa and readsb publish alt_baro / gs / baro_rate. Debian's
 * dump1090-mutability, which install-pisky.sh installs, publishes
 * altitude / speed / vert_rate. Both dialects are accepted, most specific
 * name first. Altitude is deliberately not forced to a number because
 * aircraft on the ground report the string "ground".
 */
function pisky_flights_decode_fields($aircraft) {
	$altitude = pisky_flights_value(
		$aircraft, array("alt_baro", "alt_geom", "altitude"), null
	);
	if (is_numeric($altitude)) $altitude = round(floatval($altitude));
	return array(
		"altitude_ft" => $altitude,
		"speed_knots" => pisky_flights_numeric(pisky_flights_value(
			$aircraft, array("gs", "tas", "ias", "speed"), null
		)),
		"track" => pisky_flights_numeric(pisky_flights_value(
			$aircraft, array("track", "true_heading", "mag_heading"), null
		)),
		"vertical_rate" => pisky_flights_numeric(pisky_flights_value(
			$aircraft, array("baro_rate", "geom_rate", "vert_rate"), null
		))
	);
}

function pisky_flights_is_emergency($aircraft) {
	$emergency = strtolower(trim(strval(pisky_flights_value($aircraft, array("emergency"), "none"))));
	$squawk = trim(strval(pisky_flights_value($aircraft, array("squawk"), "")));
	return ($emergency !== "" && $emergency !== "none") || in_array($squawk, array("7500", "7600", "7700"));
}

function pisky_get_flights($settings) {
	$config = pisky_flights_config();
	if (!pisky_flights_bool($config["enabled"])) {
		return array("ok" => false, "error" => "Local flight tracking is disabled.");
	}

	$error = "";
	$sourcePath = "";
	$raw = pisky_flights_source($config, $error, $sourcePath);
	if (!is_array($raw) || !isset($raw["aircraft"]) || !is_array($raw["aircraft"])) {
		return array("ok" => false, "error" => $error !== "" ? $error : "ADS-B aircraft data is unavailable.");
	}

	$receiverData = pisky_flights_receiver($config, $sourcePath);
	$coverageMap = isset($config["coverage_map"]) && is_array($config["coverage_map"])
		? $config["coverage_map"] : array();
	$latitude = pisky_decimal_coordinate(
		isset($coverageMap["latitude"]) ? $coverageMap["latitude"] : ""
	);
	$longitude = pisky_decimal_coordinate(
		isset($coverageMap["longitude"]) ? $coverageMap["longitude"] : ""
	);
	if ($latitude === null) {
		$latitude = pisky_decimal_coordinate(isset($config["latitude"]) ? $config["latitude"] : "");
	}
	if ($longitude === null) {
		$longitude = pisky_decimal_coordinate(isset($config["longitude"]) ? $config["longitude"] : "");
	}
	if ($latitude === null && is_array($receiverData) && isset($receiverData["lat"])) {
		$latitude = pisky_decimal_coordinate($receiverData["lat"]);
	}
	if ($longitude === null && is_array($receiverData) && isset($receiverData["lon"])) {
		$longitude = pisky_decimal_coordinate($receiverData["lon"]);
	}
	if ($latitude === null && isset($settings["latitude"])) {
		$latitude = pisky_decimal_coordinate($settings["latitude"]);
	}
	if ($longitude === null && isset($settings["longitude"])) {
		$longitude = pisky_decimal_coordinate($settings["longitude"]);
	}

	$mapEnabled = !empty($coverageMap["enabled"]) && $latitude !== null && $longitude !== null;
	$rangeKm = max(10, min(600, floatval($config["range_km"])));
	$maxAircraft = max(1, min(250, intval($config["max_aircraft"])));
	$maxSeen = max(2, min(120, floatval($config["max_seen_seconds"])));
	$aircraft = array();
	$activeCount = 0;
	$positionedCount = 0;
	$emergencyCount = 0;

	foreach ($raw["aircraft"] as $item) {
		if (!is_array($item)) continue;
		$seen = pisky_flights_numeric(pisky_flights_value($item, array("seen"), 0));
		if ($seen !== null && $seen > $maxSeen) continue;
		$activeCount++;

		$aircraftLat = pisky_flights_numeric(pisky_flights_value($item, array("lat"), null));
		$aircraftLon = pisky_flights_numeric(pisky_flights_value($item, array("lon"), null));
		$distance = null;
		$bearing = null;
		if ($latitude !== null && $longitude !== null && $aircraftLat !== null && $aircraftLon !== null) {
			list($distance, $bearing) = pisky_flights_distance_bearing($latitude, $longitude, $aircraftLat, $aircraftLon);
			if ($distance > $rangeKm) continue;
			$positionedCount++;
		}

		$emergency = pisky_flights_is_emergency($item);
		if ($emergency) $emergencyCount++;
		$decoded = pisky_flights_decode_fields($item);

		$callsign = trim(strval(pisky_flights_value($item, array("flight", "callsign"), "")));
		$hex = strtoupper(trim(strval(pisky_flights_value($item, array("hex"), ""))));
		$lookupIdentity = $callsign !== "" ? preg_replace("/[^A-Za-z0-9]/", "", $callsign) : "";
		$aircraft[] = array(
			"hex" => $hex,
			"callsign" => $callsign,
			"registration" => trim(strval(pisky_flights_value($item, array("r", "registration", "reg"), ""))),
			"aircraft_type" => trim(strval(pisky_flights_value($item, array("t", "type", "icao_type"), ""))),
			"description" => trim(strval(pisky_flights_value($item, array("desc", "type_description"), ""))),
			"operator" => trim(strval(pisky_flights_value($item, array("ownOp", "operator"), ""))),
			"origin" => trim(strval(pisky_flights_value($item, array("origin", "origin_icao"), ""))),
			"destination" => trim(strval(pisky_flights_value($item, array("destination", "destination_icao"), ""))),
			"altitude_ft" => $decoded["altitude_ft"],
			"speed_knots" => $decoded["speed_knots"],
			"track" => $decoded["track"],
			"vertical_rate" => $decoded["vertical_rate"],
			"latitude" => $aircraftLat,
			"longitude" => $aircraftLon,
			"distance_km" => $distance,
			"bearing" => $bearing,
			"squawk" => trim(strval(pisky_flights_value($item, array("squawk"), ""))),
			"emergency" => $emergency,
			"category" => pisky_flights_value($item, array("category"), null),
			"seen_seconds" => $seen,
			"messages" => intval(pisky_flights_value($item, array("messages"), 0)),
			"rssi" => pisky_flights_numeric(pisky_flights_value($item, array("rssi"), null)),
			"lookup" => array(
				"flightaware" => $lookupIdentity !== ""
					? "https://www.flightaware.com/live/flight/" . rawurlencode($lookupIdentity) : "",
				"flightradar24" => $lookupIdentity !== ""
					? "https://www.flightradar24.com/data/flights/" . rawurlencode(strtolower($lookupIdentity)) : ""
			)
		);
	}

	usort($aircraft, function ($a, $b) {
		if ($a["distance_km"] !== null && $b["distance_km"] !== null) {
			if ($a["distance_km"] == $b["distance_km"]) return 0;
			return $a["distance_km"] < $b["distance_km"] ? -1 : 1;
		}
		if ($a["distance_km"] !== null) return -1;
		if ($b["distance_km"] !== null) return 1;
		if ($a["seen_seconds"] == $b["seen_seconds"]) return 0;
		return $a["seen_seconds"] < $b["seen_seconds"] ? -1 : 1;
	});
	if (count($aircraft) > $maxAircraft) $aircraft = array_slice($aircraft, 0, $maxAircraft);

	$nearest = null;
	foreach ($aircraft as $item) {
		if ($item["distance_km"] !== null) {
			$nearest = $item["distance_km"];
			break;
		}
	}

	$generated = isset($raw["now"]) && is_numeric($raw["now"])
		? intval($raw["now"])
		: ($sourcePath !== "" && file_exists($sourcePath) ? filemtime($sourcePath) : time());
	$stale = (time() - $generated) > max(20, intval($maxSeen) + 5);
	$sharingConfig = isset($config["sharing"]) && is_array($config["sharing"]) ? $config["sharing"] : array();
	$fa = isset($sharingConfig["flightaware"]) ? $sharingConfig["flightaware"] : array();
	$fr = isset($sharingConfig["flightradar24"]) ? $sharingConfig["flightradar24"] : array();

	return array(
		"ok" => true,
		"stale" => $stale,
		"decoder" => trim(strval($config["decoder"])) !== "" ? trim(strval($config["decoder"])) : "Local ADS-B receiver",
		"source" => $sourcePath !== "" ? basename($sourcePath) : "Configured ADS-B endpoint",
		"observed_at" => date(DATE_ATOM, $generated),
		// The station position is published so the public radar can place targets
		// over a basemap. Hosts who would rather not disclose it turn the
		// coverage map off in PiSky Setup, which also removes it from here.
		// Zoom 0 means the radar should choose a level that fits the range.
		"receiver" => array(
			"range_km" => $rangeKm,
			"latitude" => $mapEnabled ? $latitude : null,
			"longitude" => $mapEnabled ? $longitude : null,
			"map_enabled" => $mapEnabled,
			"zoom" => isset($coverageMap["zoom"]) && is_numeric($coverageMap["zoom"])
				? max(0, min(16, intval($coverageMap["zoom"]))) : 0
		),
		"stats" => array(
			"aircraft_count" => $activeCount,
			"displayed_count" => count($aircraft),
			"positioned_count" => $positionedCount,
			"emergency_count" => $emergencyCount,
			"messages" => isset($raw["messages"]) ? intval($raw["messages"]) : null,
			"nearest_km" => $nearest
		),
		"sharing" => array(
			"flightaware" => array(
				"enabled" => pisky_flights_bool(isset($fa["enabled"]) ? $fa["enabled"] : false),
				"url" => "https://www.flightaware.com/adsb/stats"
			),
			"flightradar24" => array(
				"enabled" => pisky_flights_bool(isset($fr["enabled"]) ? $fr["enabled"] : false),
				"url" => "https://www.flightradar24.com/account/data-sharing"
			)
		),
		"aircraft" => $aircraft
	);
}
?>
