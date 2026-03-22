# Weather Reports

BinktermPHP can generate and post automated weather reports to echomail areas using data from the OpenWeatherMap API. Reports include current conditions and a 3-day forecast for any set of configured locations worldwide.

## Table of Contents

- [Features](#features)
- [Setup](#setup)
  - [Get an API Key](#get-an-api-key)
  - [Create the Configuration](#create-the-configuration)
- [Configuration Reference](#configuration-reference)
- [Automation via Broadcast Manager](#automation-via-broadcast-manager)
- [Running Manually](#running-manually)
- [Sample Output](#sample-output)
- [API Limits](#api-limits)

---

## Features

- Configurable locations — any cities worldwide via JSON configuration
- Custom report title and coverage area label
- Current conditions with descriptive explanations and practical advice
- 3-day forecast with activity recommendations
- Metric, imperial, or standard (Kelvin) units
- Demo mode for testing without an API key

---

## Setup

### Get an API Key

1. Sign up for a free account at [OpenWeatherMap](https://openweathermap.org/api)
2. The free tier includes the Current Weather API and 5-day Forecast API (1,000 free calls/day)
3. Copy your API key from the dashboard

### Create the Configuration

The recommended way is through the admin panel: **Admin → Community → Weather Report**.

The page will load blank if no configuration exists yet. Use the **Load Example** button to populate the form with sample values, then adjust the title, coverage area, API key, and locations to match your setup. Save when done — this writes `config/weather.json` via the admin daemon.

Alternatively, copy the example file manually and edit it:

```bash
cp config/weather.json.example config/weather.json
```

---

## Configuration Reference

`config/weather.json` structure:

```json
{
    "title": "Pacific Northwest Weather Report",
    "coverage_area": "British Columbia & Pacific Northwest",
    "api_key": "your_openweathermap_api_key_here",
    "locations": [
        {"name": "Vancouver", "lat": 49.2827, "lon": -123.1207},
        {"name": "Seattle",   "lat": 47.6062, "lon": -122.3321}
    ],
    "settings": {
        "api_timeout":   10,
        "max_locations": 10,
        "units":         "metric"
    }
}
```

### Required Fields

| Field | Description |
|---|---|
| `title` | Report title — used in the subject line when posting to echomail |
| `coverage_area` | Descriptive label for the geographic area covered |
| `api_key` | Your OpenWeatherMap API key |
| `locations` | Array of `{name, lat, lon}` objects |

### Optional Settings

| Setting | Default | Description |
|---|---|---|
| `api_timeout` | `10` | HTTP request timeout in seconds |
| `max_locations` | `10` | Maximum number of locations to include |
| `units` | `"metric"` | `"metric"` (°C), `"imperial"` (°F), or `"standard"` (K) |

### Example Location Sets

**European cities:**
```json
"locations": [
    {"name": "London",  "lat": 51.5074, "lon": -0.1278},
    {"name": "Paris",   "lat": 48.8566, "lon":  2.3522},
    {"name": "Berlin",  "lat": 52.5200, "lon": 13.4050}
]
```

**Australian cities:**
```json
"locations": [
    {"name": "Sydney",    "lat": -33.8688, "lon": 151.2093},
    {"name": "Melbourne", "lat": -37.8136, "lon": 144.9631}
]
```

---

## Automation via Broadcast Manager

The recommended way to post weather reports automatically is through the Broadcast Manager (**Admin → Ad Campaigns**).

1. Click **New Campaign** and choose the **Weather Report** preset from the Quick Setup wizard
2. The preset pre-configures a daily schedule at 3:00 AM and sets the content command to `scripts/weather_report.php`
3. Select the target echomail area and save

> **Note:** The Weather Report preset is greyed out if `config/weather.json` does not exist. Configure it first under **Admin → Community → Weather Report**.

---

## Running Manually

Print a weather report to stdout:
```bash
php scripts/weather_report.php
```

Test without an API key using built-in demo data:
```bash
php scripts/weather_report.php --demo
```

Use a custom configuration file:
```bash
php scripts/weather_report.php --config=/path/to/weather.json
```

Debug API connectivity (prints raw responses, exits 1 on failure):
```bash
php scripts/weather_report.php --debug
```

Post directly to an echomail area:
```bash
php scripts/weather_report.php --post --areas=WEATHER --user=admin
```

Show full usage information:
```bash
php scripts/weather_report.php --help
```

Exit codes: `0` on success, `1` on any error.

---

## Sample Output

```
PACIFIC NORTHWEST WEATHER REPORT
==================================================
Generated: Friday, September 6, 2025 at 14:30 PDT
Coverage: British Columbia & Pacific Northwest

CURRENT CONDITIONS
====================

Vancouver: 18°C - Light rain
  Light rain falling with gentle precipitation. Wet conditions - umbrellas
  and rain gear recommended. Moderate winds creating breezy conditions.
  Details: Feels like 17°C, Humidity 78%, Wind 15.2 km/h from SW
  Pressure 1013 hPa, Cloud cover 85%, Visibility 8.5 km

Victoria: 16°C - Overcast
  Overcast conditions with thick cloud cover blocking most sunlight.
  Details: Feels like 15°C, Humidity 82%, Wind 12.8 km/h from W

3-DAY FORECAST
====================

Vancouver:
----------
Fri Sep 6: Light rain, High 19°C, Low 13°C
  Periods of light rain with occasional breaks. Keep an umbrella handy.
  Details: Humidity 78%, Wind 15.3 km/h

Sat Sep 7: Overcast, High 17°C, Low 12°C
  Cloudy skies with limited sunshine.
  Details: Humidity 85%, Wind 12.1 km/h

Sun Sep 8: Partly cloudy, High 21°C, Low 14°C
  Mix of sun and clouds with pleasant conditions.
  Details: Humidity 72%, Wind 8.7 km/h


Weather data provided by OpenWeatherMap
Report generated by BinktermPHP v1.8.8
==================================================
```

---

## API Limits

OpenWeatherMap's free tier provides:
- 1,000 API calls per day
- Current weather data
- 5-day forecast (3-hourly; the script uses the first 3 days)

The script makes **two API calls per location** (one for current conditions, one for forecast). Six locations = 12 calls per report run.
