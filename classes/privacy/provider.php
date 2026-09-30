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

namespace local_messagingsupercharger\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_messagingsupercharger\local\attachments;
use local_messagingsupercharger\local\extras;

/**
 * Privacy provider.
 *
 * Messages themselves belong to core_message. Everything this plugin stores about a
 * person (their reactions, edits and previous versions, pins, mentions of them, their
 * scheduled messages, held emails and the files they attached) is reported in that
 * person's user context. Attachment files live in the system context under this plugin
 * and are exported and deleted with their uploader's data.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /** @var array Table => user id columns. */
    const USER_COLUMNS = [
        'local_messagingsupercharger_meta' => ['userid'],
        'local_messagingsupercharger_attach' => ['userid'],
        'local_messagingsupercharger_mention' => ['userid'],
        'local_messagingsupercharger_reaction' => ['userid'],
        'local_messagingsupercharger_revision' => ['userid'],
        'local_messagingsupercharger_pin' => ['userid'],
        'local_messagingsupercharger_sched' => ['userid'],
        'local_messagingsupercharger_emailq' => ['useridfrom', 'useridto'],
    ];

    /**
     * Describe stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_messagingsupercharger_meta', [
            'messageid' => 'privacy:metadata:local_messagingsupercharger_meta:messageid',
            'userid' => 'privacy:metadata:local_messagingsupercharger_meta:userid',
            'body' => 'privacy:metadata:local_messagingsupercharger_meta:body',
            'timeedited' => 'privacy:metadata:local_messagingsupercharger_meta:timeedited',
        ], 'privacy:metadata:local_messagingsupercharger_meta');
        $collection->add_database_table('local_messagingsupercharger_attach', [
            'userid' => 'privacy:metadata:local_messagingsupercharger_attach:userid',
            'conversationid' => 'privacy:metadata:local_messagingsupercharger_attach:conversationid',
            'timecreated' => 'privacy:metadata:local_messagingsupercharger_attach:timecreated',
        ], 'privacy:metadata:local_messagingsupercharger_attach');
        $collection->add_database_table('local_messagingsupercharger_mention', [
            'messageid' => 'privacy:metadata:local_messagingsupercharger_mention:messageid',
            'userid' => 'privacy:metadata:local_messagingsupercharger_mention:userid',
            'timecreated' => 'privacy:metadata:local_messagingsupercharger_mention:timecreated',
        ], 'privacy:metadata:local_messagingsupercharger_mention');
        $collection->add_database_table('local_messagingsupercharger_reaction', [
            'messageid' => 'privacy:metadata:local_messagingsupercharger_reaction:messageid',
            'userid' => 'privacy:metadata:local_messagingsupercharger_reaction:userid',
            'reaction' => 'privacy:metadata:local_messagingsupercharger_reaction:reaction',
            'timecreated' => 'privacy:metadata:local_messagingsupercharger_reaction:timecreated',
        ], 'privacy:metadata:local_messagingsupercharger_reaction');
        $collection->add_database_table('local_messagingsupercharger_revision', [
            'messageid' => 'privacy:metadata:local_messagingsupercharger_revision:messageid',
            'userid' => 'privacy:metadata:local_messagingsupercharger_revision:userid',
            'body' => 'privacy:metadata:local_messagingsupercharger_revision:body',
            'timecreated' => 'privacy:metadata:local_messagingsupercharger_revision:timecreated',
        ], 'privacy:metadata:local_messagingsupercharger_revision');
        $collection->add_database_table('local_messagingsupercharger_pin', [
            'messageid' => 'privacy:metadata:local_messagingsupercharger_pin:messageid',
            'userid' => 'privacy:metadata:local_messagingsupercharger_pin:userid',
            'timecreated' => 'privacy:metadata:local_messagingsupercharger_pin:timecreated',
        ], 'privacy:metadata:local_messagingsupercharger_pin');
        $collection->add_database_table('local_messagingsupercharger_sched', [
            'userid' => 'privacy:metadata:local_messagingsupercharger_sched:userid',
            'conversationid' => 'privacy:metadata:local_messagingsupercharger_sched:conversationid',
            'body' => 'privacy:metadata:local_messagingsupercharger_sched:body',
            'timesend' => 'privacy:metadata:local_messagingsupercharger_sched:timesend',
        ], 'privacy:metadata:local_messagingsupercharger_sched');
        $collection->add_database_table('local_messagingsupercharger_emailq', [
            'messageid' => 'privacy:metadata:local_messagingsupercharger_emailq:messageid',
            'useridfrom' => 'privacy:metadata:local_messagingsupercharger_emailq:useridfrom',
            'useridto' => 'privacy:metadata:local_messagingsupercharger_emailq:useridto',
            'timedue' => 'privacy:metadata:local_messagingsupercharger_emailq:timedue',
        ], 'privacy:metadata:local_messagingsupercharger_emailq');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        $collection->add_user_preference(extras::PREF_SEENBY, 'privacy:metadata:preference:showseenby');
        $collection->add_external_location_link('linkpreview', [
            'url' => 'privacy:metadata:linkpreview:url',
        ], 'privacy:metadata:linkpreview');
        return $collection;
    }

    /**
     * Does the user have any plugin data?
     *
     * @param int $userid
     * @return bool
     */
    protected static function user_has_data(int $userid): bool {
        global $DB;
        foreach (self::USER_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if ($DB->record_exists($table, [$column => $userid])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Contexts holding the user's data: their own user context.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::user_has_data($userid)) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && self::user_has_data((int)$context->instanceid)) {
            $userlist->add_user((int)$context->instanceid);
        }
    }

    /**
     * Export the user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        $context = null;
        foreach ($contextlist->get_contexts() as $candidate) {
            if ($candidate instanceof \context_user && (int)$candidate->instanceid === $userid) {
                $context = $candidate;
            }
        }
        if (!$context) {
            return;
        }
        $root = [get_string('pluginname', 'local_messagingsupercharger')];
        $writer = writer::with_context($context);

        $reactions = [];
        foreach ($DB->get_records('local_messagingsupercharger_reaction', ['userid' => $userid], 'timecreated, id') as $row) {
            $reactions[] = ['messageid' => $row->messageid, 'reaction' => $row->reaction,
                'timecreated' => transform::datetime($row->timecreated)];
        }
        if ($reactions) {
            self::export_section($writer, $root, 'privacy:reactions', ['reactions' => $reactions]);
        }

        $mentions = [];
        foreach ($DB->get_records('local_messagingsupercharger_mention', ['userid' => $userid], 'timecreated, id') as $row) {
            $mentions[] = ['messageid' => $row->messageid, 'timecreated' => transform::datetime($row->timecreated)];
        }
        if ($mentions) {
            self::export_section($writer, $root, 'privacy:mentions', ['mentions' => $mentions]);
        }

        $edits = [];
        foreach ($DB->get_records('local_messagingsupercharger_meta', ['userid' => $userid], 'timecreated, id') as $row) {
            $edits[] = ['messageid' => $row->messageid, 'text' => $row->body,
                'timeedited' => $row->timeedited ? transform::datetime($row->timeedited) : null];
        }
        $revisions = [];
        foreach ($DB->get_records('local_messagingsupercharger_revision', ['userid' => $userid], 'timecreated, id') as $row) {
            $revisions[] = ['messageid' => $row->messageid, 'previoustext' => $row->body,
                'timereplaced' => transform::datetime($row->timecreated)];
        }
        if ($edits || $revisions) {
            self::export_section($writer, $root, 'privacy:messages', ['messages' => $edits, 'revisions' => $revisions]);
        }

        $pins = [];
        foreach ($DB->get_records('local_messagingsupercharger_pin', ['userid' => $userid], 'timecreated, id') as $row) {
            $pins[] = ['messageid' => $row->messageid, 'conversationid' => $row->conversationid,
                'timecreated' => transform::datetime($row->timecreated)];
        }
        if ($pins) {
            self::export_section($writer, $root, 'privacy:pins', ['pins' => $pins]);
        }

        $scheduled = [];
        foreach ($DB->get_records('local_messagingsupercharger_sched', ['userid' => $userid], 'timesend, id') as $row) {
            $scheduled[] = ['conversationid' => $row->conversationid, 'text' => $row->body,
                'timesend' => transform::datetime($row->timesend)];
        }
        if ($scheduled) {
            self::export_section($writer, $root, 'privacy:scheduled', ['scheduled' => $scheduled]);
        }

        $emails = [];
        $emailrows = $DB->get_records_select(
            'local_messagingsupercharger_emailq',
            'useridfrom = ? OR useridto = ?',
            [$userid, $userid]
        );
        foreach ($emailrows as $row) {
            $emails[] = ['messageid' => $row->messageid, 'timedue' => transform::datetime($row->timedue)];
        }
        if ($emails) {
            self::export_section($writer, $root, 'privacy:heldemails', ['emails' => $emails]);
        }

        $fs = get_file_storage();
        $syscontextid = \context_system::instance()->id;
        $filesroot = array_merge($root, [get_string('attachments', 'local_messagingsupercharger')]);
        foreach ($DB->get_records('local_messagingsupercharger_attach', ['userid' => $userid], 'id') as $set) {
            foreach ([attachments::AREA_ATTACHMENT, attachments::AREA_INLINE] as $area) {
                $files = $fs->get_area_files($syscontextid, 'local_messagingsupercharger', $area, $set->id, 'id', false);
                foreach ($files as $file) {
                    $writer->export_file(array_merge($filesroot, [$set->id]), $file);
                }
            }
        }
    }

    /**
     * Write one section of the export.
     *
     * @param \core_privacy\local\request\content_writer $writer
     * @param array $root Subcontext of the plugin
     * @param string $stringkey Lang string naming the section
     * @param array $data
     */
    protected static function export_section($writer, array $root, string $stringkey, array $data): void {
        $subcontext = array_merge($root, [get_string($stringkey, 'local_messagingsupercharger')]);
        $writer->export_data($subcontext, (object)$data);
    }

    /**
     * Export the seen-by preference.
     *
     * @param int $userid
     */
    public static function export_user_preferences(int $userid): void {
        $value = get_user_preferences(extras::PREF_SEENBY, null, $userid);
        if ($value !== null) {
            writer::export_user_preference(
                'local_messagingsupercharger',
                extras::PREF_SEENBY,
                $value ? get_string('yes') : get_string('no'),
                get_string('privacy:metadata:preference:showseenby', 'local_messagingsupercharger')
            );
        }
    }

    /**
     * Delete one user's plugin data.
     *
     * @param int $userid
     */
    protected static function delete_user(int $userid): void {
        global $DB;
        foreach ($DB->get_fieldset_select('local_messagingsupercharger_attach', 'id', 'userid = ?', [$userid]) as $setid) {
            attachments::delete_set((int)$setid);
        }
        foreach (self::USER_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $DB->delete_records($table, [$column => $userid]);
            }
        }
    }

    /**
     * Delete all data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context instanceof \context_user) {
            self::delete_user((int)$context->instanceid);
        }
    }

    /**
     * Delete a user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int)$context->instanceid === $userid) {
                self::delete_user($userid);
            }
        }
    }

    /**
     * Delete several users' data in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        if (in_array((int)$context->instanceid, array_map('intval', $userlist->get_userids()), true)) {
            self::delete_user((int)$context->instanceid);
        }
    }
}
