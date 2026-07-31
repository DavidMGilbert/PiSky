<?php
/*
 * PiSky weather administration view
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

include_once(__DIR__ . "/piskyWeather.php");
include_once(__DIR__ . "/piskyFlights.php");
include_once(__DIR__ . "/piskyAdmin.php");

/*
 * Which metrics this station can currently produce.
 *
 * The fixed registry covers readings every provider reports. Supplementary
 * observations are read from the live payload instead, because which ones
 * exist depends on the station's sensors, and a host can only sensibly toggle
 * something their hardware actually reports.
 */
function pisky_weather_toggleable_metrics($weather) {
	$metrics = pisky_weather_metric_registry();
	if (isset($weather["observations"]) && is_array($weather["observations"])) {
		foreach ($weather["observations"] as $observation) {
			if (empty($observation["id"])) continue;
			$metrics[$observation["id"]] = !empty($observation["label"])
				? $observation["label"] : $observation["id"];
		}
	}
	return $metrics;
}

/*
 * Persist the visibility choices. The posted form lists every metric it drew
 * as a hidden field, so a metric whose checkbox is absent was switched off
 * rather than simply not rendered on this request.
 */
function pisky_weather_save_visibility(&$notice, &$notceIsError) {
	$config = pisky_weather_config();
	$known = isset($_POST["pisky_metric_known"]) && is_array($_POST["pisky_metric_known"])
		? $_POST["pisky_metric_known"] : array();
	$enabled = isset($_POST["pisky_metric"]) && is_array($_POST["pisky_metric"])
		? $_POST["pisky_metric"] : array();
	if (!count($known)) {
		$notice = "No metrics were submitted, so nothing was changed.";
		$notceIsError = true;
		return;
	}

	$visibility = isset($config["public_metrics"]) && is_array($config["public_metrics"])
		? $config["public_metrics"] : array();
	foreach ($known as $id) {
		$id = strval($id);
		// Ignore anything that is not a plausible metric identifier so a
		// crafted post cannot grow the configuration file arbitrarily.
		if (!preg_match("/^[a-z0-9_]{1,40}$/", $id)) continue;
		$visibility[$id] = in_array($id, $enabled, true);
	}

	$config["public_metrics"] = $visibility;
	$message = "";
	$ok = pisky_admin_apply_configs($config, pisky_flights_config(), $message);
	$notice = $message !== "" ? $message
		: ($ok ? "Public metric visibility saved." : "Visibility could not be saved.");
	$notceIsError = !$ok;
}

function DisplayPiSkyWeather() {
	$notice = "";
	$noticeIsError = false;
	global $useLogin;
	if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["pisky_metric_visibility"])) {
		if ($useLogin && !CSRFValidate()) {
			$notice = "The security token was invalid. Please try again.";
			$noticeIsError = true;
		} else {
			pisky_weather_save_visibility($notice, $noticeIsError);
		}
	}

	$configPath = htmlspecialchars(pisky_weather_config_path());
	$config = pisky_weather_config();
	$provider = isset($config["provider"]) ? strtolower($config["provider"]) : "open-meteo";
	$providerLabel = $provider === "weewx" ? "Local WeeWX station" : "Open-Meteo";
	$liveWeather = pisky_get_weather(isset($GLOBALS["settings_array"]) ? $GLOBALS["settings_array"] : array());
	$toggleable = pisky_weather_toggleable_metrics($liveWeather);
	$hiddenCount = 0;
	foreach (array_keys($toggleable) as $metricId) {
		if (!pisky_weather_metric_visible($config, $metricId)) $hiddenCount++;
	}

	// Renders the switch shown in the top-right corner of a metric card.
	$metricToggle = function ($id) use ($config) {
		$visible = pisky_weather_metric_visible($config, $id);
		echo '<label class="pisky-metric-toggle" title="'
			. ($visible ? 'Visible on the public site' : 'Hidden from the public site')
			. '"><input type="checkbox" name="pisky_metric[]" value="' . htmlspecialchars($id) . '"'
			. ($visible ? ' checked' : '') . '><span aria-hidden="true"></span>'
			. '<b class="sr-only">Show ' . htmlspecialchars($id) . ' publicly</b></label>'
			. '<input type="hidden" name="pisky_metric_known[]" value="' . htmlspecialchars($id) . '">';
	};
?>

<div class="pisky-page-heading">
	<div>
		<span class="pisky-eyebrow">Observatory conditions</span>
		<h1>Weather</h1>
		<p>Monitor forecast, on-site station and additional environmental observations.</p>
	</div>
	<div class="pisky-heading-actions">
		<span class="pisky-live-pill" data-pisky-weather-status>Connecting…</span>
	</div>
</div>

<?php if ($notice !== "") { ?>
<div class="pisky-glass pisky-inline-notice<?php echo $noticeIsError ? " is-error" : " is-success"; ?>">
	<?php echo htmlspecialchars($notice); ?>
</div>
<?php } ?>

