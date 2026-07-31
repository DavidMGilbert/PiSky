#!/usr/bin/env python3
"""Apply a curated PiSky weather-station preset to a WeeWX configuration."""

import argparse

from configobj import ConfigObj


PRESETS = {
    "ecowitt": {
        "device_type": "ecowitt-client",
        "default_name": "Ecowitt weather station",
    },
    "wu-client": {
        "device_type": "wu-client",
        "default_name": "Weather Underground compatible station",
    },
    "observer": {
        "device_type": "observer",
        "default_name": "ObserverIP compatible station",
    },
}


def apply_preset(config, preset, port, hardware_name):
    """Update only the fields managed by the PiSky station wizard."""
    details = PRESETS[preset]
    station = config.setdefault("Station", {})
    station["station_type"] = "Interceptor"

    interceptor = config.setdefault("Interceptor", {})
    interceptor["driver"] = "user.interceptor"
    interceptor["device_type"] = details["device_type"]
    interceptor["mode"] = "listen"
    interceptor["address"] = ""
    interceptor["port"] = str(port)
    interceptor["hardware_name"] = hardware_name or details["default_name"]

    # A PiSky preset always uses the unprivileged HTTP listener. Remove sniffing
    # options that may remain after manual experimentation.
    interceptor.pop("iface", None)
    interceptor.pop("pcap_filter", None)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", required=True)
    parser.add_argument("--preset", choices=sorted(PRESETS), required=True)
    parser.add_argument("--port", type=int, required=True)
    parser.add_argument("--hardware-name", default="")
    args = parser.parse_args()

    if not 1024 <= args.port <= 65535:
        parser.error("port must be between 1024 and 65535")

    config = ConfigObj(args.config, encoding="utf-8")
    apply_preset(
        config,
        preset=args.preset,
        port=args.port,
        hardware_name=args.hardware_name,
    )
    config.write()


if __name__ == "__main__":
    main()
