<?php

namespace BinktermPHP\Qwk\Transport;

interface TransportInterface
{
    public function downloadPacket(array $uplink): ?string;

    public function uploadPacket(array $uplink, string $localPacketPath): bool;
}
