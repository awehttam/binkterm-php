<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */


namespace BinktermPHP\Web;

use BinktermPHP\Database;
use BinktermPHP\Template;
use BinktermPHP\Auth;
use BinktermPHP\Nodelist\NodelistManager;

class NodelistController
{
    private $db;
    private $template;
    private $nodelistManager;
    private $auth;
    
    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->template = new Template();
        $this->nodelistManager = new NodelistManager();
        $this->auth = new Auth();
    }
    
    public function index($search = '', $zone = '', $net = '', $page = 1)
    {
        $user = $this->auth->getCurrentUser(); // Get user if logged in, but don't require auth
        
        $limit = 50;
        $offset = ($page - 1) * $limit;
        
        $criteria = [];
        if ($search) {
            $criteria['search_term'] = $search;
        }
        if ($zone) {
            $criteria['zone'] = $zone;
        }
        if ($net) {
            $criteria['net'] = $net;
        }
        
        $nodes = $this->nodelistManager->searchNodes($criteria);
        $zones = $this->nodelistManager->getZones();
        $stats = $this->nodelistManager->getNodelistStats();
        $activeNodelist = $this->nodelistManager->getActiveNodelist();
        $activeNodelists = $this->nodelistManager->getActiveNodelists();

        $nets = [];
        if ($zone) {
            $nets = $this->nodelistManager->getNetsByZone($zone);
        }

        return $this->template->render('nodelist/index.twig', [
            'user' => $user,
            'title' => 'Node List Browser',
            'nodes' => $nodes,
            'zones' => $zones,
            'nets' => $nets,
            'stats' => $stats,
            'activeNodelist' => $activeNodelist,
            'activeNodelists' => $activeNodelists,
            'search' => $search,
            'selectedZone' => $zone,
            'selectedNet' => $net,
            'page' => $page
        ]);
    }
    
    public function view($address)
    {
        $user = $this->auth->getCurrentUser(); // Get user if logged in, but don't require auth
        
        if (empty($address)) {
            http_response_code(400);
            return $this->template->render('error.twig', [
                'user' => $user,
                'title' => 'Invalid Request',
                'message' => 'No node address specified.'
            ]);
        }
        
        $node = $this->nodelistManager->findNode($address);
        if (!$node) {
            http_response_code(404);
            return $this->template->render('error.twig', [
                'user' => $user,
                'title' => 'Node Not Found',
                'message' => 'The requested node address was not found in the nodelist.'
            ]);
        }
        
        return $this->template->render('nodelist/view.twig', [
            'user' => $user,
            'title' => 'Node Details - ' . $address,
            'node' => $node
        ]);
    }
    
    public function import()
    {
        $user = $this->auth->getCurrentUser();
        if (!$user || !$user['is_admin']) {
            http_response_code(403);
            return $this->template->render('error.twig', [
                'user' => $user,
                'title' => 'Access Denied',
                'message' => 'Administrator access required.'
            ]);
        }
        
        $message = '';
        $error = '';
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                // Get and validate domain
                $domain = trim($_POST['domain'] ?? '');
                if (empty($domain)) {
                    throw new \Exception('Please specify a network domain');
                }
                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $domain)) {
                    throw new \Exception('Domain should contain only letters, numbers, underscores, and hyphens');
                }
                $domain = strtolower($domain);

                if (!isset($_FILES['nodelist']) || $_FILES['nodelist']['error'] !== UPLOAD_ERR_OK) {
                    throw new \Exception('Please select a valid nodelist file');
                }

                $uploadedFile = $_FILES['nodelist'];
                $tempPath = $uploadedFile['tmp_name'];
                $originalName = $uploadedFile['name'];

                // Handle compressed files
                $actualNodelistFile = $this->handleCompressedFile($tempPath, $originalName);

                if (!$this->validateNodelistFile($actualNodelistFile)) {
                    throw new \Exception('Invalid nodelist format');
                }

                // Check if archive_old checkbox is checked
                $archiveOld = isset($_POST['archive_old']);

                $result = $this->nodelistManager->importNodelist($actualNodelistFile, $domain, $archiveOld);
                
                $message = sprintf(
                    'Successfully imported %d nodes from %s (Day %d) for domain @%s',
                    $result['inserted_nodes'],
                    $result['filename'],
                    $result['day_of_year'],
                    $domain
                );
                
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }
        
        $activeNodelist = $this->nodelistManager->getActiveNodelist();
        $stats = $this->nodelistManager->getNodelistStats();
        
        return $this->template->render('nodelist/import.twig', [
            'user' => $user,
            'title' => 'Import Nodelist',
            'message' => $message,
            'error' => $error,
            'activeNodelist' => $activeNodelist,
            'stats' => $stats
        ]);
    }
    
    public function api($action = '')
    {
        header('Content-Type: application/json');
        
        $user = $this->auth->getCurrentUser(); // Get user if logged in, but don't require auth
        
        try {
            switch ($action) {
                case 'search':
                    return $this->apiSearch();
                case 'node':
                    return $this->apiNode();
                case 'zones':
                    return $this->apiZones();
                case 'nets':
                    return $this->apiNets();
                case 'stats':
                    return $this->apiStats();
                default:
                    http_response_code(404);
                    echo json_encode(['error' => 'API endpoint not found']);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function apiSearch()
    {
        $query = $_GET['q'] ?? '';
        $type = $_GET['type'] ?? 'all';
        $zone = $_GET['zone'] ?? '';
        $net = $_GET['net'] ?? '';
        
        $criteria = [];
        
        switch ($type) {
            case 'address':
                $criteria['address'] = $query;
                break;
            case 'sysop':
                $criteria['sysop'] = $query;
                break;
            case 'location':
                $criteria['location'] = $query;
                break;
            case 'system':
                $criteria['system_name'] = $query;
                break;
            default:
                if ($query) {
                    $nodes1 = $this->nodelistManager->findNodesBySysop($query);
                    $nodes2 = $this->nodelistManager->findNodesByLocation($query);
                    $nodes = array_merge($nodes1, $nodes2);
                    
                    $seen = [];
                    $unique = [];
                    foreach ($nodes as $node) {
                        $key = $node['zone'] . ':' . $node['net'] . '/' . $node['node'] . '.' . $node['point'];
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $unique[] = $node;
                        }
                    }
                    
                    echo json_encode(['nodes' => array_slice($unique, 0, 100)]);
                    return;
                }
        }
        
        if ($zone) {
            $criteria['zone'] = $zone;
        }
        if ($net) {
            $criteria['net'] = $net;
        }
        
        $nodes = $this->nodelistManager->searchNodes($criteria);
        echo json_encode(['nodes' => array_slice($nodes, 0, 100)]);
    }
    
    private function apiNode()
    {
        $address = $_GET['address'] ?? '';
        if (!$address) {
            http_response_code(400);
            echo json_encode(['error' => 'Address parameter required']);
            return;
        }
        
        $node = $this->nodelistManager->findNode($address);
        if (!$node) {
            http_response_code(404);
            echo json_encode(['error' => 'Node not found']);
            return;
        }
        
        echo json_encode(['node' => $node]);
    }
    
    private function apiZones()
    {
        $zones = $this->nodelistManager->getZones();
        echo json_encode(['zones' => $zones]);
    }
    
    private function apiNets()
    {
        $zone = $_GET['zone'] ?? '';
        if (!$zone) {
            http_response_code(400);
            echo json_encode(['error' => 'Zone parameter required']);
            return;
        }
        
        $nets = $this->nodelistManager->getNetsByZone($zone);
        echo json_encode(['nets' => $nets]);
    }
    
    private function apiStats()
    {
        $stats = $this->nodelistManager->getNodelistStats();
        $activeNodelist = $this->nodelistManager->getActiveNodelist();
        
        echo json_encode([
            'stats' => $stats,
            'active_nodelist' => $activeNodelist
        ]);
    }
    
    private function validateNodelistFile($filepath)
    {
        $content = file_get_contents($filepath, false, null, 0, 1000);
        return strpos($content, ';') === 0;
    }
    
    private function handleCompressedFile($tempPath, $originalName)
    {
        $archiveType = $this->detectArchiveType($originalName);
        
        if ($archiveType === 'PLAIN') {
            return $tempPath;
        }
        
        // Create temp directory for extraction
        $extractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nodelist_web_' . uniqid();
        mkdir($extractDir);
        
        try {
            return $this->extractArchive($tempPath, $archiveType, $extractDir);
        } catch (\Exception $e) {
            $this->cleanupTempFiles($extractDir);
            throw new \Exception('Failed to extract archive: ' . $e->getMessage());
        }
    }
    
    private function detectArchiveType($filename)
    {
        $ext = strtoupper(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (preg_match('/^Z(\d+)$/', $ext)) {
            return 'ZIP';
        } elseif (preg_match('/^A(\d+)$/', $ext)) {
            return 'ARC';
        } elseif (preg_match('/^J(\d+)$/', $ext)) {
            return 'ARJ';
        } elseif (preg_match('/^L(\d+)$/', $ext)) {
            return 'LZH';
        } elseif (preg_match('/^R(\d+)$/', $ext)) {
            return 'RAR';
        }
        
        return 'PLAIN';
    }
    
    private function extractArchive($archiveFile, $type, $tempDir)
    {
        $extractedFile = null;
        
        switch ($type) {
            case 'ZIP':
                if (!extension_loaded('zip')) {
                    throw new \Exception("ZIP extension not available");
                }
                
                $zip = new \ZipArchive;
                if ($zip->open($archiveFile) === TRUE) {
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $filename = $zip->getNameIndex($i);
                        if (preg_match('/nodelist/i', $filename) || preg_match('/\.(\d{3})$/', $filename)) {
                            $extractedFile = $tempDir . DIRECTORY_SEPARATOR . basename($filename);
                            $zip->extractTo($tempDir, $filename);
                            if (basename($filename) !== basename($extractedFile)) {
                                rename($tempDir . DIRECTORY_SEPARATOR . $filename, $extractedFile);
                            }
                            break;
                        }
                    }
                    $zip->close();
                } else {
                    throw new \Exception("Could not open ZIP file");
                }
                break;
                
            default:
                throw new \Exception("Archive format {$type} not supported in web interface (use command line)");
        }
        
        if (!$extractedFile || !file_exists($extractedFile)) {
            throw new \Exception("Could not find nodelist file in archive");
        }
        
        return $extractedFile;
    }
    
    private function cleanupTempFiles($tempDir)
    {
        if (is_dir($tempDir)) {
            $files = glob($tempDir . DIRECTORY_SEPARATOR . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($tempDir);
        }
    }
}
