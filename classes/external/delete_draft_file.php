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

/**
 * Remove a file the user uploaded but has not sent.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_draft_file extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'draftitemid' => new external_value(PARAM_INT, 'Draft item id'),
            'filename' => new external_value(PARAM_FILE, 'File name'),
        ]);
    }

    /**
     * Delete.
     *
     * @param int $draftitemid
     * @param string $filename
     * @return array
     */
    public static function execute(int $draftitemid, string $filename): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['draftitemid' => $draftitemid, 'filename' => $filename]);
        self::validate_context(\context_system::instance());
        $fs = get_file_storage();
        $usercontext = \context_user::instance((int)$USER->id);
        $file = $fs->get_file($usercontext->id, 'user', 'draft', $params['draftitemid'], '/', $params['filename']);
        if ($file) {
            $file->delete();
        }
        return ['success' => (bool)$file];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'A file was deleted'),
        ]);
    }
}
