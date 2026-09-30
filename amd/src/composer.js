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
 * The send area: attachments (button, drag and drop, paste), the rich-text editor and
 * scheduled send.
 *
 * @module     local_messagingsupercharger/composer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalForm from 'core_form/modalform';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {add as addToast} from 'core/toast';
import Selectors from 'local_messagingsupercharger/selectors';
import * as Repository from 'local_messagingsupercharger/repository';
import * as Interceptor from 'local_messagingsupercharger/send_interceptor';
import {config, enabled, str} from 'local_messagingsupercharger/state';
import {el, button, icon, uniqueId} from 'local_messagingsupercharger/dom';

const FORMAT_HTML = 1;
const FORMAT_PLAIN = 2;

export default class Composer {
    /**
     * @param {Object} controller The widget controller
     */
    constructor(controller) {
        this.controller = controller;
        this.root = controller.root;
        this.pending = [];
        this.permissions = null;
        this.skipNextSend = false;
        this.toolbar = null;
        this.pendingList = null;
        this.live = null;
        this.fileInput = null;
        this.dragDepth = 0;
        this.listen();
    }

    /**
     * The send area container, textarea and send button of this widget.
     *
     * @returns {Object}
     */
    parts() {
        const footer = this.root.querySelector(Selectors.FOOTER_CONTAINER + ' ' + Selectors.VIEW_CONVERSATION);
        const content = footer ? footer.querySelector(Selectors.FOOTER_CONTENT) : null;
        return {
            footer,
            content,
            textarea: content ? content.querySelector(Selectors.TEXTAREA) : null,
            send: content ? content.querySelector(Selectors.SEND_BUTTON) : null,
        };
    }

    /**
     * Add our controls to the send area if they are not there yet.
     */
    ensure() {
        const {content, textarea} = this.parts();
        if (!content || !textarea || content.querySelector('.msgsc-toolbar')) {
            return;
        }
        this.live = el('div', {className: 'visually-hidden', 'aria-live': 'polite'});
        this.pendingList = el('ul', {className: 'msgsc-pending list-unstyled', 'aria-label': str('attachments'),
            hidden: true});
        this.fileInput = el('input', {type: 'file', multiple: true, hidden: true, tabindex: '-1',
            accept: (config().acceptedtypes || []).filter((t) => t.startsWith('.')).join(',') || null});
        this.fileInput.addEventListener('change', () => {
            this.addFiles(Array.from(this.fileInput.files || []));
            this.fileInput.value = '';
        });

        const attach = button('', {className: 'btn btn-link btn-sm msgsc-attach', 'data-msgsc': 'attach',
            'aria-label': str('attachfile'), title: str('attachfile'), hidden: true});
        attach.appendChild(icon('fa fa-paperclip'));
        attach.addEventListener('click', () => this.fileInput.click());

        const rich = button('', {className: 'btn btn-link btn-sm msgsc-rich', 'data-msgsc': 'rich',
            'aria-label': str('richeditor'), title: str('richeditor'), hidden: true});
        rich.appendChild(icon('fa fa-font'));
        rich.addEventListener('click', () => this.openRichEditor());

        const schedule = button('', {className: 'btn btn-link btn-sm msgsc-schedule', 'data-msgsc': 'schedule',
            'aria-label': str('schedulesend'), title: str('schedulesend'), hidden: true});
        schedule.appendChild(icon('fa-regular fa-clock'));
        schedule.addEventListener('click', () => this.openSchedule());

        this.toolbar = el('div', {className: 'msgsc-toolbar d-flex align-items-center', role: 'toolbar',
            'aria-label': str('messageactions')}, [attach, rich, schedule]);
        const body = this.root.querySelector(Selectors.BODY_CONTAINER + ' ' + Selectors.VIEW_CONVERSATION);
        if (body && !body.querySelector('.msgsc-drop-hint')) {
            body.appendChild(el('div', {className: 'msgsc-drop-hint', 'aria-hidden': 'true', text: str('dropfiles')}));
        }
        content.insertBefore(this.fileInput, content.firstChild);
        content.insertBefore(this.live, content.firstChild);
        content.insertBefore(this.pendingList, content.firstChild);
        content.appendChild(this.toolbar);
        this.applyPermissions(this.permissions);
    }

