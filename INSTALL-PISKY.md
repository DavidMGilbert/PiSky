# Installing PiSky 0.1 alpha

PiSky's first installation target is a clean Raspberry Pi OS Bookworm 64-bit
system on a Raspberry Pi 4 or 5. Raspberry Pi OS Bookworm is now shown as a
legacy release in Raspberry Pi Imager because the current Raspberry Pi OS is
newer. Do not use Debian 13/Trixie for this alpha.

Use a disposable or backed-up SD card. This release is intended for hardware
validation before production use.

## Hardware preparation

- Raspberry Pi 4B with 4 GB RAM or better.
- Official-quality 5 V power supply.
- At least 16 GB storage; high-endurance media or an SSD is recommended.
- An Allsky-supported camera, when the Camera capability is wanted.
- Optional weather station supported by WeeWX.
- Optional RTL-SDR, Mode-S Beast GPS, or compatible 1090 MHz ADS-B receiver.
- A powered USB hub when the camera and receivers exceed the Pi's reliable USB
  power budget.

In Raspberry Pi Imager, choose **Raspberry Pi OS (Legacy, 64-bit) Bookworm**
with the desktop environment. Configure the hostname, user, Wi-Fi, locale and
SSH in Imager if required for the initial bootstrap.

## Unified installation

Open a terminal on the Pi and run:

```bash
sudo apt update
sudo apt install -y git
git clone https://github.com/DavidMGilbert/PiSky.git
cd PiSky
./install-pisky.sh
```

Run the script as the normal Pi user, not with `sudo`. It requests elevated
permission only for package installation and tightly scoped system changes.

The default `--full` profile enables all current capabilities and installs:

1. The inherited interactive Allsky camera system.
2. The PiSky WebUI, validated configuration helper and admin setup page.
3. WeeWX 5.x in an isolated Python environment.
4. PiSky's original WeeWX-to-JSON interoperability service.
5. A pinned build of the WeeWX Interceptor driver for the guided Ecowitt,
   Weather Underground client and ObserverIP station presets.
6. Debian Bookworm's `dump1090-mutability` local ADS-B decoder.
7. A pinned build of FlightAware's open-source `beast-splitter` for Mode-S
   Beast and compatible serial receivers.

When the inherited Allsky stage asks whether to reboot immediately, choose
**No**. This lets the unified installer finish PiSky core, WeeWX and ADS-B
before one final reboot. If Allsky has already rebooted the Pi, simply return
to the PiSky folder and run `./install-pisky.sh` again. PiSky detects the
current Allsky completion files and continues with the remaining stages
without asking for a full Allsky reinstall.

Capabilities are independent. Useful installation profiles:

```bash
# Camera only
./install-pisky.sh --minimal

# Weather-only appliance using free Open-Meteo
./install-pisky.sh --without-camera --with-weather --without-weewx --without-adsb

# Local aircraft receiver plus weather, without a camera or WeeWX
./install-pisky.sh --without-camera --with-weather --without-weewx --with-adsb

# Camera and local ADS-B, without public weather
./install-pisky.sh --without-weather --with-adsb

# Add local ADS-B while keeping Open-Meteo but not WeeWX
./install-pisky.sh --without-weewx --with-adsb

# Finish PiSky components after Allsky was installed separately
./install-pisky.sh --skip-allsky

# Inspect the selected installation without changing the Pi
./install-pisky.sh --dry-run
```

When Camera is disabled on a fresh installation, PiSky installs its own
camera-free web foundation and does not run camera detection or the inherited
Allsky installer. The administration and visitor interfaces then open on the
first enabled observation capability.

PiAware and `fr24feed` are deliberately not installed or enabled. They remain
optional outbound sharing destinations and are never PiSky flight-data
sources.

## Browser configuration

After installation, open:

```text
http://PI_ADDRESS/admin/?page=pisky_setup
```

The public observatory is the device root at `http://PI_ADDRESS/`. The
administration interface is available only under `/admin/` and always requires
a login. On a completely fresh Allsky configuration, the inherited initial
credentials are username `admin` and password `secret`; change them
immediately from **Change Password**.

Use **PiSky Setup** to configure:

- shared station coordinates and timezone;
- Open-Meteo or local/remote WeeWX weather;
- Metric or Imperial weather display units across both interfaces;
- guided Ecowitt, Weather Underground client and ObserverIP weather stations;
- RTL-SDR device index/serial, gain and PPM correction;
- Mode-S Beast serial path, baud rate and GPS/Radarcape stream format;
- local decoder paths, ports, network visibility, range and freshness;
- the admin coverage map zoom and its separate public-visibility consent;
- FlightAware and Flightradar24 sharing indicators;
- component start, stop, restart and boot state;
- the complete local `weewx.conf` when advanced station settings are needed.

Use **Public Content** to enable or hide the Camera capability, edit visitor
page introductions and the About page, record equipment models and mounting
heights, and upload station photos. Weather and Aircraft visibility follows
their enabled settings in PiSky Setup. Disabled capabilities disappear from
both public and relevant administration navigation.

