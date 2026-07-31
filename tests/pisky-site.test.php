<?php
/*
 * Verify that administrator-authored public content keeps useful formatting
 * without allowing executable markup.
 * SPDX-License-Identifier: MIT
 */

include dirname(__DIR__) . "/html/includes/piskySite.php";

$unsafe = '<p class="hero" onclick=alert(1)>Welcome '
	. '<img src=x onerror=alert(2)>'
	. '<a href="javascript:alert(3)" onmouseover="alert(4)">bad</a> '
	. '<a href="https://example.com/path?q=1" target="_blank">safe</a></p>'
	. '<h3 style=color:red onmouseover=alert(5)>Details</h3>';
$clean = pisky_site_clean_html($unsafe);

foreach (array("onclick", "onerror", "onmouseover", "javascript:", "<img", "style=") as $needle) {
	if (stripos($clean, $needle) !== false) {
		fwrite(STDERR, "Unsafe site-content marker survived: " . $needle . PHP_EOL);
		exit(1);
	}
}
if (strpos($clean, '<p>Welcome ') === false
	|| strpos($clean, '<h3>Details</h3>') === false
	|| strpos($clean, 'href="https://example.com/path?q=1" rel="noopener"') === false) {
	fwrite(STDERR, "Safe site-content formatting was not preserved." . PHP_EOL);
	exit(1);
}

echo "PiSky public-content sanitisation passed." . PHP_EOL;
