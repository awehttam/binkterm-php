<?php

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
        $user = $this->auth->getCurrentUser();
        if (!$user) {
            header('Location: /login');
            exit;
        }
        
        $limit = 50;
        $offset = ($page - 1) * $limit;
        
        $criteria = [];
        if ($search) {
            $criteria['sysop'] = $search;
            $criteria['location'] = $search;
            $criteria['system_name'] = $search;
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
            'search' => $search,
            'selectedZone' => $zone,
            'selectedNet' => $net,
            'page' => $page
        ]);
    }
    
    public function view($address)
    {
        $user = $this->auth->getCurrentUser();
        if (!$user) {
            header('Location: /login');
            exit;
        }
        
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
                if (!isset($_FILES['nodelist']) || $_FILES['nodelist']['error'] !== UPLOAD_ERR_OK) {
                    throw new \Exception('Please select a valid nodelist file');
                }
                
                $uploadedFile = $_FILES['nodelist'];
                $tempPath = $uploadedFile['tmp_name'];
                
                if (!$this->validateNodelistFile($tempPath)) {
                    throw new \Exception('Invalid nodelist format');
                }
                
                $result = $this->nodelistManager->importNodelist($tempPath);
                
                $message = sprintf(
                    'Successfully imported %d nodes from %s (Day %d)',
                    $result['inserted_nodes'],
                    $result['filename'],
                    $result['total_nodes']
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
        
        $user = $this->auth->getCurrentUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            return;
        }
        
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
}