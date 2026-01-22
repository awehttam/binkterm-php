#!/usr/bin/php
<?php

// Include composer autoloader if available
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Manual includes for BinktermPHP classes
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/MessageHandler.php';
require_once __DIR__ . '/../src/Version.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use BinktermPHP\Version;

/**
 * Weather Report Generator
 * 
 * Generates weather forecasts and current conditions for configurable locations.
 * Configuration is loaded from config/weather.json
 */
class WeatherReportGenerator 
{
    private $config;
    private $apiKey;
    private $locations;
    private $title;
    private $coverageArea;
    private $settings;
    
    public function __construct($configPath = null) 
    {
        $this->loadConfiguration($configPath);
    }
    
    /**
     * Load configuration from JSON file
     */
    private function loadConfiguration($configPath = null): void
    {
        if ($configPath === null) {
            $configPath = __DIR__ . '/../config/weather.json';
        }
        
        // Check if config file exists
        if (!file_exists($configPath)) {
            // Fall back to example file for reference
            $examplePath = __DIR__ . '/../config/weather.json.example';
            if (file_exists($examplePath)) {
                throw new \Exception("Weather configuration not found. Please copy {$examplePath} to " . dirname($configPath) . "/weather.json and configure it.");
            } else {
                throw new \Exception("Weather configuration file not found: {$configPath}");
            }
        }
        
        $configContent = file_get_contents($configPath);
        if ($configContent === false) {
            throw new \Exception("Could not read weather configuration file: {$configPath}");
        }
        
        $this->config = json_decode($configContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON in weather configuration: " . json_last_error_msg());
        }
        
        // Validate required configuration sections
        $this->validateConfiguration();
        
        // Set properties from configuration
        $this->title = $this->config['title'];
        $this->coverageArea = $this->config['coverage_area'];
        $this->settings = $this->config['settings'] ?? [];
        
        // API key - prioritize config file, then fall back to environment
        $this->apiKey = $this->config['api_key'] ?? Config::env('WEATHER_API_KEY', '') ?: Config::env('weather_api_key', '');
        
        // Convert locations array to associative array for backward compatibility
        $this->locations = [];
        foreach ($this->config['locations'] as $location) {
            $this->locations[$location['name']] = [
                'lat' => $location['lat'],
                'lon' => $location['lon']
            ];
        }
    }
    
    /**
     * Validate configuration structure
     */
    private function validateConfiguration(): void
    {
        $required = ['title', 'coverage_area', 'locations'];
        foreach ($required as $field) {
            if (!isset($this->config[$field])) {
                throw new \Exception("Missing required configuration field: {$field}");
            }
        }
        
        if (!is_array($this->config['locations']) || empty($this->config['locations'])) {
            throw new \Exception("Configuration must include at least one location");
        }
        
        // Validate each location has required fields
        foreach ($this->config['locations'] as $index => $location) {
            $requiredLocationFields = ['name', 'lat', 'lon'];
            foreach ($requiredLocationFields as $field) {
                if (!isset($location[$field])) {
                    throw new \Exception("Location {$index} missing required field: {$field}");
                }
            }
        }
        
        // Validate settings if present
        if (isset($this->config['settings'])) {
            if (isset($this->config['settings']['max_locations']) && count($this->config['locations']) > $this->config['settings']['max_locations']) {
                throw new \Exception("Too many locations configured. Maximum allowed: " . $this->config['settings']['max_locations']);
            }
        }
    }
    
    /**
     * Get API key for debugging
     */
    public function getApiKey(): string 
    {
        return $this->apiKey;
    }
    
    /**
     * Get configuration for accessing config values
     */
    public function getConfig(): array
    {
        return $this->config;
    }
    
    /**
     * Generate weather report for all locations
     */
    public function generateReport(bool $demoMode = false): string 
    {
        if (empty($this->apiKey) && !$demoMode) {
            return "ERROR: Weather API key not configured. Please set 'WEATHER_API_KEY' environment variable.";
        }
        
        $report = $this->generateHeader();
        
        if ($demoMode) {
            $report .= $this->generateDemoCurrentConditions();
            $report .= $this->generateDemoForecast();
        } else {
            $report .= $this->generateCurrentConditions();
            $report .= $this->generateForecast();
        }
        
        $report .= $this->generateFooter();
        
        return $report;
    }
    