<form method="post" class="pisky-metric-visibility-form">
	<?php if ($useLogin) CSRFToken(); ?>
	<input type="hidden" name="pisky_metric_visibility" value="1">

	<div class="pisky-visibility-bar pisky-glass">
		<div>
			<span class="pisky-eyebrow">Public visibility</span>
			<strong>Choose which readings visitors see</strong>
			<small><?php echo $hiddenCount === 0
				? "Every available reading is published."
				: $hiddenCount . " reading" . ($hiddenCount === 1 ? " is" : "s are") . " hidden from the public site."; ?></small>
		</div>
		<button type="submit" class="btn btn-primary">Save visibility</button>
	</div>

	<div class="pisky-metric-grid pisky-weather-metrics">
		<div class="pisky-glass pisky-metric"<?php echo pisky_weather_metric_visible($config, "temperature") ? "" : " data-pisky-hidden"; ?>>
			<?php $metricToggle("temperature"); ?>
			<div class="pisky-metric-glyph" data-pisky-metric-icon="temperature" aria-hidden="true"></div>
			<span>Temperature</span>
			<strong data-pisky-weather="temperature">—</strong>
			<small>Feels like <b data-pisky-weather="apparent_temperature">—</b></small>
		</div>
		<div class="pisky-glass pisky-metric"<?php echo pisky_weather_metric_visible($config, "humidity") ? "" : " data-pisky-hidden"; ?>>
			<?php $metricToggle("humidity"); ?>
			<div class="pisky-metric-glyph" data-pisky-metric-icon="humidity" aria-hidden="true"></div>
			<span>Humidity</span>
			<strong data-pisky-weather="humidity">—</strong>
			<small>Dew point <b data-pisky-weather="dew_point">—</b></small>
		</div>
		<div class="pisky-glass pisky-metric"<?php echo pisky_weather_metric_visible($config, "wind_speed") ? "" : " data-pisky-hidden"; ?>>
			<?php $metricToggle("wind_speed"); ?>
			<div class="pisky-metric-glyph" data-pisky-metric-icon="wind_speed" aria-hidden="true"></div>
			<span>Wind</span>
			<strong data-pisky-weather="wind_speed">—</strong>
			<small>Gusting <b data-pisky-weather="wind_gust">—</b></small>
		</div>
		<div class="pisky-glass pisky-metric"<?php echo pisky_weather_metric_visible($config, "cloud_cover") ? "" : " data-pisky-hidden"; ?>>
			<?php $metricToggle("cloud_cover"); ?>
			<div class="pisky-metric-glyph" data-pisky-metric-icon="cloud_cover" aria-hidden="true"></div>
			<span>Cloud cover</span>
			<strong data-pisky-weather="cloud_cover">—</strong>
			<small data-pisky-weather="condition">Current conditions</small>
		</div>
	</div>

<div class="row pisky-weather-admin-grid">
	<div class="col-lg-8">
		<section class="pisky-glass pisky-panel">
			<div class="pisky-panel-heading">
				<div>
					<span class="pisky-eyebrow">Live observation</span>
					<h2 data-pisky-weather="condition">Current conditions</h2>
				</div>
				<span class="pisky-provider-pill" data-pisky-provider><?php echo htmlspecialchars($providerLabel); ?></span>
			</div>
			<div class="pisky-condition-map">
				<div class="pisky-weather-orb"><span></span><i></i></div>
				<div class="pisky-condition-primary">
					<div class="pisky-condition-icon" data-pisky-weather-icon aria-hidden="true"></div>
					<strong data-pisky-weather="temperature">—</strong>
					<span>Observed at <b data-pisky-weather="observed_at">—</b></span>
				</div>
				<dl class="pisky-toggleable-list">
					<?php foreach (array(
						"pressure" => "Pressure",
						"rain" => "Rain",
						"visibility" => "Visibility",
						"wind_direction" => "Wind direction"
					) as $metricId => $metricLabel) { ?>
					<div<?php echo pisky_weather_metric_visible($config, $metricId) ? "" : " data-pisky-hidden"; ?>>
						<dt><span class="pisky-metric-glyph" data-pisky-metric-icon="<?php echo htmlspecialchars($metricId); ?>" aria-hidden="true"></span><?php echo htmlspecialchars($metricLabel); ?></dt>
						<dd data-pisky-weather="<?php echo htmlspecialchars($metricId); ?>">—</dd>
						<?php $metricToggle($metricId); ?>
					</div>
					<?php } ?>
				</dl>
			</div>
			<footer class="pisky-data-attribution" data-pisky-weather="source">
				Weather source
			</footer>
		</section>
	</div>
	<div class="col-lg-4">
		<section class="pisky-glass pisky-panel pisky-provider-panel">
			<div class="pisky-panel-heading">
				<div>
					<span class="pisky-eyebrow">Data provider</span>
					<h2><?php echo htmlspecialchars($providerLabel); ?></h2>
				</div>
			</div>
			<p>
				<?php if ($provider === "weewx") { ?>
					PiSky is reading normalized live observations from your local WeeWX output.
				<?php } else { ?>
					Free non-commercial forecast data is loaded using the camera coordinates in PiSky.
				<?php } ?>
			</p>
			<div class="pisky-provider-choice">
				<div class="<?php echo $provider === "open-meteo" ? "active" : ""; ?>">
					<i class="fa fa-cloud"></i>
					<span><strong>Open-Meteo</strong><small>Forecast · no API key</small></span>
				</div>
				<div class="<?php echo $provider === "weewx" ? "active" : ""; ?>">
					<i class="fa fa-broadcast-tower"></i>
					<span><strong>WeeWX</strong><small>Local real-time station</small></span>
				</div>
			</div>
			<div class="pisky-config-note">
				<span>Configuration file</span>
				<code><?php echo $configPath; ?></code>
			</div>
			<a class="btn btn-primary btn-block" href="/admin/?page=pisky_setup">Open PiSky setup</a>
		</section>
	</div>
