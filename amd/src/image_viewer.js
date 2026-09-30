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
 * Full-size image viewer for images in messages, and their sizing in the panel.
 *
 * Clicking an image in a message (or pressing Enter or Space on it) opens it in a
 * dialogue at its real size, scrollable, with a switch to fit it to the window and a link
 * to the original. The click is caught before core's handlers, so it neither follows the
 * attachment link nor toggles the message's selection. Images inside a link the author
 * wrote keep following that link, small icons are left alone, and nothing is intercepted
 * while core's message selection mode is on.
 *
 * In the panel, images are as wide as the space allows but never wider than their real
 * size. The real size cannot be carried in the message HTML (core's HTML cleaning clamps
 * width and height attributes at 1200 pixels), so it is applied here once the image loads.
 *
 * Everything taken from the message (alt text, addresses) is used as text or as an
 * attribute, never as HTML.
 *
 * @module     local_messagingsupercharger/image_viewer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import Notification from 'core/notification';
import Selectors from 'local_messagingsupercharger/selectors';
import {str} from 'local_messagingsupercharger/state';
import {el, button} from 'local_messagingsupercharger/dom';

/** Images smaller than this (icons, emoticons) are not treated as pictures. */
const MIN_SIZE = 48;

/**
 * Is this an image the viewer should handle?
 *
 * @param {HTMLImageElement} img
 * @returns {Boolean}
 */
const isPicture = (img) => {
    if (img.classList.contains('icon') || img.classList.contains('emoticon')) {
        return false;
    }
    const link = img.closest('a');
    if (link && !link.classList.contains('msgsc-attachment-image')) {
        return false;
    }
    const width = img.naturalWidth || parseInt(img.getAttribute('width'), 10) || MIN_SIZE;
    const height = img.naturalHeight || parseInt(img.getAttribute('height'), 10) || MIN_SIZE;
    return width >= MIN_SIZE || height >= MIN_SIZE;
};

/**
 * The image a click or key press in message text is about, if any.
 *
 * @param {HTMLElement} target
 * @returns {Object|null} {src, alt, returnTo}
 */
const imageFor = (target) => {
    if (!target.closest || !target.closest(Selectors.MESSAGE_TEXT)) {
        return null;
    }
    const link = target.closest('a.msgsc-attachment-image');
    const img = link ? link.querySelector('img') : target.closest('img');
    if (!img || !isPicture(img)) {
        return null;
    }
    // An attached image's link points at the original; an embedded image is its own source.
    const src = link ? link.href : img.currentSrc || img.src;
    if (!/^https?:/i.test(src)) {
        return null;
    }
    return {src, alt: img.alt || '', returnTo: link || img};
};

/**
 * Is core's message selection mode (used for deleting messages) on in this widget?
 *
 * @param {HTMLElement} root
 * @returns {Boolean}
 */
const selecting = (root) => {
    const editmode = root.querySelector('[data-region="content-messages-footer-edit-mode-container"]');
    return !!(editmode && !editmode.classList.contains('hidden'));
};

/**
 * Open the viewer.
 *
 * @param {Object} image From imageFor()
 */
const show = async(image) => {
    const picture = el('img', {src: image.src, alt: image.alt});
    const stage = el('div', {className: 'msgsc-viewer-stage'}, [picture]);
    const fit = button(str('imagefit'), {className: 'btn btn-secondary btn-sm', 'aria-pressed': 'false'});
    fit.addEventListener('click', () => {
        const fitted = stage.classList.toggle('fit');
        fit.setAttribute('aria-pressed', fitted ? 'true' : 'false');
    });
    const original = el('a', {href: image.src, target: '_blank', rel: 'noopener', className: 'btn btn-link btn-sm',
        text: str('imageoriginal')});
    const caption = image.alt ? el('p', {className: 'small text-muted mb-2', text: image.alt}) : null;
    const body = el('div', {}, [el('div', {className: 'msgsc-viewer-toolbar'}, [fit, original]), caption, stage]);
    // The title is a fixed string: core sets modal titles as HTML.
    const modal = await Modal.create({
        title: str('imageviewer'),
        body: '',
        large: true,
        removeOnClose: true,
        returnElement: image.returnTo,
    });
    modal.getRoot()[0].querySelector('.modal-dialog').classList.add('modal-xl');
    modal.getBody()[0].appendChild(body);
    modal.show();
};

/**
 * Size one image: as wide as the panel, never wider than its real size.
 *
 * @param {HTMLImageElement} img
 */
const fitToRealSize = (img) => {
    if (img.naturalWidth) {
        img.style.maxWidth = `min(100%, ${img.naturalWidth}px)`;
        img.classList.add('msgsc-sized');
    }
};

/**
 * Prepare the pictures in one message's text: size them, and make embedded pictures
 * reachable by keyboard. Safe to call repeatedly.
 *
 * @param {HTMLElement} text A message text container
 */
export const prepare = (text) => {
    if (!text) {
        return;
    }
    text.querySelectorAll('img').forEach((img) => {
        if (img.dataset.msgscPrepared) {
            return;
        }
        img.dataset.msgscPrepared = '1';
        const setup = () => {
            if (!isPicture(img)) {
                return;
            }
            fitToRealSize(img);
            img.classList.add('msgsc-picture');
            // Fill the panel (up to the real size) unless the author chose a size for an
            // embedded image; attached images always fill.
            if (img.classList.contains('msgsc-attachment-thumb') || !img.hasAttribute('width')) {
                img.classList.add('msgsc-fill');
            }
            if (!img.closest('a')) {
                img.setAttribute('tabindex', '0');
                img.setAttribute('role', 'button');
                img.setAttribute('aria-label', img.alt ? `${str('imageviewer')}: ${img.alt}` : str('imageviewer'));
            }
        };
        if (img.complete) {
            setup();
        } else {
            img.addEventListener('load', setup, {once: true});
        }
    });
};

/**
 * Catch clicks and key presses on message images in a widget.
 *
 * @param {HTMLElement} root
 */
export const listen = (root) => {
    const open = (e) => {
        if (selecting(root)) {
            return;
        }
        const image = imageFor(e.target);
        if (!image) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        show(image).catch(Notification.exception);
    };
    root.addEventListener('click', open, true);
    root.addEventListener('keydown', (e) => {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('img.msgsc-picture')) {
            open(e);
        }
    }, true);
};
