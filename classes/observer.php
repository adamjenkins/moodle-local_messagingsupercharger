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

namespace local_messagingsupercharger;

use local_messagingsupercharger\local\cleanup;
use local_messagingsupercharger\local\conversations;

/**
 * Event observers.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A message was deleted for one user. Core records deletion per member (even "delete
     * for everyone" is one DELETED row per member).
     *
     * - Once every current member has deleted it, it is dropped from core's pending group
     *   digest, which does not check deletions itself.
     * - Plugin data is removed then only for individual and self conversations. A group
     *   conversation can gain members, and core shows its older messages to them, so its
     *   data stays until the message itself is removed (the sweep) or its author deletes it
     *   for everyone through the plugin (editing::delete_for_all() purges it).
     *
     * @param \core\event\message_deleted $event
     */
    public static function message_deleted(\core\event\message_deleted $event): void {
        global $DB;
        // The event's objectid is the message_user_actions row; the message is in other.
        $messageid = (int)($event->other['messageid'] ?? 0);
        if (!$messageid) {
            return;
        }
        $message = $DB->get_record('messages', ['id' => $messageid], 'id, conversationid');
        if (!$message) {
            cleanup::purge_message($messageid);
            return;
        }
        if (!conversations::is_deleted_for_all($messageid, (int)$message->conversationid)) {
            return;
        }
        $DB->delete_records('message_email_messages', ['messageid' => $messageid]);
        $type = (int)$DB->get_field('message_conversations', 'type', ['id' => $message->conversationid]);
        if ($type !== \core_message\api::MESSAGE_CONVERSATION_TYPE_GROUP) {
            cleanup::purge_message($messageid);
        }
    }

    /**
     * A group or course was deleted: core has already removed its conversations. Rather
     * than sweep the whole site once per group (a course with many groups fires this many
     * times), queue a single background sweep.
     *
     * @param \core\event\base $event
     */
    public static function sweep(\core\event\base $event): void {
        \core\task\manager::queue_adhoc_task(new \local_messagingsupercharger\task\sweep_orphans(), true);
    }
}
