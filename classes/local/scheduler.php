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
 * Scheduled send.
 *
 * A scheduled message is stored in the plugin's table, not in core's, until it is due;
 * an ad hoc task then sends it through {@see sender::send()} as the author. All of
 * core's rules (messaging enabled, membership, blocking, contact-only settings, a
 * disabled conversation) are therefore applied at delivery time, not when it was
 * written. A message that can no longer be sent is kept as failed, with the reason,
 * so the author can see what happened.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduler {
    /** @var int Waiting to be sent. */
    const STATUS_PENDING = 0;
    /** @var int Could not be sent. */
    const STATUS_FAILED = 1;
    /** @var int Claimed by a runner that is sending it right now. */
    const STATUS_SENDING = 2;

    /** @var int Furthest ahead a message may be scheduled. */
    const MAX_AHEAD = 365 * DAYSECS;
    /** @var int Most pending messages per user. */
    const MAX_PENDING = 50;

    /**
     * Check a send time.
     *
     * @param int $timesend
     * @throws \moodle_exception
     */
    protected static function validate_time(int $timesend): void {
        $now = time();
        if ($timesend <= $now + 30) {
            throw new \moodle_exception('scheduleinpast', features::COMPONENT);
        }
        if ($timesend > $now + self::MAX_AHEAD) {
            throw new \moodle_exception('scheduletoofar', features::COMPONENT);
        }
    }

    /**
     * Schedule a message.
     *
     * @param int $userid
     * @param int $conversationid
     * @param string $text
     * @param int $format
     * @param int $timesend
     * @param int $draftitemid Attachments (0 for none)
     * @param int[] $mentionids
     * @param string[]|null $filenames Attach only these files from the draft area (null for all)
     * @return int Scheduled message id
     * @throws \moodle_exception
     */
    public static function schedule(
        int $userid,
        int $conversationid,
        string $text,
        int $format,
        int $timesend,
        int $draftitemid = 0,
        array $mentionids = [],
        ?array $filenames = null
    ): int {
        global $DB;
        features::require_enabled(features::SCHEDULING);
        $format = sender::is_html($format) ? (int)FORMAT_HTML : (int)FORMAT_PLAIN;
        $conversation = conversations::get($conversationid);
        conversations::require_capability('schedulesend', $conversation, $userid);
        self::validate_time($timesend);
        $waiting = $DB->count_records_select(
            'local_messagingsupercharger_sched',
            'userid = ? AND status <> ?',
            [$userid, self::STATUS_FAILED]
        );
        if ($waiting >= self::MAX_PENDING) {
            throw new \moodle_exception('toomanyscheduled', features::COMPONENT, '', self::MAX_PENDING);
        }
        $mentions = mentions::resolve($conversation, $userid, $mentionids);
        $hasattachments = (bool)attachments::draft_files($userid, $draftitemid, $filenames);
        sender::check($userid, $conversation, $text, $format, $hasattachments, array_keys($mentions));

        $now = time();
        $id = $DB->insert_record('local_messagingsupercharger_sched', (object)[
            'userid' => $userid,
            'conversationid' => $conversationid,
            'body' => $text,
            'bodyformat' => $format,
            'attachsetid' => null,
            'mentions' => json_encode(array_keys($mentions)),
            'timesend' => $timesend,
            'status' => self::STATUS_PENDING,
            'failreason' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        if ($hasattachments) {
            [$setid] = attachments::create_set($userid, $conversationid, $draftitemid, 0, '', $filenames);
            if ($setid) {
                $DB->set_field('local_messagingsupercharger_attach', 'scheduledid', $id, ['id' => $setid]);
                $DB->set_field('local_messagingsupercharger_sched', 'attachsetid', $setid, ['id' => $id]);
            }
        }
        self::queue($id, $timesend);
        return $id;
    }

    /**
     * Queue the delivery task.
     *
     * @param int $id
     * @param int $timesend
     */
    protected static function queue(int $id, int $timesend): void {
        $task = new \local_messagingsupercharger\task\send_scheduled_message();
        $task->set_custom_data(['id' => $id, 'timesend' => $timesend]);
        $task->set_next_run_time($timesend);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Load one of the user's own scheduled messages.
     *
     * @param int $id
     * @param int $userid
     * @return \stdClass
     * @throws \moodle_exception
     */
    protected static function require_own(int $id, int $userid): \stdClass {
        global $DB;
        $row = $DB->get_record('local_messagingsupercharger_sched', ['id' => $id]);
        if (!$row || (int)$row->userid !== $userid) {
            throw new \moodle_exception('schedulednotfound', features::COMPONENT);
        }
        return $row;
    }

    /**
     * Change the text or time of a scheduled message. A failed one becomes pending again.
     *
     * @param int $id
     * @param int $userid
     * @param string $text
     * @param int $timesend
     * @throws \moodle_exception
     */
    public static function update(int $id, int $userid, string $text, int $timesend): void {
        global $DB;
        features::require_enabled(features::SCHEDULING);
        $row = self::require_own($id, $userid);
        if ((int)$row->status === self::STATUS_SENDING) {
            throw new \moodle_exception('schedulebeingsent', features::COMPONENT);
        }
        $conversation = conversations::get((int)$row->conversationid);
        conversations::require_capability('schedulesend', $conversation, $userid);
        self::validate_time($timesend);
        $mentions = mentions::resolve($conversation, $userid, json_decode((string)$row->mentions, true) ?: []);
        sender::check(
            $userid,
            $conversation,
            $text,
            (int)$row->bodyformat,
            !empty($row->attachsetid),
            array_keys($mentions)
        );
        // Conditional on the row not having been claimed for sending since it was read, so an
        // edit can never reopen a message that is going out right now.
        $DB->execute("UPDATE {local_messagingsupercharger_sched}
                         SET body = :body, timesend = :timesend, status = :pending, failreason = NULL,
                             timemodified = :now
                       WHERE id = :id AND status <> :sending", [
            'body' => $text,
            'timesend' => $timesend,
            'pending' => self::STATUS_PENDING,
            'now' => time(),
            'id' => $row->id,
            'sending' => self::STATUS_SENDING,
        ]);
        $after = $DB->get_record('local_messagingsupercharger_sched', ['id' => $row->id], 'id, status, timesend');
        if (!$after || (int)$after->status === self::STATUS_SENDING || (int)$after->timesend !== $timesend) {
            throw new \moodle_exception('schedulebeingsent', features::COMPONENT);
        }
        if ((int)$row->timesend !== $timesend || (int)$row->status !== self::STATUS_PENDING) {
            self::queue((int)$row->id, $timesend);
        }
    }

    /**
     * Cancel a scheduled message.
     *
     * @param int $id
     * @param int $userid
     * @throws \moodle_exception
     */
    public static function cancel(int $id, int $userid): void {
        global $DB;
        $row = self::require_own($id, $userid);
        // Take it out of the queue in one conditional step, so a send in progress is never
        // cancelled halfway (its attachments are being attached to the message).
        $DB->execute("UPDATE {local_messagingsupercharger_sched} SET status = :failed, failreason = :reason
                       WHERE id = :id AND status <> :sending", [
            'failed' => self::STATUS_FAILED,
            'reason' => 'cancelled',
            'id' => $row->id,
            'sending' => self::STATUS_SENDING,
        ]);
        if ($DB->get_field('local_messagingsupercharger_sched', 'failreason', ['id' => $row->id]) !== 'cancelled') {
            throw new \moodle_exception('schedulebeingsent', features::COMPONENT);
        }
        cleanup::purge_scheduled((int)$row->id);
    }

    /**
     * The user's scheduled messages, optionally for one conversation.
     *
     * @param int $userid
     * @param int|null $conversationid
     * @return array
     */
    public static function list(int $userid, ?int $conversationid = null): array {
        global $DB;
        $params = ['userid' => $userid];
        if ($conversationid !== null) {
            $params['conversationid'] = $conversationid;
        }
        $rows = $DB->get_records('local_messagingsupercharger_sched', $params, 'timesend, id');
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row->id,
                'conversationid' => (int)$row->conversationid,
                'text' => (string)$row->body,
                'format' => (int)$row->bodyformat,
                'timesend' => (int)$row->timesend,
                'failed' => (int)$row->status === self::STATUS_FAILED,
                'failreason' => (int)$row->status === self::STATUS_FAILED && $row->failreason
                    ? get_string($row->failreason, features::COMPONENT) : '',
                'attachments' => $row->attachsetid ? count(attachments::set_files((int)$row->attachsetid)) : 0,
            ];
        }
        return $result;
    }

    /**
     * Deliver a scheduled message if it is due. Called by the ad hoc task.
     *
     * @param int $id
     * @param int $expectedtimesend The send time the task was queued for; a later edit
     *        queues a new task, and this one then does nothing.
     * @return int|null The core message id, or null if nothing was sent
     */
    public static function deliver(int $id, int $expectedtimesend): ?int {
        global $DB;
        $row = $DB->get_record('local_messagingsupercharger_sched', ['id' => $id]);
        if (
            !$row || (int)$row->status !== self::STATUS_PENDING || (int)$row->timesend !== $expectedtimesend
                || (int)$row->timesend > time()
        ) {
            return null;
        }
        if (self::claim((int)$row->id) === null) {
            return null;
        }
        $reason = self::delivery_failure((int)$row->userid, (int)$row->conversationid);
        if ($reason === null) {
            $message = null;
            try {
                $message = sender::send(
                    (int)$row->userid,
                    (int)$row->conversationid,
                    (string)$row->body,
                    (int)$row->bodyformat,
                    0,
                    0,
                    json_decode((string)$row->mentions, true) ?: [],
                    $row->attachsetid ? (int)$row->attachsetid : null
                );
            } catch (\Throwable $e) {
                // Nothing was sent: sender::send() only throws before sending.
                $reason = 'failedcannotsend';
            }
            if ($message) {
                $DB->delete_records('local_messagingsupercharger_sched', ['id' => $row->id]);
                return (int)$message->id;
            }
        }
        $DB->update_record('local_messagingsupercharger_sched', (object)[
            'id' => $row->id,
            'status' => self::STATUS_FAILED,
            'failreason' => $reason,
            'timemodified' => time(),
        ]);
        return null;
    }

    /**
     * Claim a pending row for sending. One UPDATE both changes the status and stamps a
     * token, and only one runner's UPDATE can match a pending row, so reading the token
     * back proves the claim. The claim time is recorded, so an interrupted send can be
     * found later.
     *
     * @param int $id
     * @return string|null The claim token, or null if someone else has the row
     */
    public static function claim(int $id): ?string {
        global $DB;
        $token = 'claim:' . random_string(20);
        $DB->execute("UPDATE {local_messagingsupercharger_sched}
                         SET status = :sending, failreason = :token, timemodified = :now
                       WHERE id = :id AND status = :pending", [
            'sending' => self::STATUS_SENDING,
            'token' => $token,
            'now' => time(),
            'id' => $id,
            'pending' => self::STATUS_PENDING,
        ]);
        return $DB->get_field('local_messagingsupercharger_sched', 'failreason', ['id' => $id]) === $token ? $token : null;
    }

    /**
     * Rows left claimed by a sender that died (fatal error, time or memory limit): mark
     * them failed, without retrying, because the message may or may not have gone out.
     *
     * @param int $olderthan Seconds since the claim
     */
    public static function fail_interrupted(int $olderthan = HOURSECS): void {
        global $DB;
        $DB->execute("UPDATE {local_messagingsupercharger_sched} SET status = :failed, failreason = :reason
                       WHERE status = :sending AND timemodified < :cutoff", [
            'failed' => self::STATUS_FAILED,
            'reason' => 'failedinterrupted',
            'sending' => self::STATUS_SENDING,
            'cutoff' => time() - $olderthan,
        ]);
    }

    /**
     * Why a scheduled message cannot be sent now, or null if it can.
     *
     * @param int $userid
     * @param int $conversationid
     * @return string|null Lang string key
     */
    public static function delivery_failure(int $userid, int $conversationid): ?string {
        global $CFG, $DB;
        if (empty($CFG->messaging) || !features::enabled(features::SCHEDULING)) {
            return 'faileddisabled';
        }
        if (!$DB->record_exists('message_conversations', ['id' => $conversationid])) {
            return 'failednoconversation';
        }
        if (!conversations::is_member($userid, $conversationid)) {
            return 'failednotmember';
        }
        if (!conversations::can_send($userid, $conversationid)) {
            return 'failedcannotsend';
        }
        return null;
    }
}
