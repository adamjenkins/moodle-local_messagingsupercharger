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
 * What the plugin adds to each message: reactions, a row of action buttons (react, edit,
 * delete for everyone, pin, history), the "edited" marker, link previews and "seen by".
 *
 * The additions sit next to core's text container, never inside it, because core
 * replaces that container's contents when a message is re-rendered.
 *
 * @module     local_messagingsupercharger/decorations
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalForm from 'core_form/modalform';
import Modal from 'core/modal';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Selectors from 'local_messagingsupercharger/selectors';
import * as Repository from 'local_messagingsupercharger/repository';
import {config, enabled, str} from 'local_messagingsupercharger/state';
import {el, button, icon, isolate, formatTime} from 'local_messagingsupercharger/dom';

const DECOR = 'msgsc-decor';

export default class Decorations {
    /**
     * @param {Object} controller
     */
    constructor(controller) {
        this.controller = controller;
        this.root = controller.root;
        this.byId = {};
    }

    /**
     * Message elements of the open conversation.
     *
     * @returns {HTMLElement[]}
     */
    messageElements() {
        const body = this.root.querySelector(Selectors.BODY_CONTAINER + ' ' + Selectors.VIEW_CONVERSATION);
        return body ? Array.from(body.querySelectorAll(Selectors.MESSAGE)) : [];
    }

    /**
     * Saved (not in-flight) message ids shown.
     *
     * @returns {Number[]}
     */
    messageIds() {
        return this.messageElements()
            .map((node) => node.getAttribute('data-message-id'))
            .filter((id) => /^\d+$/.test(id))
            .map((id) => parseInt(id, 10));
    }

    /**
     * Add the decoration container to every saved message that lacks one.
     *
     * @returns {Boolean} True if a message was seen for the first time
     */
    decorateAll() {
        let fresh = false;
        this.messageElements().forEach((node) => {
            const id = node.getAttribute('data-message-id');
            if (!/^\d+$/.test(id)) {
                return;
            }
            let decor = node.querySelector(':scope > .' + DECOR);
            if (decor && decor.getAttribute('data-for') === id) {
                return;
            }
            if (decor) {
                decor.remove();
            }
            decor = this.buildContainer(parseInt(id, 10));
            node.appendChild(decor);
            fresh = true;
            const data = this.byId[id];
            if (data) {
                this.fill(node, data);
            }
        });
        return fresh;
    }

    /**
     * Build the empty decoration container for a message.
     *
     * @param {Number} id
     * @returns {HTMLElement}
     */
    buildContainer(id) {
        const decor = el('div', {className: DECOR, 'data-for': String(id)}, [
            el('div', {className: 'msgsc-previews'}),
            el('div', {className: 'msgsc-reactions d-flex flex-wrap align-items-center'}),
            el('div', {className: 'msgsc-meta small text-muted d-flex flex-wrap align-items-center'}, [
                el('span', {className: 'msgsc-pinned-marker', hidden: true}),
                el('span', {className: 'msgsc-edited', hidden: true}),
                el('span', {className: 'msgsc-seenby', hidden: true}),
            ]),
        ]);
        isolate(decor);
        return decor;
    }

    /**
     * Apply fresh data from the server.
     *
     * @param {Object} extras
     */
    apply(extras) {
        this.byId = {};
        extras.messages.forEach((data) => {
            this.byId[String(data.id)] = data;
        });
        this.decorateAll();
        this.messageElements().forEach((node) => {
            const id = node.getAttribute('data-message-id');
            if (extras.gone.includes(parseInt(id, 10))) {
                this.markGone(node);
                return;
            }
            const data = this.byId[id];
            if (data) {
                this.fill(node, data);
            }
        });
    }

    /**
     * Hide a message that has been deleted for everyone by its author.
     *
     * @param {HTMLElement} node
     */
    markGone(node) {
        node.hidden = true;
        node.setAttribute('aria-hidden', 'true');
    }

