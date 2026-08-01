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

const sidebarStart = index.indexOf('<div class="navbar-default sidebar"');

assert.ok(sidebarStart !== -1, "the administration rail must exist");
assert.doesNotMatch(
	index,
	/navbar-static-top/,
	"nothing may sit above the rail: a bar that scrolls away leaves a gap over a pinned rail"
);
assert.ok(
	index.indexOf('class="pisky-sidebar-head"') > sidebarStart,
	"the brand must live inside the rail rather than in a separate header"
);
assert.match(
	index,
	/data-target="\.pisky-sidebar-collapse"/,
	"the menu toggle must only target the PiSky sidebar"
);
assert.match(
	css,
	/\.navbar-default\.sidebar\s*\{\s*position:\s*fixed;\s*top:\s*12px;\s*bottom:\s*12px;/,
	"the desktop rail must span the viewport so no gap can open above it"
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
// The radar credit strip carries the update time and the OpenStreetMap
// attribution. Positioned absolutely it sat underneath the target list and the
// selected-aircraft card, which both reach the same edge of the stage, so it
// gets a grid row of its own that nothing else is allowed to occupy.
const overlayStart = publicView.indexOf('class="pisky-scope-overlay"');
const overlayEnd = publicView.indexOf('</section>', overlayStart);
const creditStart = publicView.indexOf('class="pisky-scope-credit"');

assert.ok(
	creditStart > overlayStart && creditStart < overlayEnd,
	"the radar credit must sit inside the overlay grid, not float over it"
);
assert.match(
	css,
	/\.pisky-scope-overlay\s*\{[^}]*grid-template-rows:\s*auto minmax\(0, 1fr\) auto;/,
	"the radar overlay must reserve a row for the credit strip"
);
assert.match(
	css,
	/\.pisky-scope-detail\.is-open\s*\{[^}]*grid-row:\s*1 \/ 3;/,
	"an opened aircraft card must stop short of the credit row"
);
assert.doesNotMatch(
	css,
	/\.pisky-scope-detail\s*\{[^}]*transition:\s*max-height/,
	"opening the aircraft card must not be animated: Chrome parks a percentage max-height transition on its start value and the card never opens"
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
