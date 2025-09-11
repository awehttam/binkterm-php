# Weather Report Generator

This script generates configurable weather forecasts and current conditions for any set of locations worldwide.

## Features

- **Configurable Locations**: Define any cities worldwide via JSON configuration
- **Custom Report Titles**: Personalize the report title and coverage area
- **3-Day Forecast**: Detailed weather forecasts with descriptive explanations and activity recommendations
- **Enhanced Current Conditions**: Descriptive weather explanations with practical advice and safety recommendations
- **Echomail Integration**: Prepares reports for posting to echomail areas
- **Demo Mode**: Test functionality without API key or live data

## Setup

### 1. Get OpenWeatherMap API Key

1. Sign up for a free account at [OpenWeatherMap](https://openweathermap.org/api)
2. Use the free tier which includes Current Weather API and 5-day Forecast API (1,000 free calls per day)
3. Get your API key from the dashboard

### 2. Create Weather Configuration

Copy the example configuration file and customize it:

```bash
cp config/weather.json.example config/weather.json
```

Edit `config/weather.json` with your settings:

```json
{
    "title": "Pacific Northwest Weather Report",
    "coverage_area": "British Columbia & Pacific Northwest",
    "api_key": "your_openweathermap_api_key_here",
    "locations": [
        {
            "name": "Vancouver",
            "lat": 49.2827,
            "lon": -123.1207
        },
        {
            "name": "Seattle", 
            "lat": 47.6062,
            "lon": -122.3321
        }
    ],
    "settings": {
        "api_timeout": 10,
        "max_locations": 10,
        "units": "metric"
    }
}
```

**Alternative**: Set API key as environment variable (config file takes priority):
```bash
export WEATHER_API_KEY=your_openweathermap_api_key_here
```

## Usage

### Basic Commands

Generate a weather report with your configuration:
```bash
php scripts/weather_report.php
```

Test without API key using demo data:
```bash
php scripts/weather_report.php --demo
```

Use custom configuration file:
```bash
php scripts/weather_report.php --config=/path/to/custom/weather.json
```

Debug API connectivity:
```bash
php scripts/weather_report.php --debug
```

Post report to echomail area:
```bash
php scripts/weather_report.php --post --areas=WEATHER --user=admin
```

### Use in Other Scripts

```php
require_once 'scripts/weather_report.php';

$generator = new WeatherReportGenerator();

// Generate text report
$report = $generator->generateReport();

// Create netmail message structure
$netmailData = $generator->createNetmailMessage('All', '1:153/757');
```

## Configuration Options

### Required Fields

- **title**: Report title (appears in headers and email subjects)
- **coverage_area**: Geographic description of covered locations  
- **locations**: Array of location objects with name, lat, lon
- **api_key**: Your OpenWeatherMap API key (optional if set via environment)

### Optional Settings

- **api_timeout**: API request timeout in seconds (default: 10)
- **max_locations**: Maximum allowed locations (default: unlimited)
- **units**: Temperature units - "metric", "imperial", or "kelvin" (default: "metric")

### Example Configurations

**European Cities:**
```json
{
    "title": "European Weather Report",
    "coverage_area": "Major European Cities", 
    "locations": [
        {"name": "London", "lat": 51.5074, "lon": -0.1278},
        {"name": "Paris", "lat": 48.8566, "lon": 2.3522},
        {"name": "Berlin", "lat": 52.5200, "lon": 13.4050}
    ]
}
```

**Australian Cities:**
```json
{
    "title": "Australian Weather Update",
    "coverage_area": "Major Australian Cities",
    "locations": [
        {"name": "Sydney", "lat": -33.8688, "lon": 151.2093},
        {"name": "Melbourne", "lat": -37.8136, "lon": 144.9631}
    ]
}
```

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
  and rain gear recommended. Moderate winds creating breezy conditions. High
  humidity making it feel more muggy.
  Details: Feels like 17°C, Humidity 78%, Wind 15.2 km/h from SW
  Pressure 1013 hPa, Cloud cover 85%, Visibility 8.5 km

Victoria: 16°C - Overcast  
  Overcast conditions with thick cloud cover blocking most sunlight. Cloud
  cover may provide natural temperature regulation. High humidity making it
  feel more muggy.
  Details: Feels like 15°C, Humidity 82%, Wind 12.8 km/h from W
  Pressure 1011 hPa, Cloud cover 95%

3-DAY FORECAST
====================

Vancouver:
----------
Fri Sep 6: Light rain, High 19°C, Low 13°C
  Periods of light rain with occasional breaks. Keep an umbrella handy and
  dress in layers. Breezy conditions adding to the weather dynamic.
  Details: Humidity 78%, Wind 15.3 km/h

Sat Sep 7: Overcast, High 17°C, Low 12°C
  Cloudy skies dominating with limited sunshine. Stable temperatures under
  cloud cover.
  Details: Humidity 85%, Wind 12.1 km/h

Sun Sep 8: Partly cloudy, High 21°C, Low 14°C
  Mix of sun and clouds with pleasant conditions. Good day for both indoor
  and outdoor activities.
  Details: Humidity 72%, Wind 8.7 km/h


Weather data provided by OpenWeatherMap
Report generated by BinktermPHP v1.4.6
==================================================
```

## Configuration Options

The script automatically detects your system configuration. You can customize:

- API timeout (default: 10 seconds)
- Cities to include in the report
- Report format and styling
- Netmail sender information

## Error Handling

The script includes comprehensive error handling:
- Network timeouts
- API rate limiting
- Invalid API responses
- Missing configuration

If the weather API is unavailable, the script will generate an error message instead of failing silently.

## Automation

You can schedule this script to run automatically:

```bash
# Daily at 6 AM
0 6 * * * cd /path/to/binkterm-php && php scripts/weather_report.php
```

## API Limits

OpenWeatherMap's free tier includes:
- 1,000 API calls per day
- Current weather data  
- 5-day weather forecast (3-hourly data, script uses first 3 days)

This script makes two API calls per location: one for current conditions, one for forecast (6 cities = 12 calls per report).
