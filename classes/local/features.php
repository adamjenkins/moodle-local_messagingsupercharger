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

namespace local_messagingsupercharger\local;

/**
 * Feature switches and plugin configuration.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class features {
    /** @var string Attachments and drag-and-drop images. */
    const ATTACHMENTS = 'attachments';
    /** @var string Rich text compose modal. */
    const RICHTEXT = 'richtext';
    /** @var string Mentions in group conversations. */
    const MENTIONS = 'mentions';
    /** @var string Reactions. */
    const REACTIONS = 'reactions';
    /** @var string Edit and delete own messages. */
    const EDITING = 'editing';
    /** @var string Pinned messages. */
    const PINNING = 'pinning';
    /** @var string Link previews. */
    const LINKPREVIEWS = 'linkpreviews';
    /** @var string Seen-by in group conversations. */
    const SEENBY = 'seenby';
    /** @var string Scheduled send. */
    const SCHEDULING = 'scheduling';
    /** @var string Message search. */
    const SEARCH = 'search';

    /** @var string[] Every feature, in settings order. */
    const ALL = [
        self::ATTACHMENTS, self::RICHTEXT, self::MENTIONS, self::REACTIONS, self::EDITING,
        self::PINNING, self::LINKPREVIEWS, self::SEENBY, self::SCHEDULING, self::SEARCH,
    ];

    /** @var string Component name. */
    const COMPONENT = 'local_messagingsupercharger';

    /**
     * Default on/off state of a feature. Link previews make the server fetch arbitrary
     * URLs, so they are off until an administrator turns them on.
     *
     * @param string $feature
     * @return bool
     */
    public static function default_enabled(string $feature): bool {
        return $feature !== self::LINKPREVIEWS;
    }

    /**
     * Is a feature enabled? Every feature also requires site messaging to be on.
     *
     * @param string $feature
     * @return bool
     */
    public static function enabled(string $feature): bool {
        global $CFG;
        if (empty($CFG->messaging)) {
            return false;
        }
        $value = get_config(self::COMPONENT, 'enable' . $feature);
        if ($value === false) {
            return self::default_enabled($feature);
        }
        return !empty($value);
    }

    /**
     * Throw unless a feature is enabled.
     *
     * @param string $feature
     * @throws \moodle_exception
     */
    public static function require_enabled(string $feature): void {
        if (!self::enabled($feature)) {
            throw new \moodle_exception('featuredisabled', self::COMPONENT);
        }
    }

    /**
     * Maximum size of one attachment in bytes, never above the site limit.
     *
     * @return int
     */
    public static function max_attachment_size(): int {
        global $CFG;
        $size = (int)get_config(self::COMPONENT, 'maxattachmentsize');
        if ($size <= 0) {
            $size = 5 * 1024 * 1024;
        }
        if (!empty($CFG->maxbytes)) {
            $size = min($size, (int)$CFG->maxbytes);
        }
        return $size;
    }

    /**
     * Maximum number of attachments on one message.
     *
     * @return int
     */
    public static function max_attachments(): int {
        $count = get_config(self::COMPONENT, 'maxattachments');
        return $count === false ? 5 : max(0, (int)$count);
    }

    /**
     * Allowed attachment types as a filetypes-util list.
     *
     * @return string[]
     */
    public static function attachment_types(): array {
        $types = get_config(self::COMPONENT, 'attachmenttypes');
        if ($types === false) {
            $types = 'web_image,document,archive,.txt';
        }
        $util = new \core_form\filetypes_util();
        return $util->normalize_file_types($types);
    }

    /**
     * Seconds a sender may edit or delete their message for, 0 meaning no limit.
     *
     * @return int
     */
    public static function edit_window(): int {
        $window = get_config(self::COMPONENT, 'editwindow');
        return $window === false ? 15 * MINSECS : max(0, (int)$window);
    }

    /**
     * Seconds to hold a message email back, 0 meaning core sends it straight away.
     *
     * @return int
     */
    public static function email_delay(): int {
        global $CFG;
        if (empty($CFG->messaging)) {
            return 0;
        }
        $delay = get_config(self::COMPONENT, 'emaildelay');
        return $delay === false ? 2 * MINSECS : max(0, (int)$delay);
    }

    /**
     * Seconds between the browser's refreshes of plugin data for an open conversation.
     *
     * @return int
     */
    public static function poll_interval(): int {
        $interval = (int)get_config(self::COMPONENT, 'pollinterval');
        return $interval > 0 ? max(5, $interval) : 15;
    }
}
