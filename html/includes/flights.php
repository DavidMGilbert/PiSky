<?php
/*
 * PiSky local air-traffic administration view
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

include_once(__DIR__ . "/piskyFlights.php");

function DisplayPiSkyFlights() {
	$configPath = htmlspecialchars(pisky_flights_config_path());
	$config = pisky_flights_config();
	$decoder = htmlspecialchars(isset($config["decoder"]) ? $config["decoder"] : "Local ADS-B receiver");
	$sharing = isset($config["sharing"]) ? $config["sharing"] : array();
	$flightAware = pisky_flights_bool(isset($sharing["flightaware"]["enabled"]) ? $sharing["flightaware"]["enabled"] : false);
	$flightRadar = pisky_flights_bool(isset($sharing["flightradar24"]["enabled"]) ? $sharing["flightradar24"]["enabled"] : false);
	$coverageMap = isset($config["coverage_map"]) ? $config["coverage_map"] : array();
	$mapEnabled = !isset($coverageMap["enabled"])
		|| pisky_flights_bool($coverageMap["enabled"]);
	$mapLatitude = isset($coverageMap["latitude"]) && is_numeric($coverageMap["latitude"])
		? floatval($coverageMap["latitude"])
		: (isset($config["latitude"]) && is_numeric($config["latitude"])
			? floatval($config["latitude"]) : null);
	$mapLongitude = isset($coverageMap["longitude"]) && is_numeric($coverageMap["longitude"])
		? floatval($coverageMap["longitude"])
		: (isset($config["longitude"]) && is_numeric($config["longitude"])
			? floatval($config["longitude"]) : null);
	// The radar draws its own basemap from the station position, so no embed
	// URL is built here any more; only whether a position is known at all.
	$mapPlaced = $mapEnabled && $mapLatitude !== null && $mapLongitude !== null;
?>

<div class="pisky-page-heading">
	<div>
		<span class="pisky-eyebrow">Local 1090 MHz observation</span>
		<h1>Air traffic</h1>
		<p>Track aircraft decoded on this Pi by an RTL-SDR or another compatible ADS-B receiver.</p>
	</div>
	<div class="pisky-heading-actions">
		<span class="pisky-live-pill" data-pisky-flights-status>Connecting…</span>
	</div>
</div>

<div class="pisky-metric-grid">
	<div class="pisky-glass pisky-metric">
		<span>Aircraft tracked</span>
		<strong data-pisky-flights="aircraft_count">—</strong>
		<small>Fresh receiver messages</small>
	</div>
	<div class="pisky-glass pisky-metric">
		<span>Position fixes</span>
		<strong data-pisky-flights="positioned_count">—</strong>
		<small>Targets shown on radar</small>
	</div>
	<div class="pisky-glass pisky-metric">
		<span>Nearest target</span>
		<strong data-pisky-flights="nearest">—</strong>
		<small>From receiver location</small>
	</div>
	<div class="pisky-glass pisky-metric">
		<span>Messages decoded</span>
		<strong data-pisky-flights="messages">—</strong>
		<small>Since decoder start</small>
	</div>
</div>

<div class="pisky-flight-admin-grid">
	<section class="pisky-glass pisky-panel pisky-flight-radar-panel">
		<div class="pisky-panel-heading">
			<div>
				<span class="pisky-eyebrow">Live local receiver</span>
				<h2><?php echo $decoder; ?></h2>
			</div>
			<span class="pisky-provider-pill">Range <b data-pisky-flights="range">—</b></span>
		</div>
		<?php
		// The same canvas scope the visitor site uses, so both interfaces plot
		// targets over the same basemap with one implementation.
		?>
		<div class="pisky-scope-stage pisky-scope-stage-admin" aria-label="Live local aircraft radar">
			<canvas class="pisky-scope-canvas" data-pisky-radar-canvas
				aria-label="Radar display of aircraft received by this station"></canvas>
			<div class="pisky-scope-tag pisky-glass" data-pisky-radar-tag aria-hidden="true"></div>
		</div>
		<?php if (!$mapPlaced) { ?>
		<div class="pisky-coverage-map-empty">
			<?php echo $mapEnabled
				? "Save the station latitude and longitude in PiSky Setup to place the radar over a map."
				: "The coverage map is switched off, so targets are plotted by range and bearing only."; ?>
		</div>
		<?php } ?>
		<small class="pisky-map-attribution">
			Map © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>
		</small>
		<footer class="pisky-data-attribution">
			<span data-pisky-flights="decoder">Local ADS-B decoder</span> · receiver data only · updated
			<span data-pisky-flights="observed_at">—</span>
		</footer>
	</section>

	<section class="pisky-glass pisky-panel pisky-flight-table-panel">
		<div class="pisky-panel-heading">
			<div>
				<span class="pisky-eyebrow">Decoded targets</span>
				<h2>Nearby aircraft</h2>
			</div>
		</div>
		<div class="pisky-flight-table-scroll">
			<table class="pisky-flight-table pisky-flight-table-admin">
				<thead>
					<tr><th>Flight / ICAO</th><th>Altitude</th><th>Speed</th><th>Track</th><th>Range</th><th>Seen</th></tr>
				</thead>
				<tbody data-pisky-flight-list data-limit="24">
					<tr><td colspan="6">Waiting for the local receiver…</td></tr>
				</tbody>
			</table>
		</div>
		<aside class="pisky-flight-detail" data-pisky-flight-detail aria-live="polite">
			<span class="pisky-eyebrow">Select an aircraft</span>
			<h3>Flight and aircraft details</h3>
			<p>Choose a target in the table or radar to inspect locally decoded information.</p>
		</aside>
	</section>
</div>

<div class="pisky-flight-setup-grid">
	<section class="pisky-glass pisky-panel">
		<span class="pisky-guide-number">01</span>
		<h3>Attach a receiver</h3>
		<p>Connect an RTL-SDR or Mode-S Beast GPS receiver to the Pi, then select it in PiSky Setup. PiSky configures the local decoder and data path for you.</p>
	</section>
	<section class="pisky-glass pisky-panel">
		<span class="pisky-guide-number">02</span>
		<h3>Keep tracking local</h3>
		<p>PiSky reads the decoder file directly from RAM. Internet access and a hosted flight-data subscription are not required for the radar or traffic table.</p>
	</section>
	<section class="pisky-glass pisky-panel pisky-sharing-panel">
		<span class="pisky-guide-number">03</span>
		<h3>Optional data sharing</h3>
		<div class="pisky-sharing-badges">
			<span data-pisky-sharing="flightaware" class="<?php echo $flightAware ? "active" : ""; ?>">FlightAware <b><?php echo $flightAware ? "enabled" : "optional"; ?></b></span>
			<span data-pisky-sharing="flightradar24" class="<?php echo $flightRadar ? "active" : ""; ?>">Flightradar24 <b><?php echo $flightRadar ? "enabled" : "optional"; ?></b></span>
		</div>
		<p>PiAware and fr24feed can upload the same locally decoded traffic. Qualifying contributors can receive FlightAware Enterprise or Flightradar24 Contributor benefits under each platform’s current rules.</p>
	</section>
</div>

<div class="pisky-config-note pisky-flight-config-note">
	<span>Configuration file</span>
	<code><?php echo $configPath; ?></code>
	<a class="btn btn-primary" href="/admin/?page=pisky_setup">Configure receiver</a>
</div>

<?php
}
?>
