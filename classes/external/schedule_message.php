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
use local_messagingsupercharger\local\scheduler;

/**
 * Schedule a message to be sent later.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule_message extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'Conversation id'),
            'text' => new external_value(PARAM_RAW, 'Text'),
            'format' => new external_value(PARAM_INT, 'FORMAT_PLAIN or FORMAT_HTML', VALUE_DEFAULT, FORMAT_PLAIN),
            'timesend' => new external_value(PARAM_INT, 'When to send (unix time)'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft area of attachments', VALUE_DEFAULT, 0),
            'mentions' => new external_multiple_structure(
                new external_value(PARAM_INT, 'User id'),
                'Mentioned users',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Schedule.
     *
     * @param int $conversationid
     * @param string $text
     * @param int $format
     * @param int $timesend
     * @param int $draftitemid
     * @param array $mentions
     * @return array
     */
    public static function execute(
        int $conversationid,
        string $text,
        int $format,
        int $timesend,
        int $draftitemid,
        array $mentions
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'conversationid' => $conversationid,
            'text' => $text,
            'format' => $format,
            'timesend' => $timesend,
            'draftitemid' => $draftitemid,
            'mentions' => $mentions,
        ]);
        self::validate_context(\context_system::instance());
        $id = scheduler::schedule(
            (int)$USER->id,
            $params['conversationid'],
            $params['text'],
            $params['format'],
            $params['timesend'],
            $params['draftitemid'],
            $params['mentions']
        );
        return ['id' => $id];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Scheduled message id'),
        ]);
    }
}
