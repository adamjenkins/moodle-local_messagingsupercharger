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
 * THE list of core DOM selectors this plugin depends on.
 *
 * Everything the plugin knows about core's messaging markup is here and nowhere else, so
 * that when a core upgrade changes the drawer, this file (plus the web service names in
 * send_interceptor.js) is all that needs checking. Each entry names the core template it
 * comes from (core_message, Moodle 5.2/5.3; identical in both branches).
 *
 * @module     local_messagingsupercharger/selectors
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export default {
    // Widget roots: the drawer on every page (message_drawer.mustache) and the full
    // messages page (message_index.mustache). Both can be on /message/index.php at once.
    ROOTS: '[data-region="message-drawer"], [data-region="message-index"]',

    // The drawer's outer container (core/drawer.mustache); closing the drawer hides this.
    DRAWER_CONTAINER: '[data-region="right-hand-drawer"]',

    // Containers inside a root.
    HEADER_CONTAINER: '[data-region="header-container"]',
    BODY_CONTAINER: '[data-region="body-container"]',
    FOOTER_CONTAINER: '[data-region="footer-container"]',
    PANEL_HEADER_CONTAINER: '[data-region="panel-header-container"]',

    // The conversation view: body (message_drawer_view_conversation_body.mustache) and
    // footer (message_drawer_view_conversation_footer.mustache) share this data-region.
    // From Moodle 5.1 the footer carries data-conversation-id once a conversation is loaded
    // (4.5 does not; the controller follows core's route changes there).
    VIEW_CONVERSATION: '[data-region="view-conversation"]',

    // Scrolling list of days and messages (message_drawer_view_conversation_body.mustache).
    CONTENT_MESSAGE_CONTAINER: '[data-region="content-message-container"]',

    // One message (message_drawer_view_conversation_body_message.mustache). A message being
    // sent has a temporary id starting "temp" that core renames in place when it is saved.
    MESSAGE: '[data-region="message"][data-message-id]',
    MESSAGE_TEXT: '[data-region="text-container"]',

    // The send area (message_drawer_view_conversation_footer_content.mustache).
    FOOTER_CONTENT: '[data-region="content-messages-footer-container"]',
    TEXTAREA: '[data-region="send-message-txt"]',
    SEND_BUTTON: '[data-action="send-message"]',

    // Overview header, where the search button goes (message_drawer_view_overview_header.mustache).
    VIEW_OVERVIEW: '[data-region="view-overview"]',

    // Settings panel body (message_drawer_view_settings_body.mustache).
    VIEW_SETTINGS: '[data-region="view-settings"]',
};
