"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

function read(relativePath) {
	return fs.readFileSync(path.join(__dirname, "..", relativePath), "utf8");
}

const index = read("html/index.php");
const adminIndex = read("html/admin/index.php");
const authentication = read("html/includes/authenticate.php");
const publicView = read("html/public.php");
const liveView = read("html/includes/liveview.php");
const css = read("html/css/pisky.css");
const navigation = read("html/documentation/js/sb-admin-2.js");
const weatherClient = read("html/js/pisky-weather.js");
const flightsClient = read("html/js/pisky-flights.js");
const installer = read("install-pisky.sh");

const topNavigationEnd = index.indexOf("</nav>");
const sidebarStart = index.indexOf('<div class="navbar-default sidebar"');

assert.ok(topNavigationEnd !== -1, "top navigation must close");
assert.ok(
	sidebarStart > topNavigationEnd,
	"the fixed sidebar must sit outside the blurred header containing block"
);
assert.match(
	index,
	/data-target="\.pisky-sidebar-collapse"/,
	"the menu toggle must only target the PiSky sidebar"
);
assert.match(
	css,
	/\.navbar-default\.sidebar\s*\{\s*position:\s*fixed;/,
	"desktop sidebar must be fixed to the viewport"
);
assert.match(
	css,
	/@media \(max-width: 900px\)[\s\S]*?\.navbar-default\.sidebar\s*\{\s*position:\s*relative;/,
	"compact sidebar must return to document flow"
);
assert.match(
	navigation,
	/width < 901[\s\S]*?sidebar-nav\.navbar-collapse/,
	"navigation script must use the same compact breakpoint as the PiSky styles"
);
assert.match(
	index,
	/if \(!defined\("PISKY_ADMIN_ENTRY"\)\)[\s\S]*?public\.php/,
	"the appliance root must dispatch to the public observatory"
);
assert.match(
	adminIndex,
	/define\("PISKY_ADMIN_ENTRY", true\)/,
	"/admin/ must explicitly enter the authenticated control interface"
);
assert.match(
	authentication,
	/\$useLogin\s*=\s*true;/,
	"every inherited administration endpoint must enforce the PiSky login"
);
assert.doesNotMatch(
	index,
	/href="index\.php\?page=/,
	"admin navigation must not leak back to the public root"
);
assert.match(
	publicView,
	/href="\/admin\/"/,
	"the public observatory must link to the dedicated admin login"
);
assert.match(
	publicView,
	/href="\/\?view=archive"/,
	"the public archive must use PiSky's working appliance route"
);
assert.match(
	liveView,
	/href="\/"/,
	"the admin live view must link back to the public appliance root"
);
assert.match(
	weatherClient,
	/const endpoint = "\/pisky-weather\.php";/,
	"weather data must resolve from both the public root and /admin/"
);
assert.match(
	flightsClient,
	/const endpoint = "\/pisky-flights\.php";/,
	"flight data must resolve from both the public root and /admin/"
);
assert.match(
	index,
	/filemtime\(__DIR__ \. "\/js\/pisky-weather\.js"\)/,
	"the admin must invalidate cached weather clients after an update"
);
assert.match(
	publicView,
	/filemtime\(__DIR__ \. "\/js\/pisky-flights\.js"\)/,
	"the public view must invalidate cached flight clients after an update"
);
assert.doesNotMatch(index, /page=overlay|page=module/, "broken overlay and module controls must stay hidden");
assert.match(index, /data-pisky-theme-toggle/, "the admin must expose the shared theme controller");
assert.match(publicView, /pisky_site_capabilities/, "public navigation must follow enabled capabilities");
assert.match(
	publicView,
	/href="\/\?view=overview"/,
	"the station overview must be reachable from public navigation"
);
assert.match(
	publicView,
	/if \(\$moduleCount > 1\) array_unshift\(\$availableViews, "overview"\);/,
	"the overview must lead the view list so it becomes the default landing page"
);
assert.match(
	publicView,
	/\$moduleCount > 1/,
	"a single-module station must skip the overview and land on its own page"
);
assert.match(
	publicView,
	/\$capabilities\["camera"\] && \(\$publicView === "live" \|\| \$publicView === "overview"\)/,
	"the overview must refresh its live sky image like the dedicated sky page"
);
assert.match(
	installer,
	/Camera capability is disabled; skipping camera detection and the Allsky installer/,
	"camera-free installations must not be forced through camera detection"
);
assert.match(
	installer,
	/install_headless_web_foundation/,
	"weather-only and aircraft-only profiles must install the PiSky web foundation"
);
assert.match(
	index,
	/\$piskyDefaultPage = \$piskyCapabilities\["camera"\]/,
	"the admin landing page must follow the station's enabled capabilities"
);

console.log("PiSky responsive navigation and public/admin routing passed.");
