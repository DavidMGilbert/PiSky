<?php
/*
 * PiSky normalized local flight API
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, max-age=0");
header("X-Content-Type-Options: nosniff");

include_once(__DIR__ . "/includes/functions.php");
include_once(__DIR__ . "/includes/status_messages.php");
include_once(__DIR__ . "/includes/piskyFlights.php");

$status = new StatusMessages();
$lastChangedName = "lastchanged";
initialize_variables();

$flights = pisky_get_flights($settings_array);
if (empty($flights["ok"])) http_response_code(503);
echo json_encode($flights, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
