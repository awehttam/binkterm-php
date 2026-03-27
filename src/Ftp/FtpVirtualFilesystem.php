<?php

namespace BinktermPHP\Ftp;

use BinktermPHP\ActivityTracker;
use BinktermPHP\BbsConfig;
use BinktermPHP\FileAreaManager;
use BinktermPHP\Qwk\QwkHttpController;
use BinktermPHP\UserCredit;

class FtpVirtualFilesystem
{
    private const VIRTUAL_DIRECTORY_INDEX_FILES = ['00INDEX.TXT', 'FILES.BBS'];

    private FileAreaManager $fileAreaManager;
    private QwkHttpController $qwkController;

    public function __construct()
    {
        $this->fileAreaManager = new FileAreaManager();
        $this->qwkController = new QwkHttpController();
    }

    public function normalizePath(string $cwd, string $path): string
    {
        $rawPath = trim($path);
        if ($rawPath === '') {
            return $cwd !== '' ? $cwd : '/';
        }

        $segments = [];
        $input = str_starts_with($rawPath, '/')
            ? explode('/', $rawPath)
            : explode('/', rtrim($cwd, '/') . '/' . $rawPath);

        foreach ($input as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDirectory(array $user, array &$session, string $path): array
    {
        $resolved = $this->normalizePath('/', $path);
        if ($resolved === '/') {
            return $this->listRoot($user);
        }
        if ($this->isAnonymousUser($user)) {
            if ($resolved === '/fileareas') {
                return $this->listFileAreas($user);
            }
            if (!str_starts_with($resolved, '/fileareas/')) {
                return [];
            }
        }
        if ($resolved === '/qwk') {
            return $this->listQwkRoot();
        }
        if ($resolved === '/qwk/download') {
            return $this->listQwkDownload($user);
        }
        if ($resolved === '/qwk/upload') {
            return $this->listQwkUpload($user);
        }
        if ($resolved === '/incoming') {
            return $this->listIncomingAreas($user);
        }
        if ($resolved === '/fileareas') {
            return $this->listFileAreas($user);
        }
        if ($this->isFileAreaDomainPath($user, $resolved)) {
            return $this->listFileAreaDomain($user, $resolved);
        }
        if (str_starts_with($resolved, '/incoming/')) {
            $incomingArea = $this->resolveIncomingArea($user, $resolved);
            if ($incomingArea === null) {
                return [];
            }
            return [];
        }
        if (!str_starts_with($resolved, '/fileareas/')) {
            return [];
        }

        $fileAreaContext = $this->resolveFileAreaDirectory($user, $resolved);
        if ($fileAreaContext === null) {
            return [];
        }

        $entries = [];
        foreach ($this->fileAreaManager->getSubfolders((int)$fileAreaContext['area']['id'], $fileAreaContext['subfolder']) as $subfolder) {
            $entries[] = [
                'name' => $this->getSubfolderDisplayName($subfolder),
                'type' => 'dir',
                'size' => 0,
                'mtime' => time(),
                'description' => $this->normalizeDescription((string)($subfolder['description'] ?? '')),
            ];
        }

        $files = $this->fileAreaManager->getFiles((int)$fileAreaContext['area']['id'], $fileAreaContext['subfolder']);
        foreach ($files as $file) {
            $entries[] = [
                'name' => (string)$file['filename'],
                'type' => 'file',
                'size' => (int)($file['filesize'] ?? 0),
                'mtime' => strtotime((string)($file['created_at'] ?? 'now')) ?: time(),
            ];
        }

        if ($files !== []) {
            foreach ($this->buildVirtualDirectoryIndexEntries($fileAreaContext, $files) as $indexEntry) {
                $entries[] = $indexEntry;
            }
        }

        usort($entries, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });

        return $entries;
    }

    public function isDirectory(array $user, array &$session, string $path): bool
    {
        $resolved = $this->normalizePath('/', $path);
        $anonymous = $this->isAnonymousUser($user);
        if (in_array($resolved, $anonymous ? ['/', '/fileareas'] : ['/', '/qwk', '/qwk/download', '/qwk/upload', '/incoming', '/fileareas'], true)) {
            return true;
        }

        if ($this->isFileAreaDomainPath($user, $resolved)) {
            return $this->isBrowsableFileAreaDomain($user, $resolved);
        }

        if ($anonymous) {
            return $this->resolveFileAreaDirectory($user, $resolved) !== null;
        }

        if (str_starts_with($resolved, '/incoming/')) {
            return $this->resolveIncomingArea($user, $resolved) !== null;
        }

        return $this->resolveFileAreaDirectory($user, $resolved) !== null;
    }

    public function getDirectoryDescription(array $user, array &$session, string $path): ?string
    {
        $resolved = $this->normalizePath('/', $path);
        if (in_array($resolved, ['/', '/qwk', '/qwk/download', '/qwk/upload', '/incoming', '/fileareas'], true)) {
            return null;
        }

        if ($this->isFileAreaDomainPath($user, $resolved)) {
            return null;
        }

        if (str_starts_with($resolved, '/incoming/')) {
            $incomingArea = $this->resolveIncomingArea($user, $resolved);
            if ($incomingArea === null) {
                return null;
            }

            return $this->normalizeDescription((string)($incomingArea['area']['description'] ?? ''));
        }

        if (!str_starts_with($resolved, '/fileareas/')) {
            return null;
        }

        $context = $this->resolveFileAreaDirectory($user, $resolved);
        if ($context === null) {
            return null;
        }

        if (empty($context['subfolder'])) {
            return $this->normalizeDescription((string)($context['area']['description'] ?? ''));
        }

        return $this->normalizeDescription($this->fileAreaManager->getSubfolderLabel((int)$context['area']['id'], (string)$context['subfolder']));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getFileInfo(array $user, array &$session, string $path): ?array
    {
        $resolved = $this->normalizePath('/', $path);

        if (!$this->isAnonymousUser($user) && $this->isQwkDownloadFile($user, $resolved)) {
            $metadata = $this->qwkController->getDownloadMetadata((int)$user['id']);
            $cachedPacket = $session['qwk_download_packet'] ?? null;
            if (is_array($cachedPacket) && !empty($cachedPacket['path']) && file_exists((string)$cachedPacket['path'])) {
                return [
                    'name' => $metadata['filename'],
                    'type' => 'file',
                    'size' => (int)($cachedPacket['filesize'] ?? 0),
                    'mtime' => (int)($cachedPacket['mtime'] ?? time()),
                ];
            }

            return [
                'name' => $metadata['filename'],
                'type' => 'file',
                'size' => 0,
                'mtime' => time(),
            ];
        }

        if (!$this->isAnonymousUser($user) && $this->isQwkUploadFile($resolved)) {
            return [
                'name' => basename($resolved),
                'type' => 'file',
                'size' => 0,
                'mtime' => time(),
            ];
        }

        $fileContext = $this->resolveFileAreaFile($user, $resolved);
        if ($fileContext === null) {
            return null;
        }

        if (($fileContext['virtual_type'] ?? null) === 'generated_text') {
            $content = (string)($fileContext['virtual_content'] ?? '');
            return [
                'name' => (string)$fileContext['file']['filename'],
                'type' => 'file',
                'size' => strlen($content),
                'mtime' => time(),
            ];
        }

        return [
            'name' => (string)$fileContext['file']['filename'],
            'type' => 'file',
            'size' => (int)($fileContext['file']['filesize'] ?? 0),
            'mtime' => strtotime((string)($fileContext['file']['created_at'] ?? 'now')) ?: time(),
        ];
    }

    /**
     * @return array{stream:resource,size:int,mtime:int,cleanup:callable|null}|null
     */
    public function openReadStream(array $user, array &$session, string $path): ?array
    {
        $resolved = $this->normalizePath('/', $path);

        if (!$this->isAnonymousUser($user) && $this->isQwkDownloadFile($user, $resolved)) {
            $packet = $this->ensureQwkDownloadPacket($user, $session);
            $stream = @fopen((string)$packet['path'], 'rb');
            if ($stream === false) {
                return null;
            }

            return [
                'stream' => $stream,
                'size' => (int)$packet['filesize'],
                'mtime' => (int)$packet['mtime'],
                'cleanup' => static function () use ($stream): void {
                    @fclose($stream);
                },
            ];
        }

        $fileContext = $this->resolveFileAreaFile($user, $resolved);
        if ($fileContext === null) {
            return null;
        }

        if (($fileContext['virtual_type'] ?? null) === 'generated_text') {
            $stream = fopen('php://temp', 'r+b');
            if ($stream === false) {
                return null;
            }

            $content = (string)($fileContext['virtual_content'] ?? '');
            fwrite($stream, $content);
            rewind($stream);

            return [
                'stream' => $stream,
                'size' => strlen($content),
                'mtime' => time(),
                'cleanup' => static function () use ($stream): void {
                    @fclose($stream);
                },
            ];
        }

        $storagePath = $this->fileAreaManager->resolveFilePath($fileContext['file']);
        $stream = @fopen($storagePath, 'rb');
        if ($stream === false) {
            return null;
        }

        return [
            'stream' => $stream,
            'size' => (int)($fileContext['file']['filesize'] ?? 0),
            'mtime' => strtotime((string)($fileContext['file']['created_at'] ?? 'now')) ?: time(),
            'cleanup' => static function () use ($stream): void {
                @fclose($stream);
            },
        ];
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function validateUploadTarget(array $user, string $path): array
    {
        if ($this->isAnonymousUser($user)) {
            return ['success' => false, 'message' => 'Anonymous users cannot upload files'];
        }

        $resolved = $this->normalizePath('/', $path);
        if ($this->isQwkUploadFile($resolved)) {
            return ['success' => true, 'message' => 'OK'];
        }

        $incomingArea = $this->resolveIncomingArea($user, $resolved);
        if ($incomingArea !== null) {
            return ['success' => true, 'message' => 'OK'];
        }

        $fileAreaUpload = $this->resolveFileAreaUploadTarget($user, $resolved);
        if ($fileAreaUpload !== null) {
            return ['success' => true, 'message' => 'OK'];
        }

        if (str_starts_with($resolved, '/incoming/')) {
            return ['success' => false, 'message' => 'Upload directory not found or not permitted'];
        }

        if (str_starts_with($resolved, '/fileareas/')) {
            return ['success' => false, 'message' => 'Upload directory not found or not permitted'];
        }

        return ['success' => false, 'message' => 'Uploads are only allowed inside /incoming/<area>, /fileareas/<area>, or /qwk/upload'];
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function importUploadedRep(array $user, string $path, string $tempPath): array
    {
        if ($this->isAnonymousUser($user)) {
            return ['success' => false, 'message' => 'Anonymous users cannot upload REP packets'];
        }

        $resolved = $this->normalizePath('/', $path);
        if (!$this->isQwkUploadFile($resolved)) {
            return ['success' => false, 'message' => 'Uploads are only allowed inside /qwk/upload'];
        }

        $result = $this->qwkController->processUploadedRep([
            'name' => basename($resolved),
            'type' => 'application/octet-stream',
            'tmp_name' => $tempPath,
            'error' => UPLOAD_ERR_OK,
            'size' => (int)(filesize($tempPath) ?: 0),
            '_cleanup_tmp' => false,
        ], (int)$user['id']);

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => implode('; ', $result['errors']) ?: 'REP import failed',
            ];
        }

        return [
            'success' => true,
            'message' => sprintf(
                'REP processed: imported %d, skipped %d',
                (int)$result['imported'],
                (int)$result['skipped']
            ),
        ];
    }

    /**
     * @return array{success:bool,message:string,file_id?:int,status?:string}
     */
    public function storeIncomingUpload(array $user, string $path, string $tempPath): array
    {
        if ($this->isAnonymousUser($user)) {
            return ['success' => false, 'message' => 'Anonymous users cannot upload files'];
        }

        $resolved = $this->normalizePath('/', $path);
        $uploadTarget = $this->resolveIncomingAreaUploadTarget($user, $resolved)
            ?? $this->resolveFileAreaUploadTarget($user, $resolved);
        if ($uploadTarget === null) {
            return ['success' => false, 'message' => 'Uploads are only allowed inside /incoming/<area> or /fileareas/<area>'];
        }

        $fileArea = $uploadTarget['area'];
        $subfolder = $uploadTarget['subfolder'];
        $ownerId = (int)($user['id'] ?? 0);
        $isAdmin = !empty($user['is_admin']);
        $isOwnPrivateArea = !empty($fileArea['is_private']) && (string)($fileArea['tag'] ?? '') === ('PRIVATE_USER_' . $ownerId);
        $initialStatus = ($isAdmin || $isOwnPrivateArea) ? 'approved' : 'pending';
        $filename = (string)$uploadTarget['filename'];
        $shortDescription = $filename;
        $uploadedBy = (string)($user['username'] ?? 'Unknown');

        $uploadCostCharged = false;
        $uploadCost = UserCredit::isEnabled() ? UserCredit::getCreditCost('file_upload', 0) : 0;
        if ($uploadCost > 0) {
            $uploadCostCharged = UserCredit::debit(
                $ownerId,
                $uploadCost,
                'Uploaded file cost: ' . $filename,
                null,
                UserCredit::TYPE_PAYMENT
            );
            if (!$uploadCostCharged) {
                return ['success' => false, 'message' => 'Insufficient credits for file upload'];
            }
        }

        try {
            $targetPath = dirname($tempPath) . DIRECTORY_SEPARATOR . $filename;
            if (!@rename($tempPath, $targetPath)) {
                $targetPath = $tempPath;
            }

            $fileId = $this->fileAreaManager->uploadFileFromPath(
                (int)$fileArea['id'],
                $targetPath,
                $shortDescription,
                '',
                $uploadedBy,
                $ownerId,
                $initialStatus,
                $filename,
                $subfolder
            );

            ActivityTracker::track($ownerId, ActivityTracker::TYPE_FILE_UPLOAD, $fileId, $filename, ['file_area_id' => (int)$fileArea['id']]);

            return [
                'success' => true,
                'message' => $initialStatus === 'pending' ? 'Upload received and queued for approval' : 'Upload stored',
                'file_id' => $fileId,
                'status' => $initialStatus,
            ];
        } catch (\Throwable $e) {
            if ($uploadCostCharged && $uploadCost > 0) {
                UserCredit::credit(
                    $ownerId,
                    $uploadCost,
                    'Refund: File upload failed',
                    null,
                    UserCredit::TYPE_REFUND
                );
            }
            throw $e;
        }
    }

    public function cleanupSession(array &$session): void
    {
        $packet = $session['qwk_download_packet'] ?? null;
        if (!is_array($packet)) {
            return;
        }

        $path = (string)($packet['path'] ?? '');
        if ($path !== '' && file_exists($path)) {
            @unlink($path);
        }

        unset($session['qwk_download_packet']);
    }

    /**
     * @return array<int, array{name:string,type:string,size:int,mtime:int}>
     */
    private function listRoot(array $user): array
    {
        $entries = [];
        if (!$this->isAnonymousUser($user) && BbsConfig::isFeatureEnabled('qwk')) {
            $entries[] = ['name' => 'qwk', 'type' => 'dir', 'size' => 0, 'mtime' => time()];
        }
        if (FileAreaManager::isFeatureEnabled()) {
            $entries[] = ['name' => 'fileareas', 'type' => 'dir', 'size' => 0, 'mtime' => time()];
            if (!$this->isAnonymousUser($user)) {
                $entries[] = ['name' => 'incoming', 'type' => 'dir', 'size' => 0, 'mtime' => time()];
            }
        }
        return $entries;
    }

    /**
     * @return array<int, array{name:string,type:string,size:int,mtime:int}>
     */
    private function listQwkRoot(): array
    {
        if (!BbsConfig::isFeatureEnabled('qwk')) {
            return [];
        }

        return [
            ['name' => 'download', 'type' => 'dir', 'size' => 0, 'mtime' => time()],
            ['name' => 'upload', 'type' => 'dir', 'size' => 0, 'mtime' => time()],
        ];
    }

    /**
     * @return array<int, array{name:string,type:string,size:int,mtime:int}>
     */
    private function listQwkDownload(array $user): array
    {
        if (!BbsConfig::isFeatureEnabled('qwk')) {
            return [];
        }

        $metadata = $this->qwkController->getDownloadMetadata((int)$user['id']);
        return [[
            'name' => $metadata['filename'],
            'type' => 'file',
            'size' => 0,
            'mtime' => time(),
        ]];
    }

    /**
     * @return array<int, array{name:string,type:string,size:int,mtime:int}>
     */
    private function listQwkUpload(array $user): array
    {
        if (!BbsConfig::isFeatureEnabled('qwk')) {
            return [];
        }

        // Present /qwk/upload as an empty drop directory so FTP clients do not
        // treat the synthetic reply target as an already-existing remote file.
        return [];
    }

    /**
     * @return array<int, array{name:string,type:string,size:int,mtime:int}>
     */
    private function listFileAreas(array $user): array
    {
        if (!FileAreaManager::isFeatureEnabled()) {
            return [];
        }

        $entries = [];
        $domains = [];
        foreach ($this->getAccessibleFileAreas($user) as $area) {
            $domain = trim((string)($area['domain'] ?? ''));
            if ($domain === '') {
                $entries[] = [
                    'name' => (string)($area['tag'] ?? ''),
                    'type' => 'dir',
                    'size' => 0,
                    'mtime' => strtotime((string)($area['updated_at'] ?? 'now')) ?: time(),
                    'description' => $this->normalizeDescription((string)($area['description'] ?? '')),
                ];
                continue;
            }

            $key = strtolower($domain);
            if (!isset($domains[$key])) {
                $domains[$key] = [
                    'name' => $domain,
                    'type' => 'dir',
                    'size' => 0,
                    'mtime' => strtotime((string)($area['updated_at'] ?? 'now')) ?: time(),
                ];
            } else {
                $domains[$key]['mtime'] = max(
                    (int)$domains[$key]['mtime'],
                    strtotime((string)($area['updated_at'] ?? 'now')) ?: time()
                );
            }
        }

        foreach ($domains as $domainEntry) {
            $entries[] = $domainEntry;
        }

        usort($entries, static function (array $a, array $b): int {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });

        return $entries;
    }

    /**
     * @return array<int, array{name:string,type:string,size:int,mtime:int,description?:string}>
     */
    private function listFileAreaDomain(array $user, string $path): array
    {
        $domain = $this->extractFileAreaDomainForUser($user, $path);
        if ($domain === null) {
            return [];
        }

        $entries = [];
        foreach ($this->getAccessibleFileAreas($user) as $area) {
            if (strcasecmp((string)($area['domain'] ?? ''), $domain) !== 0) {
                continue;
            }

            $entries[] = [
                'name' => (string)($area['tag'] ?? ''),
                'type' => 'dir',
                'size' => 0,
                'mtime' => strtotime((string)($area['updated_at'] ?? 'now')) ?: time(),
                'description' => $this->normalizeDescription((string)($area['description'] ?? '')),
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });

        return $entries;
    }

    /**
     * @return array<int, array{name:string,type:string,size:int,mtime:int}>
     */
    private function listIncomingAreas(array $user): array
    {
        if (!FileAreaManager::isFeatureEnabled()) {
            return [];
        }

        $entries = [];
        $viewerUserId = $this->getViewerUserId($user);
        foreach ($this->fileAreaManager->getFileAreas('active', $viewerUserId, !empty($user['is_admin'])) as $area) {
            if (!$this->canUploadToArea($area, $user)) {
                continue;
            }

            $entries[] = [
                'name' => $this->buildIncomingAreaKey($area),
                'type' => 'dir',
                'size' => 0,
                'mtime' => strtotime((string)($area['updated_at'] ?? 'now')) ?: time(),
                'description' => $this->normalizeDescription((string)($area['description'] ?? '')),
            ];
        }

        return $entries;
    }

    private function buildAreaKey(array $area): string
    {
        return (string)($area['tag'] ?? '');
    }

    private function buildIncomingAreaKey(array $area): string
    {
        $tag = (string)($area['tag'] ?? '');
        $domain = trim((string)($area['domain'] ?? ''));

        if ($tag === '' || $domain === '') {
            return $tag;
        }

        return $tag . '@' . $domain;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getAccessibleFileAreas(array $user): array
    {
        $entries = [];
        $viewerUserId = $this->getViewerUserId($user);
        foreach ($this->fileAreaManager->getFileAreas('active', $viewerUserId, !empty($user['is_admin'])) as $area) {
            if (!$this->fileAreaManager->canAccessFileArea((int)$area['id'], $viewerUserId, !empty($user['is_admin']))) {
                continue;
            }
            $entries[] = $area;
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFileAreaDirectory(array $user, string $path): ?array
    {
        if (!str_starts_with($path, '/fileareas/')) {
            return null;
        }

        $relative = substr($path, strlen('/fileareas/'));
        if ($relative === false || $relative === '') {
            return null;
        }

        $segments = explode('/', $relative);
        $areaResolution = $this->resolveFileAreaPathArea($user, $segments);
        if ($areaResolution === null) {
            return null;
        }

        $area = $areaResolution['area'];
        $segments = $areaResolution['remaining_segments'];
        $subfolder = $this->resolveSubfolderPath((int)$area['id'], $segments);
        if ($segments && $subfolder === null) {
            return null;
        }

        return [
            'area' => $area,
            'subfolder' => $subfolder,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFileAreaFile(array $user, string $path): ?array
    {
        if (!str_starts_with($path, '/fileareas/')) {
            return null;
        }

        $relative = substr($path, strlen('/fileareas/'));
        if ($relative === false || $relative === '') {
            return null;
        }

        $segments = explode('/', $relative);
        $areaResolution = $this->resolveFileAreaPathArea($user, $segments);
        if ($areaResolution === null || !$areaResolution['remaining_segments']) {
            return null;
        }

        $area = $areaResolution['area'];
        $segments = $areaResolution['remaining_segments'];
        $filename = array_pop($segments);
        if ($filename === null || $filename === '') {
            return null;
        }

        $subfolder = $this->resolveSubfolderPath((int)$area['id'], $segments);
        if ($segments && $subfolder === null) {
            return null;
        }
        $virtualIndex = $this->resolveVirtualDirectoryIndexFile((int)$area['id'], $subfolder, $filename);
        if ($virtualIndex !== null) {
            return [
                'area' => $area,
                'file' => [
                    'filename' => $virtualIndex['filename'],
                    'filesize' => strlen($virtualIndex['content']),
                    'created_at' => date('c'),
                ],
                'subfolder' => $subfolder,
                'virtual_type' => 'generated_text',
                'virtual_content' => $virtualIndex['content'],
            ];
        }

        foreach ($this->fileAreaManager->getFiles((int)$area['id'], $subfolder) as $file) {
            if (strcasecmp((string)$file['filename'], $filename) === 0) {
                return [
                    'area' => $area,
                    'file' => $file,
                    'subfolder' => $subfolder,
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveAreaByKey(array $user, string $areaKey): ?array
    {
        $tag = $areaKey;
        $domain = '';
        if (str_contains($areaKey, '@')) {
            [$tag, $domain] = explode('@', $areaKey, 2);
        }

        $area = $this->fileAreaManager->getFileAreaByTag($tag, $domain);
        if ($area === null || empty($area['is_active'])) {
            return null;
        }

        if (!$this->fileAreaManager->canAccessFileArea((int)$area['id'], $this->getViewerUserId($user), !empty($user['is_admin']))) {
            return null;
        }

        return $area;
    }

    private function isFileAreaDomainPath(array $user, string $path): bool
    {
        $domain = $this->extractFileAreaDomainForUser($user, $path);
        return $domain !== null;
    }

    private function isBrowsableFileAreaDomain(array $user, string $path): bool
    {
        $domain = $this->extractFileAreaDomainForUser($user, $path);
        return $domain !== null;
    }

    private function extractFileAreaDomainForUser(array $user, string $path): ?string
    {
        if (!str_starts_with($path, '/fileareas/')) {
            return null;
        }

        $relative = substr($path, strlen('/fileareas/'));
        if ($relative === false || $relative === '') {
            return null;
        }

        $segments = explode('/', $relative);
        if (count($segments) !== 1) {
            return null;
        }

        $candidate = trim((string)$segments[0]);
        if ($candidate === '') {
            return null;
        }

        foreach ($this->getAccessibleFileAreas($user) as $area) {
            $domain = trim((string)($area['domain'] ?? ''));
            if ($domain !== '' && strcasecmp($domain, $candidate) === 0) {
                return (string)$area['domain'];
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $segments
     * @return array{area:array<string, mixed>,remaining_segments:array<int, string>}|null
     */
    private function resolveFileAreaPathArea(array $user, array $segments): ?array
    {
        if ($segments === []) {
            return null;
        }

        $first = array_shift($segments);
        if ($first === null || $first === '') {
            return null;
        }

        if ($segments !== []) {
            $domainArea = $this->resolveAreaByTagAndDomain($user, (string)$segments[0], $first);
            if ($domainArea !== null) {
                array_shift($segments);
                return [
                    'area' => $domainArea,
                    'remaining_segments' => array_values($segments),
                ];
            }
        }

        $area = $this->resolveAreaByKey($user, $first);
        if ($area === null) {
            return null;
        }

        return [
            'area' => $area,
            'remaining_segments' => array_values($segments),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveAreaByTagAndDomain(array $user, string $tag, string $domain): ?array
    {
        $area = $this->fileAreaManager->getFileAreaByTag($tag, $domain);
        if ($area === null || empty($area['is_active'])) {
            return null;
        }

        if (!$this->fileAreaManager->canAccessFileArea((int)$area['id'], $this->getViewerUserId($user), !empty($user['is_admin']))) {
            return null;
        }

        return $area;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveIncomingArea(array $user, string $path): ?array
    {
        if (!str_starts_with($path, '/incoming/')) {
            return null;
        }

        $relative = substr($path, strlen('/incoming/'));
        if ($relative === false || $relative === '') {
            return null;
        }

        $segments = explode('/', $relative);
        $areaKey = $segments[0] ?? '';
        if ($areaKey === '') {
            return null;
        }

        $area = $this->resolveAreaByKey($user, $areaKey);
        if ($area === null || !$this->canUploadToArea($area, $user)) {
            return null;
        }

        if (count($segments) > 2) {
            return null;
        }

        return ['area' => $area];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveIncomingAreaUploadTarget(array $user, string $path): ?array
    {
        if (!str_starts_with($path, '/incoming/')) {
            return null;
        }

        $relative = substr($path, strlen('/incoming/'));
        if ($relative === false || $relative === '') {
            return null;
        }

        $segments = explode('/', $relative);
        $areaKey = array_shift($segments);
        $filename = array_pop($segments);
        if ($areaKey === null || $areaKey === '' || $filename === null || $filename === '') {
            return null;
        }

        if ($segments) {
            return null;
        }

        $area = $this->resolveAreaByKey($user, $areaKey);
        if ($area === null || !$this->canUploadToArea($area, $user)) {
            return null;
        }

        return [
            'area' => $area,
            'subfolder' => null,
            'filename' => $filename,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveFileAreaUploadTarget(array $user, string $path): ?array
    {
        if (!str_starts_with($path, '/fileareas/')) {
            return null;
        }

        $relative = substr($path, strlen('/fileareas/'));
        if ($relative === false || $relative === '') {
            return null;
        }

        $segments = explode('/', $relative);
        $areaResolution = $this->resolveFileAreaPathArea($user, $segments);
        if ($areaResolution === null || !$areaResolution['remaining_segments']) {
            return null;
        }

        $area = $areaResolution['area'];
        $segments = $areaResolution['remaining_segments'];
        $filename = array_pop($segments);
        if ($filename === null || $filename === '') {
            return null;
        }

        if (!$this->canUploadToArea($area, $user)) {
            return null;
        }

        $subfolder = $this->resolveSubfolderPath((int)$area['id'], $segments);
        if ($segments && $subfolder === null) {
            return null;
        }

        return [
            'area' => $area,
            'subfolder' => $subfolder,
            'filename' => $filename,
        ];
    }

    /**
     * @param array<string, mixed> $subfolder
     */
    private function getSubfolderDisplayName(array $subfolder): string
    {
        $label = trim((string)($subfolder['description'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        return basename((string)($subfolder['subfolder'] ?? ''));
    }

    /**
     * @param array<int, string> $segments
     */
    private function resolveSubfolderPath(int $areaId, array $segments): ?string
    {
        if (!$segments) {
            return null;
        }

        $resolved = null;
        foreach ($segments as $segment) {
            $matched = null;
            foreach ($this->fileAreaManager->getSubfolders($areaId, $resolved) as $candidate) {
                $candidatePath = (string)($candidate['subfolder'] ?? '');
                $candidateBase = basename($candidatePath);
                $candidateDisplay = $this->getSubfolderDisplayName($candidate);
                if (
                    strcasecmp($candidateDisplay, $segment) === 0
                    || strcasecmp($candidateBase, $segment) === 0
                ) {
                    $matched = $candidatePath;
                    break;
                }
            }

            if ($matched === null) {
                return null;
            }

            $resolved = $matched;
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function buildVirtualDirectoryIndexEntries(array $context, array $files): array
    {
        $entries = [];
        foreach (self::VIRTUAL_DIRECTORY_INDEX_FILES as $filename) {
            $content = $this->generateVirtualDirectoryIndexContent($context, $files, $filename);
            $entries[] = [
                'name' => $filename,
                'type' => 'file',
                'size' => strlen($content),
                'mtime' => time(),
            ];
        }

        return $entries;
    }

    private function resolveVirtualDirectoryIndexFile(int $areaId, ?string $subfolder, string $filename): ?array
    {
        foreach (self::VIRTUAL_DIRECTORY_INDEX_FILES as $virtualFilename) {
            if (strcasecmp($virtualFilename, $filename) !== 0) {
                continue;
            }

            $files = $this->fileAreaManager->getFiles($areaId, $subfolder);
            if ($files === []) {
                return null;
            }

            return [
                'filename' => $virtualFilename,
                'content' => $this->generateVirtualDirectoryIndexContent(
                    ['area' => ['id' => $areaId], 'subfolder' => $subfolder],
                    $files,
                    $virtualFilename
                ),
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $files
     */
    private function generateVirtualDirectoryIndexContent(array $context, array $files, string $filename): string
    {
        return strtoupper($filename) === 'FILES.BBS'
            ? $this->generateFilesBbsContent($files)
            : $this->generateZeroZeroIndexContent($context, $files);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function generateFilesBbsContent(array $files): string
    {
        $lines = [];
        foreach ($files as $file) {
            $name = (string)($file['filename'] ?? '');
            $description = $this->normalizeVirtualFileDescription($file);
            $wrapped = $this->wrapText($description, 58);
            $lines[] = $name . '  ' . ($wrapped[0] ?? '');
            for ($i = 1; $i < count($wrapped); $i++) {
                $lines[] = '    ' . $wrapped[$i];
            }
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $files
     */
    private function generateZeroZeroIndexContent(array $context, array $files): string
    {
        $lines = [];
        $directoryLabel = $this->buildVirtualDirectoryLabel($context);
        if ($directoryLabel !== '') {
            $lines[] = 'Directory: ' . $directoryLabel;
            $lines[] = '';
        }

        foreach ($files as $file) {
            $lines[] = 'File: ' . (string)($file['filename'] ?? '');
            $lines[] = '';
            foreach ($this->wrapText($this->normalizeVirtualFileDescription($file), 72) as $line) {
                $lines[] = '    ' . $line;
            }
            $lines[] = '';
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildVirtualDirectoryLabel(array $context): string
    {
        $area = (array)($context['area'] ?? []);
        $areaTag = (string)($area['tag'] ?? '');
        $subfolder = (string)($context['subfolder'] ?? '');
        if ($subfolder === '') {
            return $areaTag;
        }

        return $areaTag . '/' . $subfolder;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function normalizeVirtualFileDescription(array $file): string
    {
        $long = trim((string)($file['long_description'] ?? ''));
        if ($long !== '') {
            return preg_replace('/\r\n?/', "\n", $long) ?? $long;
        }

        $short = trim((string)($file['short_description'] ?? ''));
        if ($short !== '') {
            return $short;
        }

        return (string)($file['filename'] ?? '');
    }

    /**
     * @return array<int, string>
     */
    private function wrapText(string $text, int $width): array
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($normalized === '') {
            return [''];
        }

        $lines = [];
        foreach (explode("\n", $normalized) as $paragraph) {
            $wrapped = wordwrap($paragraph, $width, "\n", false);
            foreach (explode("\n", $wrapped) as $line) {
                $lines[] = rtrim($line);
            }
        }

        return $lines === [] ? [''] : $lines;
    }

    private function canUploadToArea(array $area, array $user): bool
    {
        if ($this->isAnonymousUser($user)) {
            return false;
        }

        if (!$this->fileAreaManager->canAccessFileArea((int)$area['id'], $this->getViewerUserId($user), !empty($user['is_admin']))) {
            return false;
        }

        $uploadPermission = (int)($area['upload_permission'] ?? FileAreaManager::UPLOAD_USERS_ALLOWED);
        if ($uploadPermission === FileAreaManager::UPLOAD_READ_ONLY) {
            return false;
        }
        if ($uploadPermission === FileAreaManager::UPLOAD_ADMIN_ONLY && empty($user['is_admin'])) {
            return false;
        }

        return true;
    }

    private function isQwkDownloadFile(array $user, string $path): bool
    {
        if ($this->isAnonymousUser($user)) {
            return false;
        }

        if (!BbsConfig::isFeatureEnabled('qwk')) {
            return false;
        }

        $metadata = $this->qwkController->getDownloadMetadata((int)$user['id']);
        return $path === '/qwk/download/' . $metadata['filename'];
    }

    private function isQwkUploadFile(string $path): bool
    {
        if (!str_starts_with($path, '/qwk/upload/')) {
            return false;
        }

        $extension = strtolower((string)pathinfo(basename($path), PATHINFO_EXTENSION));
        return in_array($extension, ['rep', 'zip'], true);
    }

    /**
     * @return array{path:string,filename:string,filesize:int,mtime:int}
     */
    private function ensureQwkDownloadPacket(array $user, array &$session): array
    {
        $cachedPacket = $session['qwk_download_packet'] ?? null;
        if (is_array($cachedPacket)) {
            $path = (string)($cachedPacket['path'] ?? '');
            if ($path !== '' && file_exists($path)) {
                return $cachedPacket;
            }
        }

        $packet = $this->qwkController->buildDownloadPacket((int)$user['id']);
        $packet['mtime'] = time();
        $session['qwk_download_packet'] = $packet;
        return $packet;
    }

    private function parentSubfolder(string $subfolder): ?string
    {
        if (!str_contains($subfolder, '/')) {
            return null;
        }

        $parent = dirname($subfolder);
        return $parent === '.' ? null : $parent;
    }

    private function isAnonymousUser(array $user): bool
    {
        return !empty($user['is_anonymous']);
    }

    private function getViewerUserId(array $user): ?int
    {
        if ($this->isAnonymousUser($user)) {
            return null;
        }

        return isset($user['id']) ? (int)$user['id'] : null;
    }

    private function normalizeDescription(string $description): ?string
    {
        $description = trim(preg_replace('/\s+/', ' ', $description) ?? '');
        return $description !== '' ? $description : null;
    }
}
