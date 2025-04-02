<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Right answer sheet viewed event class.
 *
 * @package   quiz_answersheets
 * @copyright 2019 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_answersheets\event;

/**
 * Right answer sheet viewed event class.
 *
 * @package   quiz_answersheets
 * @copyright 2019 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class right_answer_viewed extends base_event {

    /**
     * Returns the name of the event.
     *
     * @return string The name.
     */
    public static function get_name(): string {
        return get_string('event_right_answer_viewed', 'quiz_answersheets');
    }

    /**
     * Returns the description of the event.
     *
     * @return string The description.
     */
    public function get_description(): string {
        return 'The user with id ' . $this->userid . ' has viewed the right answer sheet with id ' . $this->other['attemptid'] .
                ' belonging to the user with id ' . $this->relateduserid . ' for the quiz with course module id ' .
                $this->contextinstanceid . '.';
    }

    /**
     * Returns the URL where the event can be viewed.
     *
     * @return \moodle_url The URL.
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/quiz/report/answersheets/attemptsheet.php',
                ['attempt' => $this->other['attemptid'], 'rightanswer' => 1]);
    }
}
