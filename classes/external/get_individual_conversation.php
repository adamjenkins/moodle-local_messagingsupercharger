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
use core_external\external_single_structure;
use core_external\external_value;

/**
 * The id of the current user's existing individual conversation with another user.
 *
 * Core's drawer, when it opens a conversation by user rather than by conversation,
 * never exposes the conversation id in the page; the plugin asks for it here. Nothing
 * is created: 0 means there is no conversation yet.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_individual_conversation extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'otheruserid' => new external_value(PARAM_INT, 'The other user'),
        ]);
    }

    /**
     * Look up.
     *
     * @param int $otheruserid
     * @return array
     */
    public static function execute(int $otheruserid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['otheruserid' => $otheruserid]);
        self::validate_context(\context_system::instance());
        $id = \core_message\api::get_conversation_between_users([(int)$USER->id, $params['otheruserid']]);
        return ['conversationid' => (int)$id];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'conversationid' => new external_value(PARAM_INT, 'Conversation id, or 0 if there is none'),
        ]);
    }
}
