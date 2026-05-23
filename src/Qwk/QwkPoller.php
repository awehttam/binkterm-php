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

    /**
     * @return array<string,mixed>
     */
    public function pollMailbox(int $mailboxId): array
    {
        $mailbox = $this->mailboxes->getById($mailboxId, true);
        if ($mailbox === null) {
            throw new \InvalidArgumentException('QWK mailbox not found');
        }

        $downloadedPath = null;
        $repPath = null;
        try {
            $stats = ['imported' => 0, 'skipped' => 0, 'uploaded' => false];

            $downloadedPath = $this->transport->downloadPacket($mailbox);
            if ($downloadedPath !== null) {
                $importStats = $this->inbound->importPacket($mailboxId, $downloadedPath);
                $stats['imported'] = $importStats['imported'];
                $stats['skipped'] = $importStats['skipped'];
            }

            $repPath = $this->outbound->buildPendingRepPacket($mailbox);
            if ($repPath !== null) {
                $stats['uploaded'] = $this->transport->uploadPacket($mailbox, $repPath);
                if ($stats['uploaded']) {
                    $this->outbound->markUploaded($mailboxId);
                }
            }

            $this->mailboxes->markPollResult($mailboxId, null);
            return array_merge(['success' => true], $stats);
        } catch (\Throwable $e) {
            $this->mailboxes->markPollResult($mailboxId, $e->getMessage());
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
}