    /**
     * Generate report header
     */
    private function generateHeader(): string 
    {
        $date = date('l, F j, Y \a\t H:i T');
        $title = strtoupper($this->title);
        
        return "{$title}\n"
             . str_repeat("=", max(50, strlen($title))) . "\n"
             . "Generated: {$date}\n"
             . "Coverage: {$this->coverageArea}\n\n";
    }
    
    /**
     * Generate current conditions section
     */
    private function generateCurrentConditions(): string 
    {
        $conditions = "CURRENT CONDITIONS\n" . str_repeat("=", 20) . "\n\n";
        
        foreach ($this->locations as $locationName => $coords) {
            $currentWeather = $this->getCurrentWeather($coords['lat'], $coords['lon']);
            if(!$currentWeather)
                throw new Exception("Unable to getCurrentWeather");

            if ($currentWeather) {
                $temp = round($currentWeather['main']['temp']);
                $feelsLike = round($currentWeather['main']['feels_like']);
                $description = ucfirst($currentWeather['weather'][0]['description']);
                $humidity = $currentWeather['main']['humidity'];
                $pressure = $currentWeather['main']['pressure'];
                $windSpeed = round($currentWeather['wind']['speed'] * 3.6, 1); // Convert m/s to km/h
                $windDir = $currentWeather['wind']['deg'] ?? 0;
                $visibility = isset($currentWeather['visibility']) ? round($currentWeather['visibility'] / 1000, 1) : null;
                $cloudiness = $currentWeather['clouds']['all'] ?? 0;
                
                // Enhanced descriptive text
                $conditions .= "{$locationName}: {$temp}°C - {$description}\n";
                $conditions .= "  {$this->generateCurrentConditionsDescription($currentWeather)}\n";
                $conditions .= "  Details: Feels like {$feelsLike}°C, Humidity {$humidity}%, Wind {$windSpeed} km/h";
                if ($windDir > 0) {
                    $conditions .= " from " . $this->getWindDirection($windDir);
                }
                $conditions .= "\n  Pressure {$pressure} hPa";
                if ($cloudiness > 0) {
                    $conditions .= ", Cloud cover {$cloudiness}%";
                }
                if ($visibility !== null) {
                    $conditions .= ", Visibility {$visibility} km";
                }
                $conditions .= "\n\n";
            } else {
                $conditions .= "{$locationName}: Unable to retrieve current conditions\n\n";
            }
        }
        
        return $conditions;
    }
    
    /**
     * Generate 3-day forecast section
     */
    private function generateForecast(): string 
    {
        $forecast = "3-DAY FORECAST\n" . str_repeat("=", 20) . "\n\n";
        
        foreach ($this->locations as $locationName => $coords) {
            $res=$this->getLocationForecast($locationName, $coords['lat'], $coords['lon']);
            if(!$res)
                throw new Exception("Unable to getLocationForecast");
            $forecast .= $res;
            $forecast .= "\n";
        }
        
        return $forecast;
    }
    
    /**
     * Get current weather for a specific location
     */
    private function getCurrentWeather(float $lat, float $lon): ?array 
    {
        $url = "https://api.openweathermap.org/data/2.5/weather?lat={$lat}&lon={$lon}&appid={$this->apiKey}&units=metric";
        
        return $this->makeApiRequest($url);
    }
    
