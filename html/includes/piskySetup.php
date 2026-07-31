<?php
/*
 * PiSky unified administration and component setup.
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */

include_once(__DIR__ . "/piskyWeather.php");
include_once(__DIR__ . "/piskyFlights.php");
include_once(__DIR__ . "/piskyAdmin.php");
include_once(__DIR__ . "/piskySite.php");
include_once(__DIR__ . "/piskyWeatherHistory.php");

function pisky_setup_post($name, $default, $maxLength=1024) {
	if (!isset($_POST[$name]) || is_array($_POST[$name])) return $default;
	$value = trim(strval($_POST[$name]));
	return substr($value, 0, $maxLength);
}

function pisky_setup_checked($name) {
	return isset($_POST[$name]) && $_POST[$name] === "1";
}

function pisky_setup_number($name, $default, $minimum, $maximum, &$errors) {
	$value = pisky_setup_post($name, strval($default), 32);
	if (!is_numeric($value)) {
		$errors[] = ucfirst(str_replace("_", " ", $name)) . " must be a number.";
		return $default;
	}
	$number = floatval($value);
	if ($number < $minimum || $number > $maximum) {
		$errors[] = ucfirst(str_replace("_", " ", $name))
			. " must be between " . $minimum . " and " . $maximum . ".";
		return $default;
	}
	return $number;
}

function pisky_setup_coordinate($name, $minimum, $maximum, &$errors) {
	$value = pisky_setup_post($name, "", 40);
	if ($value === "") return "";
	if (!is_numeric($value) || floatval($value) < $minimum || floatval($value) > $maximum) {
		$errors[] = ucfirst($name) . " must be a decimal value between "
			. $minimum . " and " . $maximum . ".";
		return "";
	}
	return floatval($value);
}

function pisky_setup_http_url($name, &$errors) {
	$value = pisky_setup_post($name, "", 1024);
	if ($value !== "" && !preg_match("#^https?://#i", $value)) {
		$errors[] = ucfirst(str_replace("_", " ", $name)) . " must use HTTP or HTTPS.";
		return "";
	}
	return $value;
}

function pisky_setup_service_card($id, $label, $description, $service, $csrfEnabled) {
	$installed = isset($service["installed"]) && $service["installed"];
	$active = isset($service["active"]) && $service["active"];
	$enabled = isset($service["enabled"]) && $service["enabled"];
	$statusClass = $active ? "is-active" : ($installed ? "is-stopped" : "is-missing");
	$statusText = $active ? "Running" : ($installed ? "Stopped" : "Not installed");
?>
	<article class="pisky-glass pisky-service-card">
		<div class="pisky-service-card-heading">
			<div>
				<span class="pisky-service-state <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
				<h3><?php echo htmlspecialchars($label); ?></h3>
			</div>
			<i class="fa <?php echo $active ? "fa-check-circle" : "fa-circle"; ?>" aria-hidden="true"></i>
		</div>
		<p><?php echo htmlspecialchars($description); ?></p>
		<small><?php echo $enabled ? "Starts at boot" : "Not enabled at boot"; ?></small>
		<?php if ($installed && $csrfEnabled) { ?>
		<form method="post" class="pisky-service-actions">
			<input type="hidden" name="page" value="pisky_setup">
			<input type="hidden" name="pisky_action" value="service">
			<input type="hidden" name="service" value="<?php echo htmlspecialchars($id); ?>">
			<?php if ($csrfEnabled) CSRFToken(); ?>
			<?php if ($active) { ?>
				<button class="btn btn-default" name="operation" value="restart" type="submit">Restart</button>
				<button class="btn btn-default" name="operation" value="stop" type="submit">Stop</button>
			<?php } else { ?>
				<button class="btn btn-primary" name="operation" value="start" type="submit">Start</button>
			<?php } ?>
			<button class="btn btn-default" name="operation"
				value="<?php echo $enabled ? "disable-stop" : "enable-start"; ?>" type="submit">
				<?php echo $enabled ? "Disable" : "Enable at boot"; ?>
			</button>
		</form>
		<?php } ?>
	</article>
<?php
}

