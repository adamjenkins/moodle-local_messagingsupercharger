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

use local_messagingsupercharger\local\cleanup as cleaner;
use local_messagingsupercharger\local\emailhold;
use local_messagingsupercharger\local\scheduler;

/**
 * Hourly housekeeping: send any held email whose task was lost, and remove plugin data
 * left behind by deletions that core performs without events (groups, courses, privacy).
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskcleanup', 'local_messagingsupercharger');
    }

    /**
     * Run the housekeeping.
     */
    public function execute() {
        $sent = emailhold::send_due();
        if ($sent) {
            mtrace("Sent $sent overdue held message email(s).");
        }
        cleaner::sweep();

        // Scheduled messages whose delivery task never ran (deliver() ignores any that
        // were sent, changed or cancelled meanwhile).
        global $DB;
        $overdue = $DB->get_records_select(
            'local_messagingsupercharger_sched',
            'status = :status AND timesend < :cutoff',
            ['status' => scheduler::STATUS_PENDING, 'cutoff' => time() - 10 * MINSECS],
            'timesend',
            'id, timesend'
        );
        foreach ($overdue as $row) {
            scheduler::deliver((int)$row->id, (int)$row->timesend);
        }
    }
}
