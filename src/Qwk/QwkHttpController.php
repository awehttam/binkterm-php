<?php

namespace BinktermPHP\Qwk;

use BinktermPHP\BbsConfig;
use BinktermPHP\UserMeta;

/**
 * Shared QWK HTTP helpers used by both session-authenticated API routes and
 * HTTP Basic Auth endpoints.
 */
class QwkHttpController
{
    /**
     * Resolve a REP upload from the current HTTP request.
     *
     * Supports either a multipart form upload in the "rep" field or a raw
     * request body with the filename supplied by ?rep=, X-File-Name, or
     * Content-Disposition.
     *
     * @return array<string,mixed>
     */
    public function getUploadedRepFromRequest(): array
    {
        if (!empty($_FILES['rep']) && is_array($_FILES['rep'])) {
            return $_FILES['rep'];
        }

        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody) || $rawBody === '') {
            return [];
        }

        $filename = $this->resolveUploadFilename();
        if ($filename === '') {
            $filename = 'upload.rep';
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'qwkrep_');
        if ($tmpPath === false) {
            throw new \RuntimeException('Failed to create temporary file for REP upload.');
        }

        if (file_put_contents($tmpPath, $rawBody) === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Failed to store uploaded REP payload.');
        }

        return [
            'name' => $filename,
            'type' => (string)($_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream'),
            'tmp_name' => $tmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($rawBody),
            '_cleanup_tmp' => true,
        ];
    }

    /**
     * Return the filename metadata for this user's QWK packet without building it.
     *
     * @param int $userId
     * @return array{bbs_id:string,filename:string,reply_filename:string}
     */
    public function getDownloadMetadata(int $userId): array
    {
        if (!BbsConfig::isFeatureEnabled('qwk')) {
            throw new \DomainException('QWK offline mail is not enabled on this system.');
        }

        $builder = new QwkBuilder();
        $bbsId = $builder->getBbsId();

        return [
            'bbs_id' => $bbsId,
            'filename' => $bbsId . '.QWK',
            'reply_filename' => $bbsId . '.REP',
        ];
    }

    /**
     * Build a QWK packet and return the generated archive details.
     *
     * @param int $userId
     * @return array{path:string,filename:string,filesize:int}
     */
    public function buildDownloadPacket(int $userId): array
    {
        if (!BbsConfig::isFeatureEnabled('qwk')) {
            throw new \DomainException('QWK offline mail is not enabled on this system.');
        }

        $meta   = new UserMeta();
        $format = $_GET['format'] ?? $meta->getValue($userId, 'qwk_format') ?? 'qwk';
        $qwke   = ($format === 'qwke');
        $meta->setValue($userId, 'qwk_format', $qwke ? 'qwke' : 'qwk');

        $hardCap        = QwkBuilder::MAX_MESSAGES_HARD_CAP;
        $savedLimit     = (int)($meta->getValue($userId, 'qwk_limit') ?? 2500);
        $requestedLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : $savedLimit;
        $limit          = max(1, min($hardCap, $requestedLimit));
        $meta->setValue($userId, 'qwk_limit', $limit);

        $builder  = new QwkBuilder();
        $zipPath  = $builder->buildPacket($userId, $qwke, $limit);
        $bbsId    = $builder->getBbsId();
        $filename = $bbsId . '.QWK';
        $filesize = (int)filesize($zipPath);

        return [
            'path' => $zipPath,
            'filename' => $filename,
            'filesize' => $filesize,
        ];
    }

    /**
     * Validate and import an uploaded REP packet.
     *
     * @param array<string,mixed> $file
     * @param int $userId
     * @return array{success:bool,imported:int,skipped:int,errors:array}
     */
    public function processUploadedRep(array $file, int $userId): array
    {
        if (!BbsConfig::isFeatureEnabled('qwk')) {
            throw new \DomainException('QWK offline mail is not enabled on this system.');
        }

        if (empty($file)) {
            throw new \InvalidArgumentException('No REP file received. Send the file in the "rep" field.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('File upload error code: ' . ($file['error'] ?? 'unknown'));
        }

        $ext = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['rep', 'zip'], true)) {
            throw new \InvalidArgumentException('Please upload a .REP or .ZIP file.');
        }

        $processor = new RepProcessor();

        try {
            $result = $processor->processRepPacket((string)$file['tmp_name'], $userId);
        } finally {
            if (!empty($file['_cleanup_tmp']) && !empty($file['tmp_name']) && is_string($file['tmp_name'])) {
                @unlink($file['tmp_name']);
            }
        }

        return [
            'success'  => $result['imported'] > 0 || count($result['errors']) === 0,
            'imported' => (int)$result['imported'],
            'skipped'  => (int)$result['skipped'],
            'errors'   => $result['errors'],
        ];
    }

    private function resolveUploadFilename(): string
    {
        $candidates = [];

        if (!empty($_GET['rep']) && is_string($_GET['rep'])) {
            $candidates[] = $_GET['rep'];
        }

        if (!empty($_SERVER['HTTP_X_FILE_NAME']) && is_string($_SERVER['HTTP_X_FILE_NAME'])) {
            $candidates[] = $_SERVER['HTTP_X_FILE_NAME'];
        }

        if (!empty($_SERVER['HTTP_CONTENT_DISPOSITION']) && is_string($_SERVER['HTTP_CONTENT_DISPOSITION'])) {
            if (preg_match('/filename\\*?=(?:UTF-8\'\'|")?([^";]+)/i', $_SERVER['HTTP_CONTENT_DISPOSITION'], $matches)) {
                $candidates[] = rawurldecode(trim($matches[1], "\"' "));
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }

            $basename = basename(str_replace('\\', '/', $candidate));
            $basename = str_replace(["\r", "\n", "\0"], '', $basename);
            if ($basename !== '') {
                return $basename;
            }
        }

        return '';
    }
}
