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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat steps for local_messagingsupercharger.
 *
 * @package    local_messagingsupercharger
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_messagingsupercharger extends behat_base {
    /** @var string A tiny valid PNG, base64. */
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * Drop a file on the open conversation of the message drawer, as a browser would.
     *
     * Browsers cannot be given a real file drag from outside the page, so this builds
     * the file in the page and dispatches the same dragenter, dragover and drop events.
     *
     * @When /^I drop a file named "(?P<name_string>(?:[^"]|\\")*)" on the message conversation$/
     * @param string $name File name; a .png gets a real image, anything else some text
     */
    public function i_drop_a_file_named_on_the_message_conversation(string $name): void {
        $this->require_javascript();
        $js = <<<JS
(function(name, png) {
    const bytes = name.toLowerCase().endsWith('.png')
        ? Uint8Array.from(atob(png), c => c.charCodeAt(0))
        : new TextEncoder().encode('Dropped file content');
    const file = new File([bytes], name, {type: name.toLowerCase().endsWith('.png') ? 'image/png' : 'text/plain'});
    const data = new DataTransfer();
    data.items.add(file);
    const target = document.querySelector(
        '[data-region="message-drawer"] [data-region="body-container"] [data-region="view-conversation"]');
    ['dragenter', 'dragover', 'drop'].forEach(type => {
        target.dispatchEvent(new DragEvent(type, {dataTransfer: data, bubbles: true, cancelable: true}));
    });
})(%s, %s);
JS;
        $this->execute_script(sprintf($js, json_encode($name), json_encode(self::PNG)));
        $this->wait_for_pending_js();
    }

    /**
     * Check the raw response (a downloaded file is not an HTML page, so "I should see" cannot).
     *
     * @Then /^the response should( not)? contain "(?P<text_string>(?:[^"]|\\")*)"$/
     * @param string $not
     * @param string $text
     */
    public function the_response_should_contain(string $not, string $text): void {
        $content = $this->getSession()->getPage()->getContent();
        $found = strpos($content, $text) !== false;
        if ($found === ($not !== '')) {
            throw new \Behat\Mink\Exception\ExpectationException(
                '"' . $text . '"' . ($found ? ' was found in' : ' was not found in') . ' the response',
                $this->getSession()
            );
        }
    }

    /**
     * Try to fetch an attachment and check it is refused without revealing its content.
     *
     * Moodle's "file not found" page includes a debugging trace on test sites, which
     * Behat's after-step check reports as a failure; this step checks the refusal itself
     * and then leaves that page.
     *
     * @Then /^I should be refused the message attachment "(?P<filename_string>(?:[^"]|\\")*)"$/
     * @param string $filename
     */
    public function i_should_be_refused_the_message_attachment(string $filename): void {
        $this->getSession()->visit($this->locate_path($this->attachment_url($filename)->out_as_local_url(false)));
        $content = $this->getSession()->getPage()->getContent();
        $refused = strpos($content, get_string('filenotfound', 'error')) !== false;
        $leaked = strpos($content, 'Secret minutes') !== false;
        $this->getSession()->visit($this->locate_path('/'));
        if (!$refused || $leaked) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'The attachment "' . $filename . '" was not refused',
                $this->getSession()
            );
        }
    }

    /**
     * The download URL of an attachment.
     *
     * @param string $filename
     * @return moodle_url
     */
    protected function attachment_url(string $filename): moodle_url {
        global $DB;
        $file = $DB->get_record('files', ['component' => 'local_messagingsupercharger', 'filearea' => 'attachment',
            'filename' => $filename], '*', MUST_EXIST);
        return moodle_url::make_pluginfile_url(
            $file->contextid,
            $file->component,
            $file->filearea,
            $file->itemid,
            $file->filepath,
            $file->filename,
            true
        );
    }

    /**
     * Visit the download URL of an attachment, as whoever is logged in.
     *
     * @When /^I visit the message attachment "(?P<filename_string>(?:[^"]|\\")*)"$/
     * @param string $filename
     */
    public function i_visit_the_message_attachment(string $filename): void {
        $this->execute('behat_general::i_visit', [$this->attachment_url($filename)]);
    }
}
