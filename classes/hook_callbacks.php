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

use local_messagingsupercharger\local\cleanup;
use local_messagingsupercharger\local\features;
use local_messagingsupercharger\local\reactions;

/**
 * Hook callbacks.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /** @var string[] Page layouts that never show the messaging drawer. */
    const SKIP_LAYOUTS = ['embedded', 'popup', 'maintenance', 'print', 'redirect', 'secure', 'frametop'];

    /**
     * Load the plugin's JavaScript on pages that can show messaging.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(\core\hook\output\before_footer_html_generation $hook): void {
        global $CFG, $PAGE;
        if (during_initial_install() || !isloggedin() || isguestuser() || empty($CFG->messaging)) {
            return;
        }
        if (in_array($PAGE->pagelayout, self::SKIP_LAYOUTS, true)) {
            return;
        }
        $PAGE->requires->js_call_amd('local_messagingsupercharger/main', 'init', [self::js_config()]);
    }

    /**
     * Configuration handed to the browser. It only shapes the interface; every limit
     * and permission is enforced again on the server.
     *
     * @return array
     */
    public static function js_config(): array {
        global $USER;
        $enabled = [];
        foreach (features::ALL as $feature) {
            $enabled[$feature] = features::enabled($feature);
        }
        $syscontext = \context_system::instance();
        $types = features::attachment_types();
        $util = new \core_form\filetypes_util();
        return [
            'userid' => (int)$USER->id,
            'features' => $enabled,
            'draftitemid' => $enabled[features::ATTACHMENTS] ? file_get_unused_draft_itemid() : 0,
            'maxattachmentsize' => features::max_attachment_size(),
            'maxattachmentsizetext' => display_size(features::max_attachment_size()),
            'maxattachments' => features::max_attachments(),
            'acceptedtypes' => array_values($util->expand($types)),
            'editwindow' => features::edit_window(),
            'pollinterval' => features::poll_interval(),
            'reactions' => reactions::for_js(),
            'showseenby' => (bool)get_user_preferences('local_messagingsupercharger_showseenby', true),
            'canschedule' => has_capability('local/messagingsupercharger:schedulesend', $syscontext),
            'contextid' => $syscontext->id,
        ];
    }

    /**
     * Remove a deleted user's plugin data before core removes the user.
     *
     * @param \core_user\hook\before_user_deleted $hook
     */
    public static function before_user_deleted(\core_user\hook\before_user_deleted $hook): void {
        cleanup::purge_user((int)$hook->user->id);
    }
}