    /**
     * Fill a message's decorations.
     *
     * @param {HTMLElement} node
     * @param {Object} data
     */
    fill(node, data) {
        const decor = node.querySelector(':scope > .' + DECOR);
        if (!decor) {
            return;
        }
        if (data.text) {
            const text = node.querySelector(Selectors.MESSAGE_TEXT);
            if (text) {
                // Formatted and cleaned by core's message_format_message_text() on the server.
                text.innerHTML = data.text;
            }
            data.text = '';
        }
        this.fillPreviews(decor.querySelector('.msgsc-previews'), data.previews);
        this.fillReactions(decor.querySelector('.msgsc-reactions'), data);
        const edited = decor.querySelector('.msgsc-edited');
        edited.hidden = !data.edited;
        edited.textContent = data.edited ? str('edited', formatTime(data.timeedited)) : '';
        const pinned = decor.querySelector('.msgsc-pinned-marker');
        pinned.hidden = !data.pinned;
        pinned.textContent = data.pinned ? str('pinned') : '';
        this.fillSeenBy(decor.querySelector('.msgsc-seenby'), data.seenby);
        this.fillActions(decor, node, data);
    }

    /**
     * Link previews.
     *
     * @param {HTMLElement} container
     * @param {Array} previews
     */
    fillPreviews(container, previews) {
        container.textContent = '';
        (previews || []).forEach((preview) => {
            const children = [];
            if (preview.imageurl) {
                children.push(el('img', {src: preview.imageurl, alt: '', className: 'msgsc-preview-image'}));
            }
            children.push(el('div', {className: 'msgsc-preview-text'}, [
                el('strong', {className: 'd-block text-truncate', text: preview.title}),
                preview.description ? el('span', {className: 'small', text: preview.description}) : null,
            ]));
            container.appendChild(el('a', {href: preview.url, target: '_blank', rel: 'noopener noreferrer nofollow',
                className: 'msgsc-preview d-flex', 'aria-label': str('linkpreview') + ': ' + preview.title}, children));
        });
    }

    /**
     * The reactions row.
     *
     * @param {HTMLElement} container
     * @param {Object} data
     */
    fillReactions(container, data) {
        container.textContent = '';
        if (!enabled('reactions')) {
            return;
        }
        const known = {};
        config().reactions.forEach((reaction) => {
            known[reaction.key] = reaction;
        });
        (data.reactions || []).forEach((entry) => {
            const reaction = known[entry.key];
            if (!reaction) {
                return;
            }
            const names = entry.names.join(', ');
            const pill = button('', {
                className: 'btn btn-sm msgsc-reaction' + (entry.reacted ? ' reacted' : ''),
                'aria-pressed': entry.reacted ? 'true' : 'false',
                'aria-label': `${reaction.label}: ${entry.count}. ${str('reactedwith')} ${names}`,
                title: names,
                'data-reaction': entry.key,
            });
            pill.appendChild(el('span', {'aria-hidden': 'true', text: reaction.emoji}));
            pill.appendChild(el('span', {className: 'msgsc-reaction-count', 'aria-hidden': 'true', text: String(entry.count)}));
            pill.disabled = !this.canReact();
            pill.addEventListener('click', () => this.toggleReaction(data.id, entry.key));
            container.appendChild(pill);
        });
    }

    /**
     * May the viewer react in this conversation?
     *
     * @returns {Boolean}
     */
    canReact() {
        const extras = this.controller.extras;
        return enabled('reactions') && !!extras && extras.permissions.canreact;
    }

    /**
     * "Seen by", on the newest message only.
     *
     * @param {HTMLElement} node
     * @param {Array} seenby
     */
    fillSeenBy(node, seenby) {
        const list = seenby || [];
        node.hidden = !list.length;
        node.textContent = list.length ? str('seenby', list.map((person) => person.fullname).join(', ')) : '';
    }

