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
 * Editing and deleting one's own messages.
 *
 * Core has no concept of an edited message, so an edit rewrites the text columns of
 * the core messages row (the only place core reads text from) and keeps the previous
 * text in the plugin's revisions table. An edit never sends anything: core only
 * delivers a message when it is created, and a held email reads the text when it goes
 * out. Deleting for everyone reuses core's own delete-for-all-users, which records a
 * deletion for every member — no new kind of deletion is invented.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class editing {
    /**
     * Is the message still inside the edit window?
     *
     * @param \stdClass $message
     * @return bool
     */
    public static function within_window(\stdClass $message): bool {
        $window = features::edit_window();
        return $window === 0 || (time() - (int)$message->timecreated) <= $window;
    }

    /**
     * Load a message the user may edit, or throw.
     *
     * @param int $messageid
     * @param int $userid
     * @param string $capability 'editownmessage' or 'deleteownmessageforall'
     * @return array [message, conversation]
     * @throws \moodle_exception
     */
    protected static function require_own_message(int $messageid, int $userid, string $capability): array {
        features::require_enabled(features::EDITING);
        [$message, $conversation] = conversations::require_visible_message($messageid, $userid);
        if ((int)$message->useridfrom !== $userid) {
            throw new \moodle_exception('notyourmessage', features::COMPONENT);
        }
        conversations::require_capability($capability, $conversation, $userid);
        if (!self::within_window($message)) {
            throw new \moodle_exception('editwindowpassed', features::COMPONENT);
        }
        return [$message, $conversation];
    }

    /**
     * Can the user edit this message? For the interface only.
     *
     * @param \stdClass $message
     * @param \stdClass $conversation
     * @param int $userid
     * @return bool
     */
    public static function can_edit(\stdClass $message, \stdClass $conversation, int $userid): bool {
        return features::enabled(features::EDITING) && (int)$message->useridfrom === $userid
            && self::within_window($message) && conversations::has_capability('editownmessage', $conversation, $userid);
    }

    /**
     * Can the user delete this message for everyone? For the interface only.
     *
     * @param \stdClass $message
     * @param \stdClass $conversation
     * @param int $userid
     * @return bool
     */
    public static function can_delete_for_all(\stdClass $message, \stdClass $conversation, int $userid): bool {
        return features::enabled(features::EDITING) && (int)$message->useridfrom === $userid
            && self::within_window($message)
            && conversations::has_capability('deleteownmessageforall', $conversation, $userid);
    }

    /**
     * The text to put in the edit box.
     *
     * @param int $messageid
     * @param int $userid
     * @return array ['text' => string, 'format' => int]
     */
    public static function get_editable(int $messageid, int $userid): array {
        global $DB;
        [$message] = self::require_own_message($messageid, $userid, 'editownmessage');
        $meta = $DB->get_record('local_messagingsupercharger_meta', ['messageid' => $message->id]);
        if ($meta) {
            return ['text' => (string)$meta->body, 'format' => (int)$meta->bodyformat];
        }
        // Sent by core: its text is in smallmessage.
        $format = sender::is_html($message->fullmessageformat) ? (int)FORMAT_HTML : (int)FORMAT_PLAIN;
        return ['text' => (string)$message->smallmessage, 'format' => $format];
    }

    /**
     * Edit a message.
     *
     * @param int $messageid
     * @param int $userid
     * @param string $text New text, in the same format as the original body
     * @return \stdClass The updated messages record
     * @throws \moodle_exception
     */
    public static function edit(int $messageid, int $userid, string $text): \stdClass {
        global $DB;
        [$message, $conversation] = self::require_own_message($messageid, $userid, 'editownmessage');
        conversations::require_can_send($userid, (int)$conversation->id);

        $meta = $DB->get_record('local_messagingsupercharger_meta', ['messageid' => $message->id]);
        $now = time();
        if (!$meta) {
            $format = sender::is_html($message->fullmessageformat) ? (int)FORMAT_HTML : (int)FORMAT_PLAIN;
            $meta = (object)[
                'messageid' => $message->id,
                'conversationid' => $conversation->id,
                'userid' => $userid,
                'body' => (string)$message->smallmessage,
                'bodyformat' => $format,
                'attachsetid' => null,
                'timeedited' => null,
                'timecreated' => (int)$message->timecreated,
            ];
            $meta->id = $DB->insert_record('local_messagingsupercharger_meta', $meta);
        }
        $format = sender::is_html($meta->bodyformat) ? (int)FORMAT_HTML : (int)FORMAT_PLAIN;
        if (sender::is_html($format)) {
            conversations::require_capability('userichtext', $conversation, $userid);
        }
        $hasattachments = !empty($meta->attachsetid);
        sender::check($userid, $conversation, $text, $format, $hasattachments, []);
        if ((string)$meta->body === $text) {
            return $message;
        }

        $mentions = [];
        $mentioned = $DB->get_fieldset_select('local_messagingsupercharger_mention', 'userid', 'messageid = ?', [$message->id]);
        if ($mentioned) {
            $mentions = mentions::resolve($conversation, $userid, $mentioned);
        }
        $html = sender::build_html($text, $format, $mentions, $meta->attachsetid ? (int)$meta->attachsetid : null);

        $transaction = $DB->start_delegated_transaction();
        $DB->insert_record('local_messagingsupercharger_revision', (object)[
            'messageid' => $message->id,
            'conversationid' => $conversation->id,
            'userid' => $userid,
            'body' => $meta->body,
            'bodyformat' => $meta->bodyformat,
            'timecreated' => $now,
        ]);
        $DB->update_record('local_messagingsupercharger_meta', (object)['id' => $meta->id, 'body' => $text, 'timeedited' => $now]);
        $DB->update_record('messages', (object)[
            'id' => $message->id,
            'smallmessage' => $html,
            'fullmessage' => html_to_text($html),
            'fullmessagehtml' => $html,
            'fullmessageformat' => FORMAT_HTML,
        ]);
        $transaction->allow_commit();

        // The plugin's own search reads the messages table, so it finds the new text at
        // once. Core's global search indexes by creation time and keeps the old text.
        return $DB->get_record('messages', ['id' => $message->id], '*', MUST_EXIST);
    }

    /**
     * Delete one's own message for every member, using core's own mechanism.
     *
     * @param int $messageid
     * @param int $userid
     * @throws \moodle_exception
     */
    public static function delete_for_all(int $messageid, int $userid): void {
        [$message] = self::require_own_message($messageid, $userid, 'deleteownmessageforall');
        api::delete_message_for_all_users((int)$message->id);
    }

    /**
     * Previous versions of a message, newest first. Visible to members only.
     *
     * @param int $messageid
     * @param int $userid Viewer
     * @return array of ['text' => string, 'format' => int, 'timecreated' => int]
     */
    public static function revisions(int $messageid, int $userid): array {
        global $DB;
        conversations::require_visible_message($messageid, $userid);
        $rows = $DB->get_records('local_messagingsupercharger_revision', ['messageid' => $messageid], 'timecreated DESC, id DESC');
        $result = [];
        foreach ($rows as $row) {
            $result[] = ['text' => (string)$row->body, 'format' => (int)$row->bodyformat,
                'timecreated' => (int)$row->timecreated];
        }
        return $result;
    }
}
