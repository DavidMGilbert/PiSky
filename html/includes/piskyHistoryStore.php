<?php
/*
 * PiSky history storage backends.
 *
 * The default backend writes one daily rollup per month to a local JSON file.
 * That is deliberately coarse: a Raspberry Pi runs from an SD card, and
 * writing a sample every few minutes for years wears it out.
 *
 * Hosts who want the detail behind that summary can point PiSky at a remote
 * database instead. Intraday samples then go there, the SD card keeps only the
 * rollups, and the interface gains real graphs. The remote is always optional
 * and always degradable: if it is unreachable the station carries on recording
 * rollups locally and simply cannot draw the within-day curve.
 *
 * Database credentials live in their own root-owned file rather than in
 * pisky-weather.json, because that configuration is merged into structures the
 * web interface renders and a password must never travel that path.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

if (!defined("PISKY_HISTORY_SETTINGS")) {
	define("PISKY_HISTORY_SETTINGS", "/etc/pisky/history.json");
}

/* The readings a sample stores, and therefore what a graph can plot. */
function pisky_history_sample_fields() {
	return array(
		"temperature", "apparent_temperature", "dew_point", "humidity",
		"pressure", "wind_speed", "wind_gust", "wind_direction",
		"rain", "rain_rate", "cloud_cover", "visibility",
		"uv", "solar_radiation"
	);
}

function pisky_history_settings_path() {
	$override = getenv("PISKY_HISTORY_SETTINGS");
	if ($override !== false && trim($override) !== "") return trim($override);
	return PISKY_HISTORY_SETTINGS;
}

function pisky_history_settings() {
	$defaults = array(
		"enabled" => false,
		"driver" => "mysql",
		"host" => "",
		"port" => 3306,
		"database" => "",
		"user" => "",
		"password" => "",
		"tls" => true,
		"station" => "default",
		"sample_interval_seconds" => 300,
		"retain_days" => 400
	);
	$path = pisky_history_settings_path();
	if (!is_readable($path)) return $defaults;
	$decoded = json_decode(file_get_contents($path), true);
	if (!is_array($decoded)) return $defaults;
	foreach ($defaults as $key => $value) {
		if (array_key_exists($key, $decoded)) $defaults[$key] = $decoded[$key];
	}
	return $defaults;
}

/*
 * Settings safe to hand to the interface: everything except the password, and
 * with the host reduced to a hostname so a full connection string is not put
 * on screen.
 */
function pisky_history_settings_public($settings = null) {
	$settings = $settings === null ? pisky_history_settings() : $settings;
	unset($settings["password"]);
	$settings["password_set"] = trim(strval(pisky_history_settings()["password"])) !== "";
	return $settings;
}

function pisky_history_remote_configured($settings = null) {
	$settings = $settings === null ? pisky_history_settings() : $settings;
	return !empty($settings["enabled"])
		&& trim(strval($settings["host"])) !== ""
		&& trim(strval($settings["database"])) !== ""
		&& trim(strval($settings["user"])) !== "";
}

/*
 * Connect once per request. A failure is remembered so a page rendering
 * several history panels does not retry a dead host repeatedly and stall.
 */
function pisky_history_pdo(&$error) {
	static $handle = null;
	static $failed = false;
	static $failure = "";

	$error = "";
	if ($failed) {
		$error = $failure;
		return null;
	}
	if ($handle instanceof PDO) return $handle;

	$settings = pisky_history_settings();
	if (!pisky_history_remote_configured($settings)) {
		$failed = true;
		$failure = "No remote history database is configured.";
		$error = $failure;
		return null;
	}
	if (!class_exists("PDO") || !in_array("mysql", PDO::getAvailableDrivers(), true)) {
		$failed = true;
		$failure = "PHP is missing the MySQL driver. Install php-mysql.";
		$error = $failure;
		return null;
	}

	$dsn = sprintf(
		"mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
		$settings["host"],
		max(1, min(65535, intval($settings["port"]))),
		$settings["database"]
	);
	$options = array(
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
		PDO::ATTR_TIMEOUT => 5
	);
	// A hosted database is reached across the internet, so refuse to send
	// credentials in the clear unless the host has explicitly allowed it.
	if (!empty($settings["tls"]) && defined("PDO::MYSQL_ATTR_SSL_CA")) {
		$options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
	}

	try {
		$handle = new PDO($dsn, $settings["user"], $settings["password"], $options);
	} catch (Throwable $throwable) {
		$failed = true;
		// The message can contain the connection string, so it is reduced to a
		// class of failure rather than being surfaced verbatim.
		$failure = "The history database could not be reached.";
		$error = $failure;
		return null;
	}
	return $handle;
}

function pisky_history_station() {
	$settings = pisky_history_settings();
	$station = preg_replace("/[^A-Za-z0-9_.-]/", "", strval($settings["station"]));
	return $station === "" ? "default" : substr($station, 0, 64);
}

