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


namespace BinktermPHP\Admin;

use BinktermPHP\Config;

class AdminDaemonClient
{
    private string $socketTarget;
    private string $secret;
    private $socket;

    public function __construct(?string $socketTarget = null, ?string $secret = null)
    {
        $this->socketTarget = $socketTarget
            ?? (Config::env('ADMIN_DAEMON_SOCKET') ?: $this->getDefaultSocketTarget());
        $this->secret = $secret ?? (string)Config::env('ADMIN_DAEMON_SECRET', '');
    }

    public function processPackets(): array
    {
        return $this->sendCommand('process_packets');
    }

    public function crashmailPoll(): array
    {
        return $this->sendCommand('crashmail_poll');
    }

    public function getLogs(int $lines = 25): array
    {
        return $this->sendCommand('get_logs', ['lines' => $lines]);
    }

    public function binkPoll(string $upstream): array
    {
        return $this->sendCommand('binkp_poll', ['upstream' => $upstream]);
    }

    public function getBbsConfig(): array
    {
        return $this->sendCommand('get_bbs_config');
    }

    public function setBbsConfig(array $config): array
    {
        return $this->sendCommand('set_bbs_config', ['config' => $config]);
    }

    public function getSystemConfig(): array
    {
        return $this->sendCommand('get_system_config');
    }

    public function setSystemConfig(array $config): array
    {
        return $this->sendCommand('set_system_config', ['config' => $config]);
    }

    public function getBinkpConfig(): array
    {
        return $this->sendCommand('get_binkp_config');
    }

    public function setBinkpConfig(array $config): array
    {
        return $this->sendCommand('set_binkp_config', ['config' => $config]);
    }

    public function getFullBinkpConfig(): array
    {
        return $this->sendCommand('get_full_binkp_config');
    }

    public function setFullBinkpConfig(array $config): array
    {
        return $this->sendCommand('set_full_binkp_config', ['config' => $config]);
    }

    public function reloadBinkpConfig(): array
    {
        return $this->sendCommand('reload_binkp_config');
    }

    public function saveLovlyNetConfig(string $json): array
    {
        return $this->sendCommand('save_lovlynet_config', ['json' => $json]);
    }

    public function getWebdoorsConfig(): array
    {
        return $this->sendCommand('get_webdoors_config');
    }

    public function saveWebdoorsConfig(string $json): array
    {
        return $this->sendCommand('save_webdoors_config', ['json' => $json]);
    }

    public function activateWebdoorsConfig(): array
    {
        return $this->sendCommand('activate_webdoors_config');
    }

    public function getDosdoorsConfig(): array
    {
        return $this->sendCommand('get_dosdoors_config');
    }

    public function saveDosdoorsConfig(string $json): array
    {
        return $this->sendCommand('save_dosdoors_config', ['json' => $json]);
    }

    public function getNativeDoorsConfig(): array
    {
        return $this->sendCommand('get_native_doors_config');
    }

    public function saveNativeDoorsConfig(string $json): array
    {
        return $this->sendCommand('save_native_doors_config', ['json' => $json]);
    }

    public function getFileAreaRulesConfig(): array
    {
        return $this->sendCommand('get_filearea_rules');
    }

    public function saveFileAreaRulesConfig(string $json): array
    {
        return $this->sendCommand('save_filearea_rules', ['json' => $json]);
    }

    public function getTaglines(): array
    {
        return $this->sendCommand('get_taglines');
    }

    public function saveTaglines(string $text): array
    {
        return $this->sendCommand('save_taglines', ['text' => $text]);
    }

    public function listCustomTemplates(): array
    {
        return $this->sendCommand('list_custom_templates');
    }

    public function getCustomTemplate(string $path): array
    {
        return $this->sendCommand('get_custom_template', ['path' => $path]);
    }

    public function saveCustomTemplate(string $path, string $content): array
    {
        return $this->sendCommand('save_custom_template', [
            'path' => $path,
            'content' => $content
        ]);
    }

    public function deleteCustomTemplate(string $path): array
    {
        return $this->sendCommand('delete_custom_template', ['path' => $path]);
    }

