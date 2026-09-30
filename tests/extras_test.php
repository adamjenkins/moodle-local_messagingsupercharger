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
use local_messagingsupercharger\local\editing;
use local_messagingsupercharger\local\extras;
use local_messagingsupercharger\local\pins;
use local_messagingsupercharger\local\reactions;
use local_messagingsupercharger\local\sender;

/**
 * Tests for reactions, pins, seen-by and the conversation extras web service.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\extras::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\reactions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\pins::class)]
final class extras_test extends \advanced_testcase {
    /** @var \stdClass[] */
    protected $users = [];
    /** @var \stdClass */
    protected $group;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/message/lib.php');
        $this->resetAfterTest();
        set_config('emaildelay', 0, 'local_messagingsupercharger');
        foreach (['ann', 'ben', 'cat'] as $name) {
            $this->users[$name] = $this->getDataGenerator()->create_user(['firstname' => ucfirst($name), 'lastname' => 'X']);
        }
        $this->group = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_group_conversation(array_values($this->users));
        $this->redirectMessages();
    }

    /**
     * Send as Ann and mark it read by Ben and Cat (without the message sink doing so).
     *
     * @param string $text
     * @return \stdClass
     */
    protected function send_and_read(string $text): \stdClass {
        global $DB;
        $this->setUser($this->users['ann']);
        $message = sender::send((int)$this->users['ann']->id, (int)$this->group->id, $text, FORMAT_PLAIN);
        // The message sink marks messages read for everyone; start from unread.
        $DB->delete_records('message_user_actions', ['messageid' => $message->id]);
        foreach (['ben', 'cat'] as $name) {
            api::mark_message_as_read((int)$this->users[$name]->id, $message);
        }
        return $message;
    }

    public function test_reactions_toggle_and_summary(): void {
        $message = $this->send_and_read('React to me');
        $this->assertTrue(reactions::toggle((int)$message->id, (int)$this->users['ben']->id, 'thumbsup'));
        $this->assertTrue(reactions::toggle((int)$message->id, (int)$this->users['cat']->id, 'thumbsup'));
        $this->assertTrue(reactions::toggle((int)$message->id, (int)$this->users['cat']->id, 'heart'));
        $this->assertFalse(reactions::toggle((int)$message->id, (int)$this->users['cat']->id, 'heart'));

        $summary = reactions::summary([(int)$message->id], (int)$this->users['ben']->id)[$message->id];
        $this->assertCount(1, $summary);
        $this->assertSame('thumbsup', $summary[0]['key']);
        $this->assertSame(2, $summary[0]['count']);
        $this->assertTrue($summary[0]['reacted']);
        $this->assertSame(['Ben X', 'Cat X'], $summary[0]['names']);
    }

    public function test_reactions_rejected_for_non_members_and_unknown_keys(): void {
        $message = $this->send_and_read('Private');
        $outsider = $this->getDataGenerator()->create_user();
        try {
            reactions::toggle((int)$message->id, (int)$outsider->id, 'thumbsup');
            $this->fail('Outsider reacted');
        } catch (\moodle_exception $e) {
            $this->assertSame('notamember', $e->errorcode);
        }
        try {
            reactions::toggle((int)$message->id, (int)$this->users['ben']->id, '<script>');
            $this->fail('Unknown reaction accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidreaction', $e->errorcode);
        }
    }

    public function test_pinning_rules(): void {
        $message = $this->send_and_read('Pin me');
        // Students cannot pin in a group conversation.
        try {
            pins::set((int)$message->id, (int)$this->users['ben']->id, true);
            $this->fail('Student pinned in a group');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissiontopin', $e->errorcode);
        }
        // A teacher of the course can.
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->group->courseid, 'editingteacher');
        groups_add_member($this->group->groupid, $teacher->id);
        pins::set((int)$message->id, (int)$teacher->id, true);
        $conversation = local\conversations::get((int)$this->group->id);
        $list = pins::list($conversation, (int)$this->users['ben']->id);
        $this->assertCount(1, $list);
        $this->assertSame('Pin me', $list[0]['text']);

        // In an individual conversation either member may pin.
        $individual = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_individual_conversation($this->users['ben'], $this->users['cat']);
        $this->setUser($this->users['ben']);
        $other = sender::send((int)$this->users['ben']->id, (int)$individual->id, 'Just us', FORMAT_PLAIN);
        pins::set((int)$other->id, (int)$this->users['cat']->id, true);
        $this->assertCount(1, pins::list($individual, (int)$this->users['ben']->id));
    }

    public function test_seen_by_uses_core_read_state_and_preferences(): void {
        $message = $this->send_and_read('Did you see?');
        $conversation = local\conversations::get((int)$this->group->id);
        $messages = [$message->id => $message];

        $seen = extras::seen_by($conversation, $messages, (int)$this->users['ann']->id);
        $this->assertSame(['Ben X', 'Cat X'], array_column($seen[$message->id], 'fullname'));

        // Cat opts out: she is no longer listed to others...
        set_user_preference(extras::PREF_SEENBY, 0, $this->users['cat']);
        $seen = extras::seen_by($conversation, $messages, (int)$this->users['ann']->id);
        $this->assertSame(['Ben X'], array_column($seen[$message->id], 'fullname'));
        // ...and she sees nobody's read state.
        $this->assertSame([], extras::seen_by($conversation, $messages, (int)$this->users['cat']->id));

        // Switched off site-wide: nothing.
        set_config('enableseenby', 0, 'local_messagingsupercharger');
        $this->assertSame([], extras::seen_by($conversation, $messages, (int)$this->users['ann']->id));
    }

    public function test_seen_by_not_in_individual_conversations(): void {
        $individual = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_individual_conversation($this->users['ann'], $this->users['ben']);
        $this->setUser($this->users['ann']);
        $message = sender::send((int)$this->users['ann']->id, (int)$individual->id, 'One to one', FORMAT_PLAIN);
        $this->assertSame([], extras::seen_by($individual, [$message->id => $message], (int)$this->users['ann']->id));
    }

    public function test_extras_web_service(): void {
        $message = $this->send_and_read('Everything');
        reactions::toggle((int)$message->id, (int)$this->users['ben']->id, 'laugh');
        $since = time() - 1;
        editing::edit((int)$message->id, (int)$this->users['ann']->id, 'Everything, edited');

        $this->setUser($this->users['ann']);
        $result = external\get_conversation_extras::execute((int)$this->group->id, [(int)$message->id, 999999], $since);
        $result = \core_external\external_api::clean_returnvalue(external\get_conversation_extras::execute_returns(), $result);

        $this->assertTrue($result['isgroup']);
        $this->assertSame([999999], $result['gone']);
        $this->assertCount(1, $result['messages']);
        $entry = $result['messages'][0];
        $this->assertTrue($entry['edited']);
        $this->assertStringContainsString('Everything, edited', $entry['text']);
        $this->assertTrue($entry['canedit']);
        $this->assertSame('laugh', $entry['reactions'][0]['key']);
        $this->assertSame(['Ben X', 'Cat X'], array_column($entry['seenby'], 'fullname'));
        $this->assertTrue($result['permissions']['canmention']);
        $this->assertFalse($result['permissions']['canpin']);
    }

    public function test_extras_refused_to_non_members(): void {
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        $this->expectException(\moodle_exception::class);
        external\get_conversation_extras::execute((int)$this->group->id, [], 0);
    }

    public function test_extras_do_not_leak_other_conversations_messages(): void {
        $message = $this->send_and_read('Group only');
        $individual = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_individual_conversation($this->users['ben'], $this->users['cat']);
        $this->setUser($this->users['ben']);
        // Asking about a message of another conversation returns nothing about it.
        $result = extras::for_conversation((int)$individual->id, [(int)$message->id], 0, (int)$this->users['ben']->id);
        $this->assertSame([], $result['messages']);
        $this->assertSame([(int)$message->id], $result['gone']);
    }
}
