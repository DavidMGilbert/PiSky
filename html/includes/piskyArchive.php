<?php
/*
 * PiSky public archive reader.
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

function pisky_archive_root() {
	return defined("ALLSKY_IMAGES") ? ALLSKY_IMAGES : dirname(__DIR__, 2) . "/images";
}

function pisky_archive_safe_day($day) {
	$day = strval($day);
	if (!preg_match("/^[0-9]{8}$/", $day)) return "";
	$date = DateTime::createFromFormat("!Ymd", $day);
	return $date && $date->format("Ymd") === $day ? $day : "";
}

function pisky_archive_files($directory, $patterns) {
	$files = array();
	foreach ($patterns as $pattern) {
		foreach (glob($directory . "/" . $pattern) ?: array() as $path) {
			if (is_file($path)) $files[] = $path;
		}
	}
	$files = array_values(array_unique($files));
	sort($files, SORT_NATURAL);
	return $files;
}

function pisky_archive_url($day, $path) {
	return "/images/" . rawurlencode($day) . "/" . rawurlencode(basename($path));
}

/*
 * Allsky writes a keogram and startrail alongside the timelapse. Those are
 * stills, not timelapses, so they are excluded from the archive listing while
 * still being usable as a poster frame for the video.
 */
function pisky_archive_poster($directory, $day) {
	foreach (array("keogram", "startrails") as $kind) {
		$candidates = pisky_archive_files(
			$directory . "/" . $kind, array("*.jpg", "*.jpeg", "*.png", "*.webp")
		);
		if (count($candidates)) return $candidates[0];
	}
	return null;
}

/*
 * Days that actually have a timelapse. A day with only stills is not an
 * archive entry: the archive presents finished timelapses, and the live
 * interface covers current imagery.
 */
function pisky_archive_days($search = "") {
	$root = pisky_archive_root();
	if (!is_dir($root)) return array();
	$digits = preg_replace("/[^0-9]/", "", strval($search));
	$days = array();
	foreach (glob(rtrim($root, "/") . "/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]", GLOB_ONLYDIR) ?: array() as $directory) {
		$day = basename($directory);
		if ($digits !== "" && strpos($day, $digits) !== 0) continue;
		$videos = pisky_archive_files($directory, array("*.mp4", "*.webm"));
		if (!count($videos)) continue;
		$poster = pisky_archive_poster($directory, $day);
		$days[] = array(
			"day" => $day,
			"video_count" => count($videos),
			"videos" => $videos,
			"poster" => $poster,
			"poster_url" => $poster !== null
				? "/images/" . rawurlencode($day) . "/"
					. rawurlencode(basename(dirname($poster))) . "/"
					. rawurlencode(basename($poster))
				: null
		);
	}
	usort($days, function ($a, $b) { return strcmp($b["day"], $a["day"]); });
	return $days;
}

function pisky_archive_day($day) {
	$day = pisky_archive_safe_day($day);
	if ($day === "") return null;
	$directory = realpath(pisky_archive_root() . "/" . $day);
	$root = realpath(pisky_archive_root());
	if ($directory === false || $root === false
		|| strpos($directory, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) return null;
	$poster = pisky_archive_poster($directory, $day);
	return array(
		"day" => $day,
		"videos" => pisky_archive_files($directory, array("*.mp4", "*.webm")),
		"poster" => $poster
	);
}
?>
