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
 * Event observers for local_messagingsupercharger.
 *
 * Group, course and privacy deletions remove messages without firing events, so the
 * scheduled cleanup task sweeps orphans as well; these observers only make the
 * common cases immediate.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\message_deleted',
        'callback' => '\local_messagingsupercharger\observer::message_deleted',
    ],
    [
        'eventname' => '\core\event\group_deleted',
        'callback' => '\local_messagingsupercharger\observer::sweep',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\local_messagingsupercharger\observer::sweep',
    ],
];
