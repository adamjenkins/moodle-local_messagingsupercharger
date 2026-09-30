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
 * A handle on the message drawer's left edge to make it wider or narrower.
 *
 * The handle is a focusable ARIA separator: drag it, or use the arrow keys (Shift for
 * bigger steps), Home and End; double-click restores the theme's width. The
 * chosen width is remembered in this browser only, because the right width depends on
 * the screen. On narrow screens the drawer keeps the theme's own layout.
 *
 * @module     local_messagingsupercharger/resize
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Selectors from 'local_messagingsupercharger/selectors';
import {enabled, str} from 'local_messagingsupercharger/state';
import {el} from 'local_messagingsupercharger/dom';

const STORAGE_KEY = 'local_messagingsupercharger_drawerwidth';
const MIN_WIDTH = 280;
const MIN_SCREEN = 768;
const STEP = 20;
const BIG_STEP = 80;

/**
 * Read the remembered width.
 *
 * @returns {Number|null}
 */
const loadWidth = () => {
    try {
        const value = parseInt(window.localStorage.getItem(STORAGE_KEY), 10);
        return Number.isFinite(value) ? value : null;
    } catch (e) {
        return null;
    }
};

/**
 * Remember a width (null forgets it).
 *
 * @param {Number|null} width
 */
const saveWidth = (width) => {
    try {
        if (width === null) {
            window.localStorage.removeItem(STORAGE_KEY);
        } else {
            window.localStorage.setItem(STORAGE_KEY, String(width));
        }
    } catch (e) {
        // Storage unavailable (private window, blocked site data): the width just is not remembered.
    }
};

/**
 * The widest the drawer may be on this screen.
 *
 * @returns {Number}
 */
const maxWidth = () => Math.max(MIN_WIDTH, Math.min(1200, window.innerWidth - 120));

/**
 * Add the handle to the message drawer of a widget.
 *
 * @param {HTMLElement} root A widget root
 */
export const ensure = (root) => {
    if (!enabled('resizabledrawer') || root.getAttribute('data-region') !== 'message-drawer') {
        return;
    }
    const container = root.closest(Selectors.DRAWER_CONTAINER);
    if (!container || root.querySelector(':scope > .msgsc-resize-handle')) {
        return;
    }

    let themeWidth = null;
    const handle = el('div', {
        className: 'msgsc-resize-handle',
        role: 'separator',
        tabindex: '0',
        'aria-orientation': 'vertical',
        'aria-label': str('resizedrawer'),
        title: str('resizedrawer'),
        'aria-valuemin': String(MIN_WIDTH),
    });
    // Inside the drawer's own element, so that core's focus lock lets keyboard users reach
    // it; positioned against the drawer container's left edge by CSS.
    root.insertBefore(handle, root.firstChild);

    /**
     * The drawer's width as the theme lays it out.
     *
     * @returns {Number}
     */
    const naturalWidth = () => {
        if (themeWidth === null) {
            const previous = [container.style.width, container.style.maxWidth];
            container.style.width = '';
            container.style.maxWidth = '';
            themeWidth = Math.round(container.getBoundingClientRect().width) || 315;
            [container.style.width, container.style.maxWidth] = previous;
        }
        return themeWidth;
    };

    /**
     * Apply a width (null = the theme's).
     *
     * @param {Number|null} width
     * @returns {Number} The width now in effect
     */
    const apply = (width) => {
        const active = window.innerWidth >= MIN_SCREEN;
        handle.hidden = !active;
        if (!active || width === null) {
            container.style.width = '';
            container.style.maxWidth = '';
            container.classList.remove('msgsc-resized');
            const natural = active ? naturalWidth() : 0;
            handle.setAttribute('aria-valuenow', String(natural));
            return natural;
        }
        const clamped = Math.round(Math.min(maxWidth(), Math.max(MIN_WIDTH, width)));
        container.style.width = clamped + 'px';
        container.style.maxWidth = clamped + 'px';
        container.classList.add('msgsc-resized');
        handle.setAttribute('aria-valuenow', String(clamped));
        handle.setAttribute('aria-valuemax', String(maxWidth()));
        return clamped;
    };

    const current = () => Math.round(container.getBoundingClientRect().width) || naturalWidth();

    handle.setAttribute('aria-valuemax', String(maxWidth()));
    apply(loadWidth());

    // Pointer drag. The drawer is on the right, so moving left makes it wider.
    let dragStartX = 0;
    let dragStartWidth = 0;
    handle.addEventListener('pointerdown', (e) => {
        if (e.button !== 0) {
            return;
        }
        e.preventDefault();
        dragStartX = e.clientX;
        dragStartWidth = current();
        handle.setPointerCapture(e.pointerId);
        container.classList.add('msgsc-resizing');
    });
    handle.addEventListener('pointermove', (e) => {
        if (!handle.hasPointerCapture(e.pointerId)) {
            return;
        }
        apply(dragStartWidth + (dragStartX - e.clientX));
    });
    const endDrag = (e) => {
        if (!handle.hasPointerCapture(e.pointerId)) {
            return;
        }
        handle.releasePointerCapture(e.pointerId);
        container.classList.remove('msgsc-resizing');
        saveWidth(apply(current()));
        // The click that ends a drag lands outside the drawer, which core treats as
        // "click outside: close the drawer". Swallow that one click.
        const swallow = (click) => {
            click.stopPropagation();
            click.preventDefault();
        };
        window.addEventListener('click', swallow, {capture: true, once: true});
        setTimeout(() => window.removeEventListener('click', swallow, {capture: true}), 300);
    };
    handle.addEventListener('pointerup', endDrag);
    handle.addEventListener('pointercancel', endDrag);
    handle.addEventListener('dblclick', () => {
        saveWidth(null);
        apply(null);
    });

    // Keyboard.
    handle.addEventListener('keydown', (e) => {
        const step = e.shiftKey ? BIG_STEP : STEP;
        let width = null;
        if (e.key === 'ArrowLeft') {
            width = current() + step;
        } else if (e.key === 'ArrowRight') {
            width = current() - step;
        } else if (e.key === 'Home') {
            width = MIN_WIDTH;
        } else if (e.key === 'End') {
            width = maxWidth();
        } else {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        saveWidth(apply(width));
    });

    window.addEventListener('resize', () => apply(loadWidth()));
};
