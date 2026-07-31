<?php
/*
 * Static checks for camera-free PiSky installation templates.
 * SPDX-License-Identifier: MIT
 */

$root = dirname(__DIR__);
$installer = file_get_contents($root . "/install-pisky.sh");
$defines = file_get_contents($root . "/config_repo/allskyDefines.inc.repo");
$lighttpd = file_get_contents($root . "/config_repo/pisky-lighttpd.conf.repo");
$settings = json_decode(
	file_get_contents($root . "/config_repo/pisky-headless-settings.json.repo"),
	true
);
if ($installer === false || $defines === false || $lighttpd === false
	|| !is_array($settings)) {
	fwrite(STDERR, "Unable to load camera-free installation templates." . PHP_EOL);
	exit(1);
}

preg_match_all("/XX_[A-Z0-9_]+_XX/", $defines . $lighttpd, $matches);
foreach (array_unique($matches[0]) as $placeholder) {
	if (strpos($installer, $placeholder) === false) {
		fwrite(STDERR, "Installer does not replace " . $placeholder . PHP_EOL);
		exit(1);
	}
}
if (($settings["lastchanged"] ?? "") !== "pisky-modular"
	|| ($settings["uselogin"] ?? false) !== true
	|| !isset($settings["location"])) {
	fwrite(STDERR, "Camera-free settings are missing required PiSky defaults." . PHP_EOL);
	exit(1);
}
if (strpos($lighttpd, 'alias.url = (') === false
	|| strpos($lighttpd, '"/config/"') !== false
	|| strpos($lighttpd, 'dir-listing.activate = "disable"') === false) {
	fwrite(STDERR, "Camera-free web-server template is not safely scoped." . PHP_EOL);
	exit(1);
}

echo "PiSky camera-free installer templates passed." . PHP_EOL;
