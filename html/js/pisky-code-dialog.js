/*
 * PiSky advanced configuration editor.
 *
 * Raw configuration files are long enough that showing one inline turns a
 * settings page into a scroll, so each is opened as a modal instead. The
 * dialog is native, which means focus containment, Escape and the backdrop all
 * behave the way the browser already does them rather than being reimplemented
 * here.
 *
 * Highlighting is a coloured copy of the text sitting behind a transparent
 * textarea, so what the host types into is still an ordinary textarea: undo,
 * selection, spellcheck, screen readers and paste all keep working. The two
 * layers must agree on every metric that affects where a glyph lands, which is
 * why the stylesheet sets font, padding and wrapping on both at once.
 *
 * Copyright (c) 2026 David Gilbert
 * SPDX-License-Identifier: MIT
 */
(function () {
	"use strict";

	function escapeHtml(text) {
		return text
			.replace(/&/g, "&amp;")
			.replace(/</g, "&lt;")
			.replace(/>/g, "&gt;");
	}

	/*
	 * Wraps every match in a span and escapes everything, matched or not. Doing
	 * it in one pass matters: escaping first would leave entities like &quot;
	 * for the patterns to trip over, and escaping afterwards would destroy the
	 * markup this just added.
	 */
	function tokenise(text, pattern, className) {
		let html = "";
		let index = 0;
		let match;
		pattern.lastIndex = 0;
		while ((match = pattern.exec(text)) !== null) {
			html += escapeHtml(text.slice(index, match.index));
			html += '<span class="' + className + '">' + escapeHtml(match[0]) + "</span>";
			index = match.index + match[0].length;
			// A zero-length match would spin here forever.
			if (match[0] === "") pattern.lastIndex += 1;
		}
		return html + escapeHtml(text.slice(index));
	}

	/*
	 * Where a comment starts on this line, or -1. A hash inside quotes is part
	 * of the value, not the start of a comment, so quoting is tracked.
	 */
	function commentStart(line) {
		let quote = "";
		for (let i = 0; i < line.length; i += 1) {
			const character = line.charAt(i);
			if (quote) {
				if (character === quote) quote = "";
			} else if (character === '"' || character === "'") {
				quote = character;
			} else if (character === "#" || character === ";") {
				return i;
			}
		}
		return -1;
	}

	const VALUE_PATTERN = /"[^"]*"|'[^']*'|\b(?:true|false|True|False|yes|no)\b|-?\d+(?:\.\d+)?/g;

	function highlightValue(text) {
		return tokenise(text, VALUE_PATTERN, "pisky-tok-value");
	}

	/*
	 * INI and ConfigObj files: section headers in brackets, key = value pairs,
	 * hash or semicolon comments. WeeWX nests sections by repeating the
	 * brackets, which the header pattern allows for.
	 */
	function highlightConfLine(line) {
		const comment = commentStart(line);
		const code = comment === -1 ? line : line.slice(0, comment);
		const trailing = comment === -1 ? "" : line.slice(comment);
		let html;

		const section = code.match(/^(\s*)(\[+[^\]]*\]+)(\s*)$/);
		const pair = section ? null : code.match(/^(\s*)([^=]+?)(\s*=\s*)([\s\S]*)$/);

		if (section) {
			html = escapeHtml(section[1])
				+ '<span class="pisky-tok-section">' + escapeHtml(section[2]) + "</span>"
				+ escapeHtml(section[3]);
		} else if (pair) {
			html = escapeHtml(pair[1])
				+ '<span class="pisky-tok-key">' + escapeHtml(pair[2]) + "</span>"
				+ '<span class="pisky-tok-op">' + escapeHtml(pair[3]) + "</span>"
				+ highlightValue(pair[4]);
		} else {
			html = escapeHtml(code);
		}

		if (trailing) html += '<span class="pisky-tok-comment">' + escapeHtml(trailing) + "</span>";
		return html;
	}

	/* Add a grammar here to give another configuration file its own colours. */
	const GRAMMARS = {
		conf: function (source) {
			return source.split("\n").map(highlightConfLine).join("\n");
		}
	};

	function paint(input, layer) {
		const grammar = GRAMMARS[input.getAttribute("data-pisky-code-grammar")] || GRAMMARS.conf;
		// The trailing newline keeps the final line rendered when the file ends
		// in one, which every configuration file written by a tool does.
		layer.innerHTML = grammar(input.value) + "\n";
		layer.scrollTop = input.scrollTop;
		layer.scrollLeft = input.scrollLeft;
	}

	function wire(dialog) {
		const input = dialog.querySelector("[data-pisky-code-input]");
		const layer = dialog.querySelector("[data-pisky-code-highlight]");
		if (!input || !layer) return;

		const original = input.value;

		// Repainted straight from the input event rather than being deferred to
		// an animation frame. Highlighting a configuration file is a handful of
		// regexes per line, far cheaper than the coalescing would save, and a
		// frame callback does not run at all in a throttled or hidden tab.
		input.addEventListener("input", function () { paint(input, layer); });
		input.addEventListener("scroll", function () {
			layer.scrollTop = input.scrollTop;
			layer.scrollLeft = input.scrollLeft;
		});

		function dirty() {
			return input.value !== original;
		}

		function leave() {
			if (dirty() && !window.confirm(
				"Close the editor and discard your changes to this configuration?"
			)) {
				return false;
			}
			input.value = original;
			paint(input, layer);
			if (dialog.open) dialog.close();
			return true;
		}

		dialog.querySelectorAll("[data-pisky-code-close]").forEach(function (button) {
			button.addEventListener("click", leave);
		});

		// Escape reaches the dialog as a cancel, which must run the same guard
		// rather than throwing the edit away silently.
		dialog.addEventListener("cancel", function (event) {
			event.preventDefault();
			leave();
		});

		dialog.piskyPaint = function () { paint(input, layer); };
		dialog.piskyFocus = function () { input.focus(); };
		paint(input, layer);
	}

	function start() {
		const dialogs = document.querySelectorAll("[data-pisky-code-dialog]");
		if (!dialogs.length) return;
		dialogs.forEach(wire);

		document.querySelectorAll("[data-pisky-code-open]").forEach(function (button) {
			const dialog = document.getElementById(button.getAttribute("data-pisky-code-open"));
			if (!dialog) return;
			button.addEventListener("click", function () {
				if (typeof dialog.showModal === "function") {
					dialog.showModal();
				} else {
					// Without modal support the dialog still opens, just inline
					// and without the backdrop. Editing a configuration file is
					// worth more than the presentation.
					dialog.setAttribute("open", "open");
					dialog.classList.add("is-inline");
				}
				// Layout is only real once the dialog is displayed, so the
				// highlight is measured and drawn after it opens.
				if (dialog.piskyPaint) dialog.piskyPaint();
				if (dialog.piskyFocus) dialog.piskyFocus();
			});
		});
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", start);
	} else {
		start();
	}
}());
