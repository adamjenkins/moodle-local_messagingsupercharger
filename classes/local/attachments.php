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

    /** @var int Core's HTML cleaning clamps image width and height attributes to this. */
    const MAX_IMAGE_ATTRIBUTE = 1200;

    /**
     * The files currently in a user's draft area, optionally only those named.
     *
     * @param int $userid
     * @param int $draftitemid
     * @param string[]|null $filenames Only these files (null for all)
     * @return \stored_file[]
     */
    public static function draft_files(int $userid, int $draftitemid, ?array $filenames = null): array {
        if ($draftitemid <= 0) {
            return [];
        }
        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);
        $files = array_values($fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false));
        if ($filenames !== null) {
            $files = array_values(array_filter($files, fn($file) => in_array($file->get_filename(), $filenames, true)));
        }
        return $files;
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
            self::validate_stored_content($file);
        }
    }

    /**
     * Content types refused whatever a file is called: programs, installers and scripts.
     * Names as reported by PHP's fileinfo.
     */
    const REFUSED_TYPES = [
        'application/x-dosexec', 'application/x-msdownload', 'application/vnd.microsoft.portable-executable',
        'application/x-executable', 'application/x-elf', 'application/x-sharedlib', 'application/x-pie-executable',
        'application/x-mach-binary', 'application/x-msi', 'application/x-ms-installer', 'application/x-ole-storage-msi',
        'application/java-archive', 'application/x-java-applet', 'application/vnd.android.package-archive',
        'text/x-shellscript', 'application/x-sh', 'text/x-php', 'application/x-php', 'text/x-msdos-batch',
        'application/x-bat',
    ];

    /**
     * Check that a file's contents match its name: programs and scripts are refused
     * whatever they are called, and images, PDFs and plain text must really be what their
     * extension says. Other types (office documents, archives and so on) cannot be told
     * apart reliably by content, so for them only the first rule applies; a site that
     * needs more should enable an antivirus plugin, which scan_upload() uses.
     *
     * @param string $path File on disk
     * @param string $filename Its name, as uploaded
     * @throws \moodle_exception
     */
    public static function validate_content(string $path, string $filename): void {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = strtolower((string)$finfo->file($path));
        if (in_array($detected, self::REFUSED_TYPES, true)) {
            throw new \moodle_exception('attachmentexecutable', features::COMPONENT, '', $filename);
        }
        $claimed = mimeinfo('type', $filename);
        if (file_mimetype_in_typegroup($claimed, 'web_image')) {
            if (str_starts_with($claimed, 'image/svg')) {
                $ok = in_array($detected, ['image/svg+xml', 'image/svg', 'text/xml', 'application/xml'], true);
            } else {
                $info = @getimagesize($path);
                $ok = $info !== false && str_starts_with((string)($info['mime'] ?? ''), 'image/');
            }
        } else if ($claimed === 'application/pdf') {
            $ok = $detected === 'application/pdf';
        } else if ($claimed === 'text/plain') {
            $ok = str_starts_with($detected, 'text/') || in_array($detected, ['application/json', 'application/x-empty',
                'inode/x-empty'], true);
            $ok = $ok && !in_array($detected, ['text/html', 'text/x-php', 'text/x-shellscript'], true);
        } else {
            $ok = true;
        }
        if (!$ok) {
            throw new \moodle_exception('attachmentcontentmismatch', features::COMPONENT, '', $filename);
        }
    }

    /**
     * Content check for a file already in the file store.
     *
     * @param \stored_file $file
     * @throws \moodle_exception
     */
    public static function validate_stored_content(\stored_file $file): void {
        $path = $file->copy_content_to_temp('local_messagingsupercharger');
        try {
            self::validate_content($path, $file->get_filename());
        } finally {
            @unlink($path);
        }
    }

    /**
     * Scan a file with the site's antivirus plugins, through core's antivirus manager
     * (does nothing if the site has none enabled). An infected file is deleted and the
     * manager throws with the plugin's message; it also logs, notifies and quarantines
     * as the site is configured to.
     *
     * @param string $path
     * @param string $filename
     * @throws \core\antivirus\scanner_exception
     */
    public static function scan_upload(string $path, string $filename): void {
        \core\antivirus\manager::scan_file($path, $filename, true);
    }

    /**
     * Total bytes of attachments (and embedded images) a user has stored in messages,
     * scheduled ones included.
     *
     * @param int $userid
     * @return int
     */
    public static function used_bytes(int $userid): int {
        global $DB;
        $sql = "SELECT COALESCE(SUM(f.filesize), 0)
                  FROM {files} f
                  JOIN {local_messagingsupercharger_attach} a ON a.id = f.itemid
                 WHERE f.contextid = :contextid AND f.component = :component
                   AND (f.filearea = :area1 OR f.filearea = :area2)
                   AND f.filename <> '.' AND a.userid = :userid";
        return (int)$DB->get_field_sql($sql, [
            'contextid' => \context_system::instance()->id,
            'component' => features::COMPONENT,
            'area1' => self::AREA_ATTACHMENT,
            'area2' => self::AREA_INLINE,
            'userid' => $userid,
        ]);
    }

    /**
     * Throw if storing more bytes would take the user over the storage quota.
     *
     * @param int $userid
     * @param int $morebytes Bytes about to be stored (including files waiting in the draft area)
     * @throws \moodle_exception
     */
    public static function check_quota(int $userid, int $morebytes): void {
        $quota = features::user_quota();
        if ($quota > 0 && self::used_bytes($userid) + $morebytes > $quota) {
            throw new \moodle_exception('quotaexceeded', features::COMPONENT, '', display_size($quota));
        }
    }

    /**
     * Every check an upload must pass before it is stored, in order: name and size,
     * number of files, core's limit on how fast drafts may be created, the storage quota,
     * the content check, and finally the site's antivirus.
     *
     * @param string $path Uploaded file on disk
     * @param string $filename
     * @param int $userid
     * @param int $draftitemid The draft area it is going into
     * @throws \moodle_exception
     */
    public static function check_upload(string $path, string $filename, int $userid, int $draftitemid): void {
        $filesize = (int)filesize($path);
        self::validate_file($filename, $filesize);
        $waiting = self::draft_files($userid, $draftitemid);
        if (count($waiting) >= features::max_attachments()) {
            throw new \moodle_exception('toomanyattachments', features::COMPONENT, '', features::max_attachments());
        }
        if (file_is_draft_areas_limit_reached($userid)) {
            throw new \file_exception('maxdraftitemids');
        }
        $waitingbytes = array_sum(array_map(fn($file) => (int)$file->get_filesize(), $waiting));
        self::check_quota($userid, $waitingbytes + $filesize);
        self::validate_content($path, $filename);
        self::scan_upload($path, $filename);
    }

    /**
     * Create an attachment set and move the draft files into it.
     *
     * @param int $userid Sender
     * @param int $conversationid
     * @param int $draftitemid Draft area holding attachments (0 for none)
     * @param int $editordraftitemid Draft area holding images embedded in rich text (0 for none)
     * @param string $html Rich text whose draftfile URLs should be rewritten
     * @param string[]|null $filenames Attach only these files from the draft area (null for all). The
     *        drawer names the files it shows, so that an upload abandoned or still in progress in the
     *        same draft area never goes out with this message.
     * @return array [set id or null, rewritten html]
     */
    public static function create_set(
        int $userid,
        int $conversationid,
        int $draftitemid,
        int $editordraftitemid,
        string $html,
        ?array $filenames = null
    ): array {
        global $DB;
        $attached = self::draft_files($userid, $draftitemid, $filenames);
        $inline = self::draft_files($userid, $editordraftitemid);
        if (!$attached && !$inline) {
            return [null, $html];
        }
        self::validate_files($attached);
        foreach ($inline as $file) {
            self::validate_file($file->get_filename(), (int)$file->get_filesize());
            self::validate_stored_content($file);
        }
        $bytes = array_sum(array_map(fn($file) => (int)$file->get_filesize(), array_merge($attached, $inline)));
        self::check_quota($userid, $bytes);

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
            // Copied by hand: file_save_draft_area_files() reads the draft area of whoever
            // is logged in, and the sender is not always that person (scheduled or scripted sends).
            $fs = get_file_storage();
            foreach ($attached as $file) {
                $fs->create_file_from_storedfile([
                    'contextid' => $syscontext->id,
                    'component' => features::COMPONENT,
                    'filearea' => self::AREA_ATTACHMENT,
                    'itemid' => $setid,
                    'filepath' => '/',
                    'userid' => $userid,
                ], $file);
            }
            if ($filenames === null) {
                self::clear_draft($userid, $draftitemid);
            } else {
                foreach ($attached as $file) {
                    $file->delete();
                }
            }
        }
        if ($inline) {
            global $USER;
            if ((int)$USER->id !== $userid) {
                // Rewriting draft URLs in the text relies on the logged-in user's draft area.
                throw new \coding_exception('Embedded images can only be saved by their sender');
            }
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
                // The image itself, not a thumbnail: the page scales it to the width available
                // (never beyond its real size), and clicking it opens a full-size viewer.
                $attributes = ['src' => $url->out(false), 'alt' => $name, 'class' => 'msgsc-attachment-thumb'];
                $info = $file->get_imageinfo();
                if ($info && !empty($info['width']) && !empty($info['height'])) {
                    [$attributes['width'], $attributes['height']] = self::fit_dimensions(
                        (int)$info['width'],
                        (int)$info['height']
                    );
                }
                $content = \html_writer::empty_tag('img', $attributes);
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
     * Scale image dimensions to fit within the attribute limit of core's HTML cleaning,
     * keeping the aspect ratio (the cleaning would clamp each one separately and distort
     * it). The page shows the image at up to its real size regardless.
     *
     * @param int $width
     * @param int $height
     * @return int[] [width, height]
     */
    public static function fit_dimensions(int $width, int $height): array {
        $scale = min(1, self::MAX_IMAGE_ATTRIBUTE / max($width, $height));
        return [max(1, (int)round($width * $scale)), max(1, (int)round($height * $scale))];
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
