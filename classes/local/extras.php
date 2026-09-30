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
 * Everything the browser shows on top of core for an open conversation, in one call:
 * permissions, pins, the viewer's scheduled messages, and per message reactions,
 * edits, link previews and (in groups) who has seen it. Core's drawer only ever
 * fetches new messages, so this call is also how edits, reactions and deletions by
 * other people reach an open conversation.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extras {
    /** @var string User preference: show and share "seen by". */
    const PREF_SEENBY = 'local_messagingsupercharger_showseenby';

    /** @var int Most messages described per call. */
    const MAX_MESSAGES = 200;

    /**
     * Build the extras for a conversation.
     *
     * @param int $conversationid
     * @param int[] $messageids Messages currently shown
     * @param int $since Only return text for messages edited after this time (0 = none)
     * @param int $viewerid
     * @return array
     */
    public static function for_conversation(int $conversationid, array $messageids, int $since, int $viewerid): array {
        global $DB;
        conversations::require_messaging_enabled();
        $conversation = conversations::get($conversationid);
        conversations::require_member($viewerid, $conversationid);

        $messageids = array_slice(array_unique(array_filter(array_map('intval', $messageids))), 0, self::MAX_MESSAGES);
        $messages = [];
        if ($messageids) {
            [$insql, $params] = $DB->get_in_or_equal($messageids, SQL_PARAMS_NAMED);
            $params['conversationid'] = $conversationid;
            $messages = $DB->get_records_select('messages', "id $insql AND conversationid = :conversationid", $params);
        }
        $deleted = [];
        if ($messages) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($messages), SQL_PARAMS_NAMED);
            $params['userid'] = $viewerid;
            $params['action'] = api::MESSAGE_ACTION_DELETED;
            $deleted = $DB->get_fieldset_select(
                'message_user_actions',
                'messageid',
                "messageid $insql AND userid = :userid AND action = :action",
                $params
            );
        }
        $deletedids = array_map('intval', $deleted);
        // Requested ids that no longer exist or are deleted for the viewer.
        $gone = array_values(array_diff($messageids, array_diff(array_keys($messages), $deletedids)));
        foreach ($deletedids as $id) {
            unset($messages[$id]);
        }

        $visibleids = array_map('intval', array_keys($messages));
        $reactions = features::enabled(features::REACTIONS) ? reactions::summary($visibleids, $viewerid) : [];
        $metas = [];
        if ($visibleids) {
            [$insql, $params] = $DB->get_in_or_equal($visibleids, SQL_PARAMS_NAMED);
            foreach ($DB->get_records_select('local_messagingsupercharger_meta', "messageid $insql", $params) as $meta) {
                $metas[(int)$meta->messageid] = $meta;
            }
        }
        $pinnedids = $DB->get_fieldset_select(
            'local_messagingsupercharger_pin',
            'messageid',
            'conversationid = ?',
            [$conversationid]
        );
        $pinnedids = array_map('intval', $pinnedids);
        $previews = linkpreviews::for_messages(array_map(function ($m) {
            return $m->smallmessage;
        }, $messages));
        $seenby = self::seen_by($conversation, $messages, $viewerid);
        $canpin = pins::can_pin($conversation, $viewerid);

        $out = [];
        foreach ($messages as $id => $message) {
            $meta = $metas[$id] ?? null;
            $edited = $meta && !empty($meta->timeedited);
            $entry = [
                'id' => (int)$id,
                'reactions' => $reactions[$id] ?? [],
                'edited' => $edited,
                'timeedited' => $edited ? (int)$meta->timeedited : 0,
                'text' => '',
                'canedit' => editing::can_edit($message, $conversation, $viewerid),
                'candeleteforall' => editing::can_delete_for_all($message, $conversation, $viewerid),
                'pinned' => in_array((int)$id, $pinnedids, true),
                'previews' => $previews[$id] ?? [],
                'seenby' => $seenby[$id] ?? [],
            ];
            if ($edited && $since > 0 && (int)$meta->timeedited >= $since) {
                $entry['text'] = sender::format_for_display($message);
            }
            $out[] = $entry;
        }

        return [
            'conversationid' => $conversationid,
            'isgroup' => (int)$conversation->type === api::MESSAGE_CONVERSATION_TYPE_GROUP,
            'servertime' => time(),
            'permissions' => [
                'canpin' => $canpin,
                'canreact' => features::enabled(features::REACTIONS)
                    && conversations::has_capability('react', $conversation, $viewerid),
                'cansendattachments' => features::enabled(features::ATTACHMENTS)
                    && conversations::has_capability('sendattachments', $conversation, $viewerid),
                'canuserichtext' => features::enabled(features::RICHTEXT)
                    && conversations::has_capability('userichtext', $conversation, $viewerid),
                'canmention' => features::enabled(features::MENTIONS)
                    && (int)$conversation->type === api::MESSAGE_CONVERSATION_TYPE_GROUP
                    && conversations::has_capability('mention', $conversation, $viewerid),
                'canschedule' => features::enabled(features::SCHEDULING)
                    && conversations::has_capability('schedulesend', $conversation, $viewerid),
                'cansend' => conversations::can_send($viewerid, $conversationid),
            ],
            'pins' => pins::list($conversation, $viewerid),
            'scheduled' => features::enabled(features::SCHEDULING) ? scheduler::list($viewerid, $conversationid) : [],
            'messages' => $out,
            'gone' => array_map('intval', $gone),
        ];
    }

    /**
     * Does a user share and see "seen by"?
     *
     * @param int $userid
     * @return bool
     */
    public static function shows_seen_by(int $userid): bool {
        return (bool)get_user_preferences(self::PREF_SEENBY, true, $userid);
    }

    /**
     * Who has seen the newest of the given messages, in a group conversation. Uses
     * core's own per-message read records. People who have switched "seen by" off are
     * neither shown to others nor shown who has seen anything.
     *
     * @param \stdClass $conversation
     * @param array $messages id => messages record
     * @param int $viewerid
     * @return array messageid => list of ['id', 'fullname', 'timeread']
     */
    public static function seen_by(\stdClass $conversation, array $messages, int $viewerid): array {
        global $CFG, $DB;
        if (
            !$messages || !features::enabled(features::SEENBY) || empty($CFG->messaging)
                || (int)$conversation->type !== api::MESSAGE_CONVERSATION_TYPE_GROUP || !self::shows_seen_by($viewerid)
        ) {
            return [];
        }
        $newest = null;
        foreach ($messages as $message) {
            if (
                $newest === null || (int)$message->timecreated > (int)$newest->timecreated
                    || ((int)$message->timecreated === (int)$newest->timecreated && (int)$message->id > (int)$newest->id)
            ) {
                $newest = $message;
            }
        }
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT u.id, mua.timecreated AS timeread, $fields
                  FROM {message_user_actions} mua
                  JOIN {message_conversation_members} mcm
                    ON mcm.userid = mua.userid AND mcm.conversationid = :conversationid
                  JOIN {user} u ON u.id = mua.userid AND u.deleted = 0
             LEFT JOIN {user_preferences} up ON up.userid = u.id AND up.name = :pref
                 WHERE mua.messageid = :messageid AND mua.action = :action
                   AND mua.userid <> :authorid
                   AND (up.id IS NULL OR " . $DB->sql_compare_text('up.value', 1) . " <> :off)
              ORDER BY mua.timecreated, u.id";
        $rows = $DB->get_records_sql($sql, [
            'conversationid' => $conversation->id,
            'pref' => self::PREF_SEENBY,
            'messageid' => $newest->id,
            'action' => api::MESSAGE_ACTION_READ,
            'authorid' => $newest->useridfrom,
            'off' => '0',
        ]);
        $list = [];
        foreach ($rows as $row) {
            $list[] = ['id' => (int)$row->id, 'fullname' => fullname($row), 'timeread' => (int)$row->timeread];
        }
        return [(int)$newest->id => $list];
    }
}
