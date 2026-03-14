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

class NodelistImportException extends \RuntimeException
{
    private string $errorCode;
    private array $errorParams;

    public function __construct(string $errorCode, array $errorParams = [])
    {
        parent::__construct($errorCode);
        $this->errorCode = $errorCode;
        $this->errorParams = $errorParams;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorParams(): array
    {
        return $this->errorParams;
    }
}

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
                'error_title_code' => 'ui.error.title',
                'error_code' => 'errors.nodelist.api.address_required'
            ]);
        }
        
        $node = $this->nodelistManager->findNode($address);
        if (!$node) {
            http_response_code(404);
            return $this->template->render('error.twig', [
                'user' => $user,
                'error_title_code' => 'ui.error.title',
                'error_code' => 'errors.nodelist.api.node_not_found'
            ]);
        }
        
        return $this->template->render('nodelist/view.twig', [
            'user' => $user,
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
                'error_title_code' => 'ui.error.access_error',
                'error_code' => 'errors.nodelist.admin_required'
            ]);
        }
        
        $importMessageCode = null;
        $importMessageParams = [];
        $importErrorCode = null;
        $importErrorParams = [];
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                // Get and validate domain
                $domain = trim($_POST['domain'] ?? '');
                if (empty($domain)) {
                    throw new NodelistImportException('errors.nodelist.import.domain_required');
                }
                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $domain)) {
                    throw new NodelistImportException('errors.nodelist.import.domain_invalid');
                }
                $domain = strtolower($domain);

                if (!isset($_FILES['nodelist']) || $_FILES['nodelist']['error'] !== UPLOAD_ERR_OK) {
                    throw new NodelistImportException('errors.nodelist.import.file_required');
                }

                $uploadedFile = $_FILES['nodelist'];
                $tempPath = $uploadedFile['tmp_name'];
                $originalName = $uploadedFile['name'];

                // Handle compressed files
                $actualNodelistFile = $this->handleCompressedFile($tempPath, $originalName);

                if (!$this->validateNodelistFile($actualNodelistFile)) {
                    throw new NodelistImportException('errors.nodelist.import.invalid_format');
                }

                // Check if archive_old checkbox is checked
                $archiveOld = isset($_POST['archive_old']);

                $result = $this->nodelistManager->importNodelist($actualNodelistFile, $domain, $archiveOld);

                $importMessageCode = 'ui.nodelist.import.success';
                $importMessageParams = [
                    'count' => (int)($result['inserted_nodes'] ?? 0),
                    'filename' => (string)($result['filename'] ?? ''),
                    'day' => (int)($result['day_of_year'] ?? 0),
                    'domain' => $domain,
                ];

            } catch (NodelistImportException $e) {
                $importErrorCode = $e->getErrorCode();
                $importErrorParams = $e->getErrorParams();
            } catch (\Exception $e) {
                $importErrorCode = 'errors.nodelist.import.failed';
                $importErrorParams = [];
            }
        }
        
        $activeNodelist = $this->nodelistManager->getActiveNodelist();
        $stats = $this->nodelistManager->getNodelistStats();
        
        return $this->template->render('nodelist/import.twig', [
            'user' => $user,
            'import_message_code' => $importMessageCode,
            'import_message_params' => $importMessageParams,
            'import_error_code' => $importErrorCode,
            'import_error_params' => $importErrorParams,
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
                case 'map-data':
                    return $this->apiMapData();
                default:
                    $this->respondApiError('errors.nodelist.api.endpoint_not_found', 'API endpoint not found', 404);
            }
        } catch (\Exception $e) {
            $this->respondApiError('errors.nodelist.api.internal_error', 'Failed to process nodelist API request', 500);
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
            $this->respondApiError('errors.nodelist.api.address_required', 'Address parameter required', 400);
            return;
        }
        
        $node = $this->nodelistManager->findNode($address);
        if (!$node) {
            $this->respondApiError('errors.nodelist.api.node_not_found', 'Node not found', 404);
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
            $this->respondApiError('errors.nodelist.api.zone_required', 'Zone parameter required', 400);
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

    /**
     * Return all geocoded nodelist entries for the map view.
     * Only nodes with coordinates are included.
     */
    private function apiMapData()
    {
        $filterZone = $_GET['zone'] ?? '';

        $params = [];
        $where  = ['n.latitude IS NOT NULL', 'n.longitude IS NOT NULL'];

        if ($filterZone !== '') {
            $where[]  = 'n.zone = ?';
            $params[] = (int)$filterZone;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT n.zone, n.net, n.node, n.point, n.keyword_type,
                   n.system_name, n.sysop_name, n.location, n.phone, n.flags,
                   n.domain, n.latitude, n.longitude
            FROM nodelist n
            $whereClause
            ORDER BY n.system_name, n.zone, n.net, n.node
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Group by system_name (case-insensitive). Each group becomes one map marker.
        // The first row in a group provides coordinates and primary sysop/location.
        $groups = [];
        foreach ($rows as $row) {
            $flags = [];
            if (!empty($row['flags'])) {
                $decoded = json_decode($row['flags'], true);
                if (is_array($decoded)) {
                    $flags = $decoded;
                }
            }

            $address = "{$row['zone']}:{$row['net']}/{$row['node']}";
            if ((int)$row['point'] > 0) {
                $address .= ".{$row['point']}";
            }

            $inetHost = $flags['INA'] ?? ($flags['IBN'] ?? null);

            $groupKey = mb_strtolower(trim((string)$row['system_name']));
            if ($groupKey === '') {
                $groupKey = $address; // fallback: unnamed nodes each get their own pin
            }

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'system_name' => $row['system_name'],
                    'sysop_name'  => $row['sysop_name'],
                    'location'    => $row['location'],
                    'latitude'    => (float)$row['latitude'],
                    'longitude'   => (float)$row['longitude'],
                    'networks'    => [],
                    'zones'       => [],
                ];
            }

            $groups[$groupKey]['networks'][] = [
                'address'      => $address,
                'zone'         => (int)$row['zone'],
                'domain'       => $row['domain'] ?? '',
                'keyword_type' => $row['keyword_type'],
                'inet_host'    => $inetHost,
            ];

            if (!in_array((int)$row['zone'], $groups[$groupKey]['zones'], true)) {
                $groups[$groupKey]['zones'][] = (int)$row['zone'];
            }
        }

        $nodes = array_values($groups);
        echo json_encode(['nodes' => $nodes, 'total' => count($nodes)]);
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
        } catch (NodelistImportException $e) {
            $this->cleanupTempFiles($extractDir);
            throw $e;
        } catch (\Exception $e) {
            $this->cleanupTempFiles($extractDir);
            throw new NodelistImportException('errors.nodelist.import.extract_failed');
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
                    throw new NodelistImportException('errors.nodelist.import.zip_extension_missing');
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
                    throw new NodelistImportException('errors.nodelist.import.zip_open_failed');
                }
                break;
                
            default:
                throw new NodelistImportException('errors.nodelist.import.archive_unsupported', ['format' => $type]);
        }
        
        if (!$extractedFile || !file_exists($extractedFile)) {
            throw new NodelistImportException('errors.nodelist.import.archive_nodelist_missing');
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

    private function respondApiError(string $errorCode, string $fallbackMessage, int $statusCode = 400): void
    {
        http_response_code($statusCode);
        $localized = $this->localizedErrorText($errorCode, $fallbackMessage);
        echo json_encode([
            'success' => false,
            'error_code' => $errorCode,
            'error' => $localized
        ]);
    }

    private function localizedErrorText(string $errorCode, string $fallbackMessage): string
    {
        static $translator = null;
        static $localeResolver = null;

        if ($translator === null) {
            $translator = new \BinktermPHP\I18n\Translator();
            $localeResolver = new \BinktermPHP\I18n\LocaleResolver($translator);
        }

        $user = $this->auth->getCurrentUser();
        $preferredLocale = is_array($user) ? (string)($user['locale'] ?? '') : '';
        $resolvedLocale = $localeResolver->resolveLocale($preferredLocale !== '' ? $preferredLocale : null, $user);
        $translated = $translator->translate($errorCode, [], $resolvedLocale, ['errors']);

        return $translated === $errorCode ? $fallbackMessage : $translated;
    }
}
