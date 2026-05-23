<?php

namespace BinktermPHP\Qwk;

use BinktermPHP\Qwk\Transport\FtpTransport;
use BinktermPHP\Qwk\Transport\TransportInterface;

class QwkPoller
{
    private QwkMailboxManager $mailboxes;
    private QwkInbound $inbound;
    private QwkOutbound $outbound;
    private TransportInterface $transport;
    private bool $dryRun = false;
    private bool $preserveDebugArtifacts = false;
    /** @var callable|null */
    private $logger = null;

    public function __construct(
        ?QwkMailboxManager $mailboxes = null,
        ?QwkInbound $inbound = null,
        ?QwkOutbound $outbound = null,
        ?TransportInterface $transport = null
    ) {
        $this->mailboxes = $mailboxes ?? new QwkMailboxManager();
        $this->inbound = $inbound ?? new QwkInbound();
        $this->outbound = $outbound ?? new QwkOutbound();
        $this->transport = $transport ?? new FtpTransport($this->mailboxes);
    }

    public function setLogger(?callable $logger): void
    {
        $this->logger = $logger;
        $this->inbound->setLogger($logger);
        $this->outbound->setLogger($logger);
        if (method_exists($this->transport, 'setLogger')) {
            $this->transport->setLogger($logger);
        }
    }

    public function setPreserveDebugArtifacts(bool $preserveDebugArtifacts): void
    {
        $this->preserveDebugArtifacts = $preserveDebugArtifacts;
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    /**
     * @return array<string,mixed>
     */
    public function pollMailbox(int $mailboxId): array
    {
        $mailbox = $this->mailboxes->getById($mailboxId, true);
        if ($mailbox === null) {
            throw new \InvalidArgumentException('QWK mailbox not found');
        }

        $this->log('INFO', sprintf(
            'Polling mailbox %d (%s) via %s:%d',
            $mailboxId,
            (string)($mailbox['name'] ?? $mailbox['bbs_id'] ?? 'unknown'),
            (string)($mailbox['host'] ?? ''),
            (int)($mailbox['port'] ?? 21)
        ));

        $downloadedPath = null;
        $repPath = null;
        try {
            $stats = ['imported' => 0, 'skipped' => 0, 'uploaded' => false, 'dry_run' => $this->dryRun];

            $downloadedPath = $this->transport->downloadPacket($mailbox);
            if ($downloadedPath !== null) {
                $size = @filesize($downloadedPath);
                $this->log('DEBUG', sprintf(
                    'Downloaded QWK packet to %s%s',
                    $downloadedPath,
                    $size !== false ? sprintf(' (%d bytes)', $size) : ''
                ));
                $this->preservePacketArtifact($downloadedPath, $mailbox, 'download', 'qwk');
                $importStats = $this->inbound->importPacket($mailboxId, $downloadedPath);
                $stats['imported'] = $importStats['imported'];
                $stats['skipped'] = $importStats['skipped'];
                $this->log('INFO', sprintf(
                    'Imported QWK packet: %d imported, %d skipped',
                    $stats['imported'],
                    $stats['skipped']
                ));
            } else {
                $this->log('DEBUG', 'No QWK packet available for download');
            }

            $repPath = $this->outbound->buildPendingRepPacket($mailbox);
            if ($repPath !== null) {
                $repSize = @filesize($repPath);
                $this->log('DEBUG', sprintf(
                    'Built REP packet at %s%s',
                    $repPath,
                    $repSize !== false ? sprintf(' (%d bytes)', $repSize) : ''
                ));
                $this->preservePacketArtifact($repPath, $mailbox, 'upload', 'rep');
                if ($this->dryRun) {
                    $this->log('INFO', 'Dry run enabled: skipping REP upload and leaving outbound messages queued');
                } else {
                    $stats['uploaded'] = $this->transport->uploadPacket($mailbox, $repPath);
                    if ($stats['uploaded']) {
                        $this->outbound->markUploaded($mailboxId);
                        $this->log('INFO', 'Uploaded REP packet and marked outbound messages as sent');
                    } else {
                        $this->log('WARNING', 'REP packet upload failed');
                    }
                }
            } else {
                $this->log('DEBUG', 'No pending outbound REP messages for this mailbox');
            }

            $this->mailboxes->markPollResult($mailboxId, null);
            $this->log('INFO', 'Mailbox poll completed successfully');
            return array_merge(['success' => true], $stats);
        } catch (\Throwable $e) {
            $this->mailboxes->markPollResult($mailboxId, $e->getMessage());
            $this->log('ERROR', 'Mailbox poll failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            if ($downloadedPath !== null && is_file($downloadedPath)) {
                @unlink($downloadedPath);
            }
            if ($repPath !== null && is_file($repPath)) {
                @unlink($repPath);
            }
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function pollAllEnabled(): array
    {
        $results = [];
        foreach ($this->mailboxes->getAll() as $mailbox) {
            if (empty($mailbox['enabled'])) {
                continue;
            }
            $results[(string)$mailbox['name']] = $this->pollMailbox((int)$mailbox['id']);
        }
        return $results;
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message);
        }
    }

    /**
     * @param array<string,mixed> $mailbox
     */
    private function preservePacketArtifact(string $sourcePath, array $mailbox, string $direction, string $extension): void
    {
        if (!$this->preserveDebugArtifacts || !is_file($sourcePath)) {
            return;
        }

        $directory = getcwd() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'qwk-debug';
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            $this->log('WARNING', 'Failed to create debug packet directory: ' . $directory);
            return;
        }

        $timestamp = (new \DateTimeImmutable('now'))->format('Y-m-d_H-i-s');
        $mailboxLabel = (string)($mailbox['name'] ?? $mailbox['bbs_id'] ?? $mailbox['id'] ?? 'mailbox');
        $mailboxLabel = preg_replace('/[^A-Za-z0-9_-]+/', '_', $mailboxLabel) ?: 'mailbox';
        $destination = $directory . DIRECTORY_SEPARATOR
            . sprintf('%s_%s_%s.%s', $timestamp, $mailboxLabel, $direction, $extension);

        if (@copy($sourcePath, $destination)) {
            $this->log('DEBUG', 'Saved debug packet copy to ' . $destination);
            return;
        }

        $this->log('WARNING', 'Failed to save debug packet copy to ' . $destination);
    }
}
