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
 * Library callbacks for local_messagingsupercharger.
 *
 * Only callbacks that core still looks up by function name live here; everything
 * else is in classes/.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_messagingsupercharger\local\attachments;
use local_messagingsupercharger\local\emailhold;

/**
 * Serve plugin files.
 *
 * Attachments and embedded images are served only to members of the conversation the
 * message belongs to (and to the uploader). Anything that is not an image is always
 * sent as a download so that uploaded HTML can never run on the site's origin. This
 * check uses only the logged-in user, never session-only state, because the Moodle app
 * fetches these files through tokenpluginfile.php.
 *
 * @param stdClass $course
 * @param stdClass|null $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if the file is not found; otherwise the file is sent and the script ends
 */
function local_messagingsupercharger_pluginfile(
    $course,
    $cm,
    $context,
    $filearea,
    $args,
    $forcedownload,
    array $options = []
) {
    global $CFG, $USER;

    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }
    $areas = [attachments::AREA_ATTACHMENT, attachments::AREA_INLINE, attachments::AREA_PREVIEW];
    if (!in_array($filearea, $areas, true) || count($args) < 2) {
        return false;
    }
    require_login(null, false);
    if (isguestuser() || empty($CFG->messaging)) {
        // With site messaging off, messages are unavailable, and so are their files.
        return false;
    }

    $itemid = (int)array_shift($args);
    if ($filearea === attachments::AREA_PREVIEW) {
        if (!\local_messagingsupercharger\local\linkpreviews::can_view_preview($itemid, (int)$USER->id)) {
            return false;
        }
    } else if (!attachments::can_access_set($itemid, (int)$USER->id)) {
        return false;
    }

    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_messagingsupercharger', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    if (!file_mimetype_in_typegroup($file->get_mimetype(), 'web_image')) {
        $forcedownload = true;
    }
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}

/**
 * Called by core before each message processor runs (lib/classes/message/manager.php).
 *
 * Used only to hold back the email of individual-conversation messages; see
 * \local_messagingsupercharger\local\emailhold.
 *
 * @param string $procname
 * @param stdClass $proceventdata
 */
function local_messagingsupercharger_pre_processor_message_send($procname, $proceventdata) {
    if (!is_object($proceventdata)) {
        return;
    }
    emailhold::intercept((string)$procname, $proceventdata);
}

/**
 * Declare the user preferences this plugin lets users set through core's web services.
 *
 * @return array
 */
function local_messagingsupercharger_user_preferences(): array {
    return [
        'local_messagingsupercharger_showseenby' => [
            'type' => PARAM_BOOL,
            'null' => NULL_NOT_ALLOWED,
            'default' => true,
            'choices' => [0, 1],
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
    ];
}