    public function installCustomTemplate(string $source, bool $overwrite = false): array
    {
        return $this->sendCommand('install_custom_template', [
            'source' => $source,
            'overwrite' => $overwrite
        ]);
    }

    /**
     * Returns the base catalog keys and current overlay overrides for a locale/namespace.
     *
     * @return array{base: array<string,string>, overrides: array<string,string>}
     */
    public function getI18nOverlay(string $locale, string $namespace): array
    {
        return $this->sendCommand('get_i18n_overlay', ['locale' => $locale, 'ns' => $namespace]);
    }

    /**
     * Saves an overlay for a locale/namespace. Pass an empty array to clear all overrides.
     *
     * @param array<string,string> $overrides
     */
    public function saveI18nOverlay(string $locale, string $namespace, array $overrides): array
    {
        return $this->sendCommand('save_i18n_overlay', [
            'locale'    => $locale,
            'ns'        => $namespace,
            'overrides' => $overrides,
        ]);
    }

    public function getAppearanceConfig(): array
    {
        return $this->sendCommand('get_appearance_config');
    }

    public function setAppearanceConfig(array $config): array
    {
        return $this->sendCommand('set_appearance_config', ['config' => $config]);
    }

    public function setSystemNews(string $text): array
    {
        return $this->sendCommand('set_system_news', ['text' => $text]);
    }

    public function setHouseRules(string $text): array
    {
        return $this->sendCommand('set_house_rules', ['text' => $text]);
    }

    public function setLoginSplash(string $text): array
    {
        return $this->sendCommand('set_login_splash', ['text' => $text]);
    }

    public function setRegisterSplash(string $text): array
    {
        return $this->sendCommand('set_register_splash', ['text' => $text]);
    }

    public function listShellArt(): array
    {
        return $this->sendCommand('list_shell_art');
    }

    public function uploadShellArt(string $contentBase64, string $name = '', string $originalName = ''): array
    {
        return $this->sendCommand('upload_shell_art', [
            'content_base64' => $contentBase64,
            'name' => $name,
            'original_name' => $originalName
        ]);
    }

    public function deleteShellArt(string $name): array
    {
        return $this->sendCommand('delete_shell_art', ['name' => $name]);
    }

    /**
     * Write a license payload to data/license.json via the daemon.
     *
     * @param array<string,mixed> $licenseData Already-validated license array with 'payload' and 'signature' keys.
     */
    public function setLicense(array $licenseData): array
    {
        return $this->sendCommand('set_license', ['license' => $licenseData]);
    }

    /**
     * Read config/weather.json (and the example) via the daemon.
     */
    public function getWeatherConfig(): array
    {
        return $this->sendCommand('get_weather_config');
    }

    /**
     * Write config/weather.json via the daemon.
     *
     * @param string $json JSON-encoded weather config
     */
    public function saveWeatherConfig(string $json): array
    {
        return $this->sendCommand('save_weather_config', ['json' => $json]);
    }

    /**
     * Remove data/license.json via the daemon, reverting to Community Edition.
     */
    public function deleteLicense(): array
    {
        return $this->sendCommand('delete_license');
    }

    public function stopServices(): array
    {
        return $this->sendCommand('stop_services');
    }

    /**
     * Request an on-demand virus scan for a specific file.
     *
     * @param int $fileId Database ID of the file to scan
     */
    public function scanFile(int $fileId): array
    {
        return $this->sendCommand('scan_file', ['file_id' => $fileId]);
    }

    /**
     * Write an entry to data/logs/server.log via the admin daemon.
     *
     * This is the correct way for web routes to log application-level events
     * (e.g. "user sent netmail, packet ID xyz") without writing to local files
     * directly, since the daemon owns the log directory exclusively.
     *
     * @param string               $level   Log level string: INFO, WARNING, ERROR, DEBUG
     * @param string               $message Human-readable message
     * @param array<string,scalar> $context Optional structured context (username, packet_id, …)
     */
    public function serverLog(string $level, string $message, array $context = []): array
    {
        if (!isset($context['remote_addr']) && !empty($_SERVER['REMOTE_ADDR'])) {
            $context['remote_addr'] = $_SERVER['REMOTE_ADDR'];
        }

        return $this->sendCommand('server_log', [
            'level'   => strtoupper($level),
            'message' => $message,
            'context' => $context,
        ]);
    }

