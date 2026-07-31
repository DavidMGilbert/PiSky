"use strict";

/*
 * PiSky theme controller.
 *
 * Theme classes are deliberately added and removed individually.  The
 * inherited Allsky controller used to replace body.className wholesale,
 * which removed pisky-admin/pisky-public and disabled most of the layout.
 */
(function () {
	var storageKey = "pisky-theme";
	var allowed = ["auto", "dark", "light"];
	var media = window.matchMedia
		? window.matchMedia("(prefers-color-scheme: dark)")
		: null;

	function storedTheme() {
		var theme = null;
		try {
			theme = window.localStorage.getItem(storageKey);
			if (!theme) {
				var legacy = window.localStorage.getItem("theme");
				if (legacy === "light" || legacy === "dark") theme = legacy;
			}
		} catch (error) {
			theme = null;
		}
		return allowed.indexOf(theme) === -1 ? "auto" : theme;
	}

	function effectiveTheme(theme) {
		if (theme !== "auto") return theme;
		return media && media.matches ? "dark" : "light";
	}

	function updateControls(theme, effective) {
		var controls = document.querySelectorAll("[data-pisky-theme-toggle]");
		var next = theme === "auto" ? "dark" : (theme === "dark" ? "light" : "auto");
		var labels = {
			auto: "Theme: Auto",
			dark: "Theme: Dark",
			light: "Theme: Light"
		};
		var icons = {
			auto: "◐",
			dark: "☾",
			light: "☀"
		};
		Array.prototype.forEach.call(controls, function (control) {
			control.setAttribute("data-theme", theme);
			control.setAttribute("aria-label", labels[theme] + ". Select " + labels[next] + ".");
			control.setAttribute("title", labels[theme] + " (currently rendered " + effective + ")");
			var label = control.querySelector("[data-pisky-theme-label]");
			if (label) label.textContent = labels[theme].replace("Theme: ", "");
			var icon = control.querySelector("[data-pisky-theme-icon]");
			if (icon) icon.textContent = icons[theme];
		});
	}

	function apply(theme, persist) {
		if (allowed.indexOf(theme) === -1) theme = "auto";
		var body = document.body;
		if (!body) return;
		var effective = effectiveTheme(theme);
		body.classList.remove("light", "dark", "pisky-theme-light", "pisky-theme-dark");
		body.classList.add(effective, "pisky-theme-" + effective);
		body.setAttribute("data-pisky-theme", theme);
		body.style.colorScheme = effective;
		if (persist) {
			try {
				window.localStorage.setItem(storageKey, theme);
				window.localStorage.removeItem("theme");
			} catch (error) {
				// The selected theme still applies for this page view.
			}
		}
		updateControls(theme, effective);
	}

	function nextTheme(theme) {
		return theme === "auto" ? "dark" : (theme === "dark" ? "light" : "auto");
	}

	function init() {
		if (!document.body || document.body.getAttribute("data-pisky-theme-ready") === "true") return;
		document.body.setAttribute("data-pisky-theme-ready", "true");
		apply(storedTheme(), false);
		document.addEventListener("click", function (event) {
			var control = event.target.closest("[data-pisky-theme-toggle]");
			if (!control) return;
			event.preventDefault();
			apply(nextTheme(storedTheme()), true);
		});
		if (media) {
			var listener = function () {
				if (storedTheme() === "auto") apply("auto", false);
			};
			if (media.addEventListener) media.addEventListener("change", listener);
			else if (media.addListener) media.addListener(listener);
		}
	}

	window.PiSkyTheme = {
		init: init,
		apply: apply
	};
	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
}());
