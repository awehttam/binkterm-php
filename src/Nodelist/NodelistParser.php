<?php

namespace BinktermPHP\Nodelist;

class NodelistParser
{
    private $currentZone = 1;
    private $currentNet = 0;
    
    public function parseNodelist($filepath,$domain)
    {
        if (!file_exists($filepath)) {
            throw new \Exception("Nodelist file not found: {$filepath}");
        }
        
        $content = file_get_contents($filepath);
        if ($content === false) {
            throw new \Exception("Unable to read nodelist file: {$filepath}");
        }
        
        $metadata = $this->parseHeader($content);
        $lines = explode("\n", $content);
        $nodes = [];
        
        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            
            if (empty($line) || $line[0] === ';') {
                continue;
            }
            
            try {
                $node = $this->parseNodelistLine($line, $lineNumber + 1);
                if ($node) {
                    $nodes[] = $node;
                }
            } catch (\Exception $e) {
                error_log("Error parsing line {$lineNumber}: {$e->getMessage()}");
            }
        }
        
        return [
            'metadata' => $metadata,
            'nodes' => $nodes,
            'domain'=>$domain
        ];
    }
    
    public function parseHeader($content)
    {
        $lines = explode("\n", $content);
        $firstLine = isset($lines[0]) ? trim($lines[0]) : '';
        
        if (empty($firstLine) || $firstLine[0] !== ';') {
            throw new \Exception("Invalid nodelist header format");
        }
        
        preg_match('/;A\s+(\w+)\s+nodelist\s+for\s+(\w+\s+\d+,\s+\d+)\s+--\s+Day\s+(\d+)\s+:\s+(\d+)$/', $firstLine, $matches);
        
        if (!$matches) {
            preg_match('/(\d{3})$/', $firstLine, $crcMatches);
            $crc = isset($crcMatches[1]) ? $crcMatches[1] : '';
            
            return [
                'filename' => basename($content),
                'day_of_year' => 1,
                'release_date' => date('Y-m-d'),
                'crc_checksum' => $crc
            ];
        }
        
        return [
            'filename' => isset($matches[1]) ? $matches[1] : 'NODELIST',
            'day_of_year' => isset($matches[3]) ? (int)$matches[3] : 1,
            'release_date' => isset($matches[2]) ? $this->parseDate($matches[2]) : date('Y-m-d'),
            'crc_checksum' => isset($matches[4]) ? $matches[4] : ''
        ];
    }
    
    public function parseNodelistLine($line, $lineNumber = 0)
    {
        $fields = explode(',', $line);

        if (count($fields) < 6) {
            return null;
        }

        $keywordType = trim($fields[0]);
        $nodeNumber = (int)trim($fields[1]);
        $systemName = $this->cleanField(isset($fields[2]) ? $fields[2] : '');
        $location = $this->cleanField(isset($fields[3]) ? $fields[3] : '');
        $sysopName = $this->cleanField(isset($fields[4]) ? $fields[4] : '');
        $phone = trim(isset($fields[5]) ? $fields[5] : '');
        $baudRate = isset($fields[6]) ? (int)trim($fields[6]) : 0;

        // Flags start at field 7 and continue to the end of the line
        $flagFields = array_slice($fields, 7);
        $flags = !empty($flagFields) ? $this->parseFlags(implode(',', $flagFields)) : [];
        
        switch (strtolower($keywordType)) {
            case 'zone':
                $this->currentZone = $nodeNumber;
                $this->currentNet = $nodeNumber;
                break;
            case 'region':
                $this->currentNet = $nodeNumber;
                break;
            case 'host':
                $this->currentNet = $nodeNumber;
                break;
            case 'hub':
                break;
            case 'pvt':
            case '':
                break;
            default:
                if (is_numeric($keywordType)) {
                    $nodeNumber = (int)$keywordType;
                    $keywordType = '';
                }
                break;
        }
        
        $pointNumber = 0;
        if (strpos($nodeNumber, '.') !== false) {
            list($nodeNumber, $pointNumber) = explode('.', $nodeNumber, 2);
            $nodeNumber = (int)$nodeNumber;
            $pointNumber = (int)$pointNumber;
        }
        
        return [
            'zone' => $this->currentZone,
            'net' => $this->currentNet,
            'node' => $nodeNumber,
            'point' => $pointNumber,
            'keyword_type' => empty($keywordType) ? null : ucfirst(strtolower($keywordType)),
            'system_name' => $systemName,
            'location' => $location,
            'sysop_name' => $sysopName,
            'phone' => $phone,
            'baud_rate' => $baudRate,
            'flags' => json_encode($flags)
        ];
    }
    
    public function parseFlags($flagString)
    {
        $flags = [];
        $flagParts = explode(',', $flagString);
        
        foreach ($flagParts as $flag) {
            $flag = trim($flag);
            if (empty($flag)) continue;
            
            if (strpos($flag, ':') !== false) {
                list($name, $value) = explode(':', $flag, 2);
                $flags[trim($name)] = trim($value);
            } else {
                $flags[trim($flag)] = true;
            }
        }
        
        return $flags;
    }
    
    private function cleanField($field)
    {
        return str_replace('_', ' ', trim($field));
    }
    
    private function parseDate($dateStr)
    {
        try {
            $date = \DateTime::createFromFormat('F j, Y', $dateStr);
            return $date ? $date->format('Y-m-d') : date('Y-m-d');
        } catch (\Exception $e) {
            return date('Y-m-d');
        }
    }
    
    public function validateChecksumAndHeader($content)
    {
        $lines = explode("\n", $content);
        $firstLine = isset($lines[0]) ? trim($lines[0]) : '';
        
        if (empty($firstLine) || $firstLine[0] !== ';') {
            return false;
        }
        
        return true;
    }
}