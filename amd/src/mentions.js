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
 * @-mention autocomplete in the drawer's text box (group conversations).
 *
 * Typing "@" and some letters lists matching members of the conversation (fetched from
 * the server, which only ever returns members, and only to a member). The list follows
 * the ARIA combobox pattern: arrow keys move, Enter or Tab picks, Escape closes.
 *
 * @module     local_messagingsupercharger/mentions
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import Selectors from 'local_messagingsupercharger/selectors';
import * as Repository from 'local_messagingsupercharger/repository';
import {enabled, str} from 'local_messagingsupercharger/state';
import {el, uniqueId} from 'local_messagingsupercharger/dom';

// "@" at the start or after a space, then up to 40 characters that may include spaces
// (people's names have them), on the same line.
const TOKEN = /(^|\s)@([^\s@][^@\n]{0,39}|)$/u;

export default class Mentions {
    /**
     * @param {Object} controller
     */
    constructor(controller) {
        this.controller = controller;
        this.root = controller.root;
        this.picked = {};
        this.candidates = [];
        this.active = -1;
        this.list = null;
        this.timer = null;
        this.request = 0;
        this.root.addEventListener('input', (e) => {
            if (e.target.matches && e.target.matches(Selectors.TEXTAREA)) {
                this.onInput(e.target);
            }
        });
        this.root.addEventListener('focusout', (e) => {
            if (e.target.matches && e.target.matches(Selectors.TEXTAREA)) {
                // Let a click on an option land first.
                setTimeout(() => this.close(), 200);
            }
        });
    }

    /**
     * The text box.
     *
     * @returns {HTMLTextAreaElement|null}
     */
    textarea() {
        return this.root.querySelector(Selectors.FOOTER_CONTAINER + ' ' + Selectors.TEXTAREA);
    }

    /**
     * Create the list element if needed.
     */
    ensure() {
        const textarea = this.textarea();
        if (!textarea || this.list && this.list.isConnected) {
            return;
        }
        this.list = el('ul', {id: uniqueId('msgsc-mentions'), className: 'msgsc-mention-list list-unstyled',
            role: 'listbox', 'aria-label': str('mentionlist'), hidden: true});
        textarea.parentNode.parentNode.insertBefore(this.list, textarea.parentNode);
        textarea.setAttribute('aria-autocomplete', 'list');
        textarea.setAttribute('aria-controls', this.list.id);
        textarea.setAttribute('aria-expanded', 'false');
    }

    /**
     * Can the user mention in the current conversation?
     *
     * @returns {Boolean}
     */
    available() {
        const extras = this.controller.extras;
        return enabled('mentions') && !!extras && extras.permissions.canmention && extras.permissions.cansend;
    }

