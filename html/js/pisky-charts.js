/*
 * PiSky interactive charts.
 *
 * Canvas line charts drawn directly rather than through a plotting library, so
 * the appliance stays self-contained and works with no internet connection.
 * A Raspberry Pi serving a handful of series does not need a general-purpose
 * charting engine, and one fewer dependency is one fewer thing to update on a
 * device that may be running unattended for years.
 *
 * Series are plotted against a shared time axis with an independent vertical
 * scale each, because temperature, pressure and rainfall share no useful
 * range. Hovering reads out every series at the nearest sample.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */
(function (global) {
	"use strict";

	const reduceMotion = global.matchMedia
		&& global.matchMedia("(prefers-reduced-motion: reduce)").matches;

	/* Palette follows the metric glyphs so a line matches its card. */
	const SERIES_COLOUR = {
		temperature: "#ffbd7a",
		apparent_temperature: "#ff9d5c",
		dew_point: "#61d9ff",
		humidity: "#5ee6aa",
		pressure: "#9a82f4",
		wind_speed: "#7fb3ff",
		wind_gust: "#b28cff",
		rain: "#4fc3f7",
		rain_rate: "#4fc3f7",
		cloud_cover: "#c3ccdd",
		visibility: "#8fe0c4",
		uv: "#ffd166",
		solar_radiation: "#ffe08a"
	};

	/*
	 * A reading is only usable if it is genuinely a number.
	 *
	 * Number(null) and Number("") are both 0, which is finite, so testing the
	 * coerced value alone treats a series of absent readings as a valid series
	 * of zeroes. A sensor that reported nothing all day would then be drawn as
	 * a flat line along the axis rather than being left out.
	 */
	function isReading(value) {
		if (value === null || value === undefined || value === "") return false;
		return Number.isFinite(Number(value));
	}

	function colourFor(field, index) {
		if (SERIES_COLOUR[field]) return SERIES_COLOUR[field];
		const fallback = ["#61d9ff", "#9a82f4", "#5ee6aa", "#ffbd7a", "#ff8798"];
		return fallback[index % fallback.length];
	}

	function niceStep(range, targetTicks) {
		if (!(range > 0)) return 1;
		const rough = range / Math.max(1, targetTicks);
		const magnitude = Math.pow(10, Math.floor(Math.log10(rough)));
		const candidates = [1, 2, 2.5, 5, 10].map(function (m) { return m * magnitude; });
		let best = candidates[0];
		let bestGap = Infinity;
		candidates.forEach(function (candidate) {
			const gap = Math.abs(candidate - rough);
			if (gap < bestGap) { bestGap = gap; best = candidate; }
		});
		return best;
	}

	function formatValue(value, unit) {
		if (!isReading(value)) return "—";
		const number = Number(value);
		const decimals = Math.abs(number) >= 100 ? 0 : (Math.abs(number) >= 10 ? 1 : 2);
		return number.toFixed(decimals).replace(/\.0+$/, "") + (unit ? " " + unit : "");
	}

	function Chart(canvas, options) {
		this.canvas = canvas;
		this.context = canvas.getContext("2d");
		this.options = options || {};
		this.series = [];
		this.points = [];
		this.hoverIndex = -1;
		this.ratio = 1;
		this.width = 0;
		this.height = 0;
		this.padding = { top: 16, right: 14, bottom: 26, left: 46 };

		const self = this;
		this.resize();
		if (global.ResizeObserver) {
			new global.ResizeObserver(function () { self.resize(); self.draw(); })
				.observe(canvas);
		} else {
			global.addEventListener("resize", function () { self.resize(); self.draw(); });
		}

		canvas.addEventListener("mousemove", function (event) {
			const rect = canvas.getBoundingClientRect();
			self.setHover(event.clientX - rect.left);
		});
		canvas.addEventListener("mouseleave", function () {
			self.hoverIndex = -1;
			self.draw();
			self.report(null);
		});
		// Touch reads the same way as hover, so the readout works on a phone.
		canvas.addEventListener("touchstart", function (event) {
			const rect = canvas.getBoundingClientRect();
			self.setHover(event.touches[0].clientX - rect.left);
		}, { passive: true });
		canvas.addEventListener("touchmove", function (event) {
			const rect = canvas.getBoundingClientRect();
			self.setHover(event.touches[0].clientX - rect.left);
		}, { passive: true });
	}

	Chart.prototype.resize = function () {
		const rect = this.canvas.getBoundingClientRect();
		if (!rect.width || !rect.height) return;
		this.ratio = Math.min(global.devicePixelRatio || 1, 2);
		this.width = rect.width;
		this.height = rect.height;
		this.canvas.width = Math.round(rect.width * this.ratio);
		this.canvas.height = Math.round(rect.height * this.ratio);
		this.context.setTransform(this.ratio, 0, 0, this.ratio, 0, 0);
	};

	/*
	 * points: [{ time: Date, values: { field: number|null } }]
	 * series: [{ field, label, unit }]
	 */
	Chart.prototype.setData = function (points, series) {
		this.points = Array.isArray(points) ? points : [];
		this.series = Array.isArray(series) ? series : [];
		this.hoverIndex = -1;
		this.draw();
	};

	Chart.prototype.plotArea = function () {
		return {
			x: this.padding.left,
			y: this.padding.top,
			width: Math.max(10, this.width - this.padding.left - this.padding.right),
			height: Math.max(10, this.height - this.padding.top - this.padding.bottom)
		};
	};

	Chart.prototype.extentFor = function (field) {
		let low = Infinity;
		let high = -Infinity;
		this.points.forEach(function (point) {
			if (!isReading(point.values[field])) return;
			const value = Number(point.values[field]);
			if (value < low) low = value;
			if (value > high) high = value;
		});
		if (low === Infinity) return null;
		if (low === high) { low -= 1; high += 1; }
		// A little headroom stops a line tracing the frame.
		const margin = (high - low) * 0.08;
		return { low: low - margin, high: high + margin };
	};

	Chart.prototype.setHover = function (x) {
		const area = this.plotArea();
        if (!this.points.length) return;
		const ratio = (x - area.x) / area.width;
		const index = Math.round(ratio * (this.points.length - 1));
		this.hoverIndex = Math.max(0, Math.min(this.points.length - 1, index));
		this.draw();
		this.report(this.points[this.hoverIndex]);
	};

	/* Publish the hovered sample so a panel beside the chart can show it. */
	Chart.prototype.report = function (point) {
		if (typeof this.options.onHover === "function") this.options.onHover(point);
	};

	Chart.prototype.draw = function () {
		const context = this.context;
		if (!this.width || !this.height) return;
		context.clearRect(0, 0, this.width, this.height);
		const area = this.plotArea();

		if (this.points.length < 2 || !this.series.length) {
			context.fillStyle = "rgba(190, 200, 225, 0.55)";
			context.font = '13px Inter, system-ui, sans-serif';
			context.textAlign = "center";
			context.fillText(
				this.options.emptyMessage || "Not enough detail recorded for this day.",
				this.width / 2, this.height / 2
			);
			return;
		}

		// The first series owns the labelled axis; the rest share the shape.
		const primary = this.series[0];
		const primaryExtent = this.extentFor(primary.field);
		if (primaryExtent) {
			const step = niceStep(primaryExtent.high - primaryExtent.low, 4);
			context.font = '11px "JetBrains Mono", ui-monospace, monospace';
			context.textAlign = "right";
			context.textBaseline = "middle";
			for (let value = Math.ceil(primaryExtent.low / step) * step;
				value <= primaryExtent.high; value += step) {
				const y = area.y + area.height
					- ((value - primaryExtent.low) / (primaryExtent.high - primaryExtent.low)) * area.height;
				context.strokeStyle = "rgba(255, 255, 255, 0.06)";
				context.lineWidth = 1;
				context.beginPath();
				context.moveTo(area.x, y);
				context.lineTo(area.x + area.width, y);
				context.stroke();
				context.fillStyle = "rgba(180, 190, 215, 0.6)";
				context.fillText(formatValue(value, ""), area.x - 8, y);
			}
		}

		// Time axis: a label every few hours, whichever divides evenly.
		const first = this.points[0].time;
		const last = this.points[this.points.length - 1].time;
		const span = Math.max(1, last - first);
		context.textAlign = "center";
		context.textBaseline = "top";
		const hourStep = span > 20 * 3600000 ? 6 : (span > 8 * 3600000 ? 3 : 1);
		for (let index = 0; index < this.points.length; index++) {
			const time = this.points[index].time;
			if (time.getMinutes() !== 0 || time.getHours() % hourStep !== 0) continue;
			const x = area.x + ((time - first) / span) * area.width;
			context.strokeStyle = "rgba(255, 255, 255, 0.045)";
			context.beginPath();
			context.moveTo(x, area.y);
			context.lineTo(x, area.y + area.height);
			context.stroke();
			context.fillStyle = "rgba(180, 190, 215, 0.55)";
			context.fillText(
				String(time.getHours()).padStart(2, "0"),
				x, area.y + area.height + 7
			);
		}

		const self = this;
		this.series.forEach(function (entry, seriesIndex) {
			const extent = self.extentFor(entry.field);
			if (!extent) return;
			const colour = colourFor(entry.field, seriesIndex);
			context.strokeStyle = colour;
			context.lineWidth = seriesIndex === 0 ? 2.2 : 1.6;
			context.lineJoin = "round";
			context.lineCap = "round";
			context.globalAlpha = seriesIndex === 0 ? 1 : 0.75;
			context.beginPath();
			let started = false;
			self.points.forEach(function (point, index) {
				if (!isReading(point.values[entry.field])) { started = false; return; }
				const value = Number(point.values[entry.field]);
				const x = area.x + ((point.time - first) / span) * area.width;
				const y = area.y + area.height
					- ((value - extent.low) / (extent.high - extent.low)) * area.height;
				if (!started) { context.moveTo(x, y); started = true; }
				else context.lineTo(x, y);
			});
			context.stroke();
			context.globalAlpha = 1;
		});

		if (this.hoverIndex >= 0 && this.hoverIndex < this.points.length) {
			const point = this.points[this.hoverIndex];
			const x = area.x + ((point.time - first) / span) * area.width;
			context.strokeStyle = "rgba(255, 255, 255, 0.32)";
			context.lineWidth = 1;
			context.setLineDash([3, 3]);
			context.beginPath();
			context.moveTo(x, area.y);
			context.lineTo(x, area.y + area.height);
			context.stroke();
			context.setLineDash([]);
			this.series.forEach(function (entry, seriesIndex) {
				const extent = self.extentFor(entry.field);
				if (!extent || !isReading(point.values[entry.field])) return;
				const value = Number(point.values[entry.field]);
				const y = area.y + area.height
					- ((value - extent.low) / (extent.high - extent.low)) * area.height;
				context.fillStyle = colourFor(entry.field, seriesIndex);
				context.beginPath();
				context.arc(x, y, 3.4, 0, Math.PI * 2);
				context.fill();
			});
		}
	};

	function create(canvas, options) {
		return new Chart(canvas, options);
	}

	global.piskyCharts = {
		create: create,
		colourFor: colourFor,
		isReading: isReading,
		formatValue: formatValue,
		reduceMotion: reduceMotion
	};
}(window));
