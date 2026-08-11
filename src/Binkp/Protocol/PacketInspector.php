<?php

namespace BinktermPHP\Binkp\Protocol;

/**
 * Parses an FTS-0001 .pkt file's header and per-message headers for display
 * (admin queue viewer, CLI test client). Message body text is skipped
 * entirely - this is a metadata inspector, not a full packet reader.
 */
class PacketInspector
{
    /**
     * @param string $filepath Absolute path to the .pkt file
     * @return array
     */
    public static function inspect(string $filepath): array
    {
        $handle = fopen($filepath, 'rb');
        if (!$handle) {
            return ['success' => false, 'error' => 'Cannot open packet file'];
        }

        try {
            // ── Packet header (58 bytes, FTS-0001) ───────────────────────────
            $hdr = fread($handle, 60);
            if (strlen($hdr) < 58) {
                fclose($handle);
                return ['success' => false, 'error' => 'File too small to be a valid FTS-0001 packet'];
            }

            $h = unpack(
                'vorigNode/vdestNode/vyear/vmonth/vday/vhour/vminute/vsecond/' .
                'vbaud/vpacketVersion/vorigNet/vdestNet/CprodCodeLo/CrevMajor',
                substr($hdr, 0, 26)
            );

            // FTS-0001: password is 8 bytes (offsets 26–33)
            // origZone/destZone at 34/36, origPoint/destPoint at 50/52
            $password  = rtrim(substr($hdr, 26, 8), "\x00");
            $origZone  = unpack('v', substr($hdr, 34, 2))[1];
            $destZone  = unpack('v', substr($hdr, 36, 2))[1];
            $origPoint = unpack('v', substr($hdr, 50, 2))[1];
            $destPoint = unpack('v', substr($hdr, 52, 2))[1];

            $month = ($h['month'] < 12) ? $h['month'] + 1 : $h['month']; // 0-based in spec
            $created = sprintf('%04d-%02d-%02d %02d:%02d:%02d',
                $h['year'], $month, $h['day'], $h['hour'], $h['minute'], $h['second']);

            $fmtAddr = function(int $zone, int $net, int $node, int $point): string {
                $addr = "{$zone}:{$net}/{$node}";
                if ($point > 0) $addr .= ".{$point}";
                return $addr;
            };

            $packet = [
                'orig_address'   => $fmtAddr($origZone, $h['origNet'], $h['origNode'], $origPoint),
                'dest_address'   => $fmtAddr($destZone, $h['destNet'], $h['destNode'], $destPoint),
                'created'        => $created,
                'has_password'   => $password !== '',
                'packet_version' => $h['packetVersion'],
                'product_code'   => sprintf('%02X', $h['prodCodeLo']),
                'file_size'      => filesize($filepath),
            ];

            // ── Message headers ───────────────────────────────────────────────
            fseek($handle, 58);
            $messages   = [];
            $maxMsgs    = 1000;
            $attrLabels = [
                0  => 'Pvt',  1 => 'Crash', 2 => 'Rcvd', 3 => 'Sent',
                4  => 'Att',  5 => 'Trs',   6 => 'Orphn', 7 => 'K/S',
                8  => 'Local', 9 => 'Hold', 11 => 'FReq', 12 => 'RReq',
                13 => 'RRec', 14 => 'Audit', 15 => 'FUpd',
            ];

            while (!feof($handle) && count($messages) < $maxMsgs) {
                $typeBytes = fread($handle, 2);
                if (strlen($typeBytes) < 2) break;
                $msgType = unpack('v', $typeBytes)[1];
                if ($msgType === 0) break;          // end-of-packet marker
                if ($msgType !== 2) break;           // unexpected type

                // 12-byte message header: origNode destNode origNet destNet attr cost
                $mhBytes = fread($handle, 12);
                if (strlen($mhBytes) < 12) break;
                $mh = unpack('vorigNode/vdestNode/vorigNet/vdestNet/vattr/vcost', $mhBytes);

                $datetime = self::pktReadString($handle, 20);
                $toName   = self::pktReadString($handle, 36);
                $fromName = self::pktReadString($handle, 36);
                $subject  = self::pktReadString($handle, 72);

                // Skip message body (null-terminated)
                if (!self::pktSkipBody($handle, 65536)) break;

                $flags = [];
                foreach ($attrLabels as $bit => $label) {
                    if ($mh['attr'] & (1 << $bit)) {
                        $flags[] = $label;
                    }
                }

                $cp437 = fn(string $s): string =>
                    (@iconv('CP437', 'UTF-8//IGNORE', $s) ?: mb_convert_encoding($s, 'UTF-8', 'UTF-8'));

                $messages[] = [
                    'from'      => $cp437($fromName),
                    'to'        => $cp437($toName),
                    'subject'   => $cp437($subject),
                    'date'      => $datetime,
                    'orig_addr' => $mh['origNet'] . ':' . $mh['origNode'],
                    'dest_addr' => $mh['destNet'] . ':' . $mh['destNode'],
                    'flags'     => $flags,
                    'cost'      => $mh['cost'],
                ];
            }

            fclose($handle);

            return [
                'success'  => true,
                'packet'   => $packet,
                'messages' => $messages,
            ];

        } catch (\Exception $e) {
            if (is_resource($handle)) fclose($handle);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Read a null-terminated string from $handle, consuming at most $maxLen bytes.
     */
    private static function pktReadString($handle, int $maxLen): string
    {
        $result = '';
        for ($i = 0; $i < $maxLen; $i++) {
            $ch = fread($handle, 1);
            if ($ch === false || $ch === '' || $ch === "\x00") break;
            $result .= $ch;
        }
        return $result;
    }

    /**
     * Skip a null-terminated message body, consuming at most $maxLen bytes.
     * Returns false if the read failed before finding the null terminator.
     */
    private static function pktSkipBody($handle, int $maxLen): bool
    {
        for ($i = 0; $i < $maxLen; $i++) {
            $ch = fread($handle, 1);
            if ($ch === false || $ch === '') return false;
            if ($ch === "\x00") return true;
        }
        return true; // Reached limit — treat as terminated
    }
}
