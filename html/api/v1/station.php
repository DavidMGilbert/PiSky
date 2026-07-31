<?php
/* Convenience route for /api/v1/station on servers without rewrite support. */
$_GET["resource"] = "station";
require __DIR__ . "/index.php";
?>
