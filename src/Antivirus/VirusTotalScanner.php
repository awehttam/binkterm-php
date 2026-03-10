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

namespace BinktermPHP\Antivirus;

use BinktermPHP\Config;

/**
 * VirusTotal API v3 scanner backend.
 *
 * Performs a hash lookup first; only uploads the file when its hash is not
 * already in the VirusTotal database. Files larger than 32 MB are hash-checked
 * only (no upload). Analysis is polled until complete or POLL_TIMEOUT is reached.
 *
 * .env configuration:
 *   VIRUSTOTAL_API_KEY   API key (required; scanning disabled when absent)
 *
 * Free-tier limits: 4 requests/minute, 500/day, 32 MB max upload.
 */
class VirusTotalScanner implements ScannerInterface
{
    private const API_BASE      = 'https://www.virustotal.com/api/v3';
    private const MAX_FILE_SIZE = 32 * 1024 * 1024; // 32 MB
    private const POLL_INTERVAL = 5;   // seconds between polls
    private const POLL_TIMEOUT  = 90;  // seconds before giving up

    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? (string)Config::env('VIRUSTOTAL_API_KEY', '');
    }

    public function getName(): string
    {
        return 'virustotal';
    }

    public function isEnabled(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Scan a file via VirusTotal.
     *
     * @param  string      $filePath
     * @param  string|null $sha256   Pre-computed SHA-256 (avoids re-computation)
     * @return array
     */
    public function scanFile(string $filePath, ?string $sha256 = null): array
    {
        if (!$this->isEnabled()) {
            return $this->result(false, 'skipped', null,
                'errors.virustotal.not_configured', 'VirusTotal API key not configured');
        }

        if (!file_exists($filePath)) {
            return $this->result(false, 'error', null,
                'errors.virus_scanner.file_not_found', 'File not found');
        }

        $hash = $sha256 ?? hash_file('sha256', $filePath);

        // Step 1: hash lookup — returns immediately if VT already knows the file
        $known = $this->hashLookup($hash);
        if ($known !== null) {
            return $known;
        }

        // Step 2: upload if within size limit
        if (filesize($filePath) > self::MAX_FILE_SIZE) {
            return $this->result(false, 'skipped', null,
                'errors.virustotal.file_too_large',
                'File exceeds 32 MB VirusTotal upload limit; hash not found in database');
        }

        $analysisId = $this->uploadFile($filePath);
        if ($analysisId === null) {
            return $this->result(false, 'error', null,
                'errors.virustotal.upload_failed', 'Failed to upload file to VirusTotal');
        }

        // Step 3: poll for completed analysis
        return $this->pollAnalysis($analysisId);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Look up a file by SHA-256. Returns null when the hash is not found in VT.
     */
    private function hashLookup(string $hash): ?array
    {
        $response = $this->apiGet("/files/{$hash}");

        if ($response === null) {
            return null; // Network/decode error — proceed to upload
        }

        if (isset($response['error'])) {
            if (($response['error']['code'] ?? '') === 'NotFoundError') {
                return null; // Unknown file — caller should upload
            }
            return $this->result(false, 'error', null,
                'errors.virustotal.api_error',
                $response['error']['message'] ?? 'VirusTotal API error');
        }

        return $this->parseFileData($response['data'] ?? []);
    }

    /**
     * Upload a file and return the VT analysis ID.
     */
    private function uploadFile(string $filePath): ?string
    {
        $ch = curl_init(self::API_BASE . '/files');
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['file' => new \CURLFile($filePath)],
            CURLOPT_HTTPHEADER     => ['x-apikey: ' . $this->apiKey, 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 120,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $httpCode < 200 || $httpCode >= 300) {
            error_log("[VirusTotal] Upload failed: HTTP {$httpCode}");
            return null;
        }

        $data = json_decode((string)$body, true);
        return $data['data']['id'] ?? null;
    }

    /**
     * Poll /analyses/{id} until completed or timeout.
     */
    private function pollAnalysis(string $analysisId): array
    {
        $deadline = time() + self::POLL_TIMEOUT;

        while (time() < $deadline) {
            $response = $this->apiGet("/analyses/{$analysisId}");

            if ($response === null || isset($response['error'])) {
                return $this->result(false, 'error', null,
                    'errors.virustotal.api_error', 'VirusTotal API error while polling');
            }

            $attrs = $response['data']['attributes'] ?? [];
            if (($attrs['status'] ?? '') === 'completed') {
                return $this->parseAnalysisAttributes($attrs);
            }

            sleep(self::POLL_INTERVAL);
        }

        return $this->result(false, 'pending', null,
            'errors.virustotal.analysis_pending', 'Analysis still in progress; re-scan later');
    }

    /**
     * Parse a result from GET /files/{hash}.
     */
    private function parseFileData(array $data): array
    {
        $attrs      = $data['attributes'] ?? [];
        $stats      = $attrs['last_analysis_stats'] ?? [];
        $detections = (int)($stats['malicious'] ?? 0) + (int)($stats['suspicious'] ?? 0);
        $id         = $data['id'] ?? null;
        $permalink  = $id ? "https://www.virustotal.com/gui/file/{$id}" : null;
        $signature  = $this->topDetectionName($attrs['last_analysis_results'] ?? []) ?: null;

        $result = $detections > 0 ? 'infected' : 'clean';
        return $this->result(true, $result, $signature, '', '', $permalink);
    }

    /**
     * Parse a result from GET /analyses/{id}.
     */
    private function parseAnalysisAttributes(array $attrs): array
    {
        $stats      = $attrs['stats'] ?? [];
        $detections = (int)($stats['malicious'] ?? 0) + (int)($stats['suspicious'] ?? 0);
        $sha256     = $attrs['sha256'] ?? null;
        $permalink  = $sha256 ? "https://www.virustotal.com/gui/file/{$sha256}" : null;
        $signature  = $this->topDetectionName($attrs['results'] ?? []) ?: null;

        $result = $detections > 0 ? 'infected' : 'clean';
        return $this->result(true, $result, $signature, '', '', $permalink);
    }

    /**
     * Return the most commonly reported malicious/suspicious detection name.
     */
    private function topDetectionName(array $results): string
    {
        $names = [];
        foreach ($results as $detail) {
            if (in_array($detail['category'] ?? '', ['malicious', 'suspicious'], true)) {
                $name = trim($detail['result'] ?? '');
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        if (empty($names)) {
            return '';
        }

        $counts = array_count_values($names);
        arsort($counts);
        return (string)array_key_first($counts);
    }

    /**
     * GET request to the VT API v3.
     */
    private function apiGet(string $path): ?array
    {
        $ch = curl_init(self::API_BASE . $path);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['x-apikey: ' . $this->apiKey, 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return null;
        }

        if ($httpCode === 429) {
            error_log('[VirusTotal] Rate limit exceeded (429)');
            return ['error' => ['code' => 'QuotaExceededError', 'message' => 'API quota exceeded']];
        }

        $data = json_decode((string)$body, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Build a normalised result array.
     */
    private function result(
        bool $scanned,
        string $result,
        ?string $signature,
        string $errorCode,
        string $error = '',
        ?string $permalink = null
    ): array {
        return [
            'scanned'    => $scanned,
            'result'     => $result,
            'signature'  => $signature,
            'error_code' => $errorCode,
            'error'      => $error ?: null,
            'permalink'  => $permalink,
        ];
    }
}
