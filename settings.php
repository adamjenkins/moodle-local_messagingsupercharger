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

/**
 * Admin settings for local_messagingsupercharger.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_messagingsupercharger', get_string('pluginname', 'local_messagingsupercharger'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $plugin = 'local_messagingsupercharger';

        // Features.
        $settings->add(new admin_setting_heading(
            "{$plugin}/featuresheading",
            get_string('featuresheading', $plugin),
            get_string('featuresheading_desc', $plugin)
        ));
        foreach (\local_messagingsupercharger\local\features::ALL as $feature) {
            $settings->add(new admin_setting_configcheckbox(
                "{$plugin}/enable{$feature}",
                get_string("enable{$feature}", $plugin),
                get_string("enable{$feature}_desc", $plugin),
                \local_messagingsupercharger\local\features::default_enabled($feature) ? 1 : 0
            ));
        }

        // Attachments.
        $settings->add(new admin_setting_heading(
            "{$plugin}/attachmentsheading",
            get_string('attachmentsheading', $plugin),
            ''
        ));
        $settings->add(new admin_setting_configselect(
            "{$plugin}/maxattachmentsize",
            get_string('maxattachmentsize', $plugin),
            get_string('maxattachmentsize_desc', $plugin),
            5 * 1024 * 1024,
            get_max_upload_sizes($CFG->maxbytes ?? 0)
        ));
        $settings->add(new admin_setting_configtext(
            "{$plugin}/maxattachments",
            get_string('maxattachments', $plugin),
            get_string('maxattachments_desc', $plugin),
            5,
            PARAM_INT
        ));
        // This setting type calls ->out() on its label, so it needs a lang_string.
        $settings->add(new admin_setting_filetypes(
            "{$plugin}/attachmenttypes",
            new lang_string('attachmenttypes', $plugin),
            new lang_string('attachmenttypes_desc', $plugin),
            'web_image,document,archive,.txt'
        ));

        // Editing and email.
        $settings->add(new admin_setting_heading(
            "{$plugin}/editingheading",
            get_string('editingheading', $plugin),
            ''
        ));
        $settings->add(new admin_setting_configduration(
            "{$plugin}/editwindow",
            get_string('editwindow', $plugin),
            get_string('editwindow_desc', $plugin),
            15 * MINSECS,
            MINSECS
        ));
        $settings->add(new admin_setting_configduration(
            "{$plugin}/emaildelay",
            get_string('emaildelay', $plugin),
            get_string('emaildelay_desc', $plugin),
            2 * MINSECS,
            MINSECS
        ));
        $settings->add(new admin_setting_configcheckbox(
            "{$plugin}/emailskipifread",
            get_string('emailskipifread', $plugin),
            get_string('emailskipifread_desc', $plugin),
            1
        ));

        // Link previews.
        $settings->add(new admin_setting_heading(
            "{$plugin}/linkpreviewsheading",
            get_string('linkpreviewsheading', $plugin),
            get_string('linkpreviewsheading_desc', $plugin)
        ));
        $settings->add(new admin_setting_configtextarea(
            "{$plugin}/linkpreviewallowlist",
            get_string('linkpreviewallowlist', $plugin),
            get_string('linkpreviewallowlist_desc', $plugin),
            '',
            PARAM_RAW
        ));

        // Polling.
        $settings->add(new admin_setting_heading(
            "{$plugin}/pollingheading",
            get_string('pollingheading', $plugin),
            ''
        ));
        $settings->add(new admin_setting_configtext(
            "{$plugin}/pollinterval",
            get_string('pollinterval', $plugin),
            get_string('pollinterval_desc', $plugin),
            15,
            PARAM_INT
        ));
    }
}
