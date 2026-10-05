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

namespace BinktermPHP\Binkp\Protocol;

/**
 * Thrown by BinkpClient::connect() when the per-hostname:port serialization
 * lock (see acquireHostLock()) could not be acquired - either because another
 * session to the same physical host is still active and the bounded wait
 * expired, or because the lock file itself could not be opened.
 *
 * Deliberately extends \RuntimeException (not a bespoke root exception) so it
 * flows through every existing `catch (\Exception $e)` / `catch (Exception $e)`
 * poll-failure handler already in place (Scheduler::processScheduledPolls(),
 * Scheduler::pollIfOutbound(), scripts/binkp_poll.php's top-level catch, etc.)
 * without any change to those call sites - this is a controlled, expected
 * failure outcome for "the host is busy right now", not a new class of crash.
 *
 * A session must never dial without the lock; dialing anyway would let two
 * sessions to the same physical remote host run concurrently.
 */
class HostLockBusyException extends \RuntimeException
{
}
