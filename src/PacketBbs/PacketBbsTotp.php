<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 *
 */

namespace BinktermPHP\PacketBbs;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * RFC 6238 TOTP implementation for PacketBBS authenticator login.
 *
 * Secrets are Base32-encoded (RFC 4648). Codes are 6-digit HMAC-SHA1 over a
 * 30-second time step. Verification accepts ±1 step to tolerate minor clock
 * drift between the sender's device and the server.
 *
 * No submitted TOTP code is ever written to logs.
 */
class PacketBbsTotp
{
    /** Raw secret length in bytes; produces a 32-character Base32 string. */
    private const SECRET_BYTES = 20;

    /** TOTP time step in seconds (RFC 6238 default). */
    private const PERIOD = 30;

    /** Number of decimal digits in each code. */
    private const DIGITS = 6;

    /** Number of steps to accept on either side of the current step. */
    private const DRIFT = 1;

    /** RFC 4648 Base32 alphabet (upper-case, no padding). */
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a new random Base32-encoded TOTP secret.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(self::SECRET_BYTES));
    }

    /**
     * Encode raw bytes to a Base32 string (RFC 4648, no padding).
     */
    private static function base32Encode(string $data): string
    {
        $chars  = self::BASE32_CHARS;
        $result = '';
        $bits   = 0;
        $acc    = 0;
        $len    = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $acc   = ($acc << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits  -= 5;
                $result .= $chars[($acc >> $bits) & 0x1f];
            }
        }

        if ($bits > 0) {
            $result .= $chars[($acc << (5 - $bits)) & 0x1f];
        }

        return $result;
    }

    /**
     * Build an otpauth:// URI suitable for display as a QR code or manual entry.
     *
     * @param string $secret   Base32-encoded secret.
     * @param string $username Account username for the authenticator label.
     * @param string $issuer   Authenticator app display name.
     */
    public static function getOtpauthUri(string $secret, string $username, string $issuer = 'PacketBBS'): string
    {
        $label = rawurlencode($issuer . ':' . $username);
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            $secret,
            rawurlencode($issuer)
        );
    }

    /**
     * Render an otpauth:// URI as a base64 SVG data URI for browser display.
     */
    public static function getQrCodeDataUri(string $otpauthUri): string
    {
        $options = new QROptions();
        $options->outputType    = QRCode::OUTPUT_MARKUP_SVG;
        $options->eccLevel      = QRCode::ECC_M;
        $options->scale         = 6;
        $options->imageBase64   = true;
        $options->markupDark    = '#000000';
        $options->markupLight   = '#ffffff';
        $options->quietzoneSize = 4;

        return (new QRCode($options))->render($otpauthUri);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Verify a 6-digit TOTP code against a Base32-encoded secret.
     *
     * Returns true if the code matches any step in [current - DRIFT, current + DRIFT].
     *
     * When $db and $userId are supplied, single-use enforcement is applied: the
     * matched step counter is recorded in totp_used_codes and any second attempt
     * with the same code is rejected, preventing replay within the acceptance
     * window. Pass these whenever verifying a live login; omit them for
     * enrollment flows where the user is already authenticated by other means.
     *
     * Fails closed: if the DB insert cannot be performed the code is rejected.
     * The submitted code is never logged by this method.
     *
     * @param string   $secret Base32-encoded TOTP secret.
     * @param string   $code   6-digit code entered by the user.
     * @param \PDO|null $db    Database connection for single-use enforcement.
     * @param int      $userId User ID paired with $db for single-use enforcement.
     */
    public static function verifyCode(string $secret, string $code, ?\PDO $db = null, int $userId = 0): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        try {
            $rawSecret = self::base32Decode($secret);
        } catch (\InvalidArgumentException $e) {
            return false;
        }

        $currentStep = (int)floor(time() / self::PERIOD);
        $matchedStep = null;

        for ($offset = -self::DRIFT; $offset <= self::DRIFT; $offset++) {
            if (hash_equals(self::computeHotp($rawSecret, $currentStep + $offset), $code)) {
                $matchedStep = $currentStep + $offset;
                break;
            }
        }

        if ($matchedStep === null) {
            return false;
        }

        if ($db !== null && $userId > 0) {
            try {
                // Purge records outside the acceptance window before inserting.
                $db->exec("DELETE FROM totp_used_codes WHERE used_at < NOW() - INTERVAL '3 minutes'");

                // ON CONFLICT DO NOTHING returns 0 rows on a duplicate — that is a replay.
                $stmt = $db->prepare(
                    'INSERT INTO totp_used_codes (user_id, step) VALUES (?, ?) ON CONFLICT DO NOTHING'
                );
                $stmt->execute([$userId, $matchedStep]);

                if ($stmt->rowCount() === 0) {
                    return false;
                }
            } catch (\Exception) {
                // Fail closed: any DB error is treated as a rejection.
                return false;
            }
        }

        return true;
    }

    /**
     * Decode a Base32 string (RFC 4648) to raw bytes.
     *
     * @throws \InvalidArgumentException on invalid characters.
     */
    private static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(trim($encoded));
        $encoded = rtrim($encoded, '=');
        $map     = array_flip(str_split(self::BASE32_CHARS));
        $result  = '';
        $bits    = 0;
        $acc     = 0;
        $len     = strlen($encoded);

        for ($i = 0; $i < $len; $i++) {
            $c = $encoded[$i];
            if (!isset($map[$c])) {
                throw new \InvalidArgumentException("Invalid Base32 character: $c");
            }
            $acc   = ($acc << 5) | $map[$c];
            $bits += 5;
            if ($bits >= 8) {
                $bits   -= 8;
                $result .= chr(($acc >> $bits) & 0xff);
            }
        }

        return $result;
    }

    /**
     * Compute one HOTP value (RFC 4226) for the given binary secret and counter.
     *
     * @param string $rawSecret Raw (non-encoded) secret bytes.
     * @param int    $counter   Time step counter.
     */
    private static function computeHotp(string $rawSecret, int $counter): string
    {
        // Big-endian 64-bit unsigned integer encoding of the counter.
        $msg  = pack('J', $counter);
        $hmac = hash_hmac('sha1', $msg, $rawSecret, true);

        // Dynamic truncation (RFC 4226 §5.4).
        $offset = ord($hmac[19]) & 0x0f;
        $code   = (
            ((ord($hmac[$offset])     & 0x7f) << 24) |
            ((ord($hmac[$offset + 1]) & 0xff) << 16) |
            ((ord($hmac[$offset + 2]) & 0xff) <<  8) |
            ((ord($hmac[$offset + 3]) & 0xff))
        ) % (10 ** self::DIGITS);

        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }
}
