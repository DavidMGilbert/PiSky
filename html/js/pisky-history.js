/*
 * PiSky weather history client.
 *
 * Fetches a day's recorded weather and draws it. Detail comes from the
 * intraday samples a remote database holds; without one the response still
 * carries the daily rollup, so the interface falls back to the summary and
 * says why rather than showing an empty frame.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */
(function () {
	"use strict";

	const endpoint = "/pisky-history.php";

	/* Which readings each chart plots, in drawing order. */
	const CHART_SERIES = {
		temperature: [
			{ field: "temperature", label: "Temperature" },
			{ field: "apparent_temperature", label: "Feels like" },
			{ field: "dew_point", label: "Dew point" }
		],
		humidity: [
			{ field: "humidity", label: "Humidity" },
			{ field: "cloud_cover", label: "Cloud cover" }
		],
		pressure: [{ field: "pressure", label: "Pressure" }],
		wind: [
			{ field: "wind_speed", label: "Wind" },
			{ field: "wind_gust", label: "Gust" }
		],
		rain: [
			{ field: "rain_rate", label: "Rain rate" },
			{ field: "rain", label: "Rain" }
		],
		sun: [
			{ field: "solar_radiation", label: "Solar" },
			{ field: "uv", label: "UV" }
		]
	};

	function toPoints(samples) {
		const points = [];
		(samples || []).forEach(function (sample) {
			const time = new Date(sample.time);
			if (Number.isNaN(time.getTime())) return;
			const values = {};
			Object.keys(sample).forEach(function (key) {
				if (key === "time") return;
				values[key] = sample[key];
			});
			points.push({ time: time, values: values });
		});
		return points;
	}

	/*
	 * Only plot a series the day actually recorded. A station without a UV
	 * sensor should not be offered an empty UV chart.
	 */
	function presentSeries(points, series) {
		return series.filter(function (entry) {
			return points.some(function (point) {
				return window.piskyCharts.isReading(point.values[entry.field]);
			});
		});
	}

	function renderReadout(container, point, series) {
		if (!container) return;
		container.textContent = "";
		if (!point) {
			container.classList.remove("is-active");
			return;
		}
		container.classList.add("is-active");
		const time = document.createElement("strong");
		time.textContent = point.time.toLocaleTimeString([], {
			hour: "2-digit", minute: "2-digit"
		});
		container.appendChild(time);
		series.forEach(function (entry, index) {
			const value = point.values[entry.field];
			if (!window.piskyCharts.isReading(value)) return;
			const item = document.createElement("span");
			const swatch = document.createElement("i");
			swatch.style.background = window.piskyCharts.colourFor(entry.field, index);
			item.appendChild(swatch);
			item.appendChild(document.createTextNode(
				entry.label + " " + window.piskyCharts.formatValue(value, "")
			));
			container.appendChild(item);
		});
	}

	function setMessage(host, text) {
		const note = host.querySelector("[data-pisky-chart-note]");
		if (note) note.textContent = text;
	}

	async function load() {
		const hosts = document.querySelectorAll("[data-pisky-history-day]");
		if (!hosts.length) return;

		for (const host of hosts) {
			const day = host.getAttribute("data-pisky-history-day");
			if (!day) continue;
			const scope = document.body.classList.contains("pisky-public") ? "" : "&scope=admin";
			let data = null;
			try {
				const response = await fetch(
					endpoint + "?day=" + encodeURIComponent(day) + "&_ts=" + Date.now() + scope,
					{ cache: "no-store", headers: { "Accept": "application/json" } }
				);
				data = await response.json();
			} catch (error) {
				setMessage(host, "The recorded weather for this day could not be loaded.");
				continue;
			}
			if (!data || !data.ok) {
				setMessage(host, (data && data.error) || "No recorded weather for this day.");
				continue;
			}

			const points = toPoints(data.samples);
			if (!data.detail_available || points.length < 2) {
				// The summary is already rendered server-side, so this only
				// needs to explain why there is no curve to go with it.
				setMessage(host,
					"Detailed readings are not recorded for this day. Connect a history"
					+ " database in PiSky Setup to graph the hours behind each summary.");
				host.querySelectorAll("[data-pisky-chart]").forEach(function (canvas) {
					const panel = canvas.closest("[data-pisky-chart-panel]");
					if (panel) panel.hidden = true;
				});
				continue;
			}

			setMessage(host, points.length + " readings recorded across the day.");
			host.querySelectorAll("[data-pisky-chart]").forEach(function (canvas) {
				const kind = canvas.getAttribute("data-pisky-chart");
				const series = presentSeries(points, CHART_SERIES[kind] || []);
				const panel = canvas.closest("[data-pisky-chart-panel]");
				if (!series.length) {
					if (panel) panel.hidden = true;
					return;
				}
				if (panel) panel.hidden = false;
				const readout = panel ? panel.querySelector("[data-pisky-chart-readout]") : null;
				const chart = window.piskyCharts.create(canvas, {
					onHover: function (point) { renderReadout(readout, point, series); }
				});
				chart.setData(points, series);
			});
		}
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", load);
	} else {
		load();
	}
}());