    /**
     * React to typing.
     *
     * @param {HTMLTextAreaElement} textarea
     */
    onInput(textarea) {
        if (!this.available()) {
            this.close();
            return;
        }
        const before = textarea.value.slice(0, textarea.selectionStart);
        const match = before.match(TOKEN);
        if (!match) {
            this.close();
            return;
        }
        const query = match[2];
        // Just picked (or typed) someone's full name: nothing more to suggest.
        if (/\s$/.test(query) && Object.values(this.picked).includes(query.trim())) {
            this.close();
            return;
        }
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.fetch(query), 200);
    }

    /**
     * Look at the text box again (the conversation's permissions have just arrived, and
     * the person may already have typed "@" and some letters).
     */
    recheck() {
        const textarea = this.textarea();
        if (textarea && document.activeElement === textarea) {
            this.onInput(textarea);
        }
    }

    /**
     * Fetch matching members.
     *
     * @param {String} query
     */
    fetch(query) {
        const conversationid = this.controller.conversationId;
        const request = ++this.request;
        const pending = new Pending('local_messagingsupercharger/mentions:fetch');
        Repository.getMentionCandidates(conversationid, query).then((candidates) => {
            if (request === this.request && conversationid === this.controller.conversationId) {
                if (!candidates.length && /\s/.test(query)) {
                    // Past the end of a name and into ordinary text: stay out of the way.
                    this.close();
                    return candidates;
                }
                this.candidates = candidates;
                this.active = candidates.length ? 0 : -1;
                this.render();
            }
            return candidates;
        }).catch(() => {
            // A failed suggestion lookup just shows no suggestions.
            this.close();
        }).then(() => pending.resolve()).catch(() => pending.resolve());
    }

    /**
     * Draw the list.
     */
    render() {
        this.ensure();
        const textarea = this.textarea();
        if (!this.list || !textarea) {
            return;
        }
        this.list.textContent = '';
        if (!this.candidates.length) {
            this.list.appendChild(el('li', {className: 'msgsc-mention-empty text-muted small', text: str('nomentions')}));
        }
        this.candidates.forEach((candidate, index) => {
            const option = el('li', {
                id: `${this.list.id}-${candidate.id}`,
                role: 'option',
                className: 'msgsc-mention-option' + (index === this.active ? ' active' : ''),
                'aria-selected': index === this.active ? 'true' : 'false',
                'data-userid': candidate.id,
            }, [
                el('img', {src: candidate.profileimageurl, alt: '', className: 'rounded-circle msgsc-mention-pic'}),
                el('span', {text: candidate.fullname}),
            ]);
            option.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this.pick(candidate);
            });
            this.list.appendChild(option);
        });
        // Announce when the list opens or its size changes, not on every arrow key.
        if (this.list.hidden || this.announced !== this.candidates.length) {
            this.announced = this.candidates.length;
            this.controller.composer.announce(this.candidates.length ? `${this.candidates.length} ${str('mentionlist')}`
                : str('nomentions'));
        }
        this.list.hidden = false;
        textarea.setAttribute('aria-expanded', 'true');
        if (this.active >= 0) {
            textarea.setAttribute('aria-activedescendant', `${this.list.id}-${this.candidates[this.active].id}`);
        } else {
            textarea.removeAttribute('aria-activedescendant');
        }
    }

    /**
     * Close the list.
     */
    close() {
        clearTimeout(this.timer);
        this.request++;
        this.candidates = [];
        this.active = -1;
        this.announced = -1;
        if (this.list) {
            this.list.hidden = true;
            this.list.textContent = '';
        }
        const textarea = this.textarea();
        if (textarea) {
            textarea.setAttribute('aria-expanded', 'false');
            textarea.removeAttribute('aria-activedescendant');
        }
    }

    /**
     * Is the list open?
     *
     * @returns {Boolean}
     */
    isOpen() {
        return !!(this.list && !this.list.hidden && this.candidates.length);
    }

    /**
     * Handle keys while the list is open. Called by the composer before its own handling.
     *
     * @param {KeyboardEvent} e
     * @returns {Boolean} True if the key was used here
     */
    handleKeydown(e) {
        if (!this.isOpen()) {
            if (e.key === 'Escape' && this.list && !this.list.hidden) {
                this.close();
            }
            return false;
        }
        const count = this.candidates.length;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            this.active = (this.active + (e.key === 'ArrowDown' ? 1 : -1) + count) % count;
            this.render();
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            this.pick(this.candidates[Math.max(0, this.active)]);
        } else if (e.key === 'Escape') {
            this.close();
        } else {
            return false;
        }
        e.preventDefault();
        e.stopPropagation();
        return true;
    }

    /**
     * Put a person into the text.
     *
     * @param {Object} candidate
     */
    pick(candidate) {
        const textarea = this.textarea();
        if (!textarea || !candidate) {
            return;
        }
        const position = textarea.selectionStart;
        const before = textarea.value.slice(0, position);
        const after = textarea.value.slice(position);
        const match = before.match(TOKEN);
        if (!match) {
            this.close();
            return;
        }
        const start = before.length - match[2].length - 1;
        const insert = '@' + candidate.fullname + ' ';
        textarea.value = before.slice(0, start) + insert + after;
        const caret = start + insert.length;
        textarea.setSelectionRange(caret, caret);
        this.picked[candidate.id] = candidate.fullname;
        this.close();
        textarea.focus();
    }

    /**
     * The ids of picked people whose "@Name" is still in the text.
     *
     * @param {String} text
     * @returns {Number[]}
     */
    pickedIdsIn(text) {
        return Object.entries(this.picked)
            .filter(([, name]) => text.includes('@' + name))
            .map(([id]) => parseInt(id, 10));
    }

    /**
     * Forget picked people.
     */
    reset() {
        this.picked = {};
        this.close();
    }
}
