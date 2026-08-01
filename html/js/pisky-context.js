/*
 * Station-time-aware visitor copy for PiSky.
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */
(function () {
	"use strict";

	function stationHour() {
		const body = document.body;
		const timezone = body.dataset.piskyTimezone || "";
		if (timezone && window.Intl && Intl.DateTimeFormat) {
			try {
				const parts = new Intl.DateTimeFormat("en-GB", {
					timeZone: timezone,
					hour: "2-digit",
					hourCycle: "h23"
				}).formatToParts(new Date());
				const hour = parts.find(function (part) { return part.type === "hour"; });
				if (hour) return parseInt(hour.value, 10);
			} catch (error) {
				// Fall back to the station offset captured when the page loaded.
			}
		}

		const offsetSeconds = parseInt(body.dataset.piskyUtcOffset || "0", 10);
		const stationClock = new Date(Date.now() + (offsetSeconds * 1000));
		return stationClock.getUTCHours();
	}

	function contextFor(hour) {
		if (hour >= 5 && hour < 12) {
			return {
				key: "morning",
				label: "This morning",
				title: "This morning, above us.",
				copy: "A fresh view of the waking sky, paired with local conditions and the day ahead.",
				capture: "Morning capture",
				conditions: "Conditions this morning",
				traffic: "Aircraft above us this morning"
			};
		}
		if (hour >= 12 && hour < 17) {
			return {
				key: "day",
				label: "Today",
				title: "Today, above us.",
				copy: "A continuously updating view of today’s sky, local conditions and nearby air traffic.",
				capture: "Daylight capture",
				conditions: "Conditions today",
				traffic: "Aircraft above us today"
			};
		}
		if (hour >= 17 && hour < 21) {
			return {
				key: "evening",
				label: "This evening",
				title: "This evening, above us.",
				copy: "Watch daylight fade across the whole sky with local weather and traffic context.",
				capture: "Evening capture",
				conditions: "Conditions this evening",
				traffic: "Aircraft above us this evening"
			};
		}
		return {
			key: "night",
			label: "Tonight",
			title: "Tonight, above us.",
			copy: "A live window onto the night sky, paired with local conditions and nearby air traffic.",
			capture: "Night capture",
			conditions: "Conditions tonight",
			traffic: "Aircraft above us tonight"
		};
	}

	function setText(selector, value) {
		document.querySelectorAll(selector).forEach(function (element) {
			element.textContent = value;
		});
	}

	function updateContext() {
		const context = contextFor(stationHour());
		document.body.dataset.piskyDaypart = context.key;
		setText("[data-pisky-daypart-label]", context.label);
		setText("[data-pisky-daypart-title]", context.title);
		setText("[data-pisky-daypart-copy]", context.copy);
		setText("[data-pisky-daypart-capture]", context.capture);
		setText("[data-pisky-daypart-conditions]", context.conditions);
		setText("[data-pisky-daypart-traffic]", context.traffic);
	}

	if (typeof module !== "undefined" && module.exports) {
		module.exports = { contextFor: contextFor };
	}
	if (typeof document === "undefined") return;

	document.addEventListener("DOMContentLoaded", function () {
		updateContext();
		window.setInterval(updateContext, 60 * 1000);
	});
})();

/*
 * Station clock.
 *
 * Shows the current date and time where the station stands, not where the
 * visitor is. The offset is rendered into the page by PHP from the station's
 * configured timezone, because a visitor's browser knows only its own.
 */
(function () {
	"use strict";

	// This file is also loaded into a bare sandbox by the daypart tests, which
	// provide no DOM, so the clock must not touch one at load time.
	if (typeof document === "undefined" || typeof window === "undefined") return;

	function stationNow(offsetSeconds) {
		// Shift by the difference between the station's offset and the
		// viewer's, then read the result with local getters.
		const now = new Date();
		const viewerOffset = -now.getTimezoneOffset() * 60;
		return new Date(now.getTime() + (offsetSeconds - viewerOffset) * 1000);
	}

	function tick() {
		const holders = document.querySelectorAll("[data-pisky-clock]");
		if (!holders.length) return;
		const offset = parseInt(document.body.getAttribute("data-pisky-utc-offset") || "0", 10);
		const zone = document.body.getAttribute("data-pisky-timezone") || "";
		const at = stationNow(Number.isFinite(offset) ? offset : 0);

		const time = at.toLocaleTimeString([], {
			hour: "2-digit", minute: "2-digit", hour12: false
		});
		// Seconds are opt-in per clock. The card is a display worth watching
		// run; the one in the page heading would only be noise ticking beside
		// a title.
		const withSeconds = at.toLocaleTimeString([], {
			hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false
		});
		const date = at.toLocaleDateString([], {
			weekday: "short", day: "numeric", month: "short", year: "numeric"
		});
		holders.forEach(function (holder) {
			const timeNode = holder.querySelector("[data-pisky-clock-time]");
			const dateNode = holder.querySelector("[data-pisky-clock-date]");
			const zoneNode = holder.querySelector("[data-pisky-clock-zone]");
			if (timeNode) {
				timeNode.textContent = holder.hasAttribute("data-pisky-clock-seconds")
					? withSeconds : time;
			}
			if (dateNode) dateNode.textContent = date;
			if (zoneNode) zoneNode.textContent = zone;
			// A clock printing its zone does not also need to whisper it on
			// hover, and the tooltip covered the reading it described.
			if (zone && !zoneNode) holder.title = "Station local time · " + zone;
		});
	}

	/*
	 * This file is loaded from the document head, so at parse time there is no
	 * body to read the offset from and no clock to fill. Ticking immediately
	 * therefore did nothing and the first real update waited for the interval,
	 * leaving every visitor looking at --:-- for the first half minute.
	 */
	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", tick);
	} else {
		tick();
	}

	/*
	 * Once a second, so a clock showing seconds actually runs. The work is one
	 * Date and two toLocaleTimeString calls against a couple of nodes, which is
	 * far below what a second of budget allows.
	 */
	window.setInterval(tick, 1000);
}());