    /**
     * Show only the controls the user may use in this conversation.
     *
     * @param {Object|null} permissions From get_conversation_extras
     */
    applyPermissions(permissions) {
        this.permissions = permissions;
        if (!this.toolbar) {
            return;
        }
        const show = (name, visible) => {
            const node = this.toolbar.querySelector(`[data-msgsc="${name}"]`);
            if (node) {
                node.hidden = !visible;
            }
        };
        const p = permissions || {};
        const send = !!p.cansend;
        show('attach', send && enabled('attachments') && !!p.cansendattachments);
        show('rich', send && enabled('richtext') && !!p.canuserichtext);
        show('schedule', send && enabled('scheduling') && !!p.canschedule);
    }

    /**
     * Can files be attached in the current conversation?
     *
     * @returns {Boolean}
     */
    canAttach() {
        const p = this.permissions || {};
        return !!(this.controller.conversationId && enabled('attachments') && p.cansend && p.cansendattachments
            && config().draftitemid);
    }

    /**
     * Forget pending files and extras (the conversation changed).
     */
    reset() {
        this.pending.forEach((item) => {
            if (item.status === 'done') {
                Repository.deleteDraftFile(config().draftitemid, item.serverName).catch(() => null);
            }
        });
        this.pending = [];
        this.renderPending();
        Interceptor.reset();
    }

    /**
     * Wire drag and drop, paste and the send interception.
     */
    listen() {
        const root = this.root;
        const hasFiles = (e) => e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');

        root.addEventListener('dragenter', (e) => {
            if (!hasFiles(e) || !this.canAttach()) {
                return;
            }
            e.preventDefault();
            this.dragDepth++;
            root.classList.add('msgsc-dragover');
        });
        root.addEventListener('dragover', (e) => {
            if (hasFiles(e) && this.canAttach()) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
            }
        });
        root.addEventListener('dragleave', () => {
            this.dragDepth = Math.max(0, this.dragDepth - 1);
            if (!this.dragDepth) {
                root.classList.remove('msgsc-dragover');
            }
        });
        root.addEventListener('drop', (e) => {
            this.dragDepth = 0;
            root.classList.remove('msgsc-dragover');
            if (!hasFiles(e) || !this.canAttach()) {
                return;
            }
            e.preventDefault();
            this.addFiles(Array.from(e.dataTransfer.files || []));
        });

        root.addEventListener('paste', (e) => {
            if (!e.target.matches || !e.target.matches(Selectors.TEXTAREA) || !this.canAttach()) {
                return;
            }
            const files = Array.from((e.clipboardData && e.clipboardData.files) || []);
            if (!files.length) {
                return;
            }
            e.preventDefault();
            this.addFiles(files.map((file) => this.renamePasted(file)));
        });

