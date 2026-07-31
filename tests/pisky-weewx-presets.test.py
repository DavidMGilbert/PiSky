#!/usr/bin/env python3
"""Validate PiSky's curated WeeWX station presets."""

import importlib.util
from pathlib import Path
import tempfile

from configobj import ConfigObj


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "integrations" / "weewx" / "configure_station.py"
SPEC = importlib.util.spec_from_file_location("pisky_configure_station", MODULE_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


EXPECTED = {
    "ecowitt": "ecowitt-client",
    "wu-client": "wu-client",
    "observer": "observer",
}


with tempfile.TemporaryDirectory() as temporary:
    path = Path(temporary) / "weewx.conf"
    path.write_text(
        "[Station]\n"
        "    station_type = Simulator\n"
        "\n"
        "[Simulator]\n"
        "    driver = weewx.drivers.simulator\n"
        "\n"
        "[Engine]\n"
        "    [[Services]]\n"
        "        data_services = user.pisky_json.PiSkyJSON\n",
        encoding="utf-8",
    )

    for preset, device_type in EXPECTED.items():
        config = ConfigObj(str(path), encoding="utf-8")
        MODULE.apply_preset(config, preset, 8123, "Back garden station")

        assert config["Station"]["station_type"] == "Interceptor"
        assert config["Interceptor"]["driver"] == "user.interceptor"
        assert config["Interceptor"]["device_type"] == device_type
        assert config["Interceptor"]["mode"] == "listen"
        assert config["Interceptor"]["address"] == ""
        assert config["Interceptor"]["port"] == "8123"
        assert config["Interceptor"]["hardware_name"] == "Back garden station"
        assert (
            config["Engine"]["Services"]["data_services"]
            == "user.pisky_json.PiSkyJSON"
        )

print("PiSky curated WeeWX station presets passed.")
