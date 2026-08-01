<?php
/*
 * PiSky appliance-site profile and content storage.
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

include_once(__DIR__ . "/piskyFlights.php");

if (!defined("PISKY_CONTENT_DIR")) {
	define("PISKY_CONTENT_DIR", "/var/lib/pisky/content");
}

function pisky_site_defaults() {
	return array(
		"modules" => array(
			"camera" => true
		),
		"tagline" => "Local observations of the sky above us.",
		// How this station is reached from outside the local network. Used for
		// canonical links, embed snippets and the API's own self-reference. It
		// cannot be derived reliably from the request when a reverse proxy sits
		// in front, because the Pi only ever sees the proxy's own request.
		"public_url" => "",
		/*
		 * Listing on the shared map at pisky.space.
		 *
		 * Off unless the host turns it on, and turning it on publishes where
		 * this station is to a public page, so the position is rounded by
		 * default. The station only ever announces its address; everything
		 * shown on that map is read back from this station's own API, so
		 * switching this off removes the listing at the next check rather than
		 * leaving a pin behind.
		 */
		"directory" => array(
			"enabled" => false,
			"precision" => "approximate",
			"endpoint" => "https://pisky.space/api/stations/beacon",
			"station_id" => "",
			"last_beacon" => "",
			"last_result" => ""
		),
		"api" => array(
			"enabled" => true,
			"origins" => array(),
			"anonymous_per_hour" => 600,
			"key_per_hour" => 6000
		),
		"page_intro" => array(
			"overview" => "Everything this station is observing right now, at a glance.",
			"live" => "A current view from this station's sky camera.",
			"weather" => "Live station observations and a local seven-day forecast.",
			"flights" => "Aircraft positions decoded by this station's local receiver.",
			"archive" => "Historical sky images, derived observations and timelapses."
		),
		// Text shown on the overview cards. The station name itself comes from
		// the camera settings, so only the surrounding wording is editable.
		"station" => array(
			"location_label" => "Station location",
			"location_note" => "Local sky, weather and radio observations",
			"summary_label" => "One observatory",
			"brand_label" => "PiSky Observatory",
			"heading_phrase" => "in {location}"
		),
		"about" => array(
			"title" => "About this PiSky station",
			"body" => "<p>This station shares local sky observations collected with PiSky.</p>"
		),
		"equipment" => array(
			"camera" => "",
			"weather_station" => "",
			"adsb_receiver" => "",
			"antenna" => "",
			"receiver_height" => "",
			"build_notes" => ""
		),
		"gallery" => array(),
		"sensor_nodes" => array()
	);
}

function pisky_site_path() {
	$override = getenv("PISKY_SITE_CONFIG");
	if ($override !== false && trim($override) !== "") return trim($override);
	return PISKY_CONTENT_DIR . "/site.json";
}

function pisky_site_config() {
	$config = pisky_site_defaults();
	$path = pisky_site_path();
	if (is_readable($path)) {
		$decoded = json_decode(file_get_contents($path), true);
		if (is_array($decoded)) $config = pisky_merge_config($config, $decoded);
	}
	return $config;
}

function pisky_site_clean_html($html) {
	$html = trim(strval($html));
	if ($html === "") return "";
	$allowed = "<p><br><strong><b><em><i><ul><ol><li><h2><h3><h4><blockquote><a>";
	$html = strip_tags($html, $allowed);
	$html = preg_replace_callback(
		"#<a\b[^>]*>#is",
		function ($matches) {
			if (!preg_match(
				"#\bhref\s*=\s*(?:\"([^\"]*)\"|'([^']*)'|([^\s>]+))#is",
				$matches[0],
				$href
			)) return "<a>";
			$url = trim($href[1] !== "" ? $href[1]
				: ($href[2] !== "" ? $href[2] : $href[3]));
			if (!preg_match("#^(https?://|mailto:|/)#i", $url)) return "<a>";
			return '<a href="' . htmlspecialchars($url, ENT_QUOTES, "UTF-8")
				. '" rel="noopener">';
		},
		$html
	);
	$html = preg_replace(
		"#<(p|br|strong|b|em|i|ul|ol|li|h2|h3|h4|blockquote)\b[^>]*>#i",
		"<$1>",
		$html
	);
	return $html;
}

function pisky_site_text($value, $maximum) {
	$value = trim(strip_tags(strval($value)));
	return function_exists("mb_substr")
		? mb_substr($value, 0, $maximum)
		: substr($value, 0, $maximum);
}

/*
 * Describe why the content directory cannot be written to.
 *
 * "Re-run the installer" is useless advice when the installer already created
 * the directory correctly and the real problem is that the web server runs as
 * a different user, or that open_basedir excludes the path. Reporting the
 * actual owner, mode and process identity turns a dead end into something the
 * host can act on.
 */
