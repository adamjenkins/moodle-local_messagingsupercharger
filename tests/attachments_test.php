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

use local_messagingsupercharger\local\attachments;
use local_messagingsupercharger\local\cleanup;
use local_messagingsupercharger\local\sender;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\attachments::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\cleanup::class)]
/**
 * Tests for attachment safety and limits: content checks, antivirus, rate limit, quota
 * and retention.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_messagingsupercharger\local\attachments
 * @covers \local_messagingsupercharger\local\cleanup
 */
final class attachments_test extends \advanced_testcase {
    /** @var \local_messagingsupercharger_generator */
    protected $generator;
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
        $this->redirectMessages();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger');
        $this->alice = $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'A']);
        $this->bob = $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'B']);
        $this->conversation = $this->generator->create_individual_conversation($this->alice, $this->bob);
        $this->setUser($this->alice);
    }

    /**
     * Sample file contents by kind.
     *
     * @param string $kind elf, exe, script, html, pdf, png, empty or text
     * @return string
     */
    protected function content(string $kind): string {
        switch ($kind) {
            case 'elf':
                return "\x7fELF\x02\x01\x01" . str_repeat("\0", 9) . "\x02\x00\x3e\x00\x01\x00\x00\x00" . str_repeat("\0", 40);
            case 'exe':
                return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff" . str_repeat("\0", 46) . "\x80\x00\x00\x00"
                    . str_repeat("\0", 64) . "PE\0\0\x4c\x01" . str_repeat("\0", 200);
            case 'script':
                return "#!/bin/sh\necho hello\n";
            case 'html':
                return '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';
            case 'pdf':
                return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
            case 'png':
                return $this->generator->png();
            case 'empty':
                return '';
            default:
                return 'Some ordinary notes.';
        }
    }

    /**
     * Write contents to a temporary file.
     *
     * @param string $content
     * @return string Path
     */
    protected function temp_file(string $content): string {
        $path = make_request_directory() . '/upload';
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * The error code a content check throws, or null if it passes.
     *
     * @param string $filename
     * @param string $kind
     * @return string|null
     */
    protected function content_error(string $filename, string $kind): ?string {
        try {
            attachments::validate_content($this->temp_file($this->content($kind)), $filename);
            return null;
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        }
    }

    public function test_programs_are_refused_whatever_they_are_called(): void {
        foreach (['notes.txt', 'report.pdf', 'photo.png', 'archive.zip', 'essay.docx'] as $filename) {
            foreach (['elf', 'exe', 'script'] as $kind) {
                $this->assertSame('attachmentexecutable', $this->content_error($filename, $kind), "$kind as $filename");
            }
        }
    }

    public function test_contents_must_match_the_claimed_type(): void {
        $this->assertSame('attachmentcontentmismatch', $this->content_error('photo.png', 'html'));
        $this->assertSame('attachmentcontentmismatch', $this->content_error('photo.png', 'text'));
        $this->assertSame('attachmentcontentmismatch', $this->content_error('notes.txt', 'html'));
        $this->assertSame('attachmentcontentmismatch', $this->content_error('report.pdf', 'text'));
        $this->assertSame('attachmentcontentmismatch', $this->content_error('report.pdf', 'png'));
    }

    public function test_genuine_files_pass(): void {
        $this->assertNull($this->content_error('photo.png', 'png'));
        $this->assertNull($this->content_error('notes.txt', 'text'));
        $this->assertNull($this->content_error('empty.txt', 'empty'));
        $this->assertNull($this->content_error('report.pdf', 'pdf'));
        // Types that cannot be told apart by content are not second-guessed.
        $this->assertNull($this->content_error('essay.docx', 'text'));
    }

    public function test_a_disguised_program_in_the_draft_area_is_not_sent(): void {
        global $DB;
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid, 'report.pdf', $this->content('exe'));
        try {
            sender::send((int)$this->alice->id, (int)$this->conversation->id, 'See attached', FORMAT_PLAIN, $draftitemid);
            $this->fail('A program named .pdf was sent');
        } catch (\moodle_exception $e) {
            $this->assertSame('attachmentexecutable', $e->errorcode);
            $this->assertStringContainsString('"report.pdf" is a program', $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('messages'));
        $this->assertSame(0, $DB->count_records('local_messagingsupercharger_attach'));
    }

    public function test_uploads_are_scanned_by_core_antivirus(): void {
        global $CFG;
        require_once($CFG->libdir . '/tests/fixtures/testable_antivirus.php');
        $CFG->antiviruses = 'testable';

        // Core's test scanner decides by file name: "OK" is clean, "FOUND" is infected.
        $clean = $this->temp_file('clean');
        attachments::scan_upload($clean, 'OK');
        $this->assertFileExists($clean);

        $infected = $this->temp_file('infected');
        try {
            attachments::scan_upload($infected, 'FOUND');
            $this->fail('An infected upload was accepted');
        } catch (\core\antivirus\scanner_exception $e) {
            $this->assertSame('virusfound', $e->errorcode);
        }
        // Core deletes the infected file.
        $this->assertFileDoesNotExist($infected);
    }

    public function test_upload_rate_uses_core_draft_limit(): void {
        global $CFG;
        $CFG->draft_area_bucket_capacity = 2;
        for ($i = 0; $i < 3; $i++) {
            $this->generator->create_draft_file($this->alice, file_get_unused_draft_itemid(), "old$i.txt");
        }
        try {
            attachments::check_upload(
                $this->temp_file('hello'),
                'notes.txt',
                (int)$this->alice->id,
                file_get_unused_draft_itemid()
            );
            $this->fail('The draft area limit was not applied');
        } catch (\file_exception $e) {
            $this->assertSame('maxdraftitemids', $e->errorcode);
        }
    }

    public function test_storage_quota(): void {
        set_config('userquota', 1000, 'local_messagingsupercharger');
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid, 'first.txt', str_repeat('a', 600));
        sender::send((int)$this->alice->id, (int)$this->conversation->id, 'First', FORMAT_PLAIN, $draftitemid);
        $this->assertSame(600, attachments::used_bytes((int)$this->alice->id));
        $this->assertSame(0, attachments::used_bytes((int)$this->bob->id));

        // Uploading more than is left is refused.
        try {
            attachments::check_upload(
                $this->temp_file(str_repeat('b', 600)),
                'second.txt',
                (int)$this->alice->id,
                file_get_unused_draft_itemid()
            );
            $this->fail('Upload over quota accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('quotaexceeded', $e->errorcode);
            $this->assertStringContainsString('limit of ' . display_size(1000) . ' for', $e->getMessage());
        }

        // So is sending it (a file that reached the draft area another way).
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid, 'second.txt', str_repeat('b', 600));
        try {
            sender::send((int)$this->alice->id, (int)$this->conversation->id, 'Second', FORMAT_PLAIN, $draftitemid);
            $this->fail('Send over quota accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('quotaexceeded', $e->errorcode);
        }

        // Within the quota is fine, and 0 means no limit.
        attachments::check_upload($this->temp_file(str_repeat('c', 300)), 'third.txt', (int)$this->alice->id, $draftitemid + 1);
        set_config('userquota', 0, 'local_messagingsupercharger');
        attachments::check_upload($this->temp_file(str_repeat('d', 5000)), 'big.txt', (int)$this->alice->id, $draftitemid + 1);
    }

    public function test_expired_attachments_are_deleted_and_the_message_says_so(): void {
        global $DB, $CFG;
        $draftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $draftitemid, 'photo.png', $this->content('png'));
        $this->generator->create_draft_file($this->alice, $draftitemid, 'notes.txt', 'notes');
        $editordraftitemid = file_get_unused_draft_itemid();
        $this->generator->create_draft_file($this->alice, $editordraftitemid, 'inline.png', $this->content('png'));
        $usercontextid = \context_user::instance($this->alice->id)->id;
        $html = '<p>Holiday <b>photos</b></p><p><img src="' . $CFG->wwwroot . "/draftfile.php/$usercontextid/user/draft/"
            . $editordraftitemid . '/inline.png" alt="inline"></p>';
        $message = sender::send(
            (int)$this->alice->id,
            (int)$this->conversation->id,
            $html,
            FORMAT_HTML,
            $draftitemid,
            $editordraftitemid
        );
        $setid = (int)$DB->get_field('local_messagingsupercharger_attach', 'id', ['messageid' => $message->id]);
        $this->assertStringContainsString('/local_messagingsupercharger/inline/', $message->smallmessage);
        $this->assertStringContainsString('msgsc-attachments', $message->smallmessage);

        // Retention off: nothing is deleted, however old.
        $this->assertSame(0, cleanup::expire_attachments(time() + 365 * DAYSECS));
        set_config('attachmentretention', 30 * DAYSECS, 'local_messagingsupercharger');
        // Not old enough yet.
        $this->assertSame(0, cleanup::expire_attachments());

        $this->assertSame(1, cleanup::expire_attachments(time() + 31 * DAYSECS));
        $message = $DB->get_record('messages', ['id' => $message->id]);
        $this->assertStringContainsString('Holiday <b>photos</b>', $message->smallmessage);
        $this->assertStringContainsString(get_string('attachmentsexpired', 'local_messagingsupercharger'), $message->smallmessage);
        $this->assertStringNotContainsString('<img', $message->smallmessage);
        $this->assertStringNotContainsString('notes.txt', $message->smallmessage);
        $this->assertSame($message->smallmessage, $message->fullmessagehtml);
        $this->assertStringContainsString(get_string('attachmentsexpired', 'local_messagingsupercharger'), $message->fullmessage);

        $meta = $DB->get_record('local_messagingsupercharger_meta', ['messageid' => $message->id]);
        $this->assertNull($meta->attachsetid);
        $this->assertStringNotContainsString('<img', $meta->body);
        $this->assertSame(0, $DB->count_records('local_messagingsupercharger_attach'));
        $fs = get_file_storage();
        $syscontextid = \context_system::instance()->id;
        $this->assertTrue($fs->is_area_empty($syscontextid, 'local_messagingsupercharger', 'attachment', $setid));
        $this->assertTrue($fs->is_area_empty($syscontextid, 'local_messagingsupercharger', 'inline', $setid));
        $this->assertSame(0, attachments::used_bytes((int)$this->alice->id));

        // Only once.
        $this->assertSame(0, cleanup::expire_attachments(time() + 31 * DAYSECS));
    }
}
