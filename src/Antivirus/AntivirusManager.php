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
 * Orchestrates one or more antivirus scanner backends.
 *
 * Usage:
 *   $av = AntivirusManager::create();
 *   if ($av->isEnabled()) {
 *       $result = $av->scanFile('/path/to/file.zip', $sha256);
 *       // $result['result'] === 'infected' | 'clean' | 'error' | 'skipped'
 *   }
 *
 * To add a new scanner backend, implement ScannerInterface and register it
 * in the create() factory method.
 *
 * Aggregation rules:
 *   - Any scanner returns 'infected'  → aggregate result is 'infected'
 *   - At least one returns 'clean'    → aggregate result is 'clean'
 *   - All return 'error' or 'skipped' → aggregate result is 'skipped'
 *
 * Per-scanner results are available under $result['scanners'][<name>].
 */
class AntivirusManager
{
    /** @var ScannerInterface[] */
    private array $scanners = [];

    /**
     * Register a scanner backend.
     */
    public function addScanner(ScannerInterface $scanner): void
    {
        $this->scanners[$scanner->getName()] = $scanner;
    }

    /**
     * Returns true if at least one registered scanner is enabled.
     */
    public function isEnabled(): bool
    {
        foreach ($this->scanners as $scanner) {
            if ($scanner->isEnabled()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run all enabled scanners against a file and return an aggregated result.
     *
     * The aggregate result has the same shape as ScannerInterface::scanFile()
     * with an additional 'scanners' key containing the per-backend results.
     *
     * @param  string      $filePath
     * @param  string|null $sha256   Pre-computed SHA-256 (passed through to scanners that use it)
     * @return array{scanned: bool, result: string, signature: ?string, error_code: string, error: ?string, scanners: array}
     */
    public function scanFile(string $filePath, ?string $sha256 = null): array
    {
        $perScanner = [];
        $infected   = false;
        $anyClean   = false;
        $signature  = null;

        foreach ($this->scanners as $name => $scanner) {
            if (!$scanner->isEnabled()) {
                $perScanner[$name] = [
                    'scanned'    => false,
                    'result'     => 'skipped',
                    'signature'  => null,
                    'error_code' => '',
                    'error'      => null,
                ];
                continue;
            }

            $r = $scanner->scanFile($filePath, $sha256);
            $perScanner[$name] = $r;

            if (($r['result'] ?? '') === 'infected') {
                $infected  = true;
                $signature = $signature ?? ($r['signature'] ?? null);
            } elseif (($r['result'] ?? '') === 'clean') {
                $anyClean = true;
            }
        }

        if ($infected) {
            $result = 'infected';
        } elseif ($anyClean) {
            $result = 'clean';
        } else {
            $result = 'skipped'; // all scanners were unavailable or errored
        }

        return [
            'scanned'    => $infected || $anyClean,
            'result'     => $result,
            'signature'  => $signature,
            'error_code' => '',
            'error'      => null,
            'scanners'   => $perScanner,
        ];
    }

    /**
     * Factory: create an AntivirusManager with all configured scanner backends.
     *
     * ClamAV is always registered (enabled automatically when clamd is running).
     * VirusTotal is registered only when VIRUSTOTAL_API_KEY is set in .env.
     */
    public static function create(): self
    {
        $manager = new self();
        $manager->addScanner(new ClamavScanner());

        if ((string)Config::env('VIRUSTOTAL_API_KEY', '') !== '') {
            $manager->addScanner(new VirusTotalScanner());
        }

        return $manager;
    }
}
