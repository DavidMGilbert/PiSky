/*
 * PiSky weather client
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */
(function () {
	"use strict";

	const endpoint = "/pisky-weather.php";
	const refreshMs = 60 * 1000;

	function setText(name, value, suffix) {
		document.querySelectorAll("[data-pisky-weather='" + name + "']").forEach(function (element) {
			const fallback = element.getAttribute("data-fallback") || "—";
			const output = value === null || value === undefined || value === "" ? fallback : value;
			element.textContent = String(output) + (output === fallback ? "" : (suffix || ""));
		});
	}

	/*
	 * Hide anything marked [data-pisky-metric] whose reading the host has
	 * turned off for the visitor site.
	 *
	 * A hidden metric is absent from the payload, while a metric the sensors
	 * simply could not supply is present and null. Testing for the key rather
	 * than the value keeps those two cases apart, so "switched off" disappears
	 * and "nothing to report" still shows its dash.
	 */
	function applyMetricVisibility(current, observations) {
		const published = new Set();
		Object.keys(current || {}).forEach(function (key) { published.add(key); });
		(observations || []).forEach(function (observation) {
			if (observation && observation.id) published.add(observation.id);
		});
		document.querySelectorAll("[data-pisky-metric]").forEach(function (element) {
			const id = element.getAttribute("data-pisky-metric");
			element.hidden = !published.has(id);
		});
	}

	function setStatus(ok, message) {
		document.querySelectorAll("[data-pisky-weather-status]").forEach(function (element) {
			element.classList.toggle("is-error", !ok);
			element.classList.toggle("is-live", ok);
			element.textContent = message;
		});
	}

	function fixed(value, places) {
		const number = Number(value);
		return Number.isFinite(number) ? number.toFixed(places) : null;
	}

	function localTime(value) {
		if (!value) return null;
		const date = new Date(value);
		return Number.isNaN(date.getTime())
			? null
			: date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
	}

	function duration(value) {
		const seconds = Number(value);
		if (!Number.isFinite(seconds)) return null;
		const hours = Math.floor(seconds / 3600);
		const minutes = Math.round((seconds % 3600) / 60);
		return hours + "h " + minutes + "m";
	}

	function renderForecast(data) {
		document.querySelectorAll("[data-pisky-daily-forecast]").forEach(function (container) {
			container.textContent = "";
			const daily = Array.isArray(data.daily) ? data.daily : [];
			if (!daily.length) {
				const empty = document.createElement("p");
				empty.className = "pisky-forecast-empty";
				empty.textContent = "The seven-day forecast is temporarily unavailable.";
				container.appendChild(empty);
				return;
			}
			daily.slice(0, 7).forEach(function (day, index) {
				const card = document.createElement("article");
				const heading = document.createElement("strong");
				const date = new Date(String(day.date) + "T12:00:00");
				const units = data.units || {};
				const temperatureUnit = units.temperature || "°C";
				const rainUnit = units.rain || "mm";
				heading.textContent = index === 0
					? "Today"
					: date.toLocaleDateString([], { weekday: "short" });
				// Daily cards always use the daytime icon; a forecast covering a
				// whole day reads oddly with a moon on it.
				if (window.piskyWeatherIcons) {
					const icon = document.createElement("div");
					icon.className = "pisky-forecast-icon";
					window.piskyWeatherIcons.render(icon, day.weather_code, true, day.condition);
					card.appendChild(icon);
				}
				const condition = document.createElement("span");
				condition.textContent = day.condition || "Forecast";
				const temperatures = document.createElement("b");
				const high = fixed(day.temperature_max, 0);
				const low = fixed(day.temperature_min, 0);
				temperatures.textContent = (high === null ? "—" : high + temperatureUnit)
					+ " / " + (low === null ? "—" : low + temperatureUnit);
				const details = document.createElement("small");
				const rain = fixed(day.rain, String(rainUnit).toLowerCase() === "in" ? 2 : 1);
				details.textContent = (day.precipitation_probability === null
					|| day.precipitation_probability === undefined
					? "Rain " + (rain === null ? "—" : rain + " " + rainUnit)
					: "Rain " + Math.round(Number(day.precipitation_probability)) + "%")
					+ (day.uv_index_max === null || day.uv_index_max === undefined
						? "" : " · UV " + Number(day.uv_index_max).toFixed(0));
				card.appendChild(heading);
				card.appendChild(condition);
				card.appendChild(temperatures);
				card.appendChild(details);
				container.appendChild(card);
			});
		});
	}

	function renderAstronomy(data) {
		const astronomy = data.astronomy || {};
		const moon = astronomy.moon || {};
		setText("sunrise", localTime(astronomy.sunrise));
		setText("sunset", localTime(astronomy.sunset));
		setText("daylight", duration(astronomy.daylight_seconds));
		setText("moon_phase", moon.phase || null);
		setText("moon_illumination", fixed(moon.illumination, 0), "%");
		// The phase icon draws the real illuminated fraction, so it needs the
		// live figure rather than a generic crescent. Illumination is reported
		// as a percentage.
		if (window.piskyWeatherIcons) {
			const lit = Number(moon.illumination);
			window.piskyWeatherIcons.applyAstroIcons(
				Number.isFinite(lit) ? lit / 100 : 0.5
			);
		}
	}

	function renderObservations(data) {
		document.querySelectorAll("[data-pisky-observations]").forEach(function (container) {
			container.textContent = "";
			const observations = Array.isArray(data.observations) ? data.observations : [];
			container.hidden = observations.length === 0;
			// The administration page wraps this grid in the visibility form, so
			// each card also carries the switch controlling whether visitors
			// see that reading. The hidden companion field records that the
			// metric was on screen, which is how an unchecked box is told apart
			// from a metric that simply was not rendered.
			const visibility = container.closest("[data-pisky-metric-visibility]");
			const hiddenIds = visibility
				? new Set(String(visibility.getAttribute("data-pisky-metric-hidden") || "")
					.split(",").filter(Boolean))
				: null;

			observations.forEach(function (observation) {
				const card = document.createElement("article");
				const label = document.createElement("span");
				const value = document.createElement("strong");
				label.textContent = observation.label || observation.id || "Sensor";
				value.textContent = observation.value === null || observation.value === undefined
					? "—"
					: String(observation.value) + (observation.unit ? " " + observation.unit : "");

				if (observation.id && window.piskyWeatherIcons) {
					const glyph = document.createElement("div");
					glyph.className = "pisky-metric-glyph";
					window.piskyWeatherIcons.renderMetric(
						glyph, observation.id, observation.label
					);
					card.appendChild(glyph);
				}

				if (visibility && observation.id) {
					const isHidden = hiddenIds.has(observation.id);
					const toggle = document.createElement("label");
					const input = document.createElement("input");
					const knob = document.createElement("span");
					const known = document.createElement("input");
					toggle.className = "pisky-metric-toggle";
					toggle.title = isHidden
						? "Hidden from the public site" : "Visible on the public site";
					input.type = "checkbox";
					input.name = "pisky_metric[]";
					input.value = observation.id;
					input.checked = !isHidden;
					knob.setAttribute("aria-hidden", "true");
					known.type = "hidden";
					known.name = "pisky_metric_known[]";
					known.value = observation.id;
					toggle.appendChild(input);
					toggle.appendChild(knob);
					card.appendChild(toggle);
					card.appendChild(known);
					if (isHidden) card.setAttribute("data-pisky-hidden", "");
				}

				card.appendChild(label);
				card.appendChild(value);
				container.appendChild(card);
			});
		});
	}

	function update(data) {
		if (!data || !data.ok || !data.current) {
			const publicMessage = document.body.classList.contains("pisky-public")
				? "Weather temporarily unavailable"
				: (data && data.error ? data.error : "Weather unavailable");
			setStatus(false, publicMessage);
			renderForecast({ daily: [] });
			renderObservations({ observations: [] });
			return;
		}

		const current = data.current;
		const units = data.units || {};
		applyMetricVisibility(current, data.observations);
		setText("temperature", fixed(current.temperature, 1), units.temperature || "°C");
		setText("apparent_temperature", fixed(current.apparent_temperature, 1), units.temperature || "°C");
		setText("dew_point", fixed(current.dew_point, 1), units.temperature || "°C");
		setText("humidity", fixed(current.humidity, 0), units.humidity || "%");
		setText(
			"pressure",
			fixed(current.pressure, String(units.pressure || "").toLowerCase() === "inhg" ? 2 : 0),
			" " + (units.pressure || "hPa")
		);
		setText("wind_speed", fixed(current.wind_speed, 1), " " + (units.wind_speed || "km/h"));
		setText("wind_gust", fixed(current.wind_gust, 1), " " + (units.wind_speed || "km/h"));
		setText("wind_direction", fixed(current.wind_direction, 0), "°");
		setText(
			"rain",
			fixed(current.rain, String(units.rain || "").toLowerCase() === "in" ? 2 : 1),
			" " + (units.rain || "mm")
		);
		setText("cloud_cover", fixed(current.cloud_cover, 0), units.cloud_cover || "%");
		setText("visibility", fixed(current.visibility, 1), " " + (units.visibility || "km"));
		setText("condition", current.condition || "Current conditions");
		// Fall back to the station clock when the provider omits is_day, so a
		// local station without a daylight flag still gets a night icon.
		if (window.piskyWeatherIcons) {
			const hour = new Date().getHours();
			const isDay = typeof current.is_day === "boolean"
				? current.is_day : (hour >= 6 && hour < 19);
			window.piskyWeatherIcons.apply(current.weather_code, isDay, current.condition);
		}
		setText("observed_at", data.observed_at ? new Date(data.observed_at).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) : null);
		setText("source", data.attribution || data.source || "Weather source");
		renderForecast(data);
		renderAstronomy(data);
		renderObservations(data);

		document.querySelectorAll("[data-pisky-provider]").forEach(function (element) {
			element.textContent = data.source || data.provider || "Weather";
		});

		document.documentElement.style.setProperty(
			"--pisky-cloud-opacity",
			Math.min(0.46, Math.max(0.04, Number(current.cloud_cover || 0) / 220))
		);
		setStatus(true, "Live conditions");
	}

	async function loadWeather() {
		if (!document.querySelector("[data-pisky-weather], [data-pisky-weather-status]")) return;

		try {
			// The administration interface needs hidden metrics too, so it can
			// show their live values beside the visibility toggles.
			const scope = document.body.classList.contains("pisky-public")
				? "" : "&scope=admin";
			const response = await fetch(endpoint + "?_ts=" + Date.now() + scope, {
				cache: "no-store",
				headers: { "Accept": "application/json" }
			});
			const body = await response.text();
			let data;
			try {
				data = JSON.parse(body);
			} catch (error) {
				throw new Error("Weather API returned HTTP " + response.status);
			}
			update(data);
		} catch (error) {
			setStatus(false, document.body.classList.contains("pisky-public")
				? "Weather temporarily unavailable"
				: (error && error.message ? error.message : "Weather connection unavailable"));
		}
	}

	function boot() {
		// Static glyphs do not depend on live data, so paint them before the
		// first fetch rather than leaving empty boxes until it returns. The
		// moon starts half lit and is corrected once real data arrives.
		if (window.piskyWeatherIcons) {
			window.piskyWeatherIcons.applyMetricIcons();
			window.piskyWeatherIcons.applyAstroIcons(0.5);
		}
		loadWeather();
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", boot);
	} else {
		boot();
	}

	window.setInterval(loadWeather, refreshMs);
})();
