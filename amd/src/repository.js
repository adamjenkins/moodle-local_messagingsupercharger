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
 * Web service calls.
 *
 * Each call is wrapped in Promise.resolve() because core/ajax returns a jQuery Deferred,
 * which has no finally().
 *
 * @module     local_messagingsupercharger/repository
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

const call = (methodname, args) => Promise.resolve(Ajax.call([{
    methodname: 'local_messagingsupercharger_' + methodname,
    args,
}])[0]);

export const getConversationExtras = (conversationid, messageids, since) =>
    call('get_conversation_extras', {conversationid, messageids, since});

export const getIndividualConversation = (otheruserid) => call('get_individual_conversation', {otheruserid});

export const toggleReaction = (messageid, reaction) => call('toggle_reaction', {messageid, reaction});

export const getMentionCandidates = (conversationid, query) => call('get_mention_candidates', {conversationid, query});

export const deleteMessageForAll = (messageid) => call('delete_message_for_all', {messageid});

export const getMessageRevisions = (messageid) => call('get_message_revisions', {messageid});

export const setPinned = (messageid, pinned) => call('set_pinned', {messageid, pinned});

export const cancelScheduledMessage = (id) => call('cancel_scheduled_message', {id});

export const searchMessages = (query, limitfrom, limitnum) => call('search_messages', {query, limitfrom, limitnum});

export const deleteDraftFile = (draftitemid, filename) => call('delete_draft_file', {draftitemid, filename});

/**
 * Upload one file into the user's draft area, reporting progress.
 *
 * @param {File} file
 * @param {Number} conversationid
 * @param {Number} draftitemid
 * @param {Function} onProgress Called with a fraction from 0 to 1
 * @returns {Promise<Object>} The server's JSON response
 */
export const uploadFile = (file, conversationid, draftitemid, onProgress) => new Promise((resolve, reject) => {
    const data = new FormData();
    data.append('file', file, file.name || 'image.png');
    data.append('conversationid', conversationid);
    data.append('draftitemid', draftitemid);
    data.append('sesskey', M.cfg.sesskey);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', M.cfg.wwwroot + '/local/messagingsupercharger/upload.php');
    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            onProgress(e.loaded / e.total);
        }
    });
    xhr.addEventListener('load', () => {
        let response = null;
        try {
            response = JSON.parse(xhr.responseText);
        } catch (e) {
            reject(new Error(xhr.statusText || 'Upload failed'));
            return;
        }
        if (response && response.success) {
            resolve(response);
        } else {
            reject(new Error(response && response.error ? response.error : 'Upload failed'));
        }
    });
    xhr.addEventListener('error', () => reject(new Error(xhr.statusText || 'Upload failed')));
    xhr.send(data);
});
