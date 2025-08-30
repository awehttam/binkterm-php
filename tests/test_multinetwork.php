<?php
/**
 * Multi-Network Support Test Script
 * 
 * Tests the new multi-network functionality added in v1.5.0
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\AdminController;
use BinktermPHP\Binkp\Config\BinkpConfig;

class MultiNetworkTest
{
    private $db;
    private $adminController;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->adminController = new AdminController();
        $this->config = BinkpConfig::getInstance();
    }

    public function runTests()
    {
        echo "Multi-Network Support Tests\n";
        echo "===========================\n\n";

        $tests = [
            'testNetworksTable',
            'testNetworkUplinksTable', 
            'testEchoareasNetworkIntegration',
            'testAdminControllerNetworkMethods',
            'testBinkpConfigNetworkMethods',
            'testDomainHandling'
        ];

        $passed = 0;
        $total = count($tests);

        foreach ($tests as $test) {
            try {
                echo "Running $test... ";
                $result = $this->$test();
                if ($result) {
                    echo "✓ PASSED\n";
                    $passed++;
                } else {
                    echo "✗ FAILED\n";
                }
            } catch (Exception $e) {
                echo "✗ ERROR: " . $e->getMessage() . "\n";
            }
        }

        echo "\n=== Results ===\n";
        echo "Passed: $passed/$total\n";
        if ($passed === $total) {
            echo "✓ All tests passed!\n";
        } else {
            echo "✗ Some tests failed.\n";
        }

        return $passed === $total;
    }

    private function testNetworksTable()
    {
        // Check if networks table exists and has FidoNet
        $stmt = $this->db->query("
            SELECT COUNT(*) as count FROM networks 
            WHERE domain = 'fidonet' AND is_active = TRUE
        ");
        $count = $stmt->fetch()['count'];
        
        return $count > 0;
    }

    private function testNetworkUplinksTable()
    {
        // Check if network_uplinks table exists
        $stmt = $this->db->query("
            SELECT COUNT(*) as count 
            FROM information_schema.tables 
            WHERE table_name = 'network_uplinks'
        ");
        $count = $stmt->fetch()['count'];
        
        return $count > 0;
    }

    private function testEchoareasNetworkIntegration()
    {
        // Check if echoareas have network_id and are assigned to fidonet
        $stmt = $this->db->query("
            SELECT COUNT(*) as count FROM echoareas ea
            JOIN networks n ON ea.network_id = n.id
            WHERE n.domain = 'fidonet'
        ");
        $count = $stmt->fetch()['count'];
        
        return $count > 0;
    }

    private function testAdminControllerNetworkMethods()
    {
        // Test if AdminController network methods exist and work
        $networks = $this->adminController->getNetworks();
        
        // Should have at least the fidonet network
        $hasfidonet = false;
        foreach ($networks as $network) {
            if ($network['domain'] === 'fidonet') {
                $hasfidonet = true;
                break;
            }
        }

        return $hasfidonet;
    }

    private function testBinkpConfigNetworkMethods()
    {
        // Test BinkpConfig network methods
        $networks = $this->config->getNetworks();
        $fidonetNetwork = $this->config->getNetworkByDomain('fidonet');
        
        return !empty($networks) && $fidonetNetwork !== null;
    }

    private function testDomainHandling()
    {
        // Test domain extraction from addresses
        $domain1 = $this->config->getNetworkDomainFromAddress('1:123/456@fidonet');
        $domain2 = $this->config->getNetworkDomainFromAddress('1:123/456');
        
        return $domain1 === 'fidonet' && $domain2 === 'fidonet';
    }

    public function demonstrateNewFeatures()
    {
        echo "\n\nMulti-Network Features Demo\n";
        echo "===========================\n\n";

        // Show available networks
        echo "Available Networks:\n";
        echo "-------------------\n";
        $networks = $this->config->getNetworks();
        foreach ($networks as $network) {
            echo sprintf("- %s (%s): %s\n", 
                $network['domain'], 
                $network['name'], 
                $network['description'] ?: 'No description'
            );
        }

        // Show echoareas with network information
        echo "\nEchoareas by Network:\n";
        echo "--------------------\n";
        $stmt = $this->db->query("
            SELECT ea.tag, ea.description, n.domain, n.name as network_name
            FROM echoareas ea
            JOIN networks n ON ea.network_id = n.id
            ORDER BY n.domain, ea.tag
            LIMIT 10
        ");
        $echoareas = $stmt->fetchAll();
        
        $currentNetwork = '';
        foreach ($echoareas as $area) {
            if ($area['domain'] !== $currentNetwork) {
                $currentNetwork = $area['domain'];
                echo "\n  {$area['network_name']} ({$area['domain']}):\n";
            }
            echo "    - {$area['tag']}: {$area['description']}\n";
        }

        // Show domain handling examples
        echo "\nDomain Handling Examples:\n";
        echo "-------------------------\n";
        $testAddresses = [
            '1:123/456@fidonet',
            '21:1/100@fsxnet', 
            '432:1/101@dovenet',
            '1:234/567' // no domain
        ];

        foreach ($testAddresses as $addr) {
            $domain = $this->config->getNetworkDomainFromAddress($addr);
            echo "  $addr -> network: $domain\n";
        }
    }
}

// Run the tests
$tester = new MultiNetworkTest();
$success = $tester->runTests();

// Show demo if tests passed
if ($success) {
    $tester->demonstrateNewFeatures();
}

exit($success ? 0 : 1);