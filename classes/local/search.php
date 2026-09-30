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
 * Message search across the user's conversations.
 *
 * Unlike core's drawer search (a match on the raw stored text, shaped as contacts), this
 * matches the plain-text version of each message, covers group conversations, groups
 * results by conversation, and only returns messages from conversations the user is
 * still a member of, that are enabled, and that the user has not deleted. It queries
 * the messages table directly: no extra index (owner decision, STEP0-FINDINGS §11).
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search {
    /** @var int Shortest query searched. */
    const MIN_QUERY = 2;

    /**
     * Search.
     *
     * @param int $userid
     * @param string $query
     * @param int $limitfrom
     * @param int $limitnum
     * @return array ['results' => list, 'hasmore' => bool]
     */
    public static function messages(int $userid, string $query, int $limitfrom = 0, int $limitnum = 20): array {
        global $DB;
        features::require_enabled(features::SEARCH);
        $query = trim($query);
        if (\core_text::strlen($query) < self::MIN_QUERY) {
            return ['results' => [], 'hasmore' => false];
        }
        $limitnum = max(1, min(50, $limitnum));
        $fromfields = \core_user\fields::for_name()->get_sql('u', false, 'from_', '', false)->selects;
        $like = $DB->sql_like('m.fullmessage', ':query', false, false);
        $sql = "SELECT m.id, m.conversationid, m.useridfrom, m.smallmessage, m.fullmessageformat, m.timecreated,
                       mc.type, mc.name, mc.contextid, $fromfields
                  FROM {messages} m
                  JOIN {message_conversations} mc ON mc.id = m.conversationid AND mc.enabled = :enabled
                  JOIN {message_conversation_members} mcm ON mcm.conversationid = m.conversationid AND mcm.userid = :userid
                  JOIN {user} u ON u.id = m.useridfrom
             LEFT JOIN {message_user_actions} mua
                    ON mua.messageid = m.id AND mua.userid = :userid2 AND mua.action = :deleted
                 WHERE mua.id IS NULL AND $like
              ORDER BY m.timecreated DESC, m.id DESC";
        $params = [
            'enabled' => api::MESSAGE_CONVERSATION_ENABLED,
            'userid' => $userid,
            'userid2' => $userid,
            'deleted' => api::MESSAGE_ACTION_DELETED,
            'query' => '%' . $DB->sql_like_escape($query) . '%',
        ];
        $rows = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum + 1);
        $hasmore = count($rows) > $limitnum;
        $rows = array_slice($rows, 0, $limitnum);

        $results = [];
        $names = [];
        foreach ($rows as $row) {
            $from = username_load_fields_from_object(new \stdClass(), $row, 'from_');
            $cid = (int)$row->conversationid;
            if (!isset($names[$cid])) {
                $names[$cid] = self::conversation_name($row, $userid);
            }
            $results[] = [
                'messageid' => (int)$row->id,
                'conversationid' => $cid,
                'conversationname' => $names[$cid],
                'isgroup' => (int)$row->type === api::MESSAGE_CONVERSATION_TYPE_GROUP,
                'author' => fullname($from),
                'snippet' => self::snippet(self::plain_text($row), $query),
                'timecreated' => (int)$row->timecreated,
            ];
        }
        return ['results' => $results, 'hasmore' => $hasmore];
    }

    /**
     * A display name for a conversation: the group's name, or the other person.
     *
     * @param \stdClass $row With type, name, contextid, conversationid
     * @param int $userid
     * @return string
     */
    protected static function conversation_name(\stdClass $row, int $userid): string {
        global $DB;
        if ((int)$row->type === api::MESSAGE_CONVERSATION_TYPE_GROUP) {
            $context = $row->contextid ? \context::instance_by_id($row->contextid, IGNORE_MISSING) : null;
            return format_string((string)$row->name, true, ['context' => $context ?: \context_system::instance()]);
        }
        if ((int)$row->type === api::MESSAGE_CONVERSATION_TYPE_SELF) {
            return get_string('personalspace', 'message');
        }
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $other = $DB->get_record_sql(
            "SELECT u.id, $fields
                                        FROM {message_conversation_members} mcm
                                        JOIN {user} u ON u.id = mcm.userid
                                       WHERE mcm.conversationid = ? AND mcm.userid <> ?",
            [$row->conversationid, $userid],
            IGNORE_MULTIPLE
        );
        return $other ? fullname($other) : get_string('deleteduser', 'core');
    }

    /**
     * The message as plain text, as displayed (core's html_to_text() upper-cases bold text,
     * which suits email but not an excerpt).
     *
     * @param \stdClass $row With smallmessage and fullmessageformat
     * @return string
     */
    protected static function plain_text(\stdClass $row): string {
        $text = (string)$row->smallmessage;
        return sender::is_html($row->fullmessageformat) ? sender::html_to_plain($text) : $text;
    }

    /**
     * A short excerpt around the first match.
     *
     * @param string $text
     * @param string $query
     * @return string
     */
    public static function snippet(string $text, string $query): string {
        $text = trim(preg_replace('~\s+~u', ' ', $text));
        $pos = \core_text::strpos(\core_text::strtolower($text), \core_text::strtolower($query));
        $start = $pos === false ? 0 : max(0, $pos - 40);
        $snippet = \core_text::substr($text, $start, 160);
        return ($start > 0 ? '…' : '') . $snippet . (\core_text::strlen($text) > $start + 160 ? '…' : '');
    }
}
