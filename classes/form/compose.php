<?php
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

namespace local_messagingsupercharger\form;

use local_messagingsupercharger\local\conversations;
use local_messagingsupercharger\local\features;
use local_messagingsupercharger\local\sender;

/**
 * Rich-text compose form, shown in a modal from the message drawer.
 *
 * The form validates and hands the composed message back to the browser, which sends it
 * through the drawer's own send queue (so the drawer shows it like any other message);
 * the send itself is local_messagingsupercharger_send_messages, which checks everything
 * again. The attachment file manager uses the same draft area as the drawer's drag and
 * drop, so files dropped on the drawer appear here and vice versa.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compose extends \core_form\dynamic_form {
    /** @var \stdClass|null Cached conversation. */
    protected $conversation = null;

    /**
     * The conversation this form sends to.
     *
     * @return \stdClass
     */
    protected function get_conversation(): \stdClass {
        if ($this->conversation === null) {
            $this->conversation = conversations::get($this->optional_param('conversationid', 0, PARAM_INT));
        }
        return $this->conversation;
    }

    /**
     * Editor options.
     *
     * @return array
     */
    protected function editor_options(): array {
        return [
            'maxfiles' => features::enabled(features::ATTACHMENTS) ? features::max_attachments() : 0,
            'maxbytes' => features::max_attachment_size(),
            'context' => \context_user::instance($this->get_current_user_id()),
            'autosave' => false,
            'removeorphaneddrafts' => true,
            'subdirs' => 0,
        ];
    }

    /**
     * The current user's id.
     *
     * @return int
     */
    protected function get_current_user_id(): int {
        global $USER;
        return (int)$USER->id;
    }

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'conversationid');
        $mform->setType('conversationid', PARAM_INT);

        $mform->addElement(
            'editor',
            'message',
            get_string('message', 'core_message'),
            ['rows' => 10],
            $this->editor_options()
        );
        $mform->setType('message', PARAM_RAW);

        if (features::enabled(features::ATTACHMENTS)) {
            $mform->addElement('filemanager', 'attachments', get_string('attachments', 'local_messagingsupercharger'), null, [
                'subdirs' => 0,
                'maxbytes' => features::max_attachment_size(),
                'maxfiles' => features::max_attachments(),
                'accepted_types' => features::attachment_types(),
                'return_types' => FILE_INTERNAL,
            ]);
        }
    }

    /**
     * Validate.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $text = $data['message']['text'] ?? '';
        $hasattachments = !empty($data['attachments'])
            && (bool)\local_messagingsupercharger\local\attachments::draft_files(
                $this->get_current_user_id(),
                (int)$data['attachments']
            );
        try {
            sender::check($this->get_current_user_id(), $this->get_conversation(), $text, FORMAT_HTML, $hasattachments, []);
        } catch (\moodle_exception $e) {
            $errors['message'] = $e->getMessage();
        }
        return $errors;
    }

    /**
     * Context for the submission.
     *
     * @return \context
     */
    protected function get_context_for_dynamic_submission(): \context {
        return \context_system::instance();
    }

    /**
     * Check access.
     */
    protected function check_access_for_dynamic_submission(): void {
        features::require_enabled(features::RICHTEXT);
        $conversation = $this->get_conversation();
        conversations::require_can_send($this->get_current_user_id(), (int)$conversation->id);
        conversations::require_capability('userichtext', $conversation, $this->get_current_user_id());
    }

    /**
     * Return what the browser needs to send the message.
     *
     * @return array
     */
    public function process_dynamic_submission() {
        $data = $this->get_data();
        return [
            'conversationid' => (int)$data->conversationid,
            'text' => (string)$data->message['text'],
            'format' => FORMAT_HTML,
            'editordraftitemid' => (int)($data->message['itemid'] ?? 0),
            'draftitemid' => (int)($data->attachments ?? 0),
            'preview' => shorten_text(trim(html_to_text((string)$data->message['text'], 0, false)), 200),
        ];
    }

    /**
     * Prefill the editor with what was typed in the drawer.
     */
    public function set_data_for_dynamic_submission(): void {
        $conversation = $this->get_conversation();
        $text = $this->optional_param('text', '', PARAM_RAW);
        $draftitemid = $this->optional_param('draftitemid', 0, PARAM_INT);
        $html = $text === '' ? '' : sender::plain_to_html($text);
        $this->set_data([
            'conversationid' => $conversation->id,
            'message' => ['text' => $html, 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()],
            'attachments' => $draftitemid,
        ]);
    }

    /**
     * Page URL.
     *
     * @return \moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): \moodle_url {
        return new \moodle_url('/message/index.php', ['convid' => $this->optional_param('conversationid', 0, PARAM_INT)]);
    }
}
