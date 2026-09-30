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
use local_messagingsupercharger\local\mentions;
use local_messagingsupercharger\local\sender;

/**
 * Tests for mentions.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\mentions::class)]
final class mentions_test extends \advanced_testcase {
    /** @var \stdClass[] */
    protected $users = [];
    /** @var \stdClass */
    protected $conversation;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/message/lib.php');
        $this->resetAfterTest();
        foreach (['Ann' => 'Author', 'Mia' => 'Mentioned', 'Ola' => 'Other'] as $first => $last) {
            $this->users[$first] = $this->getDataGenerator()->create_user(['firstname' => $first, 'lastname' => $last]);
        }
        $this->conversation = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_group_conversation(array_values($this->users));
        $this->setUser($this->users['Ann']);
    }

    public function test_mention_is_stored_linked_and_notified(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $mia = $this->users['Mia'];
        $message = sender::send(
            $this->users['Ann']->id,
            $this->conversation->id,
            'Hi @Mia Mentioned, see this',
            FORMAT_PLAIN,
            0,
            0,
            [$mia->id]
        );

        $this->assertTrue($DB->record_exists(
            'local_messagingsupercharger_mention',
            ['messageid' => $message->id, 'userid' => $mia->id]
        ));
        $this->assertStringContainsString('class="msgsc-mention"', $message->smallmessage);
        $this->assertStringContainsString('/user/profile.php?id=' . $mia->id, $message->smallmessage);

        $notifications = array_values(array_filter(
            $sink->get_messages(),
            fn($m) => ($m->component ?? '') === 'local_messagingsupercharger'
        ));
        $this->assertCount(1, $notifications);
        $this->assertEquals('mention', $notifications[0]->eventtype);
        $this->assertEquals($mia->id, $notifications[0]->useridto);
        $this->assertStringContainsString('Ann Author mentioned you', $notifications[0]->subject);
    }

    public function test_non_members_and_self_are_dropped(): void {
        global $DB;
        $this->redirectMessages();
        $outsider = $this->getDataGenerator()->create_user();
        $message = sender::send(
            $this->users['Ann']->id,
            $this->conversation->id,
            'Hello all',
            FORMAT_PLAIN,
            0,
            0,
            [$outsider->id, $this->users['Ann']->id]
        );
        $this->assertSame(0, $DB->count_records('local_messagingsupercharger_mention', ['messageid' => $message->id]));
    }

    public function test_blocked_author_does_not_notify(): void {
        global $DB;
        $sink = $this->redirectMessages();
        api::block_user($this->users['Mia']->id, $this->users['Ann']->id);
        sender::send(
            $this->users['Ann']->id,
            $this->conversation->id,
            'Hey @Mia Mentioned',
            FORMAT_PLAIN,
            0,
            0,
            [$this->users['Mia']->id]
        );
        $this->assertSame(1, $DB->count_records('local_messagingsupercharger_mention'));
        $notifications = array_filter($sink->get_messages(), fn($m) => ($m->component ?? '') === 'local_messagingsupercharger');
        $this->assertCount(0, $notifications);
    }

    public function test_mentions_only_in_group_conversations(): void {
        $conversation = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_individual_conversation($this->users['Ann'], $this->users['Mia']);
        $this->assertSame([], mentions::resolve($conversation, (int)$this->users['Ann']->id, [$this->users['Mia']->id]));
        $this->assertSame([], mentions::candidates((int)$conversation->id, (int)$this->users['Ann']->id, ''));
    }

    public function test_candidates_are_members_only(): void {
        $outsider = $this->getDataGenerator()->create_user(['firstname' => 'Mike', 'lastname' => 'Outsider']);
        $names = array_column(
            mentions::candidates((int)$this->conversation->id, (int)$this->users['Ann']->id, 'm'),
            'fullname'
        );
        $this->assertSame(['Mia Mentioned'], $names);
        $this->assertNotContains('Mike Outsider', $names);

        $all = array_column(mentions::candidates((int)$this->conversation->id, (int)$this->users['Ann']->id, ''), 'fullname');
        $this->assertSame(['Mia Mentioned', 'Ola Other'], $all);
    }

    public function test_non_member_cannot_list_members(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        try {
            external\get_mention_candidates::execute((int)$this->conversation->id, '');
            $this->fail('Non-member listed members');
        } catch (\moodle_exception $e) {
            $this->assertSame('notamember', $e->errorcode);
        }
    }

    public function test_mention_capability_is_enforced(): void {
        $context = \context_course::instance($this->conversation->courseid);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/messagingsupercharger:mention', CAP_PROHIBIT, $roleid, $context->id, true);
        role_assign($roleid, $this->users['Ann']->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->expectException(\required_capability_exception::class);
        sender::send(
            $this->users['Ann']->id,
            $this->conversation->id,
            'Hi @Mia Mentioned',
            FORMAT_PLAIN,
            0,
            0,
            [$this->users['Mia']->id]
        );
    }
}
