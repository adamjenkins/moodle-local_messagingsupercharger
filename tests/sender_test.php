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
use local_messagingsupercharger\local\attachments;
use local_messagingsupercharger\local\sender;

/**
 * Tests for sending messages with attachments and rich text, and attachment access.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\sender::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\attachments::class)]
final class sender_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $alice;
    /** @var \stdClass */
    protected $bob;
    /** @var \stdClass */
    protected $conversation;
    /** @var \local_messagingsupercharger_generator */
    protected $generator;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/message/lib.php');
        $this->resetAfterTest();
        set_config('emaildelay', 0, 'local_messagingsupercharger');
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger');
        $this->alice = $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'A']);
        $this->bob = $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'B']);
        $this->conversation = $this->generator->create_individual_conversation($this->alice, $this->bob);
        $this->setUser($this->alice);
    }

    public function test_send_with_attachments(): void {
        global $DB;
        $this->redirectMessages();
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid, 'photo.png', $this->generator->png());
        $this->generator->create_draft_file($this->alice, $draftitemid, 'notes.txt', 'some notes');

        $message = sender::send(
            $this->alice->id,
            $this->conversation->id,
            "Look <b>at</b> this\nplease",
            FORMAT_PLAIN,
            $draftitemid
        );

        // Stored as HTML in core's table, the author's text escaped, the list appended.
        $this->assertEquals(FORMAT_HTML, $message->fullmessageformat);
        $this->assertStringContainsString("Look &lt;b&gt;at&lt;/b&gt; this<br>\nplease", $message->smallmessage);
        $this->assertStringContainsString('msgsc-attachments', $message->smallmessage);
        $this->assertStringContainsString('/local_messagingsupercharger/attachment/', $message->smallmessage);
        $this->assertStringContainsString('notes.txt', $message->smallmessage);

        $set = $DB->get_record('local_messagingsupercharger_attach', ['messageid' => $message->id], '*', MUST_EXIST);
        $this->assertEquals($this->conversation->id, $set->conversationid);
        $names = array_map(fn($f) => $f->get_filename(), attachments::set_files((int)$set->id));
        sort($names);
        $this->assertSame(['notes.txt', 'photo.png'], $names);
        // The draft area has been emptied.
        $this->assertSame([], attachments::draft_files((int)$this->alice->id, $draftitemid));

        $meta = $DB->get_record('local_messagingsupercharger_meta', ['messageid' => $message->id], '*', MUST_EXIST);
        $this->assertSame("Look <b>at</b> this\nplease", $meta->body);

        // Core renders it with the list intact.
        $this->setUser($this->bob);
        $formatted = sender::format_for_display($message);
        $this->assertStringContainsString('msgsc-attachment-thumb', $formatted);
        $this->assertStringNotContainsString('<b>at</b>', $formatted);
    }

    public function test_attachments_are_taken_from_the_senders_draft_area(): void {
        // A send made by code running as someone else (a script, a generator) still
        // attaches the sender's files, not the logged-in user's.
        $this->redirectMessages();
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid, 'from-alice.txt');
        $this->setAdminUser();
        $message = sender::send($this->alice->id, $this->conversation->id, 'Scripted', FORMAT_PLAIN, $draftitemid);
        $setid = (int)$GLOBALS['DB']->get_field('local_messagingsupercharger_attach', 'id', ['messageid' => $message->id]);
        $this->assertSame(['from-alice.txt'], array_map(fn($f) => $f->get_filename(), attachments::set_files($setid)));
        $this->assertStringContainsString('from-alice.txt', $message->smallmessage);
    }

    public function test_only_named_files_are_attached(): void {
        // A file abandoned (or still uploading) in the same draft area is not sent.
        $this->redirectMessages();
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid, 'wanted.txt');
        $this->generator->create_draft_file($this->alice, $draftitemid, 'abandoned.txt');
        $message = sender::send(
            $this->alice->id,
            $this->conversation->id,
            'One file',
            FORMAT_PLAIN,
            $draftitemid,
            0,
            [],
            null,
            ['wanted.txt']
        );
        $this->assertStringContainsString('wanted.txt', $message->smallmessage);
        $this->assertStringNotContainsString('abandoned.txt', $message->smallmessage);
        $left = array_map(fn($f) => $f->get_filename(), attachments::draft_files((int)$this->alice->id, $draftitemid));
        $this->assertSame(['abandoned.txt'], $left);
    }

    public function test_batch_is_checked_before_anything_is_sent(): void {
        global $DB;
        $this->redirectMessages();
        $before = $DB->count_records('messages');
        try {
            external\send_messages::execute((int)$this->conversation->id, 0, [
                ['text' => 'Fine', 'format' => FORMAT_PLAIN, 'draftitemid' => 0, 'editordraftitemid' => 0, 'mentions' => [],
                    'filenames' => []],
                ['text' => str_repeat('x', 5000), 'format' => FORMAT_PLAIN, 'draftitemid' => 0, 'editordraftitemid' => 0,
                    'mentions' => [], 'filenames' => []],
            ]);
            $this->fail('Too long a message was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('messagetoolong', $e->errorcode);
        }
        $this->assertSame($before, $DB->count_records('messages'));
    }

    public function test_ordinary_message_in_a_batch_is_sent_as_core_would(): void {
        $this->redirectMessages();
        $message = sender::send($this->alice->id, $this->conversation->id, '<b>hi</b>', FORMAT_MOODLE);
        $this->assertEquals(FORMAT_MOODLE, $message->fullmessageformat);
        $this->assertSame('<b>hi</b>', $message->smallmessage);
    }

    public function test_image_attributes_fit_the_cleaning_limit(): void {
        $this->assertSame([1200, 400], attachments::fit_dimensions(3000, 1000));
        $this->assertSame([300, 1200], attachments::fit_dimensions(1000, 4000));
        $this->assertSame([640, 480], attachments::fit_dimensions(640, 480));
    }

    public function test_attachment_only_message(): void {
        $this->redirectMessages();
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid, 'only.txt');
        $message = sender::send($this->alice->id, $this->conversation->id, '', FORMAT_PLAIN, $draftitemid);
        $this->assertStringContainsString('only.txt', $message->smallmessage);
    }

    public function test_empty_message_is_rejected(): void {
        $this->expectExceptionMessage(get_string('emptymessage', 'local_messagingsupercharger'));
        sender::send($this->alice->id, $this->conversation->id, '   ', FORMAT_PLAIN);
    }

    public function test_attachment_limits(): void {
        set_config('maxattachments', 1, 'local_messagingsupercharger');
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid, 'one.txt');
        $this->generator->create_draft_file($this->alice, $draftitemid, 'two.txt');
        try {
            sender::send($this->alice->id, $this->conversation->id, 'Two files', FORMAT_PLAIN, $draftitemid);
            $this->fail('Too many attachments accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('toomanyattachments', $e->errorcode);
        }
    }

    public function test_attachment_type_limit(): void {
        set_config('attachmenttypes', 'web_image', 'local_messagingsupercharger');
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid, 'evil.html', '<script>alert(1)</script>');
        try {
            sender::send($this->alice->id, $this->conversation->id, 'Bad file', FORMAT_PLAIN, $draftitemid);
            $this->fail('Disallowed type accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('attachmenttypenotallowed', $e->errorcode);
        }
    }

    public function test_attachment_size_limit(): void {
        set_config('maxattachmentsize', 10, 'local_messagingsupercharger');
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid, 'big.txt', str_repeat('x', 100));
        try {
            sender::send($this->alice->id, $this->conversation->id, 'Big file', FORMAT_PLAIN, $draftitemid);
            $this->fail('Oversized file accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('attachmenttoolarge', $e->errorcode);
        }
    }

    public function test_capability_needed_for_attachments(): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'local/messagingsupercharger:sendattachments',
            CAP_PROHIBIT,
            $roleid,
            \context_system::instance()->id,
            true
        );
        role_assign($roleid, $this->alice->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid);
        $this->expectException(\required_capability_exception::class);
        sender::send($this->alice->id, $this->conversation->id, 'With file', FORMAT_PLAIN, $draftitemid);
    }

    public function test_non_member_cannot_send(): void {
        $carol = $this->getDataGenerator()->create_user();
        try {
            sender::send($carol->id, $this->conversation->id, 'Intruder', FORMAT_PLAIN);
            $this->fail('Non-member could send');
        } catch (\moodle_exception $e) {
            $this->assertSame('cannotsend', $e->errorcode);
        }
    }

    public function test_blocked_sender_cannot_send(): void {
        api::block_user($this->bob->id, $this->alice->id);
        try {
            sender::send($this->alice->id, $this->conversation->id, 'Blocked', FORMAT_PLAIN);
            $this->fail('Blocked user could send');
        } catch (\moodle_exception $e) {
            $this->assertSame('cannotsend', $e->errorcode);
        }
    }

    public function test_messaging_disabled_blocks_send(): void {
        set_config('messaging', 0);
        try {
            sender::send($this->alice->id, $this->conversation->id, 'Off', FORMAT_PLAIN);
            $this->fail('Sent with messaging off');
        } catch (\moodle_exception $e) {
            $this->assertSame('cannotsend', $e->errorcode);
        }
    }

    public function test_rich_text_is_cleaned_by_core_on_display(): void {
        $this->redirectMessages();
        $message = sender::send(
            $this->alice->id,
            $this->conversation->id,
            '<p>Hi <strong>Bob</strong><script>alert(1)</script></p>',
            FORMAT_HTML
        );
        $formatted = sender::format_for_display($message);
        $this->assertStringContainsString('<strong>Bob</strong>', $formatted);
        $this->assertStringNotContainsString('<script', $formatted);
    }

    public function test_plain_to_html_links_urls_and_escapes(): void {
        $html = sender::plain_to_html("See https://example.com/a?b=1&c=2.\n<i>x</i>");
        $this->assertStringContainsString(
            '<a href="https://example.com/a?b=1&amp;c=2">https://example.com/a?b=1&amp;c=2</a>.',
            $html
        );
        $this->assertStringContainsString('&lt;i&gt;x&lt;/i&gt;', $html);
    }

    public function test_attachment_access(): void {
        $this->redirectMessages();
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid, 'secret.txt');
        $message = sender::send($this->alice->id, $this->conversation->id, 'Secret', FORMAT_PLAIN, $draftitemid);
        $setid = (int)$GLOBALS['DB']->get_field('local_messagingsupercharger_attach', 'id', ['messageid' => $message->id]);
        $carol = $this->getDataGenerator()->create_user();

        $this->assertTrue(attachments::can_access_set($setid, (int)$this->alice->id));
        $this->assertTrue(attachments::can_access_set($setid, (int)$this->bob->id));
        $this->assertFalse(attachments::can_access_set($setid, (int)$carol->id));
        $this->assertFalse(attachments::can_access_set($setid, 0));
        $this->assertFalse(attachments::can_access_set($setid + 1000, (int)$this->bob->id));

        // Bob deletes the message for himself: he can no longer fetch its files.
        $this->setUser($this->bob);
        api::delete_message($this->bob->id, $message->id);
        $this->assertFalse(attachments::can_access_set($setid, (int)$this->bob->id));
        $this->assertTrue(attachments::can_access_set($setid, (int)$this->alice->id));
    }

    public function test_first_message_to_user_creates_conversation(): void {
        $this->redirectMessages();
        $dave = $this->getDataGenerator()->create_user();
        api::add_contact($this->alice->id, $dave->id);
        $result = external\send_messages::execute(0, (int)$dave->id, [[
            'text' => 'Hi Dave', 'format' => FORMAT_PLAIN, 'draftitemid' => 0, 'editordraftitemid' => 0, 'mentions' => [],
        ]]);
        $result = \core_external\external_api::clean_returnvalue(external\send_messages::execute_returns(), $result);
        $this->assertCount(1, $result);
        $this->assertEquals(api::get_conversation_between_users([$this->alice->id, $dave->id]), $result[0]['conversationid']);
        $this->assertStringContainsString('Hi Dave', $result[0]['text']);
    }
}
