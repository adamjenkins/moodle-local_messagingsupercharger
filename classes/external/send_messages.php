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

namespace local_messagingsupercharger\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_messagingsupercharger\local\conversations;
use local_messagingsupercharger\local\sender;

/**
 * Send messages carrying plugin features, in place of core's drawer send calls.
 *
 * The drawer's own send code calls this instead of core_message_send_messages_to_conversation
 * / core_message_send_instant_messages when a message has attachments, rich text or
 * mentions (see amd/src/send_interceptor.js), so the response carries the fields both of
 * those core functions return.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_messages extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversationid' => new external_value(
                PARAM_INT,
                'Conversation id, 0 for a first message to touserid',
                VALUE_DEFAULT,
                0
            ),
            'touserid' => new external_value(
                PARAM_INT,
                'Recipient of a first message when there is no conversation yet',
                VALUE_DEFAULT,
                0
            ),
            'messages' => new external_multiple_structure(new external_single_structure([
                'text' => new external_value(PARAM_RAW, 'Message text'),
                'format' => new external_value(
                    PARAM_INT,
                    'FORMAT_PLAIN (drawer text with extras), FORMAT_HTML or FORMAT_MOODLE',
                    VALUE_DEFAULT,
                    FORMAT_PLAIN
                ),
                'draftitemid' => new external_value(PARAM_INT, 'Draft area of attachments', VALUE_DEFAULT, 0),
                'editordraftitemid' => new external_value(PARAM_INT, 'Draft area of embedded images', VALUE_DEFAULT, 0),
                'filenames' => new external_multiple_structure(
                    new external_value(PARAM_FILE, 'File name'),
                    'Attach only these files from the draft area (empty for all of them)',
                    VALUE_DEFAULT,
                    []
                ),
                'mentions' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Mentioned user id'),
                    'Mentioned users',
                    VALUE_DEFAULT,
                    []
                ),
            ])),
        ]);
    }

    /**
     * Send.
     *
     * @param int $conversationid
     * @param int $touserid
     * @param array $messages
     * @return array
     */
    public static function execute(int $conversationid, int $touserid, array $messages): array {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/message/lib.php');
        $params = self::validate_parameters(self::execute_parameters(), [
            'conversationid' => $conversationid,
            'touserid' => $touserid,
            'messages' => $messages,
        ]);
        self::validate_context(\context_system::instance());
        conversations::require_messaging_enabled();

        $conversationid = $params['conversationid'];
        if (!$conversationid) {
            if (!$params['touserid']) {
                throw new \invalid_parameter_exception('conversationid or touserid is required');
            }
            $conversationid = conversations::individual_conversation_for((int)$USER->id, $params['touserid']);
        }
        $conversation = conversations::get($conversationid);
        $candeleteforall = has_capability('moodle/site:deleteanymessage', conversations::context($conversation));

        // Check every message before sending any, so that a batch never half-sends. Two
        // messages may not claim the same files: the first would take them from the second.
        $claimed = [];
        foreach ($params['messages'] as $message) {
            foreach (['draftitemid' => $message['filenames'] ?: ['*'], 'editordraftitemid' => ['*']] as $area => $names) {
                if ((int)$message[$area] <= 0) {
                    continue;
                }
                foreach ($names as $name) {
                    $key = $message[$area] . '/' . $name;
                    $all = $message[$area] . '/*';
                    if (
                        isset($claimed[$key]) || isset($claimed[$all]) || ($name === '*' && preg_grep(
                            '~^' . preg_quote($message[$area] . '/', '~') . '~',
                            array_keys($claimed)
                        ))
                    ) {
                        throw new \invalid_parameter_exception('Two messages attach the same files');
                    }
                    $claimed[$key] = true;
                }
            }
        }
        foreach ($params['messages'] as $message) {
            sender::precheck(
                (int)$USER->id,
                $conversationid,
                $message['text'],
                (int)$message['format'],
                (int)$message['draftitemid'],
                (int)$message['editordraftitemid'],
                $message['mentions'],
                $message['filenames'] ?: null
            );
        }
        $results = [];
        foreach ($params['messages'] as $message) {
            $sent = sender::send(
                (int)$USER->id,
                $conversationid,
                $message['text'],
                (int)$message['format'],
                (int)$message['draftitemid'],
                (int)$message['editordraftitemid'],
                $message['mentions'],
                null,
                $message['filenames'] ?: null
            );
            $results[] = [
                'id' => (int)$sent->id,
                'msgid' => (int)$sent->id,
                'conversationid' => $conversationid,
                'useridfrom' => (int)$sent->useridfrom,
                'text' => sender::format_for_display($sent),
                'timecreated' => (int)$sent->timecreated,
                'candeletemessagesforallusers' => $candeleteforall,
            ];
        }
        return $results;
    }

    /**
     * Returns.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Message id'),
            'msgid' => new external_value(PARAM_INT, 'Message id (as core_message_send_instant_messages names it)'),
            'conversationid' => new external_value(PARAM_INT, 'Conversation id'),
            'useridfrom' => new external_value(PARAM_INT, 'Sender'),
            'text' => new external_value(PARAM_RAW, 'Formatted message text'),
            'timecreated' => new external_value(PARAM_INT, 'Time sent'),
            'candeletemessagesforallusers' => new external_value(PARAM_BOOL, 'As core returns it'),
        ]));
    }
}
