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
use local_messagingsupercharger\local\features;
use local_messagingsupercharger\local\scheduler;

/**
 * The current user's scheduled messages.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_scheduled_messages extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'Only this conversation (0 for all)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * List.
     *
     * @param int $conversationid
     * @return array
     */
    public static function execute(int $conversationid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['conversationid' => $conversationid]);
        self::validate_context(\context_system::instance());
        features::require_enabled(features::SCHEDULING);
        return scheduler::list((int)$USER->id, $params['conversationid'] ?: null);
    }

    /**
     * Returns.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(scheduled_structure::get());
    }
}
