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

namespace BinktermPHP;

/**
 * ActivityTracker — records user activity events to the user_activity_log table.
 *
 * Calls to track() always fail silently so that a database or configuration
 * problem never breaks the underlying user action being tracked.
 */
class ActivityTracker
{
    // Activity type ID constants — match activity_types table
    const TYPE_ECHOMAIL_AREA_VIEW = 1;
    const TYPE_ECHOMAIL_SEND      = 2;
    const TYPE_NETMAIL_READ       = 3;
    const TYPE_NETMAIL_SEND       = 4;
    const TYPE_FILEAREA_VIEW      = 5;
    const TYPE_FILE_DOWNLOAD      = 6;
    const TYPE_FILE_UPLOAD        = 7;
    const TYPE_WEBDOOR_PLAY       = 8;
    const TYPE_DOSDOOR_PLAY       = 9;
    const TYPE_NODELIST_VIEW      = 10;
    const TYPE_NODE_VIEW          = 11;
    const TYPE_CHAT_SEND          = 12;
    const TYPE_LOGIN              = 13;

    /**
     * Record an activity event.
     *
     * Fails silently — a tracking failure must never interrupt the tracked action.
     *
     * @param int|null $userId        The authenticated user's ID, or null for anonymous/system events.
     * @param int      $activityTypeId One of the TYPE_* constants defined on this class.
     * @param int|null $objectId      Integer ID of the related object (message, file, etc.).
     * @param string|null $objectName Human-readable context (echoarea tag, filename, door name, node address, …).
     * @param array    $meta          Optional extra key/value data stored as JSONB.
     */
    public static function track(
        ?int $userId,
        int $activityTypeId,
        ?int $objectId = null,
        ?string $objectName = null,
        array $meta = []
    ): void {
        try {
            $db = Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                INSERT INTO user_activity_log (user_id, activity_type_id, object_id, object_name, meta)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $activityTypeId,
                $objectId,
                $objectName,
                $meta ? json_encode($meta) : null
            ]);
        } catch (\Exception $e) {
            // Never let tracking failure break the user action
        }
    }
}
