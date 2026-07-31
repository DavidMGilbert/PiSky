<?php
/* Convenience route for /api/v1/aircraft on servers without rewrite support. */
$_GET["resource"] = "aircraft";
require __DIR__ . "/index.php";
?>
