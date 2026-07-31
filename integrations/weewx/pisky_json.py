"""Write the latest WeeWX loop/archive packet for PiSky.

This interoperability module is original PiSky code. It consumes WeeWX's
documented event API and does not include WeeWX source code.
"""

import json
import os
import tempfile
import time

import weewx
from weewx.engine import StdService


class PiSkyJSON(StdService):
    """Publish an atomic, local JSON snapshot for the PiSky weather bridge."""

    def __init__(self, engine, config_dict):
        super().__init__(engine, config_dict)
        options = config_dict.get("PiSky", {})
        self.output = options.get(
            "output", "/var/lib/pisky/weather/current.json"
        )
        self.station_name = config_dict.get("Station", {}).get(
            "location", "Local WeeWX station"
        )
        self.bind(weewx.NEW_LOOP_PACKET, self.new_loop_packet)
        self.bind(weewx.NEW_ARCHIVE_RECORD, self.new_archive_record)

    def new_loop_packet(self, event):
        self.write_packet(event.packet)

    def new_archive_record(self, event):
        self.write_packet(event.record)

    def write_packet(self, packet):
        unit_system = packet.get("usUnits")
        units = self.units_for(unit_system)
        current = {
            "temperature": packet.get("outTemp"),
            "apparent_temperature": self.first(
                packet, "appTemp", "heatindex", "windchill"
            ),
            "dew_point": packet.get("dewpoint"),
            "humidity": packet.get("outHumidity"),
            "pressure": self.first(packet, "barometer", "pressure"),
            "wind_speed": packet.get("windSpeed"),
            "wind_gust": packet.get("windGust"),
            "wind_direction": packet.get("windDir"),
            "rain": self.first(packet, "rain", "rainRate"),
            "cloud_cover": packet.get("cloudcover"),
            "visibility": packet.get("visibility"),
            "uv": packet.get("UV"),
            "solar_radiation": packet.get("radiation"),
        }
        payload = {
            "station_name": self.station_name,
            "observed_at": int(packet.get("dateTime", time.time())),
            "units": units,
            "current": current,
        }
        self.atomic_write(payload)

    @staticmethod
    def first(packet, *keys):
        for key in keys:
            if packet.get(key) is not None:
                return packet[key]
        return None

    @staticmethod
    def units_for(unit_system):
        if unit_system == weewx.US:
            return {
                "temperature": "°F",
                "humidity": "%",
                "pressure": "inHg",
                "wind_speed": "mph",
                "rain": "in",
                "cloud_cover": "%",
                "visibility": "mile",
            }
        if unit_system == weewx.METRIC:
            return {
                "temperature": "°C",
                "humidity": "%",
                "pressure": "hPa",
                "wind_speed": "km/h",
                "rain": "cm",
                "cloud_cover": "%",
                "visibility": "km",
            }
        return {
            "temperature": "°C",
            "humidity": "%",
            "pressure": "hPa",
            "wind_speed": "m/s",
            "rain": "mm",
            "cloud_cover": "%",
            "visibility": "km",
        }

    def atomic_write(self, payload):
        directory = os.path.dirname(self.output)
        os.makedirs(directory, mode=0o755, exist_ok=True)
        descriptor, temporary = tempfile.mkstemp(
            prefix=".pisky-weather-", dir=directory, text=True
        )
        try:
            with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
                json.dump(payload, handle, ensure_ascii=False, indent=2)
                handle.write("\n")
                handle.flush()
                os.fsync(handle.fileno())
            os.chmod(temporary, 0o644)
            os.replace(temporary, self.output)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)
