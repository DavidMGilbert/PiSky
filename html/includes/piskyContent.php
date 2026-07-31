<?php
/*
 * PiSky public-site editor.
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

include_once(__DIR__ . "/piskySite.php");
include_once(__DIR__ . "/piskyAdmin.php");

function pisky_content_post($name, $default="") {
	if (!isset($_POST[$name]) || is_array($_POST[$name])) return $default;
	return strval($_POST[$name]);
}

function pisky_content_upload(&$config, &$error) {
	if (!isset($_FILES["gallery_image"]) || !is_array($_FILES["gallery_image"])
		|| $_FILES["gallery_image"]["error"] === UPLOAD_ERR_NO_FILE) return true;
	$file = $_FILES["gallery_image"];
	if ($file["error"] !== UPLOAD_ERR_OK || $file["size"] > 8 * 1024 * 1024) {
		$error = "The photo upload failed or exceeded the 8 MB limit.";
		return false;
	}
	$finfo = new finfo(FILEINFO_MIME_TYPE);
	$mime = $finfo->file($file["tmp_name"]);
	$extensions = array(
		"image/jpeg" => "jpg",
		"image/png" => "png",
		"image/webp" => "webp"
	);
	if (!isset($extensions[$mime]) || @getimagesize($file["tmp_name"]) === false) {
		$error = "Photos must be valid JPEG, PNG or WebP images.";
		return false;
	}
	try {
		$name = bin2hex(random_bytes(16)) . "." . $extensions[$mime];
	} catch (Exception $exception) {
		$name = str_replace(".", "", uniqid("photo-", true)) . "." . $extensions[$mime];
	}
	$destination = PISKY_CONTENT_DIR . "/media/" . $name;
	if (!is_dir(dirname($destination)) || !is_writable(dirname($destination))
		|| !move_uploaded_file($file["tmp_name"], $destination)) {
		$error = "PiSky could not store the uploaded photo.";
		return false;
	}
	@chmod($destination, 0640);
	$config["gallery"][] = array(
		"file" => $name,
		"caption" => pisky_site_text(pisky_content_post("gallery_caption"), 180),
		"uploaded_at" => date(DATE_ATOM)
	);
	return true;
}

function DisplayPiSkyContent() {
	global $useLogin;
	$config = pisky_site_config();
	$notice = "";
	$noticeType = "info";

	// Repairing storage runs as root through piskyctl, so a host whose content
	// directory is missing or wrongly owned can fix it from the browser rather
	// than needing shell access to the appliance.
	if ($_SERVER["REQUEST_METHOD"] === "POST"
		&& pisky_content_post("pisky_action") === "repair_storage") {
		if (!$useLogin || !CSRFValidate()) {
			$notice = "The repair request expired or failed its security check.";
			$noticeType = "danger";
		} else {
			$output = array();
			$exitCode = 1;
			$ok = pisky_admin_run(array("ensure-storage"), $output, $exitCode);
			$notice = trim(implode("\n", $output));
			if ($notice === "") {
				$notice = $ok
					? "PiSky storage repaired."
					: "PiSky storage could not be repaired.";
			}
			$noticeType = $ok ? "success" : "danger";
		}
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST"
		&& pisky_content_post("pisky_action") === "save_content") {
		if (!$useLogin || !CSRFValidate()) {
			$notice = "The content request expired or failed its security check.";
			$noticeType = "danger";
		} else {
			$config["modules"]["camera"] = isset($_POST["camera_enabled"])
				&& $_POST["camera_enabled"] === "1";
			$config["tagline"] = pisky_site_text(pisky_content_post("tagline"), 220);
			$publicUrl = pisky_site_text(pisky_content_post("public_url"), 200);
			// Only an absolute http(s) origin is meaningful here; anything else
			// would produce broken canonical links and embed snippets.
			if ($publicUrl !== "" && !preg_match("#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/.*)?$#", $publicUrl)) {
				$publicUrl = "";
				$notice = "The public address must be a full http:// or https:// address.";
				$noticeType = "danger";
			}
			$config["public_url"] = rtrim($publicUrl, "/");
			$config["api"]["enabled"] = pisky_content_post("api_enabled") === "1";
			$origins = array();
			foreach (preg_split("/[\\r\\n,]+/", pisky_content_post("api_origins")) as $origin) {
				$origin = trim($origin);
				if ($origin === "") continue;
				if ($origin === "*" || preg_match("#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?$#", $origin)) {
					$origins[] = $origin;
				}
			}
			$config["api"]["origins"] = array_values(array_unique(array_slice($origins, 0, 40)));
			foreach (array("overview", "live", "weather", "flights", "archive") as $page) {
				$config["page_intro"][$page] = pisky_site_text(
					pisky_content_post("intro_" . $page), 600
				);
			}
			foreach (array(
				"location_label" => 60,
				"location_note" => 160,
				"summary_label" => 60,
				"brand_label" => 60,
				"heading_phrase" => 80
			) as $field => $limit) {
				$config["station"][$field] = pisky_site_text(
					pisky_content_post("station_" . $field), $limit
				);
			}
			$config["about"]["title"] = pisky_site_text(
				pisky_content_post("about_title"), 120
			);
			$config["about"]["body"] = pisky_site_clean_html(
				pisky_content_post("about_body")
			);
			foreach (array(
				"camera", "weather_station", "adsb_receiver", "antenna",
				"receiver_height", "build_notes"
			) as $field) {
				$config["equipment"][$field] = pisky_site_text(
					pisky_content_post("equipment_" . $field),
					$field === "build_notes" ? 2000 : 240
				);
			}
			$remove = pisky_content_post("remove_photo");
			if ($remove !== "") {
				$kept = array();
				foreach ($config["gallery"] as $photo) {
					if (!isset($photo["file"]) || $photo["file"] !== $remove) {
						$kept[] = $photo;
					} else {
						@unlink(PISKY_CONTENT_DIR . "/media/" . basename($remove));
					}
				}
				$config["gallery"] = $kept;
			}
			$error = "";
			if (pisky_content_upload($config, $error)
				&& pisky_site_write($config, $error)) {
				$notice = "Public content and station profile saved.";
				$noticeType = "success";
			} else {
				$notice = $error;
				$noticeType = "danger";
			}
		}
	}
?>
<div class="pisky-page-heading">
	<div>
		<span class="pisky-eyebrow">Appliance website</span>
		<h1>Public content</h1>
		<p>Choose the observations this station offers and tell visitors how it was built.</p>
	</div>
	<a class="btn btn-default" href="/?view=about" target="_blank" rel="noopener">Preview public site</a>
</div>
<?php if ($notice !== "") { ?>
<div class="alert alert-<?php echo $noticeType; ?>"><?php echo htmlspecialchars($notice); ?></div>
<?php } ?>
<?php
// Saving will fail while storage is unavailable, so the problem and its remedy
// are shown up front rather than after the host has filled the form in.
$storageProblem = pisky_site_storage_problem();
if ($storageProblem !== "") {
?>
<div class="pisky-glass pisky-panel pisky-storage-repair">
	<div class="pisky-panel-heading">
		<div>
			<span class="pisky-eyebrow">Storage unavailable</span>
			<h2>Content cannot be saved yet</h2>
		</div>
	</div>
	<p><?php echo htmlspecialchars($storageProblem); ?></p>
	<form method="post">
		<input type="hidden" name="page" value="content">
		<input type="hidden" name="pisky_action" value="repair_storage">
		<?php CSRFToken(); ?>
		<button class="btn btn-primary" type="submit">
			<i class="fa fa-wrench" aria-hidden="true"></i>
			Repair PiSky storage
		</button>
		<span>Creates the missing directories and reasserts their ownership.</span>
	</form>
</div>
<?php } ?>
<form class="pisky-content-editor" method="post" enctype="multipart/form-data">
	<input type="hidden" name="page" value="content">
	<input type="hidden" name="pisky_action" value="save_content">
	<?php CSRFToken(); ?>
	<section class="pisky-glass pisky-panel">
		<div class="pisky-panel-heading"><div><span class="pisky-eyebrow">Capabilities</span><h2>Modules on this Pi</h2></div></div>
		<p class="pisky-form-intro">Weather and aircraft visibility follow their enabled settings in PiSky Setup. The camera can be hidden independently here.</p>
		<label class="pisky-toggle">
			<input type="checkbox" name="camera_enabled" value="1" <?php echo !empty($config["modules"]["camera"]) ? "checked" : ""; ?>>
			<span>Sky camera and image archive enabled</span>
		</label>
		<label class="pisky-field pisky-field-wide">
			<span>Station tagline</span>
			<input class="form-control" type="text" name="tagline" maxlength="220" value="<?php echo htmlspecialchars($config["tagline"]); ?>">
		</label>
		<label class="pisky-field pisky-field-wide">
			<span>Public address</span>
			<input class="form-control" type="url" name="public_url" maxlength="200"
				placeholder="https://pisky.example.com"
				value="<?php echo htmlspecialchars(isset($config["public_url"]) ? $config["public_url"] : ""); ?>">
			<small class="pisky-field-hint">The address visitors use. Required when a reverse proxy sits in front, because this Pi only ever sees the proxy's own request and cannot work the address out for itself. Used for canonical links, embed snippets and the API.</small>
		</label>
	</section>
	<section class="pisky-glass pisky-panel">
		<div class="pisky-panel-heading"><div><span class="pisky-eyebrow">Data sharing</span><h2>Public API</h2></div></div>
		<p class="pisky-form-intro">Read-only access to what this station already publishes. It exposes nothing beyond the visitor site: readings switched off under Weather stay hidden here too, and there is no way to change anything through it.</p>
		<label class="pisky-toggle">
			<input type="checkbox" name="api_enabled" value="1" <?php echo !empty($config["api"]["enabled"]) ? "checked" : ""; ?>>
			<span>Offer the public data API</span>
		</label>
		<label class="pisky-field pisky-field-wide">
			<span>Sites allowed to embed this data</span>
			<textarea class="form-control" name="api_origins" rows="3"
				placeholder="https://example.com"><?php echo htmlspecialchars(implode("
", isset($config["api"]["origins"]) ? $config["api"]["origins"] : array())); ?></textarea>
			<small class="pisky-field-hint">One address per line. Leave empty to allow only this station's own pages. Use * to allow any site.</small>
		</label>
		<div class="pisky-config-note">
			<span>API address</span>
			<code><?php echo htmlspecialchars(pisky_site_public_url()); ?>/api/v1/</code>
		</div>
	</section>
	<section class="pisky-glass pisky-panel">
		<div class="pisky-panel-heading"><div><span class="pisky-eyebrow">Overview cards</span><h2>Station summary wording</h2></div></div>
		<p class="pisky-form-intro">The station name itself comes from Camera Settings. These control the wording around it on the overview page.</p>
		<div class="pisky-form-grid">
		<?php foreach (array(
			"summary_label" => array("Summary card heading", 60),
			"location_label" => array("Location card heading", 60),
			"location_note" => array("Location card description", 160),
			"brand_label" => array("Page heading suffix", 60),
			"heading_phrase" => array("Page heading phrase, use {location}", 80)
		) as $key => $meta) { ?>
			<label class="pisky-field<?php echo $key === "location_note" ? " pisky-field-wide" : ""; ?>">
				<span><?php echo htmlspecialchars($meta[0]); ?></span>
				<input class="form-control" type="text" name="station_<?php echo $key; ?>"
					maxlength="<?php echo intval($meta[1]); ?>"
					value="<?php echo htmlspecialchars(isset($config["station"][$key]) ? $config["station"][$key] : ""); ?>">
			</label>
		<?php } ?>
		</div>
	</section>
	<section class="pisky-glass pisky-panel">
		<div class="pisky-panel-heading"><div><span class="pisky-eyebrow">Page introductions</span><h2>Visitor-facing copy</h2></div></div>
		<div class="pisky-form-grid">
		<?php foreach (array("overview" => "Overview", "live" => "Sky camera", "weather" => "Weather", "flights" => "Aircraft", "archive" => "Archive") as $key => $label) { ?>
			<label class="pisky-field">
				<span><?php echo $label; ?> introduction</span>
				<textarea class="form-control" name="intro_<?php echo $key; ?>" rows="3"><?php echo htmlspecialchars($config["page_intro"][$key]); ?></textarea>
			</label>
		<?php } ?>
		</div>
	</section>
	<section class="pisky-glass pisky-panel">
		<div class="pisky-panel-heading"><div><span class="pisky-eyebrow">About this station</span><h2>Story and equipment</h2></div></div>
		<label class="pisky-field pisky-field-wide">
			<span>About heading</span>
			<input class="form-control" type="text" name="about_title" value="<?php echo htmlspecialchars($config["about"]["title"]); ?>">
		</label>
		<label class="pisky-field pisky-field-wide">
			<span>About content</span>
			<div class="pisky-wysiwyg-toolbar" aria-label="Text formatting">
				<button type="button" data-command="bold"><b>Bold</b></button>
				<button type="button" data-command="italic"><i>Italic</i></button>
				<button type="button" data-command="insertUnorderedList">List</button>
				<button type="button" data-command="formatBlock" data-value="h3">Heading</button>
			</div>
			<div class="pisky-wysiwyg" contenteditable="true" data-pisky-wysiwyg><?php echo $config["about"]["body"]; ?></div>
			<textarea hidden name="about_body" data-pisky-wysiwyg-input><?php echo htmlspecialchars($config["about"]["body"]); ?></textarea>
		</label>
		<div class="pisky-form-grid">
		<?php foreach (array(
			"camera" => "Camera model/build",
			"weather_station" => "Weather station",
			"adsb_receiver" => "ADS-B receiver",
			"antenna" => "Antenna",
			"receiver_height" => "Receiver/antenna height",
			"build_notes" => "Build notes"
		) as $key => $label) { ?>
			<label class="pisky-field <?php echo $key === "build_notes" ? "pisky-field-wide" : ""; ?>">
				<span><?php echo $label; ?></span>
				<?php if ($key === "build_notes") { ?>
				<textarea class="form-control" name="equipment_<?php echo $key; ?>" rows="4"><?php echo htmlspecialchars($config["equipment"][$key]); ?></textarea>
				<?php } else { ?>
				<input class="form-control" type="text" name="equipment_<?php echo $key; ?>" value="<?php echo htmlspecialchars($config["equipment"][$key]); ?>">
				<?php } ?>
			</label>
		<?php } ?>
		</div>
	</section>
	<section class="pisky-glass pisky-panel">
		<div class="pisky-panel-heading"><div><span class="pisky-eyebrow">Gallery</span><h2>Station photos</h2></div></div>
		<div class="pisky-gallery-admin">
		<?php foreach ($config["gallery"] as $photo) { if (empty($photo["file"])) continue; ?>
			<figure>
				<img src="<?php echo htmlspecialchars(pisky_site_media_url($photo["file"])); ?>" alt="">
				<figcaption><?php echo htmlspecialchars(isset($photo["caption"]) ? $photo["caption"] : ""); ?></figcaption>
				<button class="btn btn-default" type="submit" name="remove_photo" value="<?php echo htmlspecialchars($photo["file"]); ?>">Remove</button>
			</figure>
		<?php } ?>
		</div>
		<div class="pisky-form-grid">
			<label class="pisky-field"><span>Upload photo (JPEG, PNG or WebP; 8 MB max)</span><input class="form-control" type="file" name="gallery_image" accept="image/jpeg,image/png,image/webp"></label>
			<label class="pisky-field"><span>Caption</span><input class="form-control" type="text" name="gallery_caption" maxlength="180"></label>
		</div>
	</section>
	<div class="pisky-setup-savebar"><div><strong>Publish station content</strong><span>Changes appear immediately on the local public site.</span></div><button class="btn btn-primary" type="submit">Save public content</button></div>
</form>
<script>
(function () {
	var editor = document.querySelector("[data-pisky-wysiwyg]");
	var input = document.querySelector("[data-pisky-wysiwyg-input]");
	if (!editor || !input) return;
	document.querySelectorAll("[data-command]").forEach(function (button) {
		button.addEventListener("click", function () {
			document.execCommand(button.getAttribute("data-command"), false, button.getAttribute("data-value") || null);
			editor.focus();
		});
	});
	editor.closest("form").addEventListener("submit", function () { input.value = editor.innerHTML; });
}());
</script>
<?php
}
?>
