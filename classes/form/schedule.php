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
use local_messagingsupercharger\local\scheduler;

/**
 * Schedule a message, or change a scheduled one.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule extends \core_form\dynamic_form {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        foreach (['id', 'conversationid', 'draftitemid'] as $field) {
            $mform->addElement('hidden', $field);
            $mform->setType($field, PARAM_INT);
        }
        $mform->addElement('hidden', 'mentions');
        $mform->setType('mentions', PARAM_SEQUENCE);
        // Names of the drawer's attached files, separated by "/" (which a file name cannot contain).
        $mform->addElement('hidden', 'filenames');
        $mform->setType('filenames', PARAM_RAW);

        $mform->addElement('textarea', 'message', get_string('message', 'core_message'), ['rows' => 5, 'cols' => 60]);
        $mform->setType('message', PARAM_RAW);
        $mform->addElement(
            'date_time_selector',
            'timesend',
            get_string('sendat', 'local_messagingsupercharger'),
            ['step' => 1, 'optional' => false]
        );
        $mform->addElement('static', 'schedulenote', '', get_string('schedulenote', 'local_messagingsupercharger'));
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $USER;
        $errors = parent::validation($data, $files);
        $hasattachments = !empty($data['draftitemid']) && \local_messagingsupercharger\local\attachments::draft_files(
            (int)$USER->id,
            (int)$data['draftitemid']
        );
        if (trim((string)$data['message']) === '' && !$hasattachments) {
            $errors['message'] = get_string('emptymessage', 'local_messagingsupercharger');
        }
        if ((int)$data['timesend'] <= time() + 30) {
            $errors['timesend'] = get_string('scheduleinpast', 'local_messagingsupercharger');
        }
        return $errors;
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
     * Check access.
     */
    protected function check_access_for_dynamic_submission(): void {
        global $USER;
        features::require_enabled(features::SCHEDULING);
        $conversation = conversations::get($this->optional_param('conversationid', 0, PARAM_INT));
        conversations::require_member((int)$USER->id, (int)$conversation->id);
        conversations::require_capability('schedulesend', $conversation, (int)$USER->id);
    }

    /**
     * Save.
     *
     * @return array
     */
    public function process_dynamic_submission() {
        global $USER;
        $data = $this->get_data();
        if (!empty($data->id)) {
            scheduler::update((int)$data->id, (int)$USER->id, (string)$data->message, (int)$data->timesend);
            $id = (int)$data->id;
        } else {
            $mentions = $data->mentions === '' ? [] : array_map('intval', explode(',', $data->mentions));
            $filenames = null;
            if ((string)$data->filenames !== '') {
                $filenames = array_values(array_filter(array_map(
                    fn($name) => clean_param($name, PARAM_FILE),
                    explode('/', $data->filenames)
                )));
            }
            $id = scheduler::schedule(
                (int)$USER->id,
                (int)$data->conversationid,
                (string)$data->message,
                FORMAT_PLAIN,
                (int)$data->timesend,
                (int)$data->draftitemid,
                $mentions,
                $filenames
            );
        }
        return ['id' => $id];
    }

    /**
     * Fill in the form.
     */
    public function set_data_for_dynamic_submission(): void {
        global $USER;
        $id = $this->optional_param('id', 0, PARAM_INT);
        $conversationid = $this->optional_param('conversationid', 0, PARAM_INT);
        $data = [
            'id' => 0,
            'conversationid' => $conversationid,
            'draftitemid' => $this->optional_param('draftitemid', 0, PARAM_INT),
            'mentions' => $this->optional_param('mentions', '', PARAM_SEQUENCE),
            'filenames' => $this->optional_param('filenames', '', PARAM_RAW),
            'message' => $this->optional_param('text', '', PARAM_RAW),
            'timesend' => time() + HOURSECS,
        ];
        if ($id) {
            foreach (scheduler::list((int)$USER->id, $conversationid) as $item) {
                if ($item['id'] === $id) {
                    $data['id'] = $id;
                    $data['message'] = $item['text'];
                    $data['timesend'] = $item['timesend'];
                    $data['draftitemid'] = 0;
                }
            }
        }
        $this->set_data($data);
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
