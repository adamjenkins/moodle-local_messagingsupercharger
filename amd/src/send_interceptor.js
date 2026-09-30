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
/** What core's web services assume when the drawer sends no format (externallib.php). */
const FORMAT_MOODLE = 0;

let installed = false;
let queue = [];
/** Bumped when the conversation changes: extras of an older conversation are never reused. */
let generation = 0;

/**
 * The form core shows while sending (and resends on Retry): core_message's previewText()
 * turns line breaks into <br> and drops tags. Matching on this form, with both sides
 * normalised the same way, makes a Retry of a multi-line message find its extras.
 *
 * @param {String} text
 * @returns {String}
 */
const normalise = (text) => String(text)
    .replace(/<br[^>]*>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/\n+/g, '\n')
    .trim();

/**
 * Register extras for the next message sent with this exact text.
 *
 * @param {String} text The text as the drawer will send it (trimmed)
 * @param {Object} payload {text, format, draftitemid, editordraftitemid, filenames, mentions}
 */
export const register = (text, payload) => {
    queue.push({text: normalise(text), payload, generation});
};

/**
 * Forget registered extras (when the conversation changes).
 */
export const reset = () => {
    queue = [];
    generation++;
};

/**
 * Take the payload registered for a text, if any.
 *
 * @param {String} text
 * @returns {Object|null}
 */
const take = (text) => {
    const wanted = normalise(text);
    const index = queue.findIndex((entry) => entry.text === wanted && entry.generation === generation);
    if (index < 0) {
        return null;
    }
    return queue.splice(index, 1)[0].payload;
};

/**
 * Rewrite one request if it is a drawer send carrying registered extras.
 *
 * @param {Object} request A core/ajax request
 * @returns {Array|null} The [text, payload] pairs used, or null if the request was left alone
 */
const rewrite = (request) => {
    if (!request || !request.args || !Array.isArray(request.args.messages)) {
        return null;
    }
    if (request.methodname !== CORE_SEND_TO_CONVERSATION && request.methodname !== CORE_SEND_TO_USER) {
        return null;
    }
    const payloads = request.args.messages.map((message) => take(message.text));
    if (!payloads.some((payload) => payload)) {
        return null;
    }
    const texts = request.args.messages.map((message) => String(message.text).trim());
    const messages = request.args.messages.map((message, index) => {
        const payload = payloads[index];
        if (!payload) {
            // An ordinary message queued alongside: send it exactly as core would.
            return {text: message.text, format: FORMAT_MOODLE, draftitemid: 0, editordraftitemid: 0, filenames: [],
                mentions: []};
        }
        return {
            text: payload.text,
            format: payload.format,
            draftitemid: payload.draftitemid || 0,
            editordraftitemid: payload.editordraftitemid || 0,
            filenames: payload.filenames || [],
            mentions: payload.mentions || [],
        };
    });
    if (request.methodname === CORE_SEND_TO_CONVERSATION) {
        request.args = {conversationid: request.args.conversationid, touserid: 0, messages};
    } else {
        request.args = {conversationid: 0, touserid: request.args.messages[0].touserid, messages};
    }
    request.methodname = PLUGIN_SEND;
    return payloads.map((payload, index) => (payload ? [texts[index], payload] : null)).filter((pair) => pair);
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
        if (!queue.length || !Array.isArray(requests)) {
            return original.call(this, requests, ...rest);
        }
        const sentin = generation;
        const used = requests.map(rewrite);
        const promises = original.call(this, requests, ...rest);
        // If a rerouted send fails, put its extras back, so that core's "Retry" (which
        // resends the same text) is rerouted again rather than sent without them.
        used.forEach((pairs, index) => {
            if (pairs && promises && promises[index] && promises[index].fail) {
                promises[index].fail(() => {
                    if (sentin === generation) {
                        pairs.forEach(([text, payload]) => register(text, payload));
                    }
                });
            }
        });
        return promises;
    };
};