function DisplayPiSkySetup() {
	global $useLogin;

	$notice = "";
	$noticeType = "info";
	$weather = pisky_weather_config();
	$flights = pisky_flights_config();
	$capabilities = pisky_site_capabilities();

	if ($_SERVER["REQUEST_METHOD"] === "POST"
		&& isset($_POST["pisky_action"])) {
		if (!$useLogin) {
			$notice = "Enable WebUI authentication before using PiSky's privileged controls.";
			$noticeType = "danger";
		} else if (!CSRFValidate()) {
			$notice = "The setup request expired or failed its security check. Reload and try again.";
			$noticeType = "danger";
		} else {
			$action = pisky_setup_post("pisky_action", "", 40);
			if ($action === "save") {
				$errors = array();
				$provider = pisky_setup_post("weather_provider", "open-meteo", 30);
				if (!in_array($provider, array("open-meteo", "weewx"), true)) {
					$errors[] = "Select a supported weather provider.";
					$provider = "open-meteo";
				}
				$displayUnits = pisky_setup_post("weather_display_units", "metric", 20);
				if (!in_array($displayUnits, array("metric", "imperial"), true)) {
					$errors[] = "Select Metric or Imperial weather display units.";
					$displayUnits = "metric";
				}
				$latitude = pisky_setup_coordinate("latitude", -90, 90, $errors);
				$longitude = pisky_setup_coordinate("longitude", -180, 180, $errors);
				$coverageLatitude = pisky_setup_coordinate(
					"coverage_map_latitude", -90, 90, $errors
				);
				$coverageLongitude = pisky_setup_coordinate(
					"coverage_map_longitude", -180, 180, $errors
				);
				$timezone = pisky_setup_post("timezone", "auto", 80);
				if (!preg_match("#^[A-Za-z0-9_+./-]+$#", $timezone)) {
					$errors[] = "Timezone contains unsupported characters.";
					$timezone = "auto";
				} else if ($timezone !== "auto"
					&& !in_array($timezone, DateTimeZone::listIdentifiers(), true)
					&& $timezone !== "UTC") {
					$errors[] = "Timezone must be auto or a valid IANA name such as Australia/Sydney.";
					$timezone = "auto";
				}

				$weather = array(
					"enabled" => pisky_setup_checked("weather_enabled"),
					"provider" => $provider,
					"display_units" => $displayUnits,
					"open_meteo" => array(
						"latitude" => $latitude,
						"longitude" => $longitude,
						"timezone" => $timezone,
						"cache_seconds" => intval(pisky_setup_number(
							"weather_cache", 300, 60, 3600, $errors
						))
					),
					"weewx" => array(
						"file" => pisky_setup_post(
							"weewx_file", "/var/lib/pisky/weather/current.json", 512
						),
						"url" => pisky_setup_http_url("weewx_url", $errors),
						"cache_seconds" => intval(pisky_setup_number(
							"weewx_cache", 10, 2, 3600, $errors
						))
					)
				);

				$receiverType = pisky_setup_post("receiver_type", "rtl-sdr", 30);
				if (!in_array($receiverType, array("rtl-sdr", "mode-s-beast"), true)) {
					$errors[] = "Select a supported ADS-B receiver type.";
					$receiverType = "rtl-sdr";
				}
				$rtlDevice = pisky_setup_post("rtl_device", "0", 64);
				if (!preg_match("#^[A-Za-z0-9_.-]{1,64}$#", $rtlDevice)) {
					$errors[] = "RTL-SDR device must be an index, serial number or safe device label.";
					$rtlDevice = "0";
				}
				$rtlGain = strtolower(pisky_setup_post("rtl_gain", "max", 16));
				if (!preg_match("#^(max|agc|[0-9]{1,2}(?:[.][0-9])?)$#", $rtlGain)) {
					$errors[] = "RTL-SDR gain must be max, agc or a value between 0 and 99.9 dB.";
					$rtlGain = "max";
				}
				$beastSerial = pisky_setup_post("beast_serial_device", "/dev/beast", 80);
				if (!preg_match("#^/dev/(beast|tty(USB|ACM)[0-9]{1,3})$#", $beastSerial)) {
					$errors[] = "Mode-S Beast serial device must be /dev/beast, /dev/ttyUSBn or /dev/ttyACMn.";
					$beastSerial = "/dev/beast";
				}
				$beastBaud = pisky_setup_post("beast_baud", "auto", 20);
				$allowedBaud = array(
					"auto", "115200", "460800", "921600",
					"1000000", "2000000", "3000000"
				);
				if (!in_array($beastBaud, $allowedBaud, true)) {
					$errors[] = "Select a supported Mode-S Beast baud rate.";
					$beastBaud = "auto";
				}
				$beastFormat = pisky_setup_post(
					"beast_output_format", "beast-classic", 30
				);
				if (!in_array($beastFormat, array(
					"radarcape-gps", "radarcape", "beast-classic"
				), true)) {
					$errors[] = "Select a supported Mode-S Beast output format.";
					$beastFormat = "beast-classic";
				}
				$bindAddress = pisky_setup_post("adsb_bind_address", "127.0.0.1", 20);
				if (!in_array($bindAddress, array("127.0.0.1", "0.0.0.0"), true)) {
					$errors[] = "Select a supported ADS-B network visibility.";
					$bindAddress = "127.0.0.1";
				}
				$locationAccuracy = pisky_setup_post(
					"adsb_location_accuracy", "none", 20
				);
				if (!in_array($locationAccuracy, array(
					"none", "approximate", "exact"
				), true)) {
					$errors[] = "Select a supported receiver location accuracy.";
					$locationAccuracy = "none";
				}
				$adsbPorts = array(
					"raw_input_port" => intval(pisky_setup_number(
						"raw_input_port", 30001, 1024, 65535, $errors
					)),
					"raw_output_port" => intval(pisky_setup_number(
						"raw_output_port", 30002, 1024, 65535, $errors
					)),
					"sbs_output_port" => intval(pisky_setup_number(
						"sbs_output_port", 30003, 1024, 65535, $errors
					)),
					"beast_input_port" => intval(pisky_setup_number(
						"beast_input_port", 30004, 1024, 65535, $errors
					)),
					"beast_output_port" => intval(pisky_setup_number(
						"beast_output_port", 30005, 1024, 65535, $errors
					))
				);
				if (count(array_unique(array_values($adsbPorts))) !== count($adsbPorts)) {
					$errors[] = "Every local ADS-B network port must be unique.";
				}
				$aircraftFile = pisky_setup_post(
					"aircraft_file", "/run/dump1090-mutability/aircraft.json", 512
				);
				$receiverFile = pisky_setup_post("receiver_file", "", 512);
				if ($receiverType === "mode-s-beast" && $receiverFile === "") {
					$receiverFile = "/run/beast-splitter/status.json";
				}

				$flights = array(
					"enabled" => pisky_setup_checked("flights_enabled"),
					"decoder" => pisky_setup_post(
						"decoder", "Local ADS-B receiver", 120
					),
					"aircraft_file" => $aircraftFile,
					"receiver_file" => $receiverFile,
					"aircraft_url" => pisky_setup_http_url("aircraft_url", $errors),
					"latitude" => $latitude,
					"longitude" => $longitude,
					"range_km" => pisky_setup_number(
						"range_km", 160, 10, 1000, $errors
					),
					"max_aircraft" => intval(pisky_setup_number(
						"max_aircraft", 60, 1, 500, $errors
					)),
					"max_seen_seconds" => intval(pisky_setup_number(
						"max_seen_seconds", 15, 1, 300, $errors
					)),
					"receiver" => array(
						"type" => $receiverType,
						"rtl_sdr" => array(
							"device" => $rtlDevice,
							"gain" => $rtlGain,
							"ppm" => intval(pisky_setup_number(
								"rtl_ppm", 0, -250, 250, $errors
							))
						),
						"beast" => array(
							"serial_device" => $beastSerial,
							"baud" => $beastBaud,
							"output_format" => $beastFormat
						)
					),
					"decoder_options" => array(
						"fix_crc" => pisky_setup_checked("adsb_fix_crc"),
						"max_range_nm" => pisky_setup_number(
							"adsb_max_range_nm", 300, 10, 500, $errors
						),
						"json_interval_seconds" => intval(pisky_setup_number(
							"adsb_json_interval", 1, 1, 60, $errors
						)),
						"location_accuracy" => $locationAccuracy
					),
					"network" => array_merge(
						array("bind_address" => $bindAddress),
						$adsbPorts
					),
					"coverage_map" => array(
						"enabled" => pisky_setup_checked("coverage_map_enabled"),
						// Zero means the radar picks a level that fits the
						// configured range; 3 to 16 are honoured exactly.
						"zoom" => intval(pisky_setup_number(
							"coverage_map_zoom", 0, 0, 16, $errors
						)),
						"public" => pisky_setup_checked("coverage_map_public"),
						"latitude" => $coverageLatitude,
						"longitude" => $coverageLongitude
					),
					"sharing" => array(
						"flightaware" => array(
							"enabled" => pisky_setup_checked("flightaware_enabled"),
							"site_id" => pisky_setup_post("flightaware_site_id", "", 160)
						),
						"flightradar24" => array(
							"enabled" => pisky_setup_checked("flightradar24_enabled"),
							"radar_id" => pisky_setup_post("flightradar24_radar_id", "", 160)
						)
					)
				);

				if (count($errors) > 0) {
					$notice = implode(" ", $errors);
					$noticeType = "danger";
				} else {
					$ok = pisky_admin_apply_configs($weather, $flights, $notice);
					$noticeType = $ok ? "success" : "danger";
					if ($ok) {
						$weather = pisky_weather_config();
						$flights = pisky_flights_config();
					}
				}
			} else if ($action === "service") {
				$service = pisky_setup_post("service", "", 80);
				$operation = pisky_setup_post("operation", "", 40);
				$ok = pisky_admin_service($service, $operation, $notice);
				$noticeType = $ok ? "success" : "danger";
			} else if ($action === "save_weewx") {
				$contents = isset($_POST["weewx_config"]) && !is_array($_POST["weewx_config"])
					? strval($_POST["weewx_config"]) : "";
				$ok = pisky_admin_apply_weewx($contents, $notice);
				$noticeType = $ok ? "success" : "danger";
			} else if ($action === "configure_weewx_station") {
				$errors = array();
				$preset = pisky_setup_post("weewx_station_preset", "ecowitt", 30);
				$presetNames = array(
					"ecowitt" => "Ecowitt weather station",
					"wu-client" => "Weather Underground compatible station",
					"observer" => "ObserverIP compatible station"
				);
				if (!isset($presetNames[$preset])) {
					$errors[] = "Select a supported weather-station preset.";
					$preset = "ecowitt";
				}
				$stationPort = intval(pisky_setup_number(
					"weewx_station_port", 8000, 1024, 65535, $errors
				));
				$hardwareName = pisky_setup_post(
					"weewx_hardware_name", $presetNames[$preset], 80
				);
				if ($hardwareName === "") $hardwareName = $presetNames[$preset];
				if (!preg_match("#^[A-Za-z0-9 ._()+-]{1,80}$#", $hardwareName)) {
					$errors[] = "Station name can use letters, numbers, spaces and . _ ( ) + -.";
					$hardwareName = $presetNames[$preset];
				}
				$useAsProvider = pisky_setup_checked("weewx_use_as_provider");
				$enableStationService = pisky_setup_checked("weewx_enable_service")
					|| $useAsProvider;
				$stationSettings = array(
					"preset" => $preset,
					"port" => $stationPort,
					"hardware_name" => $hardwareName,
					"enable_service" => $enableStationService,
					"use_as_provider" => $useAsProvider
				);
				if (count($errors) > 0) {
					$notice = implode(" ", $errors);
					$noticeType = "danger";
				} else {
					$stationMessage = "";
					$ok = pisky_admin_configure_weewx_station(
						$stationSettings,
						$stationMessage
					);
					$notice = $stationMessage;
					$noticeType = $ok ? "success" : "danger";
					if ($ok) {
						$weather = pisky_weather_config();
					}
				}
			} else if ($action === "test_weewx_station") {
				$ok = pisky_admin_test_weewx_station($notice);
				$noticeType = $ok ? "success" : "warning";
			} else if ($action === "save_history") {
				$historyErrors = array();
				$historyHost = pisky_setup_post("history_host", "", 255);
				if ($historyHost !== "" && !preg_match("/^[A-Za-z0-9._-]+$/", $historyHost)) {
					$historyErrors[] = "Enter the database host as a hostname or address.";
					$historyHost = "";
				}
				$historyDatabase = pisky_setup_post("history_database", "", 64);
				if ($historyDatabase !== "" && !preg_match("/^[A-Za-z0-9_$-]+$/", $historyDatabase)) {
					$historyErrors[] = "Enter a valid database name.";
					$historyDatabase = "";
				}
				$historyStation = pisky_setup_post("history_station", "default", 64);
				if (!preg_match("/^[A-Za-z0-9_.-]*$/", $historyStation)) {
					$historyErrors[] = "Station identifiers may use letters, numbers, dot, dash and underscore.";
					$historyStation = "default";
				}
				$historyEnabled = pisky_setup_checked("history_enabled");
				if ($historyEnabled && ($historyHost === "" || $historyDatabase === ""
					|| pisky_setup_post("history_user", "", 64) === "")) {
					$historyErrors[] = "A host, database and user are required to enable remote history.";
				}
				if (count($historyErrors)) {
					$notice = implode(" ", $historyErrors);
					$noticeType = "danger";
				} else {
					$ok = pisky_admin_apply_history(array(
						"enabled" => $historyEnabled,
						"driver" => "mysql",
						"host" => $historyHost,
						"port" => intval(pisky_setup_number("history_port", 3306, 1, 65535, $historyErrors)),
						"database" => $historyDatabase,
						"user" => pisky_setup_post("history_user", "", 64),
						// Blank keeps whatever is already stored.
						"password" => pisky_setup_post("history_password", "", 256),
						"tls" => pisky_setup_checked("history_tls"),
						"station" => $historyStation === "" ? "default" : $historyStation,
						"sample_interval_seconds" => intval(pisky_setup_number(
							"history_interval", 300, 60, 3600, $historyErrors
						)),
						"retain_days" => intval(pisky_setup_number(
							"history_retain", 400, 7, 3650, $historyErrors
						))
					), $notice);
					$noticeType = $ok ? "success" : "danger";
				}
			} else if ($action === "test_history") {
				$ok = pisky_history_test($notice);
				$noticeType = $ok ? "success" : "warning";
			}
		}
	}

	$statusError = "";
	$componentStatus = pisky_admin_status($statusError);
	$services = is_array($componentStatus) && isset($componentStatus["services"])
		? $componentStatus["services"] : array();
	$version = is_array($componentStatus) && isset($componentStatus["version"])
		? $componentStatus["version"] : "installer required";
	$weewxStation = is_array($componentStatus)
		&& isset($componentStatus["weewx_station"])
		&& is_array($componentStatus["weewx_station"])
		? $componentStatus["weewx_station"] : array();

	$weewxError = "";
	$weewxConfig = $useLogin
		&& isset($services["weewx"]["installed"]) && $services["weewx"]["installed"]
		? pisky_admin_read_weewx($weewxError) : null;

	$weatherProvider = isset($weather["provider"]) ? $weather["provider"] : "open-meteo";
	$weatherDisplayUnits = isset($weather["display_units"])
		&& $weather["display_units"] === "imperial" ? "imperial" : "metric";
	$openMeteo = isset($weather["open_meteo"]) ? $weather["open_meteo"] : array();
	$weewx = isset($weather["weewx"]) ? $weather["weewx"] : array();
	$sharing = isset($flights["sharing"]) ? $flights["sharing"] : array();
	$receiver = isset($flights["receiver"]) ? $flights["receiver"] : array();
	$rtlSdr = isset($receiver["rtl_sdr"]) ? $receiver["rtl_sdr"] : array();
	$beast = isset($receiver["beast"]) ? $receiver["beast"] : array();
	$decoderOptions = isset($flights["decoder_options"])
		? $flights["decoder_options"] : array();
	$adsbNetwork = isset($flights["network"]) ? $flights["network"] : array();
	$coverageMap = isset($flights["coverage_map"]) ? $flights["coverage_map"] : array();
	$receiverType = isset($receiver["type"]) ? $receiver["type"] : "rtl-sdr";
	$latitude = isset($openMeteo["latitude"]) ? $openMeteo["latitude"] : "";
	$longitude = isset($openMeteo["longitude"]) ? $openMeteo["longitude"] : "";
	$weewxPreset = isset($weewxStation["preset"])
		? $weewxStation["preset"] : "ecowitt";
	if (!in_array($weewxPreset, array("ecowitt", "wu-client", "observer"), true)) {
		$weewxPreset = "ecowitt";
	}
	$weewxStationPort = isset($weewxStation["port"])
		? intval($weewxStation["port"]) : 8000;
	$weewxHardwareName = isset($weewxStation["hardware_name"])
		? $weewxStation["hardware_name"] : "Ecowitt weather station";
	$weewxDriverInstalled = isset($weewxStation["driver_installed"])
		&& $weewxStation["driver_installed"];
	$weewxServiceActive = isset($weewxStation["service_active"])
		&& $weewxStation["service_active"];
	$weewxDataFresh = isset($weewxStation["data_fresh"])
		&& $weewxStation["data_fresh"];
	$weewxOutputExists = isset($weewxStation["output_exists"])
		&& $weewxStation["output_exists"];
	$weewxDataAge = isset($weewxStation["data_age_seconds"])
		&& is_numeric($weewxStation["data_age_seconds"])
		? intval($weewxStation["data_age_seconds"]) : null;
	$stationEndpointHost = "pisky.local";
	$endpointCandidate = isset($_SERVER["HTTP_HOST"])
		? strval($_SERVER["HTTP_HOST"])
		: (isset($_SERVER["SERVER_ADDR"]) ? strval($_SERVER["SERVER_ADDR"]) : "");
	if ($endpointCandidate !== "") {
		$parsedEndpointHost = parse_url("http://" . $endpointCandidate, PHP_URL_HOST);
		if (is_string($parsedEndpointHost) && $parsedEndpointHost !== "") {
			$stationEndpointHost = $parsedEndpointHost;
		}
	}
	$weewxCanConfigure = $useLogin
		&& isset($services["weewx"]["installed"]) && $services["weewx"]["installed"]
		&& $weewxDriverInstalled;
?>

<div class="pisky-page-heading">
	<div>
		<span class="pisky-eyebrow">Unified observatory control</span>
		<h1>PiSky setup</h1>
		<p>Enable and configure any combination of sky imaging, local weather and locally decoded air traffic.</p>
	</div>
	<div class="pisky-heading-actions">
		<span class="pisky-provider-pill">PiSky <?php echo htmlspecialchars($version); ?></span>
	</div>
</div>

<?php if ($notice !== "") { ?>
	<div class="alert alert-<?php echo htmlspecialchars($noticeType); ?>">
		<?php echo nl2br(htmlspecialchars($notice)); ?>
	</div>
<?php } ?>
<?php if ($componentStatus === null) { ?>
	<div class="alert alert-warning">
		<strong>Installation is incomplete.</strong>
		<?php echo htmlspecialchars($statusError); ?>
		Run <code>./install-pisky.sh</code> from the PiSky folder before using these controls.
	</div>
<?php } ?>
<?php if (!$useLogin) { ?>
	<div class="alert alert-danger">
		<strong>Privileged controls are locked.</strong>
		Enable WebUI authentication before changing PiSky configuration or services.
	</div>
<?php } ?>

<form method="post" class="pisky-setup-form">
	<input type="hidden" name="page" value="pisky_setup">
	<input type="hidden" name="pisky_action" value="save">
	<?php if ($useLogin) CSRFToken(); ?>

	<div class="pisky-setup-grid">
		<section class="pisky-glass pisky-panel">
			<div class="pisky-panel-heading">
				<div>
					<span class="pisky-eyebrow">Shared observatory location</span>
					<h2>Station position</h2>
				</div>
				<i class="fa fa-map-marker-alt" aria-hidden="true"></i>
			</div>
			<p class="pisky-form-intro">Used for Open-Meteo forecasts and local aircraft range calculations. Exact coordinates are not exposed by the public API.</p>
			<div class="pisky-form-grid">
				<label class="pisky-field">
					<span>Latitude</span>
					<input class="form-control" type="number" step="any" name="latitude"
						min="-90" max="90" value="<?php echo htmlspecialchars(strval($latitude)); ?>"
						placeholder="-33.8688">
				</label>
				<label class="pisky-field">
					<span>Longitude</span>
					<input class="form-control" type="number" step="any" name="longitude"
						min="-180" max="180" value="<?php echo htmlspecialchars(strval($longitude)); ?>"
						placeholder="151.2093">
				</label>
				<label class="pisky-field pisky-field-wide">
					<span>Timezone</span>
					<input class="form-control" type="text" name="timezone"
						value="<?php echo htmlspecialchars(isset($openMeteo["timezone"]) ? $openMeteo["timezone"] : "auto"); ?>"
						placeholder="auto">
				</label>
			</div>
		</section>

		<section class="pisky-glass pisky-panel">
			<div class="pisky-panel-heading">
				<div>
					<span class="pisky-eyebrow">Conditions provider</span>
					<h2>Weather</h2>
				</div>
				<label class="pisky-toggle">
					<input type="checkbox" name="weather_enabled" value="1"
						<?php echo !isset($weather["enabled"]) || $weather["enabled"] ? "checked" : ""; ?>>
					<span>Enabled</span>
				</label>
			</div>
			<div class="pisky-form-grid">
				<label class="pisky-field pisky-field-wide">
					<span>Provider</span>
					<select class="form-control" name="weather_provider">
						<option value="open-meteo" <?php echo $weatherProvider === "open-meteo" ? "selected" : ""; ?>>Open-Meteo — free forecast data</option>
						<option value="weewx" <?php echo $weatherProvider === "weewx" ? "selected" : ""; ?>>Local WeeWX station</option>
					</select>
				</label>
				<label class="pisky-field pisky-field-wide">
					<span>Display units</span>
					<select class="form-control" name="weather_display_units">
						<option value="metric" <?php echo $weatherDisplayUnits === "metric" ? "selected" : ""; ?>>
							Metric — °C, hPa, km/h, mm and km
						</option>
						<option value="imperial" <?php echo $weatherDisplayUnits === "imperial" ? "selected" : ""; ?>>
							Imperial — °F, inHg, mph, inches and miles
						</option>
					</select>
				</label>
				<label class="pisky-field">
					<span>Open-Meteo cache (seconds)</span>
					<input class="form-control" type="number" name="weather_cache" min="60" max="3600"
						value="<?php echo htmlspecialchars(strval(isset($openMeteo["cache_seconds"]) ? $openMeteo["cache_seconds"] : 300)); ?>">
				</label>
				<label class="pisky-field">
					<span>WeeWX cache (seconds)</span>
					<input class="form-control" type="number" name="weewx_cache" min="2" max="3600"
						value="<?php echo htmlspecialchars(strval(isset($weewx["cache_seconds"]) ? $weewx["cache_seconds"] : 10)); ?>">
				</label>
				<label class="pisky-field pisky-field-wide">
					<span>Local WeeWX JSON file</span>
					<input class="form-control" type="text" name="weewx_file"
						value="<?php echo htmlspecialchars(isset($weewx["file"]) ? $weewx["file"] : "/var/lib/pisky/weather/current.json"); ?>">
				</label>
				<label class="pisky-field pisky-field-wide">
					<span>Remote WeeWX JSON URL (optional)</span>
					<input class="form-control" type="url" name="weewx_url"
						value="<?php echo htmlspecialchars(isset($weewx["url"]) ? $weewx["url"] : ""); ?>"
						placeholder="https://station.example/current.json">
				</label>
			</div>
		</section>
	</div>

	<section class="pisky-glass pisky-panel pisky-setup-section">
		<div class="pisky-panel-heading">
			<div>
				<span class="pisky-eyebrow">Local 1090 MHz receiver</span>
				<h2>Air traffic</h2>
			</div>
			<label class="pisky-toggle">
				<input type="checkbox" name="flights_enabled" value="1"
					<?php echo !isset($flights["enabled"]) || $flights["enabled"] ? "checked" : ""; ?>>
				<span>Enabled</span>
			</label>
		</div>
		<div class="pisky-form-grid pisky-form-grid-four">
			<label class="pisky-field">
				<span>Decoder label</span>
				<input class="form-control" type="text" name="decoder"
					value="<?php echo htmlspecialchars(isset($flights["decoder"]) ? $flights["decoder"] : "Local ADS-B receiver"); ?>">
			</label>
			<label class="pisky-field">
				<span>Display range (km)</span>
				<input class="form-control" type="number" name="range_km" min="10" max="1000"
					value="<?php echo htmlspecialchars(strval(isset($flights["range_km"]) ? $flights["range_km"] : 160)); ?>">
			</label>
			<label class="pisky-field">
				<span>Maximum aircraft</span>
				<input class="form-control" type="number" name="max_aircraft" min="1" max="500"
					value="<?php echo htmlspecialchars(strval(isset($flights["max_aircraft"]) ? $flights["max_aircraft"] : 60)); ?>">
			</label>
			<label class="pisky-field">
				<span>Freshness window (seconds)</span>
				<input class="form-control" type="number" name="max_seen_seconds" min="1" max="300"
					value="<?php echo htmlspecialchars(strval(isset($flights["max_seen_seconds"]) ? $flights["max_seen_seconds"] : 15)); ?>">
			</label>
			<label class="pisky-field pisky-field-wide">
				<span>Aircraft JSON file (blank enables auto-detection)</span>
				<input class="form-control" type="text" name="aircraft_file"
					value="<?php echo htmlspecialchars(isset($flights["aircraft_file"]) ? $flights["aircraft_file"] : ""); ?>"
					placeholder="/run/dump1090-mutability/aircraft.json">
			</label>
			<label class="pisky-field pisky-field-wide">
				<span>Receiver JSON file (optional)</span>
				<input class="form-control" type="text" name="receiver_file"
					value="<?php echo htmlspecialchars(isset($flights["receiver_file"]) ? $flights["receiver_file"] : ""); ?>">
			</label>
			<label class="pisky-field pisky-field-wide">
				<span>Remote decoder URL (optional)</span>
				<input class="form-control" type="url" name="aircraft_url"
					value="<?php echo htmlspecialchars(isset($flights["aircraft_url"]) ? $flights["aircraft_url"] : ""); ?>"
					placeholder="http://receiver.local/data/aircraft.json">
			</label>
		</div>
	</section>

	<section class="pisky-glass pisky-panel pisky-setup-section">
		<div class="pisky-panel-heading">
			<div>
				<span class="pisky-eyebrow">Hardware input</span>
				<h2>ADS-B receiver</h2>
			</div>
			<span class="pisky-provider-pill">Locally decoded</span>
		</div>
		<p class="pisky-form-intro">Choose the receiver connected to this Pi. Saving applies the validated service configuration; no shell or SSH editing is required.</p>
		<div class="pisky-form-grid pisky-form-grid-four">
			<label class="pisky-field pisky-field-wide">
				<span>Receiver type</span>
				<select class="form-control" name="receiver_type">
					<option value="rtl-sdr" <?php echo $receiverType === "rtl-sdr" ? "selected" : ""; ?>>RTL-SDR USB receiver</option>
					<option value="mode-s-beast" <?php echo $receiverType === "mode-s-beast" ? "selected" : ""; ?>>Mode-S Beast or compatible serial receiver</option>
				</select>
			</label>
			<label class="pisky-field">
				<span>RTL-SDR device index or serial</span>
				<input class="form-control" type="text" name="rtl_device"
					value="<?php echo htmlspecialchars(isset($rtlSdr["device"]) ? strval($rtlSdr["device"]) : "0"); ?>">
			</label>
			<label class="pisky-field">
				<span>RTL-SDR gain (max, agc or dB)</span>
				<input class="form-control" type="text" name="rtl_gain"
					value="<?php echo htmlspecialchars(isset($rtlSdr["gain"]) ? strval($rtlSdr["gain"]) : "max"); ?>">
			</label>
			<label class="pisky-field">
				<span>RTL-SDR frequency correction (PPM)</span>
				<input class="form-control" type="number" step="any" min="-250" max="250"
					name="rtl_ppm" value="<?php echo htmlspecialchars(strval(isset($rtlSdr["ppm"]) ? $rtlSdr["ppm"] : 0)); ?>">
			</label>
			<label class="pisky-field">
				<span>Mode-S Beast serial device</span>
				<input class="form-control" type="text" name="beast_serial_device"
					value="<?php echo htmlspecialchars(isset($beast["serial_device"]) ? $beast["serial_device"] : "/dev/beast"); ?>"
					placeholder="/dev/beast">
				<small class="pisky-field-hint">/dev/beast exists only when the receiver reports itself as a Mode-S Beast. If it is missing, use the real port such as /dev/ttyUSB0.</small>
			</label>
			<label class="pisky-field">
				<span>Mode-S Beast baud rate</span>
				<select class="form-control" name="beast_baud">
					<?php
					$currentBaud = isset($beast["baud"]) ? strval($beast["baud"]) : "auto";
					$baudOptions = array(
						"auto" => "Automatic detection",
						"3000000" => "3,000,000 baud",
						"2000000" => "2,000,000 baud",
						"1000000" => "1,000,000 baud",
						"921600" => "921,600 baud",
						"460800" => "460,800 baud",
						"115200" => "115,200 baud"
					);
					foreach ($baudOptions as $value => $label) {
						echo '<option value="' . htmlspecialchars($value) . '" '
							. ($currentBaud === $value ? "selected" : "") . '>'
							. htmlspecialchars($label) . '</option>';
					}
					?>
				</select>
			</label>
			<label class="pisky-field">
				<span>Mode-S Beast stream format</span>
				<select class="form-control" name="beast_output_format">
					<?php $currentFormat = isset($beast["output_format"]) ? $beast["output_format"] : "beast-classic"; ?>
					<option value="beast-classic" <?php echo $currentFormat === "beast-classic" ? "selected" : ""; ?>>Beast Classic (most serial receivers)</option>
					<option value="radarcape" <?php echo $currentFormat === "radarcape" ? "selected" : ""; ?>>Radarcape</option>
					<option value="radarcape-gps" <?php echo $currentFormat === "radarcape-gps" ? "selected" : ""; ?>>Radarcape with GPS timestamps</option>
				</select>
				<small class="pisky-field-hint">Radarcape modes are rejected by a classic Mode-S Beast and stop the stream. Choose Beast Classic unless this really is a Radarcape.</small>
			</label>
		</div>
	</section>

	<section class="pisky-glass pisky-panel pisky-setup-section">
		<div class="pisky-panel-heading">
			<div>
				<span class="pisky-eyebrow">Decoder, network and map</span>
				<h2>Local receiver behaviour</h2>
			</div>
			<i class="fa fa-broadcast-tower" aria-hidden="true"></i>
		</div>
		<div class="pisky-form-grid pisky-form-grid-four">
			<label class="pisky-field">
				<span>Maximum decoder range (NM)</span>
				<input class="form-control" type="number" min="10" max="500"
					name="adsb_max_range_nm" value="<?php echo htmlspecialchars(strval(isset($decoderOptions["max_range_nm"]) ? $decoderOptions["max_range_nm"] : 300)); ?>">
			</label>
			<label class="pisky-field">
				<span>JSON update interval (seconds)</span>
				<input class="form-control" type="number" min="1" max="60"
					name="adsb_json_interval" value="<?php echo htmlspecialchars(strval(isset($decoderOptions["json_interval_seconds"]) ? $decoderOptions["json_interval_seconds"] : 1)); ?>">
			</label>
			<label class="pisky-field">
				<span>Receiver location in decoder JSON</span>
				<select class="form-control" name="adsb_location_accuracy">
					<?php $accuracy = isset($decoderOptions["location_accuracy"]) ? $decoderOptions["location_accuracy"] : "none"; ?>
					<option value="none" <?php echo $accuracy === "none" ? "selected" : ""; ?>>Do not publish</option>
					<option value="approximate" <?php echo $accuracy === "approximate" ? "selected" : ""; ?>>Approximate</option>
					<option value="exact" <?php echo $accuracy === "exact" ? "selected" : ""; ?>>Exact</option>
				</select>
			</label>
			<label class="pisky-field">
				<span>Decoder network visibility</span>
				<select class="form-control" name="adsb_bind_address">
					<?php $bind = isset($adsbNetwork["bind_address"]) ? $adsbNetwork["bind_address"] : "127.0.0.1"; ?>
					<option value="127.0.0.1" <?php echo $bind === "127.0.0.1" ? "selected" : ""; ?>>This Pi only</option>
					<option value="0.0.0.0" <?php echo $bind === "0.0.0.0" ? "selected" : ""; ?>>Local network clients</option>
				</select>
			</label>
			<?php
			$portFields = array(
				"raw_input_port" => array("Raw input port", 30001),
				"raw_output_port" => array("Raw output port", 30002),
				"sbs_output_port" => array("SBS output port", 30003),
				"beast_input_port" => array("Beast input port", 30004),
				"beast_output_port" => array("Beast output port", 30005)
			);
			foreach ($portFields as $name => $port) {
			?>
			<label class="pisky-field">
				<span><?php echo htmlspecialchars($port[0]); ?></span>
				<input class="form-control" type="number" min="1024" max="65535"
					name="<?php echo htmlspecialchars($name); ?>"
					value="<?php echo htmlspecialchars(strval(isset($adsbNetwork[$name]) ? $adsbNetwork[$name] : $port[1])); ?>">
			</label>
			<?php } ?>
			<label class="pisky-toggle pisky-field-wide">
				<input type="checkbox" name="adsb_fix_crc" value="1"
					<?php echo !isset($decoderOptions["fix_crc"]) || $decoderOptions["fix_crc"] ? "checked" : ""; ?>>
				<span>Correct recoverable ADS-B CRC errors</span>
			</label>
			<div class="pisky-sharing-config pisky-field-wide">
				<label class="pisky-toggle">
					<input type="checkbox" name="coverage_map_enabled" value="1"
						<?php echo !isset($coverageMap["enabled"]) || $coverageMap["enabled"] ? "checked" : ""; ?>>
					<span>Show the geographic coverage map beneath the radar</span>
				</label>
				<small class="pisky-field-hint pisky-field-wide">Drawing targets over a map needs the station coordinates, so enabling this publishes them in the public flight data. Clear it to withhold the position; the radar then plots by range and bearing only.</small>
				<label class="pisky-field">
					<span>Coverage map zoom</span>
					<select class="form-control" name="coverage_map_zoom">
						<?php
						$currentZoom = isset($coverageMap["zoom"]) ? intval($coverageMap["zoom"]) : 0;
						echo '<option value="0"' . ($currentZoom < 3 ? " selected" : "")
							. '>Automatic — fit the receiver range</option>';
						for ($zoomLevel = 3; $zoomLevel <= 16; $zoomLevel++) {
							echo '<option value="' . $zoomLevel . '"'
								. ($currentZoom === $zoomLevel ? " selected" : "") . '>Zoom '
								. $zoomLevel . '</option>';
						}
						?>
					</select>
					<small class="pisky-field-hint">Automatic keeps the whole range on screen. A fixed level is honoured exactly, so a closer zoom shows more ground detail and may crop the outer range.</small>
				</label>
				<label class="pisky-field">
					<span>Map latitude override</span>
					<input class="form-control" type="number" step="any" min="-90" max="90"
						name="coverage_map_latitude"
						placeholder="Use station or Beast GPS position"
						value="<?php echo htmlspecialchars(strval(isset($coverageMap["latitude"]) ? $coverageMap["latitude"] : "")); ?>">
				</label>
				<label class="pisky-field">
					<span>Map longitude override</span>
					<input class="form-control" type="number" step="any" min="-180" max="180"
						name="coverage_map_longitude"
						placeholder="Use station or Beast GPS position"
						value="<?php echo htmlspecialchars(strval(isset($coverageMap["longitude"]) ? $coverageMap["longitude"] : "")); ?>">
				</label>
				<label class="pisky-toggle">
					<input type="checkbox" name="coverage_map_public" value="1"
						<?php echo isset($coverageMap["public"]) && $coverageMap["public"] ? "checked" : ""; ?>>
					<span>Allow the coverage map on public Air Traffic pages (reveals station position)</span>
				</label>
			</div>
		</div>
	</section>

	<section class="pisky-glass pisky-panel pisky-setup-section">
		<div class="pisky-panel-heading">
			<div>
				<span class="pisky-eyebrow">Optional outbound destinations</span>
				<h2>Flight-data sharing</h2>
			</div>
			<i class="fa fa-share-alt" aria-hidden="true"></i>
		</div>
		<p class="pisky-form-intro">These controls describe locally installed sharing clients. They never make FlightAware or Flightradar24 the source of PiSky’s aircraft data.</p>
		<div class="pisky-form-grid">
			<div class="pisky-sharing-config">
				<label class="pisky-toggle">
					<input type="checkbox" name="flightaware_enabled" value="1"
						<?php echo isset($sharing["flightaware"]["enabled"]) && $sharing["flightaware"]["enabled"] ? "checked" : ""; ?>>
					<span>FlightAware sharing enabled</span>
				</label>
				<label class="pisky-field">
					<span>FlightAware feeder/site ID</span>
					<input class="form-control" type="text" name="flightaware_site_id"
						value="<?php echo htmlspecialchars(isset($sharing["flightaware"]["site_id"]) ? $sharing["flightaware"]["site_id"] : ""); ?>">
				</label>
			</div>
			<div class="pisky-sharing-config">
				<label class="pisky-toggle">
					<input type="checkbox" name="flightradar24_enabled" value="1"
						<?php echo isset($sharing["flightradar24"]["enabled"]) && $sharing["flightradar24"]["enabled"] ? "checked" : ""; ?>>
					<span>Flightradar24 sharing enabled</span>
				</label>
				<label class="pisky-field">
					<span>Flightradar24 radar ID</span>
					<input class="form-control" type="text" name="flightradar24_radar_id"
						value="<?php echo htmlspecialchars(isset($sharing["flightradar24"]["radar_id"]) ? $sharing["flightradar24"]["radar_id"] : ""); ?>">
				</label>
			</div>
		</div>
	</section>

	<div class="pisky-setup-savebar">
		<div>
			<strong>Save the observatory configuration</strong>
			<span>Validated backups are created automatically.</span>
		</div>
		<button class="btn btn-primary" type="submit" <?php echo $useLogin ? "" : "disabled"; ?>>
			<i class="fa fa-save" aria-hidden="true"></i> Save PiSky setup
		</button>
	</div>
</form>

<section class="pisky-glass pisky-panel pisky-setup-section pisky-weewx-wizard" id="weewx-station">
	<div class="pisky-panel-heading">
		<div>
			<span class="pisky-eyebrow">Guided local weather setup</span>
			<h2>Connect your weather station</h2>
		</div>
		<span class="pisky-provider-pill <?php echo $weewxDataFresh ? "is-active" : ""; ?>">
			<?php
			if ($weewxDataFresh) echo "Receiving data";
			else if ($weewxServiceActive) echo "Listening";
			else if ($weewxDriverInstalled) echo "Ready to configure";
			else echo "Installer required";
			?>
		</span>
	</div>
	<p class="pisky-form-intro">
		Choose the family that matches your station. PiSky installs the reviewed
		driver, writes the WeeWX configuration and manages the service without SSH.
	</p>

	<?php if (!$weewxDriverInstalled) { ?>
	<div class="alert alert-warning">
		The curated station presets are not installed yet. Update this branch and
		run <code>./install-pisky.sh</code> again; the installer will preserve the
		existing camera and PiSky configuration.
	</div>
	<?php } ?>

	<div class="pisky-weewx-health">
		<div>
			<span>Preset driver</span>
			<strong><?php echo $weewxDriverInstalled ? "Installed" : "Missing"; ?></strong>
		</div>
		<div>
			<span>WeeWX service</span>
			<strong><?php echo $weewxServiceActive ? "Running" : "Stopped"; ?></strong>
		</div>
		<div>
			<span>Latest observation</span>
			<strong>
				<?php
				if ($weewxDataFresh && $weewxDataAge !== null) {
					echo htmlspecialchars(strval($weewxDataAge)) . " seconds ago";
				} else if ($weewxOutputExists && $weewxDataAge !== null) {
					echo htmlspecialchars(strval($weewxDataAge)) . " seconds old";
				} else {
					echo "Waiting for station";
				}
				?>
			</strong>
		</div>
	</div>

	<form method="post" class="pisky-weewx-preset-form">
		<input type="hidden" name="page" value="pisky_setup">
		<input type="hidden" name="pisky_action" value="configure_weewx_station">
		<?php if ($useLogin) CSRFToken(); ?>
		<div class="pisky-form-grid">
			<label class="pisky-field pisky-field-wide">
				<span>Station family</span>
				<select class="form-control" name="weewx_station_preset"
					data-pisky-weewx-preset>
					<option value="ecowitt" <?php echo $weewxPreset === "ecowitt" ? "selected" : ""; ?>>
						Ecowitt / Fine Offset custom server — recommended
					</option>
					<option value="wu-client" <?php echo $weewxPreset === "wu-client" ? "selected" : ""; ?>>
						Weather Underground compatible client
					</option>
					<option value="observer" <?php echo $weewxPreset === "observer" ? "selected" : ""; ?>>
						ObserverIP / Ambient / Fine Offset bridge
					</option>
				</select>
			</label>
			<label class="pisky-field">
				<span>Listener port</span>
				<input class="form-control" type="number" name="weewx_station_port"
					min="1024" max="65535"
					value="<?php echo htmlspecialchars(strval($weewxStationPort)); ?>">
			</label>
			<label class="pisky-field">
				<span>Station name</span>
				<input class="form-control" type="text" name="weewx_hardware_name"
					maxlength="80"
					value="<?php echo htmlspecialchars($weewxHardwareName); ?>"
					placeholder="Back garden weather station">
			</label>
		</div>

		<div class="pisky-weewx-endpoint">
			<span>Station sends to</span>
			<strong>
				<code><?php echo htmlspecialchars($stationEndpointHost); ?></code>
				<span>:</span>
				<code data-pisky-weewx-port><?php echo htmlspecialchars(strval($weewxStationPort)); ?></code>
			</strong>
			<small>If this address is not accepted by the station app, use the Pi’s numeric local IP address.</small>
		</div>

		<div class="pisky-weewx-guides">
			<article data-pisky-weewx-guide="ecowitt">
				<span class="pisky-guide-number">01</span>
				<div>
					<strong>Ecowitt / WS View setup</strong>
					<p>Open the device in WS View Plus or the Ecowitt app, choose
					<strong>Weather Services → Customized</strong>, select
					<strong>Ecowitt</strong> protocol, and enter this Pi’s local IP
					address or hostname with the listener port above. Use path
					<code>/</code> and an upload interval around 16 seconds.</p>
				</div>
			</article>
			<article data-pisky-weewx-guide="wu-client">
				<span class="pisky-guide-number">01</span>
				<div>
					<strong>Weather Underground client setup</strong>
					<p>In the station’s custom upload page, select its Weather
					Underground-compatible protocol and send it to this Pi’s local
					IP address or hostname using the listener port above.</p>
				</div>
			</article>
			<article data-pisky-weewx-guide="observer">
				<span class="pisky-guide-number">01</span>
				<div>
					<strong>ObserverIP / Ambient bridge setup</strong>
					<p>Set the bridge’s customised weather server to this Pi’s local
					IP address or hostname and use the listener port above. PiSky
					will apply the Observer parser and restart WeeWX.</p>
				</div>
			</article>
		</div>

		<div class="pisky-weewx-options">
			<label class="pisky-toggle">
				<input type="checkbox" name="weewx_enable_service" value="1"
					<?php echo !isset($weewxStation["enable_service"]) || $weewxStation["enable_service"] ? "checked" : ""; ?>>
				<span>Start WeeWX now and at boot</span>
			</label>
			<label class="pisky-toggle">
				<input type="checkbox" name="weewx_use_as_provider" value="1"
					<?php echo $weatherProvider === "weewx" ? "checked" : ""; ?>>
				<span>Use this station for PiSky’s live weather conditions</span>
			</label>
		</div>

		<div class="pisky-weewx-actions">
			<button class="btn btn-primary" type="submit"
				<?php echo $weewxCanConfigure ? "" : "disabled"; ?>>
				<i class="fa fa-magic" aria-hidden="true"></i>
				Save preset and start listening
			</button>
		</div>
	</form>

	<form method="post" class="pisky-weewx-test-form">
		<input type="hidden" name="page" value="pisky_setup">
		<input type="hidden" name="pisky_action" value="test_weewx_station">
		<?php if ($useLogin) CSRFToken(); ?>
		<button class="btn btn-default" type="submit"
			<?php echo $weewxCanConfigure ? "" : "disabled"; ?>>
			<i class="fa fa-plug" aria-hidden="true"></i>
			Check for live weather data
		</button>
		<span>A successful check confirms that PiSky has received a recent WeeWX observation.</span>
	</form>
</section>

<?php
$controlWarning = pisky_admin_control_warning();
if ($controlWarning !== "") {
?>
<div class="pisky-glass pisky-inline-notice is-error"><?php echo htmlspecialchars($controlWarning); ?></div>
<?php }

$historyError = "";
$history = pisky_admin_read_history($historyError);
$historyValue = function ($key, $default) use ($history) {
	return isset($history[$key]) && $history[$key] !== null ? $history[$key] : $default;
};
$historyDriverReady = class_exists("PDO")
	&& in_array("mysql", PDO::getAvailableDrivers(), true);
?>
<section class="pisky-setup-section">
	<div class="pisky-section-heading">
		<div>
			<span class="pisky-eyebrow">Detailed history</span>
			<h2>Remote weather database</h2>
		</div>
	</div>
	<p class="pisky-form-intro">
		PiSky always records a daily summary on this Pi. That is deliberately
		coarse, because writing a sample every few minutes for years wears out an
		SD card. Point PiSky at a database to keep the detail behind those
		summaries as well, which is what the archive graphs are drawn from. The
		daily record continues locally either way, so if the database is
		unreachable the station simply cannot draw the within-day curve.
	</p>
	<?php if (!$historyDriverReady) { ?>
	<div class="pisky-inline-notice is-error">
		PHP has no MySQL driver on this system, so a remote database cannot be used.
		Install <code>php-mysql</code> and restart the web server.
	</div>
	<?php } ?>
	<form method="post">
		<input type="hidden" name="page" value="pisky_setup">
		<input type="hidden" name="pisky_action" value="save_history">
		<?php if ($useLogin) CSRFToken(); ?>
		<div class="pisky-form-grid pisky-form-grid-four">
			<label class="pisky-toggle pisky-field-wide">
				<input type="checkbox" name="history_enabled" value="1"
					<?php echo !empty($history["enabled"]) ? "checked" : ""; ?>>
				<span>Store detailed history in a remote database</span>
			</label>
			<label class="pisky-field pisky-field-wide">
				<span>Database host</span>
				<input class="form-control" type="text" name="history_host" maxlength="255"
					placeholder="db.example.net"
					value="<?php echo htmlspecialchars(strval($historyValue("host", ""))); ?>">
			</label>
			<label class="pisky-field">
				<span>Port</span>
				<input class="form-control" type="number" name="history_port" min="1" max="65535"
					value="<?php echo htmlspecialchars(strval($historyValue("port", 3306))); ?>">
			</label>
			<label class="pisky-field">
				<span>Database name</span>
				<input class="form-control" type="text" name="history_database" maxlength="64"
					value="<?php echo htmlspecialchars(strval($historyValue("database", ""))); ?>">
			</label>
			<label class="pisky-field">
				<span>User</span>
				<input class="form-control" type="text" name="history_user" maxlength="64"
					autocomplete="off"
					value="<?php echo htmlspecialchars(strval($historyValue("user", ""))); ?>">
			</label>
			<label class="pisky-field">
				<span>Password</span>
				<input class="form-control" type="password" name="history_password" maxlength="256"
					autocomplete="new-password"
					placeholder="<?php echo !empty($history["password_set"]) ? "Stored — leave blank to keep" : ""; ?>">
				<small class="pisky-field-hint">Written by PiSky's privileged helper and never shown again. Leave blank to keep the stored password.</small>
			</label>
			<label class="pisky-field">
				<span>Station identifier</span>
				<input class="form-control" type="text" name="history_station" maxlength="64"
					value="<?php echo htmlspecialchars(strval($historyValue("station", "default"))); ?>">
				<small class="pisky-field-hint">Distinguishes this station's rows when several share one database.</small>
			</label>
			<label class="pisky-field">
				<span>Recording interval</span>
				<select class="form-control" name="history_interval">
					<?php
					$currentInterval = intval($historyValue("sample_interval_seconds", 300));
					$intervalChoices = array(
						60 => "Every minute", 120 => "Every 2 minutes",
						300 => "Every 5 minutes", 600 => "Every 10 minutes",
						900 => "Every 15 minutes", 1800 => "Every 30 minutes",
						3600 => "Every hour"
					);
					if (!isset($intervalChoices[$currentInterval])) $currentInterval = 300;
					foreach ($intervalChoices as $seconds => $label) {
						echo '<option value="' . $seconds . '"'
							. ($currentInterval === $seconds ? " selected" : "") . '>'
							. htmlspecialchars($label) . '</option>';
					}
					?>
				</select>
				<small class="pisky-field-hint">How often PiSky records a reading. A scheduled task does this whether or not anyone is viewing the station, so the recorded series stays even. Shorter intervals give smoother graphs and use more storage.</small>
			</label>
			<label class="pisky-field">
				<span>Keep detail for (days)</span>
				<input class="form-control" type="number" name="history_retain" min="7" max="3650"
					value="<?php echo htmlspecialchars(strval($historyValue("retain_days", 400))); ?>">
				<small class="pisky-field-hint">Older samples are discarded. Daily summaries are kept indefinitely.</small>
			</label>
			<label class="pisky-toggle pisky-field-wide">
				<input type="checkbox" name="history_tls" value="1"
					<?php echo $historyValue("tls", true) ? "checked" : ""; ?>>
				<span>Require an encrypted connection</span>
			</label>
		</div>
		<div class="pisky-setup-actions">
			<button class="btn btn-primary" type="submit">Save history database</button>
		</div>
	</form>
	<form method="post" class="pisky-weewx-test-form">
		<input type="hidden" name="page" value="pisky_setup">
		<input type="hidden" name="pisky_action" value="test_history">
		<?php if ($useLogin) CSRFToken(); ?>
		<button class="btn btn-default" type="submit"
			<?php echo $historyDriverReady && !empty($history["enabled"]) ? "" : "disabled"; ?>>
			<i class="fa fa-database" aria-hidden="true"></i>
			Test the database connection
		</button>
		<span>Creates the tables if they are missing and reports how many samples are stored.</span>
	</form>
</section>

<section class="pisky-setup-section">
	<div class="pisky-panel-heading">
		<div>
			<span class="pisky-eyebrow">Component health</span>
			<h2>Installed services</h2>
		</div>
	</div>
	<div class="pisky-service-grid">
		<?php
		if ($capabilities["camera"]) {
			pisky_setup_service_card("allsky", "Camera capture", "Sky-image capture and processing.", isset($services["allsky"]) ? $services["allsky"] : array(), $useLogin);
		}
		if ($capabilities["weather"]) {
			pisky_setup_service_card("weewx", "WeeWX", "Optional local weather-station collection and PiSky JSON output.", isset($services["weewx"]) ? $services["weewx"] : array(), $useLogin);
		}
		if ($capabilities["flights"]) {
			pisky_setup_service_card("dump1090-mutability", "Local ADS-B", "Aircraft decoding for RTL-SDR or a network-fed Mode-S Beast.", isset($services["dump1090-mutability"]) ? $services["dump1090-mutability"] : array(), $useLogin);
			pisky_setup_service_card("beast-splitter", "Mode-S Beast", "Serial and GPS-timestamp distribution for compatible Beast receivers.", isset($services["beast-splitter"]) ? $services["beast-splitter"] : array(), $useLogin);
			pisky_setup_service_card("piaware", "FlightAware sharing", "Optional outbound PiAware client; never a tracking source.", isset($services["piaware"]) ? $services["piaware"] : array(), $useLogin);
			pisky_setup_service_card("fr24feed", "Flightradar24 sharing", "Optional outbound fr24feed client; never a tracking source.", isset($services["fr24feed"]) ? $services["fr24feed"] : array(), $useLogin);
		}
		?>
	</div>
</section>

<?php if ($weewxConfig !== null && $useLogin) { ?>
<details class="pisky-glass pisky-panel pisky-advanced-config pisky-setup-section">
	<summary>
		<span>
			<span class="pisky-eyebrow">Advanced local station</span>
			<strong>Full WeeWX configuration</strong>
		</span>
		<i class="fa fa-chevron-down" aria-hidden="true"></i>
	</summary>
	<p>Configure the station driver and any hardware-specific settings here. PiSky validates required sections and creates a backup before restarting an active WeeWX service.</p>
	<form method="post">
		<input type="hidden" name="page" value="pisky_setup">
		<input type="hidden" name="pisky_action" value="save_weewx">
		<?php if ($useLogin) CSRFToken(); ?>
		<textarea class="form-control pisky-code-editor" name="weewx_config" rows="28"
			spellcheck="false"><?php echo htmlspecialchars($weewxConfig); ?></textarea>
		<button class="btn btn-primary" type="submit">Validate and save WeeWX</button>
	</form>
</details>
<?php } else if ($weewxError !== "") { ?>
	<div class="alert alert-warning pisky-setup-section"><?php echo htmlspecialchars($weewxError); ?></div>
<?php } ?>

<script>
(function () {
	var selector = document.querySelector("[data-pisky-weewx-preset]");
	if (!selector) return;
	var guides = document.querySelectorAll("[data-pisky-weewx-guide]");
	var portInput = document.querySelector("[name='weewx_station_port']");
	var portPreview = document.querySelector("[data-pisky-weewx-port]");
	var serviceToggle = document.querySelector("[name='weewx_enable_service']");
	var providerToggle = document.querySelector("[name='weewx_use_as_provider']");
	function showGuide() {
		Array.prototype.forEach.call(guides, function (guide) {
			guide.hidden = guide.getAttribute("data-pisky-weewx-guide") !== selector.value;
		});
	}
	function showPort() {
		if (portInput && portPreview) portPreview.textContent = portInput.value || "8000";
	}
	function keepProviderRunning() {
		if (providerToggle && providerToggle.checked && serviceToggle) {
			serviceToggle.checked = true;
		}
		if (providerToggle && serviceToggle) {
			serviceToggle.disabled = providerToggle.checked;
		}
	}
	selector.addEventListener("change", showGuide);
	if (portInput) portInput.addEventListener("input", showPort);
	if (providerToggle) providerToggle.addEventListener("change", keepProviderRunning);
	showGuide();
	showPort();
	keepProviderRunning();
})();
</script>

<?php
}
?>
