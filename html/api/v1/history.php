<?php
/* Convenience route for /api/v1/history on servers without rewrite support. */
$_GET["resource"] = "history";
require __DIR__ . "/index.php";
?>
