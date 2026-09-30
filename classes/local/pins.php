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
 * Pinned messages.
 *
 * Who may pin: in an individual or self conversation, either member; in a group
 * conversation, only people holding local/messagingsupercharger:pinmessage in the
 * group's course (by default teachers and managers), because a pin is shown to every
 * member of the group.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pins {
    /** @var int Most pinned messages per conversation. */
    const MAX_PINS = 10;

    /**
     * May the user pin or unpin in this conversation?
     *
     * @param \stdClass $conversation
     * @param int $userid
     * @return bool
     */
    public static function can_pin(\stdClass $conversation, int $userid): bool {
        if (!features::enabled(features::PINNING) || !conversations::is_member($userid, (int)$conversation->id)) {
            return false;
        }
        if ((int)$conversation->type === api::MESSAGE_CONVERSATION_TYPE_GROUP) {
            return conversations::has_capability('pinmessage', $conversation, $userid);
        }
        return true;
    }

    /**
     * Pin or unpin a message.
     *
     * @param int $messageid
     * @param int $userid
     * @param bool $pinned
     * @throws \moodle_exception
     */
    public static function set(int $messageid, int $userid, bool $pinned): void {
        global $DB;
        features::require_enabled(features::PINNING);
        [$message, $conversation] = conversations::require_visible_message($messageid, $userid);
        if (!self::can_pin($conversation, $userid)) {
            throw new \moodle_exception('nopermissiontopin', features::COMPONENT);
        }
        $params = ['conversationid' => $conversation->id, 'messageid' => $message->id];
        if (!$pinned) {
            $DB->delete_records('local_messagingsupercharger_pin', $params);
            return;
        }
        if ($DB->record_exists('local_messagingsupercharger_pin', $params)) {
            return;
        }
        if ($DB->count_records('local_messagingsupercharger_pin', ['conversationid' => $conversation->id]) >= self::MAX_PINS) {
            throw new \moodle_exception('toomanypins', features::COMPONENT, '', self::MAX_PINS);
        }
        $DB->insert_record('local_messagingsupercharger_pin', (object)($params + ['userid' => $userid, 'timecreated' => time()]));
    }

    /**
     * The pinned messages of a conversation that the viewer can see.
     *
     * @param \stdClass $conversation
     * @param int $viewerid A member (checked by the caller)
     * @return array of ['messageid', 'text', 'author', 'timecreated', 'pinnedby']
     */
    public static function list(\stdClass $conversation, int $viewerid): array {
        global $DB;
        if (!features::enabled(features::PINNING)) {
            return [];
        }
        $authorfields = \core_user\fields::for_name()->get_sql('a', false, 'author_', '', false)->selects;
        $pinnerfields = \core_user\fields::for_name()->get_sql('p', false, 'pinner_', '', false)->selects;
        $sql = "SELECT pin.id, pin.messageid, pin.timecreated AS timepinned, m.smallmessage, m.fullmessageformat, m.timecreated,
                       $authorfields, $pinnerfields
                  FROM {local_messagingsupercharger_pin} pin
                  JOIN {messages} m ON m.id = pin.messageid
                  JOIN {user} a ON a.id = m.useridfrom
             LEFT JOIN {user} p ON p.id = pin.userid
             LEFT JOIN {message_user_actions} mua
                    ON mua.messageid = m.id AND mua.userid = :viewerid AND mua.action = :deleted
                 WHERE pin.conversationid = :conversationid AND mua.id IS NULL
              ORDER BY pin.timecreated DESC, pin.id DESC";
        $rows = $DB->get_records_sql($sql, [
            'viewerid' => $viewerid,
            'deleted' => api::MESSAGE_ACTION_DELETED,
            'conversationid' => $conversation->id,
        ]);
        $result = [];
        foreach ($rows as $row) {
            $author = username_load_fields_from_object(new \stdClass(), $row, 'author_');
            $pinner = username_load_fields_from_object(new \stdClass(), $row, 'pinner_');
            $result[] = [
                'messageid' => (int)$row->messageid,
                'text' => shorten_text(sender::is_html($row->fullmessageformat)
                    ? sender::html_to_plain((string)$row->smallmessage) : trim((string)$row->smallmessage), 120),
                'author' => fullname($author),
                'timecreated' => (int)$row->timecreated,
                'pinnedby' => !empty($pinner->firstname) || !empty($pinner->lastname) ? fullname($pinner) : '',
            ];
        }
        return $result;
    }
}
