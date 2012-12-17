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
 * This file defines the report class for STACK questions.
 *
 * @copyright  2012 the University of Birmingham
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport.php');
require_once($CFG->dirroot . '/mod/quiz/report/statistics/report.php');


/**
 * Report subclass for the responses report to individual stack questions.
 *
 *
 * @copyright  2012 the University of Birmingham
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_stack_report extends quiz_attempts_report {

    /** @var int the relevant id of the question to be analysed.*/
    public $questionid;

    /** @array The names of all inputs for this question.*/
    private $inputs;

    /** @array The names of all prts for this question.*/
    private $prts;

    /** @array The deployed questionnotes for this question.*/
    private $qnotes;

    /** @array The attempts at this question.*/
    private $attempts;

    /*
     * Set the relevant id of the question to be analysed.
     */
    public function add_questionid($questionid) {
        $this->questionid = $questionid;
    }

    public function display($quiz, $cm, $course) {
        global $CFG, $DB, $OUTPUT;

        $this->context = context_module::instance($cm->id);

        // Find out current groups mode. [Copied from .../statistics/report.php lines 58 onwards]
        $currentgroup = $this->get_current_group($cm, $course, $this->context);
        $nostudentsingroup = false; // True if a group is selected and there is no one in it.
        if (empty($currentgroup)) {
            $currentgroup = 0;
            $groupstudents = array();

        } else if ($currentgroup == self::NO_GROUPS_ALLOWED) {
            $groupstudents = array();
            $nostudentsingroup = true;

        } else {
            // All users who can attempt quizzes and who are in the currently selected group.
            $groupstudents = get_users_by_capability($this->context,
                    array('mod/quiz:reviewmyattempts', 'mod/quiz:attempt'),
                    '', '', '', '', $currentgroup, '', false);
            if (!$groupstudents) {
                $nostudentsingroup = true;
            }
        }

        $qubaids = quiz_statistics_qubaids_condition($quiz->id, $currentgroup, $groupstudents, true);
        $dm = new question_engine_data_mapper();
        $this->attempts = $dm->load_attempts_at_question($this->questionid, $qubaids);

        // Setup useful internal arrays for report generation
        $question = question_bank::load_question($this->questionid);
        $this->inputs = array_keys($question->inputs);
        $this->prts = array_keys($question->prts);

        // TODO: change this to be a list of all *deployed* notes, not just those *used*.
        $qnotes = array();
        foreach ($this->attempts as $qattempt) {
            $q = $qattempt->get_question();
            $qnotes[$q->get_question_summary()] = true;
        }
        $this->qnotes = array_keys($qnotes);

        // Compute results
        list ($results, $answernote_results, $answernote_results_raw) = $this->input_report();
        list ($results_valid, $results_invalid) = $this->input_report_separate();
        // ** Display the results **

        // Overall results.
        $i=0;
        $list = '';
        $tablehead = array('');
        foreach ($this->qnotes as $qnote) {
            $list .= html_writer::tag('li', $qnote);
            $i++;
            $tablehead[] = $i;
        }
        $tablehead[] = stack_string('questionreportingtotal');
        echo html_writer::tag('ol', $list);

        // Complete anwernotes
        $inputstable = new html_table();
        $inputstable->head = $tablehead;
        foreach ($answernote_results as $anote => $a) {
            $inputstable->data[] = array_merge(array($anote), $a, array(array_sum($a)));
        }
        echo html_writer::table($inputstable);

        // Split anwernotes
        $inputstable = new html_table();
        $inputstable->head = $tablehead;
        foreach ($answernote_results_raw as $anote => $a) {
            $inputstable->data[] = array_merge(array($anote), $a, array(array_sum($a)));
        }
        echo html_writer::table($inputstable);
        

        // Results for each question note
        foreach ($this->qnotes as $qnote) {
            echo html_writer::tag('h2', $qnote);

            $inputstable = new html_table();
            $inputstable->attributes['class'] = 'generaltable stacktestsuite';
            $inputstable->head = array_merge(array(stack_string('questionreportingsummary'), '', stack_string('questionreportingscore')), $this->prts);
            foreach ($results[$qnote] as $dsummary => $summary) {
                foreach ($summary as $key => $res) {
                    $inputstable->data[] = array_merge(array($dsummary, $res['count'], $res['fraction']), $res['answernotes']);
                }
            }
            echo html_writer::table($inputstable);

            // Separate out inputs and look at validity.
            foreach ($this->inputs as $input) {
                $inputstable = new html_table();
                $inputstable->attributes['class'] = 'generaltable stacktestsuite';
                $inputstable->head = array($input, '', '');
                foreach ($results_valid[$qnote][$input] as $key => $res) {
                    $inputstable->data[] = array($key, $res, get_string('inputstatusnamevalid', 'qtype_stack'));
                    $inputstable->rowclasses[] = 'pass';
                }
                foreach ($results_invalid[$qnote][$input] as $key => $res) {
                    $inputstable->data[] = array($key, $res, get_string('inputstatusnameinvalid', 'qtype_stack'));
                    $inputstable->rowclasses[] = 'fail';
                }
                echo html_writer::table($inputstable);
            }

        }

    }

    /*
     * This function counts the number of response summaries per question note.
     */
    private function input_report() {

        // $results holds the by question note analysis
        $results = array();
        foreach ($this->qnotes as $qnote) {
            $results[$qnote] = array();
        }
        // splits up the results to look for which answernotes occur most often.
        $answernote_results = array();
        $answernote_results_raw = array();
        $answernote_empty_row = array();
        foreach ($this->qnotes as $qnote) {
            $answernote_empty_row[$qnote] = '';
        }

        foreach ($this->attempts as $qattempt) {
            $question = $qattempt->get_question();
            $qnote = $question->get_question_summary();

            for ($i = 0; $i < $qattempt->get_num_steps(); $i++) {
                $step = $qattempt->get_step($i);
                if ($data = $this->nontrivial_response_step($qattempt, $i)) {
                    $fraction = (string) $step->get_fraction();
                    $summary = $question->summarise_response($data);

                    $answernotes = array();
                    foreach ($this->prts as $prt) {
                        $prt_object = $question->get_prt_result($prt, $data, true);
                        $raw_answernotes = $prt_object->__get('answernotes');

                        foreach ($raw_answernotes as $anote) {
                            if (!array_key_exists($anote, $answernote_results_raw)) {
                                $answernote_results_raw[$anote] = $answernote_empty_row;
                            }
                            $answernote_results_raw[$anote][$qnote] += 1;
                        }

                        $answernotes[$prt] = implode(' | ', $raw_answernotes);
                        if (!array_key_exists($answernotes[$prt], $answernote_results)) {
                            $answernote_results[$answernotes[$prt]] = $answernote_empty_row;
                        }
                        $answernote_results[$answernotes[$prt]][$qnote] += 1;
                    }

                    $answernote_key = implode(' # ', $answernotes);

                    if (array_key_exists($summary, $results[$qnote])) {
                        if (array_key_exists($answernote_key, $results[$qnote][$summary])) {
                            $results[$qnote][$summary][$answernote_key]['count'] += 1;
                        } else {
                            $results[$qnote][$summary][$answernote_key]['count'] = 1;
                            $results[$qnote][$summary][$answernote_key]['answernotes'] = $answernotes;
                            $results[$qnote][$summary][$answernote_key]['fraction'] = $fraction;
                        }
                    } else {
                        $results[$qnote][$summary][$answernote_key]['count'] = 1;
                        $results[$qnote][$summary][$answernote_key]['answernotes'] = $answernotes;
                        $results[$qnote][$summary][$answernote_key]['fraction'] = $fraction;
                    }
                }
            }
        }

        return array($results, $answernote_results, $answernote_results_raw);
    }

    /*
     * Counts the number of response to each input and records their validity.
     */
    private function input_report_separate() {

        $results = array();
        $validity = array();
        foreach ($this->qnotes as $qnote) {
            foreach ($this->inputs as $input) {
                $results[$qnote][$input] = array();
            }
        }

        foreach ($this->attempts as $qattempt) {
            $question = $qattempt->get_question();
            $qnote = $question->get_question_summary();

            for ($i = 0; $i < $qattempt->get_num_steps(); $i++) {
                if ($data = $this->nontrivial_response_step($qattempt, $i)) {
                    $summary = $question->summarise_response_data($data);
                    foreach ($this->inputs as $input) {
                        if (array_key_exists($input, $summary)) {
                            if ('' != $data[$input]) {
                                if (array_key_exists($data[$input],  $results[$qnote][$input])) {
                                    $results[$qnote][$input][$data[$input]] += 1;
                                } else {
                                    $results[$qnote][$input][$data[$input]] = 1;
                                }
                            }
                            $validity[$qnote][$input][$data[$input]] = $summary[$input];
                        }
                    }
                }
            }
        }

        foreach ($this->qnotes as $qnote) {
            foreach ($this->inputs as $input) {
                arsort($results[$qnote][$input]);
            }
        }

        // Split into valid and invalid responses.
        $results_valid = array();
        $results_invalid = array();
        foreach ($this->qnotes as $qnote) {
            foreach ($this->inputs as $input) {
                $results_valid[$qnote][$input] = array();
                $results_invalid[$qnote][$input] = array();
                foreach ($results[$qnote][$input] as $key => $res) {
                    if ('valid' == $validity[$qnote][$input][$key]) {
                        $results_valid[$qnote][$input][$key] = $res;
                    } else {
                        $results_invalid[$qnote][$input][$key] = $res;
                    }
                }
            }
        }

        return array($results_valid, $results_invalid);
    }

    /*
     * From an individual attempt, we need to establish that step $i for this attempt is non-trivial, and return the non-trivial responses.
     * Otherwise we return boolean false
     */
    private function nontrivial_response_step($qattempt, $i) {
        $any_data = false;
        $rdata = array();
        $step = $qattempt->get_step($i);
        $data = $step->get_submitted_data();
        foreach ($this->inputs as $input) {
            if (array_key_exists($input, $data)) {
                $any_data = true;
                $rdata[$input] = $data[$input];
            }
        }
        if ($any_data) {
            return $rdata;
        }
        return false;
    }

}