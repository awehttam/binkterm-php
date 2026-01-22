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
    
    public function importNodelist($filepath, $domain='',$archiveOld = true)
    {
        try {
            $this->db->beginTransaction();
            
            if ($archiveOld) {
                $this->archiveOldNodelist($domain);
            }
            
            $result = $this->parser->parseNodelist($filepath,$domain);
            $metadata = $result['metadata'];
            $nodes = $result['nodes'];
            
            $metadataId = $this->insertMetadata($domain,$metadata, count($nodes));
            
            $insertedNodes = 0;
            foreach ($nodes as $node) {
                if ($this->insertNode($domain,$node)) {
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
        $sql = "SELECT * FROM nodelist WHERE location ILIKE ? ORDER BY zone, net, node, point";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['%' . $location . '%']);
        
        return $this->processNodeResults($stmt->fetchAll());
    }
    
    public function findNodesBySysop($sysop)
    {
        $sql = "SELECT * FROM nodelist WHERE sysop_name ILIKE ? ORDER BY zone, net, node, point";
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
        
        // Handle general search term (search across multiple fields with OR logic)
        if (!empty($criteria['search_term'])) {
            // Check if search term looks like a FTN address
            $addressParts = $this->parseAddress($criteria['search_term']);
            if ($addressParts) {
                // If it's a valid address format, search by address components
                if ($addressParts['node'] !== null) {
                    // Full address search: zone:net/node
                    $whereClauses[] = "zone = ? AND net = ? AND node = ?";
                    $params[] = $addressParts['zone'];
                    $params[] = $addressParts['net'];
                    $params[] = $addressParts['node'];
                    if ($addressParts['point'] > 0) {
                        $whereClauses[] = "point = ?";
                        $params[] = $addressParts['point'];
                    }
                } else {
                    // Partial address search: zone:net (all nodes in this net)
                    $whereClauses[] = "zone = ? AND net = ?";
                    $params[] = $addressParts['zone'];
                    $params[] = $addressParts['net'];
                }
            } else {
                // Regular text search across multiple fields
                $searchTerm = '%' . $criteria['search_term'] . '%';
                $whereClauses[] = "(sysop_name ILIKE ? OR location ILIKE ? OR system_name ILIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
        }
        
        // Handle specific field searches (these use AND logic with other criteria)
        if (!empty($criteria['sysop'])) {
            $whereClauses[] = "sysop_name ILIKE ?";
            $params[] = '%' . $criteria['sysop'] . '%';
        }
        
        if (!empty($criteria['location'])) {
            $whereClauses[] = "location ILIKE ?";
            $params[] = '%' . $criteria['location'] . '%';
        }
        
        if (!empty($criteria['system_name'])) {
            $whereClauses[] = "system_name ILIKE ?";
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
        $sql = "SELECT * FROM nodelist_metadata WHERE is_active = TRUE ORDER BY release_date DESC LIMIT 1";
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
            COUNT(DISTINCT zone || ':' || net) as total_nets,
            COUNT(CASE WHEN keyword_type IS NOT NULL THEN 1 END) as special_nodes,
            COUNT(CASE WHEN point > 0 THEN 1 END) as point_nodes
        FROM nodelist";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetch();
    }
    
    public function archiveOldNodelist($domain)
    {
        $sql = "UPDATE nodelist_metadata SET is_active = FALSE WHERE is_active = TRUE AND domain=?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$domain]);
        
        return $stmt->rowCount();
    }
    
    private function insertMetadata($domain,$metadata, $totalNodes)
    {
        $sql = "INSERT INTO nodelist_metadata (domain,filename, day_of_year, release_date, crc_checksum, total_nodes, is_active) 
                VALUES (?,?, ?, ?, ?, ?, TRUE)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $domain,
            $this->truncateString($metadata['filename'], 100),
            $metadata['day_of_year'],
            $metadata['release_date'],
            $this->truncateString($metadata['crc_checksum'], 10),
            $totalNodes
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Safely truncate string to specified length
     */
    private function truncateString($string, $maxLength)
    {
        if (empty($string)) {
            return $string;
        }
        
        if (mb_strlen($string) <= $maxLength) {
            return $string;
        }
        
        return mb_substr($string, 0, $maxLength);
    }
    
    private function insertNode($domain,$node)
    {
        $sql = "INSERT INTO nodelist
                (domain,zone, net, node, point, keyword_type, system_name, location, sysop_name, phone, baud_rate, flags)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (zone, net, node, point) DO UPDATE SET
                    keyword_type = EXCLUDED.keyword_type,
                    system_name = EXCLUDED.system_name,
                    location = EXCLUDED.location,
                    sysop_name = EXCLUDED.sysop_name,
                    phone = EXCLUDED.phone,
                    baud_rate = EXCLUDED.baud_rate,
                    flags = EXCLUDED.flags,
                    updated_at = CURRENT_TIMESTAMP";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $domain,
            $node['zone'],
            $node['net'],
            $node['node'],
            $node['point'],
            $this->truncateString($node['keyword_type'], 10),
            $this->truncateString($node['system_name'], 200),
            $this->truncateString($node['location'], 200),
            $this->truncateString($node['sysop_name'], 150),
            $this->truncateString($node['phone'], 100),
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
        // Standard full address format: zone:net/node.point or zone:net/node
        if (preg_match('/^(\d+):(\d+)\/(\d+)(?:\.(\d+))?$/', $address, $matches)) {
            return [
                'zone' => (int)$matches[1],
                'net' => (int)$matches[2],
                'node' => (int)$matches[3],
                'point' => isset($matches[4]) ? (int)$matches[4] : 0
            ];
        }
        
        // Partial formats for search flexibility (without points for primary nodes only)
        // Format: zone:net/node (no point specified, assumes .0)
        if (preg_match('/^(\d+):(\d+)\/(\d+)$/', $address, $matches)) {
            return [
                'zone' => (int)$matches[1],
                'net' => (int)$matches[2], 
                'node' => (int)$matches[3],
                'point' => 0
            ];
        }
        
        // Partial format: zone:net (find all nodes in this net)
        if (preg_match('/^(\d+):(\d+)$/', $address, $matches)) {
            return [
                'zone' => (int)$matches[1],
                'net' => (int)$matches[2],
                'node' => null, // null indicates wildcard search
                'point' => null
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