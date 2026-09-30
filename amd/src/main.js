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
 * Entry point: loaded on every page for logged-in users when messaging is on.
 *
 * @module     local_messagingsupercharger/main
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import Selectors from 'local_messagingsupercharger/selectors';
import * as DrawerGuard from 'local_messagingsupercharger/drawer_guard';
import * as Interceptor from 'local_messagingsupercharger/send_interceptor';
import Controller from 'local_messagingsupercharger/controller';
import {load} from 'local_messagingsupercharger/state';

const controllers = new WeakMap();

/**
 * Attach a controller to every messaging widget on the page.
 */
const attach = () => {
    document.querySelectorAll(Selectors.ROOTS).forEach((root) => {
        if (!controllers.has(root)) {
            controllers.set(root, new Controller(root));
        }
    });
};

/**
 * Start.
 *
 * @param {Object} config Configuration from the server
 */
export const init = async(config) => {
    const pending = new Pending('local_messagingsupercharger/main:init');
    try {
        await load(config);
        Interceptor.install();
        DrawerGuard.install();
        attach();
        // The messages page can render its widget after this runs.
        const observer = new MutationObserver(() => attach());
        observer.observe(document.body, {childList: true, subtree: true});
        setTimeout(() => observer.disconnect(), 30000);
    } finally {
        pending.resolve();
    }
};
