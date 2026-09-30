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
 * The "seen by" switch in the drawer's settings panel.
 *
 * @module     local_messagingsupercharger/settings
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {setUserPreference} from 'core_user/repository';
import Selectors from 'local_messagingsupercharger/selectors';
import {config, enabled, str} from 'local_messagingsupercharger/state';
import {el, uniqueId} from 'local_messagingsupercharger/dom';

const PREFERENCE = 'local_messagingsupercharger_showseenby';

/**
 * Add the switch to a widget's settings panel.
 *
 * @param {HTMLElement} root
 */
export const ensure = (root) => {
    if (!enabled('seenby') || root.querySelector('.msgsc-settings')) {
        return;
    }
    const panel = root.querySelector(Selectors.BODY_CONTAINER + ' ' + Selectors.VIEW_SETTINGS);
    if (!panel) {
        return;
    }
    const id = uniqueId('msgsc-seenby');
    const checkbox = el('input', {type: 'checkbox', id, className: 'form-check-input',
        'aria-describedby': id + '-desc'});
    checkbox.checked = !!config().showseenby;
    checkbox.addEventListener('change', () => {
        const value = checkbox.checked ? 1 : 0;
        setUserPreference(PREFERENCE, value).then(() => {
            config().showseenby = !!value;
            return value;
        }).catch(Notification.exception);
    });
    panel.appendChild(el('div', {className: 'msgsc-settings p-3'}, [
        el('h3', {className: 'h6', text: str('seenbyheading')}),
        el('div', {className: 'form-check form-switch'}, [
            checkbox,
            el('label', {'for': id, className: 'form-check-label', text: str('showseenby')}),
        ]),
        el('p', {id: id + '-desc', className: 'small text-muted', text: str('showseenby_desc')}),
    ]));
};
