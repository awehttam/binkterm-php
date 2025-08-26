<?php

namespace BinktermPHP\Nodelist;

use BinktermPHP\Database;
use PDO;
use PDOException;

class NodelistManager
{
    private $db;
    private $parser;
    
    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->parser = new NodelistParser();
    }
    
    public function importNodelist($filepath, $archiveOld = true)
    {
        try {
            $this->db->beginTransaction();
            
            if ($archiveOld) {
                $this->archiveOldNodelist();
            }
            
            $result = $this->parser->parseNodelist($filepath);
            $metadata = $result['metadata'];
            $nodes = $result['nodes'];
            
            $metadataId = $this->insertMetadata($metadata, count($nodes));
            
            $insertedNodes = 0;
            foreach ($nodes as $node) {
                if ($this->insertNode($node)) {
                    $insertedNodes++;
                }
            }
            
            $this->insertNodeFlags($nodes);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'metadata_id' => $metadataId,
                'total_nodes' => count($nodes),
                'inserted_nodes' => $insertedNodes,
                'filename' => $metadata['filename']
            ];
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw new \Exception("Nodelist import failed: " . $e->getMessage());
        }
    }
    
    public function findNode($address)
    {
        $parts = $this->parseAddress($address);
        if (!$parts) {
            return null;
        }
        
        $sql = "SELECT * FROM nodelist WHERE zone = ? AND net = ? AND node = ? AND point = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$parts['zone'], $parts['net'], $parts['node'], $parts['point']]);
        
        $node = $stmt->fetch();
        if (!$node) {
            return null;
        }
        
        // Process the single node result (same as processNodeResults but for single node)
        if ($node['flags']) {
            $node['flags'] = json_decode($node['flags'], true);
        }
        $node['full_address'] = $this->formatAddress($node);
        
        return $node;
    }
    
    public function findNodesByLocation($location)
    {
        $sql = "SELECT * FROM nodelist WHERE location LIKE ? ORDER BY zone, net, node, point";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['%' . $location . '%']);
        
        return $this->processNodeResults($stmt->fetchAll());
    }
    
    public function findNodesBySysop($sysop)
    {
        $sql = "SELECT * FROM nodelist WHERE sysop_name LIKE ? ORDER BY zone, net, node, point";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['%' . $sysop . '%']);
        
        return $this->processNodeResults($stmt->fetchAll());
    }
    
    public function searchNodes($criteria)
    {
        $whereClauses = [];
        $params = [];
        
        if (!empty($criteria['address'])) {
            $parts = $this->parseAddress($criteria['address']);
            if ($parts) {
                $whereClauses[] = "zone = ? AND net = ? AND node = ?";
                $params[] = $parts['zone'];
                $params[] = $parts['net'];
                $params[] = $parts['node'];
                if ($parts['point'] > 0) {
                    $whereClauses[] = "point = ?";
                    $params[] = $parts['point'];
                }
            }
        }
        
        if (!empty($criteria['sysop'])) {
            $whereClauses[] = "sysop_name LIKE ?";
            $params[] = '%' . $criteria['sysop'] . '%';
        }
        
        if (!empty($criteria['location'])) {
            $whereClauses[] = "location LIKE ?";
            $params[] = '%' . $criteria['location'] . '%';
        }
        
        if (!empty($criteria['system_name'])) {
            $whereClauses[] = "system_name LIKE ?";
            $params[] = '%' . $criteria['system_name'] . '%';
        }
        
        if (!empty($criteria['zone'])) {
            $whereClauses[] = "zone = ?";
            $params[] = (int)$criteria['zone'];
        }
        
        if (!empty($criteria['net'])) {
            $whereClauses[] = "net = ?";
            $params[] = (int)$criteria['net'];
        }
        
        if (!empty($criteria['keyword_type'])) {
            $whereClauses[] = "keyword_type = ?";
            $params[] = $criteria['keyword_type'];
        }
        
        $sql = "SELECT * FROM nodelist";
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        $sql .= " ORDER BY zone, net, node, point LIMIT 500";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $this->processNodeResults($stmt->fetchAll());
    }
    
    public function getActiveNodelist()
    {
        $sql = "SELECT * FROM nodelist_metadata WHERE is_active = 1 ORDER BY release_date DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetch();
    }
    
    public function getZones()
    {
        $sql = "SELECT DISTINCT zone FROM nodelist ORDER BY zone";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function getNetsByZone($zone)
    {
        $sql = "SELECT DISTINCT net FROM nodelist WHERE zone = ? ORDER BY net";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$zone]);
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function getNodesByZoneNet($zone, $net)
    {
        $sql = "SELECT * FROM nodelist WHERE zone = ? AND net = ? ORDER BY node, point";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$zone, $net]);
        
        return $this->processNodeResults($stmt->fetchAll());
    }
    
    public function getNodelistStats()
    {
        $sql = "SELECT 
            COUNT(*) as total_nodes,
            COUNT(DISTINCT zone) as total_zones,
            COUNT(DISTINCT CONCAT(zone, ':', net)) as total_nets,
            COUNT(CASE WHEN keyword_type IS NOT NULL THEN 1 END) as special_nodes,
            COUNT(CASE WHEN point > 0 THEN 1 END) as point_nodes
        FROM nodelist";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetch();
    }
    
    public function archiveOldNodelist()
    {
        $sql = "UPDATE nodelist_metadata SET is_active = 0 WHERE is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    private function insertMetadata($metadata, $totalNodes)
    {
        $sql = "INSERT INTO nodelist_metadata (filename, day_of_year, release_date, crc_checksum, total_nodes, is_active) 
                VALUES (?, ?, ?, ?, ?, 1)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $metadata['filename'],
            $metadata['day_of_year'],
            $metadata['release_date'],
            $metadata['crc_checksum'],
            $totalNodes
        ]);
        
        return $this->db->lastInsertId();
    }
    
    private function insertNode($node)
    {
        $sql = "INSERT OR REPLACE INTO nodelist 
                (zone, net, node, point, keyword_type, system_name, location, sysop_name, phone, baud_rate, flags) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $node['zone'],
            $node['net'],
            $node['node'],
            $node['point'],
            $node['keyword_type'],
            $node['system_name'],
            $node['location'],
            $node['sysop_name'],
            $node['phone'],
            $node['baud_rate'],
            $node['flags']
        ]);
    }
    
    private function insertNodeFlags($nodes)
    {
        $this->db->exec("DELETE FROM nodelist_flags");
        
        $sql = "INSERT INTO nodelist_flags (nodelist_id, flag_name, flag_value) VALUES (?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        foreach ($nodes as $node) {
            $nodeId = $this->getNodeId($node);
            if (!$nodeId) continue;
            
            $flags = json_decode($node['flags'], true);
            if (!$flags) continue;
            
            foreach ($flags as $flagName => $flagValue) {
                $stmt->execute([
                    $nodeId,
                    $flagName,
                    is_bool($flagValue) ? ($flagValue ? '1' : '0') : (string)$flagValue
                ]);
            }
        }
    }
    
    private function getNodeId($node)
    {
        $sql = "SELECT id FROM nodelist WHERE zone = ? AND net = ? AND node = ? AND point = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$node['zone'], $node['net'], $node['node'], $node['point']]);
        
        $result = $stmt->fetch();
        return $result ? $result['id'] : null;
    }
    
    private function parseAddress($address)
    {
        if (preg_match('/^(\d+):(\d+)\/(\d+)(?:\.(\d+))?$/', $address, $matches)) {
            return [
                'zone' => (int)$matches[1],
                'net' => (int)$matches[2],
                'node' => (int)$matches[3],
                'point' => isset($matches[4]) ? (int)$matches[4] : 0
            ];
        }
        
        return null;
    }
    
    private function processNodeResults($nodes)
    {
        foreach ($nodes as &$node) {
            if ($node['flags']) {
                $node['flags'] = json_decode($node['flags'], true);
            }
            $node['full_address'] = $this->formatAddress($node);
        }
        
        return $nodes;
    }
    
    private function formatAddress($node)
    {
        $address = $node['zone'] . ':' . $node['net'] . '/' . $node['node'];
        if ($node['point'] > 0) {
            $address .= '.' . $node['point'];
        }
        return $address;
    }
}