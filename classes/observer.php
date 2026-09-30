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
     * A message was deleted for one user. Core records deletion per member (even
     * "delete for everyone" is one DELETED row per member), so plugin data is removed
     * only once every member has deleted the message. Until then the other members
     * still see it, attachments included.
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
        $conversationid = $DB->get_field('messages', 'conversationid', ['id' => $messageid]);
        if (!$conversationid) {
            cleanup::purge_message($messageid);
            return;
        }
        if (conversations::is_deleted_for_all($messageid, (int)$conversationid)) {
            cleanup::purge_message($messageid);
        }
    }

    /**
     * A group or course was deleted: core has already removed its conversations.
     *
     * @param \core\event\base $event
     */
    public static function sweep(\core\event\base $event): void {
        cleanup::sweep();
    }
}
