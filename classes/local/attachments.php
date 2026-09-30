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
 * Message attachments.
 *
 * Messages have no context of their own, so attachments live in the system context
 * under this plugin, itemid = an attachment set id. The set records the conversation,
 * and the pluginfile callback serves a file only to a member of that conversation.
 * Files are uploaded first into the sender's ordinary draft area (so the drawer and
 * the rich-text modal share one upload mechanism) and moved into the set on send.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attachments {
    /** @var string File area for attached files. */
    const AREA_ATTACHMENT = 'attachment';
    /** @var string File area for images embedded in rich text. */
    const AREA_INLINE = 'inline';
    /** @var string File area for link preview images. */
    const AREA_PREVIEW = 'preview';

    /**
     * The files currently in a user's draft area.
     *
     * @param int $userid
     * @param int $draftitemid
     * @return \stored_file[]
     */
    public static function draft_files(int $userid, int $draftitemid): array {
        if ($draftitemid <= 0) {
            return [];
        }
        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);
        return array_values($fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false));
    }

    /**
     * Check one prospective file against the admin limits.
     *
     * @param string $filename
     * @param int $filesize
     * @throws \moodle_exception With a message the user can act on
     */
    public static function validate_file(string $filename, int $filesize): void {
        $max = features::max_attachment_size();
        if ($filesize > $max) {
            throw new \moodle_exception(
                'attachmenttoolarge',
                features::COMPONENT,
                '',
                (object)['name' => $filename, 'max' => display_size($max)]
            );
        }
        $types = features::attachment_types();
        $util = new \core_form\filetypes_util();
        if (!$util->is_allowed_file_type($filename, $types)) {
            throw new \moodle_exception('attachmenttypenotallowed', features::COMPONENT, '', $filename);
        }
    }

    /**
     * Check a whole draft area against the admin limits. The upload endpoint checks as
     * files arrive; this repeats the checks at send time because the draft area is the
     * user's own and can be written by other core endpoints too.
     *
     * @param \stored_file[] $files
     * @throws \moodle_exception
     */
    public static function validate_files(array $files): void {
        if (count($files) > features::max_attachments()) {
            throw new \moodle_exception('toomanyattachments', features::COMPONENT, '', features::max_attachments());
        }
        foreach ($files as $file) {
            self::validate_file($file->get_filename(), (int)$file->get_filesize());
        }
    }

    /**
     * Create an attachment set and move the draft files into it.
     *
     * @param int $userid Sender
     * @param int $conversationid
     * @param int $draftitemid Draft area holding attachments (0 for none)
     * @param int $editordraftitemid Draft area holding images embedded in rich text (0 for none)
     * @param string $html Rich text whose draftfile URLs should be rewritten
     * @return array [set id or null, rewritten html]
     */
    public static function create_set(
        int $userid,
        int $conversationid,
        int $draftitemid,
        int $editordraftitemid,
        string $html
    ): array {
        global $DB;
        $attached = self::draft_files($userid, $draftitemid);
        $inline = self::draft_files($userid, $editordraftitemid);
        if (!$attached && !$inline) {
            return [null, $html];
        }
        self::validate_files($attached);
        foreach ($inline as $file) {
            self::validate_file($file->get_filename(), (int)$file->get_filesize());
        }

        $setid = $DB->insert_record('local_messagingsupercharger_attach', (object)[
            'userid' => $userid,
            'conversationid' => $conversationid,
            'messageid' => null,
            'scheduledid' => null,
            'timecreated' => time(),
        ]);
        $syscontext = \context_system::instance();
        $options = ['subdirs' => 0, 'maxbytes' => features::max_attachment_size(), 'maxfiles' => -1];
        if ($attached) {
            file_save_draft_area_files(
                $draftitemid,
                $syscontext->id,
                features::COMPONENT,
                self::AREA_ATTACHMENT,
                $setid,
                $options
            );
            self::clear_draft($userid, $draftitemid);
        }
        if ($inline) {
            $html = file_save_draft_area_files(
                $editordraftitemid,
                $syscontext->id,
                features::COMPONENT,
                self::AREA_INLINE,
                $setid,
                $options,
                $html
            );
            // Core renders messages without rewriting @@PLUGINFILE@@, so store absolute URLs.
            $html = file_rewrite_pluginfile_urls(
                $html,
                'pluginfile.php',
                $syscontext->id,
                features::COMPONENT,
                self::AREA_INLINE,
                $setid
            );
            self::clear_draft($userid, $editordraftitemid);
        }
        return [$setid, $html];
    }

    /**
     * Empty a user's draft area once its files have been used.
     *
     * @param int $userid
     * @param int $draftitemid
     */
    public static function clear_draft(int $userid, int $draftitemid): void {
        $fs = get_file_storage();
        $fs->delete_area_files(\context_user::instance($userid)->id, 'user', 'draft', $draftitemid);
    }

    /**
     * Link an attachment set to the message that carries it.
     *
     * @param int $setid
     * @param int $messageid
     */
    public static function link_to_message(int $setid, int $messageid): void {
        global $DB;
        $DB->set_field('local_messagingsupercharger_attach', 'messageid', $messageid, ['id' => $setid]);
        $DB->set_field('local_messagingsupercharger_attach', 'scheduledid', null, ['id' => $setid]);
    }

    /**
     * The attached (not inline) files of a set.
     *
     * @param int $setid
     * @return \stored_file[]
     */
    public static function set_files(int $setid): array {
        $fs = get_file_storage();
        return array_values($fs->get_area_files(
            \context_system::instance()->id,
            features::COMPONENT,
            self::AREA_ATTACHMENT,
            $setid,
            'filename',
            false
        ));
    }

    /**
     * URL of a file in a set.
     *
     * @param \stored_file $file
     * @param bool $download
     * @return \moodle_url
     */
    public static function file_url(\stored_file $file, bool $download = false): \moodle_url {
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            $download
        );
    }

    /**
     * The HTML attachment list appended to a message. It is part of the message text,
     * so it shows wherever core shows messages: the drawer, the messages page, email
     * and the Moodle app. It uses plain links and images only, which survive core's
     * HTML cleaning and read sensibly as text.
     *
     * @param int $setid
     * @return string
     */
    public static function render_list(int $setid): string {
        $files = self::set_files($setid);
        if (!$files) {
            return '';
        }
        $items = [];
        foreach ($files as $file) {
            $name = $file->get_filename();
            $url = self::file_url($file);
            if (file_mimetype_in_typegroup($file->get_mimetype(), 'web_image')) {
                $thumb = new \moodle_url($url, ['preview' => 'bigthumb']);
                $content = \html_writer::empty_tag('img', ['src' => $thumb->out(false), 'alt' => $name,
                    'class' => 'msgsc-attachment-thumb']);
                $items[] = \html_writer::link($url, $content, ['class' => 'msgsc-attachment msgsc-attachment-image',
                    'title' => $name]);
            } else {
                $label = s($name) . ' (' . display_size($file->get_filesize()) . ')';
                $items[] = \html_writer::link(
                    self::file_url($file, true),
                    $label,
                    ['class' => 'msgsc-attachment msgsc-attachment-file']
                );
            }
        }
        return \html_writer::div(implode(' ', $items), 'msgsc-attachments');
    }

    /**
     * Delete an attachment set and its files.
     *
     * @param int $setid
     */
    public static function delete_set(int $setid): void {
        global $DB;
        $fs = get_file_storage();
        $syscontextid = \context_system::instance()->id;
        $fs->delete_area_files($syscontextid, features::COMPONENT, self::AREA_ATTACHMENT, $setid);
        $fs->delete_area_files($syscontextid, features::COMPONENT, self::AREA_INLINE, $setid);
        $DB->delete_records('local_messagingsupercharger_attach', ['id' => $setid]);
    }

    /**
     * May the user download a file from this set? Members of the set's conversation
     * who have not deleted the message may, and so may the uploader. A set that is not
     * yet attached to a message (a scheduled message) is visible to its uploader only.
     *
     * @param int $setid
     * @param int $userid
     * @return bool
     */
    public static function can_access_set(int $setid, int $userid): bool {
        global $DB;
        $set = $DB->get_record('local_messagingsupercharger_attach', ['id' => $setid]);
        if (!$set || $userid <= 0) {
            return false;
        }
        if ((int)$set->userid === $userid) {
            return true;
        }
        if (empty($set->messageid)) {
            return false;
        }
        if (!conversations::is_member($userid, (int)$set->conversationid)) {
            return false;
        }
        return !conversations::is_deleted_for((int)$set->messageid, $userid);
    }
}
