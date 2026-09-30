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

namespace local_messagingsupercharger\task;

use local_messagingsupercharger\local\scheduler;

/**
 * Sends one scheduled message when it is due.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_scheduled_message extends \core\task\adhoc_task {
    /**
     * A message that cannot be sent is marked failed; retrying could send it twice.
     *
     * @return bool
     */
    public function retry_until_success(): bool {
        return false;
    }

    /**
     * Send the message.
     */
    public function execute() {
        $data = $this->get_custom_data();
        if (empty($data->id)) {
            return;
        }
        $messageid = scheduler::deliver((int)$data->id, (int)($data->timesend ?? 0));
        if ($messageid) {
            mtrace("Sent scheduled message {$data->id} as message {$messageid}.");
        }
    }
}
