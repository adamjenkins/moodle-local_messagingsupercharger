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
use local_messagingsupercharger\local\search;
use local_messagingsupercharger\local\sender;

/**
 * Tests for message search.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\search::class)]
final class search_test extends \advanced_testcase {
    public function test_search_scope(): void {
        global $CFG;
        require_once($CFG->dirroot . '/message/lib.php');
        $this->resetAfterTest();
        set_config('emaildelay', 0, 'local_messagingsupercharger');
        $this->redirectMessages();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger');
        $ann = $this->getDataGenerator()->create_user(['firstname' => 'Ann', 'lastname' => 'X']);
        $ben = $this->getDataGenerator()->create_user(['firstname' => 'Ben', 'lastname' => 'X']);
        $cat = $this->getDataGenerator()->create_user(['firstname' => 'Cat', 'lastname' => 'X']);
        $individual = $generator->create_individual_conversation($ann, $ben);
        $group = $generator->create_group_conversation([$ann, $ben, $cat]);
        $private = $generator->create_individual_conversation($ben, $cat);

        $this->setUser($ann);
        sender::send((int)$ann->id, (int)$individual->id, 'The banana plan', FORMAT_PLAIN);
        sender::send((int)$ann->id, (int)$group->id, '<b>Banana</b> meeting', FORMAT_HTML);
        $deleted = sender::send((int)$ann->id, (int)$group->id, 'Old banana', FORMAT_PLAIN);
        $this->setUser($ben);
        sender::send((int)$ben->id, (int)$private->id, 'Secret banana', FORMAT_PLAIN);
        api::delete_message((int)$ann->id, (int)$deleted->id);

        $result = search::messages((int)$ann->id, 'banana');
        $snippets = array_column($result['results'], 'snippet');
        sort($snippets);
        // Matches plain text of rich messages, not markup; excludes deleted and others' conversations.
        $this->assertSame(['Banana meeting', 'The banana plan'], $snippets);
        $names = array_column($result['results'], 'conversationname', 'snippet');
        $this->assertSame('Team', $names['Banana meeting']);
        $this->assertSame('Ben X', $names['The banana plan']);

        // Markup is not searchable.
        $this->assertSame([], search::messages((int)$ann->id, '<b>')['results']);
        // Too short.
        $this->assertSame([], search::messages((int)$ann->id, 'b')['results']);

        // Leaving the group removes its messages from results.
        groups_remove_member($group->groupid, $ann->id);
        $this->assertSame(['The banana plan'], array_column(search::messages((int)$ann->id, 'banana')['results'], 'snippet'));
    }

    public function test_personal_space_is_named(): void {
        global $CFG;
        require_once($CFG->dirroot . '/message/lib.php');
        $this->resetAfterTest();
        $this->redirectMessages();
        $ann = $this->getDataGenerator()->create_user();
        $self = \core_message\api::get_self_conversation($ann->id)
            ?: \core_message\api::create_conversation(\core_message\api::MESSAGE_CONVERSATION_TYPE_SELF, [$ann->id]);
        $this->setUser($ann);
        sender::send((int)$ann->id, (int)$self->id, 'note to self: groceries', FORMAT_PLAIN);
        $results = search::messages((int)$ann->id, 'groceries')['results'];
        $this->assertSame(get_string('selfconversation', 'core_message'), $results[0]['conversationname']);
        $this->assertDebuggingNotCalled();
    }

    public function test_paging(): void {
        global $CFG;
        require_once($CFG->dirroot . '/message/lib.php');
        $this->resetAfterTest();
        set_config('emaildelay', 0, 'local_messagingsupercharger');
        $this->redirectMessages();
        $ann = $this->getDataGenerator()->create_user();
        $ben = $this->getDataGenerator()->create_user();
        $conversation = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_individual_conversation($ann, $ben);
        $this->setUser($ann);
        for ($i = 1; $i <= 5; $i++) {
            sender::send((int)$ann->id, (int)$conversation->id, "apple $i", FORMAT_PLAIN);
        }
        $page = search::messages((int)$ann->id, 'apple', 0, 3);
        $this->assertCount(3, $page['results']);
        $this->assertTrue($page['hasmore']);
        $page = search::messages((int)$ann->id, 'apple', 3, 3);
        $this->assertCount(2, $page['results']);
        $this->assertFalse($page['hasmore']);
    }
}
