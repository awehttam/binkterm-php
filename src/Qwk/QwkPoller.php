<?php

namespace BinktermPHP\Qwk;

use BinktermPHP\Qwk\Transport\FtpTransport;
use BinktermPHP\Qwk\Transport\TransportInterface;

class QwkPoller
{
    private QwkUplinkManager $uplinks;
    private QwkInbound $inbound;
    private QwkOutbound $outbound;
    private TransportInterface $transport;

    public function __construct(
        ?QwkUplinkManager $uplinks = null,
        ?QwkInbound $inbound = null,
        ?QwkOutbound $outbound = null,
        ?TransportInterface $transport = null
    ) {
        $this->uplinks = $uplinks ?? new QwkUplinkManager();
        $this->inbound = $inbound ?? new QwkInbound();
        $this->outbound = $outbound ?? new QwkOutbound();
        $this->transport = $transport ?? new FtpTransport($this->uplinks);
    }

    /**
     * @return array<string,mixed>
     */
    public function pollUplink(int $uplinkId): array
    {
        $uplink = $this->uplinks->getById($uplinkId, true);
        if ($uplink === null) {
            throw new \InvalidArgumentException('QWK uplink not found');
        }

        $downloadedPath = null;
        $repPath = null;
        try {
            $stats = ['imported' => 0, 'skipped' => 0, 'uploaded' => false];

            $downloadedPath = $this->transport->downloadPacket($uplink);
            if ($downloadedPath !== null) {
                $importStats = $this->inbound->importPacket($uplinkId, $downloadedPath);
                $stats['imported'] = $importStats['imported'];
                $stats['skipped'] = $importStats['skipped'];
            }

            $repPath = $this->outbound->buildPendingRepPacket($uplink);
            if ($repPath !== null) {
                $stats['uploaded'] = $this->transport->uploadPacket($uplink, $repPath);
                if ($stats['uploaded']) {
                    $this->outbound->markUploaded($uplinkId);
                }
            }

            $this->uplinks->markPollResult($uplinkId, null);
            return array_merge(['success' => true], $stats);
        } catch (\Throwable $e) {
            $this->uplinks->markPollResult($uplinkId, $e->getMessage());
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
        foreach ($this->uplinks->getAll() as $uplink) {
            if (empty($uplink['enabled'])) {
                continue;
            }
            $results[(string)$uplink['name']] = $this->pollUplink((int)$uplink['id']);
        }
        return $results;
    }
}
