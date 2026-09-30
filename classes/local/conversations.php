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
 * Access rules for conversations and messages, all derived server-side.
 *
 * Every entry point re-derives the conversation from the message id it is given and
 * checks membership against the database; nothing the browser says about which
 * conversation a message belongs to is trusted.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversations {
    /**
     * Throw unless site messaging is on.
     *
     * @throws \moodle_exception
     */
    public static function require_messaging_enabled(): void {
        global $CFG;
        if (empty($CFG->messaging)) {
            throw new \moodle_exception('disabled', 'message');
        }
    }

    /**
     * Load a conversation.
     *
     * @param int $conversationid
     * @return \stdClass
     */
    public static function get(int $conversationid): \stdClass {
        global $DB;
        return $DB->get_record('message_conversations', ['id' => $conversationid], '*', MUST_EXIST);
    }

    /**
     * The context capabilities are checked in: the group's course for a course-group
     * conversation, the system otherwise.
     *
     * @param \stdClass $conversation
     * @return \context
     */
    public static function context(\stdClass $conversation): \context {
        if (!empty($conversation->contextid)) {
            $context = \context::instance_by_id($conversation->contextid, IGNORE_MISSING);
            if ($context) {
                return $context;
            }
        }
        return \context_system::instance();
    }

    /**
     * Is the user a member of the conversation?
     *
     * @param int $userid
     * @param int $conversationid
     * @return bool
     */
    public static function is_member(int $userid, int $conversationid): bool {
        return api::is_user_in_conversation($userid, $conversationid);
    }

    /**
     * Throw unless the user is a member of the conversation.
     *
     * @param int $userid
     * @param int $conversationid
     * @throws \moodle_exception
     */
    public static function require_member(int $userid, int $conversationid): void {
        if (!self::is_member($userid, $conversationid)) {
            throw new \moodle_exception('notamember', features::COMPONENT);
        }
    }

    /**
     * Can the user send to the conversation right now? This applies every core rule:
     * site messaging, the sendmessage capability, membership, the recipient's privacy
     * setting and blocking for individual conversations, and a disabled group
     * conversation (which core's own check does not look at).
     *
     * @param int $userid
     * @param int $conversationid
     * @return bool
     */
    public static function can_send(int $userid, int $conversationid): bool {
        global $CFG, $DB;
        if (empty($CFG->messaging)) {
            return false;
        }
        $conversation = $DB->get_record('message_conversations', ['id' => $conversationid]);
        if (!$conversation || (int)$conversation->enabled !== api::MESSAGE_CONVERSATION_ENABLED) {
            return false;
        }
        $user = \core_user::get_user($userid);
        if (!$user || $user->deleted || $user->suspended) {
            return false;
        }
        return api::can_send_message_to_conversation($userid, $conversationid);
    }

    /**
     * Throw unless the user can send to the conversation.
     *
     * @param int $userid
     * @param int $conversationid
     * @throws \moodle_exception
     */
    public static function require_can_send(int $userid, int $conversationid): void {
        if (!self::can_send($userid, $conversationid)) {
            throw new \moodle_exception('cannotsend', features::COMPONENT);
        }
    }

    /**
     * Throw unless the user holds a plugin capability in the conversation's context.
     *
     * @param string $capability Short name, e.g. 'react'
     * @param \stdClass $conversation
     * @param int $userid
     * @throws \required_capability_exception
     */
    public static function require_capability(string $capability, \stdClass $conversation, int $userid): void {
        require_capability('local/messagingsupercharger:' . $capability, self::context($conversation), $userid);
    }

    /**
     * Does the user hold a plugin capability in the conversation's context?
     *
     * @param string $capability Short name, e.g. 'react'
     * @param \stdClass $conversation
     * @param int $userid
     * @return bool
     */
    public static function has_capability(string $capability, \stdClass $conversation, int $userid): bool {
        return has_capability('local/messagingsupercharger:' . $capability, self::context($conversation), $userid);
    }

    /**
     * Load a message and its conversation, and check the user can see it: a member of
     * the conversation who has not deleted it for themselves.
     *
     * @param int $messageid
     * @param int $userid
     * @return array [message record, conversation record]
     * @throws \moodle_exception
     */
    public static function require_visible_message(int $messageid, int $userid): array {
        global $DB;
        $message = $DB->get_record('messages', ['id' => $messageid]);
        if (!$message || empty($message->conversationid)) {
            throw new \moodle_exception('messagenotfound', features::COMPONENT);
        }
        self::require_member($userid, (int)$message->conversationid);
        if (self::is_deleted_for($messageid, $userid)) {
            throw new \moodle_exception('messagenotfound', features::COMPONENT);
        }
        return [$message, self::get((int)$message->conversationid)];
    }

    /**
     * Has the user deleted this message for themselves (or has it been deleted for everyone)?
     *
     * @param int $messageid
     * @param int $userid
     * @return bool
     */
    public static function is_deleted_for(int $messageid, int $userid): bool {
        global $DB;
        return $DB->record_exists(
            'message_user_actions',
            ['messageid' => $messageid, 'userid' => $userid, 'action' => api::MESSAGE_ACTION_DELETED]
        );
    }

    /**
     * Has every current member deleted this message (the result of delete-for-everyone)?
     *
     * @param int $messageid
     * @param int $conversationid
     * @return bool
     */
    public static function is_deleted_for_all(int $messageid, int $conversationid): bool {
        global $DB;
        $sql = "SELECT COUNT(1)
                  FROM {message_conversation_members} mcm
             LEFT JOIN {message_user_actions} mua
                    ON mua.userid = mcm.userid AND mua.messageid = :messageid AND mua.action = :action
                 WHERE mcm.conversationid = :conversationid AND mua.id IS NULL";
        $remaining = $DB->count_records_sql($sql, [
            'messageid' => $messageid,
            'action' => api::MESSAGE_ACTION_DELETED,
            'conversationid' => $conversationid,
        ]);
        return $remaining == 0;
    }

    /**
     * User ids of the conversation's members.
     *
     * @param int $conversationid
     * @return int[]
     */
    public static function member_ids(int $conversationid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(
            'message_conversation_members',
            'userid',
            'conversationid = ?',
            [$conversationid]
        ));
    }

    /**
     * Find or create the individual conversation between two users, applying core's
     * contact rules for a first message.
     *
     * @param int $userid Sender
     * @param int $touserid Recipient
     * @return int Conversation id
     * @throws \moodle_exception
     */
    public static function individual_conversation_for(int $userid, int $touserid): int {
        if ($userid === $touserid) {
            $conversation = api::get_self_conversation($userid);
            if (!$conversation) {
                $conversation = api::create_conversation(api::MESSAGE_CONVERSATION_TYPE_SELF, [$userid]);
            }
            return (int)$conversation->id;
        }
        $recipient = \core_user::get_user($touserid);
        if (!$recipient || $recipient->deleted || !api::can_send_message($touserid, $userid)) {
            throw new \moodle_exception('cannotsend', features::COMPONENT);
        }
        $conversationid = api::get_conversation_between_users([$userid, $touserid]);
        if (!$conversationid) {
            $conversation = api::create_conversation(api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL, [$userid, $touserid]);
            $conversationid = $conversation->id;
        }
        return (int)$conversationid;
    }
}
