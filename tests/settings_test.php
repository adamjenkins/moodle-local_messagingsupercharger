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

/**
 * Tests that the admin settings page renders.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class settings_test extends \advanced_testcase {
    public function test_settings_page_renders(): void {
        global $CFG, $PAGE;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url('/admin/settings.php', ['section' => 'local_messagingsupercharger']);
        $PAGE->set_context(\context_system::instance());
        $page = admin_get_root(true, true)->locate('local_messagingsupercharger');
        $this->assertInstanceOf(\admin_settingpage::class, $page);
        $html = $page->output_html();
        $this->assertStringContainsString('id_s_local_messagingsupercharger_attachmenttypes', $html);
        $this->assertStringContainsString('id_s_local_messagingsupercharger_emaildelay', $html);
        $this->assertStringNotContainsString('[[', $html);
    }
}
