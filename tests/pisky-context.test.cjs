"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const source = fs.readFileSync(
	path.join(__dirname, "../html/js/pisky-context.js"),
	"utf8"
);
const sandbox = {
	module: { exports: {} },
	console: console
};
vm.runInNewContext(source, sandbox, { filename: "pisky-context.js" });
const { contextFor } = sandbox.module.exports;

const cases = [
	[0, "night", "Tonight"],
	[4, "night", "Tonight"],
	[5, "morning", "This morning"],
	[11, "morning", "This morning"],
	[12, "day", "Today"],
	[16, "day", "Today"],
	[17, "evening", "This evening"],
	[20, "evening", "This evening"],
	[21, "night", "Tonight"],
	[23, "night", "Tonight"]
];

cases.forEach(function (testCase) {
	const hour = testCase[0];
	const expectedKey = testCase[1];
	const expectedLabel = testCase[2];
	const actual = contextFor(hour);
	assert.equal(actual.key, expectedKey, "daypart at hour " + hour);
	assert.equal(actual.label, expectedLabel, "label at hour " + hour);
	assert.ok(actual.title.length > 0);
	assert.ok(actual.copy.length > 0);
});

console.log("PiSky station-time context boundaries passed.");
