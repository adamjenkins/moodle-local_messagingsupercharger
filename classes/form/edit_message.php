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

use local_messagingsupercharger\local\editing;
use local_messagingsupercharger\local\sender;

/**
 * Edit one of your own messages: a plain text box, or the rich-text editor for a message
 * written in it.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_message extends \core_form\dynamic_form {
    /** @var array|null The editable text and format. */
    protected $editable = null;

    /**
     * The message's current editable text; loading it checks the user may edit it.
     *
     * @return array
     */
    protected function get_editable(): array {
        global $USER;
        if ($this->editable === null) {
            $this->editable = editing::get_editable($this->optional_param('messageid', 0, PARAM_INT), (int)$USER->id);
        }
        return $this->editable;
    }

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'messageid');
        $mform->setType('messageid', PARAM_INT);
        if (sender::is_html($this->get_editable()['format'])) {
            $mform->addElement(
                'editor',
                'message',
                get_string('message', 'core_message'),
                ['rows' => 8],
                ['maxfiles' => 0, 'autosave' => false, 'context' => \context_system::instance()]
            );
            $mform->setType('message', PARAM_RAW);
        } else {
            $mform->addElement('textarea', 'message', get_string('message', 'core_message'), ['rows' => 5, 'cols' => 60]);
            $mform->setType('message', PARAM_RAW);
        }
        $mform->addRule('message', null, 'required', null, 'client');
        $mform->addElement('static', 'editnote', '', get_string('editnoemail', 'local_messagingsupercharger'));
    }

    /**
     * Context.
     *
     * @return \context
     */
    protected function get_context_for_dynamic_submission(): \context {
        return \context_system::instance();
    }

    /**
     * Access is checked by loading the editable text.
     */
    protected function check_access_for_dynamic_submission(): void {
        $this->get_editable();
    }

    /**
     * Save the edit.
     *
     * @return array
     */
    public function process_dynamic_submission() {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/message/lib.php');
        $data = $this->get_data();
        $text = is_array($data->message) ? (string)$data->message['text'] : (string)$data->message;
        $message = editing::edit((int)$data->messageid, (int)$USER->id, $text);
        return [
            'messageid' => (int)$message->id,
            'text' => sender::format_for_display($message),
            'timeedited' => (int)$DB->get_field('local_messagingsupercharger_meta', 'timeedited', ['messageid' => $message->id]),
        ];
    }

    /**
     * Fill in the current text.
     */
    public function set_data_for_dynamic_submission(): void {
        $editable = $this->get_editable();
        $value = sender::is_html($editable['format'])
            ? ['text' => $editable['text'], 'format' => FORMAT_HTML]
            : $editable['text'];
        $this->set_data(['messageid' => $this->optional_param('messageid', 0, PARAM_INT), 'message' => $value]);
    }

    /**
     * Page URL.
     *
     * @return \moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): \moodle_url {
        return new \moodle_url('/message/index.php');
    }
}