Open-Meteo is active by default. The optional WeeWX engine is created with a
simulator baseline but remains disabled so simulated values cannot appear as
real weather. The installer also adds a pinned Interceptor driver without
activating it. Choose a station preset in the browser to replace the simulator
configuration, start WeeWX and optionally switch PiSky's live conditions to
the local station.

### Guided Ecowitt setup

1. Open **PiSky Setup → Connect your weather station**.
2. Choose **Ecowitt / Fine Offset custom server**.
3. Leave port `8000` unless it conflicts with another local service.
4. Keep **Start WeeWX now and at boot** selected.
5. Select **Use this station for PiSky's live weather conditions** and save.
6. In WS View Plus or the Ecowitt app, open the device's customised weather
   service settings. Select the **Ecowitt** protocol, enter the Pi's local IP
   address or hostname, use the same port, path `/`, and an upload interval of
   approximately 16 seconds.
7. Return to PiSky and select **Check for live weather data**.

The Weather Underground client and ObserverIP presets use the same workflow
with their matching protocol. Arbitrary extension URLs are not accepted by the
WebUI; unsupported or specialist drivers remain an advanced/manual task.

The Weather and Aircraft browser clients always use the root appliance
endpoints at `/pisky-weather.php` and `/pisky-flights.php`, including when the
current page is below `/admin/`. Opening either endpoint directly is also a
quick diagnostic: it returns normalized JSON when its source is working and a
specific error when the source file or service is unavailable.

The ADS-B decoder is installed and enabled with RTL-SDR selected initially. It
begins producing local `aircraft.json` data when a compatible RTL-SDR and
antenna are attached. Select **Mode-S Beast or compatible serial receiver** in
PiSky Setup to switch the decoder to network-only input and configure
`beast-splitter`; saving applies both services without editing `/etc/default`
files. PiSky auto-detects the Bookworm `dump1090-mutability` runtime directory
as well as common `dump1090-fa` and `readsb` locations.

Leave the stream format on **Beast Classic** unless the receiver genuinely is a
Radarcape. The Radarcape modes request hardware features a classic Mode-S Beast
cannot provide, and the receiver then delivers no data at all.

The `/dev/beast` device node is created by a udev rule that only matches
receivers reporting themselves as a Mode-S Beast. If `ls -l /dev/beast` shows
nothing, set the serial device to the real port instead, usually
`/dev/ttyUSB0`. PiSky grants the `beast` service account access to those ports;
on installations created before that was added, saving PiSky Setup repairs the
group membership.

## Time-aware visitor interface

The visitor interface uses the Pi's configured timezone rather than the
visitor's device timezone. Its language updates automatically between:

- **This morning** from 05:00 to 11:59;
- **Today** from 12:00 to 16:59;
- **This evening** from 17:00 to 20:59;
- **Tonight** from 21:00 to 04:59.

The headline, introduction, live-capture label, condition label and
air-traffic heading update independently from the visual light/dark styling.
The page re-evaluates the station time every minute without a reload.

## First-hardware acceptance check

After a reboot:

1. Open `/admin/`, sign in, change the default password if necessary, then
   confirm Allsky is running in PiSky Setup.
2. Save the observatory coordinates and keep Open-Meteo selected when Weather
   is enabled.
3. Open Weather and confirm current conditions appear when enabled.
4. Open Live View and confirm the camera is capturing when enabled.
5. When Aircraft is enabled, select RTL-SDR or a Mode-S Beast serial receiver in
   PiSky Setup, attach the receiver, save, and confirm Air Traffic begins showing
   locally received aircraft.
6. If using a weather station, select its guided preset, configure the station
   to send to the displayed listener port, and confirm a recent observation.
7. Confirm `/` contains no administrative controls or exact receiver
   coordinates, and `/admin/` requires a login.
8. Confirm disabled capabilities are absent from public navigation.
9. Reboot once more and verify all enabled components recover automatically.

## Security and recovery

PiSky configuration is written through `/usr/local/sbin/piskyctl`. The helper
accepts only known configuration targets, validates JSON, limits service names
and operations, and creates timestamped backups under
`/var/lib/pisky/backups`.

The WebUI does not receive a general-purpose shell. Weather setup is limited
to the curated, pinned station presets; it cannot install arbitrary extension
URLs. It also cannot install FlightAware or Flightradar24 clients, run
arbitrary commands, or control unlisted services through the PiSky helper.

For this alpha, flashing the operating system, cloning PiSky, third-party
account creation, exceptional driver installation and disaster recovery can
still require local terminal or SSH access.

## Recovering an installation that stopped after Allsky

The earlier alpha incorrectly looked for Allsky's retired
`config/config.sh` marker. If that version repeatedly offers a full Allsky
reinstall, do not reinstall Allsky. Update PiSky and rerun the wrapper:

```bash
cd ~/PiSky
git fetch origin
git switch agent/bookworm-installer
git pull
./install-pisky.sh
```

The corrected detector recognises `config/settings.json`,
`config/options.json`, the Allsky service and completed/reboot-pending status
states. It then installs the missing PiSky control helper, WeeWX and ADS-B
components.
