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
 * Message search: a button in the conversation list header opening a search dialogue.
 *
 * @module     local_messagingsupercharger/search
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {showConversation} from 'core_message/message_drawer_helper';
import Selectors from 'local_messagingsupercharger/selectors';
import * as Repository from 'local_messagingsupercharger/repository';
import {enabled, str} from 'local_messagingsupercharger/state';
import {el, button, icon, formatTime, uniqueId} from 'local_messagingsupercharger/dom';

const PAGE = 20;

/**
 * Add the search button to a widget's conversation list header.
 *
 * @param {HTMLElement} root
 */
export const ensure = (root) => {
    if (!enabled('search') || root.querySelector('.msgsc-search-open')) {
        return;
    }
    const overview = root.querySelector(Selectors.HEADER_CONTAINER + ' ' + Selectors.VIEW_OVERVIEW)
        || root.querySelector(Selectors.PANEL_HEADER_CONTAINER + ' ' + Selectors.VIEW_OVERVIEW);
    if (!overview) {
        return;
    }
    const open = button('', {className: 'btn btn-link btn-sm msgsc-search-open', 'aria-label': str('searchmessages'),
        title: str('searchmessages')});
    open.appendChild(icon('fa fa-magnifying-glass'));
    open.appendChild(el('span', {text: str('searchmessages')}));
    open.addEventListener('click', () => openDialogue(open));
    overview.appendChild(el('div', {className: 'msgsc-search-bar px-2 pb-1'}, [open]));
};

/**
 * Open the search dialogue.
 *
 * @param {HTMLElement} returnto
 */
const openDialogue = async(returnto) => {
    const inputid = uniqueId('msgsc-search');
    const input = el('input', {type: 'search', id: inputid, className: 'form-control',
        placeholder: str('searchplaceholder'), autocomplete: 'off'});
    const status = el('div', {className: 'small text-muted my-2', role: 'status', 'aria-live': 'polite'});
    const results = el('ul', {className: 'list-unstyled msgsc-search-results'});
    const more = button(str('loadmore'), {className: 'btn btn-secondary btn-sm', hidden: true});
    const body = el('div', {className: 'msgsc-search'}, [
        el('label', {'for': inputid, className: 'msgsc-sr-only', text: str('searchmessages')}),
        input, status, results, more,
    ]);

    const modal = await Modal.create({title: str('searchmessages'), body: '', removeOnClose: true, returnElement: returnto});
    modal.getBody()[0].appendChild(body);
    modal.show();
    setTimeout(() => input.focus(), 100);

    let timer = null;
    let request = 0;
    let offset = 0;

    const run = (reset) => {
        const query = input.value.trim();
        if (reset) {
            offset = 0;
            results.textContent = '';
        }
        if (query.length < 2) {
            status.textContent = '';
            more.hidden = true;
            return;
        }
        const mine = ++request;
        const pending = new Pending('local_messagingsupercharger/search:run');
        Repository.searchMessages(query, offset, PAGE).then((response) => {
            if (mine !== request) {
                return response;
            }
            response.results.forEach((result) => {
                const choose = button('', {className: 'btn btn-link text-start w-100 p-2 msgsc-search-result'});
                choose.appendChild(el('span', {className: 'd-block msgsc-bold', text: result.conversationname}));
                choose.appendChild(el('span', {className: 'd-block small text-muted',
                    text: `${result.author} · ${formatTime(result.timecreated)}`}));
                choose.appendChild(el('span', {className: 'd-block small', text: result.snippet}));
                choose.addEventListener('click', () => {
                    modal.destroy();
                    showConversation({conversationid: result.conversationid});
                });
                results.appendChild(el('li', {}, [choose]));
            });
            offset += response.results.length;
            more.hidden = !response.hasmore;
            status.textContent = results.children.length ? '' : str('noresults');
            return response;
        }).catch(Notification.exception).then(() => pending.resolve()).catch(() => pending.resolve());
    };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => run(true), 300);
    });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(timer);
            run(true);
        }
    });
    more.addEventListener('click', () => run(false));
};
