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

use core_message\api;

/**
 * Holds the email for an individual-conversation message back for a short delay, then
 * sends the message text as it stands at that moment — exactly once.
 *
 * Core offers no supported way to delay or cancel one processor for one message (see
 * dev-docs STEP0-FINDINGS §5). What it does offer is the legacy callback
 * pre_processor_message_send, called once per processor with a fresh copy of the event
 * data (lib/classes/message/message.php get_eventobject_for_processor), and an email
 * processor that returns true without sending or queuing anything when the recipient
 * is suspended (message/output/email/message_output_email.php:44-46). So for the email
 * processor only, this class records the email in a queue and hands the processor a
 * clone of the recipient marked suspended. The real recipient object is never touched,
 * so other processors (popup, push) are unaffected.
 *
 * The suppression happens only after the queue row and its task are saved: if anything
 * fails, core sends the email itself, straight away, which is still exactly once.
 * Group conversations are left entirely to core's daily digest (owner decision).
 * tests/emailhold_test.php fails if a core change stops the suppression working.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class emailhold {
    /**
     * Called from local_messagingsupercharger_pre_processor_message_send().
     *
     * @param string $procname Processor name
     * @param \stdClass $eventdata The per-processor event data (changes reach the processor)
     */
    public static function intercept(string $procname, \stdClass $eventdata): void {
        global $DB;
        if ($procname !== 'email' || !empty($eventdata->notification)) {
            return;
        }
        if (($eventdata->component ?? '') !== 'moodle' || ($eventdata->name ?? '') !== 'instantmessage') {
            return;
        }
        if ((int)($eventdata->conversationtype ?? 0) !== api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL) {
            return;
        }
        $delay = features::email_delay();
        if (
            $delay <= 0 || empty($eventdata->savedmessageid) || empty($eventdata->convid)
                || !isset($eventdata->userto) || !is_object($eventdata->userto) || empty($eventdata->userto->id)
                || !isset($eventdata->userfrom) || !is_object($eventdata->userfrom)
        ) {
            return;
        }

        $messageid = (int)$eventdata->savedmessageid;
        $useridto = (int)$eventdata->userto->id;
        try {
            if ($DB->record_exists('local_messagingsupercharger_emailq', ['messageid' => $messageid, 'useridto' => $useridto])) {
                // Already queued: never queue twice, but still suppress the immediate email.
                self::suppress($eventdata);
                return;
            }
            $now = time();
            $DB->insert_record('local_messagingsupercharger_emailq', (object)[
                'messageid' => $messageid,
                'conversationid' => (int)$eventdata->convid,
                'useridfrom' => (int)$eventdata->userfrom->id,
                'useridto' => $useridto,
                'subject' => (string)($eventdata->subject ?? ''),
                'timedue' => $now + $delay,
                'claimtoken' => null,
                'timecreated' => $now,
            ]);
            $task = new \local_messagingsupercharger\task\send_held_emails();
            $task->set_custom_data(['messageid' => $messageid]);
            $task->set_next_run_time($now + $delay);
            \core\task\manager::queue_adhoc_task($task);
        } catch (\Throwable $e) {
            // Let core send it now rather than risk never sending it.
            debugging('local_messagingsupercharger could not hold a message email: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $DB->delete_records('local_messagingsupercharger_emailq', ['messageid' => $messageid, 'useridto' => $useridto]);
            return;
        }
        self::suppress($eventdata);
    }

    /**
     * Make the email processor skip this one delivery, without touching the shared
     * recipient object.
     *
     * @param \stdClass $eventdata
     */
    protected static function suppress(\stdClass $eventdata): void {
        $recipient = clone($eventdata->userto);
        $recipient->suspended = 1;
        $eventdata->userto = $recipient;
    }

    /**
     * Send every due held email, optionally only those for one message.
     *
     * @param int|null $messageid
     * @return int Emails sent
     */
    public static function send_due(?int $messageid = null): int {
        global $DB;
        $params = ['now' => time()];
        $where = 'timedue <= :now AND claimtoken IS NULL';
        if ($messageid !== null) {
            $where .= ' AND messageid = :messageid';
            $params['messageid'] = $messageid;
        }
        $rows = $DB->get_records_select('local_messagingsupercharger_emailq', $where, $params, 'timedue, id');
        $sent = 0;
        foreach ($rows as $row) {
            if (!self::claim($row)) {
                continue;
            }
            try {
                if (self::deliver($row)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                // At-most-once: a claimed row is never retried.
                mtrace('local_messagingsupercharger: held email ' . $row->id . ' failed: ' . $e->getMessage());
            }
            $DB->delete_records('local_messagingsupercharger_emailq', ['id' => $row->id]);
        }
        return $sent;
    }

    /**
     * Claim a queue row atomically so that two runners can never both send it.
     *
     * @param \stdClass $row
     * @return bool True if this caller owns the row
     */
    protected static function claim(\stdClass $row): bool {
        global $DB;
        $token = random_string(40);
        $DB->set_field_select(
            'local_messagingsupercharger_emailq',
            'claimtoken',
            $token,
            'id = :id AND claimtoken IS NULL',
            ['id' => $row->id]
        );
        return $DB->get_field('local_messagingsupercharger_emailq', 'claimtoken', ['id' => $row->id]) === $token;
    }

    /**
     * Send one held email with the message text as it is now, re-checking everything
     * that may have changed during the hold.
     *
     * @param \stdClass $row
     * @return bool True if an email was sent
     */
    protected static function deliver(\stdClass $row): bool {
        global $CFG, $DB, $SITE;
        require_once($CFG->dirroot . '/message/output/email/message_output_email.php');

        $message = $DB->get_record('messages', ['id' => $row->messageid]);
        if (!$message) {
            return false;
        }
        $recipient = \core_user::get_user($row->useridto);
        $sender = \core_user::get_user($row->useridfrom);
        if (!$recipient || !$sender || $recipient->deleted || $recipient->suspended || !empty($recipient->emailstop)) {
            return false;
        }
        if (
            !conversations::is_member((int)$recipient->id, (int)$row->conversationid)
                || conversations::is_deleted_for((int)$message->id, (int)$recipient->id)
                || api::is_conversation_muted((int)$recipient->id, (int)$row->conversationid)
        ) {
            return false;
        }
        if (
            get_config(features::COMPONENT, 'emailskipifread') !== '0' && $DB->record_exists(
                'message_user_actions',
                ['messageid' => $message->id, 'userid' => $recipient->id, 'action' => api::MESSAGE_ACTION_READ]
            )
        ) {
            return false;
        }

        // Rebuild what core's manager adds before calling processors (lib/classes/message/manager.php).
        $s = new \stdClass();
        $s->sitename = format_string($SITE->shortname, true, ['context' => \context_course::instance(SITEID)]);
        $s->url = $CFG->wwwroot . '/message/index.php?id=' . $sender->id;
        $tagline = get_string_manager()->get_string('emailtagline', 'message', $s, $recipient->lang);
        $fullmessage = (string)$message->fullmessage;
        $fullmessagehtml = (string)$message->fullmessagehtml;
        if ($fullmessage !== '') {
            $fullmessage = \core_message\helper::prevent_unclosed_html_tags($fullmessage, true)
                . "\n\n---------------------------------------------------------------------\n" . $tagline;
        }
        if ($fullmessagehtml !== '') {
            $fullmessagehtml = \core_message\helper::prevent_unclosed_html_tags($fullmessagehtml, true)
                . "<br><br>---------------------------------------------------------------------<br>" . $tagline;
        }
        $subject = (string)$row->subject;
        if ($subject === '') {
            $subject = get_string_manager()->get_string('unreadnewmessage', 'message', fullname($sender), $recipient->lang);
        }

        $eventdata = (object)[
            'component' => 'moodle',
            'name' => 'instantmessage',
            'userfrom' => $sender,
            'userto' => $recipient,
            'subject' => $subject,
            'fullmessage' => $fullmessage,
            'fullmessageformat' => FORMAT_PLAIN,
            'fullmessagehtml' => $fullmessagehtml,
            'smallmessage' => (string)$message->smallmessage,
            'notification' => 0,
            'conversationtype' => api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
            'convid' => (int)$row->conversationid,
            'savedmessageid' => (int)$message->id,
            'courseid' => SITEID,
        ];
        $processor = new \message_output_email();
        return (bool)$processor->send_message($eventdata);
    }
}
