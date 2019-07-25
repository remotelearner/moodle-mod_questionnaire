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
 * Mobile output class for mod_questionnaire.
 *
 * @copyright 2018 Igor Sazonov <sovletig@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_questionnaire\output;

defined('MOODLE_INTERNAL') || die();

class mobile {

    /**
     * Returns the initial page when viewing the activity for the mobile app.
     *
     * @param  array $args Arguments from tool_mobile_get_content WS
     * @return array HTML, javascript and other data
     */
    public static function mobile_view_activity($args) {
        global $OUTPUT, $USER, $CFG, $DB;
        require_once($CFG->dirroot.'/mod/questionnaire/questionnaire.class.php');

        $args = (object) $args;

        $cmid = $args->cmid;
        $rid = isset($args->rid) ? $args->rid : 0;
        $action = (isset($args->action)) ? $args->action : 'index';
        $pagenum = (isset($args->pagenum) && !empty($args->pagenum)) ? intval($args->pagenum) : 1;

        list($cm, $course, $questionnaire) = questionnaire_get_standard_page_items($cmid);
        $questionnaire = new \questionnaire(0, $questionnaire, $course, $cm);

        $data = [];
        $data['cmid'] = $cmid;
        $data['userid'] = $USER->id;
        $data['intro'] = $questionnaire->intro;
        $data['autonumquestions'] = $questionnaire->autonum;
        $data['id'] = $questionnaire->id;
        $data['rid'] = $rid;
        $data['surveyid'] = $questionnaire->survey->id;
        $data['pagenum'] = $pagenum;
        $data['prevpage'] = 0;
        $data['nextpage'] = 0;

        // Capabilities check.
        $context = \context_module::instance($cmid);
        self::require_capability($cm, $context, 'mod/questionnaire:view');

        // Any notifications will be displayed on top of main page, and prevent questionnaire from being completed. This also checks
        // appropriate capabilities.
        $data['notifications'] = $questionnaire->user_access_messages($USER->id);
        $responses = [];

        $data['emptypage'] = 1;
        $template = 'mod_questionnaire/mobile_main_index_page';

        switch ($action) {
            case 'index':
                // List any existing submissions, if user is allowed to review them.
                if ($questionnaire->capabilities->readownresponses) {
                    $questionnaire->add_user_responses();
                    $submissions = [];
                    foreach ($questionnaire->responses as $response) {
                        $submissions[] = ['submissiondate' => userdate($response->submitted), 'submissionid' => $response->id];
                    }
                    if (!empty($submissions)) {
                        $data['submissions'] = $submissions;
                    } else {
                        $data['emptypage'] = 1;
                    }
                    if ($questionnaire->user_has_saved_response($USER->id)) {
                        $data['resume'] = 1;
                    }
                    $data['emptypage'] = 0;
                    $template = 'mod_questionnaire/mobile_main_index_page';
                }
                break;

            case 'respond':
            case 'resume':
            case 'nextpage':
            case 'previouspage':
                // Completing a questionnaire.
                if (!$data['notifications']) {
                    if ($questionnaire->user_has_saved_response($USER->id) && empty($rid)) {
                        $rid = $questionnaire->get_latest_responseid($USER->id);
                        $questionnaire->add_response($rid);
                        $data['rid'] = $rid;
                    }
                    $response = (isset($questionnaire->responses) && !empty($questionnaire->responses)) ?
                        end($questionnaire->responses) : null;
                    if ($action == 'nextpage') {
                        $nextpage = $questionnaire->next_page($pagenum, $response->id);
                        if ($nextpage === false) {
                            $pagenum = count($questionnaire->questionsbysec);
                        } else {
                            $pagenum = $nextpage;
                        }
                    } else if ($action == 'previouspage') {
                        $prevpage = $questionnaire->prev_page($pagenum, $response->id);
                        if ($prevpage === false) {
                            $pagenum = 1;
                        } else {
                            $pagenum = $prevpage;
                        }
                    }
                    $qnum = 1;
                    $pagequestions = [];
                    foreach ($questionnaire->questionsbysec[$pagenum] as $questionid) {
                        $question = $questionnaire->questions[$questionid];
                        if ($question->supports_mobile()) {
                            $pagequestions[] = $question->mobile_question_display($qnum, $questionnaire->autonum, $response);
                            if (($response !== null) && isset($response->answers[$questionid])) {
                                $responses = array_merge($responses, $question->get_mobile_response_data($response));
                            }
                        }
                        $qnum++;
                    }
                    $numpages = count($questionnaire->questionsbysec);
                    // Set some variables we are going to be using.
                    if (!empty($questionnaire->questionsbysec) && ($numpages > 1)) {
                        if ($pagenum > 1) {
                            $data['prevpage'] = true;
                        }
                        if ($pagenum < $numpages) {
                            $data['nextpage'] = true;
                        }
                    }
                    $data['pagenum'] = $pagenum;
                    $data['pagequestions'] = $pagequestions;
                    $data['completed'] = 0;
                    $data['emptypage'] = 0;
                    $template = 'mod_questionnaire/mobile_view_activity_page';
                }
                break;

            case 'review':
                // If reviewing a submission.
                if ($questionnaire->capabilities->readownresponses && isset($args->submissionid) && !empty($args->submissionid)) {
                    $questionnaire->add_response($args->submissionid);
                    $response = $questionnaire->responses[$args->submissionid];
                    $qnum = 1;
                    $pagequestions = [];
                    foreach ($questionnaire->questions as $question) {
                        if ($question->supports_mobile()) {
                            $pagequestions[] = $question->mobile_question_display($qnum, $questionnaire->autonum, $response);
                            $responses = array_merge($responses, $question->get_mobile_response_data($response));
                            $qnum++;
                        }
                    }
                    $data['prevpage'] = 0;
                    $data['nextpage'] = 0;
                    $data['pagequestions'] = $pagequestions;
                    $data['completed'] = 1;
                    $data['emptypage'] = 0;
                    $template = 'mod_questionnaire/mobile_view_activity_page';
                }
                break;
        }

        $return = [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template($template, $data)
                ],
            ],
            'otherdata' => [
                'responses' => json_encode($responses),
            ],
            'files' => null
        ];
        return $return;
    }

    /**
     * Confirms the user is logged in and has the specified capability.
     *
     * @param \stdClass $cm
     * @param \context $context
     * @param string $cap
     */
    protected static function require_capability(\stdClass $cm, \context $context, string $cap) {
        require_login($cm->course, false, $cm, true, true);
        require_capability($cap, $context);
    }
}