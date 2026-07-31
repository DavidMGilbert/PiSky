<?php

function DisplayLiveView($image_name, $delay, $daydelay, $daydelay_postMsg, $nightdelay, $nightdelay_postMsg, $darkframe) {
	global $showUpdatedMessage;
	$myStatus = new StatusMessages();
	$piskyImageUrl = preg_match('#^(?:https?:)?//#i', $image_name)
		? $image_name
		: '/' . ltrim($image_name, '/');

	// Note: if liveview is left open during a day/night transition the delay will become wrong.
	// For example, if liveview is started during the day we use "daydelay" but then
	// at night we're still using "daydelay" but should be using "nightdelay".
	// The user can fix this by reloading the web page.
	// TODO: Should we automatically reload the page every so often (we already reload the image)?

	if ($darkframe) {
		$myStatus->addMessage('Currently capturing dark frames. You can turn this off in the Camera Settings page.');
	} else if ($showUpdatedMessage) {
		$s =  number_format($daydelay);
		$msg =  "Daytime images updated every $s seconds$daydelay_postMsg,";
		$s =  number_format($nightdelay);
		$msg .= " nighttime every $s seconds$nightdelay_postMsg";
		$myStatus->addMessage("$msg", "message", true);
	}
?>

<script>
		function getImage() {
			var newImg = new Image();
			newImg.src = <?php echo json_encode($piskyImageUrl); ?> + '?_ts=' + new Date().getTime();
			newImg.id = "current";
			newImg.className = "current";
			newImg.alt = "Latest all-sky camera image";
			newImg.decode().then(() => {
				$("#live_container img.current").replaceWith(newImg);
			}).catch((err) => {
				if (!this.complete || typeof this.naturalWidth == "undefined" || this.naturalWidth == 0) {
					console.log('broken image: ', err);
				}
			}).finally(() => {
				// Use tail recursion to trigger the next invocation after `$delay` milliseconds
				setTimeout(function () { getImage(); }, <?php echo $delay ?>);
			});
		};

		getImage();
</script>

<div class="pisky-page-heading">
	<div>
		<span class="pisky-eyebrow">Observatory control</span>
		<h1>Live view</h1>
		<p>Monitor the camera, local conditions and capture health in one place.</p>
	</div>
	<div class="pisky-heading-actions">
		<span class="pisky-live-pill" data-pisky-weather-status>Connecting…</span>
		<a class="btn btn-default" href="/" target="_blank">Public view <i class="fa fa-external-link-alt"></i></a>
	</div>
</div>

<?php if ($myStatus->isMessage()) echo "<div class='pisky-status-message'>" . $myStatus->showMessages() . "</div>"; ?>

<div class="pisky-metric-grid">
	<div class="pisky-glass pisky-metric">
		<span>Camera status</span>
		<strong><?php echo $darkframe ? "Dark frames" : "Capturing"; ?></strong>
		<small>Refresh every <?php echo max(2, round($delay / 1000)); ?> seconds</small>
	</div>
	<div class="pisky-glass pisky-metric">
		<span>Temperature</span>
		<strong data-pisky-weather="temperature">—</strong>
		<small data-pisky-weather="condition">Loading local weather</small>
	</div>
	<div class="pisky-glass pisky-metric">
		<span>Cloud cover</span>
		<strong data-pisky-weather="cloud_cover">—</strong>
		<small>Visibility <b data-pisky-weather="visibility">—</b></small>
	</div>
	<div class="pisky-glass pisky-metric">
		<span>Wind</span>
		<strong data-pisky-weather="wind_speed">—</strong>
		<small>Gusting <b data-pisky-weather="wind_gust">—</b></small>
	</div>
	<div class="pisky-glass pisky-metric">
		<span>Air traffic</span>
		<strong data-pisky-flights="aircraft_count">—</strong>
		<small>Nearest <b data-pisky-flights="nearest">—</b></small>
	</div>
</div>

<div class="pisky-admin-live-grid">
	<section class="pisky-glass pisky-panel pisky-admin-live-panel">
		<div class="pisky-panel-heading">
			<div>
				<span class="pisky-eyebrow">Live camera</span>
				<h2>Current sky</h2>
			</div>
			<span class="pisky-provider-pill">Updated automatically</span>
		</div>
		<div id="live_container" class="cursorPointer pisky-admin-live-container" title="Click to make full-screen">
			<img id="current" class="current" src="<?php echo htmlspecialchars($piskyImageUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Latest all-sky camera image">
			<div class="pisky-image-vignette"></div>
			<div class="pisky-image-badge pisky-image-badge-left">
				<span>Conditions</span>
				<strong data-pisky-weather="condition">Connecting…</strong>
			</div>
			<div class="pisky-image-badge pisky-image-badge-right">
				<span>Observed</span>
				<strong data-pisky-weather="observed_at">—</strong>
			</div>
		</div>
	</section>

	<aside class="pisky-glass pisky-panel pisky-admin-condition-panel">
		<div class="pisky-panel-heading">
			<div>
				<span class="pisky-eyebrow">Weather now</span>
				<h2 data-pisky-weather="condition">Current conditions</h2>
			</div>
			<span class="pisky-provider-pill" data-pisky-provider>Weather</span>
		</div>
		<div class="pisky-admin-temperature" data-pisky-weather="temperature">—</div>
		<dl>
			<div><dt>Humidity</dt><dd data-pisky-weather="humidity">—</dd></div>
			<div><dt>Dew point</dt><dd data-pisky-weather="dew_point">—</dd></div>
			<div><dt>Pressure</dt><dd data-pisky-weather="pressure">—</dd></div>
			<div><dt>Rain</dt><dd data-pisky-weather="rain">—</dd></div>
			<div><dt>Wind direction</dt><dd data-pisky-weather="wind_direction">—</dd></div>
		</dl>
		<a class="btn btn-primary btn-block" href="/admin/?page=weather">Open weather workspace</a>
		<footer data-pisky-weather="source">Weather source</footer>
	</aside>
</div>

<?php 
}
?>
