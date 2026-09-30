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
 * Receives a file dropped or pasted into the message drawer and puts it in the user's
 * draft area, where the send (or the rich-text modal) picks it up.
 *
 * Limits are checked here so the user gets a clear message immediately, and again when
 * the message is sent. Responds with JSON.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use local_messagingsupercharger\local\attachments;
use local_messagingsupercharger\local\conversations;
use local_messagingsupercharger\local\features;

$conversationid = required_param('conversationid', PARAM_INT);
$draftitemid = required_param('draftitemid', PARAM_INT);

require_login(null, false);
require_sesskey();
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/messagingsupercharger/upload.php');

$response = ['success' => false];
try {
    if (isguestuser()) {
        throw new moodle_exception('noguest');
    }
    features::require_enabled(features::ATTACHMENTS);
    $conversation = conversations::get($conversationid);
    conversations::require_can_send((int)$USER->id, (int)$conversation->id);
    conversations::require_capability('sendattachments', $conversation, (int)$USER->id);
    // The browser picks the draft item id (see amd/src/composer.js), so that uploads can
    // start inside the drop or paste event. Draft areas belong to the uploader's own user
    // context, so any id only ever reaches the uploader's own files.
    if ($draftitemid <= 0) {
        $draftitemid = file_get_unused_draft_itemid();
    }
    if (
        empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')
            || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    ) {
        throw new moodle_exception('uploadfailed', 'local_messagingsupercharger');
    }
    $upload = $_FILES['file'];
    $filename = clean_param($upload['name'], PARAM_FILE);
    if ($filename === '') {
        $filename = 'file';
    }
    // Name, size, count, core's upload rate limit, quota, contents and antivirus
    // (core's antivirus manager, which deletes an infected upload).
    attachments::check_upload($upload['tmp_name'], $filename, (int)$USER->id, $draftitemid);
    // Pasted images arrive as "image.png" every time; keep names unique in the area.
    $usercontext = context_user::instance($USER->id);
    $fs = get_file_storage();
    if ($fs->file_exists($usercontext->id, 'user', 'draft', $draftitemid, '/', $filename)) {
        $filename = $fs->get_unused_filename($usercontext->id, 'user', 'draft', $draftitemid, '/', $filename);
    }
    $file = $fs->create_file_from_pathname([
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draftitemid,
        'filepath' => '/',
        'filename' => $filename,
        'userid' => $USER->id,
    ], $upload['tmp_name']);

    $isimage = file_mimetype_in_typegroup($file->get_mimetype(), 'web_image');
    $url = moodle_url::make_draftfile_url($draftitemid, '/', $filename);
    $response = [
        'success' => true,
        'draftitemid' => $draftitemid,
        'filename' => $filename,
        'filesize' => (int)$file->get_filesize(),
        'filesizetext' => display_size($file->get_filesize()),
        'isimage' => $isimage,
        'url' => $url->out(false),
        'thumburl' => $isimage ? (new moodle_url($url, ['preview' => 'thumb']))->out(false) : '',
    ];
} catch (Throwable $e) {
    if (!$e instanceof moodle_exception) {
        debugging('local_messagingsupercharger upload: ' . get_class($e) . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
    $response = ['success' => false, 'error' => $e instanceof moodle_exception ? $e->getMessage()
        : get_string('uploadfailed', 'local_messagingsupercharger')];
}

echo json_encode($response);
