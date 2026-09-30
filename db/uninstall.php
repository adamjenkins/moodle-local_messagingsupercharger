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

/**
 * Uninstall steps for local_messagingsupercharger.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Before the plugin's tables go: send the message emails it is still holding back (core
 * skipped them, so otherwise they would never be sent), and remove its user preference.
 *
 * @return bool
 */
function xmldb_local_messagingsupercharger_uninstall() {
    global $DB;
    $DB->set_field('local_messagingsupercharger_emailq', 'timedue', 0, ['claimtoken' => null]);
    \local_messagingsupercharger\local\emailhold::send_due();
    $DB->delete_records('user_preferences', ['name' => \local_messagingsupercharger\local\extras::PREF_SEENBY]);
    return true;
}
