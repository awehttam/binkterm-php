<?php

/*
 * Copyright Matthew Asham and BinktermPHP Contributors
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

namespace BinktermPHP\Nntp;

use BinktermPHP\Auth;

/**
 * NNTP AUTHINFO USER / PASS backed by BinktermPHP user accounts.
 *
 * Mirrors what src/Ftp/FtpServer.php does for the FTP daemon: verify credentials
 * with {@see Auth::authenticateCredentials()}, then register a `user_sessions` row
 * tagged `nntp` so the connection shows in "who's online".
 */
class NntpAuth
{
    private Auth $auth;
    private ?string $sessionId = null;
    private ?int $userId = null;

    public function __construct(?Auth $auth = null)
    {
        $this->auth = $auth ?? new Auth();
    }

    /**
     * Verify a username/real-name + password. Returns the user row on success,
     * null otherwise. On success a `nntp` session row is created.
     *
     * @return array<string,mixed>|null
     */
    public function login(string $username, string $password, string $remoteIp = ''): ?array
    {
        $user = $this->auth->authenticateCredentials($username, $password);
        if ($user === false) {
            return null;
        }

        $this->userId = (int)$user['id'];
        try {
            $this->sessionId = $this->auth->createSessionForConnection(
                $this->userId,
                'nntp',
                $remoteIp,
                'BinktermPHP NNTP'
            );
        } catch (\Throwable $e) {
            // Session bookkeeping is best-effort; auth itself succeeded.
            $this->sessionId = null;
        }

        return $user;
    }

    /**
     * Update the visible activity string for this connection's session.
     */
    public function touch(string $activity): void
    {
        if ($this->sessionId !== null) {
            try {
                $this->auth->updateSessionActivity($this->sessionId, $activity);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Tear down the session row on disconnect.
     */
    public function close(): void
    {
        if ($this->sessionId !== null) {
            try {
                $this->auth->logout($this->sessionId);
            } catch (\Throwable $e) {
                // ignore
            }
            $this->sessionId = null;
        }
    }

    public function userId(): ?int
    {
        return $this->userId;
    }
}