    /**
     * Get 3-day forecast for a specific location
     */
    private function getLocationForecast(string $locationName, float $lat, float $lon): string 
    {
        $url = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid={$this->apiKey}&units=metric";
        
        $data = $this->makeApiRequest($url);
        
        if (!$data || !isset($data['list'])) {
            throw new Exception("Unable to getLocationForecast");
            //return "{$locationName}: Unable to retrieve forecast data\n";
        }
        
        $locationForecast = "{$locationName}:\n" . str_repeat("-", strlen($locationName) + 1) . "\n";
        
        // Group forecast data by day (3-hourly data comes in, we want daily summaries)
        $dailyData = [];
        foreach ($data['list'] as $item) {
            $date = date('Y-m-d', $item['dt']);
            
            if (!isset($dailyData[$date])) {
                $dailyData[$date] = [
                    'temps' => [],
                    'conditions' => [],
                    'humidity' => [],
                    'wind_speed' => [],
                    'dt' => $item['dt']
                ];
            }
            
            $dailyData[$date]['temps'][] = $item['main']['temp'];
            $dailyData[$date]['conditions'][] = $item['weather'][0]['description'];
            $dailyData[$date]['humidity'][] = $item['main']['humidity'];
            $dailyData[$date]['wind_speed'][] = $item['wind']['speed'];
        }
        
        // Process up to 3 days
        $days = array_slice($dailyData, 0, 3, true);
        
        foreach ($days as $date => $dayData) {
            $dayName = date('D M j', $dayData['dt']);
            $high = round(max($dayData['temps']));
            $low = round(min($dayData['temps']));
            
            // Get most common weather condition for the day
            $conditionCounts = array_count_values($dayData['conditions']);
            $mostCommonCondition = array_search(max($conditionCounts), $conditionCounts);
            $description = ucfirst($mostCommonCondition);
            
            $avgHumidity = round(array_sum($dayData['humidity']) / count($dayData['humidity']));
            $avgWindSpeed = round((array_sum($dayData['wind_speed']) / count($dayData['wind_speed'])) * 3.6, 1);
            
            // Generate detailed forecast description
            $forecastDesc = $this->generateForecastDescription($mostCommonCondition, $high, $low, $avgHumidity, $avgWindSpeed);
            
            $locationForecast .= sprintf(
                "%s: %s, High %d°C, Low %d°C\n",
                $dayName,
                $description,
                $high,
                $low
            );
            $locationForecast .= "  " . $forecastDesc . "\n";
            $locationForecast .= sprintf("  Details: Humidity %d%%, Wind %s km/h\n\n", $avgHumidity, $avgWindSpeed);
        }
        
        return $locationForecast;
    }
    
    /**
     * Make API request to OpenWeatherMap
     */
    private function makeApiRequest(string $url): ?array 
    {
        $timeout = $this->settings['api_timeout'] ?? 10;
        
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'user_agent' => 'BinktermPHP Weather Reporter/' . Version::getVersion()
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            error_log("Weather API request failed: {$url}");
            // Get more details about the failure
            $error = error_get_last();
            if ($error) {
                error_log("Last error: " . $error['message']);
            }
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Weather API JSON decode error: " . json_last_error_msg());
            error_log("Response was: " . substr($response, 0, 200));
            return null;
        }
        
        // Check for API errors - OpenWeatherMap uses different codes
        if (isset($data['cod']) && $data['cod'] != 200) {
            $errorMsg = $data['message'] ?? 'Unknown error';
            error_log("Weather API error (code {$data['cod']}): {$errorMsg}");
            error_log("Full response: " . json_encode($data));
            return null;
        }
        
