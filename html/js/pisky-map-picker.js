/*
 * PiSky location picker.
 *
 * Typing a latitude and longitude is precise but unforgiving: a dropped minus
 * sign puts the observatory in the wrong hemisphere and nothing on the page
 * says so. This opens the same basemap the radar draws, lets the host drag the
 * world under a fixed crosshair, and writes the coordinate under it back into
 * the number fields, which stay editable for anyone who already knows the
 * figures.
 *
 * The map is drawn on a canvas from OpenStreetMap tiles directly, matching the
 * radar, so the administration interface still ships no mapping library.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */
(function () {
	"use strict";

	const TILE_SIZE = 256;
	const MIN_ZOOM = 2;
	const MAX_ZOOM = 18;
	const MAX_LATITUDE = 85.05112878;
	const tiles = new Map();

	function worldSize(zoom) {
		return TILE_SIZE * Math.pow(2, zoom);
	}

	function project(latitude, longitude, zoom) {
		const size = worldSize(zoom);
		const clamped = Math.max(-MAX_LATITUDE, Math.min(MAX_LATITUDE, latitude)) * Math.PI / 180;
		return {
			x: (longitude + 180) / 360 * size,
			y: (1 - Math.log(Math.tan(clamped) + 1 / Math.cos(clamped)) / Math.PI) / 2 * size
		};
	}

	function unproject(x, y, zoom) {
		const size = worldSize(zoom);
		const n = Math.PI - 2 * Math.PI * y / size;
		return {
			latitude: 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n))),
			longitude: x / size * 360 - 180
		};
	}

	function wrapLongitude(longitude) {
		let value = (longitude + 180) % 360;
		if (value < 0) value += 360;
		return value - 180;
	}

	function loadTile(url, onReady) {
		if (tiles.has(url)) return tiles.get(url);
		const image = new Image();
		image.crossOrigin = "anonymous";
		image.decoding = "async";
		image.referrerPolicy = "no-referrer";
		image.addEventListener("load", onReady);
		image.addEventListener("error", function () { image.failed = true; });
		image.src = url;
		tiles.set(url, image);
		return image;
	}

	function readNumber(input, fallback) {
		if (!input) return fallback;
		const value = Number(input.value);
		return input.value !== "" && Number.isFinite(value) ? value : fallback;
	}

	function createPicker(dialog) {
		const canvas = dialog.querySelector("[data-pisky-map-canvas]");
		const readout = dialog.querySelector("[data-pisky-map-readout]");
		if (!canvas) return null;

		const context = canvas.getContext("2d");
		const latitudeName = dialog.getAttribute("data-pisky-map-latitude");
		const longitudeName = dialog.getAttribute("data-pisky-map-longitude");
		const latitudeInput = document.querySelector('[name="' + latitudeName + '"]');
		const longitudeInput = document.querySelector('[name="' + longitudeName + '"]');

		const state = {
			latitude: 0,
			longitude: 0,
			// A station with no coordinate yet starts on the whole world rather
			// than pretending to know a neighbourhood.
			zoom: 3,
			pointer: null
		};

		function size() {
			const ratio = window.devicePixelRatio || 1;
			const box = canvas.getBoundingClientRect();
			const width = Math.max(1, Math.round(box.width));
			const height = Math.max(1, Math.round(box.height));
			if (canvas.width !== Math.round(width * ratio) || canvas.height !== Math.round(height * ratio)) {
				canvas.width = Math.round(width * ratio);
				canvas.height = Math.round(height * ratio);
			}
			context.setTransform(ratio, 0, 0, ratio, 0, 0);
			return { width: width, height: height };
		}

		function draw() {
			const box = size();
			const centre = project(state.latitude, state.longitude, state.zoom);
			const originX = centre.x - box.width / 2;
			const originY = centre.y - box.height / 2;
			const span = Math.pow(2, state.zoom);

			context.clearRect(0, 0, box.width, box.height);
			// Sea colour behind the tiles, so panning past the edge of what has
			// loaded reads as ocean rather than as a broken map.
			context.fillStyle = "#0d1222";
			context.fillRect(0, 0, box.width, box.height);

			const firstX = Math.floor(originX / TILE_SIZE);
			const lastX = Math.floor((originX + box.width) / TILE_SIZE);
			const firstY = Math.max(0, Math.floor(originY / TILE_SIZE));
			const lastY = Math.min(span - 1, Math.floor((originY + box.height) / TILE_SIZE));

			for (let tileY = firstY; tileY <= lastY; tileY += 1) {
				for (let tileX = firstX; tileX <= lastX; tileX += 1) {
					// The world repeats east and west, so the column index wraps
					// while the drawing position does not.
					const wrapped = ((tileX % span) + span) % span;
					const url = "https://tile.openstreetmap.org/" + state.zoom
						+ "/" + wrapped + "/" + tileY + ".png";
					const tile = loadTile(url, draw);
					if (!tile.complete || tile.failed || !tile.naturalWidth) continue;
					context.drawImage(
						tile,
						Math.round(tileX * TILE_SIZE - originX),
						Math.round(tileY * TILE_SIZE - originY),
						TILE_SIZE,
						TILE_SIZE
					);
				}
			}

			drawCrosshair(box);
			if (readout) {
				readout.textContent = state.latitude.toFixed(5) + ", " + state.longitude.toFixed(5);
			}
		}

		function drawCrosshair(box) {
			const x = box.width / 2;
			const y = box.height / 2;

			context.save();
			context.strokeStyle = "rgba(0, 0, 0, 0.55)";
			context.lineWidth = 4;
			ring(x, y);
			context.stroke();
			context.strokeStyle = "#ffbd7a";
			context.lineWidth = 2;
			ring(x, y);
			context.stroke();
			context.fillStyle = "#ffbd7a";
			context.beginPath();
			context.arc(x, y, 3, 0, Math.PI * 2);
			context.fill();
			context.restore();
		}

		function ring(x, y) {
			context.beginPath();
			context.arc(x, y, 13, 0, Math.PI * 2);
			context.moveTo(x - 22, y);
			context.lineTo(x - 17, y);
			context.moveTo(x + 17, y);
			context.lineTo(x + 22, y);
			context.moveTo(x, y - 22);
			context.lineTo(x, y - 17);
			context.moveTo(x, y + 17);
			context.lineTo(x, y + 22);
		}

		/*
		 * Panning is clamped in world pixels, not in degrees.
		 *
		 * Clamping the latitude after unprojecting looks equivalent but
		 * ratchets: a drag past the pole is folded back to 85.05, and dragging
		 * the same distance the other way then starts from there rather than
		 * from where the map really was, so panning to the edge and back leaves
		 * the crosshair somewhere else entirely. Stopping the movement in the
		 * space the movement happens in makes it reversible.
		 */
		function panBy(dx, dy) {
			const size = worldSize(state.zoom);
			const centre = project(state.latitude, state.longitude, state.zoom);
			const moved = unproject(
				centre.x - dx,
				Math.max(0, Math.min(size, centre.y - dy)),
				state.zoom
			);
			state.latitude = moved.latitude;
			state.longitude = wrapLongitude(moved.longitude);
			draw();
		}

		function zoomBy(step) {
			const next = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, state.zoom + step));
			if (next === state.zoom) return;
			state.zoom = next;
			draw();
		}

		canvas.addEventListener("pointerdown", function (event) {
			// The drag is recorded before capture is requested. Capture only
			// keeps the pointer attached once it leaves the canvas, so failing
			// to get it should cost a little smoothness at the edges, not the
			// ability to drag at all.
			state.pointer = { x: event.clientX, y: event.clientY };
			canvas.classList.add("is-dragging");
			try {
				canvas.setPointerCapture(event.pointerId);
			} catch (error) { /* pointer already gone */ }
		});

		canvas.addEventListener("pointermove", function (event) {
			if (!state.pointer) return;
			panBy(event.clientX - state.pointer.x, event.clientY - state.pointer.y);
			state.pointer = { x: event.clientX, y: event.clientY };
		});

		function endDrag(event) {
			if (!state.pointer) return;
			state.pointer = null;
			canvas.classList.remove("is-dragging");
			try {
				if (canvas.hasPointerCapture(event.pointerId)) {
					canvas.releasePointerCapture(event.pointerId);
				}
			} catch (error) { /* never captured */ }
		}

		canvas.addEventListener("pointerup", endDrag);
		canvas.addEventListener("pointercancel", endDrag);

		canvas.addEventListener("wheel", function (event) {
			event.preventDefault();
			zoomBy(event.deltaY < 0 ? 1 : -1);
		}, { passive: false });

		// The crosshair is the target, so the arrow keys nudge the map under it
		// and give the whole control a keyboard path.
		canvas.addEventListener("keydown", function (event) {
			const step = event.shiftKey ? 100 : 20;
			const moves = {
				ArrowUp: [0, step], ArrowDown: [0, -step],
				ArrowLeft: [step, 0], ArrowRight: [-step, 0]
			};
			if (moves[event.key]) {
				event.preventDefault();
				panBy(moves[event.key][0], moves[event.key][1]);
			} else if (event.key === "+" || event.key === "=") {
				event.preventDefault();
				zoomBy(1);
			} else if (event.key === "-") {
				event.preventDefault();
				zoomBy(-1);
			}
		});

		dialog.querySelectorAll("[data-pisky-map-zoom]").forEach(function (button) {
			button.addEventListener("click", function () {
				zoomBy(Number(button.getAttribute("data-pisky-map-zoom")) || 0);
			});
		});

		dialog.querySelectorAll("[data-pisky-map-cancel]").forEach(function (button) {
			button.addEventListener("click", function () { dialog.close(); });
		});

		const apply = dialog.querySelector("[data-pisky-map-apply]");
		if (apply) {
			apply.addEventListener("click", function () {
				// Five decimal places is about a metre, which is far finer than
				// a station position needs and stops the field filling with
				// floating-point noise.
				if (latitudeInput) latitudeInput.value = state.latitude.toFixed(5);
				if (longitudeInput) longitudeInput.value = state.longitude.toFixed(5);
				[latitudeInput, longitudeInput].forEach(function (input) {
					if (input) input.dispatchEvent(new Event("change", { bubbles: true }));
				});
				dialog.close();
			});
		}

		window.addEventListener("resize", function () {
			if (dialog.open) draw();
		});

		return {
			open: function () {
				// Start wherever the fields already point, so opening the picker
				// to adjust a saved position does not throw it away.
				const latitude = readNumber(latitudeInput, null);
				const longitude = readNumber(longitudeInput, null);
				if (latitude !== null && longitude !== null) {
					state.latitude = Math.max(-MAX_LATITUDE, Math.min(MAX_LATITUDE, latitude));
					state.longitude = wrapLongitude(longitude);
					state.zoom = 12;
				}
				draw();
				canvas.focus();
			}
		};
	}

	function start() {
		const dialogs = document.querySelectorAll("[data-pisky-map-dialog]");
		if (!dialogs.length) return;

		const pickers = new Map();
		dialogs.forEach(function (dialog) {
			const picker = createPicker(dialog);
			if (picker) pickers.set(dialog.id, picker);
		});

		document.querySelectorAll("[data-pisky-map-open]").forEach(function (button) {
			const id = button.getAttribute("data-pisky-map-open");
			const dialog = document.getElementById(id);
			const picker = pickers.get(id);
			if (!dialog || !picker) return;
			button.addEventListener("click", function () {
				if (typeof dialog.showModal === "function") {
					dialog.showModal();
				} else {
					dialog.setAttribute("open", "open");
					dialog.classList.add("is-inline");
				}
				// The canvas has no size until the dialog is displayed, so the
				// first draw has to wait for it.
				picker.open();
			});
		});
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", start);
	} else {
		start();
	}
}());