    /**
     * Convenience static method for one-shot logging via the admin daemon.
     *
     * Constructs a client, sends the log entry, and closes the connection.
     * Falls back to error_log() if the daemon is unreachable.
     *
     * @param string $level   Log level: INFO, WARNING, ERROR, DEBUG
     * @param string $message Message to log
     * @param array<string,scalar> $context Optional structured context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        try {
            $client = new self();
            $client->serverLog($level, $message, $context);
            $client->close();
        } catch (\Exception $e) {
            error_log('FALLBACK [' . strtoupper($level) . '] ' . $message);
        }
    }

    public function getMrcConfig(): array
    {
        return $this->sendCommand('get_mrc_config');
    }

    public function setMrcConfig(array $config): array
    {
        return $this->sendCommand('set_mrc_config', ['config' => $config]);
    }

    public function restartMrcDaemon(): array
    {
        return $this->sendCommand('restart_mrc_daemon');
    }

    /**
     * Run a specific echomail robot by ID via the admin daemon.
     * Runs with --debug so output includes per-message decode details.
     *
     * @param int $robotId
     * @return array ['exit_code' => int, 'stdout' => string, 'stderr' => string]
     */
    public function runEchomailRobot(int $robotId): array
    {
        return $this->sendCommand('run_echomail_robot', ['robot_id' => $robotId]);
    }

    /**
     * Trigger a re-index of an ISO-backed file area.
     *
     * @param int $areaId File area ID
     * @return array Daemon response
     */
    public function reindexIso(int $areaId): array
    {
        return $this->sendCommand('reindex_iso', ['area_id' => $areaId]);
    }

    /**
     * Re-hatch a single file by running file_hatch.php via the admin daemon.
     *
     * @param  int $fileId The files.id to re-hatch
     * @return array{ok:bool, result?:array, error?:string}
     */
    public function rehatchFile(int $fileId): array
    {
        return $this->sendCommand('rehatch_file', ['file_id' => $fileId]);
    }

    public function close(): void
    {
        if ($this->socket && is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    private function connect(): void
    {
        if ($this->secret === '') {
            throw new \RuntimeException('ADMIN_DAEMON_SECRET must be set');
        }

        if ($this->socket && is_resource($this->socket)) {
            return;
        }

        $this->socket = @stream_socket_client($this->socketTarget, $errno, $errstr, 5);
        if (!$this->socket) {
            throw new \RuntimeException("Failed to connect to admin daemon: {$errstr} ({$errno})");
        }

        $this->writeLine(['auth' => $this->secret]);
        $response = $this->readResponse();
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException('Admin daemon auth failed');
        }
    }

    private function sendCommand(string $cmd, array $data = []): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->connect();

                $this->writeLine([
                    'cmd' => $cmd,
                    'data' => $data
                ]);

                $response = $this->readResponse();
                if (!($response['ok'] ?? false)) {
                    $error = $response['error'] ?? 'unknown_error';
                    throw new \RuntimeException("Admin daemon error: {$error}");
                }

                $result = $response['result'] ?? [];
                $this->close();
                return $result;
            } catch (\RuntimeException $e) {
                $this->close();
                if ($attempt === 1) {
                    throw $e;
                }
            }
        }

        return [];
    }

    private function writeLine(array $payload): void
    {
        $written = @fwrite($this->socket, json_encode($payload) . "\n");
        if ($written === false) {
            $this->close();
            throw new \RuntimeException('Admin daemon connection closed while sending request');
        }
    }

    private function readResponse(): array
    {
        $line = @fgets($this->socket);
        if ($line === false) {
            $this->close();
            throw new \RuntimeException('Admin daemon closed connection');
        }

        $data = json_decode(trim($line), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid response from admin daemon');
        }

        return $data;
    }

    private function getDefaultSocketTarget(): string
    {
        return 'tcp://127.0.0.1:9065';
    }
}

