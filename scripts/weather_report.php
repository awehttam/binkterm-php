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

use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use BinktermPHP\Version;

/**
 * Weather Report Generator for British Columbia / Pacific Northwest
 * 
 * Generates a 7-day weather forecast and special weather statements
 * for posting to echomail forums or sending as netmail.
 */
class WeatherReportGenerator 
{
    private $apiKey;
    private $locations;
    
    public function __construct() 
    {
        // OpenWeatherMap API key - get from environment or config
        $this->apiKey = Config::env('WEATHER_API_KEY', '') ?: Config::env('weather_api_key', '');
        
        // Major BC/PNW cities for weather reporting
        $this->locations = [
            'Vancouver' => ['lat' => 49.2827, 'lon' => -123.1207],
            'Victoria' => ['lat' => 48.4284, 'lon' => -123.3656],
            'Seattle' => ['lat' => 47.6062, 'lon' => -122.3321],
            'Portland' => ['lat' => 45.5152, 'lon' => -122.6784],
            'Kelowna' => ['lat' => 49.8880, 'lon' => -119.4960],
            'Prince George' => ['lat' => 53.9171, 'lon' => -122.7497]
        ];
    }
    
    /**
     * Get API key for debugging
     */
    public function getApiKey(): string 
    {
        return $this->apiKey;
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
        
        return "PACIFIC NORTHWEST WEATHER REPORT\n"
             . str_repeat("=", 50) . "\n"
             . "Generated: {$date}\n"
             . "Coverage: British Columbia & Pacific Northwest\n\n";
    }
    
    /**
     * Generate current conditions section
     */
    private function generateCurrentConditions(): string 
    {
        $conditions = "CURRENT CONDITIONS\n" . str_repeat("=", 20) . "\n\n";
        
        foreach ($this->locations as $locationName => $coords) {
            $currentWeather = $this->getCurrentWeather($coords['lat'], $coords['lon']);
            
            if ($currentWeather) {
                $temp = round($currentWeather['main']['temp']);
                $feelsLike = round($currentWeather['main']['feels_like']);
                $description = ucfirst($currentWeather['weather'][0]['description']);
                $humidity = $currentWeather['main']['humidity'];
                $pressure = $currentWeather['main']['pressure'];
                $windSpeed = round($currentWeather['wind']['speed'] * 3.6, 1); // Convert m/s to km/h
                $windDir = $currentWeather['wind']['deg'] ?? 0;
                
                $conditions .= "{$locationName}: {$temp}°C ({$description})\n";
                $conditions .= "  Feels like {$feelsLike}°C, Humidity {$humidity}%, Wind {$windSpeed} km/h\n";
                $conditions .= "  Pressure {$pressure} hPa\n\n";
            } else {
                $conditions .= "{$locationName}: Unable to retrieve current conditions\n\n";
            }
        }
        
        return $conditions;
    }
    
