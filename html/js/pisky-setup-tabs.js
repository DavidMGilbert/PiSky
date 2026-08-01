/*
 * PiSky Setup tabs.
 *
 * Setup covers several unrelated concerns and had grown into one long scroll.
 * Panels are grouped and shown a tab at a time.
 *
 * Panels are hidden rather than moved or detached. Several of them live inside
 * a single form, and a hidden field still submits, so switching tabs can never
 * silently drop a setting that is not on screen. That also means validation
 * errors can point at a control the host cannot currently see, so anything
 * invalid pulls its own tab forward.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */
(function () {
	"use strict";

	const STORAGE_KEY = "pisky-setup-tab";

	/*
	 * A panel may list more than one tab, separated by spaces, for something
	 * that belongs to several of them: the save bar submits fields spread
	 * across three. ~= is the attribute selector for one word in such a list.
	 */
	function panelsFor(tab) {
		return document.querySelectorAll('[data-pisky-tab-panel~="' + tab + '"]');
	}

	function show(tab, buttons, remember) {
		let matched = false;
		buttons.forEach(function (button) {
			const key = button.getAttribute("data-pisky-tab");
			const active = key === tab;
			if (active) matched = true;
			button.classList.toggle("is-active", active);
			button.setAttribute("aria-selected", active ? "true" : "false");
		});
		if (!matched) return false;

		/*
		 * Visibility is decided per panel rather than per tab. Deciding it per
		 * tab meant a panel listed under two of them was shown by one pass and
		 * hidden again by the next, so whether it appeared came down to the
		 * order of the buttons.
		 */
		document.querySelectorAll("[data-pisky-tab-panel]").forEach(function (panel) {
			const keys = (panel.getAttribute("data-pisky-tab-panel") || "").split(/\s+/);
			panel.hidden = keys.indexOf(tab) === -1;
		});
		if (remember) {
			try { window.sessionStorage.setItem(STORAGE_KEY, tab); } catch (error) { /* private mode */ }
		}
		return true;
	}

	function start() {
		const bar = document.querySelector("[data-pisky-setup-tabs]");
		if (!bar) return;
		const buttons = Array.prototype.slice.call(bar.querySelectorAll("[data-pisky-tab]"));
		if (!buttons.length) return;

		// A tab with nothing in it would be a dead end, which happens when a
		// capability is disabled and its panels are not rendered at all.
		buttons.forEach(function (button) {
			if (!panelsFor(button.getAttribute("data-pisky-tab")).length) button.hidden = true;
		});
		const usable = buttons.filter(function (button) { return !button.hidden; });
		if (!usable.length) {
			bar.hidden = true;
			return;
		}

		buttons.forEach(function (button) {
			button.addEventListener("click", function () {
				show(button.getAttribute("data-pisky-tab"), buttons, true);
			});
		});

		/*
		 * Opening tab: an explicit request, then whatever was last open this
		 * session, then the first tab.
		 *
		 * A panel can ask to be brought forward with data-pisky-tab-alert,
		 * which is reserved for a problem the host must act on. Notice classes
		 * are deliberately not used for this: .is-error also styles purely
		 * informational messages, and keying on it meant a machine without the
		 * MySQL driver always opened Setup on the History tab because of the
		 * note explaining that.
		 */
		let initial = usable[0].getAttribute("data-pisky-tab");
		const alert = document.querySelector("[data-pisky-tab-panel][data-pisky-tab-alert]");
		const fragment = (window.location.hash || "").replace("#", "");
		let stored = "";
		try { stored = window.sessionStorage.getItem(STORAGE_KEY) || ""; } catch (error) { /* private mode */ }

		// A panel may list several tabs; bringing one forward means the first.
		if (alert) initial = (alert.getAttribute("data-pisky-tab-panel") || "").split(/\s+/)[0];
		else if (fragment && panelsFor(fragment).length) initial = fragment;
		else if (stored && panelsFor(stored).length) initial = stored;
		show(initial, buttons, false);
		bar.classList.add("is-ready");
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", start);
	} else {
		start();
	}
}());
