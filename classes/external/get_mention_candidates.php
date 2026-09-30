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
use local_messagingsupercharger\local\features;
use local_messagingsupercharger\local\mentions;

/**
 * Members of a group conversation to offer in the mention list. Only members are returned, and only to a member.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_mention_candidates extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversationid' => new external_value(PARAM_INT, 'Conversation id'),
            'query' => new external_value(PARAM_TEXT, 'Text typed after @', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Search.
     *
     * @param int $conversationid
     * @param string $query
     * @return array
     */
    public static function execute(int $conversationid, string $query): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['conversationid' => $conversationid, 'query' => $query]);
        self::validate_context(\context_system::instance());
        features::require_enabled(features::MENTIONS);
        $conversation = conversations::get($params['conversationid']);
        conversations::require_member((int)$USER->id, (int)$conversation->id);
        conversations::require_capability('mention', $conversation, (int)$USER->id);
        return mentions::candidates((int)$conversation->id, (int)$USER->id, $params['query']);
    }

    /**
     * Returns.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'User id'),
            'fullname' => new external_value(PARAM_RAW, 'Full name'),
            'profileimageurl' => new external_value(PARAM_URL, 'Picture'),
        ]));
    }
}
