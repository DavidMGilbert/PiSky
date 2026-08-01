"use strict";

/*
 * Setup's modal dialogs.
 *
 * Two properties are easy to lose in an edit and expensive to notice: an
 * advanced panel drifting outside the tab system, where it shows on every tab
 * and turns each of them into a scroll, and a dialog drifting inside it, where
 * the tab script would start hiding and showing something the browser already
 * manages.
 */

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

function read(relativePath) {
	return fs.readFileSync(path.join(__dirname, "..", relativePath), "utf8");
}

const setup = read("html/includes/piskySetup.php");
const css = read("html/css/pisky.css");
const codeDialog = read("html/js/pisky-code-dialog.js");
const mapPicker = read("html/js/pisky-map-picker.js");
const admin = read("html/index.php");

/* The advanced WeeWX panel belongs to the weather tab. */
const advanced = setup.indexOf("pisky-advanced-config");
assert.ok(advanced !== -1, "the advanced WeeWX panel must exist");
assert.match(
	setup.slice(advanced, advanced + 260),
	/data-pisky-tab-panel="weather"/,
	"the advanced WeeWX panel must belong to a tab, or it shows on all of them"
);

assert.doesNotMatch(
	setup,
	/<details[^>]*pisky-advanced-config/,
	"the raw configuration must open as a modal rather than expand in the page"
);

/*
 * Dialogs must not carry a panel attribute. The tab script sets .hidden, which
 * would fight the user-agent rules that already show and hide a dialog.
 */
const dialogPattern = /<dialog\b[^>]*>/g;
let match;
while ((match = dialogPattern.exec(setup)) !== null) {
	assert.doesNotMatch(
		match[0],
		/data-pisky-tab-panel/,
		"a dialog must stay outside the tab panels: " + match[0]
	);
}

/* Both editors are reached from a button, and every button has a dialog. */
const opens = [...setup.matchAll(/data-pisky-(?:code|map)-open="([^"]+)"/g)].map(m => m[1]);
assert.ok(opens.length >= 3, "expected the WeeWX editor and both location pickers");
opens.forEach(function (id) {
	assert.ok(
		setup.includes('id="' + id + '"') || setup.includes('htmlspecialchars($id)'),
		"the launcher for " + id + " must have a dialog to open"
	);
});

/* Scripts have to be loaded, or every launcher is inert. */
assert.match(admin, /pisky-code-dialog\.js/, "the admin must load the configuration editor");
assert.match(admin, /pisky-map-picker\.js/, "the admin must load the location picker");

/*
 * The highlight layer and the textarea are two stacked copies of the same text.
 * Anything affecting glyph position must be declared for both together, so the
 * colours cannot drift away from the characters they belong to.
 */
const shared = css.match(/\.pisky-code-highlight,\s*\.pisky-code-editor\s*\{[^}]*\}/);
assert.ok(shared, "the editor layers must share one metrics rule");
["font-family", "font-size", "line-height", "padding", "white-space", "tab-size"].forEach(function (property) {
	assert.ok(
		shared[0].includes(property + ":"),
		property + " must be set on both editor layers at once"
	);
});

/*
 * Panning is clamped in world pixels. Clamping the latitude instead ratchets:
 * dragging to the pole and back does not return the crosshair where it was.
 */
assert.match(
	mapPicker,
	/Math\.max\(0, Math\.min\(size, centre\.y - dy\)\)/,
	"the map must clamp panning in world pixels so a drag stays reversible"
);

/* Markup in a configuration file must never become markup in the page. */
assert.match(
	codeDialog,
	/replace\(\/&\/g, "&amp;"\)/,
	"the highlighter must escape the file it renders"
);
const writes = codeDialog.match(/\.innerHTML\s*=[^\n]*/g) || [];
assert.equal(writes.length, 1, "the highlighter should write HTML in exactly one place");
assert.match(
	writes[0],
	/=\s*grammar\(input\.value\)/,
	"only the escaped, highlighted output may be written as HTML"
);

console.log("PiSky setup dialog placement and editor safety passed.");
