<?php

if (!defined("PISKY_ADMIN_ENTRY")) {
	require __DIR__ . "/public.php";
	exit;
}

/**
 * PiSky Web User Interface
 *
 * Modern interface layer for the Allsky camera project.
 * PiSky additions Copyright (c) 2026 David Gilbert.
 *
 * Enables use of a web interface rather than SSH to control a camera on the Raspberry Pi.
 * Uses code from RaspAP by Lawrence Yau <sirlagz@gmail.com> and Bill Zimmerman <billzimmerman@gmail.com>
 *
 * @author     Lawrence Yau <sirlagz@gmail.comm>
 * @author     Bill Zimmerman <billzimmerman@gmail.com>
 * @author     Thomas Jacquin <jacquin.thomas@gmail.com>
 * @author     David Gilbert <https://davidmgilbert.com>
 * @license    GNU General Public License, version 3 (GPL-3.0)
 * @version    0.0.1
 */

// Globals
$lastChangedName = "lastchanged";	// json setting name
$formReadonly = false;				// The WebUI isn't readonly
$ME = htmlspecialchars($_SERVER["PHP_SELF"]);

// functions.php sets a bunch of constants and variables.
// It needs to be at the top of this file since code below uses the items it sets.
include_once('includes/functions.php');
include_once('includes/status_messages.php');
$status = new StatusMessages();
initialize_variables();		// sets some variables
include_once("includes/piskySite.php");
$piskyCapabilities = pisky_site_capabilities();

