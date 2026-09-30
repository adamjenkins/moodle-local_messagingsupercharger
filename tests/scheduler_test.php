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
use local_messagingsupercharger\local\scheduler;

/**
 * Tests for scheduled send, including the checks made at delivery time.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\scheduler::class)]
final class scheduler_test extends \advanced_testcase {
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
    }

    /**
     * Pretend the scheduled message is due now and run its task.
     *
     * @param int $id
     * @return int|null
     */
    protected function deliver_now(int $id): ?int {
        global $DB;
        $timesend = time() - 1;
        $DB->set_field('local_messagingsupercharger_sched', 'timesend', $timesend, ['id' => $id]);
        return scheduler::deliver($id, $timesend);
    }

    public function test_schedule_and_deliver(): void {
        global $DB;
        $this->redirectMessages();
        $id = scheduler::schedule((int)$this->alice->id, (int)$this->conversation->id, 'Later', FORMAT_PLAIN, time() + HOURSECS);
        $this->assertSame(0, $DB->count_records('messages', ['conversationid' => $this->conversation->id]));
        $list = scheduler::list((int)$this->alice->id, (int)$this->conversation->id);
        $this->assertCount(1, $list);
        $this->assertSame('Later', $list[0]['text']);

        // An ad hoc task was queued for the send time.
        $tasks = \core\task\manager::get_adhoc_tasks(task\send_scheduled_message::class);
        $this->assertCount(1, $tasks);

        // The task does nothing before it is due.
        $this->assertNull(scheduler::deliver($id, (int)$DB->get_field(
            'local_messagingsupercharger_sched',
            'timesend',
            ['id' => $id]
        )));

        $messageid = $this->deliver_now($id);
        $this->assertNotNull($messageid);
        $message = $DB->get_record('messages', ['id' => $messageid], '*', MUST_EXIST);
        $this->assertEquals($this->alice->id, $message->useridfrom);
        $this->assertStringContainsString('Later', $message->smallmessage);
        $this->assertFalse($DB->record_exists('local_messagingsupercharger_sched', ['id' => $id]));
    }

    public function test_blocked_recipient_at_delivery_time_fails(): void {
        global $DB;
        $this->redirectMessages();
        $id = scheduler::schedule(
            (int)$this->alice->id,
            (int)$this->conversation->id,
            'Blocked later',
            FORMAT_PLAIN,
            time() + HOURSECS
        );
        // Bob blocks Alice after she scheduled it.
        api::block_user($this->bob->id, $this->alice->id);
        $this->assertNull($this->deliver_now($id));
        $row = $DB->get_record('local_messagingsupercharger_sched', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(scheduler::STATUS_FAILED, $row->status);
        $this->assertSame('failedcannotsend', $row->failreason);
        $this->assertSame(0, $DB->count_records('messages', ['conversationid' => $this->conversation->id]));
    }

    public function test_left_group_at_delivery_time_fails(): void {
        global $DB;
        $carol = $this->getDataGenerator()->create_user();
        $group = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_group_conversation([$this->alice, $carol]);
        $id = scheduler::schedule((int)$this->alice->id, (int)$group->id, 'To the group', FORMAT_PLAIN, time() + HOURSECS);
        groups_remove_member($group->groupid, $this->alice->id);
        $this->assertNull($this->deliver_now($id));
        $this->assertSame('failednotmember', $DB->get_field('local_messagingsupercharger_sched', 'failreason', ['id' => $id]));
    }

    public function test_disabled_group_conversation_at_delivery_time_fails(): void {
        global $DB;
        $carol = $this->getDataGenerator()->create_user();
        $group = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_group_conversation([$this->alice, $carol]);
        $id = scheduler::schedule((int)$this->alice->id, (int)$group->id, 'To the group', FORMAT_PLAIN, time() + HOURSECS);
        api::disable_conversation((int)$group->id);
        $this->assertNull($this->deliver_now($id));
        $this->assertSame('failedcannotsend', $DB->get_field('local_messagingsupercharger_sched', 'failreason', ['id' => $id]));
    }

    public function test_edit_moves_the_delivery(): void {
        global $DB;
        $id = scheduler::schedule((int)$this->alice->id, (int)$this->conversation->id, 'First', FORMAT_PLAIN, time() + HOURSECS);
        $oldtime = (int)$DB->get_field('local_messagingsupercharger_sched', 'timesend', ['id' => $id]);
        scheduler::update($id, (int)$this->alice->id, 'Second', time() + 2 * HOURSECS);
        // The task queued for the old time now does nothing.
        $this->assertNull(scheduler::deliver($id, $oldtime));
        $this->assertSame('Second', $DB->get_field('local_messagingsupercharger_sched', 'body', ['id' => $id]));
        $this->assertCount(2, \core\task\manager::get_adhoc_tasks(task\send_scheduled_message::class));
    }

    public function test_only_owner_can_change_or_cancel(): void {
        $id = scheduler::schedule((int)$this->alice->id, (int)$this->conversation->id, 'Mine', FORMAT_PLAIN, time() + HOURSECS);
        foreach (['update', 'cancel'] as $action) {
            try {
                if ($action === 'update') {
                    scheduler::update($id, (int)$this->bob->id, 'Hijack', time() + HOURSECS);
                } else {
                    scheduler::cancel($id, (int)$this->bob->id);
                }
                $this->fail("Another user could $action");
            } catch (\moodle_exception $e) {
                $this->assertSame('schedulednotfound', $e->errorcode);
            }
        }
    }

    public function test_cancel_removes_attachments(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger');
        $draftitemid = file_get_unused_draft_itemid();
        $generator->create_draft_file($this->alice, $draftitemid, 'plan.txt');
        $id = scheduler::schedule(
            (int)$this->alice->id,
            (int)$this->conversation->id,
            'With file',
            FORMAT_PLAIN,
            time() + HOURSECS,
            $draftitemid
        );
        $setid = (int)$DB->get_field('local_messagingsupercharger_sched', 'attachsetid', ['id' => $id]);
        $this->assertCount(1, local\attachments::set_files($setid));
        scheduler::cancel($id, (int)$this->alice->id);
        $this->assertFalse($DB->record_exists('local_messagingsupercharger_sched', ['id' => $id]));
        $this->assertFalse($DB->record_exists('local_messagingsupercharger_attach', ['id' => $setid]));
        $this->assertCount(0, local\attachments::set_files($setid));
    }

    public function test_scheduled_attachments_arrive_with_message(): void {
        global $DB;
        $this->redirectMessages();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger');
        $draftitemid = file_get_unused_draft_itemid();
        $generator->create_draft_file($this->alice, $draftitemid, 'plan.txt');
        $id = scheduler::schedule(
            (int)$this->alice->id,
            (int)$this->conversation->id,
            'With file',
            FORMAT_PLAIN,
            time() + HOURSECS,
            $draftitemid
        );
        $messageid = $this->deliver_now($id);
        $message = $DB->get_record('messages', ['id' => $messageid]);
        $this->assertStringContainsString('plan.txt', $message->smallmessage);
        $this->assertTrue($DB->record_exists('local_messagingsupercharger_attach', ['messageid' => $messageid]));
    }

    public function test_time_must_be_in_the_future(): void {
        try {
            scheduler::schedule((int)$this->alice->id, (int)$this->conversation->id, 'Past', FORMAT_PLAIN, time() - 10);
            $this->fail('Past time accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('scheduleinpast', $e->errorcode);
        }
    }

    public function test_conversation_deleted_marks_scheduled_failed(): void {
        global $DB;
        $carol = $this->getDataGenerator()->create_user();
        $group = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_group_conversation([$this->alice, $carol]);
        $id = scheduler::schedule((int)$this->alice->id, (int)$group->id, 'Soon', FORMAT_PLAIN, time() + HOURSECS);
        groups_delete_group($group->groupid);
        $this->runAdhocTasks(task\sweep_orphans::class);
        // Kept, marked failed, so the author can see what happened.
        $row = $DB->get_record('local_messagingsupercharger_sched', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals(scheduler::STATUS_FAILED, $row->status);
        $this->assertSame('failednoconversation', $row->failreason);
        $this->assertSame(
            get_string('failednoconversation', 'local_messagingsupercharger'),
            scheduler::list((int)$this->alice->id)[0]['failreason']
        );
    }

    public function test_claimed_scheduled_message_is_not_sent_again(): void {
        global $DB;
        $this->redirectMessages();
        $id = scheduler::schedule(
            (int)$this->alice->id,
            (int)$this->conversation->id,
            'Once',
            FORMAT_PLAIN,
            time() + HOURSECS
        );
        $timesend = time() - 1;
        $DB->set_field('local_messagingsupercharger_sched', 'timesend', $timesend, ['id' => $id]);
        // Another runner has claimed it.
        $DB->set_field('local_messagingsupercharger_sched', 'status', scheduler::STATUS_SENDING, ['id' => $id]);
        $this->assertNull(scheduler::deliver($id, $timesend));
        $this->assertSame(0, $DB->count_records('messages', ['conversationid' => $this->conversation->id]));
        // And it cannot be edited while being sent.
        $this->expectExceptionMessage(get_string('schedulebeingsent', 'local_messagingsupercharger'));
        scheduler::update($id, (int)$this->alice->id, 'Changed', time() + HOURSECS);
    }

    public function test_scheduled_attaches_only_named_files(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger');
        $draftitemid = file_get_unused_draft_itemid();
        $generator->create_draft_file($this->alice, $draftitemid, 'chosen.txt');
        $generator->create_draft_file($this->alice, $draftitemid, 'other.txt');
        $id = scheduler::schedule(
            (int)$this->alice->id,
            (int)$this->conversation->id,
            'Later',
            FORMAT_PLAIN,
            time() + HOURSECS,
            $draftitemid,
            [],
            ['chosen.txt']
        );
        $setid = (int)$DB->get_field('local_messagingsupercharger_sched', 'attachsetid', ['id' => $id]);
        $this->assertSame(['chosen.txt'], array_map(fn($f) => $f->get_filename(), local\attachments::set_files($setid)));
        $this->assertSame(['other.txt'], array_map(
            fn($f) => $f->get_filename(),
            local\attachments::draft_files((int)$this->alice->id, $draftitemid)
        ));
    }
}
