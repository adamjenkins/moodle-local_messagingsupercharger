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

use local_messagingsupercharger\local\linkpreviews;

/**
 * Tests for link previews: the request-forgery guards and page parsing. No network use.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_messagingsupercharger\local\linkpreviews::class)]
final class linkpreviews_test extends \advanced_testcase {
    /**
     * Addresses.
     *
     * @return array
     */
    public static function ip_provider(): array {
        return [
            'public v4' => ['93.184.216.34', true],
            'public v6' => ['2606:2800:220:1:248:1893:25c8:1946', true],
            'loopback' => ['127.0.0.1', false],
            'private 10' => ['10.1.2.3', false],
            'private 192.168' => ['192.168.56.13', false],
            'private 172.16' => ['172.20.0.1', false],
            'link local / metadata' => ['169.254.169.254', false],
            'this network' => ['0.0.0.0', false],
            'carrier nat' => ['100.64.1.1', false],
            'multicast' => ['224.0.0.1', false],
            'v6 loopback' => ['::1', false],
            'v6 unique local' => ['fd00::1', false],
            'v6 link local' => ['fe80::1', false],
            'v4-mapped loopback' => ['::ffff:127.0.0.1', false],
            'not an address' => ['example.com', false],
        ];
    }

    /**
     * Only public addresses are fetched.
     *
     * @param string $ip
     * @param bool $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ip_provider')]
    public function test_is_public_ip(string $ip, bool $expected): void {
        $this->assertSame($expected, linkpreviews::is_public_ip($ip));
    }

    public function test_url_rules(): void {
        $this->resetAfterTest();
        $this->assertFalse(linkpreviews::is_allowed_url('ftp://example.com/'));
        $this->assertFalse(linkpreviews::is_allowed_url('file:///etc/passwd'));
        $this->assertFalse(linkpreviews::is_allowed_url('gopher://example.com/'));
        $this->assertFalse(linkpreviews::is_allowed_url('http://example.com:8080/'));
        $this->assertFalse(linkpreviews::is_allowed_url('http://user:pass@example.com/'));
        $this->assertFalse(linkpreviews::is_allowed_url('not a url'));

        set_config('linkpreviewallowlist', "wikipedia.org\n.moodle.org", 'local_messagingsupercharger');
        $this->assertTrue(linkpreviews::host_on_allowlist('en.wikipedia.org'));
        $this->assertTrue(linkpreviews::host_on_allowlist('moodle.org'));
        $this->assertFalse(linkpreviews::host_on_allowlist('evilwikipedia.org'));
        $this->assertFalse(linkpreviews::is_allowed_url('https://example.com/'));
    }

    public function test_private_hosts_are_never_fetched(): void {
        $this->resetAfterTest();
        // Literal private addresses fail before any connection is attempted.
        foreach (
            ['http://127.0.0.1/', 'http://192.168.56.13/', 'http://169.254.169.254/latest/meta-data/',
                'http://[::1]/', 'http://10.0.0.1/'] as $url
        ) {
            $this->assertNull(linkpreviews::fetch($url, 1024, ['text/html']), $url);
        }
        $this->assertNull(linkpreviews::resolve_public('127.0.0.1'));
        $this->assertNull(linkpreviews::resolve_public('localhost'));
    }

    public function test_extract_urls(): void {
        $html = '<a href="https://example.com/a?x=1&amp;y=2">x</a> <a href="https://example.com/a?x=1&amp;y=2">again</a>'
            . '<a href="https://site/pluginfile.php/1/local_messagingsupercharger/attachment/1/f.txt">file</a>'
            . '<a href="javascript:alert(1)">bad</a><a href="http://two.example/">2</a>';
        $this->assertSame(['https://example.com/a?x=1&y=2', 'http://two.example/'], linkpreviews::extract_urls($html));
    }

    public function test_parse_meta(): void {
        $page = '<html><head><title>Fallback</title>'
            . '<meta property="og:title" content="The &amp; Title">'
            . '<meta name="description" content="A <b>page</b>  description">'
            . '<meta property="og:image" content="/img/card.png">'
            . '</head><body>Hi</body></html>';
        $meta = linkpreviews::parse_meta($page, 'https://example.com/path/page.html');
        $this->assertSame('The & Title', $meta['title']);
        $this->assertSame('A page description', $meta['description']);
        $this->assertSame('https://example.com/img/card.png', $meta['image']);

        $meta = linkpreviews::parse_meta('<title>Only title</title>', 'https://example.com/');
        $this->assertSame('Only title', $meta['title']);
        $this->assertSame('', $meta['image']);
    }

    public function test_previews_off_by_default_and_not_queued(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/message/lib.php');
        $this->resetAfterTest();
        set_config('emaildelay', 0, 'local_messagingsupercharger');
        $this->redirectMessages();
        $ann = $this->getDataGenerator()->create_user();
        $ben = $this->getDataGenerator()->create_user();
        $conversation = $this->getDataGenerator()->get_plugin_generator('local_messagingsupercharger')
            ->create_individual_conversation($ann, $ben);
        $this->setUser($ann);
        $this->assertFalse(local\features::enabled(local\features::LINKPREVIEWS));
        local\sender::send((int)$ann->id, (int)$conversation->id, 'See https://example.com/', FORMAT_PLAIN);
        $this->assertSame(0, $DB->count_records('local_messagingsupercharger_preview'));

        set_config('enablelinkpreviews', 1, 'local_messagingsupercharger');
        local\sender::send((int)$ann->id, (int)$conversation->id, 'See https://example.com/', FORMAT_PLAIN);
        $this->assertSame(1, $DB->count_records('local_messagingsupercharger_preview'));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(task\fetch_link_preview::class));
    }
}