    /**
     * The row of action buttons: react, edit, delete for everyone, pin, history.
     *
     * @param {HTMLElement} decor
     * @param {HTMLElement} node The message element
     * @param {Object} data
     */
    fillActions(decor, node, data) {
        const old = decor.querySelector('.msgsc-actions');
        let refocus = null;
        if (old) {
            if (old.contains(document.activeElement)) {
                refocus = document.activeElement.getAttribute('data-action-key');
            }
            old.remove();
        }
        const extras = this.controller.extras || {permissions: {}};
        const items = [];
        if (this.canReact()) {
            items.push({key: 'react', label: str('addreaction'), icon: 'fa-regular fa-face-smile',
                action: () => this.openPalette(decor, data.id)});
        }
        if (data.canedit) {
            items.push({key: 'edit', label: str('editmessage'), icon: 'fa fa-pen',
                action: () => this.edit(node, data.id)});
        }
        if (data.candeleteforall) {
            items.push({key: 'delete', label: str('deleteforeveryone'), icon: 'fa fa-trash-can',
                action: () => this.deleteForAll(node, data.id)});
        }
        if (extras.permissions.canpin) {
            items.push({key: 'pin', label: data.pinned ? str('unpinmessage') : str('pinmessage'),
                icon: 'fa fa-thumbtack', pressed: data.pinned, action: () => this.setPinned(data.id, !data.pinned)});
        }
        if (data.edited) {
            items.push({key: 'history', label: str('showhistory'), icon: 'fa fa-clock-rotate-left',
                action: () => this.showHistory(data.id)});
        }
        if (!items.length) {
            return;
        }
        const bar = el('div', {className: 'msgsc-actions d-flex', role: 'toolbar', 'aria-label': str('messageactions')});
        items.forEach((item) => {
            const control = button('', {
                className: 'btn btn-link btn-sm msgsc-action msgsc-action-' + item.key,
                'aria-label': item.label,
                title: item.label,
                'data-action-key': item.key,
            });
            if (item.pressed !== undefined) {
                control.setAttribute('aria-pressed', item.pressed ? 'true' : 'false');
            }
            control.appendChild(icon(item.icon));
            control.addEventListener('click', item.action);
            bar.appendChild(control);
        });
        decor.appendChild(bar);
        if (refocus) {
            const again = bar.querySelector(`[data-action-key="${refocus}"]`);
            (again || node).focus();
        }
    }

