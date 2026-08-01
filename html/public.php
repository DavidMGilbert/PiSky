<?php
/*
 * PiSky modular public observation interface.
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

include_once("includes/functions.php");
include_once("includes/status_messages.php");
$status = new StatusMessages();
$lastChangedName = "lastchanged";
initialize_variables();
include_once("includes/piskySite.php");
include_once("includes/piskyArchive.php");
include_once("includes/piskyWeatherHistory.php");

$site = pisky_site_config();
$capabilities = pisky_site_capabilities();
// The overview is the station's front door and summarises whichever modules
// are enabled. It is only worth showing when there is more than one thing to
// summarise; a single-module station goes straight to that module's own page.
$moduleCount = intval($capabilities["camera"]) + intval($capabilities["weather"])
	+ intval($capabilities["flights"]);
$availableViews = array("about");
if ($capabilities["camera"]) array_unshift($availableViews, "live");
if ($capabilities["weather"]) $availableViews[] = "weather";
// The archive now carries recorded weather as well as timelapses, so a
// weather-only station still has an archive worth visiting.
if ($capabilities["camera"] || $capabilities["weather"]) $availableViews[] = "archive";
if ($capabilities["flights"]) $availableViews[] = "flights";
if ($moduleCount > 1) array_unshift($availableViews, "overview");
$requestedView = isset($_GET["view"]) && !is_array($_GET["view"])
	? strtolower(trim($_GET["view"])) : "";
$publicView = in_array($requestedView, $availableViews, true)
	? $requestedView : $availableViews[0];

$piskyImageUrl = preg_match('#^(?:https?:)?//#i', $image_name)
	? $image_name : '/' . ltrim($image_name, '/');
// getVariableOrDefault only substitutes when the key is missing, and this one
// is present and frequently blank. The raw value is kept separate from the
// display name: the page eyebrow omits the place entirely rather than showing
// a placeholder, while the location card still needs something to head it.
$stationLocation = trim(strval(getVariableOrDefault($settings_array, "location", "")));
$observatoryName = $stationLocation !== "" ? $stationLocation : "PiSky Observatory";
// Overview card wording, editable in Public Content. Falls back to the shipped
// defaults so an upgrade never leaves a card with an empty heading.
$stationDefaults = pisky_site_defaults();
$stationCopy = array();
foreach ($stationDefaults["station"] as $stationKey => $stationDefault) {
	$stationValue = isset($site["station"][$stationKey])
		? trim(strval($site["station"][$stationKey])) : "";
	$stationCopy[$stationKey] = $stationValue !== "" ? $stationValue : $stationDefault;
}
$visitorWeatherConfig = pisky_weather_config();
$stationTimezone = isset($visitorWeatherConfig["open_meteo"]["timezone"])
	? trim($visitorWeatherConfig["open_meteo"]["timezone"]) : "auto";
if ($stationTimezone === "" || $stationTimezone === "auto") {
	$stationTimezone = is_readable("/etc/timezone")
		? trim(file_get_contents("/etc/timezone")) : date_default_timezone_get();
}
try {
	$stationTimezoneObject = new DateTimeZone($stationTimezone);
	$stationUtcOffset = $stationTimezoneObject->getOffset(new DateTime("now", $stationTimezoneObject));
} catch (Exception $exception) {
	$stationTimezone = "UTC";
	$stationUtcOffset = 0;
}

$viewTitles = array(
	"overview" => "Station overview",
	"live" => "Live sky",
	"weather" => "Weather",
	"flights" => "Aircraft",
	"archive" => "Observation archive",
	"about" => "About this station"
);
$flightsConfig = pisky_flights_config();
$coverageMap = isset($flightsConfig["coverage_map"]) ? $flightsConfig["coverage_map"] : array();
$mapLatitude = isset($coverageMap["latitude"]) && is_numeric($coverageMap["latitude"])
	? floatval($coverageMap["latitude"])
	: (isset($flightsConfig["latitude"]) && is_numeric($flightsConfig["latitude"]) ? floatval($flightsConfig["latitude"]) : null);
$mapLongitude = isset($coverageMap["longitude"]) && is_numeric($coverageMap["longitude"])
	? floatval($coverageMap["longitude"])
	: (isset($flightsConfig["longitude"]) && is_numeric($flightsConfig["longitude"]) ? floatval($flightsConfig["longitude"]) : null);
$mapZoom = max(3, min(16, intval(isset($coverageMap["zoom"]) ? $coverageMap["zoom"] : 8)));
$publicMapUrl = "";
if (!empty($coverageMap["enabled"]) && !empty($coverageMap["public"])
	&& $mapLatitude !== null && $mapLongitude !== null) {
	$longitudeSpan = 360 / pow(2, $mapZoom) * 1.8;
	$latitudeSpan = 180 / pow(2, $mapZoom) * 1.35;
	$bbox = implode(",", array(
		$mapLongitude - $longitudeSpan, max(-85, $mapLatitude - $latitudeSpan),
		$mapLongitude + $longitudeSpan, min(85, $mapLatitude + $latitudeSpan)
	));
	$publicMapUrl = "https://www.openstreetmap.org/export/embed.html?bbox="
		. rawurlencode($bbox) . "&layer=mapnik&marker="
		. rawurlencode($mapLatitude . "," . $mapLongitude);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="description" content="<?php echo htmlspecialchars($site["tagline"]); ?>">
	<meta name="author" content="PiSky station host">
	<meta name="robots" content="index,follow,max-image-preview:large">
	<title><?php echo htmlspecialchars($viewTitles[$publicView] . " · " . $observatoryName . " · PiSky"); ?></title>
	<link rel="canonical" href="/?view=<?php echo rawurlencode($publicView); ?>">
	<link href="/css/pisky.css?c=<?php echo filemtime(__DIR__ . "/css/pisky.css"); ?>" rel="stylesheet">
	<link rel="icon" type="image/svg+xml" href="/pisky-favicon.svg">
	<link rel="alternate icon" type="image/png" href="/allsky/allsky-favicon.png">
	<script defer src="/documentation/js/all.min.js"></script>
	<script src="/documentation/bower_components/jquery/dist/jquery.min.js"></script>
	<script src="/js/pisky-theme.js?c=<?php echo filemtime(__DIR__ . "/js/pisky-theme.js"); ?>"></script>
	<script src="/js/pisky-context.js?c=<?php echo filemtime(__DIR__ . "/js/pisky-context.js"); ?>"></script>
	<?php if ($capabilities["weather"]) { ?><script src="/js/pisky-weather-icons.js?c=<?php echo filemtime(__DIR__ . "/js/pisky-weather-icons.js"); ?>"></script><script src="/js/pisky-weather.js?c=<?php echo filemtime(__DIR__ . "/js/pisky-weather.js"); ?>"></script><?php } ?>
	<?php if ($capabilities["flights"]) { ?><script src="/js/pisky-flights.js?c=<?php echo filemtime(__DIR__ . "/js/pisky-flights.js"); ?>"></script><?php } ?>
	<?php if ($publicView === "archive") { ?><script src="/js/pisky-charts.js?c=<?php echo filemtime(__DIR__ . "/js/pisky-charts.js"); ?>"></script><script src="/js/pisky-history.js?c=<?php echo filemtime(__DIR__ . "/js/pisky-history.js"); ?>"></script><?php } ?>
</head>
<body class="pisky-public"
	data-pisky-view="<?php echo htmlspecialchars($publicView); ?>"
	data-pisky-timezone="<?php echo htmlspecialchars($stationTimezone); ?>"
	data-pisky-utc-offset="<?php echo intval($stationUtcOffset); ?>">
	<div class="pisky-public-orb pisky-public-orb-one"></div>
	<div class="pisky-public-orb pisky-public-orb-two"></div>
	<main class="pisky-public-shell">
		<nav class="pisky-public-nav pisky-glass" aria-label="Primary navigation">
			<a class="pisky-public-brand" href="/" aria-label="PiSky home">
				<span class="pisky-brand-mark" aria-hidden="true"><span></span></span>
				<span><strong>PiSky</strong><small><?php echo htmlspecialchars($observatoryName); ?></small></span>
			</a>
			<div class="pisky-public-links">
				<?php if ($moduleCount > 1) { ?><a class="<?php echo $publicView === "overview" ? "active" : ""; ?>" href="/?view=overview">Overview</a><?php } ?>
				<?php if ($capabilities["camera"]) { ?><a class="<?php echo $publicView === "live" ? "active" : ""; ?>" href="/?view=live">Sky</a><?php } ?>
				<?php if ($capabilities["weather"]) { ?><a class="<?php echo $publicView === "weather" ? "active" : ""; ?>" href="/?view=weather">Weather</a><?php } ?>
				<?php if ($capabilities["flights"]) { ?><a class="<?php echo $publicView === "flights" ? "active" : ""; ?>" href="/?view=flights">Aircraft</a><?php } ?>
				<?php if ($capabilities["camera"] || $capabilities["weather"]) { ?><a class="<?php echo $publicView === "archive" ? "active" : ""; ?>" href="/?view=archive">Archive</a><?php } ?>
				<a class="<?php echo $publicView === "about" ? "active" : ""; ?>" href="/?view=about">About</a>
			</div>
			<div class="pisky-public-actions">
				<button class="pisky-subtle-button pisky-public-theme" type="button" data-pisky-theme-toggle><i data-pisky-theme-icon aria-hidden="true">◐</i><span data-pisky-theme-label>Auto</span></button>
				<a class="pisky-subtle-button" href="/admin/">Admin <span aria-hidden="true">↗</span></a>
			</div>
		</nav>

		<header class="pisky-public-intro pisky-public-page-intro">
			<div>
				<span class="pisky-eyebrow"><b data-pisky-daypart-label>Today</b><?php
					// The phrase is host-editable and may name the place with a
					// {location} placeholder. With no location set the whole
					// phrase is dropped, so the line never reads "Tonight in  ·".
					$headingPhrase = trim(str_replace(
						"{location}", $stationLocation, $stationCopy["heading_phrase"]
					));
					if ($stationLocation !== "" && $headingPhrase !== "") {
						echo " " . htmlspecialchars($headingPhrase);
					}
					echo " · " . htmlspecialchars($stationCopy["brand_label"]);
				?></span>
				<div class="pisky-station-clock" data-pisky-clock>
					<strong data-pisky-clock-time>--:--</strong>
					<span data-pisky-clock-date>&nbsp;</span>
				</div>
				<h1><?php echo htmlspecialchars($viewTitles[$publicView]); ?></h1>
			</div>
			<p><?php echo htmlspecialchars($publicView === "about" ? $site["tagline"] : $site["page_intro"][$publicView]); ?></p>
		</header>

		<?php if ($publicView === "overview") { ?>

		<div class="pisky-public-grid">
			<?php if ($capabilities["camera"]) { ?>
			<section class="pisky-glass pisky-public-live-card" aria-label="Live all-sky image">
				<header class="pisky-card-heading">
					<div><span class="pisky-eyebrow" data-pisky-daypart-capture>Current capture</span><h2>Live all-sky view</h2></div>
					<div class="pisky-capture-state"><span>Auto refresh</span><strong><?php echo max(2, round($delay / 1000)); ?>s</strong></div>
				</header>
				<div id="live_container" class="pisky-live-container">
					<img id="current" class="pisky-current-image" src="<?php echo htmlspecialchars($piskyImageUrl); ?>" alt="Latest all-sky camera image">
					<div class="pisky-image-vignette"></div>
					<?php if ($capabilities["weather"]) { ?>
					<div class="pisky-image-badge pisky-image-badge-left"><span>Weather</span><strong data-pisky-weather="condition">Connecting…</strong></div>
					<div class="pisky-image-badge pisky-image-badge-right"><span>Observed</span><strong data-pisky-weather="observed_at">—</strong></div>
					<?php } ?>
				</div>
				<footer class="pisky-capture-footer">
					<div><span class="pisky-capture-dot"></span><span>Camera stream active</span></div>
					<a href="/?view=live">Open the live sky page <span aria-hidden="true">→</span></a>
				</footer>
			</section>
			<?php } ?>

			<aside class="pisky-public-conditions" id="conditions">
				<section class="pisky-glass pisky-public-time-card" data-pisky-clock data-pisky-clock-seconds aria-label="Station date and time">
					<div class="pisky-condition-heading">
						<span class="pisky-eyebrow">Station time</span>
						<span class="pisky-provider-pill" data-pisky-clock-zone>&nbsp;</span>
					</div>
					<strong data-pisky-clock-time>--:--:--</strong>
					<div class="pisky-time-card-foot">
						<span data-pisky-clock-date>&nbsp;</span>
						<b data-pisky-daypart-label>Today</b>
					</div>
				</section>

				<?php if ($capabilities["weather"]) { ?>
				<section class="pisky-glass pisky-public-condition-card">
					<div class="pisky-condition-heading"><span class="pisky-eyebrow">Conditions now</span><span class="pisky-provider-pill" data-pisky-provider>Weather</span></div>
					<div class="pisky-temperature-row">
						<div class="pisky-condition-icon" data-pisky-weather-icon aria-hidden="true"></div>
						<strong data-pisky-weather="temperature">—</strong>
						<div><span data-pisky-weather="condition">Current conditions</span><small>Feels like <b data-pisky-weather="apparent_temperature">—</b></small></div>
					</div>
					<dl class="pisky-condition-metrics">
						<div data-pisky-metric="humidity"><dt><div class="pisky-metric-glyph" data-pisky-metric-icon="humidity" aria-hidden="true"></div>Humidity</dt><dd data-pisky-weather="humidity">—</dd></div>
						<div data-pisky-metric="wind_speed"><dt><div class="pisky-metric-glyph" data-pisky-metric-icon="wind_speed" aria-hidden="true"></div>Wind</dt><dd data-pisky-weather="wind_speed">—</dd></div>
						<div data-pisky-metric="pressure"><dt><div class="pisky-metric-glyph" data-pisky-metric-icon="pressure" aria-hidden="true"></div>Pressure</dt><dd data-pisky-weather="pressure">—</dd></div>
					</dl>
				</section>
				<section class="pisky-glass pisky-public-condition-card pisky-clarity-card">
					<div class="pisky-condition-heading"><span class="pisky-eyebrow">Observing quality</span><span class="pisky-quality-label">Live</span></div>
					<div class="pisky-clarity-row">
						<div data-pisky-metric="cloud_cover"><div class="pisky-metric-glyph" data-pisky-metric-icon="cloud_cover" aria-hidden="true"></div><span>Cloud cover</span><strong data-pisky-weather="cloud_cover">—</strong></div>
						<div class="pisky-clarity-track"><i></i></div>
					</div>
					<div class="pisky-clarity-row">
						<div data-pisky-metric="visibility"><div class="pisky-metric-glyph" data-pisky-metric-icon="visibility" aria-hidden="true"></div><span>Visibility</span><strong data-pisky-weather="visibility">—</strong></div>
						<div class="pisky-clarity-track visibility"><i></i></div>
					</div>
					<footer data-pisky-weather="source">Weather source</footer>
				</section>
				<section class="pisky-glass pisky-public-condition-card">
					<div class="pisky-condition-heading"><span class="pisky-eyebrow">Sun &amp; moon</span><span class="pisky-astro-glyph" data-pisky-astro-icon="daylight" aria-hidden="true"></span><span class="pisky-wind-direction" data-pisky-weather="daylight">—</span></div>
					<dl class="pisky-condition-metrics">
						<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="sunrise" aria-hidden="true"></span>Sunrise</dt><dd data-pisky-weather="sunrise">—</dd></div>
						<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="sunset" aria-hidden="true"></span>Sunset</dt><dd data-pisky-weather="sunset">—</dd></div>
						<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="moon_phase" aria-hidden="true"></span>Moon phase</dt><dd data-pisky-weather="moon_phase">—</dd></div>
						<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="moon_phase" aria-hidden="true"></span>Illumination</dt><dd data-pisky-weather="moon_illumination">—</dd></div>
					</dl>
					<footer><a href="/?view=weather">Full weather and forecast <span aria-hidden="true">→</span></a></footer>
				</section>
				<?php } ?>
			</aside>
		</div>

		<?php if ($capabilities["flights"]) { ?>
		<section class="pisky-glass pisky-public-flight-card" id="traffic">
			<header class="pisky-card-heading">
				<div><span class="pisky-eyebrow">Local ADS-B receiver</span><h2>Aircraft above us</h2></div>
				<div class="pisky-flight-summary">
					<span class="pisky-live-pill" data-pisky-flights-status>Connecting…</span>
					<strong><b data-pisky-flights="aircraft_count">—</b> tracked</strong>
				</div>
			</header>
			<div class="pisky-public-flight-grid">
				<div class="pisky-scope-stage pisky-scope-stage-compact">
					<canvas class="pisky-scope-canvas" data-pisky-radar-canvas
						aria-label="Radar summary of aircraft received by this station"></canvas>
				</div>
				<div class="pisky-flight-list-wrap">
					<div class="pisky-flight-list-heading">
						<span>Closest live targets</span>
						<span>Nearest <b data-pisky-flights="nearest">—</b></span>
					</div>
					<table class="pisky-flight-table">
						<thead><tr><th>Flight</th><th>Altitude</th><th>Speed</th><th>Range</th></tr></thead>
						<tbody data-pisky-flight-list data-limit="7">
							<tr><td colspan="4">Waiting for the local receiver…</td></tr>
						</tbody>
					</table>
					<footer>
						<span data-pisky-flights="decoder">Local receiver</span>
						<a href="/?view=flights">Open the radar <span aria-hidden="true">→</span></a>
					</footer>
				</div>
			</div>
		</section>
		<?php } ?>

		<section class="pisky-public-context">
			<article class="pisky-glass">
				<span class="pisky-eyebrow"><?php echo htmlspecialchars($stationCopy["summary_label"]); ?></span>
				<h2><?php echo htmlspecialchars($site["tagline"]); ?></h2>
				<p>This station publishes<?php
					$summaryParts = array();
					if ($capabilities["camera"]) $summaryParts[] = "continuous all-sky imaging";
					if ($capabilities["weather"]) $summaryParts[] = "local weather observations";
					if ($capabilities["flights"]) $summaryParts[] = "aircraft positions decoded on site";
					$last = array_pop($summaryParts);
					echo " " . htmlspecialchars($summaryParts
						? implode(", ", $summaryParts) . " and " . $last : $last);
				?>.</p>
			</article>
			<article class="pisky-glass pisky-location-card">
				<div class="pisky-location-orbit"><span></span><i></i></div>
				<div>
					<span class="pisky-eyebrow"><?php echo htmlspecialchars($stationCopy["location_label"]); ?></span>
					<h3><?php echo htmlspecialchars($observatoryName); ?></h3>
					<?php if ($stationCopy["location_note"] !== "") { ?>
					<p><?php echo htmlspecialchars($stationCopy["location_note"]); ?></p>
					<?php } ?>
				</div>
			</article>
		</section>
		<?php } ?>

		<?php if ($publicView === "live") { ?>
		<section class="pisky-glass pisky-public-live-card pisky-public-page-card">
			<header class="pisky-card-heading">
				<div><span class="pisky-eyebrow" data-pisky-daypart-capture>Live capture</span><h2>Current sky view</h2></div>
				<div class="pisky-capture-state"><span>Auto refresh</span><strong><?php echo max(2, round($delay / 1000)); ?>s</strong></div>
			</header>
			<div id="live_container" class="pisky-live-container">
				<img id="current" class="pisky-current-image" src="<?php echo htmlspecialchars($piskyImageUrl, ENT_QUOTES, "UTF-8"); ?>" alt="Latest sky camera image">
				<div class="pisky-image-vignette"></div>
				<div class="pisky-sky-lens" aria-hidden="true"><img src="<?php echo htmlspecialchars($piskyImageUrl, ENT_QUOTES, "UTF-8"); ?>" alt=""></div>
				<?php if ($capabilities["weather"]) { ?>
				<div class="pisky-image-badge pisky-image-badge-left"><span>Weather</span><strong data-pisky-weather="condition">Connecting…</strong></div>
				<div class="pisky-image-badge pisky-image-badge-right"><span>Observed</span><strong data-pisky-weather="observed_at">—</strong></div>
				<?php } ?>
			</div>
			<footer class="pisky-capture-footer"><div><span class="pisky-capture-dot"></span><span>Camera stream active</span></div><a href="/?view=archive">Browse archive →</a></footer>
		</section>
		<section class="pisky-glass pisky-panel pisky-astronomy-panel">
			<div class="pisky-panel-heading"><div><span class="pisky-eyebrow">Sky clock</span><h2>Sun and Moon</h2></div></div>
			<?php if ($capabilities["weather"]) { ?>
			<dl class="pisky-astronomy-grid">
				<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="sunrise" aria-hidden="true"></span>Sunrise</dt><dd data-pisky-weather="sunrise">—</dd></div><div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="sunset" aria-hidden="true"></span>Sunset</dt><dd data-pisky-weather="sunset">—</dd></div>
				<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="daylight" aria-hidden="true"></span>Daylight</dt><dd data-pisky-weather="daylight">—</dd></div><div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="moon_phase" aria-hidden="true"></span>Moon phase</dt><dd data-pisky-weather="moon_phase">—</dd></div>
				<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="moon_phase" aria-hidden="true"></span>Moon illuminated</dt><dd data-pisky-weather="moon_illumination">—</dd></div>
			</dl>
			<?php } else { ?><p>Enable Weather for location-aware sunrise, sunset and lunar context.</p><?php } ?>
		</section>
		<?php } ?>

		<?php if ($publicView === "weather") { ?>
		<div class="pisky-metric-grid pisky-weather-metrics">
			<div class="pisky-glass pisky-metric" data-pisky-metric="temperature"><div class="pisky-metric-glyph" data-pisky-metric-icon="temperature" aria-hidden="true"></div><span>Temperature</span><strong data-pisky-weather="temperature">—</strong><small>Feels like <b data-pisky-weather="apparent_temperature">—</b></small></div>
			<div class="pisky-glass pisky-metric" data-pisky-metric="humidity"><div class="pisky-metric-glyph" data-pisky-metric-icon="humidity" aria-hidden="true"></div><span>Humidity</span><strong data-pisky-weather="humidity">—</strong><small>Dew point <b data-pisky-weather="dew_point">—</b></small></div>
			<div class="pisky-glass pisky-metric" data-pisky-metric="wind_speed"><div class="pisky-metric-glyph" data-pisky-metric-icon="wind_speed" aria-hidden="true"></div><span>Wind</span><strong data-pisky-weather="wind_speed">—</strong><small>Gust <b data-pisky-weather="wind_gust">—</b></small></div>
			<div class="pisky-glass pisky-metric" data-pisky-metric="pressure"><div class="pisky-metric-glyph" data-pisky-metric-icon="pressure" aria-hidden="true"></div><span>Pressure</span><strong data-pisky-weather="pressure">—</strong><small data-pisky-weather="condition">Current conditions</small></div>
		</div>
		<section class="pisky-glass pisky-panel">
			<div class="pisky-panel-heading"><div><span class="pisky-eyebrow">Current observation</span><h2 data-pisky-weather="condition">Connecting…</h2></div><span class="pisky-live-pill" data-pisky-weather-status>Connecting…</span></div>
			<dl class="pisky-astronomy-grid pisky-weather-detail-grid">
				<div data-pisky-metric="rain"><dt><div class="pisky-metric-glyph" data-pisky-metric-icon="rain" aria-hidden="true"></div>Rain</dt><dd data-pisky-weather="rain">—</dd></div><div data-pisky-metric="cloud_cover"><dt><div class="pisky-metric-glyph" data-pisky-metric-icon="cloud_cover" aria-hidden="true"></div>Cloud cover</dt><dd data-pisky-weather="cloud_cover">—</dd></div>
				<div data-pisky-metric="visibility"><dt><div class="pisky-metric-glyph" data-pisky-metric-icon="visibility" aria-hidden="true"></div>Visibility</dt><dd data-pisky-weather="visibility">—</dd></div><div data-pisky-metric="wind_direction"><dt><div class="pisky-metric-glyph" data-pisky-metric-icon="wind_direction" aria-hidden="true"></div>Wind direction</dt><dd data-pisky-weather="wind_direction">—</dd></div>
				<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="sunrise" aria-hidden="true"></span>Sunrise</dt><dd data-pisky-weather="sunrise">—</dd></div><div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="sunset" aria-hidden="true"></span>Sunset</dt><dd data-pisky-weather="sunset">—</dd></div>
				<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="moon_phase" aria-hidden="true"></span>Moon</dt><dd data-pisky-weather="moon_phase">—</dd></div><div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="moon_phase" aria-hidden="true"></span>Illumination</dt><dd data-pisky-weather="moon_illumination">—</dd></div>
			</dl>
			<footer class="pisky-data-attribution" data-pisky-weather="source">Weather source</footer>
		</section>
		<section class="pisky-glass pisky-panel pisky-sensor-panel"><div class="pisky-panel-heading"><div><span class="pisky-eyebrow">Station and sensor nodes</span><h2>Additional observations</h2></div></div><div class="pisky-observation-grid" data-pisky-observations hidden></div></section>
		<section class="pisky-glass pisky-panel pisky-forecast-panel"><div class="pisky-panel-heading"><div><span class="pisky-eyebrow">Seven-day outlook</span><h2>Forecast</h2></div></div><div class="pisky-daily-forecast" data-pisky-daily-forecast><p>Loading forecast…</p></div></section>
		<?php } ?>

		<?php if ($publicView === "flights") { ?>
		<section class="pisky-scope-stage" aria-label="Live aircraft radar">
			<canvas class="pisky-scope-canvas" data-pisky-radar-canvas
				aria-label="Radar display of aircraft received by this station"></canvas>
			<div class="pisky-scope-tag pisky-glass" data-pisky-radar-tag aria-hidden="true"></div>

			<div class="pisky-scope-overlay">
				<div class="pisky-scope-stats pisky-glass">
					<div class="pisky-scope-stat"><span>Aircraft</span><strong data-pisky-flights="aircraft_count">—</strong></div>
					<div class="pisky-scope-stat"><span>Positions</span><strong data-pisky-flights="positioned_count">—</strong></div>
					<div class="pisky-scope-stat"><span>Nearest</span><strong data-pisky-flights="nearest">—</strong></div>
					<div class="pisky-scope-stat"><span>Range</span><strong data-pisky-flights="range">—</strong></div>
				</div>

				<div class="pisky-scope-list pisky-glass">
					<header>
						<span class="pisky-eyebrow">In range</span>
						<small data-pisky-flights-status>Connecting…</small>
					</header>
					<div class="pisky-scope-rows">
						<table class="pisky-flight-table pisky-scope-table">
							<tbody data-pisky-flight-list data-limit="30">
								<tr><td colspan="4">Waiting for receiver…</td></tr>
							</tbody>
						</table>
					</div>
				</div>

				<aside class="pisky-scope-detail pisky-glass" data-pisky-flight-detail>
					<span class="pisky-eyebrow">Select an aircraft</span>
					<h3>Flight and aircraft details</h3>
					<p>Choose a target on the radar or in the list to inspect locally decoded information.</p>
				</aside>

				<?php
				// Inside the overlay grid rather than pinned to the bottom of
				// the stage. Absolutely positioned it sat underneath the target
				// list, which reaches the same edge, and the attribution and
				// update time were covered up.
				?>
				<footer class="pisky-scope-credit">
					<span data-pisky-flights="decoder">Local receiver</span>
					<span>Updated <b data-pisky-flights="observed_at">—</b></span>
					<span class="pisky-map-attribution">Map © OpenStreetMap contributors</span>
				</footer>
			</div>
		</section>
		<?php } ?>

		<?php if ($publicView === "archive") {
			$archiveSearch = isset($_GET["search"]) && !is_array($_GET["search"]) ? trim($_GET["search"]) : "";
			$archiveDayName = isset($_GET["day"]) && !is_array($_GET["day"]) ? pisky_archive_safe_day($_GET["day"]) : "";
			$archiveDay = $archiveDayName !== "" ? pisky_archive_day($archiveDayName) : null;
			$archiveHistory = $capabilities["weather"] && $archiveDayName !== ""
				? pisky_history_day($archiveDayName, $settings_array) : null;
			$historyUnits = $archiveHistory !== null && !empty($archiveHistory["units"])
				? $archiveHistory["units"] : array();
			$historyValue = function ($value, $unit, $decimals = 1) {
				if ($value === null || $value === "") return "—";
				return number_format(floatval($value), $decimals) . ($unit !== "" ? " " . $unit : "");
			};
		?>
		<form class="pisky-glass pisky-archive-search" method="get"><input type="hidden" name="view" value="archive"><label><span>Search by date</span><input type="search" name="search" placeholder="YYYY, YYYYMM or YYYYMMDD" value="<?php echo htmlspecialchars($archiveSearch); ?>"></label><button type="submit">Search archive</button></form>

		<?php if ($archiveDayName !== "") { ?>
		<section class="pisky-glass pisky-panel"><div class="pisky-panel-heading"><div><span class="pisky-eyebrow">Selected observation day</span><h2><?php echo htmlspecialchars(DateTime::createFromFormat("Ymd", $archiveDayName)->format("j F Y")); ?></h2></div><a href="/?view=archive">All dates</a></div>
			<?php if ($archiveDay && count($archiveDay["videos"])) { ?>
			<div class="pisky-archive-videos"><?php foreach ($archiveDay["videos"] as $video) { ?><video controls preload="metadata"<?php if (!empty($archiveDay["poster"])) { ?> poster="<?php echo htmlspecialchars(pisky_archive_url($archiveDayName, $archiveDay["poster"])); ?>"<?php } ?>><source src="<?php echo htmlspecialchars(pisky_archive_url($archiveDayName, $video)); ?>"></video><?php } ?></div>
			<?php } else if ($capabilities["camera"]) { ?>
			<p class="pisky-archive-empty">No timelapse was produced for this date.</p>
			<?php } ?>
		</section>
		<?php } ?>

		<?php if ($archiveHistory !== null) { ?>
		<section class="pisky-glass pisky-panel pisky-history-day">
			<div class="pisky-panel-heading">
				<div><span class="pisky-eyebrow">Weather that day</span><h2><?php echo htmlspecialchars($archiveHistory["condition"] !== null ? $archiveHistory["condition"] : "Recorded conditions"); ?></h2></div>
				<?php if ($archiveHistory["origin"] === "archive") { ?><span class="pisky-provider-pill" title="This date predates local recording, so it comes from Open-Meteo's historical archive rather than the station.">Reanalysis</span><?php } else { ?><span class="pisky-provider-pill">Station record</span><?php } ?>
			</div>
			<dl class="pisky-history-metrics">
				<div><dt>High</dt><dd><?php echo htmlspecialchars($historyValue($archiveHistory["temperature_max"], isset($historyUnits["temperature"]) ? $historyUnits["temperature"] : "°C")); ?></dd></div>
				<div><dt>Low</dt><dd><?php echo htmlspecialchars($historyValue($archiveHistory["temperature_min"], isset($historyUnits["temperature"]) ? $historyUnits["temperature"] : "°C")); ?></dd></div>
				<div><dt>Mean</dt><dd><?php echo htmlspecialchars($historyValue($archiveHistory["temperature_avg"], isset($historyUnits["temperature"]) ? $historyUnits["temperature"] : "°C")); ?></dd></div>
				<div><dt>Humidity</dt><dd><?php echo htmlspecialchars($historyValue($archiveHistory["humidity_avg"], "%", 0)); ?></dd></div>
				<div><dt>Pressure</dt><dd><?php echo htmlspecialchars($historyValue($archiveHistory["pressure_avg"], isset($historyUnits["pressure"]) ? $historyUnits["pressure"] : "hPa", 0)); ?></dd></div>
				<div><dt>Wind</dt><dd><?php echo htmlspecialchars($historyValue($archiveHistory["wind_speed_avg"], isset($historyUnits["wind_speed"]) ? $historyUnits["wind_speed"] : "km/h")); ?></dd></div>
				<div><dt>Peak gust</dt><dd><?php echo htmlspecialchars($historyValue($archiveHistory["wind_gust_max"], isset($historyUnits["wind_speed"]) ? $historyUnits["wind_speed"] : "km/h")); ?></dd></div>
				<div><dt>Rain</dt><dd><?php echo htmlspecialchars($historyValue($archiveHistory["rain_total"], isset($historyUnits["rain"]) ? $historyUnits["rain"] : "mm")); ?></dd></div>
			</dl>
			<?php if ($archiveHistory["origin"] === "archive") { ?><small class="pisky-map-attribution">Historical data by Open-Meteo.com · CC BY 4.0. This date predates local recording.</small><?php } ?>
		</section>

		<?php
		// Charts sit beneath the summary and are filled by the history client.
		// Each panel hides itself when the day has nothing to plot for it, so a
		// station without a UV sensor is not shown an empty UV chart.
		$chartPanels = array(
			"temperature" => "Temperature",
			"humidity" => "Humidity and cloud",
			"pressure" => "Pressure",
			"wind" => "Wind",
			"rain" => "Rainfall",
			"sun" => "Sun and UV"
		);
		?>
		<section class="pisky-glass pisky-panel pisky-history-charts" data-pisky-history-day="<?php echo htmlspecialchars($archiveDayName); ?>">
			<div class="pisky-panel-heading">
				<div><span class="pisky-eyebrow">Through the day</span><h2>Recorded readings</h2></div>
				<small data-pisky-chart-note>Loading recorded readings…</small>
			</div>
			<div class="pisky-chart-grid">
				<?php foreach ($chartPanels as $chartKey => $chartLabel) { ?>
				<article data-pisky-chart-panel hidden>
					<header>
						<h3><?php echo htmlspecialchars($chartLabel); ?></h3>
						<div class="pisky-chart-readout" data-pisky-chart-readout></div>
					</header>
					<canvas data-pisky-chart="<?php echo htmlspecialchars($chartKey); ?>"
						aria-label="<?php echo htmlspecialchars($chartLabel); ?> through the day"></canvas>
				</article>
				<?php } ?>
			</div>
		</section>
		<?php } else if ($archiveDayName !== "" && $capabilities["weather"]) { ?>
		<section class="pisky-glass pisky-panel"><h2>No weather record</h2><p>PiSky has no recorded weather for this date, and the historical service did not return one.</p></section>
		<?php } ?>

		<?php if ($archiveDayName === "") {
			$archiveDays = $capabilities["camera"] ? pisky_archive_days($archiveSearch) : array();
			$historyDays = $capabilities["weather"] ? pisky_history_days($archiveSearch) : array();
		?>
		<?php if (count($archiveDays)) { ?>
		<div class="pisky-archive-day-grid"><?php foreach ($archiveDays as $dayInfo) { ?><a class="pisky-glass pisky-archive-day" href="/?view=archive&amp;day=<?php echo rawurlencode($dayInfo["day"]); ?>"><?php if (!empty($dayInfo["poster_url"])) { ?><img loading="lazy" src="<?php echo htmlspecialchars($dayInfo["poster_url"]); ?>" alt=""><?php } ?><div><strong><?php echo htmlspecialchars(DateTime::createFromFormat("Ymd", $dayInfo["day"])->format("j M Y")); ?></strong><span><?php echo intval($dayInfo["video_count"]); ?> timelapse<?php echo intval($dayInfo["video_count"]) === 1 ? "" : "s"; ?></span></div></a><?php } ?></div>
		<?php } ?>

		<?php if (count($historyDays)) { ?>
		<section class="pisky-glass pisky-panel">
			<div class="pisky-panel-heading"><div><span class="pisky-eyebrow">Weather history</span><h2>Recorded days</h2></div><span><?php echo count($historyDays); ?> day<?php echo count($historyDays) === 1 ? "" : "s"; ?></span></div>
			<div class="pisky-flight-table-scroll">
				<table class="pisky-flight-table pisky-history-table">
					<thead><tr><th>Date</th><th>Conditions</th><th>High</th><th>Low</th><th>Rain</th></tr></thead>
					<tbody>
					<?php foreach ($historyDays as $record) {
						$units = !empty($record["units"]) ? $record["units"] : array();
					?>
						<tr>
							<td><a href="/?view=archive&amp;day=<?php echo rawurlencode($record["date"]); ?>"><?php echo htmlspecialchars(DateTime::createFromFormat("Ymd", $record["date"])->format("j M Y")); ?></a></td>
							<td><?php echo htmlspecialchars($record["condition"] !== null ? $record["condition"] : "—"); ?></td>
							<td><?php echo htmlspecialchars($historyValue($record["temperature_max"], isset($units["temperature"]) ? $units["temperature"] : "°C")); ?></td>
							<td><?php echo htmlspecialchars($historyValue($record["temperature_min"], isset($units["temperature"]) ? $units["temperature"] : "°C")); ?></td>
							<td><?php echo htmlspecialchars($historyValue($record["rain_total"], isset($units["rain"]) ? $units["rain"] : "mm")); ?></td>
						</tr>
					<?php } ?>
					</tbody>
				</table>
			</div>
		</section>
		<?php } ?>

		<?php if (!count($archiveDays) && !count($historyDays)) { ?>
		<section class="pisky-glass pisky-panel"><h2>Nothing archived yet</h2><p><?php echo $archiveSearch !== "" ? "No date matched that search." : "Timelapses appear here once Allsky has produced them, and weather history builds up as PiSky records each day. Open a specific date to look up historical weather from before PiSky was installed."; ?></p></section>
		<?php } } ?>
		<?php } ?>

		<?php if ($publicView === "about") { ?>
		<section class="pisky-glass pisky-panel pisky-about-content"><span class="pisky-eyebrow">Station profile</span><h2><?php echo htmlspecialchars($site["about"]["title"]); ?></h2><div class="pisky-rich-content"><?php echo pisky_site_clean_html($site["about"]["body"]); ?></div></section>
		<?php $equipmentLabels = array("camera" => "Camera", "weather_station" => "Weather station", "adsb_receiver" => "Aircraft receiver", "antenna" => "Antenna", "receiver_height" => "Receiver height", "build_notes" => "Build notes"); ?>
		<dl class="pisky-glass pisky-equipment-grid"><?php foreach ($equipmentLabels as $key => $label) { if (empty($site["equipment"][$key])) continue; ?><div><dt><?php echo $label; ?></dt><dd><?php echo nl2br(htmlspecialchars($site["equipment"][$key])); ?></dd></div><?php } ?></dl>
		<?php if (count($site["gallery"])) { ?><div class="pisky-station-gallery"><?php foreach ($site["gallery"] as $photo) { if (empty($photo["file"])) continue; ?><figure class="pisky-glass"><img loading="lazy" src="<?php echo htmlspecialchars(pisky_site_media_url($photo["file"])); ?>" alt="<?php echo htmlspecialchars(isset($photo["caption"]) ? $photo["caption"] : "Station photo"); ?>"><figcaption><?php echo htmlspecialchars(isset($photo["caption"]) ? $photo["caption"] : ""); ?></figcaption></figure><?php } ?></div><?php } ?>
		<?php } ?>

		<footer class="pisky-public-footer">
			<span>Powered by <a href="https://www.pisky.space/" target="_blank" rel="noopener">PiSky</a> · a modular sky-observation platform</span>
			<span><a class="pisky-build-link" href="https://pisky.space/" target="_blank" rel="noopener">Build your own PiSky Observatory →</a></span>
			<span>Station content provided by its host<?php if ($capabilities["weather"]) { ?> · forecast data by <a href="https://open-meteo.com/" target="_blank" rel="noopener">Open-Meteo</a><?php } ?></span>
		</footer>
	</main>
	<?php if ($capabilities["camera"] && ($publicView === "live" || $publicView === "overview")) { ?>
	<script>
	(function refreshImage() {
		var newImg = new Image();
		newImg.src = <?php echo json_encode($piskyImageUrl); ?> + "?_ts=" + Date.now();
		newImg.id = "current";
		newImg.className = "pisky-current-image";
		newImg.alt = "Latest sky camera image";
		newImg.onload = function () {
			var current = document.querySelector("#live_container .pisky-current-image");
			if (current) current.replaceWith(newImg);
			var lens = document.querySelector(".pisky-sky-lens img");
			if (lens) lens.src = newImg.src;
			window.setTimeout(refreshImage, <?php echo intval($delay); ?>);
		};
		newImg.onerror = function () { window.setTimeout(refreshImage, <?php echo intval($delay); ?>); };
	}());
	</script>
	<?php } ?>
</body>
</html>
