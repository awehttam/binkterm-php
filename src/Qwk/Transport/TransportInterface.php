<?php

namespace BinktermPHP\Qwk\Transport;

/**
 * Transport contract for inter-BBS QWK mailbox exchange.
 *
 * Implementations download remote `.QWK` packets and upload `.REP` packets for
 * mailbox-based networking between BBSes.
 *
 * Used by: Inter-BBS
 */
interface TransportInterface
{
    public function downloadPacket(array $mailbox): ?string;

    public function uploadPacket(array $mailbox, string $localPacketPath): bool;
}