        return $data;
    }
    
    /**
     * Generate descriptive current conditions text
     */
    private function generateCurrentConditionsDescription(array $weather): string
    {
        $description = strtolower($weather['weather'][0]['description']);
        $main = strtolower($weather['weather'][0]['main']);
        $temp = round($weather['main']['temp']);
        $humidity = $weather['main']['humidity'];
        $windSpeed = round($weather['wind']['speed'] * 3.6, 1);
        $cloudiness = $weather['clouds']['all'] ?? 0;
        
        $desc = "";
        
        // Weather condition descriptions
        switch ($main) {
            case 'clear':
                $desc = "Clear skies prevail with bright sunshine. ";
                if ($temp > 25) {
                    $desc .= "Warm and pleasant conditions for outdoor activities.";
                } elseif ($temp < 10) {
                    $desc .= "Cool and crisp with excellent visibility.";
                } else {
                    $desc .= "Comfortable temperatures with good visibility.";
                }
                break;
                
            case 'clouds':
                if ($cloudiness < 25) {
                    $desc = "Partly cloudy with occasional sunshine breaking through. ";
                } elseif ($cloudiness < 75) {
                    $desc = "Mostly cloudy skies with limited sunshine. ";
                } else {
                    $desc = "Overcast conditions with thick cloud cover blocking most sunlight. ";
                }
                $desc .= "Cloud cover may provide natural temperature regulation.";
                break;
                
            case 'rain':
                if (strpos($description, 'light') !== false) {
                    $desc = "Light rain falling with gentle precipitation. ";
                } elseif (strpos($description, 'heavy') !== false) {
                    $desc = "Heavy rainfall with significant precipitation. ";
                } else {
                    $desc = "Steady rain with moderate precipitation. ";
                }
                $desc .= "Wet conditions - umbrellas and rain gear recommended.";
                break;
                
            case 'drizzle':
                $desc = "Fine drizzle creating misty conditions. Light moisture in the air with minimal accumulation.";
                break;
                
            case 'thunderstorm':
                $desc = "Thunderstorm activity with lightning and heavy rain. ";
                $desc .= "Seek indoor shelter and avoid outdoor activities.";
                break;
                
            case 'snow':
                if (strpos($description, 'light') !== false) {
                    $desc = "Light snowfall with gentle flakes. ";
                } else {
                    $desc = "Snow falling with accumulation likely. ";
                }
                $desc .= "Winter driving conditions - exercise caution on roads.";
                break;
                
            case 'mist':
            case 'fog':
                $desc = "Foggy conditions reducing visibility. ";
                $desc .= "Drive carefully and use headlights in reduced visibility.";
                break;
                
            default:
                $desc = ucfirst($description) . " conditions currently observed. ";
        }
        
        // Add wind information
        if ($windSpeed > 25) {
            $desc .= " Strong winds may affect outdoor activities.";
        } elseif ($windSpeed > 15) {
            $desc .= " Moderate winds creating breezy conditions.";
        } elseif ($windSpeed < 5) {
            $desc .= " Calm winds with still air.";
        }
        
        // Add humidity context
        if ($humidity > 80) {
            $desc .= " High humidity making it feel more muggy.";
        } elseif ($humidity < 30) {
            $desc .= " Low humidity creating dry air conditions.";
        }
        
        return $this->wrapText($desc, 77);
    }
    
    /**
     * Convert wind direction degrees to compass direction
     */
    private function getWindDirection(int $degrees): string
    {
        $directions = [
            'N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE',
            'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'
        ];
        
        $index = round($degrees / 22.5) % 16;
        return $directions[$index];
    }
    
    /**
     * Generate descriptive forecast text
     */
    private function generateForecastDescription(string $condition, int $high, int $low, int $humidity, float $windSpeed): string
    {
        $condition = strtolower($condition);
        $tempRange = $high - $low;
        
        $desc = "";
        
        // Weather condition forecast
        if (strpos($condition, 'clear') !== false) {
            $desc = "Expect bright, sunny skies throughout the day. ";
            if ($high > 25) {
                $desc .= "Hot temperatures - stay hydrated and seek shade during peak hours.";
            } elseif ($high > 20) {
                $desc .= "Perfect weather for outdoor activities and recreation.";
            } else {
                $desc .= "Cool but comfortable with plenty of sunshine.";
            }
        } elseif (strpos($condition, 'partly cloudy') !== false || strpos($condition, 'few clouds') !== false) {
            $desc = "Mix of sun and clouds with pleasant conditions. ";
            $desc .= "Good day for both indoor and outdoor activities.";
        } elseif (strpos($condition, 'overcast') !== false || strpos($condition, 'cloudy') !== false) {
            $desc = "Cloudy skies dominating with limited sunshine. ";
            if ($tempRange > 8) {
                $desc .= "Variable temperatures as clouds move in and out.";
            } else {
                $desc .= "Stable temperatures under cloud cover.";
            }
        } elseif (strpos($condition, 'light rain') !== false) {
            $desc = "Periods of light rain with occasional breaks. ";
            $desc .= "Keep an umbrella handy and dress in layers.";
        } elseif (strpos($condition, 'heavy rain') !== false) {
            $desc = "Significant rainfall expected throughout the day. ";
            $desc .= "Plan indoor activities and expect wet road conditions.";
        } elseif (strpos($condition, 'rain') !== false) {
            $desc = "Rain likely with wet conditions prevailing. ";
            $desc .= "Waterproof clothing recommended for any outdoor plans.";
        } elseif (strpos($condition, 'snow') !== false) {
            if (strpos($condition, 'light') !== false) {
                $desc = "Light snowfall creating winter scenery. ";
                $desc .= "Beautiful conditions but watch for slippery surfaces.";
            } else {
                $desc = "Snow accumulation likely with winter conditions. ";
                $desc .= "Travel may be impacted - check road conditions before heading out.";
            }
        } elseif (strpos($condition, 'thunderstorm') !== false) {
            $desc = "Thunderstorms possible with lightning and heavy rain. ";
            $desc .= "Stay indoors during storm activity and avoid open areas.";
        } elseif (strpos($condition, 'mist') !== false || strpos($condition, 'fog') !== false) {
            $desc = "Misty conditions reducing visibility. ";
            $desc .= "Allow extra time for travel and use caution on roads.";
        } else {
            $desc = "Weather conditions as described with typical seasonal patterns. ";
        }
        
        // Add temperature context
        if ($tempRange > 12) {
            $desc .= " Large temperature swing from morning to afternoon.";
        } elseif ($tempRange < 5) {
            $desc .= " Steady temperatures throughout the day.";
        }
        
        // Add wind context
        if ($windSpeed > 25) {
            $desc .= " Windy conditions may make it feel cooler.";
        } elseif ($windSpeed > 15) {
            $desc .= " Breezy conditions adding to the weather dynamic.";
        }
        
        // Add humidity context for comfort
        if ($humidity > 75 && $high > 20) {
            $desc .= " High humidity will make it feel warmer and more uncomfortable.";
        } elseif ($humidity < 35) {
            $desc .= " Low humidity creating crisp, dry air conditions.";
        }
        
        return $this->wrapText($desc, 77);
    }
    
    /**
     * Wrap text to specified line length
     */
    private function wrapText(string $text, int $width = 77): string
    {
        return wordwrap($text, $width, "\n  ", false);
    }
    
    /**
     * Generate demo conditions description for demo mode
     */
    private function generateDemoConditionsDescription(string $condition, int $temp, int $humidity, float $windSpeed): string
    {
        $condition = strtolower($condition);
        
        $desc = "";
        
        if ($condition === 'light rain') {
            $desc = "Light rain falling with gentle precipitation. Wet conditions - umbrellas and rain gear recommended.";
        } elseif ($condition === 'overcast') {
            $desc = "Overcast conditions with thick cloud cover blocking most sunlight. Cloud cover may provide natural temperature regulation.";
        } elseif ($condition === 'partly cloudy') {
            $desc = "Mix of sun and clouds with pleasant conditions. Good day for both indoor and outdoor activities.";
        } elseif ($condition === 'clear') {
            $desc = "Clear skies prevail with bright sunshine. Perfect weather for outdoor activities and recreation.";
        } elseif ($condition === 'cloudy') {
            $desc = "Cloudy skies dominating with limited sunshine. Stable temperatures under cloud cover.";
        } else {
            $desc = ucfirst($condition) . " conditions currently observed.";
        }
        
        // Add wind context
        if ($windSpeed > 15) {
            $desc .= " Moderate winds creating breezy conditions.";
        } elseif ($windSpeed < 10) {
            $desc .= " Calm winds with still air.";
        }
        
        // Add humidity context
        if ($humidity > 75) {
            $desc .= " High humidity making it feel more muggy.";
        } elseif ($humidity < 40) {
            $desc .= " Low humidity creating dry air conditions.";
        }
        
        return $this->wrapText($desc, 77);
    }
    
    /**
     * Generate report footer
     */
    private function generateFooter(): string 
    {
        return "\nWeather data provided by OpenWeatherMap\n"
             . "Report generated by " . Version::getFullVersion() . "\n"
             . str_repeat("=", 50) . "\n";
    }
    
    /**
     * Generate demo current conditions for testing
     */
    private function generateDemoCurrentConditions(): string 
    {
        $conditions = "DEMO CURRENT CONDITIONS\n" . str_repeat("=", 20) . "\n\n";
        
        // Use configured locations with sample data
        $sampleData = [
            ['temp' => 18, 'desc' => 'Light rain', 'feels' => 17, 'humidity' => 78, 'wind' => 15.2, 'pressure' => 1013],
            ['temp' => 16, 'desc' => 'Overcast', 'feels' => 15, 'humidity' => 82, 'wind' => 12.8, 'pressure' => 1011],
            ['temp' => 17, 'desc' => 'Light rain', 'feels' => 16, 'humidity' => 80, 'wind' => 14.3, 'pressure' => 1012],
            ['temp' => 19, 'desc' => 'Partly cloudy', 'feels' => 19, 'humidity' => 68, 'wind' => 9.7, 'pressure' => 1015],
            ['temp' => 21, 'desc' => 'Clear', 'feels' => 22, 'humidity' => 55, 'wind' => 8.1, 'pressure' => 1018],
            ['temp' => 14, 'desc' => 'Cloudy', 'feels' => 13, 'humidity' => 71, 'wind' => 11.5, 'pressure' => 1016]
        ];
        
        $index = 0;
        foreach ($this->locations as $locationName => $coords) {
            $data = $sampleData[$index % count($sampleData)];
            
            $conditions .= "{$locationName}: {$data['temp']}°C - {$data['desc']}\n";
            
            // Generate demo descriptive text
            $demoDesc = $this->generateDemoConditionsDescription($data['desc'], $data['temp'], $data['humidity'], $data['wind']);
            $conditions .= "  {$demoDesc}\n";
            $conditions .= "  Details: Feels like {$data['feels']}°C, Humidity {$data['humidity']}%, Wind {$data['wind']} km/h\n";
            $conditions .= "  Pressure {$data['pressure']} hPa\n\n";
            
            $index++;
        }
        
        return $conditions;
    }
    
    /**
     * Generate demo forecast for testing
     */
    private function generateDemoForecast(): string 
    {
        $forecast = "3-DAY FORECAST\n" . str_repeat("=", 20) . "\n\n";
        
        // Sample forecast data to cycle through
        $forecastData = [
            [
                ['desc' => 'Light rain', 'high' => 19, 'low' => 13, 'humidity' => 78, 'wind' => 15.3],
                ['desc' => 'Overcast', 'high' => 17, 'low' => 12, 'humidity' => 85, 'wind' => 12.1],
                ['desc' => 'Partly cloudy', 'high' => 21, 'low' => 14, 'humidity' => 72, 'wind' => 8.7]
            ],
            [
                ['desc' => 'Overcast', 'high' => 18, 'low' => 12, 'humidity' => 70, 'wind' => 13.8],
                ['desc' => 'Heavy rain', 'high' => 16, 'low' => 11, 'humidity' => 90, 'wind' => 18.5],
                ['desc' => 'Light rain', 'high' => 19, 'low' => 13, 'humidity' => 78, 'wind' => 11.3]
            ]
        ];
        
        $locationIndex = 0;
        foreach ($this->locations as $locationName => $coords) {
            $forecast .= "{$locationName}:\n" . str_repeat("-", strlen($locationName) + 1) . "\n";
            
            $dataSet = $forecastData[$locationIndex % count($forecastData)];
            
            $days = ['Fri Sep 6', 'Sat Sep 7', 'Sun Sep 8'];
            for ($i = 0; $i < 3; $i++) {
                $day = $dataSet[$i];
                $forecast .= "{$days[$i]}: {$day['desc']}, High {$day['high']}°C, Low {$day['low']}°C\n";
                
                // Generate demo forecast description
                $demoForecastDesc = $this->generateForecastDescription($day['desc'], $day['high'], $day['low'], $day['humidity'], $day['wind']);
                $forecast .= "  {$demoForecastDesc}\n";
                $forecast .= "  Details: Humidity {$day['humidity']}%, Wind {$day['wind']} km/h\n\n";
            }
            $forecast .= "\n";
            
            $locationIndex++;
        }
        
        return $forecast;
    }
    
    /**
     * Create an echomail message with the weather report (but don't send it)
     */
    public function createEchomailMessage(string $echoarea = 'LOCALTEST', string $toName = 'All', string $username = '', bool $demoMode = false): array 
    {
        $report = $this->generateReport($demoMode);
        $subject = $this->title . " - " . date('M j, Y');
        
        // Get user info if username provided
        $fromName = 'Weather Bot';
        if (!empty($username)) {
            $user = $this->getUserByUsername($username);
            if ($user && !empty($user['real_name'])) {
                $fromName = $user['real_name'];
            }
        } else {
            $fromName = Config::env('SYSOP_NAME', 'Weather Bot');
        }
        
        return [
            'type' => 'echomail',
            'echoarea' => $echoarea,
            'to_name' => $toName,
            'from_name' => $fromName,
            'from_address' => Config::env('FIDONET_ORIGIN', '1:2/3'),
            'subject' => $subject,
            'message_body' => $report,
            'date_written' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Get user by username
     */
    private function getUserByUsername(string $username): ?array 
    {
        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        return $user ?: null;
    }
    
    /**
     * Post weather report to one or more echomail areas
     */
    public function postWeatherReport(string $username = '', string $echoareas = 'LOCALTEST', string $domain='fidonet', string $toName = 'All', bool $demoMode = false): bool
    {
        $report = $this->generateReport($demoMode);
        if($report==false)
            return false;
        $subject = $this->title . " - " . date('M j, Y');
        
        try {
            $handler = new MessageHandler();
            $db = Database::getInstance()->getPdo();
            
            // Get user by username or fall back to first user
            if (!empty($username)) {
                $user = $this->getUserByUsername($username);
                if (!$user) {
                    echo "✗ User not found: {$username}\n";
                    return false;
                }
            } else {
                $stmt = $db->query("SELECT * FROM users ORDER BY id LIMIT 1");
                $user = $stmt->fetch();
                if (!$user) {
                    echo "✗ No users found in database\n";
                    return false;
                }
            }
            
            echo "Posting as user: {$user['username']} ({$user['real_name']})\n";
            
            // Parse comma-delimited echoareas
            $echoareaList = array_map('trim', explode(',', $echoareas));
            $successCount = 0;
            $totalCount = count($echoareaList);
            
            foreach ($echoareaList as $echoarea) {
                if (empty($echoarea)) continue;
                
                echo "Posting to {$echoarea}... ";
                
                // Verify echoarea exists
                $stmt = $db->prepare("SELECT * FROM echoareas WHERE tag = ? AND is_active = TRUE");
                $stmt->execute([$echoarea]);
                $area = $stmt->fetch();
                
                if (!$area) {
                    echo "✗ Echo area not found or inactive: {$echoarea}\n";
                    continue;
                }
                
                // Post the echomail message
                $result = $handler->postEchomail(
                    $user['id'],
                    $echoarea,
                    $domain,
                    $toName,
                    $subject,
                    $report,
                    null // no reply-to message
                );
                
                if ($result) {
                    echo "✓ Success\n";
                    $successCount++;
                } else {
                    echo "✗ Failed\n";
                }
            }
            
            echo "\nPosting summary: {$successCount}/{$totalCount} areas successful\n";
            return $successCount > 0;
            
        } catch (Exception $e) {
            echo "✗ Error posting weather report: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

// Main execution if run directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    // Parse command line arguments first
    $args = [];
    foreach ($argv ?? [] as $arg) {
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        }
    }
    
    // Check for demo mode, debug mode, and post mode
    $demoMode = isset($args['demo']);
    $debugMode = isset($args['debug']);
    $postMode = isset($args['post']);
    $username = $args['user'] ?? '';
    $echoareas = $args['areas'] ?? 'LOCALTEST';
    $domain = $args['domain'] ?? '';
    $configPath = $args['config'] ?? null;
    
    // Try to create the weather generator
    try {
        $generator = new WeatherReportGenerator($configPath);
        $config = $generator->getConfig();
        $title = $config['title'] ?? 'Weather Report Generator';
        echo "{$title}\n";
        echo str_repeat("=", max(50, strlen($title))) . "\n\n";
    } catch (Exception $e) {
        echo "Weather Report Generator\n";
        echo str_repeat("=", 50) . "\n\n";
        echo "❌ Configuration Error: " . $e->getMessage() . "\n\n";
        
        if (!$demoMode) {
            echo "You can:\n";
            echo "1. Copy config/weather.json.example to config/weather.json and configure it\n";
            echo "2. Run in demo mode: php scripts/weather_report.php --demo\n";
            echo "3. Specify custom config: php scripts/weather_report.php --config=/path/to/config.json\n";
            exit(1);
        } else {
            echo "Demo mode enabled - using fallback configuration\n\n";
            // Create a minimal generator for demo mode
            $generator = new class {
                public function generateReport($demo = true) { 
                    return "Demo weather report - configuration file needed for live data\n"; 
                }
                public function createEchomailMessage($area = 'LOCALTEST', $to = 'All', $user = '', $demo = false) {
                    return ['type' => 'demo', 'message' => 'Demo message'];
                }
            };
        }
    }
    
    // Debug mode - test API connectivity
    if ($debugMode) {
        echo "DEBUG MODE: Testing API connectivity\n";
        echo str_repeat("-", 40) . "\n\n";
        
        // Test with Vancouver coordinates
        $testUrl = "https://api.openweathermap.org/data/2.5/weather?lat=49.2827&lon=-123.1207&appid=" . $generator->getApiKey() . "&units=metric";
        echo "Testing URL: {$testUrl}\n\n";
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'BinktermPHP Weather Reporter/' . Version::getVersion()
            ]
        ]);
        
        echo "Making request...\n";
        $response = @file_get_contents($testUrl, false, $context);
        
        if ($response === false) {
            echo "❌ Request failed!\n";
            $error = error_get_last();
            if ($error) {
                echo "Error: " . $error['message'] . "\n";
            }
        } else {
            echo "✅ Request successful!\n";
            echo "Response length: " . strlen($response) . " characters\n";
            echo "First 200 characters: " . substr($response, 0, 200) . "\n\n";
            
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "❌ JSON decode error: " . json_last_error_msg() . "\n";
            } else {
                echo "✅ JSON decoded successfully\n";
                if (isset($data['cod'])) {
                    echo "API response code: " . $data['cod'] . "\n";
                    if (isset($data['message'])) {
                        echo "API message: " . $data['message'] . "\n";
                    }
                }
                if (isset($data['name'])) {
                    echo "Location: " . $data['name'] . "\n";
                }
                if (isset($data['main']['temp'])) {
                    echo "Temperature: " . $data['main']['temp'] . "°C\n";
                }
            }
        }
        return;
    }
    
    // Post mode - actually post to echomail
    if ($postMode) {
        echo "POST MODE: Posting weather report to echoarea(s)\n";
        echo str_repeat("-", 50) . "\n\n";
        
        if (!empty($username)) {
            echo "User: {$username}\n";
        }
        echo "Areas: {$echoareas}\n\n";
        
        $success = $generator->postWeatherReport($username, $echoareas, $domain,'All', $demoMode);
        exit($success ? 0 : 1);
    }
    
    // Generate and display the weather report
    if ($demoMode) {
        echo "DEMO MODE: Showing sample weather report with mock data\n";
        echo str_repeat("-", 55) . "\n\n";
        echo $generator->generateReport(true);
    } else {
        echo "Weather Report:\n";
        echo str_repeat("-", 20) . "\n";
        $report = $generator->generateReport();
        echo $report;
        
        // If no API key, show demo instructions
        if (strpos($report, 'ERROR:') === 0) {
            echo "\nTo see a demo report with sample data, run:\n";
            echo "php scripts/weather_report.php --demo\n\n";
        }
    }
    
    // Create sample echomail message structure (but don't send)
    echo "\nSample Echomail Message Structure:\n";
    echo str_repeat("-", 36) . "\n";
    $echomailData = $generator->createEchomailMessage('LOCALTEST', 'All', $username, $demoMode);
    
    foreach ($echomailData as $key => $value) {
        if ($key === 'message_body') {
            echo "{$key}: [" . strlen($value) . " characters]\n";
        } else {
            echo "{$key}: {$value}\n";
        }
    }
    
    echo "\n[Note: This is a test run. No messages were actually posted.]\n";
    echo "Usage examples:\n";
    echo "  Post to LOCALTEST:           php scripts/weather_report.php --post\n";
    echo "  Post to multiple areas:      php scripts/weather_report.php --post --areas=LOCALTEST,WEATHER\n";
    echo "  Post as specific user:       php scripts/weather_report.php --post --user=admin\n";
    echo "  Post with all options:       php scripts/weather_report.php --post --user=admin --areas=LOCALTEST,WEATHER\n";
}