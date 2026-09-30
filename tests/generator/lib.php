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

use core_message\api;

/**
 * Test data generator for local_messagingsupercharger.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_messagingsupercharger_generator extends component_generator_base {
    /**
     * An individual conversation between two users.
     *
     * @param stdClass $a
     * @param stdClass $b
     * @return stdClass Conversation record
     */
    public function create_individual_conversation(stdClass $a, stdClass $b): stdClass {
        global $DB;
        // Contacts, so core's privacy rules (default: contacts and course members) let them message.
        api::add_contact($a->id, $b->id);
        $conversation = api::create_conversation(api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL, [$a->id, $b->id]);
        return $DB->get_record('message_conversations', ['id' => $conversation->id], '*', MUST_EXIST);
    }

    /**
     * A course group with messaging enabled, and its conversation.
     *
     * @param stdClass[] $members
     * @param stdClass|null $course Created if not given
     * @return stdClass Conversation record (with ->courseid and ->groupid added)
     */
    public function create_group_conversation(array $members, ?stdClass $course = null): stdClass {
        global $DB;
        $generator = $this->datagenerator;
        $course = $course ?? $generator->create_course();
        foreach ($members as $member) {
            $generator->enrol_user($member->id, $course->id, 'student');
        }
        $group = $generator->create_group(['courseid' => $course->id, 'name' => 'Team']);
        // Link the conversation as groups_create_group() does for an admin (it skips this for
        // other users), before adding members so core adds them to it.
        $context = context_course::instance($course->id);
        $created = api::create_conversation(
            api::MESSAGE_CONVERSATION_TYPE_GROUP,
            [],
            $group->name,
            api::MESSAGE_CONVERSATION_ENABLED,
            'core_group',
            'groups',
            $group->id,
            $context->id
        );
        foreach ($members as $member) {
            $generator->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
        }
        $conversation = $DB->get_record('message_conversations', ['id' => $created->id], '*', MUST_EXIST);
        $conversation->courseid = $course->id;
        $conversation->groupid = $group->id;
        return $conversation;
    }

    /**
     * Put a file in a user's draft area.
     *
     * @param stdClass $user
     * @param int $draftitemid
     * @param string $filename
     * @param string $content
     * @return stored_file
     */
    public function create_draft_file(
        stdClass $user,
        int $draftitemid,
        string $filename = 'notes.txt',
        string $content = 'hello'
    ): stored_file {
        $fs = get_file_storage();
        return $fs->create_file_from_string([
            'contextid' => context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $user->id,
        ], $content);
    }

    /**
     * A tiny valid PNG.
     *
     * @return string
     */
    public function png(): string {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
}
