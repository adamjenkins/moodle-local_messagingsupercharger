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
use local_messagingsupercharger\local\extras;

/**
 * Plugin data for an open conversation.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_conversation_extras extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'Conversation id'),
            'messageids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Message id'),
                'Messages shown',
                VALUE_DEFAULT,
                []
            ),
            'since' => new external_value(PARAM_INT, 'Return new text for messages edited since this time', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Fetch.
     *
     * @param int $conversationid
     * @param array $messageids
     * @param int $since
     * @return array
     */
    public static function execute(int $conversationid, array $messageids, int $since): array {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/message/lib.php');
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversationid' => $conversationid, 'messageids' => $messageids, 'since' => $since]
        );
        self::validate_context(\context_system::instance());
        return extras::for_conversation($params['conversationid'], $params['messageids'], $params['since'], (int)$USER->id);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'conversationid' => new external_value(PARAM_INT, 'Conversation id'),
            'isgroup' => new external_value(PARAM_BOOL, 'Group conversation'),
            'servertime' => new external_value(PARAM_INT, 'Server time, to pass back as since'),
            'permissions' => new external_single_structure([
                'canpin' => new external_value(PARAM_BOOL, 'Can pin'),
                'canreact' => new external_value(PARAM_BOOL, 'Can react'),
                'cansendattachments' => new external_value(PARAM_BOOL, 'Can attach files'),
                'canuserichtext' => new external_value(PARAM_BOOL, 'Can use the rich text editor'),
                'canmention' => new external_value(PARAM_BOOL, 'Can mention'),
                'canschedule' => new external_value(PARAM_BOOL, 'Can schedule'),
                'cansend' => new external_value(PARAM_BOOL, 'Can send to this conversation now'),
            ]),
            'pins' => new external_multiple_structure(new external_single_structure([
                'messageid' => new external_value(PARAM_INT, 'Message id'),
                'text' => new external_value(PARAM_TEXT, 'Plain text excerpt'),
                'author' => new external_value(PARAM_TEXT, 'Author name'),
                'timecreated' => new external_value(PARAM_INT, 'Time sent'),
                'pinnedby' => new external_value(PARAM_TEXT, 'Who pinned it'),
            ])),
            'scheduled' => new external_multiple_structure(scheduled_structure::get()),
            'messages' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Message id'),
                'reactions' => new external_multiple_structure(new external_single_structure([
                    'key' => new external_value(PARAM_ALPHANUMEXT, 'Reaction key'),
                    'count' => new external_value(PARAM_INT, 'How many'),
                    'reacted' => new external_value(PARAM_BOOL, 'Whether the viewer reacted'),
                    'names' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Name')),
                ])),
                'edited' => new external_value(PARAM_BOOL, 'Edited'),
                'timeedited' => new external_value(PARAM_INT, 'Time of the last edit'),
                'text' => new external_value(PARAM_RAW, 'New formatted text if edited since the given time, else empty'),
                'canedit' => new external_value(PARAM_BOOL, 'Viewer may edit'),
                'candeleteforall' => new external_value(PARAM_BOOL, 'Viewer may delete for everyone'),
                'pinned' => new external_value(PARAM_BOOL, 'Pinned'),
                'previews' => new external_multiple_structure(new external_single_structure([
                    'url' => new external_value(PARAM_URL, 'Linked URL'),
                    'title' => new external_value(PARAM_TEXT, 'Title'),
                    'description' => new external_value(PARAM_TEXT, 'Description'),
                    'imageurl' => new external_value(PARAM_URL, 'Image served by Moodle, or empty'),
                ])),
                'seenby' => new external_multiple_structure(new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'User id'),
                    'fullname' => new external_value(PARAM_TEXT, 'Name'),
                    'timeread' => new external_value(PARAM_INT, 'When they read it'),
                ])),
            ])),
            'gone' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Message id'),
                'Requested messages that are deleted or no longer visible'
            ),
        ]);
    }
}
