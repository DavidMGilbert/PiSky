/*
 * PiSky living weather icons
 *
 * Inline animated SVG keyed to WMO weather codes, drawn to match the
 * liquid-glass interface rather than imported from an icon library. Each icon
 * is built from a few shared primitives (sun, moon, cloud, precipitation,
 * bolt, fog bars) so a handful of parts covers the whole code range.
 *
 * Motion is CSS-driven and disabled wholesale under prefers-reduced-motion,
 * where the icons stay legible as static artwork.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */
(function (global) {
	"use strict";

	const SVG = "http://www.w3.org/2000/svg";

	function element(name, attributes, parent) {
		const node = document.createElementNS(SVG, name);
		Object.keys(attributes || {}).forEach(function (key) {
			node.setAttribute(key, attributes[key]);
		});
		if (parent) parent.appendChild(node);
		return node;
	}

	function sun(parent, className) {
		const group = element("g", { class: "pisky-icon-sun " + (className || "") }, parent);
		const rays = element("g", { class: "pisky-icon-rays" }, group);
		for (let index = 0; index < 8; index++) {
			element("line", {
				x1: 32, y1: 8, x2: 32, y2: 15,
				transform: "rotate(" + (index * 45) + " 32 32)"
			}, rays);
		}
		element("circle", { cx: 32, cy: 32, r: 11, class: "pisky-icon-disc" }, group);
		return group;
	}

	function moon(parent) {
		const group = element("g", { class: "pisky-icon-moon" }, parent);
		element("circle", { cx: 32, cy: 30, r: 17, class: "pisky-icon-glow" }, group);
		element("path", {
			d: "M40 20a14 14 0 1 0 8 12 11 11 0 0 1-8-12z",
			class: "pisky-icon-disc"
		}, group);
		return group;
	}

	function cloud(parent, className) {
		const group = element("g", { class: "pisky-icon-cloud " + (className || "") }, parent);
		element("path", {
			d: "M22 46a10 10 0 0 1 1-19.9 14 14 0 0 1 26.4 4.2A8.5 8.5 0 0 1 47 46z"
		}, group);
		return group;
	}

	function drops(parent, count, className) {
		const group = element("g", { class: "pisky-icon-fall " + (className || "") }, parent);
		for (let index = 0; index < count; index++) {
			const x = 24 + index * 8;
			const drop = element("line", {
				x1: x, y1: 50, x2: x - 2, y2: 57, class: "pisky-icon-drop"
			}, group);
			drop.style.animationDelay = (index * 0.22) + "s";
		}
		return group;
	}

	function flakes(parent, count) {
		const group = element("g", { class: "pisky-icon-fall pisky-icon-snow" }, parent);
		for (let index = 0; index < count; index++) {
			const flake = element("circle", {
				cx: 24 + index * 8, cy: 53, r: 2.2, class: "pisky-icon-flake"
			}, group);
			flake.style.animationDelay = (index * 0.28) + "s";
		}
		return group;
	}

	function bolt(parent) {
		const group = element("g", { class: "pisky-icon-bolt" }, parent);
		element("path", { d: "M34 46l-7 11h6l-2 9 9-13h-6l3-7z" }, group);
		return group;
	}

	function fog(parent) {
		const group = element("g", { class: "pisky-icon-fog" }, parent);
		[0, 1, 2].forEach(function (index) {
			const bar = element("line", {
				x1: 18, y1: 44 + index * 7, x2: 46, y2: 44 + index * 7
			}, group);
			bar.style.animationDelay = (index * 0.4) + "s";
		});
		return group;
	}

	/*
	 * WMO code groups. Anything unrecognised falls back to cloud so the
	 * interface never renders an empty box.
	 */
	function describe(code, isDay) {
		// Number(null) and Number("") are both 0, which is the code for clear
		// sky. A station that reports no weather code must not be shown a sun.
		if (code === null || code === undefined || code === "") {
			return { kind: "cloud", label: "Cloud" };
		}
		const value = Number(code);
		if (!Number.isFinite(value)) return { kind: "cloud", label: "Cloud" };
		if (value === 0) return { kind: isDay ? "clear-day" : "clear-night", label: "Clear" };
		if (value === 1 || value === 2) {
			return { kind: isDay ? "partly-day" : "partly-night", label: "Partly cloudy" };
		}
		if (value === 3) return { kind: "cloud", label: "Overcast" };
		if (value === 45 || value === 48) return { kind: "fog", label: "Fog" };
		if (value >= 51 && value <= 57) return { kind: "drizzle", label: "Drizzle" };
		if (value >= 61 && value <= 67) return { kind: "rain", label: "Rain" };
		if (value >= 71 && value <= 77) return { kind: "snow", label: "Snow" };
		if (value >= 80 && value <= 82) return { kind: "showers", label: "Showers" };
		if (value === 85 || value === 86) return { kind: "snow", label: "Snow showers" };
		// 99 is the highest WMO code; anything beyond it is not a weather code
		// at all and must not be mistaken for a thunderstorm.
		if (value >= 95 && value <= 99) return { kind: "storm", label: "Thunderstorm" };
		return { kind: "cloud", label: "Cloud" };
	}

	function build(kind) {
		const svg = element("svg", {
			viewBox: "0 0 64 72",
			class: "pisky-weather-icon is-" + kind,
			role: "img",
			focusable: "false"
		});
		switch (kind) {
			case "clear-day":
				sun(svg);
				break;
			case "clear-night":
				moon(svg);
				break;
			case "partly-day":
				sun(svg, "pisky-icon-behind");
				cloud(svg);
				break;
			case "partly-night":
				moon(svg);
				cloud(svg);
				break;
			case "fog":
				cloud(svg);
				fog(svg);
				break;
			case "drizzle":
				cloud(svg);
				drops(svg, 3, "is-light");
				break;
			case "rain":
				cloud(svg);
				drops(svg, 4);
				break;
			case "showers":
				sun(svg, "pisky-icon-behind");
				cloud(svg);
				drops(svg, 3);
				break;
			case "snow":
				cloud(svg);
				flakes(svg, 4);
				break;
			case "storm":
				cloud(svg, "is-dark");
				bolt(svg);
				drops(svg, 2);
				break;
			default:
				cloud(svg);
				break;
		}
		return svg;
	}

	/*
	 * Render into every [data-pisky-weather-icon] element. The code and
	 * day/night flag are supplied by the weather client; the previous icon is
	 * replaced only when the kind actually changes so animations do not restart
	 * on every poll.
	 */
	function render(root, code, isDay, labelText) {
		const info = describe(code, isDay !== false);
		if (root.getAttribute("data-icon-kind") === info.kind) return;
		root.setAttribute("data-icon-kind", info.kind);
		root.textContent = "";
		const svg = build(info.kind);
		const title = element("title", {}, svg);
		title.textContent = labelText || info.label;
		root.appendChild(svg);
	}

	function apply(code, isDay, labelText) {
		document.querySelectorAll("[data-pisky-weather-icon]").forEach(function (root) {
			render(root, code, isDay, labelText);
		});
	}

	/*
	 * Metric glyphs.
	 *
	 * These are line symbols rather than scenes: one per measurement, sharing a
	 * stroke weight so a grid of cards reads evenly. Anything without its own
	 * glyph falls back to a gauge, so a sensor PiSky has never seen still gets
	 * a sensible mark instead of an empty box.
	 */
	const METRIC_PATHS = {
		temperature: "M26 34V14a6 6 0 0 1 12 0v20a12 12 0 1 1-12 0z|M32 20v18",
		apparent_temperature: "M26 34V14a6 6 0 0 1 12 0v20a12 12 0 1 1-12 0z",
		humidity: "M32 10s14 16 14 25a14 14 0 1 1-28 0c0-9 14-25 14-25z",
		dew_point: "M32 12s10 12 10 19a10 10 0 1 1-20 0c0-7 10-19 10-19z|M27 39a6 6 0 0 0 6 5",
		pressure: "M12 38a20 20 0 0 1 40 0|M32 38l11-11|M32 38h.01",
		pressure_msl: "M12 38a20 20 0 0 1 40 0|M32 38l11-11",
		pressure_trend: "M14 42l12-12 8 8 14-16|M40 22h8v8",
		wind_speed: "M8 24h26a6 6 0 1 0-6-6|M8 34h34a6 6 0 1 1-6 6|M8 44h18",
		wind_gust: "M8 26h26a6 6 0 1 0-6-6|M8 38h20a5 5 0 1 1-5 5",
		wind_speed_max: "M8 26h26a6 6 0 1 0-6-6|M8 38h20a5 5 0 1 1-5 5",
		wind_direction: "M32 10l10 40-10-9-10 9z",
		rain: "M20 34a11 11 0 0 1 1-21 14 14 0 0 1 25 5 8 8 0 0 1-2 16z|M24 44l-3 8|M32 44l-3 8|M40 44l-3 8",
		rain_rate: "M22 12v14|M32 8v18|M42 12v14|M18 36h28l-4 18H22z",
		rain_today: "M20 34a11 11 0 0 1 1-21 14 14 0 0 1 25 5 8 8 0 0 1-2 16z|M26 44l-2 8|M38 44l-2 8",
		rain_storm: "M20 32a11 11 0 0 1 1-21 14 14 0 0 1 25 5 8 8 0 0 1-2 16z|M34 38l-8 12h7l-2 10 9-14h-7z",
		rain_month: "M14 18h36v32H14z|M14 28h36|M24 12v10|M40 12v10",
		rain_year: "M14 18h36v32H14z|M14 28h36|M22 36h8v8h-8z",
		cloud_cover: "M20 44a12 12 0 0 1 1-23 15 15 0 0 1 28 4 9 9 0 0 1-3 19z",
		cloud_cover_low: "M20 46a10 10 0 0 1 1-19 13 13 0 0 1 24 4 8 8 0 0 1-2 15z",
		cloud_cover_mid: "M20 40a10 10 0 0 1 1-19 13 13 0 0 1 24 4 8 8 0 0 1-2 15z",
		cloud_cover_high: "M20 32a10 10 0 0 1 1-19 13 13 0 0 1 24 4 8 8 0 0 1-2 15z",
		cloud_base: "M18 26h28|M32 34v16|M26 44l6 6 6-6",
		visibility: "M6 32s10-14 26-14 26 14 26 14-10 14-26 14S6 32 6 32z|M32 40a8 8 0 1 0 0-16 8 8 0 0 0 0 16z",
		uv: "M32 20a12 12 0 1 0 0 24 12 12 0 0 0 0-24z|M32 6v6|M32 52v6|M12 32H6|M58 32h-6|M18 18l-4-4|M46 46l4 4|M46 18l4-4|M18 46l-4 4",
		solar_radiation: "M32 22a10 10 0 1 0 0 20 10 10 0 0 0 0-20z|M32 6v8|M32 50v8|M6 32h8|M50 32h8",
		evapotranspiration: "M32 10s10 13 10 20a10 10 0 1 1-20 0c0-7 10-20 10-20z|M14 50h36",
		soil_temperature: "M8 40h48v14H8z|M20 40V22a6 6 0 0 1 12 0v18",
		soil_moisture: "M8 40h48v14H8z|M32 12s8 11 8 17a8 8 0 1 1-16 0c0-6 8-17 8-17z",
		leaf_wetness: "M14 50C14 26 34 14 50 14c0 20-14 36-36 36z|M22 42l14-14",
		leaf_temperature: "M14 50C14 26 34 14 50 14c0 20-14 36-36 36z|M24 26v12",
		lightning_distance: "M34 8L18 36h12l-4 20 18-30H32z",
		lightning_count: "M34 8L18 36h12l-4 20 18-30H32z|M50 44v10|M46 49h8",
		snowfall: "M32 10v44|M14 21l36 22|M50 21L14 43",
		snow_depth: "M32 10v30|M20 22l12-12 12 12|M8 50h48",
		showers: "M20 32a11 11 0 0 1 1-21 14 14 0 0 1 25 5 8 8 0 0 1-2 16z|M24 42l-3 10|M34 42l-3 10|M44 42l-3 10",
		freezing_level: "M32 10v36|M18 20l14-10 14 10|M8 54h48",
		indoor_temperature: "M10 30L32 12l22 18v22H10z|M26 40h12v12H26z",
		indoor_humidity: "M10 30L32 12l22 18v22H10z|M32 30s6 8 6 12a6 6 0 1 1-12 0c0-4 6-12 6-12z",
		heat_index: "M26 34V14a6 6 0 0 1 12 0v20a12 12 0 1 1-12 0z|M48 16l6 6-6 6",
		wind_chill: "M26 34V14a6 6 0 0 1 12 0v20a12 12 0 1 1-12 0z|M48 14v14|M42 18l6-4 6 4",
		thw_index: "M26 34V14a6 6 0 0 1 12 0v20a12 12 0 1 1-12 0z|M46 20h10",
		thsw_index: "M26 34V14a6 6 0 0 1 12 0v20a12 12 0 1 1-12 0z|M46 20h10|M46 28h10",
		temperature_max: "M26 34V14a6 6 0 0 1 12 0v20a12 12 0 1 1-12 0z|M46 24l6-8 6 8",
		temperature_min: "M26 34V14a6 6 0 0 1 12 0v20a12 12 0 1 1-12 0z|M46 16l6 8 6-8",
		air_density: "M14 22h36|M14 32h36|M14 42h36",
		air_quality_index: "M10 26h30a7 7 0 1 0-7-7|M10 38h24a6 6 0 1 1-6 6|M46 30h8",
		pm2_5: "M18 24a4 4 0 1 0 0-8 4 4 0 0 0 0 8z|M40 22a5 5 0 1 0 0-10 5 5 0 0 0 0 10z|M26 44a6 6 0 1 0 0-12 6 6 0 0 0 0 12z|M44 46a4 4 0 1 0 0-8 4 4 0 0 0 0 8z",
		pm10: "M20 28a7 7 0 1 0 0-14 7 7 0 0 0 0 14z|M42 46a8 8 0 1 0 0-16 8 8 0 0 0 0 16z|M40 20a4 4 0 1 0 0-8 4 4 0 0 0 0 8z",
		ozone: "M32 12a20 20 0 1 0 0 40 20 20 0 0 0 0-40z|M32 24a8 8 0 1 0 0 16 8 8 0 0 0 0-16z",
		nitrogen_dioxide: "M32 12a20 20 0 1 0 0 40 20 20 0 0 0 0-40z|M24 32h16",
		co2: "M42 24a12 12 0 1 0 0 16|M20 26h8|M20 38h8",
		voc: "M16 44c8-6 8-18 16-18s8 12 16 18|M16 26h4|M44 26h4",
		battery_status: "M10 24h36v16H10z|M46 29h6v6h-6z|M16 29h6v6h-6z",
		signal_quality: "M14 44h6v6h-6z|M26 34h6v16h-6z|M38 24h6v26h-6z",
		condition: "M20 42a12 12 0 0 1 1-23 15 15 0 0 1 28 4 9 9 0 0 1-3 19z",
		default: "M32 10a22 22 0 1 0 0 44 22 22 0 0 0 0-44z|M32 20v12l8 6"
	};

	/*
	 * Astronomy icons.
	 *
	 * These are living rather than flat: the sun climbs and sinks across the
	 * horizon, the moon drifts, and the phase icon shows the actual
	 * illuminated fraction rather than a generic crescent.
	 */
	function astroIcon(kind, illumination) {
		const svg = element("svg", {
			viewBox: "0 0 64 64",
			class: "pisky-astro-icon is-" + kind,
			role: "img",
			focusable: "false"
		});

		if (kind === "moon_phase") {
			// A circle masked by an offset circle: sliding the mask across
			// reproduces every phase from new to full.
			const fraction = Math.max(0, Math.min(1, Number(illumination)));
			const id = "piskyPhase" + Math.random().toString(36).slice(2, 8);
			const defs = element("defs", {}, svg);
			const mask = element("mask", { id: id }, defs);
			element("rect", { x: 0, y: 0, width: 64, height: 64, fill: "#fff" }, mask);
			// The masking circle sits over the disc at new moon and slides clear
			// of it at full. Two radii of travel takes it from covering the disc
			// entirely to just touching, so nothing is hidden at full.
			element("circle", {
				cx: 32 - fraction * 34, cy: 32, r: 17, fill: "#000"
			}, mask);
			element("circle", { cx: 32, cy: 32, r: 17, class: "pisky-icon-glow" }, svg);
			element("circle", {
				cx: 32, cy: 32, r: 17, class: "pisky-icon-disc", mask: "url(#" + id + ")"
			}, svg);
			element("circle", {
				cx: 32, cy: 32, r: 17, class: "pisky-icon-outline"
			}, svg);
			return svg;
		}

		if (kind === "daylight") {
			// Length of day: a sun tracking an arc from horizon to horizon.
			const horizonLine = element("g", { class: "pisky-icon-horizon" }, svg);
			element("line", { x1: 6, y1: 46, x2: 58, y2: 46 }, horizonLine);
			element("path", {
				d: "M12 46a20 20 0 0 1 40 0", class: "pisky-icon-arc"
			}, svg);
			const travelling = element("g", { class: "pisky-icon-travel" }, svg);
			element("circle", { cx: 32, cy: 26, r: 7, class: "pisky-icon-disc" }, travelling);
			return svg;
		}

		const horizon = element("g", { class: "pisky-icon-horizon" }, svg);
		element("line", { x1: 8, y1: 46, x2: 56, y2: 46 }, horizon);

		if (kind === "moonrise" || kind === "moonset") {
			const body = element("g", { class: "pisky-icon-body" }, svg);
			element("path", {
				d: "M36 16a12 12 0 1 0 6 10 9 9 0 0 1-6-10z", class: "pisky-icon-disc"
			}, body);
		} else {
			const body = element("g", { class: "pisky-icon-body" }, svg);
			const rays = element("g", { class: "pisky-icon-rays" }, body);
			for (let index = 0; index < 8; index++) {
				element("line", {
					x1: 32, y1: 8, x2: 32, y2: 14,
					transform: "rotate(" + (index * 45) + " 32 30)"
				}, rays);
			}
			element("circle", { cx: 32, cy: 30, r: 9, class: "pisky-icon-disc" }, body);
		}

		const arrow = element("g", { class: "pisky-icon-arrow" }, svg);
		if (kind === "sunrise" || kind === "moonrise") {
			element("path", { d: "M32 60V52M27 56l5-5 5 5" }, arrow);
		} else {
			element("path", { d: "M32 52v8M27 55l5 5 5-5" }, arrow);
		}
		return svg;
	}

	function renderAstro(root, kind, illumination, labelText) {
		const key = kind + ":" + (kind === "moon_phase"
			? Math.round(Number(illumination) * 20) : "");
		if (root.getAttribute("data-icon-astro") === key) return;
		root.setAttribute("data-icon-astro", key);
		root.textContent = "";
		const svg = astroIcon(kind, illumination);
		const title = element("title", {}, svg);
		title.textContent = labelText || kind.replace("_", " ");
		root.appendChild(svg);
	}

	/*
	 * Motion families.
	 *
	 * Grouping metrics by how they should move keeps a grid of cards coherent:
	 * everything that falls falls at the same rate, everything that radiates
	 * pulses together. The family drives a CSS class rather than per-icon
	 * animation, so the whole set stays consistent and stops as one under
	 * prefers-reduced-motion.
	 */
	const METRIC_FAMILY = {
		temperature: "warm", apparent_temperature: "warm", heat_index: "warm",
		wind_chill: "warm", thw_index: "warm", thsw_index: "warm",
		temperature_max: "warm", temperature_min: "warm",
		indoor_temperature: "warm", soil_temperature: "warm",
		leaf_temperature: "warm",

		humidity: "drip", dew_point: "drip", indoor_humidity: "drip",
		soil_moisture: "drip", leaf_wetness: "drip",
		evapotranspiration: "drip",

		rain: "fall", rain_rate: "fall", rain_today: "fall", rain_storm: "fall",
		rain_month: "fall", rain_year: "fall", showers: "fall",
		snowfall: "fall", snow_depth: "fall",

		wind_speed: "drift", wind_gust: "drift", wind_speed_max: "drift",
		wind_direction: "spin", cloud_cover: "drift", cloud_cover_low: "drift",
		cloud_cover_mid: "drift", cloud_cover_high: "drift", cloud_base: "drift",
		condition: "drift", freezing_level: "drift",

		uv: "radiate", solar_radiation: "radiate",

		pressure: "sweep", pressure_msl: "sweep", pressure_trend: "sweep",
		air_density: "sweep", visibility: "sweep",

		lightning_distance: "flicker", lightning_count: "flicker",
		battery_status: "flicker", signal_quality: "flicker",

		pm2_5: "float", pm10: "float", ozone: "float",
		nitrogen_dioxide: "float", co2: "float", voc: "float",
		air_quality_index: "float"
	};

	function metricIcon(id) {
		const family = METRIC_FAMILY[id] || "still";
		const svg = element("svg", {
			viewBox: "0 0 64 64",
			class: "pisky-metric-icon is-" + family,
			"data-metric": id,
			role: "img",
			focusable: "false"
		});
		const definition = METRIC_PATHS[id] || METRIC_PATHS.default;
		definition.split("|").forEach(function (segment) {
			element("path", { d: segment }, svg);
		});
		return svg;
	}

	function renderMetric(root, id, labelText) {
		if (root.getAttribute("data-icon-metric") === id) return;
		root.setAttribute("data-icon-metric", id);
		root.textContent = "";
		const svg = metricIcon(id);
		const title = element("title", {}, svg);
		title.textContent = labelText || id;
		root.appendChild(svg);
	}

	/* Fill every [data-pisky-metric-icon] element from its own identifier. */
	function applyMetricIcons(scope) {
		(scope || document).querySelectorAll("[data-pisky-metric-icon]").forEach(function (root) {
			const id = root.getAttribute("data-pisky-metric-icon");
			if (id) renderMetric(root, id, root.getAttribute("data-icon-label"));
		});
	}

	/*
	 * Fill every [data-pisky-astro-icon] element. The moon phase needs the
	 * illuminated fraction, which the client passes through after each poll.
	 */
	function applyAstroIcons(illumination) {
		document.querySelectorAll("[data-pisky-astro-icon]").forEach(function (root) {
			const kind = root.getAttribute("data-pisky-astro-icon");
			if (kind) renderAstro(root, kind, illumination, root.getAttribute("data-icon-label"));
		});
	}

	global.piskyWeatherIcons = {
		apply: apply,
		render: render,
		describe: describe,
		renderMetric: renderMetric,
		applyMetricIcons: applyMetricIcons,
		renderAstro: renderAstro,
		applyAstroIcons: applyAstroIcons,
		hasMetricIcon: function (id) { return Object.hasOwn(METRIC_PATHS, id); }
	};
}(window));
