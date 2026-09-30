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
 * Keeps the message drawer open while one of the plugin's dialogues is in use.
 *
 * Core closes the drawer on any "activate" event (click, Enter, Space) outside it
 * (core_message/message_drawer, document handler). Dialogues, TinyMCE menus and the file
 * picker are all attached to the page body, so every click in them would close the
 * drawer behind the dialogue. While a plugin dialogue is open, activate events outside
 * the drawer are stopped at the body, before they reach that document handler; the
 * guard is removed as soon as the dialogue is hidden.
 *
 * @module     local_messagingsupercharger/drawer_guard
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';
import CustomEvents from 'core/custom_interaction_events';
import ModalEvents from 'core/modal_events';
import Selectors from 'local_messagingsupercharger/selectors';

const DRAWER = Selectors.DRAWER_CONTAINER;

let open = 0;
let installed = false;

/**
 * Stop activate events outside the drawer from reaching the document.
 *
 * @param {Event} e
 */
const stopOutside = (e) => {
    if (!$(e.target).closest(DRAWER).length) {
        e.stopPropagation();
    }
};

/**
 * Guard the drawer while a modal is open.
 *
 * @param {Object} modal A core/modal instance
 */
export const guard = (modal) => {
    if (!modal || !modal.getRoot) {
        return;
    }
    if (open++ === 0) {
        $('body').on(CustomEvents.events.activate, stopOutside);
    }
    let released = false;
    modal.getRoot().on(ModalEvents.hidden, () => {
        if (released) {
            return;
        }
        released = true;
        // Let the click that closed the dialogue finish before core listens again.
        setTimeout(() => {
            if (--open === 0) {
                $('body').off(CustomEvents.events.activate, stopOutside);
            }
        }, 0);
    });
};

/**
 * Guard every modal shown while the message drawer is open (the plugin's dialogues and
 * core's confirmation dialogues alike). Core modals trigger "shown" on their root, and
 * the event bubbles to the document.
 */
export const install = () => {
    if (installed) {
        return;
    }
    installed = true;
    $(document).on(ModalEvents.shown, (e) => {
        const drawer = document.querySelector(DRAWER + ':not(.hidden) [data-region="message-drawer"]');
        if (drawer && e.target && e.target.classList && e.target.classList.contains('modal')) {
            guard({getRoot: () => $(e.target)});
        }
    });
};
