<?php

namespace BinktermPHP\Ftp;

use BinktermPHP\ActivityTracker;
use BinktermPHP\BbsConfig;
use BinktermPHP\FileAreaManager;
use BinktermPHP\Qwk\QwkHttpController;
use BinktermPHP\UserCredit;

class FtpVirtualFilesystem
{
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
                'name' => basename((string)$subfolder['subfolder']),
                'type' => 'dir',
                'size' => 0,
                'mtime' => time(),
                'description' => $this->normalizeDescription((string)($subfolder['description'] ?? '')),
            ];
        }

        foreach ($this->fileAreaManager->getFiles((int)$fileAreaContext['area']['id'], $fileAreaContext['subfolder']) as $file) {
            $entries[] = [
                'name' => (string)$file['filename'],
                'type' => 'file',
                'size' => (int)($file['filesize'] ?? 0),
                'mtime' => strtotime((string)($file['created_at'] ?? 'now')) ?: time(),
            ];
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
        $incomingArea = $this->resolveIncomingArea($user, $resolved);
        if ($incomingArea === null) {
            return ['success' => false, 'message' => 'Uploads are only allowed inside /incoming/<area>'];
        }

        $fileArea = $incomingArea['area'];
        $ownerId = (int)($user['id'] ?? 0);
        $isAdmin = !empty($user['is_admin']);
        $isOwnPrivateArea = !empty($fileArea['is_private']) && (string)($fileArea['tag'] ?? '') === ('PRIVATE_USER_' . $ownerId);
        $initialStatus = ($isAdmin || $isOwnPrivateArea) ? 'approved' : 'pending';
        $filename = basename($resolved);
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
                $filename
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

        $metadata = $this->qwkController->getDownloadMetadata((int)$user['id']);
        return [[
            'name' => $metadata['reply_filename'],
            'type' => 'file',
            'size' => 0,
            'mtime' => time(),
        ]];
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
        $viewerUserId = $this->getViewerUserId($user);
        foreach ($this->fileAreaManager->getFileAreas('active', $viewerUserId, !empty($user['is_admin'])) as $area) {
            if (!$this->fileAreaManager->canAccessFileArea((int)$area['id'], $viewerUserId, !empty($user['is_admin']))) {
                continue;
            }

            $entries[] = [
                'name' => $this->buildAreaKey($area),
                'type' => 'dir',
                'size' => 0,
                'mtime' => strtotime((string)($area['updated_at'] ?? 'now')) ?: time(),
                'description' => $this->normalizeDescription((string)($area['description'] ?? '')),
            ];
        }

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
                'name' => $this->buildAreaKey($area),
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
        $areaKey = array_shift($segments);
        if ($areaKey === null || $areaKey === '') {
            return null;
        }

        $area = $this->resolveAreaByKey($user, $areaKey);
        if ($area === null) {
            return null;
        }

        $subfolder = $segments ? implode('/', $segments) : null;
        if ($subfolder !== null) {
            $valid = false;
            foreach ($this->fileAreaManager->getSubfolders((int)$area['id'], $this->parentSubfolder($subfolder)) as $candidate) {
                if ((string)$candidate['subfolder'] === $subfolder) {
                    $valid = true;
                    break;
                }
            }
            if (!$valid) {
                return null;
            }
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
        $areaKey = array_shift($segments);
        if ($areaKey === null || $areaKey === '' || !$segments) {
            return null;
        }

        $filename = array_pop($segments);
        if ($filename === null || $filename === '') {
            return null;
        }

        $area = $this->resolveAreaByKey($user, $areaKey);
        if ($area === null) {
            return null;
        }

        $subfolder = $segments ? implode('/', $segments) : null;
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
