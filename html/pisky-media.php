<?php
/*
 * Auth-free, read-only delivery of administrator-approved PiSky gallery media.
 */
include_once(__DIR__ . "/includes/piskySite.php");
$file = isset($_GET["file"]) && !is_array($_GET["file"]) ? basename($_GET["file"]) : "";
if (!preg_match("/^[a-z0-9-]+\.(jpg|jpeg|png|webp)$/i", $file)) {
	http_response_code(404);
	exit;
}
$root = realpath(PISKY_CONTENT_DIR . "/media");
$path = realpath(PISKY_CONTENT_DIR . "/media/" . $file);
if ($root === false || $path === false
	|| strpos($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0
	|| !is_file($path)) {
	http_response_code(404);
	exit;
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($path);
if (!in_array($mime, array("image/jpeg", "image/png", "image/webp"), true)) {
	http_response_code(415);
	exit;
}
header("Content-Type: " . $mime);
header("Content-Length: " . filesize($path));
header("Cache-Control: public, max-age=86400, immutable");
header("X-Content-Type-Options: nosniff");
readfile($path);
?>
