<?php

namespace BinktermPHP\Qwk\Transport;

interface TransportInterface
{
    public function downloadPacket(array $mailbox): ?string;

    public function uploadPacket(array $mailbox, string $localPacketPath): bool;
}