    /**
     * Show the reaction palette under a message.
     *
     * @param {HTMLElement} decor
     * @param {Number} messageid
     */
    openPalette(decor, messageid) {
        const existing = decor.querySelector('.msgsc-palette');
        if (existing) {
            existing.remove();
        }
        const palette = el('div', {className: 'msgsc-palette d-flex', role: 'group', 'aria-label': str('addreaction')});
        config().reactions.forEach((reaction) => {
            const choice = button(reaction.emoji, {className: 'btn btn-sm msgsc-palette-choice', 'aria-label': reaction.label,
                title: reaction.label, 'data-reaction': reaction.key});
            choice.addEventListener('click', () => {
                palette.remove();
                this.toggleReaction(messageid, reaction.key);
            });
            palette.appendChild(choice);
        });
        palette.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                palette.remove();
                const toggle = decor.querySelector('.msgsc-action-react');
                if (toggle) {
                    toggle.focus();
                }
            }
        });
        decor.insertBefore(palette, decor.querySelector('.msgsc-meta'));
        palette.querySelector('button').focus();
    }

    /**
     * Toggle a reaction.
     *
     * @param {Number} messageid
     * @param {String} key
     */
    toggleReaction(messageid, key) {
        const pending = new Pending('local_messagingsupercharger/decorations:react');
        Repository.toggleReaction(messageid, key).then((result) => {
            const data = this.byId[String(messageid)];
            if (data) {
                data.reactions = result.reactions;
                const node = this.root.querySelector(`${Selectors.MESSAGE}[data-message-id="${messageid}"]`);
                if (node) {
                    this.fill(node, data);
                    const pill = node.querySelector(`.msgsc-reaction[data-reaction="${key}"]`)
                        || node.querySelector('.msgsc-action-react');
                    if (pill) {
                        pill.focus();
                    }
                }
            }
            return result;
        }).catch(Notification.exception).then(() => pending.resolve()).catch(() => pending.resolve());
    }

    /**
     * Edit a message in a dialogue.
     *
     * @param {HTMLElement} node
     * @param {Number} messageid
     */
    edit(node, messageid) {
        // Return focus to the message itself: the edit button is rebuilt when the text changes.
        const form = new ModalForm({
            formClass: 'local_messagingsupercharger\\form\\edit_message',
            args: {messageid},
            modalConfig: {title: str('editmessage')},
            returnFocus: node,
        });
        form.addEventListener(form.events.FORM_SUBMITTED, (e) => {
            const text = node.querySelector(Selectors.MESSAGE_TEXT);
            if (text) {
                // Formatted and cleaned by core's message_format_message_text() on the server.
                text.innerHTML = e.detail.text;
            }
            const data = this.byId[String(messageid)];
            if (data) {
                data.edited = true;
                data.timeedited = e.detail.timeedited;
                this.fill(node, data);
            }
            this.controller.requestExtrasSoon(0);
        });
        form.show().catch(Notification.exception);
    }

    /**
     * Delete a message for everyone, after confirmation.
     *
     * @param {HTMLElement} node
     * @param {Number} messageid
     */
    deleteForAll(node, messageid) {
        let pending = null;
        Notification.deleteCancelPromise(str('deleteforeveryone'), str('confirmdeleteforall'), str('deleteforeveryone'))
            .then(() => {
                pending = new Pending('local_messagingsupercharger/decorations:delete');
                return Repository.deleteMessageForAll(messageid);
            })
            .then(() => {
                this.markGone(node);
                this.controller.requestExtrasSoon(0);
                const textarea = this.root.querySelector(Selectors.FOOTER_CONTAINER + ' ' + Selectors.TEXTAREA);
                if (textarea) {
                    textarea.focus();
                }
                return true;
            })
            .catch((e) => {
                // A cancelled confirmation rejects without a message; only report real errors.
                if (e && e.message) {
                    Notification.exception(e);
                }
            })
            .then(() => pending && pending.resolve())
            .catch(() => null);
    }

    /**
     * Pin or unpin.
     *
     * @param {Number} messageid
     * @param {Boolean} pinned
     */
    setPinned(messageid, pinned) {
        const pending = new Pending('local_messagingsupercharger/decorations:pin');
        Repository.setPinned(messageid, pinned)
            .then(() => this.controller.requestExtrasSoon(0))
            .catch(Notification.exception)
            .then(() => pending.resolve())
            .catch(() => pending.resolve());
    }

    /**
     * Show earlier versions of a message.
     *
     * @param {Number} messageid
     */
    showHistory(messageid) {
        Repository.getMessageRevisions(messageid).then(async(revisions) => {
            const list = el('ol', {className: 'msgsc-history list-unstyled'});
            revisions.forEach((revision) => {
                // Stored author text: show it as text, never as HTML.
                const text = revision.format === 1
                    ? new DOMParser().parseFromString(revision.text, 'text/html').body.textContent
                    : revision.text;
                list.appendChild(el('li', {className: 'mb-2'}, [
                    el('div', {className: 'small text-muted', text: formatTime(revision.timecreated)}),
                    el('div', {className: 'msgsc-history-text', text}),
                ]));
            });
            const modal = await Modal.create({title: str('history'), body: '', removeOnClose: true});
            modal.getBody()[0].appendChild(list);
            modal.show();
            return modal;
        }).catch(Notification.exception);
    }
}