    /**
     * Generate 5-day forecast section
     */
    private function generateForecast(): string 
    {
        $forecast = "5-DAY FORECAST\n" . str_repeat("=", 20) . "\n\n";
        
        foreach ($this->locations as $locationName => $coords) {
            $forecast .= $this->getLocationForecast($locationName, $coords['lat'], $coords['lon']);
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
     * Get 5-day forecast for a specific location
     */
    private function getLocationForecast(string $locationName, float $lat, float $lon): string 
    {
        $url = "https://api.openweathermap.org/data/2.5/forecast?lat={$lat}&lon={$lon}&appid={$this->apiKey}&units=metric";
        
        $data = $this->makeApiRequest($url);
        
        if (!$data || !isset($data['list'])) {
            return "{$locationName}: Unable to retrieve forecast data\n";
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
        
        // Process up to 5 days
        $days = array_slice($dailyData, 0, 5, true);
        
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
            
            $locationForecast .= sprintf(
                "%s: %s, High %d°C, Low %d°C, Humidity %d%%, Wind %s km/h\n",
                $dayName,
                $description,
                $high,
                $low,
                $avgHumidity,
                $avgWindSpeed
            );
        }
        
        return $locationForecast;
    }
    
    /**
     * Make API request to OpenWeatherMap
     */
    private function makeApiRequest(string $url): ?array 
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
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
        $conditions = "CURRENT CONDITIONS\n" . str_repeat("=", 20) . "\n\n";
        
        $conditions .= "Vancouver: 18°C (Light rain)\n";
        $conditions .= "  Feels like 17°C, Humidity 78%, Wind 15.2 km/h\n";
        $conditions .= "  Pressure 1013 hPa\n\n";
        
        $conditions .= "Victoria: 16°C (Overcast)\n";
        $conditions .= "  Feels like 15°C, Humidity 82%, Wind 12.8 km/h\n";
        $conditions .= "  Pressure 1011 hPa\n\n";
        
        $conditions .= "Seattle: 17°C (Light rain)\n";
        $conditions .= "  Feels like 16°C, Humidity 80%, Wind 14.3 km/h\n";
        $conditions .= "  Pressure 1012 hPa\n\n";
        
        $conditions .= "Portland: 19°C (Partly cloudy)\n";
        $conditions .= "  Feels like 19°C, Humidity 68%, Wind 9.7 km/h\n";
        $conditions .= "  Pressure 1015 hPa\n\n";
        
        $conditions .= "Kelowna: 21°C (Clear)\n";
        $conditions .= "  Feels like 22°C, Humidity 55%, Wind 8.1 km/h\n";
        $conditions .= "  Pressure 1018 hPa\n\n";
        
        $conditions .= "Prince George: 14°C (Cloudy)\n";
        $conditions .= "  Feels like 13°C, Humidity 71%, Wind 11.5 km/h\n";
        $conditions .= "  Pressure 1016 hPa\n\n";
        
        return $conditions;
    }
    
    /**
     * Generate demo forecast for testing
     */
    private function generateDemoForecast(): string 
    {
        $forecast = "5-DAY FORECAST\n" . str_repeat("=", 20) . "\n\n";
        
        $forecast .= "Vancouver:\n----------\n";
        $forecast .= "Fri Sep 6: Light rain, High 19°C, Low 13°C, Humidity 78%, Wind 15.3 km/h\n";
        $forecast .= "Sat Sep 7: Overcast, High 17°C, Low 12°C, Humidity 85%, Wind 12.1 km/h\n";
        $forecast .= "Sun Sep 8: Partly cloudy, High 21°C, Low 14°C, Humidity 72%, Wind 8.7 km/h\n";
        $forecast .= "Mon Sep 9: Sunny, High 24°C, Low 16°C, Humidity 58%, Wind 6.4 km/h\n";
        $forecast .= "Tue Sep 10: Partly cloudy, High 23°C, Low 15°C, Humidity 61%, Wind 10.2 km/h\n\n";
        
        $forecast .= "Victoria:\n---------\n";
        $forecast .= "Fri Sep 6: Overcast, High 18°C, Low 12°C, Humidity 70%, Wind 13.8 km/h\n";
        $forecast .= "Sat Sep 7: Heavy rain, High 16°C, Low 11°C, Humidity 90%, Wind 18.5 km/h\n";
        $forecast .= "Sun Sep 8: Light rain, High 19°C, Low 13°C, Humidity 78%, Wind 11.3 km/h\n";
        $forecast .= "Mon Sep 9: Sunny, High 22°C, Low 14°C, Humidity 55%, Wind 9.8 km/h\n";
        $forecast .= "Tue Sep 10: Partly cloudy, High 21°C, Low 13°C, Humidity 63%, Wind 12.4 km/h\n\n";
        
        $forecast .= "Seattle:\n--------\n";
        $forecast .= "Fri Sep 6: Rain, High 18°C, Low 12°C, Humidity 75%, Wind 16.2 km/h\n";
        $forecast .= "Sat Sep 7: Heavy rain, High 16°C, Low 11°C, Humidity 88%, Wind 19.8 km/h\n";
        $forecast .= "Sun Sep 8: Light rain, High 20°C, Low 13°C, Humidity 74%, Wind 9.5 km/h\n";
        $forecast .= "Mon Sep 9: Sunny, High 23°C, Low 15°C, Humidity 59%, Wind 7.3 km/h\n";
        $forecast .= "Tue Sep 10: Partly cloudy, High 22°C, Low 14°C, Humidity 64%, Wind 10.1 km/h\n\n";
        
        return $forecast;
    }
    
    /**
     * Create an echomail message with the weather report (but don't send it)
     */
    public function createEchomailMessage(string $echoarea = 'LOCALTEST', string $toName = 'All', string $username = '', bool $demoMode = false): array 
    {
        $report = $this->generateReport($demoMode);
        $subject = "Pacific Northwest Weather Report - " . date('M j, Y');
        
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
    public function postWeatherReport(string $username = '', string $echoareas = 'LOCALTEST', string $toName = 'All', bool $demoMode = false): bool 
    {
        $report = $this->generateReport($demoMode);
        $subject = "Pacific Northwest Weather Report - " . date('M j, Y');
        
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
    echo "Pacific Northwest Weather Report Generator\n";
    echo str_repeat("=", 50) . "\n\n";
    
    // Parse command line arguments
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
    
    $generator = new WeatherReportGenerator();
    
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
        
        $success = $generator->postWeatherReport($username, $echoareas, 'All', $demoMode);
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