<?php
/* Convenience route for /api/v1/weather on servers without rewrite support. */
$_GET["resource"] = "weather";
require __DIR__ . "/index.php";
?>
