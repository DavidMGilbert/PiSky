# PiSky

PiSky is a modular platform for sharing observations of the sky. A station can
run Camera, Weather or locally decoded Aircraft tracking independently, or in
any combination. It adds a liquid-glass administration dashboard, capability-
aware public pages, normalized local data endpoints, editable host content and
an extension path for future ESP32 sensor nodes.

Project lead and PiSky interface: **David Gilbert** —
[davidmgilbert.com](https://davidmgilbert.com)

> Development status: Bookworm installation alpha. Test on a non-critical,
> backed-up SD card before using it on a production camera. See
> [INSTALL-PISKY.md](INSTALL-PISKY.md).

## What PiSky adds

- A modern admin shell and live-observation workspace.
- A unified Raspberry Pi OS Bookworm installer at `install-pisky.sh`.
- A browser setup workspace at `/admin/?page=pisky_setup` with validated
  configuration, component health and allow-listed service controls.
- A public Overview landing page summarising every enabled module, plus
  separate Sky, Weather, Aircraft, Archive and About pages that appear only
  when their capabilities are enabled.
- An Archive of finished Allsky timelapses alongside searchable recorded
  weather. PiSky writes its own daily rollup so a station archives the readings
  it actually measured; dates from before PiSky was installed are filled from
  Open-Meteo's historical service and labelled as reanalysis rather than
  presented as station measurements.
- Animated weather icons drawn to match the interface, keyed to WMO codes and
  day or night, which stop moving under `prefers-reduced-motion`.
- Interactive charts of a day's recorded readings, drawn on a canvas with no
  external plotting library so the appliance stays self-contained.
- An optional remote history database for the intraday detail behind each
  daily summary, keeping repeated writes off the SD card. The daily record is
  always written locally, so an unreachable database costs detail rather than
  data.
- A read-only public data API at `/api/v1/` for embedding station data in other
  sites. It publishes nothing beyond the visitor pages: readings hidden in the
  administration interface are absent from it, and it has no write surface.
  Access is open because the data is already public; API keys exist only to
  raise rate limits.
- An editable station profile, equipment details, public introductions and
  photo gallery managed without SSH.
- A branded, session-authenticated administration entry at `/admin/`.
- Station-time-aware visitor language for this morning, today, this evening
  and tonight.
- A weather workspace at `/admin/?page=weather`.
- `pisky-weather.php`, a read-only normalized JSON endpoint shared by both
  interfaces.
- Open-Meteo current conditions, sunrise/sunset context and seven-day forecast
  support without an API key.
- WeeWX interoperability through a local JSON file or an HTTP(S) JSON URL.
- An optional isolated WeeWX 5.x install and original PiSky event service that
  publishes live observations to local JSON.
- A guided WeeWX station wizard with curated Ecowitt, Weather Underground
  client and ObserverIP presets, live-data confirmation and safe service
  activation.
- Five-minute Open-Meteo caching and ten-second WeeWX caching by default, with
  stale-data fallback when a provider is temporarily unavailable.
- Local aircraft tracking from an RTL-SDR or Mode-S Beast GPS receiver using
  PiSky-managed dump1090-mutability and beast-splitter services, while
  remaining compatible with dump1090-fa, readsb and standard `aircraft.json`.
- An animated radar scope drawing locally received targets over an
  OpenStreetMap basemap, with altitude-coloured aircraft, track history,
  range rings and receiver metrics.
- Clickable aircraft details using locally decoded registration/type/operator
  fields, with optional outbound lookups for licensed hosted information.
- A configurable geographic coverage map with an independent coordinate
  override, which also positions the public radar. Disabling it withholds the
  station coordinates and reverts the scope to range-and-bearing plotting.
- Automatic display of additional WeeWX observations such as solar radiation,
  UV, rainfall, lightning, soil moisture and air-quality fields when present.

## Weather configuration

Installation creates `config/pisky-weather.json` without overwriting an
existing file. Open-Meteo is the default provider and uses the latitude and
longitude already configured in Allsky when its own coordinates are blank.

```json
{
  "enabled": true,
  "provider": "open-meteo",
  "display_units": "metric",
  "open_meteo": {
    "latitude": "",
    "longitude": "",
    "timezone": "auto",
    "cache_seconds": 300
  },
  "weewx": {
    "file": "/var/lib/pisky/weather/current.json",
    "url": "",
    "cache_seconds": 10
  }
}
```

Set `provider` to `weewx` to use a local station. Prefer the local-file option
when PiSky and WeeWX are on the same machine or trusted LAN. PiSky accepts both
its normalized keys and common WeeWX field names. Set `display_units` to
`metric` or `imperial`; PiSky converts the observations and forecast values
before serving the same units to both the visitor and administration
interfaces.

For supported network stations, PiSky Setup installs and configures a pinned
revision of the GPL-3.0 WeeWX Interceptor extension. Ecowitt is the recommended
preset for GW-series gateways and compatible Wi-Fi consoles configured to use
their custom Ecowitt upload service. The wizard also supports Weather
Underground-compatible clients and ObserverIP/Ambient/Fine Offset bridges.
Each preset uses an unprivileged local listener port, backs up `weewx.conf`,
starts the restricted WeeWX service if requested, and reports whether PiSky
has received a recent observation.

### Recommended WeeWX JSON shape

```json
{
  "station_name": "Backyard observatory",
  "observed_at": "2026-07-28T19:42:00+10:00",
  "units": {
    "temperature": "°C",
    "humidity": "%",
    "pressure": "hPa",
    "wind_speed": "km/h",
    "rain": "mm"
  },
  "current": {
    "temperature": 14.8,
    "apparent_temperature": 13.9,
    "dew_point": 9.7,
    "humidity": 72,
    "pressure": 1018.4,
    "wind_speed": 7.2,
    "wind_gust": 11.4,
    "wind_direction": 238,
    "rain": 0,
    "cloud_cover": 18,
    "visibility": 22,
    "condition": "Mostly clear"
  }
}
```

The same document may be written to the configured file by a WeeWX extension
or served from a trusted URL. The unified installer can install WeeWX from
PyPI and enables PiSky's original interoperability service in
`integrations/weewx/pisky_json.py`. A ready-to-copy example is included at
`config_repo/weewx-current.example.json`. WeeWX remains a separately licensed
external dependency.

## Provider and licensing notes

Open-Meteo's free service is intended for non-commercial use and has usage
limits. Check its current terms before operating a high-traffic or commercial
public installation. The interface displays the required Open-Meteo
attribution whenever that provider supplies the data.

PiSky retains the existing Allsky license, file headers, history, and upstream
credit. See `NOTICE.md` for the full attribution model.

## Local ADS-B flight tracking

The primary flight source is always the receiver attached to the host Pi.
PiSky does not need FlightAware, Flightradar24, an external flight API, or an
internet connection to display locally received aircraft.

1. Connect an RTL-SDR, Mode-S Beast GPS, or compatible receiver.
2. Use PiSky Setup to select and configure the receiver without editing service
   files or opening a shell.
3. Use the unified installer's Bookworm
   [`dump1090-mutability`](https://packages.debian.org/bookworm/dump1090-mutability)
   package and pinned
   [`beast-splitter`](https://github.com/flightaware/beast-splitter) build, or
   install
   [dump1090-fa](https://github.com/flightaware/dump1090) or
   [readsb](https://github.com/wiedehopf/readsb).
4. Confirm the decoder is updating `aircraft.json`. PiSky automatically checks
   the common dump1090-mutability, dump1090-fa, readsb, and dump1090 paths
   under `/run` and `/var/run`.
5. Open `/admin/?page=flights` to verify receiver status, range, targets, and
   message counts.

The default configuration is installed at `config/pisky-flights.json`:

```json
{
  "enabled": true,
  "decoder": "Local ADS-B receiver",
  "aircraft_file": "/run/dump1090-mutability/aircraft.json",
  "receiver_file": "",
  "aircraft_url": "",
  "latitude": "",
  "longitude": "",
  "range_km": 160,
  "max_aircraft": 60,
  "max_seen_seconds": 15,
  "receiver": {
    "type": "rtl-sdr",
    "rtl_sdr": {"device": "0", "gain": "max", "ppm": 0},
    "beast": {
      "serial_device": "/dev/beast",
      "baud": "auto",
      "output_format": "radarcape-gps"
    }
  },
  "decoder_options": {
    "fix_crc": true,
    "max_range_nm": 300,
    "json_interval_seconds": 1,
    "location_accuracy": "none"
  },
  "network": {
    "bind_address": "127.0.0.1",
    "raw_input_port": 30001,
    "raw_output_port": 30002,
    "sbs_output_port": 30003,
    "beast_input_port": 30004,
    "beast_output_port": 30005
  },
  "coverage_map": {
    "enabled": true,
    "zoom": 8,
    "public": false,
    "latitude": "",
    "longitude": ""
  },
  "sharing": {
    "flightaware": {
      "enabled": false,
      "site_id": ""
    },
    "flightradar24": {
      "enabled": false,
      "radar_id": ""
    }
  }
}
```

When receiver coordinates are absent, PiSky uses the camera latitude and
longitude. `aircraft_url` can point to a trusted LAN decoder endpoint instead
of a local file. A small test fixture is included at
`config_repo/aircraft.example.json`.

The public Aircraft page draws locally received targets on a radar scope over
an OpenStreetMap basemap. Placing targets against a map requires the station
position, so when the coverage map is enabled the receiver latitude and
longitude are included in the public flight JSON and are therefore visible to
anyone who can reach the site. Turn the coverage map off in PiSky Setup to
withhold them; the scope then falls back to plain range-and-bearing plotting,
which needs no coordinates. Platform station identifiers for FlightAware and
Flightradar24 are never published.

### Optional sharing services

PiAware and fr24feed can both consume the same local decoder stream, normally
in Beast format on port 30005. PiSky only shows the traffic decoded locally; it
does not call either company's hosted flight-data APIs.

- Install and claim PiAware using
  [FlightAware's current instructions](https://www.flightaware.com/adsb/piaware/install).
- Install fr24feed using
  [Flightradar24's current data-sharing instructions](https://www.flightradar24.com/build-your-own).
- Set the matching `sharing` flag only after the external upload client is
  working. PiSky Setup can display and control an already installed client's
  service, but it does not install the client or create its account.

Sharing locally received data can qualify a host for platform benefits under
the provider's current rules. FlightAware currently offers qualifying PiAware
contributors an Enterprise account upgrade, while Flightradar24 offers active
receivers its Contributor plan. These benefits are provided and controlled by
the respective platforms, not PiSky.

Flightradar24 currently instructs hosts who share with other networks to
disable its MLAT options. Review the service's latest guidance before enabling
multiple sharing destinations. Accounts, sharing keys, upload-client updates,
MLAT, and each provider's terms remain outside PiSky.
