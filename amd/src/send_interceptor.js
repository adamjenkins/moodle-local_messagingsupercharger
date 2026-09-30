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
 * Routes drawer sends that carry plugin extras to the plugin's send web service.
 *
 * Core's drawer keeps its own queue of outgoing messages, renders each one straight
 * away, and swaps in the server's copy when the send returns
 * (core_message/message_drawer_view_conversation processSendMessageBuffer). To keep all of
 * that behaviour, this module does not replace the drawer's send: it wraps core/ajax and,
 * only when a message's text matches one registered here (because it has attachments,
 * mentions or rich text), rewrites that one request to
 * local_messagingsupercharger_send_messages, whose response has the same fields as the
 * core functions it stands in for. Every other request passes through untouched.
 *
 * This and selectors.js are the two places to check after a core upgrade.
 *
 * @module     local_messagingsupercharger/send_interceptor
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/** Core web services the drawer uses to send (core_message/message_repository). */
const CORE_SEND_TO_CONVERSATION = 'core_message_send_messages_to_conversation';
const CORE_SEND_TO_USER = 'core_message_send_instant_messages';
const PLUGIN_SEND = 'local_messagingsupercharger_send_messages';
const FORMAT_PLAIN = 2;

let installed = false;
let queue = [];

/**
 * Register extras for the next message sent with this exact text.
 *
 * @param {String} text The text as the drawer will send it (trimmed)
 * @param {Object} payload {text, format, draftitemid, editordraftitemid, mentions}
 */
export const register = (text, payload) => {
    queue.push({text: text.trim(), payload});
};

/**
 * Forget registered extras (when the conversation changes).
 */
export const reset = () => {
    queue = [];
};

/**
 * Take the payload registered for a text, if any.
 *
 * @param {String} text
 * @returns {Object|null}
 */
const take = (text) => {
    const index = queue.findIndex((entry) => entry.text === String(text).trim());
    if (index < 0) {
        return null;
    }
    return queue.splice(index, 1)[0].payload;
};

/**
 * Rewrite one request if it is a drawer send carrying registered extras.
 *
 * @param {Object} request A core/ajax request
 */
const rewrite = (request) => {
    if (!request || !request.args || !Array.isArray(request.args.messages)) {
        return;
    }
    if (request.methodname !== CORE_SEND_TO_CONVERSATION && request.methodname !== CORE_SEND_TO_USER) {
        return;
    }
    const payloads = request.args.messages.map((message) => take(message.text));
    if (!payloads.some((payload) => payload)) {
        return;
    }
    const messages = request.args.messages.map((message, index) => {
        const payload = payloads[index];
        return {
            text: payload ? payload.text : message.text,
            format: payload ? payload.format : FORMAT_PLAIN,
            draftitemid: payload ? payload.draftitemid || 0 : 0,
            editordraftitemid: payload ? payload.editordraftitemid || 0 : 0,
            mentions: payload ? payload.mentions || [] : [],
        };
    });
    if (request.methodname === CORE_SEND_TO_CONVERSATION) {
        request.args = {conversationid: request.args.conversationid, touserid: 0, messages};
    } else {
        request.args = {conversationid: 0, touserid: request.args.messages[0].touserid, messages};
    }
    request.methodname = PLUGIN_SEND;
};

/**
 * Install the wrapper around core/ajax (once per page).
 */
export const install = () => {
    if (installed) {
        return;
    }
    installed = true;
    const original = Ajax.call;
    Ajax.call = function(requests, ...rest) {
        if (queue.length && Array.isArray(requests)) {
            requests.forEach(rewrite);
        }
        return original.call(this, requests, ...rest);
    };
};
