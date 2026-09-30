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
use local_messagingsupercharger\local\search;

/**
 * Search the current user's messages.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_messages extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Search text'),
            'limitfrom' => new external_value(PARAM_INT, 'Offset', VALUE_DEFAULT, 0),
            'limitnum' => new external_value(PARAM_INT, 'How many', VALUE_DEFAULT, 20),
        ]);
    }

    /**
     * Search.
     *
     * @param string $query
     * @param int $limitfrom
     * @param int $limitnum
     * @return array
     */
    public static function execute(string $query, int $limitfrom, int $limitnum): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'query' => $query,
            'limitfrom' => $limitfrom,
            'limitnum' => $limitnum,
        ]);
        self::validate_context(\context_system::instance());
        return search::messages((int)$USER->id, $params['query'], max(0, $params['limitfrom']), $params['limitnum']);
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'results' => new external_multiple_structure(new external_single_structure([
                'messageid' => new external_value(PARAM_INT, 'Message id'),
                'conversationid' => new external_value(PARAM_INT, 'Conversation id'),
                'conversationname' => new external_value(PARAM_RAW, 'Conversation name'),
                'isgroup' => new external_value(PARAM_BOOL, 'Group conversation'),
                'author' => new external_value(PARAM_RAW, 'Sender'),
                'snippet' => new external_value(PARAM_RAW, 'Excerpt'),
                'timecreated' => new external_value(PARAM_INT, 'Time sent'),
            ])),
            'hasmore' => new external_value(PARAM_BOOL, 'More results exist'),
        ]);
    }
}
