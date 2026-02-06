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


namespace BinktermPHP;

class WebDoorManifest
{
    public static function getManifestDirectory(): string
    {
        return __DIR__ . '/../public_html/webdoors';
    }

    public static function listManifests(): array
    {
        $dir = self::getManifestDirectory();
        if (!is_dir($dir)) {
            return [];
        }

        $entries = scandir($dir);
        if (!$entries) {
            return [];
        }

        $manifests = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $manifestPath = $dir . '/' . $entry . '/webdoor.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $manifestJson = @file_get_contents($manifestPath);
            $manifest = json_decode($manifestJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($manifest)) {
                continue;
            }

            $game = $manifest['game'] ?? [];
            $gameId = $game['id'] ?? $entry;

            $manifests[] = [
                'id' => $gameId,
                'path' => $entry,
                'manifest' => $manifest
            ];
        }

        return $manifests;
    }
}

