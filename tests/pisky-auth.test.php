<?php
/*
 * Verify the documented fresh-install PiSky administration credentials.
 * SPDX-License-Identifier: MIT
 */

$source = file_get_contents(dirname(__DIR__) . "/html/includes/authenticate.php");
if ($source === false) {
	fwrite(STDERR, "Unable to read PiSky authentication source." . PHP_EOL);
	exit(1);
}

if (!preg_match("/'admin_pass'\\s*=>\\s*'([^']+)'/", $source, $matches)) {
	fwrite(STDERR, "Unable to find the initial PiSky password hash." . PHP_EOL);
	exit(1);
}

if (!password_verify("secret", $matches[1])) {
	fwrite(STDERR, "The documented initial PiSky password no longer matches." . PHP_EOL);
	exit(1);
}

if (!preg_match('/\\$useLogin\\s*=\\s*true;/', $source)) {
	fwrite(STDERR, "PiSky administration endpoints do not force authentication." . PHP_EOL);
	exit(1);
}

echo "PiSky authentication defaults and enforcement passed." . PHP_EOL;
