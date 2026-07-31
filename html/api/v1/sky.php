<?php
/* Convenience route for /api/v1/sky on servers without rewrite support. */
$_GET["resource"] = "sky";
require __DIR__ . "/index.php";
?>
