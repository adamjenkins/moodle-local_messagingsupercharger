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
use local_messagingsupercharger\local\editing;

/**
 * Previous versions of an edited message.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_message_revisions extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'messageid' => new external_value(PARAM_INT, 'Message id'),
        ]);
    }

    /**
     * Fetch.
     *
     * @param int $messageid
     * @return array
     */
    public static function execute(int $messageid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['messageid' => $messageid]);
        self::validate_context(\context_system::instance());
        return editing::revisions($params['messageid'], (int)$USER->id);
    }

    /**
     * Returns.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'text' => new external_value(PARAM_RAW, 'Previous text (not formatted)'),
            'format' => new external_value(PARAM_INT, 'Format'),
            'timecreated' => new external_value(PARAM_INT, 'When it was replaced'),
        ]));
    }
}
