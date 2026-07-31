/*
 * Weather icon code mapping.
 *
 * The icon set is keyed to WMO weather codes. The edge cases matter more than
 * the happy path: Number(null) and Number("") are both 0, which is the code
 * for clear sky, so a station that reports no code at all must not be given a
 * sun, and codes above the WMO range must not be mistaken for thunderstorms.
 *
 * SPDX-License-Identifier: MIT
 */

const assert = require("node:assert");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const source = fs.readFileSync(
	path.join(__dirname, "..", "html", "js", "pisky-weather-icons.js"), "utf8"
);

// The module only needs a window to attach to; nothing here builds DOM nodes.
const sandbox = { window: {}, document: { createElementNS: () => ({}) } };
vm.createContext(sandbox);
vm.runInContext(source, sandbox);
const describe = sandbox.window.piskyWeatherIcons.describe;

const kind = (code, isDay) => describe(code, isDay).kind;

// Day and night split only where the sky is visible.
assert.strictEqual(kind(0, true), "clear-day");
assert.strictEqual(kind(0, false), "clear-night");
assert.strictEqual(kind(1, true), "partly-day");
assert.strictEqual(kind(2, false), "partly-night");
assert.strictEqual(kind(3, false), "cloud", "overcast looks the same at night");

// Representative codes across each band.
assert.strictEqual(kind(45, true), "fog");
assert.strictEqual(kind(48, true), "fog");
assert.strictEqual(kind(51, true), "drizzle");
assert.strictEqual(kind(57, true), "drizzle");
assert.strictEqual(kind(61, true), "rain");
assert.strictEqual(kind(67, true), "rain");
assert.strictEqual(kind(71, true), "snow");
assert.strictEqual(kind(77, true), "snow");
assert.strictEqual(kind(80, true), "showers");
assert.strictEqual(kind(82, true), "showers");
assert.strictEqual(kind(85, true), "snow");
assert.strictEqual(kind(86, true), "snow");

// Band boundaries: 95-99 are thunderstorms, nothing either side of them is.
assert.strictEqual(kind(94, true), "cloud", "94 is not a thunderstorm");
assert.strictEqual(kind(95, true), "storm");
assert.strictEqual(kind(99, true), "storm");
assert.strictEqual(kind(100, true), "cloud", "100 is outside the WMO range");
assert.strictEqual(kind(999, true), "cloud", "999 must not read as a storm");

// Absent or unusable codes fall back rather than implying clear sky.
assert.strictEqual(kind(null, true), "cloud", "a missing code is not clear sky");
assert.strictEqual(kind(undefined, true), "cloud");
assert.strictEqual(kind("", true), "cloud", "an empty code is not clear sky");
assert.strictEqual(kind("nonsense", true), "cloud");
assert.strictEqual(kind(NaN, true), "cloud");

// Numeric strings are still real codes; providers vary in how they type them.
assert.strictEqual(kind("0", true), "clear-day");
assert.strictEqual(kind("63", true), "rain");

// Every branch must carry a human-readable label for the icon title.
[0, 2, 3, 45, 53, 63, 73, 81, 95, 999, null].forEach(function (code) {
	const info = describe(code, true);
	assert.ok(
		typeof info.label === "string" && info.label.length > 0,
		"code " + code + " must produce a label"
	);
});

console.log("PiSky weather icon mapping passed.");
