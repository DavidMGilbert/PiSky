# PiSky notices and attribution

PiSky is an open-source interface and integration layer led by David Gilbert.

- PiSky design, weather integration, and project direction: Copyright © 2026
  David Gilbert, [davidmgilbert.com](https://davidmgilbert.com).
- PiSky is based on the
  [Allsky camera project](https://github.com/AllskyTeam/allsky). Allsky
  authorship, copyright notices, file headers and license terms remain in
  place. PiSky does not claim authorship of the underlying Allsky capture
  system. This repository begins at PiSky's first public release rather than
  carrying Allsky's commit history; that history remains available in the
  upstream project linked above.
- The Allsky repository carries the MIT License in `LICENSE`, including the
  original Copyright © 2016 Thomas Jacquin notice. Individual files can carry
  additional or different terms. In particular, the WebUI identifies
  GPL-3.0-licensed code derived from RaspAP; those headers and obligations are
  retained.
- [WeeWX](https://weewx.com/) is an optional external weather-station
  provider. The unified installer can download WeeWX from its Python package;
  PiSky does not vendor or modify WeeWX source. PiSky's JSON event service is
  original interoperability code. WeeWX is Copyright © 2009–2026 Thomas
  Keffer, Matthew Wall, and Gary Roderick and is distributed under GPL-3.0.
- The guided network-station presets install a pinned revision of Matthew
  Wall's separately maintained
  [`weewx-interceptor`](https://github.com/matthewwall/weewx-interceptor)
  extension. Its source is downloaded at installation time, is not vendored
  into PiSky, and remains distributed under GPL-3.0.
- [Open-Meteo](https://open-meteo.com/) is an optional hosted weather-data
  provider. Weather data must be attributed to Open-Meteo and its upstream
  providers under CC BY 4.0. Use of the free API is also subject to
  Open-Meteo's current API terms and limits.
- PiSky can install Debian's separately packaged `dump1090-mutability` or read
  the local JSON output of dump1090-fa and readsb. PiSky does not vendor those
  decoders. PiAware is a FlightAware project distributed under the
  BSD-2-Clause license; other receiver software remains subject to its own
  license.
- For Mode-S Beast receivers, the installer builds a pinned revision of
  FlightAware's separately maintained
  [`beast-splitter`](https://github.com/flightaware/beast-splitter). Its source
  is not vendored into PiSky and remains licensed under BSD-2-Clause.
- FlightAware and Flightradar24 are optional external data-sharing
  destinations. PiSky does not access, reproduce, or resell either service's
  global flight data, and does not bundle their upload clients.

The PiSky name and branding are project identifiers only. They do not imply
endorsement by the Allsky or WeeWX maintainers, FlightAware, or Flightradar24.
