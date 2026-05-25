<?php

namespace BinktermPHP\Qwk;

/**
 * Parsed QWK-format message value object.
 *
 * Used as the in-memory representation of one message extracted from an
 * inter-BBS QWK packet.
 *
 * Used by: Inter-BBS
 */
class QwkMessage
{
    public int $messageNumber;
    public int $conferenceNumber;
    public int $replyToNumber;
    public string $status;
    public string $toName;
    public string $fromName;
    public string $subject;
    public string $body;
    public string $kludgeLines;
    public ?string $sourceMsgId;

    public function __construct(
        int $messageNumber,
        int $conferenceNumber,
        int $replyToNumber,
        string $status,
        string $toName,
        string $fromName,
        string $subject,
        string $body,
        string $kludgeLines = '',
        ?string $sourceMsgId = null
    ) {
        $this->messageNumber = $messageNumber;
        $this->conferenceNumber = $conferenceNumber;
        $this->replyToNumber = $replyToNumber;
        $this->status = $status;
        $this->toName = $toName;
        $this->fromName = $fromName;
        $this->subject = $subject;
        $this->body = $body;
        $this->kludgeLines = $kludgeLines;
        $this->sourceMsgId = $sourceMsgId;
    }
}