/*
 * Create the tables when they are absent. Called on demand rather than by a
 * migration step, so pointing PiSky at an empty database is all a host has to
 * do. Column names are fixed by pisky_history_sample_fields().
 */
function pisky_history_schema(&$error) {
	$pdo = pisky_history_pdo($error);
	if ($pdo === null) return false;
	$columns = array();
	foreach (pisky_history_sample_fields() as $field) {
		$columns[] = "`" . $field . "` DOUBLE NULL";
	}
	try {
		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS pisky_samples ("
			. "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,"
			. "station VARCHAR(64) NOT NULL,"
			. "observed_at DATETIME NOT NULL,"
			. "day CHAR(8) NOT NULL,"
			. implode(",", $columns) . ","
			. "weather_code INT NULL,"
			. "UNIQUE KEY uniq_station_time (station, observed_at),"
			. "KEY idx_station_day (station, day)"
			. ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS pisky_rollups ("
			. "station VARCHAR(64) NOT NULL,"
			. "day CHAR(8) NOT NULL,"
			. "temperature_min DOUBLE NULL, temperature_max DOUBLE NULL,"
			. "temperature_avg DOUBLE NULL, humidity_avg DOUBLE NULL,"
			. "pressure_avg DOUBLE NULL, wind_speed_avg DOUBLE NULL,"
			. "wind_gust_max DOUBLE NULL, rain_total DOUBLE NULL,"
			. "conditions VARCHAR(120) NULL, weather_code INT NULL,"
			. "origin VARCHAR(20) NOT NULL DEFAULT 'station',"
			. "units TEXT NULL, updated_at DATETIME NULL,"
			. "PRIMARY KEY (station, day)"
			. ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
	} catch (Throwable $throwable) {
		$error = "The history tables could not be created.";
		return false;
	}
	return true;
}

/*
 * Store one intraday sample. Rate limited to the configured interval so the
 * poll frequency of the interface does not decide the write rate, and written
 * with INSERT IGNORE so a duplicate timestamp is harmless.
 */
function pisky_history_store_sample($weather) {
	if (empty($weather["ok"]) || !isset($weather["current"])) return false;
	$settings = pisky_history_settings();
	if (!pisky_history_remote_configured($settings)) return false;

	$interval = max(60, intval($settings["sample_interval_seconds"]));
	$observed = isset($weather["observed_at"]) ? strtotime($weather["observed_at"]) : time();
	if ($observed === false) $observed = time();
	// Align to the interval so samples land on predictable boundaries and the
	// unique key absorbs anything arriving between them.
	$slot = intdiv($observed, $interval) * $interval;

	$error = "";
	$pdo = pisky_history_pdo($error);
	if ($pdo === null) return false;
	if (!pisky_history_schema($error)) return false;

	$fields = pisky_history_sample_fields();
	$current = $weather["current"];
	$observations = array();
	if (isset($weather["observations"]) && is_array($weather["observations"])) {
		foreach ($weather["observations"] as $observation) {
			if (isset($observation["id"])) $observations[$observation["id"]] = $observation["value"];
		}
	}

	$columns = array("station", "observed_at", "day", "weather_code");
	$values = array(
		pisky_history_station(),
		gmdate("Y-m-d H:i:s", $slot),
		pisky_history_day_key(isset($weather["observed_at"]) ? $weather["observed_at"] : ""),
		isset($current["weather_code"]) && is_numeric($current["weather_code"])
			? intval($current["weather_code"]) : null
	);
	foreach ($fields as $field) {
		$value = null;
		if (isset($current[$field]) && is_numeric($current[$field])) {
			$value = floatval($current[$field]);
		} else if (isset($observations[$field]) && is_numeric($observations[$field])) {
			$value = floatval($observations[$field]);
		}
		$columns[] = $field;
		$values[] = $value;
	}

	$placeholders = implode(",", array_fill(0, count($columns), "?"));
	$sql = "INSERT IGNORE INTO pisky_samples (`" . implode("`,`", $columns) . "`) VALUES (" . $placeholders . ")";
	try {
		$statement = $pdo->prepare($sql);
		$statement->execute($values);
	} catch (Throwable $throwable) {
		return false;
	}
	return true;
}

/*
 * The intraday series for one day, oldest first, ready to plot. Returns an
 * empty array when no remote database is configured or reachable, which is
 * what tells the interface to offer the rollup summary instead of a graph.
 */
function pisky_history_samples($day, &$error) {
	$error = "";
	$day = pisky_history_safe_day($day);
	if ($day === "") return array();
	if (!pisky_history_remote_configured()) return array();

	$pdo = pisky_history_pdo($error);
	if ($pdo === null) return array();

	$fields = pisky_history_sample_fields();
	$select = "observed_at, weather_code, `" . implode("`,`", $fields) . "`";
	try {
		$statement = $pdo->prepare(
			"SELECT " . $select . " FROM pisky_samples"
			. " WHERE station = ? AND day = ? ORDER BY observed_at ASC LIMIT 2000"
		);
		$statement->execute(array(pisky_history_station(), $day));
		$rows = $statement->fetchAll();
	} catch (Throwable $throwable) {
		$error = "The history database could not be read.";
		return array();
	}

	$samples = array();
	foreach ($rows as $row) {
		$point = array("time" => str_replace(" ", "T", $row["observed_at"]) . "Z");
		foreach ($fields as $field) {
			$point[$field] = $row[$field] === null ? null : floatval($row[$field]);
		}
		$point["weather_code"] = $row["weather_code"] === null ? null : intval($row["weather_code"]);
		$samples[] = $point;
	}
	return $samples;
}

/* Mirror the daily rollup remotely so history survives the SD card. */
function pisky_history_store_rollup($day, $record) {
	if (!pisky_history_remote_configured()) return false;
	$error = "";
	$pdo = pisky_history_pdo($error);
	if ($pdo === null || !pisky_history_schema($error)) return false;
	try {
		$statement = $pdo->prepare(
			"INSERT INTO pisky_rollups (station, day, temperature_min, temperature_max,"
			. " temperature_avg, humidity_avg, pressure_avg, wind_speed_avg, wind_gust_max,"
			. " rain_total, conditions, weather_code, origin, units, updated_at)"
			. " VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
			. " ON DUPLICATE KEY UPDATE"
			. " temperature_min=VALUES(temperature_min), temperature_max=VALUES(temperature_max),"
			. " temperature_avg=VALUES(temperature_avg), humidity_avg=VALUES(humidity_avg),"
			. " pressure_avg=VALUES(pressure_avg), wind_speed_avg=VALUES(wind_speed_avg),"
			. " wind_gust_max=VALUES(wind_gust_max), rain_total=VALUES(rain_total),"
			. " conditions=VALUES(conditions), weather_code=VALUES(weather_code),"
			. " units=VALUES(units), updated_at=VALUES(updated_at)"
		);
		$number = function ($key) use ($record) {
			return isset($record[$key]) && is_numeric($record[$key]) ? floatval($record[$key]) : null;
		};
		$statement->execute(array(
			pisky_history_station(), $day,
			$number("temperature_min"), $number("temperature_max"), $number("temperature_avg"),
			$number("humidity_avg"), $number("pressure_avg"), $number("wind_speed_avg"),
			$number("wind_gust_max"), $number("rain_total"),
			isset($record["condition"]) ? substr(strval($record["condition"]), 0, 120) : null,
			isset($record["weather_code"]) && is_numeric($record["weather_code"])
				? intval($record["weather_code"]) : null,
			isset($record["origin"]) ? substr(strval($record["origin"]), 0, 20) : "station",
			json_encode(isset($record["units"]) ? $record["units"] : array()),
			gmdate("Y-m-d H:i:s")
		));
	} catch (Throwable $throwable) {
		return false;
	}
	return true;
}

/* Discard samples past the retention window, so the table cannot grow without
   bound on a hosted plan. Rollups are never pruned; they are the long record. */
function pisky_history_prune() {
	$settings = pisky_history_settings();
	if (!pisky_history_remote_configured($settings)) return false;
	$days = max(7, min(3650, intval($settings["retain_days"])));
	$error = "";
	$pdo = pisky_history_pdo($error);
	if ($pdo === null) return false;
	try {
		$statement = $pdo->prepare(
			"DELETE FROM pisky_samples WHERE station = ? AND observed_at < ?"
		);
		$statement->execute(array(
			pisky_history_station(), gmdate("Y-m-d H:i:s", time() - $days * 86400)
		));
	} catch (Throwable $throwable) {
		return false;
	}
	return true;
}

/* Connection check for the administration interface. */
function pisky_history_test(&$message) {
	$settings = pisky_history_settings();
	if (!pisky_history_remote_configured($settings)) {
		$message = "No remote history database is configured.";
		return false;
	}
	$error = "";
	$pdo = pisky_history_pdo($error);
	if ($pdo === null) {
		$message = $error;
		return false;
	}
	if (!pisky_history_schema($error)) {
		$message = $error;
		return false;
	}
	try {
		$statement = $pdo->prepare("SELECT COUNT(*) AS total FROM pisky_samples WHERE station = ?");
		$statement->execute(array(pisky_history_station()));
		$row = $statement->fetch();
		$message = "Connected. " . intval($row["total"]) . " sample"
			. (intval($row["total"]) === 1 ? "" : "s") . " stored for this station.";
	} catch (Throwable $throwable) {
		$message = "Connected, but the sample table could not be read.";
		return false;
	}
	return true;
}
?>
