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
use local_messagingsupercharger\local\emailhold;

/**
 * Tests for the held message email: sent once, after the delay, with the text as it stands.
 *
 * These tests deliberately do NOT use a message sink: redirectMessages() returns before
 * core calls any processor (lib/messagelib.php message_handle_phpunit_redirection), so the
 * pre_processor callback would never run and every assertion would pass vacuously. Only
 * the email sink is used, so the real processor path runs.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\emailhold::class)]
final class emailhold_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $sender;
    /** @var \stdClass */
    protected $recipient;
    /** @var \stdClass */
    protected $conversation;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('emaildelay', 120, 'local_messagingsupercharger');
        $this->sender = $this->getDataGenerator()->create_user(['firstname' => 'Sam', 'lastname' => 'Sender']);
        $this->recipient = $this->getDataGenerator()->create_user(['firstname' => 'Rita', 'lastname' => 'Recipient']);
        $this->conversation = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_individual_conversation($this->sender, $this->recipient);
    }

    /**
     * Make every queued email due now.
     */
    protected function make_due(): void {
        global $DB;
        $DB->set_field('local_messagingsupercharger_emailq', 'timedue', time() - 1);
    }

    /**
     * Send a message as the sender.
     *
     * @param string $text
     * @return \stdClass
     */
    protected function send(string $text): \stdClass {
        $this->setUser($this->sender);
        return api::send_message_to_conversation($this->sender->id, $this->conversation->id, $text, FORMAT_MOODLE);
    }

    public function test_email_is_held_then_sent_exactly_once(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $message = $this->send('Hello there');

        // Nothing goes out when the message is sent, and core queues no digest row.
        $this->assertSame(0, $sink->count());
        $this->assertSame(0, $DB->count_records('message_email_messages'));
        $row = $DB->get_record('local_messagingsupercharger_emailq', ['messageid' => $message->id], '*', MUST_EXIST);
        $this->assertEquals($this->recipient->id, $row->useridto);
        $this->assertGreaterThan(time() + 60, (int)$row->timedue);

        // Not yet due: still nothing.
        $this->assertSame(0, emailhold::send_due());
        $this->assertSame(0, $sink->count());

        $this->make_due();
        $this->assertSame(1, emailhold::send_due());
        $emails = $sink->get_messages();
        $this->assertCount(1, $emails);
        $this->assertSame($this->recipient->email, $emails[0]->to);
        $this->assertStringContainsString('Hello there', quoted_printable_decode($emails[0]->body));
        $this->assertSame(0, $DB->count_records('local_messagingsupercharger_emailq'));

        // Running again (a retry, the hourly sweep) sends nothing more.
        $this->assertSame(0, emailhold::send_due());
        $this->assertSame(0, emailhold::send_due($message->id));
        $this->assertSame(1, $sink->count());
    }

    public function test_edit_during_hold_is_what_gets_emailed(): void {
        $sink = $this->redirectEmails();
        $message = $this->send('Teh meeting is at 3');
        editing::edit((int)$message->id, (int)$this->sender->id, 'The meeting is at 3');
        $this->assertSame(0, $sink->count());

        $this->make_due();
        emailhold::send_due();
        $emails = $sink->get_messages();
        $this->assertCount(1, $emails);
        $body = quoted_printable_decode($emails[0]->body);
        $this->assertStringContainsString('The meeting is at 3', $body);
        $this->assertStringNotContainsString('Teh meeting', $body);
    }

    public function test_editing_a_message_produces_no_second_email(): void {
        $sink = $this->redirectEmails();
        $message = $this->send('First version');
        $this->make_due();
        emailhold::send_due();
        $this->assertSame(1, $sink->count());

        // Edit after the email went: no new email, now or from any later run.
        editing::edit((int)$message->id, (int)$this->sender->id, 'Second version');
        editing::edit((int)$message->id, (int)$this->sender->id, 'Third version');
        $this->make_due();
        emailhold::send_due();
        (new \local_messagingsupercharger\task\cleanup())->execute();
        $this->assertSame(1, $sink->count());
    }

    public function test_no_delay_means_core_sends_immediately_once(): void {
        global $DB;
        set_config('emaildelay', 0, 'local_messagingsupercharger');
        $sink = $this->redirectEmails();
        $this->send('Right now');
        $this->assertSame(1, $sink->count());
        $this->assertSame(0, $DB->count_records('local_messagingsupercharger_emailq'));
    }

    public function test_group_conversations_are_left_to_core_digest(): void {
        global $DB;
        $third = $this->getDataGenerator()->create_user();
        $group = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_group_conversation([$this->sender, $this->recipient, $third]);
        $sink = $this->redirectEmails();
        $this->setUser($this->sender);
        api::send_message_to_conversation($this->sender->id, $group->id, 'To the group', FORMAT_MOODLE);
        $this->assertSame(0, $sink->count());
        $this->assertSame(0, $DB->count_records('local_messagingsupercharger_emailq'));
        // Core's digest queue has one row per recipient.
        $this->assertSame(2, $DB->count_records('message_email_messages'));
    }

    public function test_deleted_during_hold_is_not_emailed(): void {
        $sink = $this->redirectEmails();
        $message = $this->send('Oops');
        $this->setUser($this->recipient);
        api::delete_message($this->recipient->id, $message->id);
        $this->make_due();
        $this->assertSame(0, emailhold::send_due());
        $this->assertSame(0, $sink->count());
    }

    public function test_read_during_hold_is_not_emailed_unless_configured(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $message = $this->send('Seen it');
        $record = $DB->get_record('messages', ['id' => $message->id]);
        api::mark_message_as_read($this->recipient->id, $record);
        $this->make_due();
        $this->assertSame(0, emailhold::send_due());
        $this->assertSame(0, $sink->count());

        set_config('emailskipifread', 0, 'local_messagingsupercharger');
        $message = $this->send('Seen it too');
        $record = $DB->get_record('messages', ['id' => $message->id]);
        api::mark_message_as_read($this->recipient->id, $record);
        $this->make_due();
        $this->assertSame(1, emailhold::send_due());
        $this->assertSame(1, $sink->count());
    }

    public function test_muted_during_hold_is_not_emailed(): void {
        $sink = $this->redirectEmails();
        $this->send('Shh');
        api::mute_conversation($this->recipient->id, $this->conversation->id);
        $this->make_due();
        $this->assertSame(0, emailhold::send_due());
        $this->assertSame(0, $sink->count());
    }

    public function test_emailstop_set_during_hold_is_honoured_unless_forced(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $this->send('Stop, please');
        $DB->set_field('user', 'emailstop', 1, ['id' => $this->recipient->id]);
        $this->make_due();
        $this->assertSame(0, emailhold::send_due());
        $this->assertSame(0, $sink->count());

        // With message email forced by the site, core ignores emailstop, and so do we.
        $DB->set_field('user', 'emailstop', 0, ['id' => $this->recipient->id]);
        $this->send('Forced');
        $DB->set_field('user', 'emailstop', 1, ['id' => $this->recipient->id]);
        set_config('email_provider_moodle_instantmessage_locked', 1, 'message');
        set_config('message_provider_moodle_instantmessage_enabled', 'email,popup', 'message');
        $this->make_due();
        $this->assertSame(1, emailhold::send_due());
        $this->assertSame(1, $sink->count());
    }

    public function test_claimed_row_is_never_sent_twice(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $this->send('Race');
        $this->make_due();
        // Another runner has claimed the row.
        $DB->set_field('local_messagingsupercharger_emailq', 'claimtoken', 'someoneelse');
        $this->assertSame(0, emailhold::send_due());
        $this->assertSame(0, $sink->count());
    }

    public function test_intercept_ignores_everything_but_instant_message_email(): void {
        $userto = clone($this->recipient);
        $base = (object)[
            'component' => 'moodle', 'name' => 'instantmessage', 'notification' => 0,
            'conversationtype' => api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
            'savedmessageid' => 999, 'convid' => $this->conversation->id,
            'userto' => $userto, 'userfrom' => $this->sender, 'subject' => 'x',
        ];
        foreach (['popup', 'airnotifier'] as $processor) {
            $data = clone($base);
            emailhold::intercept($processor, $data);
            $this->assertSame($userto, $data->userto, $processor);
        }
        $data = clone($base);
        $data->notification = 1;
        emailhold::intercept('email', $data);
        $this->assertSame($userto, $data->userto);

        $data = clone($base);
        emailhold::intercept('email', $data);
        $this->assertNotSame($userto, $data->userto);
        $this->assertEquals(1, $data->userto->suspended);
        // The shared recipient object itself is untouched.
        $this->assertEquals(0, $userto->suspended);
    }
}
