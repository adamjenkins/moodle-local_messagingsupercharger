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
     * Delete the attachments of messages older than the retention period. The message
     * stays: its attachment list and embedded images are replaced by a note saying the
     * attachments have expired, and its text (with any edits and mentions) is kept.
     *
     * @param int|null $now For tests
     * @return int Number of messages whose attachments were deleted
     */
    public static function expire_attachments(?int $now = null): int {
        global $DB;
        $retention = features::attachment_retention();
        if (!$retention) {
            return 0;
        }
        $sql = "SELECT a.id, a.messageid
                  FROM {local_messagingsupercharger_attach} a
                  JOIN {messages} m ON m.id = a.messageid
                 WHERE m.timecreated < :cutoff";
        $sets = $DB->get_records_sql($sql, ['cutoff' => ($now ?? time()) - $retention], 0, 500);
        $note = \html_writer::div(
            s(get_string('attachmentsexpired', features::COMPONENT)),
            'msgsc-attachments-expired'
        );
        foreach ($sets as $set) {
            $transaction = $DB->start_delegated_transaction();
            $message = $DB->get_record('messages', ['id' => $set->messageid], 'id, smallmessage');
            $html = self::strip_attachments((string)$message->smallmessage) . $note;
            $DB->update_record('messages', (object)[
                'id' => $message->id,
                'smallmessage' => $html,
                'fullmessage' => html_to_text($html),
                'fullmessagehtml' => $html,
                'fullmessageformat' => FORMAT_HTML,
            ]);
            $meta = $DB->get_record('local_messagingsupercharger_meta', ['messageid' => $message->id], 'id, body');
            if ($meta) {
                // Later edits rebuild the message from the body, so the images go from it too.
                $DB->update_record('local_messagingsupercharger_meta', (object)[
                    'id' => $meta->id,
                    'body' => self::strip_attachments((string)$meta->body),
                    'attachsetid' => null,
                ]);
            }
            attachments::delete_set((int)$set->id);
            $transaction->allow_commit();
        }
        return count($sets);
    }

    /**
     * Remove the plugin's attachment list and embedded images from message HTML.
     *
     * @param string $html
     * @return string
     */
    public static function strip_attachments(string $html): string {
        // The list is generated by attachments::render_list(): links and images only, no divs inside.
        $html = preg_replace('~<div class="msgsc-attachments">.*?</div>~s', '', $html);
        $inline = '/' . features::COMPONENT . '/' . attachments::AREA_INLINE . '/';
        return preg_replace_callback('~<img\b[^>]*>~i', function (array $matches) use ($inline): string {
            return strpos($matches[0], $inline) === false ? $matches[0] : '';
        }, $html);
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
