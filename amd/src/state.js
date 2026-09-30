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
 * Page-wide configuration and pre-loaded strings.
 *
 * @module     local_messagingsupercharger/state
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

const COMPONENT = 'local_messagingsupercharger';

/** Strings used synchronously while building the interface. */
const STRING_KEYS = [
    'attachfile', 'attachments', 'richeditor', 'schedulesend', 'dropfiles', 'uploading', 'removeattachment',
    'messageactions', 'addreaction', 'editmessage', 'deleteforeveryone', 'pinmessage', 'unpinmessage',
    'showhistory', 'edited', 'seenby', 'pinned', 'scheduled', 'editscheduled', 'cancelscheduled',
    'failed', 'searchmessages', 'searchplaceholder', 'noresults', 'loadmore', 'mentionlist', 'nomentions',
    'confirmdeleteforall', 'confirmcancelscheduled', 'showseenby', 'showseenby_desc', 'history', 'current',
    'jumptomessage', 'reactedwith', 'sendat', 'linkpreview', 'attachmentonly', 'schedulesaved',
    'toomanyattachmentsjs', 'attachmenttoolargejs', 'attachmenttypejs', 'pinnedcount', 'scheduledcount',
    'openmodalerror', 'deleted', 'send', 'seenbyheading', 'resizedrawer', 'imagefit', 'imageoriginal', 'imageviewer',
];

const state = {
    config: null,
    strings: {},
};

/**
 * Store the configuration and load strings.
 *
 * @param {Object} config From the server
 * @returns {Promise}
 */
export const load = async(config) => {
    state.config = config;
    const values = await getStrings(STRING_KEYS.map((key) => ({key, component: COMPONENT})));
    STRING_KEYS.forEach((key, index) => {
        state.strings[key] = values[index];
    });
};

/**
 * The configuration.
 *
 * @returns {Object}
 */
export const config = () => state.config;

/**
 * A pre-loaded string, with {$a} replaced if given.
 *
 * @param {String} key
 * @param {String|Number} [a]
 * @returns {String}
 */
export const str = (key, a) => {
    let value = state.strings[key] || key;
    if (a !== undefined) {
        value = value.split('{$a}').join(String(a));
    }
    return value;
};

/**
 * Is a feature enabled on this site?
 *
 * @param {String} feature
 * @returns {Boolean}
 */
export const enabled = (feature) => !!(state.config && state.config.features[feature]);

export {COMPONENT};
