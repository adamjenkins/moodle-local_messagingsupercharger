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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_messagingsupercharger\local\editing;
use local_messagingsupercharger\local\pins;
use local_messagingsupercharger\local\reactions;
use local_messagingsupercharger\local\scheduler;
use local_messagingsupercharger\local\sender;

/**
 * Tests for the privacy provider.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /** @var \stdClass */
    protected $ann;
    /** @var \stdClass */
    protected $ben;
    /** @var \stdClass */
    protected $cat;

    /**
     * Give Ann some of every kind of plugin data.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/message/lib.php');
        $this->resetAfterTest();
        set_config('emaildelay', 120, 'local_messagingsupercharger');
        $generator = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger');
        $this->ann = $this->getDataGenerator()->create_user(['firstname' => 'Ann', 'lastname' => 'X']);
        $this->ben = $this->getDataGenerator()->create_user(['firstname' => 'Ben', 'lastname' => 'X']);
        $this->cat = $this->getDataGenerator()->create_user(['firstname' => 'Cat', 'lastname' => 'X']);
        $individual = $generator->create_individual_conversation($this->ann, $this->ben);
        $group = $generator->create_group_conversation([$this->ann, $this->ben, $this->cat]);

        $this->setUser($this->ann);
        $draftitemid = file_get_unused_draft_itemid();
        $generator->create_draft_file($this->ann, $draftitemid, 'ann.txt', 'annsfile');
        // Not redirecting messages, so the email hold records a held email too.
        $message = sender::send((int)$this->ann->id, (int)$individual->id, 'Hello Ben', FORMAT_PLAIN, $draftitemid);
        editing::edit((int)$message->id, (int)$this->ann->id, 'Hello Ben!');
        pins::set((int)$message->id, (int)$this->ann->id, true);
        $groupmessage = sender::send(
            (int)$this->ann->id,
            (int)$group->id,
            'Hi @Ben X',
            FORMAT_PLAIN,
            0,
            0,
            [$this->ben->id]
        );
        reactions::toggle((int)$groupmessage->id, (int)$this->ann->id, 'thumbsup');
        scheduler::schedule((int)$this->ann->id, (int)$group->id, 'Tomorrow', FORMAT_PLAIN, time() + DAYSECS);
        set_user_preference('local_messagingsupercharger_showseenby', 0, $this->ann);

        // Ben reacts too, so we can check his data survives Ann's deletion.
        reactions::toggle((int)$groupmessage->id, (int)$this->ben->id, 'heart');
    }

    public function test_metadata(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('local_messagingsupercharger'));
        $tables = [];
        foreach ($collection->get_collection() as $item) {
            $tables[] = $item->get_name();
        }
        foreach (array_keys(provider::USER_COLUMNS) as $table) {
            $this->assertContains($table, $tables);
        }
        $this->assertContains('core_files', $tables);
    }

    public function test_contexts_and_users(): void {
        $contexts = provider::get_contexts_for_userid((int)$this->ann->id)->get_contextids();
        $this->assertSame([(int)\context_user::instance($this->ann->id)->id], array_map('intval', $contexts));
        $nobody = $this->getDataGenerator()->create_user();
        $this->assertSame([], provider::get_contexts_for_userid((int)$nobody->id)->get_contextids());

        $userlist = new userlist(\context_user::instance($this->ann->id), 'local_messagingsupercharger');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int)$this->ann->id], array_map('intval', $userlist->get_userids()));
    }

    public function test_export(): void {
        global $DB;
        $context = \context_user::instance($this->ann->id);
        $this->export_context_data_for_user((int)$this->ann->id, $context, 'local_messagingsupercharger');
        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
        $root = get_string('pluginname', 'local_messagingsupercharger');

        $reactions = $writer->get_data([$root, get_string('privacy:reactions', 'local_messagingsupercharger')]);
        $this->assertSame('thumbsup', $reactions->reactions[0]['reaction']);
        $messages = $writer->get_data([$root, get_string('privacy:messages', 'local_messagingsupercharger')]);
        $this->assertSame('Hello Ben', $messages->revisions[0]['previoustext']);
        $pins = $writer->get_data([$root, get_string('privacy:pins', 'local_messagingsupercharger')]);
        $this->assertCount(1, $pins->pins);
        $scheduled = $writer->get_data([$root, get_string('privacy:scheduled', 'local_messagingsupercharger')]);
        $this->assertSame('Tomorrow', $scheduled->scheduled[0]['text']);
        $emails = $writer->get_data([$root, get_string('privacy:heldemails', 'local_messagingsupercharger')]);
        $this->assertCount(1, $emails->emails);

        $setid = $DB->get_field('local_messagingsupercharger_attach', 'id', ['userid' => $this->ann->id]);
        $files = $writer->get_files([$root, get_string('attachments', 'local_messagingsupercharger'), $setid]);
        $this->assertArrayHasKey('ann.txt', $files);

        provider::export_user_preferences((int)$this->ann->id);
        $prefs = writer::with_context(\context_system::instance())->get_user_preferences('local_messagingsupercharger');
        $this->assertObjectHasProperty('local_messagingsupercharger_showseenby', $prefs);

        // Ben's mention is exported to Ben.
        $bencontext = \context_user::instance($this->ben->id);
        $this->export_context_data_for_user((int)$this->ben->id, $bencontext, 'local_messagingsupercharger');
        $mentions = writer::with_context($bencontext)->get_data([$root,
            get_string('privacy:mentions', 'local_messagingsupercharger')]);
        $this->assertCount(1, $mentions->mentions);
    }

    public function test_delete_for_user(): void {
        global $DB;
        $context = \context_user::instance($this->ann->id);
        $contextlist = new approved_contextlist($this->ann, 'local_messagingsupercharger', [$context->id]);
        provider::delete_data_for_user($contextlist);
        $this->assert_ann_gone();
        // Ben's data stays.
        $this->assertSame(1, $DB->count_records('local_messagingsupercharger_reaction', ['userid' => $this->ben->id]));
        // The pin Ann made stays for the conversation, no longer linked to her.
        $this->assertSame(1, $DB->count_records('local_messagingsupercharger_pin', ['userid' => 0]));
        $this->assertSame(1, $DB->count_records('local_messagingsupercharger_mention', ['userid' => $this->ben->id]));
    }

    public function test_delete_for_context(): void {
        provider::delete_data_for_all_users_in_context(\context_user::instance($this->ann->id));
        $this->assert_ann_gone();
        // A system context request deletes nothing.
        provider::delete_data_for_all_users_in_context(\context_system::instance());
        $this->assertTrue(provider::get_contexts_for_userid((int)$this->ben->id)->count() > 0);
    }

    public function test_delete_for_users(): void {
        $context = \context_user::instance($this->ann->id);
        provider::delete_data_for_users(new approved_userlist($context, 'local_messagingsupercharger', [$this->ann->id]));
        $this->assert_ann_gone();
    }

    /**
     * Ann has no plugin data or files left.
     */
    protected function assert_ann_gone(): void {
        global $DB;
        foreach (provider::USER_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame(0, $DB->count_records($table, [$column => $this->ann->id]), "$table.$column");
            }
        }
        $files = $DB->count_records_select('files', "component = 'local_messagingsupercharger' AND filename <> '.'");
        $this->assertSame(0, $files);
    }
}
