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
        $result    = $processor->processRepPacket((string)$file['tmp_name'], $userId);

        return [
            'success'  => $result['imported'] > 0 || count($result['errors']) === 0,
            'imported' => (int)$result['imported'],
            'skipped'  => (int)$result['skipped'],
            'errors'   => $result['errors'],
        ];
    }
}
