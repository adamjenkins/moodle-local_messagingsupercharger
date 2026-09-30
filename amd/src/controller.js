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
 * One controller per messaging widget (the drawer, and the messages page).
 *
 * A MutationObserver watches the widget; whenever core changes it (a conversation is
 * opened, messages are rendered, a sent message receives its real id) the controller
 * re-applies the plugin's additions. Plugin data for the open conversation is fetched on
 * change and then every few seconds while the conversation is visible.
 *
 * @module     local_messagingsupercharger/controller
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Pending from 'core/pending';
import {subscribe} from 'core/pubsub';
import MessageDrawerEvents from 'core_message/message_drawer_events';
import Selectors from 'local_messagingsupercharger/selectors';
import * as Repository from 'local_messagingsupercharger/repository';
import * as Interceptor from 'local_messagingsupercharger/send_interceptor';
import * as ImageViewer from 'local_messagingsupercharger/image_viewer';
import * as Resize from 'local_messagingsupercharger/resize';
import * as Search from 'local_messagingsupercharger/search';
import * as Settings from 'local_messagingsupercharger/settings';
import Composer from 'local_messagingsupercharger/composer';
import Mentions from 'local_messagingsupercharger/mentions';
import Decorations from 'local_messagingsupercharger/decorations';
import Strip from 'local_messagingsupercharger/strip';
import {config} from 'local_messagingsupercharger/state';

export default class Controller {
    /**
     * @param {HTMLElement} root A widget root
     */
    constructor(root) {
        this.root = root;
        this.conversationId = null;
        this.extras = null;
        this.since = 0;
        this.fetching = false;
        this.refetch = false;
        this.refreshTimer = null;
        this.extrasTimer = null;
        this.created = {};
        this.mentions = new Mentions(this);
        this.composer = new Composer(this);
        this.decorations = new Decorations(this);
        this.strip = new Strip(this);
        ImageViewer.listen(root);

        // Core only puts the conversation id on the footer when a conversation is opened.
        // A first message to someone creates the conversation without doing that, so
        // learn its id from core's own event (matched on the other member).
        subscribe(MessageDrawerEvents.CONVERSATION_CREATED, (conversation) => {
            (conversation.members || []).forEach((member) => {
                if (member.id !== conversation.loggedInUserId) {
                    this.created[member.id] = conversation.id;
                }
            });
            this.scheduleRefresh();
        });

        this.observer = new MutationObserver(() => this.scheduleRefresh());
        this.observer.observe(root, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['data-conversation-id', 'data-other-user-id', 'data-message-id', 'aria-hidden'],
        });
        const interval = Math.max(5, config().pollinterval || 15) * 1000;
        this.poll = setInterval(() => {
            if (this.conversationVisible() && document.visibilityState === 'visible') {
                this.fetchExtras();
            }
        }, interval);
        this.scheduleRefresh();
    }

    /**
     * The conversation footer of this widget, which carries the conversation id.
     *
     * @returns {HTMLElement|null}
     */
    footerView() {
        return this.root.querySelector(Selectors.FOOTER_CONTAINER + ' ' + Selectors.VIEW_CONVERSATION);
    }

    /**
     * Is the conversation view showing?
     *
     * @returns {Boolean}
     */
    conversationVisible() {
        const body = this.root.querySelector(Selectors.BODY_CONTAINER + ' ' + Selectors.VIEW_CONVERSATION);
        return !!(body && body.getAttribute('aria-hidden') !== 'true' && !body.classList.contains('hidden'));
    }

    /**
     * The id of the conversation currently loaded, or null.
     *
     * @returns {Number|null}
     */
    currentConversationId() {
        const footer = this.footerView();
        if (!footer) {
            return null;
        }
        let id = parseInt(footer.getAttribute('data-conversation-id'), 10);
        if (!Number.isFinite(id)) {
            const otheruserid = footer.getAttribute('data-other-user-id');
            if (otheruserid && this.created[otheruserid] === undefined) {
                this.lookUpConversation(otheruserid);
            }
            id = parseInt(this.created[otheruserid], 10);
        }
        return Number.isFinite(id) && id > 0 ? id : null;
    }

    /**
     * Find the conversation with a user when core opened it by user (0 = none yet).
     *
     * @param {String} otheruserid
     */
    lookUpConversation(otheruserid) {
        this.created[otheruserid] = null;
        const pending = new Pending('local_messagingsupercharger/controller:lookup');
        Repository.getIndividualConversation(parseInt(otheruserid, 10))
            .then((result) => {
                if (this.created[otheruserid] === null) {
                    this.created[otheruserid] = result.conversationid;
                }
                this.scheduleRefresh();
                return result;
            })
            .catch(() => {
                delete this.created[otheruserid];
            })
            .then(() => pending.resolve())
            .catch(() => pending.resolve());
    }

    /**
     * Batch refreshes: core often changes many nodes at once.
     */
    scheduleRefresh() {
        if (this.refreshTimer) {
            return;
        }
        this.refreshTimer = setTimeout(() => {
            this.refreshTimer = null;
            this.refresh();
        }, 50);
    }

    /**
     * Bring the plugin's additions in line with what core is showing.
     */
    refresh() {
        const id = this.currentConversationId();
        if (id !== this.conversationId) {
            this.changeConversation(id);
        }
        this.composer.ensure();
        this.mentions.ensure();
        Resize.ensure(this.root);
        Search.ensure(this.root);
        Settings.ensure(this.root);
        if (id) {
            this.strip.ensure();
            if (this.decorations.decorateAll()) {
                this.requestExtrasSoon(400);
            }
        }
    }

    /**
     * A different conversation was opened.
     *
     * @param {Number|null} id
     */
    changeConversation(id) {
        this.conversationId = id;
        this.extras = null;
        this.since = 0;
        Interceptor.reset();
        this.composer.reset();
        this.composer.applyPermissions(null);
        this.mentions.reset();
        this.strip.clear();
        if (id) {
            this.requestExtrasSoon(0);
        }
    }

    /**
     * Fetch plugin data after a short delay (coalescing bursts).
     *
     * @param {Number} delay Milliseconds
     */
    requestExtrasSoon(delay) {
        clearTimeout(this.extrasTimer);
        this.extrasTimer = setTimeout(() => this.fetchExtras(), delay);
    }

    /**
     * Fetch plugin data for the open conversation.
     */
    fetchExtras() {
        const id = this.conversationId;
        if (!id) {
            return;
        }
        if (this.fetching) {
            this.refetch = true;
            return;
        }
        this.fetching = true;
        const pending = new Pending('local_messagingsupercharger/controller:extras');
        Repository.getConversationExtras(id, this.decorations.messageIds(), this.since)
            .then((extras) => {
                if (extras.conversationid !== this.conversationId) {
                    return extras;
                }
                this.extras = extras;
                this.since = extras.servertime;
                this.composer.applyPermissions(extras.permissions);
                this.decorations.apply(extras);
                this.strip.render(extras);
                return extras;
            })
            .catch(() => {
                // A failed refresh is retried on the next poll; nothing to tell the user.
                return null;
            })
            .then(() => {
                this.fetching = false;
                pending.resolve();
                if (this.refetch) {
                    this.refetch = false;
                    this.requestExtrasSoon(200);
                }
                return null;
            })
            .catch(() => pending.resolve());
    }
}
