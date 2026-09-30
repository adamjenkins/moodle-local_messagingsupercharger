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
 * Web service functions for local_messagingsupercharger.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_messagingsupercharger_send_messages' => [
        'classname' => \local_messagingsupercharger\external\send_messages::class,
        'description' => 'Send messages with attachments, rich text or mentions.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_get_individual_conversation' => [
        'classname' => \local_messagingsupercharger\external\get_individual_conversation::class,
        'description' => 'The id of the current user\'s conversation with another user, if any.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_get_conversation_extras' => [
        'classname' => \local_messagingsupercharger\external\get_conversation_extras::class,
        'description' => 'Reactions, edits, pins, previews and seen-by for an open conversation.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_toggle_reaction' => [
        'classname' => \local_messagingsupercharger\external\toggle_reaction::class,
        'description' => 'Add or remove a reaction.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_get_mention_candidates' => [
        'classname' => \local_messagingsupercharger\external\get_mention_candidates::class,
        'description' => 'Members of a group conversation to mention.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_get_editable_message' => [
        'classname' => \local_messagingsupercharger\external\get_editable_message::class,
        'description' => 'Text of an own message for editing.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_edit_message' => [
        'classname' => \local_messagingsupercharger\external\edit_message::class,
        'description' => 'Edit an own message.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_delete_message_for_all' => [
        'classname' => \local_messagingsupercharger\external\delete_message_for_all::class,
        'description' => 'Delete an own message for everyone.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_get_message_revisions' => [
        'classname' => \local_messagingsupercharger\external\get_message_revisions::class,
        'description' => 'Previous versions of an edited message.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_set_pinned' => [
        'classname' => \local_messagingsupercharger\external\set_pinned::class,
        'description' => 'Pin or unpin a message.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_schedule_message' => [
        'classname' => \local_messagingsupercharger\external\schedule_message::class,
        'description' => 'Schedule a message.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_update_scheduled_message' => [
        'classname' => \local_messagingsupercharger\external\update_scheduled_message::class,
        'description' => 'Change a scheduled message.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_cancel_scheduled_message' => [
        'classname' => \local_messagingsupercharger\external\cancel_scheduled_message::class,
        'description' => 'Cancel a scheduled message.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_get_scheduled_messages' => [
        'classname' => \local_messagingsupercharger\external\get_scheduled_messages::class,
        'description' => 'List scheduled messages.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_search_messages' => [
        'classname' => \local_messagingsupercharger\external\search_messages::class,
        'description' => 'Search messages.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_messagingsupercharger_delete_draft_file' => [
        'classname' => \local_messagingsupercharger\external\delete_draft_file::class,
        'description' => 'Remove an uploaded but unsent file.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
