# PiSky

**Turn a Raspberry Pi into a shareable local view of the sky.**

PiSky is a modular sky-observation platform. It runs three independent
capabilities — an all-sky **Camera**, live **Weather**, and locally decoded
**Aircraft** tracking — and a host can enable any one of them, or any
combination. Everything is managed from a browser; nothing routine requires
SSH.

Every station gets a public page for visitors and a private control interface
for its owner.

- **Docs:** [wiki.pisky.space](https://wiki.pisky.space)
- **Community and support:** [pisky.space/community](https://pisky.space/community)
- **Project home:** [pisky.space](https://pisky.space)

---

## What a PiSky station does

**Sky camera.** Continuous all-sky capture with nightly timelapses, keograms
and startrails, presented in a searchable archive.

**Weather.** Live conditions from Open-Meteo with no API key, or from a local
WeeWX station. PiSky reads both, prefers your own sensors, and quietly fills
what they cannot measure — cloud cover, visibility, UV, air quality — from
Open-Meteo, labelling what it borrowed. Guided setup presets cover Ecowitt,
Weather Underground clients and ObserverIP bridges.

**Aircraft.** Aircraft decoded by a receiver attached to your own Pi, shown on
an animated radar scope over a map, with altitude colouring, track history and
range rings. No external flight API and no internet connection required.

**Archive and history.** PiSky records a daily weather summary locally. Connect
an optional database and it also keeps the intraday detail, which the archive
draws as interactive charts. Dates from before you installed PiSky are filled
from Open-Meteo's historical service and clearly labelled as reanalysis rather
than passed off as your own readings.

**Public data API.** A read-only API at `/api/v1/` so other sites can embed your
station's data. It publishes nothing beyond your visitor pages — readings you
hide stay hidden — and has no write surface at all.

**Your station, your choices.** Every weather reading has a switch controlling
whether visitors see it. Station coordinates are withheld unless you publish
them. Page wording, equipment details and photos are all editable in the
browser.

---

## Requirements

| | |
|---|---|
| **Hardware** | Raspberry Pi 4 or 5 |
| **OS** | Raspberry Pi OS **Bookworm**, 64-bit |
| **Storage** | 32 GB or larger, good quality |
| **Camera** *(optional)* | An Allsky-supported ZWO or Raspberry Pi camera |
| **Weather** *(optional)* | Any station that can post to WeeWX, or none — Open-Meteo needs no hardware |
| **Aircraft** *(optional)* | RTL-SDR, or a Mode-S Beast serial receiver, plus a 1090 MHz antenna |

Debian 13 (Trixie) is not supported yet.

---

## Install

```bash
git clone https://github.com/DavidMGilbert/PiSky.git
cd PiSky
./install-pisky.sh
```

The installer asks which capabilities you want, installs only what those need,
and writes its own privileged helper so the rest can be managed from a browser.

Install specific capabilities without being asked:

```bash
./install-pisky.sh --with-weather --with-adsb --without-camera
```

See what it would do, changing nothing:

```bash
./install-pisky.sh --dry-run
```

When it finishes:

- **`http://<your-pi>/`** — the public observatory
- **`http://<your-pi>/admin/`** — the control interface

Open **PiSky Setup** first to set your location, choose a weather provider and
configure a receiver. Full instructions are in
[INSTALL-PISKY.md](INSTALL-PISKY.md).

---

## Upgrading

```bash
cd PiSky && git pull && ./install-pisky.sh
```

Re-running the installer is safe. It preserves your configuration, your public
content and your enabled capabilities.

> The installer also refreshes PiSky's privileged helper. Pulling new code
> **without** re-running it leaves that helper behind, and the interface will
> tell you so rather than failing obscurely.

---

## Documentation

| Document | Covers |
|---|---|
| [INSTALL-PISKY.md](INSTALL-PISKY.md) | Full installation, receivers, weather stations, troubleshooting |
| [PISKY.md](PISKY.md) | Feature detail, configuration files, data endpoints |
| [NOTICE.md](NOTICE.md) | Upstream authorship, licensing and attribution |

---

## Status

**0.1.0 — first public release.**

PiSky is usable and installable today. It is an early release: it has been
built and tested against Raspberry Pi OS Bookworm on Pi 4 hardware, and wider
validation across camera models, weather stations and receivers is ongoing.

Install it on a machine you can reformat rather than a production observatory,
and please report what you find at
[pisky.space/community](https://pisky.space/community).

---

## Licence and attribution

PiSky is released under the MIT Licence — see [LICENSE](LICENSE).

PiSky builds on the [Allsky camera project](https://github.com/AllskyTeam/allsky)
and includes GPL-3.0 code derived from RaspAP. Those licences, copyright
notices and file headers are retained, and the combined work carries their
obligations. [NOTICE.md](NOTICE.md) sets out the full attribution model.

Weather data from [Open-Meteo](https://open-meteo.com/) is used under CC BY 4.0.
Map tiles are © [OpenStreetMap](https://www.openstreetmap.org/copyright)
contributors. [WeeWX](https://weewx.com/) is an optional, separately licensed
dependency that PiSky interoperates with but does not include.

PiSky is led by **David Gilbert** — [davidmgilbert.com](https://davidmgilbert.com)
