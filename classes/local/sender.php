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
 * Sends messages that use plugin features (attachments, rich text, mentions).
 *
 * Every message still goes through core's own api::send_message_to_conversation(),
 * so core stores it, delivers it to every processor and fires its events; this class
 * only prepares the text, checks the plugin's own permissions and records metadata.
 * The same code path is used by the web service and by scheduled delivery, so the
 * checks run with the sender's identity at the moment of sending.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sender {
    /** @var int Largest rich-text body accepted, in bytes (plain text uses core's limit). */
    const MAX_RICH_LENGTH = 20000;

    /**
     * Is this format HTML? Moodle's FORMAT_* constants are strings ('1'), so compare as ints.
     *
     * @param int|string $format
     * @return bool
     */
    public static function is_html($format): bool {
        return (int)$format === (int)FORMAT_HTML;
    }

    /**
     * Check a message can be sent, without sending it.
     *
     * @param int $userid Sender
     * @param \stdClass $conversation
     * @param string $text
     * @param int $format FORMAT_PLAIN or FORMAT_HTML
     * @param bool $hasattachments
     * @param int[] $mentionids
     * @throws \moodle_exception
     */
    public static function check(
        int $userid,
        \stdClass $conversation,
        string $text,
        int $format,
        bool $hasattachments,
        array $mentionids
    ): void {
        conversations::require_can_send($userid, (int)$conversation->id);
        if (self::is_html($format)) {
            features::require_enabled(features::RICHTEXT);
            conversations::require_capability('userichtext', $conversation, $userid);
            if (strlen($text) > self::MAX_RICH_LENGTH) {
                throw new \moodle_exception('messagetoolong', features::COMPONENT);
            }
        } else if (\core_text::strlen($text) > api::MESSAGE_MAX_LENGTH) {
            throw new \moodle_exception('messagetoolong', features::COMPONENT);
        }
        if ($hasattachments) {
            features::require_enabled(features::ATTACHMENTS);
            conversations::require_capability('sendattachments', $conversation, $userid);
        }
        if ($mentionids) {
            features::require_enabled(features::MENTIONS);
            conversations::require_capability('mention', $conversation, $userid);
        }
        if (trim(html_to_text($text, 0, false)) === '' && !$hasattachments && stripos($text, '<img') === false) {
            throw new \moodle_exception('emptymessage', features::COMPONENT);
        }
    }

    /**
     * Normalise a format: FORMAT_HTML (rich text), FORMAT_MOODLE (an ordinary drawer message
     * passed through as core sends it) or FORMAT_PLAIN (drawer text with plugin extras).
     *
     * @param int|string $format
     * @return int
     */
    public static function normalise_format($format): int {
        if (self::is_html($format)) {
            return (int)FORMAT_HTML;
        }
        return (int)$format === (int)FORMAT_MOODLE ? (int)FORMAT_MOODLE : (int)FORMAT_PLAIN;
    }

    /**
     * Check a message can be sent, including its attachments, without sending anything.
     * Used to check every message of a batch before any is sent.
     *
     * @param int $userid
     * @param int $conversationid
     * @param string $text
     * @param int $format
     * @param int $draftitemid
     * @param int $editordraftitemid
     * @param int[] $mentionids
     * @param string[]|null $filenames
     * @throws \moodle_exception
     */
    public static function precheck(
        int $userid,
        int $conversationid,
        string $text,
        int $format,
        int $draftitemid = 0,
        int $editordraftitemid = 0,
        array $mentionids = [],
        ?array $filenames = null
    ): void {
        $conversation = conversations::get($conversationid);
        $mentions = mentions::resolve($conversation, $userid, $mentionids);
        $attached = attachments::draft_files($userid, $draftitemid, $filenames);
        $inline = attachments::draft_files($userid, $editordraftitemid);
        self::check(
            $userid,
            $conversation,
            $text,
            self::normalise_format($format),
            $attached || $inline,
            array_keys($mentions)
        );
        attachments::validate_files($attached);
    }

    /**
     * Send a message.
     *
     * @param int $userid Sender
     * @param int $conversationid
     * @param string $text The author's text
     * @param int $format FORMAT_PLAIN (text typed in the drawer), FORMAT_HTML (rich text) or
     *        FORMAT_MOODLE (an ordinary message, sent exactly as core would)
     * @param int $draftitemid Draft area of attachments (0 for none)
     * @param int $editordraftitemid Draft area of images embedded in rich text (0 for none)
     * @param int[] $mentionids Users the author picked from the mention list
     * @param int|null $setid An attachment set prepared earlier (scheduled messages)
     * @param string[]|null $filenames Attach only these files from the draft area (null for all)
     * @return \stdClass The core messages record
     * @throws \moodle_exception If the message cannot be sent; nothing has been sent then
     */
    public static function send(
        int $userid,
        int $conversationid,
        string $text,
        int $format,
        int $draftitemid = 0,
        int $editordraftitemid = 0,
        array $mentionids = [],
        ?int $setid = null,
        ?array $filenames = null
    ): \stdClass {
        global $DB;

        $format = self::normalise_format($format);
        $conversation = conversations::get($conversationid);
        $mentions = mentions::resolve($conversation, $userid, $mentionids);
        $hasattachments = $setid !== null || attachments::draft_files($userid, $draftitemid, $filenames)
            || attachments::draft_files($userid, $editordraftitemid);
        self::check($userid, $conversation, $text, $format, $hasattachments, array_keys($mentions));

        if ($format === (int)FORMAT_MOODLE && !$hasattachments && !$mentions) {
            // Nothing for the plugin to add: send it exactly as core's drawer would.
            $sent = api::send_message_to_conversation($userid, $conversationid, $text, FORMAT_MOODLE);
            return $DB->get_record('messages', ['id' => $sent->id], '*', MUST_EXIST);
        }
        if ($format === (int)FORMAT_MOODLE) {
            $format = (int)FORMAT_PLAIN;
        }

        if ($setid === null) {
            [$setid, $text] = attachments::create_set(
                $userid,
                $conversationid,
                $draftitemid,
                $editordraftitemid,
                $text,
                $filenames
            );
        }

        $html = self::build_html($text, $format, $mentions, $setid);
        $sent = api::send_message_to_conversation($userid, $conversationid, $html, FORMAT_HTML);
        $messageid = (int)$sent->id;

        // The message has gone. Nothing below may make the caller think it has not, or a
        // retry would send it twice: failures are reported for debugging only.
        try {
            $DB->insert_record('local_messagingsupercharger_meta', (object)[
                'messageid' => $messageid,
                'conversationid' => $conversationid,
                'userid' => $userid,
                'body' => $text,
                'bodyformat' => $format,
                'attachsetid' => $setid,
                'timeedited' => null,
                'timecreated' => time(),
            ]);
            if ($setid) {
                attachments::link_to_message($setid, $messageid);
            }
            if ($mentions) {
                mentions::record_and_notify($conversation, $messageid, $userid, $mentions, $text, $format);
            }
            if (features::enabled(features::LINKPREVIEWS)) {
                linkpreviews::queue_for_html($html);
            }
        } catch (\Throwable $e) {
            debugging(
                'local_messagingsupercharger: after sending message ' . $messageid . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
        return $DB->get_record('messages', ['id' => $messageid], '*', MUST_EXIST);
    }

    /**
     * Turn the author's text into the stored message HTML.
     *
     * @param string $text
     * @param int $format
     * @param array $mentions userid => display name
     * @param int|null $setid
     * @return string
     */
    public static function build_html(string $text, int $format, array $mentions, ?int $setid): string {
        $html = self::is_html($format) ? $text : self::plain_to_html($text);
        $html = mentions::linkify($html, $mentions);
        if ($setid) {
            $html .= attachments::render_list($setid);
        }
        return $html;
    }

    /**
     * Convert text typed in the drawer to HTML: escape it, keep line breaks and link
     * bare URLs, as core would display it.
     *
     * @param string $text
     * @return string
     */
    public static function plain_to_html(string $text): string {
        $escaped = s(trim($text));
        $linked = preg_replace_callback('~\bhttps?://[^\s<>"\']+~i', function (array $matches): string {
            $url = rtrim($matches[0], '.,;:!?)');
            $trail = substr($matches[0], strlen($url));
            return '<a href="' . $url . '">' . $url . '</a>' . $trail;
        }, $escaped);
        return nl2br($linked, false);
    }

    /**
     * Message HTML as plain text, keeping the words as written (core's html_to_text()
     * upper-cases bold text, which suits email but not a preview or an excerpt).
     *
     * @param string $html
     * @return string
     */
    public static function html_to_plain(string $html): string {
        $text = preg_replace('~<(br|/p|/div|/li|/h[1-6])\b[^>]*>~i', ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('~\s+~u', ' ', $text));
    }

    /**
     * The message text as the drawer displays it, formatted by core.
     *
     * @param \stdClass $message A messages record
     * @return string
     */
    public static function format_for_display(\stdClass $message): string {
        return message_format_message_text($message);
    }
}
