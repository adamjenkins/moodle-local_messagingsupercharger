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
 * The collapsible strip at the top of a conversation: pinned messages, and the viewer's
 * own scheduled messages for this conversation (with edit and cancel).
 *
 * @module     local_messagingsupercharger/strip
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalForm from 'core_form/modalform';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Selectors from 'local_messagingsupercharger/selectors';
import * as Repository from 'local_messagingsupercharger/repository';
import {str} from 'local_messagingsupercharger/state';
import {el, button, isolate, formatTime, uniqueId} from 'local_messagingsupercharger/dom';

export default class Strip {
    /**
     * @param {Object} controller
     */
    constructor(controller) {
        this.controller = controller;
        this.root = controller.root;
        this.node = null;
        this.expanded = {pins: false, scheduled: true};
        this.last = null;
    }

    /**
     * Insert the strip at the top of the message list if it is not there.
     */
    ensure() {
        const container = this.root.querySelector(Selectors.BODY_CONTAINER + ' ' + Selectors.CONTENT_MESSAGE_CONTAINER);
        if (!container || (this.node && this.node.parentNode === container)) {
            return;
        }
        this.node = el('div', {className: 'msgsc-strip', hidden: true});
        isolate(this.node);
        container.insertBefore(this.node, container.firstChild);
        if (this.last) {
            this.render(this.last);
        }
    }

    /**
     * Empty the strip (conversation changed).
     */
    clear() {
        this.last = null;
        if (this.node) {
            this.node.textContent = '';
            this.node.hidden = true;
        }
    }

    /**
     * Draw the strip.
     *
     * @param {Object} extras
     */
    render(extras) {
        this.last = extras;
        this.ensure();
        if (!this.node) {
            return;
        }
        const focused = this.node.contains(document.activeElement) ? document.activeElement.getAttribute('data-focus-key')
            : null;
        this.node.textContent = '';
        if (extras.pins.length) {
            this.node.appendChild(this.section('pins', str('pinnedcount', extras.pins.length),
                extras.pins.map((pin) => this.pinItem(pin, extras.permissions.canpin))));
        }
        if (extras.scheduled.length) {
            this.node.appendChild(this.section('scheduled', str('scheduledcount', extras.scheduled.length),
                extras.scheduled.map((item) => this.scheduledItem(item))));
        }
        this.node.hidden = !extras.pins.length && !extras.scheduled.length;
        if (focused) {
            const again = this.node.querySelector(`[data-focus-key="${focused}"]`);
            if (again) {
                again.focus();
            }
        }
    }

    /**
     * A collapsible section.
     *
     * @param {String} key
     * @param {String} title
     * @param {HTMLElement[]} items
     * @returns {HTMLElement}
     */
    section(key, title, items) {
        const listid = uniqueId('msgsc-strip-' + key);
        const toggle = button(title, {className: 'btn btn-link btn-sm p-0 msgsc-strip-toggle',
            'aria-expanded': this.expanded[key] ? 'true' : 'false', 'aria-controls': listid,
            'data-focus-key': 'toggle-' + key});
        const list = el('ul', {id: listid, className: 'list-unstyled mb-0', hidden: !this.expanded[key]}, items);
        toggle.addEventListener('click', () => {
            this.expanded[key] = !this.expanded[key];
            toggle.setAttribute('aria-expanded', this.expanded[key] ? 'true' : 'false');
            list.hidden = !this.expanded[key];
        });
        return el('div', {className: 'msgsc-strip-section msgsc-strip-' + key}, [toggle, list]);
    }

    /**
     * One pinned message.
     *
     * @param {Object} pin
     * @param {Boolean} canpin
     * @returns {HTMLElement}
     */
    pinItem(pin, canpin) {
        const jump = button(`${pin.author}: ${pin.text}`, {className: 'btn btn-link btn-sm p-0 text-start msgsc-pin-jump',
            title: str('jumptomessage'), 'data-focus-key': 'pin-' + pin.messageid});
        jump.addEventListener('click', () => this.jumpTo(pin.messageid));
        const children = [jump];
        if (canpin) {
            const unpin = button('×', {className: 'btn btn-link btn-sm p-0 ms-1 msgsc-unpin',
                'aria-label': str('unpinmessage'), title: str('unpinmessage')});
            unpin.addEventListener('click', () => {
                const pending = new Pending('local_messagingsupercharger/strip:unpin');
                Repository.setPinned(pin.messageid, false)
                    .then(() => this.controller.requestExtrasSoon(0))
                    .catch(Notification.exception)
                    .then(() => pending.resolve())
                    .catch(() => pending.resolve());
            });
            children.push(unpin);
        }
        return el('li', {className: 'msgsc-pin d-flex align-items-start'}, children);
    }

    /**
     * Scroll to a message if it is loaded.
     *
     * @param {Number} messageid
     */
    jumpTo(messageid) {
        const node = this.root.querySelector(`${Selectors.MESSAGE}[data-message-id="${messageid}"]`);
        if (node) {
            node.scrollIntoView({block: 'center'});
            node.focus();
            node.classList.add('msgsc-highlight');
            setTimeout(() => node.classList.remove('msgsc-highlight'), 2000);
        }
    }

    /**
     * One scheduled message.
     *
     * @param {Object} item
     * @returns {HTMLElement}
     */
    scheduledItem(item) {
        const text = item.format === 1
            ? new DOMParser().parseFromString(item.text, 'text/html').body.textContent
            : item.text;
        const edit = button(str('editscheduled'), {className: 'btn btn-link btn-sm p-0 ms-2',
            'data-focus-key': 'sched-edit-' + item.id});
        edit.addEventListener('click', () => this.editScheduled(item, edit));
        const cancel = button(str('cancelscheduled'), {className: 'btn btn-link btn-sm p-0 ms-2',
            'data-focus-key': 'sched-cancel-' + item.id});
        cancel.addEventListener('click', () => this.cancelScheduled(item));
        return el('li', {className: 'msgsc-scheduled' + (item.failed ? ' failed' : '')}, [
            el('span', {className: 'small fw-bold', text: formatTime(item.timesend) + ' '}),
            el('span', {className: 'small', text: text || str('attachments')}),
            item.failed ? el('span', {className: 'small text-danger d-block', text: `${str('failed')}: ${item.failreason}`})
                : null,
            edit,
            cancel,
        ]);
    }

    /**
     * Change a scheduled message.
     *
     * @param {Object} item
     * @param {HTMLElement} returnto
     */
    editScheduled(item, returnto) {
        const form = new ModalForm({
            formClass: 'local_messagingsupercharger\\form\\schedule',
            args: {id: item.id, conversationid: item.conversationid},
            modalConfig: {title: str('editscheduled')},
            returnFocus: returnto,
        });
        form.addEventListener(form.events.FORM_SUBMITTED, () => this.controller.requestExtrasSoon(0));
        form.show().catch(Notification.exception);
    }

    /**
     * Cancel a scheduled message, after confirmation.
     *
     * @param {Object} item
     */
    cancelScheduled(item) {
        Notification.deleteCancelPromise(str('cancelscheduled'), str('confirmcancelscheduled'), str('cancelscheduled'))
            .then(() => Repository.cancelScheduledMessage(item.id))
            .then(() => this.controller.requestExtrasSoon(0))
            .catch((e) => {
                if (e && e.message) {
                    Notification.exception(e);
                }
            });
    }
}
