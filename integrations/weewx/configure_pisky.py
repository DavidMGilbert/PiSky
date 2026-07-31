#!/usr/bin/env python3
"""Idempotently enable the PiSky JSON service in a WeeWX configuration."""

import argparse

from configobj import ConfigObj


SERVICE = "user.pisky_json.PiSkyJSON"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    config = ConfigObj(args.config, encoding="utf-8")
    engine = config.setdefault("Engine", {})
    services = engine.setdefault("Services", {})
    current = services.get("data_services", [])
    if isinstance(current, str):
        current = [item.strip() for item in current.split(",") if item.strip()]
    if SERVICE not in current:
        current.append(SERVICE)
    services["data_services"] = current
    pisky = config.setdefault("PiSky", {})
    pisky["output"] = args.output
    config.write()


if __name__ == "__main__":
    main()
