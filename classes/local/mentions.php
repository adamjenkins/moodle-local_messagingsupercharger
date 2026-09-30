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
 * Mentions (typing @ and a name) in group conversations.
 *
 * The author picks people from a list of the conversation's members; the browser sends
 * their ids alongside the text, and they are stored in their own table rather than
 * parsed out of the text later. Each id is re-checked against the membership here.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mentions {
    /** @var int Most candidates returned by one autocomplete query. */
    const MAX_CANDIDATES = 20;

    /**
     * Keep only mentionable users: members of this group conversation other than the author.
     *
     * @param \stdClass $conversation
     * @param int $authorid
     * @param int[] $userids
     * @return array userid => full name
     */
    public static function resolve(\stdClass $conversation, int $authorid, array $userids): array {
        global $DB;
        $userids = array_unique(array_filter(array_map('intval', $userids)));
        if (!$userids || (int)$conversation->type !== api::MESSAGE_CONVERSATION_TYPE_GROUP) {
            return [];
        }
        $members = conversations::member_ids((int)$conversation->id);
        $userids = array_values(array_intersect($userids, $members));
        $userids = array_values(array_diff($userids, [$authorid]));
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $fields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        $users = $DB->get_records_select('user', "id $insql AND deleted = 0", $params, '', 'id, ' . $fields);
        $result = [];
        foreach ($users as $user) {
            $result[(int)$user->id] = fullname($user);
        }
        return $result;
    }

    /**
     * Turn "@Full Name" into a link to the person's profile.
     *
     * @param string $html Message HTML (names in it are HTML-escaped)
     * @param array $mentions userid => full name
     * @return string
     */
    public static function linkify(string $html, array $mentions): string {
        foreach ($mentions as $userid => $name) {
            $needle = '@' . s($name);
            if (strpos($html, $needle) === false) {
                continue;
            }
            $url = new \moodle_url('/user/profile.php', ['id' => $userid]);
            $link = \html_writer::link($url, $needle, ['class' => 'msgsc-mention']);
            $html = str_replace($needle, $link, $html);
        }
        return $html;
    }

    /**
     * Store mentions and notify the mentioned people through the plugin's own
     * notification type, so that a muted conversation can still reach them.
     *
     * @param \stdClass $conversation
     * @param int $messageid
     * @param int $authorid
     * @param array $mentions userid => full name
     * @param string $text Author's text
     * @param int $format FORMAT_PLAIN or FORMAT_HTML
     */
    public static function record_and_notify(
        \stdClass $conversation,
        int $messageid,
        int $authorid,
        array $mentions,
        string $text,
        int $format
    ): void {
        global $DB;
        $now = time();
        $author = \core_user::get_user($authorid);
        $plain = sender::is_html($format) ? sender::html_to_plain($text) : $text;
        $snippet = shorten_text(trim($plain), 200);
        $url = new \moodle_url('/message/index.php', ['convid' => $conversation->id]);
        $conversationname = format_string(
            $conversation->name ?? '',
            true,
            ['context' => conversations::context($conversation)]
        );

        foreach ($mentions as $userid => $unused) {
            if ($DB->record_exists('local_messagingsupercharger_mention', ['messageid' => $messageid, 'userid' => $userid])) {
                continue;
            }
            $DB->insert_record('local_messagingsupercharger_mention', (object)[
                'messageid' => $messageid,
                'conversationid' => $conversation->id,
                'userid' => $userid,
                'timecreated' => $now,
            ]);
            $recipient = \core_user::get_user($userid);
            if (!$recipient || $recipient->deleted || $recipient->suspended || api::is_blocked($userid, $authorid)) {
                continue;
            }
            $a = (object)[
                'name' => fullname($author),
                'conversation' => $conversationname,
                'text' => $snippet,
            ];
            $message = new \core\message\message();
            $message->component = features::COMPONENT;
            $message->name = 'mention';
            $message->userfrom = $author;
            $message->userto = $recipient;
            $message->notification = 1;
            $message->courseid = SITEID;
            $sm = get_string_manager();
            $message->subject = $sm->get_string('mentionsubject', features::COMPONENT, $a, $recipient->lang);
            $message->fullmessage = $sm->get_string('mentionbody', features::COMPONENT, $a, $recipient->lang);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '<p>' . s($message->fullmessage) . '</p>';
            $message->smallmessage = $sm->get_string('mentionsmall', features::COMPONENT, $a, $recipient->lang);
            $message->contexturl = $url->out(false);
            $message->contexturlname = $conversationname !== '' ? $conversationname
                : $sm->get_string('messages', 'message', null, $recipient->lang);
            message_send($message);
        }
    }

    /**
     * Members of a group conversation matching a search, for the mention autocomplete.
     * Only members are ever returned, and only to a member.
     *
     * @param int $conversationid
     * @param int $userid The person typing
     * @param string $query
     * @return array of ['id' => int, 'fullname' => string, 'profileimageurl' => string]
     */
    public static function candidates(int $conversationid, int $userid, string $query): array {
        global $DB, $PAGE;
        $conversation = conversations::get($conversationid);
        conversations::require_member($userid, $conversationid);
        if ((int)$conversation->type !== api::MESSAGE_CONVERSATION_TYPE_GROUP) {
            return [];
        }
        $fields = \core_user\fields::for_userpic()->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT $fields
                  FROM {message_conversation_members} mcm
                  JOIN {user} u ON u.id = mcm.userid
                 WHERE mcm.conversationid = :conversationid
                   AND u.id <> :userid
                   AND u.deleted = 0
              ORDER BY u.firstname, u.lastname, u.id";
        $members = $DB->get_records_sql($sql, ['conversationid' => $conversationid, 'userid' => $userid]);
        $query = \core_text::strtolower(trim($query));
        $result = [];
        foreach ($members as $member) {
            $name = fullname($member);
            if ($query !== '' && strpos(\core_text::strtolower($name), $query) === false) {
                continue;
            }
            $picture = new \user_picture($member);
            $picture->size = 35;
            $result[] = [
                'id' => (int)$member->id,
                'fullname' => $name,
                'profileimageurl' => $picture->get_url($PAGE)->out(false),
            ];
            if (count($result) >= self::MAX_CANDIDATES) {
                break;
            }
        }
        return $result;
    }
}