</div>

<section class="pisky-glass pisky-panel pisky-forecast-panel">
	<div class="pisky-panel-heading">
		<div>
			<span class="pisky-eyebrow">Seven-day outlook</span>
			<h2>Forecast for this location</h2>
		</div>
		<span class="pisky-provider-pill">Open-Meteo forecast</span>
	</div>
	<div class="pisky-daily-forecast" data-pisky-daily-forecast>
		<p class="pisky-forecast-empty">Loading the forecast…</p>
	</div>
</section>

<section class="pisky-glass pisky-panel pisky-astronomy-panel">
	<div class="pisky-panel-heading">
		<div>
			<span class="pisky-eyebrow">Sky clock</span>
			<h2>Sun and Moon today</h2>
		</div>
	</div>
	<dl class="pisky-astronomy-grid">
		<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="sunrise" aria-hidden="true"></span>Sunrise</dt><dd data-pisky-weather="sunrise">—</dd></div>
		<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="sunset" aria-hidden="true"></span>Sunset</dt><dd data-pisky-weather="sunset">—</dd></div>
		<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="daylight" aria-hidden="true"></span>Daylight</dt><dd data-pisky-weather="daylight">—</dd></div>
		<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="moon_phase" aria-hidden="true"></span>Moon phase</dt><dd data-pisky-weather="moon_phase">—</dd></div>
		<div><dt><span class="pisky-astro-glyph" data-pisky-astro-icon="moon_phase" aria-hidden="true"></span>Moon illuminated</dt><dd data-pisky-weather="moon_illumination">—</dd></div>
	</dl>
</section>

<section class="pisky-glass pisky-panel pisky-sensor-panel">
	<div class="pisky-panel-heading">
		<div>
			<span class="pisky-eyebrow">Station and sensor nodes</span>
			<h2>Additional observations</h2>
		</div>
	</div>
	<?php
	// The client renders these cards, so it is told which readings are
	// currently hidden in order to draw each switch in the right state.
	$hiddenObservationIds = array();
	foreach (array_keys($toggleable) as $metricId) {
		if (!pisky_weather_metric_visible($config, $metricId)) $hiddenObservationIds[] = $metricId;
	}
	?>
	<div class="pisky-observation-grid" data-pisky-observations hidden
		data-pisky-metric-visibility
		data-pisky-metric-hidden="<?php echo htmlspecialchars(implode(",", $hiddenObservationIds)); ?>"></div>
	<p class="pisky-form-intro">Compatible WeeWX fields appear automatically, including UV, solar radiation, rainfall, lightning, air quality and soil sensors. Use the switch on each card to choose whether visitors see it.</p>
</section>

</form>

<section class="pisky-glass pisky-panel pisky-integration-guide">
	<div class="pisky-panel-heading">
		<div>
			<span class="pisky-eyebrow">Connection guide</span>
			<h2>Choose the observation source</h2>
		</div>
	</div>
	<div class="pisky-guide-grid">
		<article>
			<span class="pisky-guide-number">01</span>
			<h3>Open-Meteo</h3>
			<p>Set <code>provider</code> to <code>open-meteo</code>. PiSky automatically uses latitude and longitude from Camera Settings and caches requests locally.</p>
		</article>
		<article>
			<span class="pisky-guide-number">02</span>
			<h3>Local WeeWX</h3>
			<p>Set <code>provider</code> to <code>weewx</code>, then point <code>weewx.file</code> at a JSON file generated by your WeeWX skin or extension.</p>
		</article>
		<article>
			<span class="pisky-guide-number">03</span>
			<h3>Remote WeeWX</h3>
			<p>Leave <code>weewx.file</code> empty and provide an HTTP or HTTPS JSON endpoint in <code>weewx.url</code>. PiSky normalizes common WeeWX field names.</p>
		</article>
	</div>
</section>

<?php
}
?>
