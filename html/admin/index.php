<?php
/*
 * Stable authenticated PiSky administration entry point.
 * SPDX-License-Identifier: MIT
 */

define("PISKY_ADMIN_ENTRY", true);
$_SERVER["PHP_SELF"] = "/admin/";
chdir(dirname(__DIR__));
require dirname(__DIR__) . "/index.php";
?>