        // Capture phase, so this runs before core's own send handlers.
        root.addEventListener('click', (e) => {
            if (e.target.closest && e.target.closest(Selectors.SEND_BUTTON) && this.root.contains(e.target)) {
                this.beforeSend(e);
            }
        }, true);
        root.addEventListener('keydown', (e) => {
            if (!e.target.matches || !e.target.matches(Selectors.TEXTAREA)) {
                return;
            }
            if (this.controller.mentions.handleKeydown(e)) {
                return;
            }
            const {footer} = this.parts();
            // Same test as core (message_drawer_view_conversation.js): the preference is stored as '1'.
            const setting = footer ? footer.getAttribute('data-enter-to-send') : null;
            const entertosend = !!setting && setting !== 'false' && setting !== '0';
            if (e.key === 'Enter' && !e.shiftKey && entertosend) {
                this.beforeSend(e);
            }
        }, true);
    }

    /**
     * Give pasted images a distinct name (browsers call them all image.png).
     *
     * @param {File} file
     * @returns {File}
     */
    renamePasted(file) {
        if (file.name && file.name !== 'image.png') {
            return file;
        }
        const extension = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
        const stamp = new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14);
        return new File([file], `pasted-${stamp}.${extension}`, {type: file.type});
    }

    /**
     * Check a file against the limits the server will apply, for an early, clear error.
     *
     * @param {File} file
     * @returns {String|null} An error message, or null if acceptable
     */
    checkFile(file) {
        const cfg = config();
        if (file.size > cfg.maxattachmentsize) {
            return str('attachmenttoolargejs', file.name + ' (' + cfg.maxattachmentsizetext + ')');
        }
        const types = cfg.acceptedtypes || [];
        if (types.length && !types.includes('*')) {
            const name = file.name.toLowerCase();
            const ok = types.some((type) => type.startsWith('.') && name.endsWith(type.toLowerCase()));
            if (!ok) {
                return str('attachmenttypejs', file.name);
            }
        }
        return null;
    }

    /**
     * Upload files straight away and show them in the pending list.
     *
     * @param {File[]} files
     */
    addFiles(files) {
        if (!files.length || !this.canAttach()) {
            return;
        }
        const cfg = config();
        files.forEach((file) => {
            const active = this.pending.filter((item) => item.status !== 'error').length;
            const item = {id: uniqueId('msgsc-file'), file, name: file.name, progress: 0, status: 'uploading',
                error: '', serverName: '', thumb: '', sizetext: ''};
            if (active >= cfg.maxattachments) {
                item.status = 'error';
                item.error = str('toomanyattachmentsjs', cfg.maxattachments);
            } else {
                const problem = this.checkFile(file);
                if (problem) {
                    item.status = 'error';
                    item.error = problem;
                }
            }
            this.pending.push(item);
            if (item.status === 'error') {
                this.announce(item.error);
                return;
            }
            if (file.type.startsWith('image/')) {
                item.thumb = URL.createObjectURL(file);
            }
            this.announce(str('uploading') + ' ' + file.name);
            const pendingPromise = new Pending('local_messagingsupercharger/composer:upload');
            Repository.uploadFile(file, this.controller.conversationId, cfg.draftitemid, (fraction) => {
                item.progress = fraction;
                this.updateProgress(item);
            }).then((response) => {
                item.status = 'done';
                item.serverName = response.filename;
                item.name = response.filename;
                item.sizetext = response.filesizetext;
                this.renderPending();
                this.announce(response.filename);
                return response;
            }).catch((error) => {
                item.status = 'error';
                item.error = error.message;
                this.renderPending();
                this.announce(error.message);
            }).then(() => pendingPromise.resolve()).catch(() => pendingPromise.resolve());
        });
        this.renderPending();
    }

    /**
     * Update one item's progress bar without re-rendering the list.
     *
     * @param {Object} item
     */
    updateProgress(item) {
        const bar = this.pendingList && this.pendingList.querySelector(`#${item.id} progress`);
        if (bar) {
            bar.value = Math.round(item.progress * 100);
        }
    }

    /**
     * Say something to screen reader users.
     *
     * @param {String} message
     */
    announce(message) {
        if (this.live) {
            this.live.textContent = message;
        }
    }

    /**
     * Draw the pending attachments list.
     */
    renderPending() {
        if (!this.pendingList) {
            return;
        }
        this.pendingList.textContent = '';
        this.pending.forEach((item) => {
            const children = [];
            if (item.thumb) {
                children.push(el('img', {src: item.thumb, alt: '', className: 'msgsc-pending-thumb'}));
            } else {
                children.push(icon('fa fa-file msgsc-pending-icon'));
            }
            children.push(el('span', {className: 'msgsc-pending-name text-truncate', text: item.name}));
            if (item.status === 'uploading') {
                children.push(el('progress', {max: '100', value: String(Math.round(item.progress * 100)),
                    'aria-label': str('uploading') + ' ' + item.name}));
            } else if (item.status === 'error') {
                children.push(el('span', {className: 'msgsc-pending-error text-danger small', role: 'alert',
                    text: item.error}));
            } else if (item.sizetext) {
                children.push(el('span', {className: 'small text-muted', text: item.sizetext}));
            }
            const remove = button('×', {className: 'btn btn-link btn-sm p-0 msgsc-pending-remove',
                'aria-label': str('removeattachment', item.name), title: str('removeattachment', item.name)});
            remove.addEventListener('click', () => this.removeItem(item));
            children.push(remove);
            this.pendingList.appendChild(el('li', {id: item.id, className: 'msgsc-pending-item ' + item.status}, children));
        });
        this.pendingList.hidden = this.pending.length === 0;
    }

    /**
     * Remove a pending file.
     *
     * @param {Object} item
     */
    removeItem(item) {
        this.pending = this.pending.filter((other) => other !== item);
        if (item.status === 'done') {
            Repository.deleteDraftFile(config().draftitemid, item.serverName).catch(Notification.exception);
        }
        if (item.thumb) {
            URL.revokeObjectURL(item.thumb);
        }
        this.renderPending();
        const {textarea} = this.parts();
        if (textarea) {
            textarea.focus();
        }
    }

    /**
     * Just before core sends: register plugin extras for this message if it has any.
     *
     * @param {Event} e
     */
    beforeSend(e) {
        if (this.skipNextSend) {
            this.skipNextSend = false;
            return;
        }
        const {textarea} = this.parts();
        if (!textarea || !this.controller.conversationId) {
            return;
        }
        if (this.pending.some((item) => item.status === 'uploading')) {
            e.preventDefault();
            e.stopPropagation();
            this.announce(str('uploading'));
            addToast(str('uploading'));
            return;
        }
        const attached = this.pending.filter((item) => item.status === 'done');
        let text = textarea.value.trim();
        const mentions = this.controller.mentions.pickedIdsIn(text);
        if (!attached.length && !mentions.length) {
            return;
        }
        let payloadtext = text;
        if (text === '') {
            // Core ignores an empty text box; give it the file names to show while sending.
            text = str('attachmentonly', attached.map((item) => item.name).join(', '));
            textarea.value = text;
            payloadtext = '';
        }
        Interceptor.register(text, {
            text: payloadtext,
            format: FORMAT_PLAIN,
            draftitemid: attached.length ? config().draftitemid : 0,
            mentions,
        });
        this.clearAfterSend();
    }

    /**
     * The files have gone with the message: clear the list (without deleting them).
     */
    clearAfterSend() {
        this.pending.forEach((item) => item.thumb && URL.revokeObjectURL(item.thumb));
        this.pending = this.pending.filter((item) => item.status === 'uploading');
        this.renderPending();
        this.controller.mentions.reset();
        this.controller.requestExtrasSoon(1500);
    }

    /**
     * Open the rich-text editor, prefilled with what has been typed.
     */
    openRichEditor() {
        const {textarea, send} = this.parts();
        const conversationid = this.controller.conversationId;
        if (!textarea || !conversationid) {
            return;
        }
        const form = new ModalForm({
            formClass: 'local_messagingsupercharger\\form\\compose',
            args: {conversationid, text: textarea.value, draftitemid: this.canAttach() ? config().draftitemid : 0},
            modalConfig: {title: str('richeditor'), large: true},
            saveButtonText: str('send'),
            returnFocus: textarea,
        });
        form.addEventListener(form.events.FORM_SUBMITTED, (e) => {
            const data = e.detail;
            if (!send || this.controller.conversationId !== data.conversationid) {
                return;
            }
            const preview = (data.preview || '').trim() || '…';
            textarea.value = preview;
            Interceptor.register(preview, {
                text: data.text,
                format: FORMAT_HTML,
                draftitemid: data.draftitemid || 0,
                editordraftitemid: data.editordraftitemid || 0,
                mentions: [],
            });
            this.pending = [];
            this.renderPending();
            this.skipNextSend = true;
            send.click();
            this.controller.requestExtrasSoon(1500);
        });
        form.show().catch(Notification.exception);
    }

    /**
     * Open the schedule dialogue with what has been typed.
     */
    openSchedule() {
        const {textarea} = this.parts();
        const conversationid = this.controller.conversationId;
        if (!textarea || !conversationid) {
            return;
        }
        const text = textarea.value;
        const attached = this.pending.filter((item) => item.status === 'done');
        const form = new ModalForm({
            formClass: 'local_messagingsupercharger\\form\\schedule',
            args: {
                conversationid,
                text,
                draftitemid: attached.length ? config().draftitemid : 0,
                mentions: this.controller.mentions.pickedIdsIn(text).join(','),
            },
            modalConfig: {title: str('schedulesend')},
            returnFocus: textarea,
        });
        form.addEventListener(form.events.FORM_SUBMITTED, () => {
            textarea.value = '';
            this.pending = this.pending.filter((item) => item.status === 'uploading');
            this.renderPending();
            this.controller.mentions.reset();
            addToast(str('schedulesaved'));
            this.controller.requestExtrasSoon(0);
        });
        form.show().catch(Notification.exception);
    }
}