function pisky_site_storage_problem() {
	$path = PISKY_CONTENT_DIR;
	if (!file_exists($path)) {
		return "PiSky content storage (" . $path . ") does not exist. Re-run install-pisky.sh.";
	}
	if (!is_dir($path)) {
		return "PiSky content storage (" . $path . ") is not a directory.";
	}
	if (is_writable($path)) return "";

	$owner = "unknown";
	$mode = "unknown";
	$stat = @stat($path);
	if ($stat !== false) {
		$mode = substr(sprintf("%o", $stat["mode"]), -4);
		if (function_exists("posix_getpwuid")) {
			$user = @posix_getpwuid($stat["uid"]);
			$group = function_exists("posix_getgrgid") ? @posix_getgrgid($stat["gid"]) : null;
			$owner = ($user && isset($user["name"]) ? $user["name"] : $stat["uid"])
				. ":" . ($group && isset($group["name"]) ? $group["name"] : $stat["gid"]);
		} else {
			$owner = $stat["uid"] . ":" . $stat["gid"];
		}
	}
	$process = function_exists("posix_geteuid") && function_exists("posix_getpwuid")
		? (($entry = @posix_getpwuid(posix_geteuid())) && isset($entry["name"])
			? $entry["name"] : strval(posix_geteuid()))
		: "the web server user";
	$basedir = trim(strval(ini_get("open_basedir")));

	$message = "PiSky cannot write to " . $path . ". It is owned by " . $owner
		. " with mode " . $mode . ", and PHP is running as " . $process . ".";
	if ($basedir !== "") {
		$message .= " open_basedir is set to " . $basedir
			. ", which must include the path.";
	} else {
		$message .= " Grant that user write access, for example:"
			. " sudo chown -R " . $process . " " . $path;
	}
	return $message;
}

function pisky_site_write($config, &$error) {
	$error = "";
	$problem = pisky_site_storage_problem();
	if ($problem !== "") {
		$error = $problem;
		return false;
	}
	$json = json_encode(
		$config,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);
	if ($json === false || strlen($json) > 1048576) {
		$error = "The site profile could not be encoded.";
		return false;
	}
	$temporary = tempnam(PISKY_CONTENT_DIR, ".site-");
	if ($temporary === false
		|| file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
		if ($temporary) @unlink($temporary);
		$error = "PiSky could not stage the site profile.";
		return false;
	}
	@chmod($temporary, 0640);
	if (!@rename($temporary, pisky_site_path())) {
		@unlink($temporary);
		$error = "PiSky could not save the site profile.";
		return false;
	}
	return true;
}

function pisky_site_capabilities() {
	$site = pisky_site_config();
	$weather = pisky_weather_config();
	$flights = pisky_flights_config();
	return array(
		"camera" => !isset($site["modules"]["camera"]) || !empty($site["modules"]["camera"]),
		"weather" => !empty($weather["enabled"]),
		"flights" => !empty($flights["enabled"])
	);
}

/*
 * The address visitors actually use.
 *
 * Falls back to the requested host, which is correct for a station reached
 * directly, and is what a reverse proxy deployment must override because the
 * Pi only ever sees the proxy's request.
 */
function pisky_site_public_url() {
	$site = pisky_site_config();
	$configured = isset($site["public_url"]) ? trim(strval($site["public_url"])) : "";
	if ($configured !== "") return rtrim($configured, "/");
	$scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
	$host = isset($_SERVER["HTTP_HOST"]) ? $_SERVER["HTTP_HOST"] : "pisky.local";
	return $scheme . "://" . $host;
}

function pisky_site_media_url($filename) {
	return "/pisky-media.php?file=" . rawurlencode(basename($filename));
}

/* ---------------------------------------------------------------------------
 * Shared map listing
 * ------------------------------------------------------------------------ */

function pisky_directory_config() {
	$site = pisky_site_config();
	$defaults = pisky_site_defaults();
	$directory = isset($site["directory"]) && is_array($site["directory"])
		? $site["directory"] : array();
	return array_merge($defaults["directory"], $directory);
}

/*
 * A name for this station on the shared map.
 *
 * Generated here rather than taken from /etc/machine-id, which identifies the
 * hardware across everything else on it and has no business being published.
 * This one means nothing anywhere but the map, and a host who wants to be
 * forgotten can clear it.
 */
function pisky_directory_station_id($config = null) {
	if ($config === null) $config = pisky_directory_config();
	$id = isset($config["station_id"]) ? trim(strval($config["station_id"])) : "";
	return preg_match('/^[a-f0-9]{32}$/', $id) ? $id : "";
}

function pisky_directory_new_station_id() {
	return bin2hex(random_bytes(16));
}

/*
 * How precisely the shared map may place this station.
 *
 * Rounding happens before the coordinate leaves the Pi, so the map is never
 * sent a precise position and then trusted to blur it. Two decimal places is
 * roughly a kilometre, which still shows who covers what without publishing
 * anybody's address.
 */
function pisky_directory_round($value, $precision) {
	if ($value === null) return null;
	return $precision === "exact" ? round($value, 5) : round($value, 2);
}

/*
 * Whether the shared map could reach this address at all.
 *
 * A station only ever known as pisky.local, or at a private address, cannot be
 * verified from the internet, and announcing it would leave an unreachable pin
 * that never resolves. The interface warns with this and the beacon refuses
 * with it, so what the host is told matches what actually happens.
 */
function pisky_directory_reachable_url($url) {
	$url = trim(strval($url));
	if (!preg_match('#^https?://([A-Za-z0-9\-]+\.)+[A-Za-z]{2,}(:\d{1,5})?(/\S*)?$#', $url)) {
		return false;
	}
	$host = strtolower(strval(parse_url($url, PHP_URL_HOST)));
	if ($host === "" || substr($host, -6) === ".local" || substr($host, -8) === ".localdomain") {
		return false;
	}
	return true;
}

function pisky_directory_precision($config = null) {
	if ($config === null) $config = pisky_directory_config();
	$precision = isset($config["precision"]) ? strval($config["precision"]) : "";
	return $precision === "exact" ? "exact" : "approximate";
}
?>