// PiSky always protects its administration route, even when the public view is open.
$useLogin = true;
if ($useLogin && session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

// Constants for configuration file paths.
// These are typical for default RPi installs. Modify if needed.
include_once('includes/authenticate.php');
define('RASPI_WPA_SUPPLICANT_CONFIG', '/etc/wpa_supplicant/wpa_supplicant.conf');
define('RASPI_WPA_CTRL_INTERFACE', '/var/run/wpa_supplicant');

// Optional services, set to true to enable.
define('DHCP_ENABLED', true);
define('APD_ENABLED', false);
define('RASPI_OPENVPN_ENABLED', false);
define('RASPI_TORPROXY_ENABLED', false);

if (DHCP_ENABLED) {
	define('RASPI_DNSMASQ_CONFIG', '/etc/dnsmasq.conf');
	define('RASPI_DNSMASQ_LEASES', '/var/lib/misc/dnsmasq.leases');
} else {
	function DisplayDHCPConfig() {}
}
if (APD_ENABLED) {
	define('RASPI_HOSTAPD_CONFIG', '/etc/hostapd/hostapd.conf');
	define('RASPI_HOSTAPD_CTRL_INTERFACE', '/var/run/hostapd');
} else {
	function DisplayHostAPDConfig() {}
}
if (RASPI_OPENVPN_ENABLED || RASPI_TORPROXY_ENABLED) {
	include_once('includes/torAndVPN.php');
	define('RASPI_OPENVPN_CLIENT_CONFIG', '/etc/openvpn/client.conf');
	define('RASPI_OPENVPN_SERVER_CONFIG', '/etc/openvpn/server.conf');
	define('RASPI_TORPROXY_CONFIG', '/etc/tor/torrc');
} else {
	function SaveTORAndVPNConfig() {}
	function DisplayOpenVPNConfig() {}
	function DisplayTorProxyConfig() {}
}

$output = $return = 0;
if (isset($_POST['page']))
	$page = $_POST['page'];
else if (isset($_GET['page']))
	$page = $_GET['page'];
else
	$page = "";
$piskyDefaultPage = $piskyCapabilities["camera"]
	? "live_view"
	: ($piskyCapabilities["weather"]
		? "weather"
		: ($piskyCapabilities["flights"] ? "flights" : "content"));
$piskyCameraPages = array(
	"configuration", "list_days", "list_images", "list_videos",
	"list_keograms", "list_startrails", "live_view"
);
if ($page === "" || (!$piskyCapabilities["camera"]
	&& in_array($page, $piskyCameraPages, true))) {
	$page = $piskyDefaultPage;
}
if (isset($_GET['day']))
	$day = " - " . $_GET['day'];
else
	$day = "";

if ($useLogin) {
	if (empty($_SESSION['csrf_token'])) {
		if (function_exists('mcrypt_create_iv')) {
			$_SESSION['csrf_token'] = bin2hex(mcrypt_create_iv(32, MCRYPT_DEV_URANDOM));
		} else {
			$_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
		}
	}
	$csrf_token = $_SESSION['csrf_token'];
}

// Get the version of the remote Allsky Website, if it exists.
$remoteWebsiteVersion = "";
if ($useRemoteWebsite) {
	$f = getRemoteWebsiteConfigFile(); 
	$errorMsg = "WARNING: ";
	$retMsg = "";
	$a_array = get_decoded_json_file($f, true, $errorMsg, $retMsg);
	if ($a_array === null) {
		$status->addMessage($retMsg, 'warning');
	} else {
		$c = getVariableOrDefault($a_array, 'config', '');
		if ($c !== "") {
			$remoteWebsiteVersion = getVariableOrDefault($c, 'AllskyVersion', null);
			if ($remoteWebsiteVersion === null) {
				$remoteWebsiteVersion = '<span class="errorMsg">[version unknown]</span>';
			} else if ($remoteWebsiteVersion == ALLSKY_VERSION) {
				$remoteWebsiteVersion = "";		// don't display if same version as Allsky
			} else {
				$remoteWebsiteVersion = "&nbsp; (version $remoteWebsiteVersion)";
			}
		}
	}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="description" content="PiSky modular sky-observation control centre">
	<meta name="author" content="David Gilbert">

<?php	// Give each page its own <title> so they are easy to distinguish in the browser.
	switch ($page) {
		case "WLAN_info":			$Title = "WLAN Dashboard";		break;
		case "LAN_info":			$Title = "LAN Dashboard";		break;
		case "configuration":		$Title = "Camera Settings";		break;
		case "pisky_setup":			$Title = "PiSky Setup";			break;
		case "weather":				$Title = "Weather";				break;
		case "flights":				$Title = "Air Traffic";			break;
		case "content":				$Title = "Public Content";		break;
		case "wifi":				$Title = "Configure Wi-Fi";		break;
		case "dhcp_conf":			$Title = "Configure DHCP";		break;
		case "hostapd_conf":		$Title = "Configure Hotspot";	break;
		case "openvpn_conf":		$Title = "Configure OpenVPN";	break;
		case "torproxy_conf":		$Title = "Configure TOR proxy";	break;
		case "auth_conf":			$Title = "Change Password";		break;
		case "system":				$Title = "System";				break;
		case "list_days":			$Title = "Images";				break;
		case "list_images":			$Title = "Images$day";			break;
		case "list_videos":			$Title = "Timelapse$day";		break;
		case "list_keograms":		$Title = "Keogram$day";			break;
		case "list_startrails":		$Title = "Startrails$day";		break;
		case "editor":				$Title = "Editor";				break;
		case "live_view":			$Title = "Live View";			break;
		case "support": 			$Title = "Getting Support";		break;
		default:					$Title = "PiSky Control Centre";	break;
	}
?>
	<!-- allows <a external="true" ...> -->
	<script src="/documentation/js/documentation.js" type="application/javascript"></script>

	<title><?php echo "$Title - PiSky"; ?></title>

	<!-- Bootstrap Core CSS -->
	<link href="/documentation/bower_components/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">

	<!-- MetisMenu CSS -->
	<link href="/documentation/bower_components/metisMenu/dist/metisMenu.min.css" rel="stylesheet">

	<link href="/documentation/css/sb-admin-2.css" rel="stylesheet">

	<!-- Font Awesome -->
	<script defer src="/documentation/js/all.min.js"></script>

	<!-- Custom CSS -->
	<link href="/documentation/css/custom.css" rel="stylesheet">
	<link href="/css/pisky.css?c=<?php echo ALLSKY_VERSION; ?>" rel="stylesheet">

	<link rel="icon" type="image/svg+xml" href="/pisky-favicon.svg">
	<link rel="alternate icon" type="image/png" href="/allsky/allsky-favicon.png">

	<!-- RaspAP JavaScript -->
	<script src="/documentation/js/functions.js"></script>

	<!-- jQuery -->
	<script src="/documentation/bower_components/jquery/dist/jquery.min.js"></script>

	<!-- Bootstrap Core JavaScript -->
	<script src="/documentation/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>

	<!-- Metis Menu Plugin JavaScript -->
	<script src="/documentation/bower_components/metisMenu/dist/metisMenu.min.js"></script>

	<script src="/js/bigscreen.min.js"></script>

	<script src="/js/pisky-theme.js?c=<?php echo filemtime(__DIR__ . "/js/pisky-theme.js"); ?>"></script>
	<script src="/js/allsky.js"></script>
	<script src="/js/pisky-weather-icons.js?c=<?php echo filemtime(__DIR__ . "/js/pisky-weather-icons.js"); ?>"></script>
	<script src="/js/pisky-weather.js?c=<?php echo filemtime(__DIR__ . "/js/pisky-weather.js"); ?>"></script>
	<script src="/js/pisky-flights.js?c=<?php echo filemtime(__DIR__ . "/js/pisky-flights.js"); ?>"></script>
	<script src="/js/pisky-setup-tabs.js?c=<?php echo filemtime(__DIR__ . "/js/pisky-setup-tabs.js"); ?>"></script>
	<script> var allskyPage='<?php echo $page ?>';  </script>

	<!-- Custom Theme JavaScript -->
	<script src="/documentation/js/sb-admin-2.js"></script>

	<!-- Code Mirror editor -->
<?php if ($page === "editor") { ?>
	<link rel="stylesheet" href="/lib/codeMirror/codemirror.css">
	<link rel="stylesheet" href="/lib/codeMirror/monokai.min.css">
	<link rel="stylesheet" href="/lib/codeMirror/lint.css">
	<script type="text/javascript" src="/lib/codeMirror/codemirror.js"> </script>
	<script type="text/javascript" src="/lib/codeMirror/json.js"> </script>
	<script type="text/javascript" src="/lib/codeMirror/jsonlint.js"> </script>
	<script type="text/javascript" src="/lib/codeMirror/lint.js"> </script>
	<script type="text/javascript" src="/lib/codeMirror/json-lint.js"> </script>

	<script src="/lib/codeMirror/matchesonscrollbar.js"></script>
	<script src="/lib/codeMirror/searchcursor.js"></script>
	<script src="/lib/codeMirror/match-highlighter.js"></script>

	<script src="/js/jquery-loading-overlay/dist/loadingoverlay.min.js?c=<?php echo ALLSKY_VERSION; ?>"></script>
	<script src="/js/bootbox/bootbox.all.js?c=<?php echo ALLSKY_VERSION; ?>"></script>
	<script src="/js/bootbox/bootbox.locales.min.js?c=<?php echo ALLSKY_VERSION; ?>"></script>

<?php } ?>
</head>
<body class="pisky-admin">
<div id="wrapper">
	<!-- Navigation -->
	<nav class="navbar navbar-default navbar-static-top" role="navigation" style="margin-bottom: 0">
		<div class="navbar-header">
			<button type="button" class="navbar-toggle as-nav-toggle" data-toggle="collapse" data-target=".pisky-sidebar-collapse" aria-expanded="false">
				<span class="sr-only">Toggle navigation</span>
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
				<span class="icon-bar"></span>
			</button>
			<div class="navbar-brand valign-center pisky-navbar-brand">
				<a id="index" class="navbar-brand valign-center" href="/admin/">
					<span class="pisky-brand-mark" aria-hidden="true"><span></span></span>
					<div class="pisky-brand-copy">
						<strong>PiSky</strong>
						<small>Modular sky observations</small>
					</div>
				</a>
				<div class="version-title version-title-color">
					<span id="allskyStatus"><?php
						echo $piskyCapabilities["camera"]
							? output_allsky_status()
							: "<span class='nowrap alert-success'>Platform: Running</span><br>";
					?></span>
<?php
					$versionInfo = $piskyCapabilities["camera"]
						? getNewestAllskyVersion($changed) : null;
					if ($versionInfo !== null) {
						$newestVersion = $versionInfo['version'];
					} else {
						$newestVersion = null;
					}
					if ($newestVersion !== null && $newestVersion > ALLSKY_VERSION) {
						$note = getVariableOrDefault($versionInfo, "versionNote", "");
						$more = "title='New Version $newestVersion Available";
						if ($note !== "") {
							$more .= ", $note";
						}
						$more .= "' style='background-color: red; color: white;'";

						if ($changed) {
							$x = "<br>&nbsp; &nbsp;";
							$msg = "$x<strong>";
							$msg .= "A new release of Allsky is available: $newestVersion";
							$msg .= "</strong>";
							if ($note !== "") {
								$msg .= "$x$note";
							}
							$msg .= "<br><br>";
							$cmd = ALLSKY_SCRIPTS . "/addMessage.sh";
							$cmd .= " --no-date --type success --msg '${msg}'";
							runCommand($cmd, "", "");
						}
					} else {
						$more = "";
					}
					echo "<span class='nowrap'>";
						$displayVersion = $piskyCapabilities["camera"]
							? ALLSKY_VERSION
							: trim(@file_get_contents(dirname(__DIR__) . "/PISKY_VERSION"));
						echo "<span $more>Version: " . htmlspecialchars($displayVersion) . "</span>";
						echo "&nbsp; on &nbsp;";
						echo "<span style='font-weight: bold'>$hostname</span>";
					echo "</span>";
if ($useLocalWebsite) {
					echo "<br>";
					echo "<span class='nowrap'>";
					echo "<a external='true' class='version-title-color' href='/allsky/index.php'>";
					echo "Local Website</a>";
					echo "</span>";
}
if ($useRemoteWebsite) {
					echo "&nbsp;&nbsp;&nbsp;&nbsp; ";
					echo "<span class='nowrap'>";
					echo "<a external='true' class='version-title-color' href='$remoteWebsiteURL'>";
					echo "Remote Website $remoteWebsiteVersion</a>";
					echo "</span>";
} ?>
				</div>
		</div> <!-- /.navbar-header -->
	</nav>

	<!-- Navigation.  Add "id" to any page that needs to be refreshed. -->
	<div class="navbar-default sidebar" role="navigation">
		<div class="sidebar-nav navbar-collapse pisky-sidebar-collapse">
			<ul class="nav" id="side-menu">
					<li class="pisky-nav-label">Observe</li>
					<?php if ($piskyCapabilities["camera"]) { ?>
					<li>
						<a id="live_view" href="/admin/?page=live_view"><i class="fa fa-eye fa-fw"></i> Live View</a>
					</li>
					<li>
						<a id="list_days" href="/admin/?page=list_days"><i class="fa fa-image fa-fw"></i> Images</a>
					</li>
					<?php } ?>
					<?php if ($piskyCapabilities["weather"]) { ?>
					<li>
						<a id="weather" href="/admin/?page=weather"><i class="fa fa-cloud-sun fa-fw"></i> Weather</a>
					</li>
					<?php } ?>
					<?php if ($piskyCapabilities["flights"]) { ?>
					<li>
						<a id="flights" href="/admin/?page=flights"><i class="fa fa-plane fa-fw"></i> Air Traffic</a>
					</li>
					<?php } ?>
					<li class="pisky-nav-label">Configure</li>
					<li>
						<a id="pisky_setup" href="/admin/?page=pisky_setup"><i class="fa fa-sliders-h fa-fw"></i> PiSky Setup</a>
					</li>
					<?php if ($piskyCapabilities["camera"]) { ?>
					<li>
						<a id="configuration" href="/admin/?page=configuration"><i class="fa fa-camera fa-fw"></i> Camera Settings</a>
					</li>
					<?php } ?>
					<li>
						<a id="content" href="/admin/?page=content"><i class="fa fa-pen-fancy fa-fw"></i> Public Content</a>
					</li>
					<?php if ($piskyCapabilities["camera"]) { ?>
					<?php } ?>
					<li class="pisky-nav-label">Network &amp; system</li>
					<li>
						<a id="LAN" href="/admin/?page=LAN_info"><i class="fa fa-network-wired fa-fw"></i> <b>LAN</b> Dashboard</a>
					</li>
					<li>
						<a id="WLAN" href="/admin/?page=WLAN_info"><i class="fa fa-tachometer-alt fa-fw"></i> <b>WLAN</b> Dashboard</a>
					</li>
					<li>
						<a id="wifi" href="/admin/?page=wifi"><i class="fa fa-wifi fa-fw"></i> Configure Wi-Fi</a>
					</li>
					<?php if (DHCP_ENABLED) : ?>
						<li>
							<a id="vpn" href="/admin/?page=dhcp_conf"><i class="fa fa-exchange fa-fw"></i> Configure DHCP</a>
						</li>
					<?php endif; ?>
					<?php if (APD_ENABLED) : ?>
						<li>
							<a id="vpn" href="/admin/?page=hostapd_conf"><i class="fa fa-dot-circle fa-fw"></i> Configure Hotspot</a>
						</li>
					<?php endif; ?>
					<?php if (RASPI_OPENVPN_ENABLED) : ?>
						<li>
							<a id="vpn" href="/admin/?page=openvpn_conf"><i class="fa fa-lock fa-fw"></i> Configure OpenVPN</a>
						</li>
					<?php endif; ?>
					<?php if (RASPI_TORPROXY_ENABLED) : ?>
						<li>
							<a id="tor" href="/admin/?page=torproxy_conf"><i class="fa fa-eye-slash fa-fw"></i> Configure TOR proxy</a>
						</li>
					<?php endif; ?>
					<li>
						<a id="auth_conf" href="/admin/?page=auth_conf"><i class="fa fa-lock fa-fw"></i> Change Password</a>
					</li>
					<li>
						<a id="system" href="/admin/?page=system"><i class="fa fa-cube fa-fw"></i> System</a>
					</li>
					<li class="pisky-nav-label">PiSky project</li>
					<li>
						<a external="true" href="https://wiki.pisky.space" target="_blank" rel="noopener"><i class="fa fa-book fa-fw"></i> PiSky Docs</a>
					</li>
					<li>
						<a external="true" href="https://pisky.space/community" target="_blank" rel="noopener"><i class="fa fa-comments fa-fw"></i> Community &amp; Support</a>
					</li>
					<li>
						<a href="/admin/?pisky_logout=1"><i class="fa fa-sign-out-alt fa-fw"></i> Sign out</a>
					</li>
					<li>
						<button class="pisky-theme-toggle" type="button" data-pisky-theme-toggle>
							<i data-pisky-theme-icon aria-hidden="true">◐</i>
							<span>Theme: <b data-pisky-theme-label>Auto</b></span>
						</button>
					</li>

			</ul>
		</div><!-- /.navbar-collapse -->
	</div><!-- /.navbar-default -->

	<div id="page-wrapper">
		<div class="row right-panel">
			<div class="col-lg-12">
				<?php
				if ($piskyCapabilities["camera"]) {
					check_if_configured($page, "main");	// It calls addMessage() on error.
				}

				if (isset($_POST['clear'])) {
					$t = @filemtime(ALLSKY_MESSAGES);
					// if it fails it's probably because something else deleted the file,
					// in which case we don't care.
					if ($t != false) {
						$newT = getVariableOrDefault($_POST, "filetime", 0);
						if ($t == $newT) {
							exec("sudo rm -f " . ALLSKY_MESSAGES, $result, $retcode);
							if ($retcode !== 0) {
								$status->addMessage("Unable to clear messages: " . $result[0], 'danger');
								$status->showMessages();
							}
						} else {
							// If the messages changed after the user viewed the last page
							// and before they clicked the "Clear" button,
							// we'll have the old time in $filetime, but the timestamp of the file
							// won't match so we'll get here, and then display the messages below.
							$status->addMessage("System Messages changed.  New content is:", "warning");
						}
					}
				}
				clearstatcache();
				$size = @filesize(ALLSKY_MESSAGES);
				if ($size !== false && $size > 0) {
					$contents_array = file(ALLSKY_MESSAGES, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
					echo "<div class='row'>"; echo "<div class='system-message'>";
						echo "<div class='title'>System Messages</div>";
						foreach ($contents_array as $line) {
							// Format: id, cmd_txt, level (i.e., CSS class), date, count, message [, url]
							//         0   1        2                        3     4      5          6
							$cmd = "";
							$message_array = explode("\t", $line);
							$message = getVariableOrDefault($message_array, 5, null);
							if ($message !== null) {
								$id = getVariableOrDefault($message_array, 0, "");
								$cmd_txt = getVariableOrDefault($message_array, 1, "");
								$level = $message_array[2];
								$date = $message_array[3];
								$count = $message_array[4];
								$url = getVariableOrDefault($message_array, 6, "");
								if ($url !== "") {
									$m1 = "<a href='$url' title='Click for more information' target='_messages'>";
									$m2 = "<i class='fa fa-external-link-alt fa-fw'></i>";
									$m2 = "<span class='externalSmall'>$m2</span>";
									$message = "${m1}${message}${m2}</a>";
								}

								if ($id !== "") {
									$m1 = "<br><a href='/execute.php?id=" . urlencode($id) . "'";
									$m1 .= " class='executeAction' title='Click to perform action' target='_actions'>";
									$message .= "${m1}${cmd_txt}</a>";
								}

								if ($count == 1) {
									if ($date !== "")
										$message .= " &nbsp; ($date)";
								} else {
									$message .= " &nbsp; ($count occurrences";
									if ($date !== "")
										$message .= ", last on $date";
									$message .= ")";
								}
							} else {
								$level = "error";	// badly formed message
								$message = "INTERNAL ERROR: Poorly formatted message: $line";
							}
							$status->addMessage($message, $level);
							if ($cmd !== "") {
								$status->addMessage($cmd, $level);
							}
						}
						$status->showMessages();
						echo "<br><div class='message-button'>";
							$ts = time();
							echo "<form action='$ME?_ts=$ts' method='POST'>";
							echo "<input type='hidden' name='page' value='$page'>";
							echo "<input type='hidden' name='clear' value='true'>";
							$t = @filemtime(ALLSKY_MESSAGES);
							echo "<input type='hidden' name='filetime' value='$t'>";
							echo "<input type='submit' class='btn btn-primary' value='Clear messages' />";
							echo "</form>";
						echo "</div>";
					echo "</div>"; echo "</div>";// /.system-message and /.row
				}

				switch ($page) {
					case "WLAN_info":
						include_once('includes/dashboard_WLAN.php');
						DisplayDashboard_WLAN();
						break;
					case "LAN_info":
						include_once('includes/dashboard_LAN.php');
						DisplayDashboard_LAN();
						break;
					case "configuration":
						include_once('includes/allskySettings.php');
						DisplayAllskyConfig();
						break;
					case "pisky_setup":
						include_once('includes/piskySetup.php');
						DisplayPiSkySetup();
						break;
					case "weather":
						include_once('includes/weather.php');
						DisplayPiSkyWeather();
						break;
					case "flights":
						include_once('includes/flights.php');
						DisplayPiSkyFlights();
						break;
					case "content":
						include_once('includes/piskyContent.php');
						DisplayPiSkyContent();
						break;
					case "wifi":
						include_once('includes/configureWiFi.php');
						DisplayWPAConfig();
						break;
					case "dhcp_conf":
						include_once('includes/dhcp.php');
						DisplayDHCPConfig();
						break;
					case "hostapd_conf":
						include_once('includes/hostapd.php');
						DisplayHostAPDConfig();
						break;
					case "openvpn_conf":
						include_once('includes/torAndVPN.php');
						DisplayTorProxyConfig();
						DisplayOpenVPNConfig();
						break;
					case "torproxy_conf":
						include_once('includes/torAndVPN.php');
						DisplayTorProxyConfig();
						break;
					case "save_hostapd_conf":
						SaveTORAndVPNConfig();
						break;
					case "auth_conf":
						include_once('includes/admin.php');
						DisplayAuthConfig($config['admin_user'], $config['admin_pass']);
						break;
					case "system":
						include_once('includes/system.php');
						DisplaySystem();
						break;
					case "list_days":
						include_once('includes/days.php');
						ListDays();
						break;
					case "list_images":
						include_once('includes/images.php');
						ListImages();
						break;
					case "list_videos":
						// directory, file name prefix, formal name, type of file
						ListFileType("", "allsky", "Timelapse", "video");
						break;
					case "list_keograms":
						// directory, file name prefix, formal name, type of file
						ListFileType("keogram/", "keogram", "Keogram", "picture");
						break;
					case "list_startrails":
						// directory, file name prefix, formal name, type of file
						ListFileType("startrails/", "startrails", "Startrails", "picture");
						break;
					case "editor":
						include_once('includes/editor.php');
						DisplayEditor();
						break;
					case "support":
						include_once('includes/support.php');
						break;

					case "live_view":
					default:
						include_once('includes/liveview.php');
						DisplayLiveView($image_name, $delay, $daydelay, $daydelay_postMsg, $nightdelay, $nightdelay_postMsg, $darkframe);
				}
				?>
			</div>
		</div>
		<footer class="pisky-admin-footer">
			<span>PiSky interface by <a href="https://davidmgilbert.com" target="_blank" rel="noopener">David Gilbert</a></span>
			<span>Built on <a href="https://github.com/AllskyTeam/allsky" target="_blank" rel="noopener">Allsky</a> · Weather via <a href="https://weewx.com" target="_blank" rel="noopener">WeeWX</a>/<a href="https://open-meteo.com" target="_blank" rel="noopener">Open-Meteo</a> · Local ADS-B receiver support</span>
		</footer>
	</div><!-- /#page-wrapper -->
</div><!-- /#wrapper -->

</body>
</html>
