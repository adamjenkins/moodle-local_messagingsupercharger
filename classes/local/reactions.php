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
 * Reactions on individual messages.
 *
 * Reactions are stored as short keys rather than emoji characters, so they work on
 * databases without four-byte UTF-8 support; the browser maps keys to emoji.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reactions {
    /** @var string[] Reaction key => emoji code point(s), hex. */
    const REACTIONS = [
        'thumbsup' => '1F44D',
        'heart' => '2764',
        'laugh' => '1F602',
        'wow' => '1F62E',
        'sad' => '1F622',
        'party' => '1F389',
    ];

    /**
     * The reactions for the browser: key, emoji and accessible label.
     *
     * @return array
     */
    public static function for_js(): array {
        $result = [];
        foreach (self::REACTIONS as $key => $codepoint) {
            $result[] = [
                'key' => $key,
                'emoji' => \core_text::code2utf8(hexdec($codepoint)),
                'label' => get_string('reaction_' . $key, features::COMPONENT),
            ];
        }
        return $result;
    }

    /**
     * Add or remove the user's reaction to a message.
     *
     * @param int $messageid
     * @param int $userid
     * @param string $reaction
     * @return bool True if the reaction is now present
     * @throws \moodle_exception
     */
    public static function toggle(int $messageid, int $userid, string $reaction): bool {
        global $DB;
        features::require_enabled(features::REACTIONS);
        if (!array_key_exists($reaction, self::REACTIONS)) {
            throw new \moodle_exception('invalidreaction', features::COMPONENT);
        }
        [$message, $conversation] = conversations::require_visible_message($messageid, $userid);
        conversations::require_capability('react', $conversation, $userid);

        $params = ['messageid' => $messageid, 'userid' => $userid, 'reaction' => $reaction];
        if ($DB->record_exists('local_messagingsupercharger_reaction', $params)) {
            $DB->delete_records('local_messagingsupercharger_reaction', $params);
            return false;
        }
        $DB->insert_record('local_messagingsupercharger_reaction', (object)($params + [
            'conversationid' => $conversation->id,
            'timecreated' => time(),
        ]));
        return true;
    }

    /**
     * Reactions for a set of messages, for display.
     *
     * @param int[] $messageids Messages already checked to be visible to the viewer
     * @param int $viewerid
     * @return array messageid => list of ['key', 'count', 'reacted', 'names']
     */
    public static function summary(array $messageids, int $viewerid): array {
        global $DB;
        if (!$messageids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($messageids, SQL_PARAMS_NAMED);
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT r.id, r.messageid, r.reaction, r.userid, $fields
                  FROM {local_messagingsupercharger_reaction} r
                  JOIN {user} u ON u.id = r.userid
                 WHERE r.messageid $insql AND u.deleted = 0
              ORDER BY r.timecreated, r.id";
        $grouped = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $key = $row->reaction;
            if (!isset($grouped[$row->messageid][$key])) {
                $grouped[$row->messageid][$key] = ['key' => $key, 'count' => 0, 'reacted' => false, 'names' => []];
            }
            $entry = &$grouped[$row->messageid][$key];
            $entry['count']++;
            $entry['names'][] = fullname($row);
            if ((int)$row->userid === $viewerid) {
                $entry['reacted'] = true;
            }
            unset($entry);
        }
        $order = array_keys(self::REACTIONS);
        $result = [];
        foreach ($grouped as $messageid => $entries) {
            uksort($entries, function ($a, $b) use ($order) {
                return array_search($a, $order) <=> array_search($b, $order);
            });
            $result[$messageid] = array_values($entries);
        }
        return $result;
    }
}
