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

use core_external\external_single_structure;
use core_external\external_value;

/**
 * The web service description of a scheduled message, shared by several functions.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduled_structure {
    /**
     * The structure.
     *
     * @return external_single_structure
     */
    public static function get(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Scheduled message id'),
            'conversationid' => new external_value(PARAM_INT, 'Conversation id'),
            'text' => new external_value(PARAM_RAW, 'The author\'s text'),
            'format' => new external_value(PARAM_INT, 'Text format'),
            'timesend' => new external_value(PARAM_INT, 'When it will be sent'),
            'failed' => new external_value(PARAM_BOOL, 'Could not be sent'),
            'failreason' => new external_value(PARAM_TEXT, 'Why it could not be sent'),
            'attachments' => new external_value(PARAM_INT, 'Number of attachments'),
        ]);
    }
}
