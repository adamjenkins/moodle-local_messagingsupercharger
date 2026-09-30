<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_messagingsupercharger\local;

/**
 * Removal of plugin data when the messages, conversations or users it belongs to go.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup {
    /** @var string[] Tables keyed by core message id. */
    const MESSAGE_TABLES = [
        'local_messagingsupercharger_meta',
        'local_messagingsupercharger_mention',
        'local_messagingsupercharger_reaction',
        'local_messagingsupercharger_revision',
        'local_messagingsupercharger_pin',
        'local_messagingsupercharger_emailq',
    ];

    /** @var int Unsent attachment sets older than this are abandoned uploads. */
    const ABANDONED_AFTER = DAYSECS;

    /** @var int Failed scheduled messages are kept this long for their author to see. */
    const FAILED_RETENTION = 30 * DAYSECS;

    /**
     * Remove all plugin data about one message.
     *
     * @param int $messageid
     */
    public static function purge_message(int $messageid): void {
        global $DB;
        foreach ($DB->get_fieldset_select('local_messagingsupercharger_attach', 'id', 'messageid = ?', [$messageid]) as $setid) {
            attachments::delete_set((int)$setid);
        }
        foreach (self::MESSAGE_TABLES as $table) {
            $DB->delete_records($table, ['messageid' => $messageid]);
        }
    }

    /**
     * Remove a scheduled message and its attachments.
     *
     * @param int $scheduledid
     */
    public static function purge_scheduled(int $scheduledid): void {
        global $DB;
        $setids = $DB->get_fieldset_select('local_messagingsupercharger_attach', 'id', 'scheduledid = ?', [$scheduledid]);
        foreach ($setids as $setid) {
            attachments::delete_set((int)$setid);
        }
        $DB->delete_records('local_messagingsupercharger_sched', ['id' => $scheduledid]);
    }

    /**
     * Remove what belongs to a user being deleted. Their sent messages stay in core, so
     * the attachments shown in those messages stay too.
     *
     * @param int $userid
     */
    public static function purge_user(int $userid): void {
        global $DB;
        foreach ($DB->get_fieldset_select('local_messagingsupercharger_sched', 'id', 'userid = ?', [$userid]) as $id) {
            self::purge_scheduled((int)$id);
        }
        $setids = $DB->get_fieldset_select(
            'local_messagingsupercharger_attach',
            'id',
            'userid = ? AND messageid IS NULL',
            [$userid]
        );
        foreach ($setids as $id) {
            attachments::delete_set((int)$id);
        }
        $DB->delete_records('local_messagingsupercharger_reaction', ['userid' => $userid]);
        $DB->delete_records('local_messagingsupercharger_mention', ['userid' => $userid]);
        $DB->delete_records('local_messagingsupercharger_emailq', ['useridto' => $userid]);
        $DB->delete_records('local_messagingsupercharger_emailq', ['useridfrom' => $userid]);
        $DB->set_field('local_messagingsupercharger_pin', 'userid', 0, ['userid' => $userid]);
        self::remove_from_scheduled_mentions($userid);
    }

    /**
     * Take a user out of the mention lists of other people's pending scheduled messages.
     *
     * @param int $userid
     */
    public static function remove_from_scheduled_mentions(int $userid): void {
        global $DB;
        $like = $DB->sql_like('mentions', ':pattern');
        $rows = $DB->get_records_select(
            'local_messagingsupercharger_sched',
            $like,
            ['pattern' => '%' . $DB->sql_like_escape((string)$userid) . '%'],
            '',
            'id, mentions'
        );
        foreach ($rows as $row) {
            $ids = json_decode((string)$row->mentions, true) ?: [];
            $kept = array_values(array_filter($ids, fn($id) => (int)$id !== $userid));
            if (count($kept) !== count($ids)) {
                $DB->set_field('local_messagingsupercharger_sched', 'mentions', json_encode($kept), ['id' => $row->id]);
            }
        }
    }

    /**
     * Find and remove orphaned plugin data. Core deletes group conversations (on group
     * deletion, course deletion and course reset) and runs privacy deletions without
     * firing message events, so this sweep is the backstop for all of them.
     */
    public static function sweep(): void {
        global $DB;

        // Rows whose message is gone.
        foreach (self::MESSAGE_TABLES as $table) {
            $DB->delete_records_select(
                $table,
                "NOT EXISTS (SELECT 1 FROM {messages} m WHERE m.id = {{$table}}.messageid)"
            );
        }

        // Attachment sets whose message, scheduled message or conversation is gone, and
        // uploads that were never sent.
        $sql = "SELECT a.id
                  FROM {local_messagingsupercharger_attach} a
                 WHERE (a.messageid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {messages} m WHERE m.id = a.messageid))
                    OR (a.scheduledid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {local_messagingsupercharger_sched} s
                                                                   WHERE s.id = a.scheduledid))
                    OR (a.messageid IS NULL AND a.scheduledid IS NULL AND a.timecreated < :abandoned)";
        foreach ($DB->get_fieldset_sql($sql, ['abandoned' => time() - self::ABANDONED_AFTER]) as $setid) {
            attachments::delete_set((int)$setid);
        }

        // Scheduled messages whose conversation is gone: keep them, marked failed, so the
        // author can see what happened (see scheduler), and remove them after a while.
        $DB->execute("UPDATE {local_messagingsupercharger_sched}
                         SET status = :failed, failreason = :reason, timemodified = :now
                       WHERE status <> :failed2
                         AND NOT EXISTS (SELECT 1 FROM {message_conversations} c
                                          WHERE c.id = {local_messagingsupercharger_sched}.conversationid)", [
            'failed' => scheduler::STATUS_FAILED,
            'failed2' => scheduler::STATUS_FAILED,
            'reason' => 'failednoconversation',
            'now' => time(),
        ]);
        $old = $DB->get_fieldset_select(
            'local_messagingsupercharger_sched',
            'id',
            'status = ? AND timemodified < ?',
            [scheduler::STATUS_FAILED, time() - self::FAILED_RETENTION]
        );
        foreach ($old as $id) {
            self::purge_scheduled((int)$id);
        }

        // Held emails a crashed runner claimed but never finished (never retried).
        $DB->delete_records_select(
            'local_messagingsupercharger_emailq',
            'claimtoken IS NOT NULL AND timedue < ?',
            [time() - DAYSECS]
        );

        // Link previews are kept: they are fetched only when a message is sent, never when
        // it is read, so a deleted preview could not come back. They are small, one per URL.
    }
}
