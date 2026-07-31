/*
 * PiSky local ADS-B client
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */
(function () {
	"use strict";

	const endpoint = "/pisky-flights.php";
	const refreshMs = 2500;
	let latestAircraft = [];
	let selectedIdentity = "";
	let hoveredIdentity = "";

	const reduceMotion = window.matchMedia
		&& window.matchMedia("(prefers-reduced-motion: reduce)").matches;

	// Altitude ramp follows the dump1090/tar1090 convention so operators reading
	// both tools see the same colour mean the same height.
	const altitudeRamp = [
		[0, [255, 77, 77]],
		[4000, [255, 140, 60]],
		[9000, [255, 214, 77]],
		[18000, [140, 240, 120]],
		[28000, [94, 234, 212]],
		[36000, [77, 170, 255]],
		[45000, [130, 150, 255]]
	];

	function rgbString(parts, alpha) {
		return alpha === undefined
			? "rgb(" + parts[0] + "," + parts[1] + "," + parts[2] + ")"
			: "rgba(" + parts[0] + "," + parts[1] + "," + parts[2] + "," + alpha + ")";
	}

	function altitudeParts(value) {
		const feet = Number(value);
		if (!Number.isFinite(feet)) return [150, 170, 200];
		if (feet <= altitudeRamp[0][0]) return altitudeRamp[0][1];
		for (let index = 1; index < altitudeRamp.length; index++) {
			if (feet > altitudeRamp[index][0]) continue;
			const low = altitudeRamp[index - 1];
			const high = altitudeRamp[index];
			const ratio = (feet - low[0]) / (high[0] - low[0]);
			return low[1].map(function (channel, position) {
				return Math.round(channel + (high[1][position] - channel) * ratio);
			});
		}
		return altitudeRamp[altitudeRamp.length - 1][1];
	}

	function identityOf(flight) {
		return (flight && (flight.hex || flight.callsign)) || "";
	}

	function labelOf(flight) {
		return (flight && (flight.callsign || flight.registration || flight.hex)) || "Unknown";
	}

	function setText(name, value) {
		document.querySelectorAll("[data-pisky-flights='" + name + "']").forEach(function (element) {
			element.textContent = value === null || value === undefined || value === "" ? "—" : String(value);
		});
	}

	function setStatus(ok, message) {
		document.querySelectorAll("[data-pisky-flights-status]").forEach(function (element) {
			element.classList.toggle("is-error", !ok);
			element.classList.toggle("is-live", ok);
			element.textContent = message;
		});
	}

	function compactNumber(value) {
		const number = Number(value);
		if (!Number.isFinite(number)) return null;
		try {
			return new Intl.NumberFormat([], { notation: "compact", maximumFractionDigits: 1 }).format(number);
		} catch (error) {
			return number.toLocaleString();
		}
	}

	function altitude(value) {
		if (typeof value === "string" && value.toLowerCase() === "ground") return "Ground";
		const number = Number(value);
		return Number.isFinite(number) ? Math.round(number).toLocaleString() + " ft" : "—";
	}

	function speed(value) {
		const number = Number(value);
		return Number.isFinite(number) ? Math.round(number) + " kt" : "—";
	}

	function degrees(value) {
		const number = Number(value);
		return Number.isFinite(number) ? Math.round(number) + "°" : "—";
	}

	function distance(value) {
		const number = Number(value);
		return Number.isFinite(number) ? number.toFixed(number < 10 ? 1 : 0) + " km" : "—";
	}

	function observedTime(value) {
		if (!value) return null;
		const date = new Date(value);
		return Number.isNaN(date.getTime())
			? null
			: date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit", second: "2-digit" });
	}

	function cell(text, className) {
		const element = document.createElement("td");
		if (className) element.className = className;
		element.textContent = text;
		return element;
	}

	function detailValue(value, formatter) {
		if (value === null || value === undefined || value === "") return "—";
		return formatter ? formatter(value) : String(value);
	}

	function renderDetail(flight) {
		document.querySelectorAll("[data-pisky-flight-detail]").forEach(function (panel) {
			panel.textContent = "";
			// Selecting an aircraft lets the card use the full height of the
			// scope, so the detail rows and the external lookup links are all
			// reachable without scrolling inside it.
			panel.classList.toggle("is-open", Boolean(flight));
			if (!flight) {
				const eyebrow = document.createElement("span");
				const heading = document.createElement("h3");
				const copy = document.createElement("p");
				eyebrow.className = "pisky-eyebrow";
				eyebrow.textContent = "Select an aircraft";
				heading.textContent = "Flight and aircraft details";
				copy.textContent = "Choose a target in the table or radar to inspect locally decoded information.";
				panel.appendChild(eyebrow);
				panel.appendChild(heading);
				panel.appendChild(copy);
				return;
			}

			// Dismiss control, so a visitor can return the card to its
			// unselected state without hunting for empty sky to click.
			const close = document.createElement("button");
			close.type = "button";
			close.className = "pisky-scope-close";
			close.setAttribute("aria-label", "Close aircraft details");
			close.textContent = "×";
			close.addEventListener("click", function (event) {
				event.stopPropagation();
				clearSelection();
			});
			panel.appendChild(close);

			const eyebrow = document.createElement("span");
			const heading = document.createElement("h3");
			const meta = document.createElement("p");
			const grid = document.createElement("dl");
			const links = document.createElement("div");
			eyebrow.className = "pisky-eyebrow";
			grid.className = "pisky-flight-detail-grid";
			links.className = "pisky-flight-detail-links";
			eyebrow.textContent = flight.emergency ? "Priority transponder state" : "Locally decoded target";
			heading.textContent = flight.callsign || flight.registration || flight.hex || "Unknown aircraft";
			meta.textContent = [flight.registration, flight.aircraft_type, flight.description]
				.filter(Boolean).join(" · ") || "The transponder has not supplied aircraft details.";
			// Only rows the transponder actually supplied are shown. A row of
			// em dashes tells the reader nothing, and an aircraft broadcasting
			// a minimal message would otherwise fill the panel with them.
			const present = function (value) {
				return value !== null && value !== undefined && value !== ""
					&& !(typeof value === "number" && !Number.isFinite(value));
			};
			const rows = [];
			if (present(flight.operator)) rows.push(["Operator", String(flight.operator)]);
			if (present(flight.origin) || present(flight.destination)) {
				rows.push(["Route",
					(present(flight.origin) ? flight.origin : "?")
					+ " → " + (present(flight.destination) ? flight.destination : "?")]);
			}
			if (present(flight.altitude_ft)) rows.push(["Altitude", altitude(flight.altitude_ft)]);
			if (present(flight.speed_knots)) rows.push(["Speed", speed(flight.speed_knots)]);
			if (present(flight.track)) rows.push(["Track", degrees(flight.track)]);
			if (present(flight.vertical_rate)) {
				rows.push(["Vertical rate",
					Math.round(Number(flight.vertical_rate)).toLocaleString() + " ft/min"]);
			}
			if (present(flight.distance_km)) rows.push(["Range", distance(flight.distance_km)]);
			if (present(flight.squawk)) rows.push(["Squawk", String(flight.squawk)]);
			if (present(flight.hex)) rows.push(["ICAO address", String(flight.hex)]);
			if (present(flight.category)) rows.push(["Category", String(flight.category)]);
			if (present(flight.rssi)) rows.push(["Signal", Number(flight.rssi).toFixed(1) + " dB"]);

			rows.forEach(function (item) {
				const wrapper = document.createElement("div");
				const term = document.createElement("dt");
				const description = document.createElement("dd");
				term.textContent = item[0];
				description.textContent = item[1];
				wrapper.appendChild(term);
				wrapper.appendChild(description);
				grid.appendChild(wrapper);
			});
			if (!rows.length) {
				const note = document.createElement("p");
				note.className = "pisky-flight-empty";
				note.textContent = "This aircraft is transmitting only an identifier.";
				grid.appendChild(note);
			}
			Object.keys(flight.lookup || {}).forEach(function (provider) {
				if (!flight.lookup[provider]) return;
				const link = document.createElement("a");
				link.href = flight.lookup[provider];
				link.target = "_blank";
				link.rel = "noopener";
				link.textContent = provider === "flightaware"
					? "Open in FlightAware ↗" : "Open in Flightradar24 ↗";
				links.appendChild(link);
			});
			panel.appendChild(eyebrow);
			panel.appendChild(heading);
			panel.appendChild(meta);
			panel.appendChild(grid);
			if (links.childNodes.length) {
				const note = document.createElement("small");
				note.textContent = "External services may supply a route or schedule when available.";
				panel.appendChild(links);
				panel.appendChild(note);
			}
		});
	}

	/* Return the panel to its unselected state and drop the radar highlight. */
	function clearSelection() {
		selectedIdentity = "";
		document.querySelectorAll("[data-pisky-flight-index]").forEach(function (element) {
			element.classList.remove("is-selected");
		});
		renderDetail(null);
	}

	function selectFlight(index) {
		const flight = latestAircraft[index];
		if (!flight) return;
		selectedIdentity = flight.hex || flight.callsign || "";
		document.querySelectorAll("[data-pisky-flight-index]").forEach(function (element) {
			element.classList.toggle("is-selected", Number(element.getAttribute("data-pisky-flight-index")) === index);
		});
		renderDetail(flight);
	}

	function renderFlightLists(aircraft) {
		document.querySelectorAll("[data-pisky-flight-list]").forEach(function (body) {
			const table = body.closest("table");
			const admin = table && table.classList.contains("pisky-flight-table-admin");
			const columns = admin ? 6 : 4;
			const limit = Math.max(1, Number(body.getAttribute("data-limit")) || 7);
			body.textContent = "";

			if (!aircraft.length) {
				const row = document.createElement("tr");
				const message = cell("No fresh aircraft are currently in receiver range.");
				message.colSpan = columns;
				message.className = "pisky-flight-empty";
				row.appendChild(message);
				body.appendChild(row);
				return;
			}

			aircraft.slice(0, limit).forEach(function (flight, index) {
				const row = document.createElement("tr");
				if (flight.emergency) row.className = "is-emergency";
				row.setAttribute("data-pisky-flight-index", index);
				row.setAttribute("tabindex", "0");
				row.setAttribute("role", "button");
				row.setAttribute("aria-label", "Show details for " + (flight.callsign || flight.hex || "aircraft"));
				row.addEventListener("click", function () { selectFlight(index); });
				row.addEventListener("keydown", function (event) {
					if (event.key === "Enter" || event.key === " ") {
						event.preventDefault();
						selectFlight(index);
					}
				});

				const identity = document.createElement("td");
				const callsign = document.createElement("strong");
				const hex = document.createElement("small");
				callsign.textContent = flight.callsign || flight.hex || "Unknown";
				hex.textContent = flight.hex || "No ICAO";
				identity.appendChild(callsign);
				identity.appendChild(hex);
				row.appendChild(identity);
				row.appendChild(cell(altitude(flight.altitude_ft)));
				row.appendChild(cell(speed(flight.speed_knots)));
				if (admin) row.appendChild(cell(degrees(flight.track)));
				row.appendChild(cell(distance(flight.distance_km)));
				if (admin) {
					const seen = Number(flight.seen_seconds);
					row.appendChild(cell(Number.isFinite(seen) ? seen.toFixed(1) + "s" : "—"));
				}
				body.appendChild(row);
			});
		});
	}

	// ---------------------------------------------------------------------
	// Canvas radar scope
	//
	// Targets are placed from latitude/longitude when the host publishes the
	// station position, so they line up with the basemap. Without coordinates
	// the scope falls back to the polar bearing/range pair, which the API
	// always provides.
	// ---------------------------------------------------------------------

	const TILE_SIZE = 256;
	const tileCache = new Map();
	const scopes = [];

	function projectWorld(latitude, longitude, worldSize) {
		const latRad = latitude * Math.PI / 180;
		const clamped = Math.max(-85.05112878, Math.min(85.05112878, latitude)) * Math.PI / 180;
		return {
			x: (longitude + 180) / 360 * worldSize,
			y: (1 - Math.log(Math.tan(clamped) + 1 / Math.cos(clamped)) / Math.PI) / 2 * worldSize,
			latRad: latRad
		};
	}

	function metresPerPixel(latitude, zoom) {
		return 156543.03392 * Math.cos(latitude * Math.PI / 180) / Math.pow(2, zoom);
	}

	function loadTile(url, onReady) {
		if (tileCache.has(url)) return tileCache.get(url);
		const image = new Image();
		image.crossOrigin = "anonymous";
		image.decoding = "async";
		image.referrerPolicy = "no-referrer";
		image.addEventListener("load", onReady);
		image.addEventListener("error", function () { image.failed = true; });
		image.src = url;
		tileCache.set(url, image);
		return image;
	}

	function createScope(canvas) {
		const context = canvas.getContext("2d");
		const scope = {
			canvas: canvas,
			context: context,
			width: 0,
			height: 0,
			ratio: 1,
			sweep: 0,
			centre: null,
			zoom: 8,
			rangeKm: 160,
			mapEnabled: false,
			trails: new Map(),
			pointer: { x: -9999, y: -9999, inside: false },
			placed: []
		};

		function resize() {
			const rect = canvas.getBoundingClientRect();
			if (!rect.width || !rect.height) return;
			scope.ratio = Math.min(window.devicePixelRatio || 1, 2);
			scope.width = rect.width;
			scope.height = rect.height;
			canvas.width = Math.round(rect.width * scope.ratio);
			canvas.height = Math.round(rect.height * scope.ratio);
			context.setTransform(scope.ratio, 0, 0, scope.ratio, 0, 0);
		}

		scope.resize = resize;
		resize();

		if (window.ResizeObserver) {
			new window.ResizeObserver(resize).observe(canvas);
		} else {
			window.addEventListener("resize", resize);
		}

		canvas.addEventListener("mousemove", function (event) {
			const rect = canvas.getBoundingClientRect();
			scope.pointer.x = event.clientX - rect.left;
			scope.pointer.y = event.clientY - rect.top;
			scope.pointer.inside = true;
			const hit = pickAt(scope, scope.pointer.x, scope.pointer.y);
			hoveredIdentity = hit ? identityOf(hit) : "";
			canvas.style.cursor = hit ? "pointer" : "default";
			updateHoverTag(scope, hit);
		});
		canvas.addEventListener("mouseleave", function () {
			scope.pointer.inside = false;
			hoveredIdentity = "";
			updateHoverTag(scope, null);
		});
		canvas.addEventListener("click", function (event) {
			const rect = canvas.getBoundingClientRect();
			const hit = pickAt(scope, event.clientX - rect.left, event.clientY - rect.top);
			if (hit) selectByIdentity(identityOf(hit));
		});

		return scope;
	}

	function pickAt(scope, x, y) {
		let best = null;
		let bestDistance = 20;
		scope.placed.forEach(function (entry) {
			const gap = Math.hypot(entry.x - x, entry.y - y);
			if (gap < bestDistance) {
				bestDistance = gap;
				best = entry.flight;
			}
		});
		return best;
	}

	function updateHoverTag(scope, flight) {
		const tag = document.querySelector("[data-pisky-radar-tag]");
		if (!tag) return;
		if (!flight) {
			tag.classList.remove("is-visible");
			return;
		}
		const entry = scope.placed.find(function (item) {
			return identityOf(item.flight) === identityOf(flight);
		});
		if (!entry) {
			tag.classList.remove("is-visible");
			return;
		}
		const rect = scope.canvas.getBoundingClientRect();
		const host = tag.offsetParent
			? tag.offsetParent.getBoundingClientRect()
			: { left: 0, top: 0 };
		tag.style.left = (rect.left - host.left + entry.x) + "px";
		tag.style.top = (rect.top - host.top + entry.y) + "px";
		tag.textContent = labelOf(flight) + " · " + altitude(flight.altitude_ft);
		tag.classList.add("is-visible");
	}

	function ringSteps(rangeKm) {
		const raw = rangeKm / 4;
		const magnitude = Math.pow(10, Math.floor(Math.log10(raw)));
		const step = [1, 2, 2.5, 5, 10].reduce(function (chosen, factor) {
			const candidate = factor * magnitude;
			return candidate <= raw ? candidate : chosen;
		}, magnitude);
		const rings = [];
		for (let value = step; value <= rangeKm + 0.001; value += step) rings.push(value);
		return rings.length ? rings : [rangeKm];
	}

	function drawTiles(scope) {
		if (!scope.mapEnabled || !scope.centre) return;
		const context = scope.context;
		const worldSize = TILE_SIZE * Math.pow(2, scope.zoom);
		const centre = projectWorld(scope.centre.latitude, scope.centre.longitude, worldSize);
		const originX = centre.x - scope.width / 2;
		const originY = centre.y - scope.height / 2;
		const firstColumn = Math.floor(originX / TILE_SIZE);
		const lastColumn = Math.floor((originX + scope.width) / TILE_SIZE);
		const firstRow = Math.floor(originY / TILE_SIZE);
		const lastRow = Math.floor((originY + scope.height) / TILE_SIZE);
		const span = Math.pow(2, scope.zoom);
		const redraw = function () { scope.dirty = true; };

		context.save();
		context.globalAlpha = 0.55;
		for (let column = firstColumn; column <= lastColumn; column++) {
			for (let row = firstRow; row <= lastRow; row++) {
				if (row < 0 || row >= span) continue;
				const wrapped = ((column % span) + span) % span;
				const url = "https://tile.openstreetmap.org/" + scope.zoom
					+ "/" + wrapped + "/" + row + ".png";
				const tile = loadTile(url, redraw);
				if (!tile.complete || tile.failed || !tile.naturalWidth) continue;
				context.drawImage(
					tile,
					Math.round(column * TILE_SIZE - originX),
					Math.round(row * TILE_SIZE - originY),
					TILE_SIZE,
					TILE_SIZE
				);
			}
		}
		context.restore();

		// Darken the basemap so range rings and targets stay legible over it.
		const wash = context.createRadialGradient(
			scope.width / 2, scope.height / 2, 0,
			scope.width / 2, scope.height / 2, Math.max(scope.width, scope.height) * 0.75
		);
		wash.addColorStop(0, "rgba(7, 11, 22, 0.35)");
		wash.addColorStop(1, "rgba(7, 11, 22, 0.78)");
		context.fillStyle = wash;
		context.fillRect(0, 0, scope.width, scope.height);
	}

	function placeFlight(scope, flight) {
		const centreX = scope.width / 2;
		const centreY = scope.height / 2;
		if (scope.centre && Number.isFinite(Number(flight.latitude))
			&& Number.isFinite(Number(flight.longitude))) {
			const worldSize = TILE_SIZE * Math.pow(2, scope.zoom);
			const origin = projectWorld(scope.centre.latitude, scope.centre.longitude, worldSize);
			const point = projectWorld(Number(flight.latitude), Number(flight.longitude), worldSize);
			return { x: centreX + (point.x - origin.x), y: centreY + (point.y - origin.y) };
		}
		const bearing = Number(flight.bearing);
		const range = Number(flight.distance_km);
		if (!Number.isFinite(bearing) || !Number.isFinite(range)) return null;
		const radius = (range / scope.rangeKm) * scope.scopeRadius;
		const angle = bearing * Math.PI / 180;
		return { x: centreX + Math.sin(angle) * radius, y: centreY - Math.cos(angle) * radius };
	}

	function drawScope(scope, elapsed) {
		const context = scope.context;
		if (!scope.width || !scope.height) return;
		const centreX = scope.width / 2;
		const centreY = scope.height / 2;
		context.clearRect(0, 0, scope.width, scope.height);

		const backdrop = context.createRadialGradient(
			centreX, centreY, 0, centreX, centreY, Math.max(scope.width, scope.height) * 0.7
		);
		backdrop.addColorStop(0, "rgba(16, 26, 48, 0.92)");
		backdrop.addColorStop(0.5, "rgba(10, 16, 32, 0.94)");
		backdrop.addColorStop(1, "rgba(6, 10, 20, 0.96)");
		context.fillStyle = backdrop;
		context.fillRect(0, 0, scope.width, scope.height);

		drawTiles(scope);

		// Scope radius: how many pixels one configured range equals.
		if (scope.mapEnabled && scope.centre) {
			const mpp = metresPerPixel(scope.centre.latitude, scope.zoom);
			scope.scopeRadius = (scope.rangeKm * 1000) / mpp;
		} else {
			scope.scopeRadius = Math.min(scope.width, scope.height) * 0.46;
		}

		context.save();
		context.translate(centreX, centreY);

		context.strokeStyle = "rgba(255,255,255,.055)";
		context.lineWidth = 1;
		for (let degrees = 0; degrees < 360; degrees += 30) {
			const radians = degrees * Math.PI / 180;
			context.beginPath();
			context.moveTo(0, 0);
			context.lineTo(
				Math.sin(radians) * scope.scopeRadius,
				-Math.cos(radians) * scope.scopeRadius
			);
			context.stroke();
		}

		context.font = '10px "JetBrains Mono", ui-monospace, monospace';
		context.textAlign = "left";
		ringSteps(scope.rangeKm).forEach(function (km) {
			const mpp = scope.mapEnabled && scope.centre
				? metresPerPixel(scope.centre.latitude, scope.zoom) : null;
			const radius = mpp ? (km * 1000) / mpp : (km / scope.rangeKm) * scope.scopeRadius;
			if (radius > Math.hypot(scope.width, scope.height)) return;
			context.beginPath();
			context.arc(0, 0, radius, 0, Math.PI * 2);
			context.strokeStyle = "rgba(120,150,210,.20)";
			context.lineWidth = 1;
			context.stroke();
			context.fillStyle = "rgba(150,170,220,.42)";
			context.fillText(Math.round(km) + " km", 5, -radius + 13);
		});

		if (!reduceMotion) {
			scope.sweep += elapsed * 0.00042;
			context.save();
			context.rotate(scope.sweep);
			if (context.createConicGradient) {
				const cone = context.createConicGradient(0, 0, 0);
				cone.addColorStop(0, "rgba(94,234,212,.17)");
				cone.addColorStop(0.09, "rgba(94,234,212,0)");
				cone.addColorStop(1, "rgba(94,234,212,0)");
				context.fillStyle = cone;
				context.beginPath();
				context.moveTo(0, 0);
				context.arc(0, 0, scope.scopeRadius, -0.5, 0);
				context.closePath();
				context.fill();
			}
			context.strokeStyle = "rgba(94,234,212,.32)";
			context.lineWidth = 1.5;
			context.beginPath();
			context.moveTo(0, 0);
			context.lineTo(scope.scopeRadius, 0);
			context.stroke();
			context.restore();
		}

		context.fillStyle = "rgba(160,180,225,.55)";
		context.font = '600 11px "Space Grotesk", system-ui, sans-serif';
		context.textAlign = "center";
		context.textBaseline = "middle";
		const compass = scope.scopeRadius + 14;
		[["N", 0, -compass], ["E", compass, 0], ["S", 0, compass], ["W", -compass, 0]]
			.forEach(function (item) {
				if (Math.abs(item[1]) > scope.width / 2 || Math.abs(item[2]) > scope.height / 2) return;
				context.fillText(item[0], item[1], item[2]);
			});

		const pulse = reduceMotion ? 0 : Math.sin(performance.now() * 0.004) * 1.4;
		context.beginPath();
		context.arc(0, 0, 12 + pulse, 0, Math.PI * 2);
		context.fillStyle = "rgba(255,179,71,.14)";
		context.fill();
		context.beginPath();
		context.arc(0, 0, 4, 0, Math.PI * 2);
		context.fillStyle = "#ffb347";
		context.shadowColor = "#ffb347";
		context.shadowBlur = 14;
		context.fill();
		context.restore();

		scope.placed = [];
		latestAircraft.forEach(function (flight) {
			const point = placeFlight(scope, flight);
			if (!point) return;
			if (point.x < -60 || point.y < -60
				|| point.x > scope.width + 60 || point.y > scope.height + 60) return;

			const identity = identityOf(flight);
			const parts = flight.emergency ? [255, 92, 92] : altitudeParts(flight.altitude_ft);
			const colour = rgbString(parts);
			const isSelected = identity !== "" && identity === selectedIdentity;
			const isHovered = identity !== "" && identity === hoveredIdentity;
			const active = isSelected || isHovered;
			scope.placed.push({ flight: flight, x: point.x, y: point.y });

			const trail = scope.trails.get(identity) || [];
			if (trail.length > 1) {
				context.beginPath();
				trail.forEach(function (node, index) {
					if (index) context.lineTo(node.x, node.y);
					else context.moveTo(node.x, node.y);
				});
				context.lineTo(point.x, point.y);
				context.strokeStyle = rgbString(parts, 0.3);
				context.lineWidth = 1.5;
				context.stroke();
			}

			if (active) {
				context.beginPath();
				context.arc(point.x, point.y, 13, 0, Math.PI * 2);
				context.strokeStyle = isSelected ? "rgba(255,179,71,.9)" : "rgba(255,255,255,.5)";
				context.lineWidth = 1.4;
				context.stroke();
			}

			const track = Number(flight.track);
			const heading = Number.isFinite(track) ? track : Number(flight.bearing) || 0;
			context.save();
			context.translate(point.x, point.y);
			context.rotate(heading * Math.PI / 180);
			context.fillStyle = colour;
			context.shadowColor = colour;
			context.shadowBlur = active ? 14 : 7;
			context.beginPath();
			context.moveTo(0, -6);
			context.lineTo(4.4, 5);
			context.lineTo(0, 2.6);
			context.lineTo(-4.4, 5);
			context.closePath();
			context.fill();
			context.restore();

			if (active) {
				context.font = '600 11px "JetBrains Mono", ui-monospace, monospace';
				context.textAlign = "left";
				context.textBaseline = "alphabetic";
				context.shadowColor = "rgba(0,0,0,.85)";
				context.shadowBlur = 6;
				context.fillStyle = "#eaf0ff";
				context.fillText(labelOf(flight), point.x + 11, point.y - 7);
				context.shadowBlur = 0;
			}
		});
	}

	function recordTrails() {
		scopes.forEach(function (scope) {
			const seen = new Set();
			scope.placed.forEach(function (entry) {
				const identity = identityOf(entry.flight);
				if (!identity) return;
				seen.add(identity);
				const trail = scope.trails.get(identity) || [];
				trail.push({ x: entry.x, y: entry.y });
				while (trail.length > 14) trail.shift();
				scope.trails.set(identity, trail);
			});
			scope.trails.forEach(function (value, identity) {
				if (!seen.has(identity)) scope.trails.delete(identity);
			});
		});
	}

	/*
	 * Choose the basemap zoom.
	 *
	 * A level configured in PiSky Setup is honoured exactly, so the host
	 * decides how much ground the scope covers. Zoom 0 means "fit the range",
	 * which is the behaviour below. Rewriting the radar as a canvas originally
	 * dropped the configured value entirely and always auto-fitted.
	 */
	function fitZoom(scope) {
		if (!scope.mapEnabled || !scope.centre || !scope.width) return;
		if (Number.isFinite(scope.configuredZoom) && scope.configuredZoom >= 3) {
			scope.zoom = Math.max(3, Math.min(16, Math.round(scope.configuredZoom)));
			return;
		}
		const target = Math.min(scope.width, scope.height) * 0.46;
		// Zoom levels double, so demanding the outer ring fit exactly can waste
		// almost half the stage. Allow a little overflow and pick whichever
		// level lands closest to the target radius.
		let best = 3;
		let bestScore = Infinity;
		for (let zoom = 3; zoom <= 12; zoom++) {
			const radius = (scope.rangeKm * 1000) / metresPerPixel(scope.centre.latitude, zoom);
			if (radius > target * 1.15) continue;
			const score = Math.abs(target - radius);
			if (score < bestScore) {
				bestScore = score;
				best = zoom;
			}
		}
		scope.zoom = best;
	}

	function startScopes() {
		document.querySelectorAll("[data-pisky-radar-canvas]").forEach(function (canvas) {
			scopes.push(createScope(canvas));
		});
		if (!scopes.length) return;

		let previous = performance.now();
		const frame = function (now) {
			const elapsed = Math.min(now - previous, 80);
			previous = now;
			scopes.forEach(function (scope) { drawScope(scope, elapsed); });
			window.requestAnimationFrame(frame);
		};
		window.requestAnimationFrame(frame);
		window.setInterval(recordTrails, 900);
	}

	function applyScopeData(data) {
		const receiver = (data && data.receiver) || {};
		const rangeKm = Number(receiver.range_km);
		const latitude = Number(receiver.latitude);
		const longitude = Number(receiver.longitude);
		const configuredZoom = Number(receiver.zoom);
		scopes.forEach(function (scope) {
			if (Number.isFinite(rangeKm) && rangeKm > 0) scope.rangeKm = rangeKm;
			scope.configuredZoom = configuredZoom;
			scope.mapEnabled = Boolean(receiver.map_enabled)
				&& Number.isFinite(latitude) && Number.isFinite(longitude);
			scope.centre = scope.mapEnabled
				? { latitude: latitude, longitude: longitude } : null;
			fitZoom(scope);
		});
	}

	function selectByIdentity(identity) {
		const index = latestAircraft.findIndex(function (flight) {
			return identityOf(flight) === identity;
		});
		if (index >= 0) selectFlight(index);
	}

	function renderRadar(aircraft, rangeKm) {
		document.querySelectorAll("[data-pisky-radar]").forEach(function (layer) {
			layer.textContent = "";
			aircraft.forEach(function (flight, index) {
				const bearing = Number(flight.bearing);
				const range = Number(flight.distance_km);
				if (!Number.isFinite(bearing) || !Number.isFinite(range) || !Number.isFinite(rangeKm)) return;

				const radius = Math.min(46, Math.max(2, (range / rangeKm) * 46));
				const angle = bearing * Math.PI / 180;
				const left = 50 + Math.sin(angle) * radius;
				const top = 50 - Math.cos(angle) * radius;
				const blip = document.createElement("button");
				const track = Number(flight.track);
				blip.className = "pisky-aircraft-blip" + (flight.emergency ? " is-emergency" : "");
				blip.style.left = left + "%";
				blip.style.top = top + "%";
				blip.style.setProperty("--pisky-aircraft-track", (Number.isFinite(track) ? track : bearing) + "deg");
				blip.type = "button";
				blip.setAttribute("data-pisky-flight-index", index);
				blip.setAttribute("aria-label", "Show details for " + (flight.callsign || flight.hex || "aircraft"));
				blip.addEventListener("click", function () { selectFlight(index); });
				blip.title = (flight.callsign || flight.hex || "Aircraft") + " · "
					+ altitude(flight.altitude_ft) + " · " + distance(flight.distance_km);

				const marker = document.createElement("i");
				const label = document.createElement("b");
				label.textContent = flight.callsign || flight.hex || "";
				blip.appendChild(marker);
				blip.appendChild(label);
				layer.appendChild(blip);
			});
		});
	}

	function updateSharing(sharing) {
		["flightaware", "flightradar24"].forEach(function (name) {
			const details = sharing && sharing[name] ? sharing[name] : {};
			document.querySelectorAll("[data-pisky-sharing='" + name + "']").forEach(function (element) {
				element.classList.toggle("active", Boolean(details.enabled));
				const state = element.querySelector("b");
				if (state) state.textContent = details.enabled ? "enabled" : "optional";
			});
		});
	}

	function update(data) {
		if (!data || !data.ok || !data.stats) {
			setStatus(false, document.body.classList.contains("pisky-public")
				? "No live receiver data"
				: (data && data.error ? data.error : "Receiver unavailable"));
			latestAircraft = [];
			renderFlightLists([]);
			renderRadar([], 1);
			return;
		}

		const stats = data.stats;
		const aircraft = Array.isArray(data.aircraft) ? data.aircraft : [];
		latestAircraft = aircraft;
		applyScopeData(data);
		const rangeKm = Number(data.receiver && data.receiver.range_km);
		setText("aircraft_count", stats.aircraft_count);
		setText("positioned_count", stats.positioned_count);
		setText("nearest", distance(stats.nearest_km));
		setText("messages", compactNumber(stats.messages));
		setText("range", distance(rangeKm));
		setText("decoder", data.decoder || "Local ADS-B receiver");
		setText("observed_at", observedTime(data.observed_at));
		renderFlightLists(aircraft);
		renderRadar(aircraft, rangeKm);
		let selected = null;
		if (selectedIdentity) {
			selected = aircraft.find(function (flight) {
				return (flight.hex || flight.callsign || "") === selectedIdentity;
			}) || null;
		}
		renderDetail(selected);
		updateSharing(data.sharing || {});
		setStatus(!data.stale, data.stale ? "Receiver data is stale" : "Local receiver live");
	}

	async function loadFlights() {
		if (!document.querySelector("[data-pisky-flights], [data-pisky-flights-status], [data-pisky-radar], [data-pisky-radar-canvas]")) return;

		try {
			const response = await fetch(endpoint + "?_ts=" + Date.now(), {
				cache: "no-store",
				headers: { "Accept": "application/json" }
			});
			const body = await response.text();
			let data;
			try {
				data = JSON.parse(body);
			} catch (error) {
				throw new Error("Flight API returned HTTP " + response.status);
			}
			update(data);
		} catch (error) {
			setStatus(false, document.body.classList.contains("pisky-public")
				? "No live receiver data"
				: (error && error.message ? error.message : "Receiver connection unavailable"));
		}
	}

	function boot() {
		startScopes();
		loadFlights();
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", boot);
	} else {
		boot();
	}

	window.setInterval(loadFlights, refreshMs);
})();
