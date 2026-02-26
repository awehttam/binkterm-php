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
                // Don't set currentNet here - wait for Net/Host/Region line
                // Zone coordinators are always node 0
                $nodeNumber = 0;
                break;
            case 'net':
                $this->currentNet = $nodeNumber;
                // Network coordinators are always node 0
                $nodeNumber = 0;
                break;
            case 'region':
                $this->currentNet = $nodeNumber;
                // Region coordinators are always node 0
                $nodeNumber = 0;
                break;
            case 'host':
                $this->currentNet = $nodeNumber;
                // Network coordinators (hosts) are always node 0
                $nodeNumber = 0;
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
