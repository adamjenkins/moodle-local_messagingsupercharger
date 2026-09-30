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
 * Small DOM helpers. Text always goes in as textContent, never as HTML.
 *
 * @module     local_messagingsupercharger/dom
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

let uid = 0;

/**
 * Create an element.
 *
 * @param {String} tag
 * @param {Object} [attrs] Attributes; "text" sets textContent, "className" sets class
 * @param {Array} [children] Elements or strings
 * @returns {HTMLElement}
 */
export const el = (tag, attrs = {}, children = []) => {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([name, value]) => {
        if (value === null || value === undefined || value === false) {
            return;
        }
        if (name === 'text') {
            node.textContent = value;
        } else if (name === 'className') {
            node.className = value;
        } else {
            node.setAttribute(name, value === true ? '' : value);
        }
    });
    children.forEach((child) => {
        if (child === null || child === undefined) {
            return;
        }
        node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
};

/**
 * A button.
 *
 * @param {String} label Visible text
 * @param {Object} [attrs]
 * @returns {HTMLButtonElement}
 */
export const button = (label, attrs = {}) => el('button', Object.assign({type: 'button', text: label}, attrs));

/**
 * A decorative Font Awesome icon (the icon font core loads on every page).
 *
 * @param {String} classes E.g. "fa fa-paperclip"
 * @returns {HTMLElement}
 */
export const icon = (classes) => el('i', {className: `icon ${classes} fa-fw`, 'aria-hidden': 'true'});

/**
 * A page-unique id.
 *
 * @param {String} prefix
 * @returns {String}
 */
export const uniqueId = (prefix) => `${prefix}-${Date.now().toString(36)}-${++uid}`;

/**
 * Stop clicks and keys inside our controls from reaching core's message handlers (a
 * click anywhere in a message otherwise toggles its selection).
 *
 * @param {HTMLElement} node
 */
export const isolate = (node) => {
    ['click', 'keydown', 'keyup', 'keypress'].forEach((type) => {
        node.addEventListener(type, (e) => e.stopPropagation());
    });
};

/**
 * Show or hide an element for everyone, including screen readers.
 *
 * @param {HTMLElement} node
 * @param {Boolean} visible
 */
export const setVisible = (node, visible) => {
    if (!node) {
        return;
    }
    node.hidden = !visible;
};

/**
 * Format a unix time as a short local date/time.
 *
 * @param {Number} timestamp Seconds
 * @returns {String}
 */
export const formatTime = (timestamp) => {
    const date = new Date(timestamp * 1000);
    const now = new Date();
    const options = date.toDateString() === now.toDateString()
        ? {hour: 'numeric', minute: '2-digit'}
        : {day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit'};
    return date.toLocaleString(document.documentElement.lang || undefined, options);
};
