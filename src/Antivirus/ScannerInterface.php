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

/**
 * Contract for antivirus scanner backends.
 *
 * Each scanner backend (ClamAV, VirusTotal, etc.) must implement this interface.
 * Scanners are registered with AntivirusManager, which runs all enabled backends
 * and aggregates their results.
 *
 * Return array shape from scanFile():
 * [
 *   'scanned'    => bool,          // true if the scanner actually ran
 *   'result'     => string,        // 'clean' | 'infected' | 'error' | 'skipped'
 *   'signature'  => string|null,   // virus/malware name when infected
 *   'error_code' => string,        // i18n key for errors (empty string when clean)
 *   'error'      => string|null,   // human-readable error description
 * ]
 */
interface ScannerInterface
{
    /**
     * Human-readable name used in logs and aggregated result metadata.
     */
    public function getName(): string;

    /**
     * Returns true if this scanner is configured and operational.
     * AntivirusManager skips scanners that are not enabled.
     */
    public function isEnabled(): bool;

    /**
     * Scan a file and return a result array.
     *
     * @param  string      $filePath  Absolute path to the file to scan
     * @param  string|null $sha256    Pre-computed SHA-256 hash (optional optimisation)
     * @return array{scanned: bool, result: string, signature: ?string, error_code: string, error: ?string}
     */
    public function scanFile(string $filePath, ?string $sha256 = null): array;
}
