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

namespace local_messagingsupercharger;

use core_message\api;
use local_messagingsupercharger\local\cleanup;
use local_messagingsupercharger\local\editing;
use local_messagingsupercharger\local\pins;
use local_messagingsupercharger\local\reactions;
use local_messagingsupercharger\local\sender;

/**
 * Tests for editing, revisions, delete for everyone and cleanup of plugin data.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\editing::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\cleanup::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\observer::class)]
final class editing_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $alice;
    /** @var \stdClass */
    protected $bob;
    /** @var \stdClass */
    protected $conversation;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/message/lib.php');
        $this->resetAfterTest();
        set_config('emaildelay', 0, 'local_messagingsupercharger');
        $this->alice = $this->getDataGenerator()->create_user();
        $this->bob = $this->getDataGenerator()->create_user();
        $this->conversation = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_individual_conversation($this->alice, $this->bob);
        $this->setUser($this->alice);
        $this->redirectMessages();
    }

    public function test_edit_keeps_revisions_and_updates_core_text(): void {
        global $DB;
        $message = sender::send((int)$this->alice->id, (int)$this->conversation->id, 'Version one', FORMAT_PLAIN);
        editing::edit((int)$message->id, (int)$this->alice->id, 'Version two');
        editing::edit((int)$message->id, (int)$this->alice->id, 'Version three');

        $row = $DB->get_record('messages', ['id' => $message->id]);
        $this->assertStringContainsString('Version three', $row->smallmessage);
        $this->assertStringContainsString('Version three', $row->fullmessage);
        $this->assertEquals(FORMAT_HTML, $row->fullmessageformat);

        $revisions = editing::revisions((int)$message->id, (int)$this->bob->id);
        $this->assertSame(['Version two', 'Version one'], array_column($revisions, 'text'));
        $this->assertNotEmpty($DB->get_field('local_messagingsupercharger_meta', 'timeedited', ['messageid' => $message->id]));
    }

    public function test_edit_keeps_attachments(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger');
        $draftitemid = file_get_unused_draft_itemid();
        $generator->create_draft_file($this->alice, $draftitemid, 'keep.txt');
        $message = sender::send((int)$this->alice->id, (int)$this->conversation->id, 'Typo', FORMAT_PLAIN, $draftitemid);
        $edited = editing::edit((int)$message->id, (int)$this->alice->id, 'Fixed');
        $this->assertStringContainsString('Fixed', $edited->smallmessage);
        $this->assertStringContainsString('keep.txt', $edited->smallmessage);
    }

    public function test_edit_core_sent_message(): void {
        $message = api::send_message_to_conversation((int)$this->alice->id, (int)$this->conversation->id, 'From core',
            FORMAT_MOODLE);
        $this->assertSame(['text' => 'From core', 'format' => (int)FORMAT_PLAIN],
            editing::get_editable((int)$message->id, (int)$this->alice->id));
        $edited = editing::edit((int)$message->id, (int)$this->alice->id, 'From core, edited');
        $this->assertStringContainsString('From core, edited', $edited->smallmessage);
        $this->assertSame(['From core'], array_column(editing::revisions((int)$message->id, (int)$this->alice->id), 'text'));
    }

    public function test_cannot_edit_others_messages(): void {
        $message = sender::send((int)$this->alice->id, (int)$this->conversation->id, 'Mine', FORMAT_PLAIN);
        try {
            editing::edit((int)$message->id, (int)$this->bob->id, 'Hijacked');
            $this->fail('Edited another person\'s message');
        } catch (\moodle_exception $e) {
            $this->assertSame('notyourmessage', $e->errorcode);
        }
    }

    public function test_edit_window(): void {
        global $DB;
        set_config('editwindow', 60, 'local_messagingsupercharger');
        $message = sender::send((int)$this->alice->id, (int)$this->conversation->id, 'Old', FORMAT_PLAIN);
        $DB->set_field('messages', 'timecreated', time() - 120, ['id' => $message->id]);
        try {
            editing::edit((int)$message->id, (int)$this->alice->id, 'Too late');
            $this->fail('Edited outside the window');
        } catch (\moodle_exception $e) {
            $this->assertSame('editwindowpassed', $e->errorcode);
        }
        set_config('editwindow', 0, 'local_messagingsupercharger');
        editing::edit((int)$message->id, (int)$this->alice->id, 'No limit now');
        $this->assertStringContainsString('No limit now', $DB->get_field('messages', 'smallmessage', ['id' => $message->id]));
    }

    public function test_edit_needs_capability(): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/messagingsupercharger:editownmessage', CAP_PROHIBIT, $roleid,
            \context_system::instance()->id, true);
        role_assign($roleid, $this->alice->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $message = sender::send((int)$this->alice->id, (int)$this->conversation->id, 'Locked', FORMAT_PLAIN);
        $this->expectException(\required_capability_exception::class);
        editing::edit((int)$message->id, (int)$this->alice->id, 'Changed');
    }

    public function test_delete_for_all_uses_core_and_purges_plugin_data(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger');
        $draftitemid = file_get_unused_draft_itemid();
        $generator->create_draft_file($this->alice, $draftitemid, 'gone.txt');
        $message = sender::send((int)$this->alice->id, (int)$this->conversation->id, 'Regret', FORMAT_PLAIN, $draftitemid);
        editing::edit((int)$message->id, (int)$this->alice->id, 'Regret more');
        reactions::toggle((int)$message->id, (int)$this->bob->id, 'thumbsup');
        pins::set((int)$message->id, (int)$this->bob->id, true);
        $setid = (int)$DB->get_field('local_messagingsupercharger_attach', 'id', ['messageid' => $message->id]);

        editing::delete_for_all((int)$message->id, (int)$this->alice->id);

        // Core's own delete-for-all: a DELETED action for every member.
        foreach ([$this->alice, $this->bob] as $user) {
            $this->assertTrue($DB->record_exists('message_user_actions',
                ['messageid' => $message->id, 'userid' => $user->id, 'action' => api::MESSAGE_ACTION_DELETED]));
        }
        // The observer removed the plugin's data and files.
        foreach (cleanup::MESSAGE_TABLES as $table) {
            $this->assertSame(0, $DB->count_records($table, ['messageid' => $message->id]), $table);
        }
        $this->assertFalse($DB->record_exists('local_messagingsupercharger_attach', ['id' => $setid]));
        $this->assertCount(0, local\attachments::set_files($setid));
    }

    public function test_delete_for_me_keeps_data_for_others(): void {
        global $DB;
        $message = sender::send((int)$this->alice->id, (int)$this->conversation->id, 'Keep for Bob', FORMAT_PLAIN);
        reactions::toggle((int)$message->id, (int)$this->bob->id, 'heart');
        api::delete_message((int)$this->alice->id, (int)$message->id);
        $this->assertSame(1, $DB->count_records('local_messagingsupercharger_reaction', ['messageid' => $message->id]));
    }

    public function test_cannot_delete_others_for_all(): void {
        $message = sender::send((int)$this->alice->id, (int)$this->conversation->id, 'Not yours', FORMAT_PLAIN);
        $this->expectException(\moodle_exception::class);
        editing::delete_for_all((int)$message->id, (int)$this->bob->id);
    }

    public function test_group_deletion_sweeps_plugin_data(): void {
        global $DB;
        $carol = $this->getDataGenerator()->create_user();
        $group = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_group_conversation([$this->alice, $carol]);
        $message = sender::send((int)$this->alice->id, (int)$group->id, 'In the group', FORMAT_PLAIN);
        reactions::toggle((int)$message->id, (int)$carol->id, 'party');
        groups_delete_group($group->groupid);
        $this->assertFalse($DB->record_exists('messages', ['id' => $message->id]));
        $this->assertSame(0, $DB->count_records('local_messagingsupercharger_reaction', ['messageid' => $message->id]));
        $this->assertSame(0, $DB->count_records('local_messagingsupercharger_meta', ['messageid' => $message->id]));
    }

    public function test_user_deletion_purges_their_plugin_data(): void {
        global $DB;
        $message = sender::send((int)$this->alice->id, (int)$this->conversation->id, 'Hi', FORMAT_PLAIN);
        reactions::toggle((int)$message->id, (int)$this->bob->id, 'wow');
        delete_user($this->bob);
        $this->assertSame(0, $DB->count_records('local_messagingsupercharger_reaction', ['userid' => $this->bob->id]));
    }
}
