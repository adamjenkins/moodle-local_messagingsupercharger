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
 * Full-size image viewer for images in messages.
 *
 * Clicking an image in a message (or activating the link around an attached image) opens
 * it in a dialogue at its real size, scrollable, with a switch to fit it to the window
 * and a link to the original. The click is caught before core's handlers, so it neither
 * follows the link nor toggles the message's selection.
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

/**
 * The image a click in message text is about, if any.
 *
 * @param {HTMLElement} target
 * @returns {Object|null} {src, alt, link}
 */
const imageFor = (target) => {
    if (!target.closest || !target.closest(Selectors.MESSAGE_TEXT)) {
        return null;
    }
    const link = target.closest('a.msgsc-attachment-image');
    const img = link ? link.querySelector('img') : target.closest('img');
    if (!img) {
        return null;
    }
    // An attached image's link points at the original; an embedded image is its own source.
    const src = link ? link.href : img.currentSrc || img.src;
    return {src, alt: img.alt || '', returnTo: link || img};
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
    const body = el('div', {}, [el('div', {className: 'd-flex gap-2 mb-2'}, [fit, original]), stage]);
    const modal = await Modal.create({
        title: image.alt || str('imageviewer'),
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
 * Catch clicks on message images in a widget.
 *
 * @param {HTMLElement} root
 */
export const listen = (root) => {
    root.addEventListener('click', (e) => {
        const image = imageFor(e.target);
        if (!image) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        show(image).catch(Notification.exception);
    }, true);
};
