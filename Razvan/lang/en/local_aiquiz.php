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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Strings for the AI quiz generator plugin.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aiquiz:generate'] = 'Generate quizzes with AI';
$string['allowlocalapi'] = 'Allow a local API address';
$string['allowlocalapi_desc'] = 'Development only. Moodle blocks outgoing requests to localhost and private IP addresses to prevent server side request forgery. Enable this to call an API running on the same machine or network. Leave disabled in production.';
$string['answersperquestion'] = 'Answers per question';
$string['answersperquestion_help'] = 'How many answer options each question offers, including the incorrect ones.';
$string['apiendpoint'] = 'API base URL';
$string['apiendpoint_desc'] = 'Base address of the generation service, without a trailing slash and without any API path: the plugin appends those itself. For example https://chat.readerbench.com/moodle, not https://chat.readerbench.com/moodle/question-bank/generation-jobs.';
$string['authtoken'] = 'Authentication token';
$string['authtoken_desc'] = 'The token sent with every request to the generation service. Issued by the service, not by Moodle.';
$string['confirmdeletequiz'] = 'Delete "{$a}" and its generation record? The quiz activity and any questions in it will be removed. This cannot be undone.';
$string['count'] = 'Number of questions';
$string['createdby'] = 'Created by';
$string['difficulty'] = 'Difficulty split';
$string['difficulty_help'] = 'The percentage of easy, medium and hard questions for this question type. The three values must add up to 100.';
$string['erroranswersperquestion'] = 'At least 2 answers per question are required.';
$string['errordifficultysum'] = 'The percentages must add up to 100 (currently {$a}).';
$string['errorjobnotdone'] = 'This quiz has no generated questions yet, so it cannot be published.';
$string['errornoendpoint'] = 'The generation service API base URL is not configured. Set it under Site administration > Plugins > Local plugins > AI Quiz generator.';
$string['errornofiles'] = 'Select at least one source material.';
$string['errornofilesincourse'] = 'This course has no files yet. Add a File or Folder resource first.';
$string['errornoquestions'] = 'Request at least one question of this type.';
$string['errornotypes'] = 'Select at least one question type.';
$string['errorsendfailed'] = 'Could not reach the generation service: {$a}';
$string['errorservicestatus'] = 'The generation service returned HTTP {$a->code}: {$a->body}';
$string['errortoomanyquestions'] = 'The maximum is {$a} questions per quiz.';
$string['generalheader'] = 'General';
$string['generatenewquiz'] = 'Generate new quiz';
$string['generatequiz'] = 'Generate quiz';
$string['generationstarted'] = 'The quiz was created as a hidden draft and question generation has started. It stays invisible to students until you review and publish it.';
$string['language'] = 'Question language';
$string['languageen'] = 'English';
$string['languagero'] = 'Romanian';
$string['learningobjectives'] = 'Learning objectives';
$string['learningobjectives_help'] = 'Optional. Tells the AI service which concepts from the selected materials the questions should focus on. Leave empty to cover the materials as a whole.';
$string['materialsheader'] = 'Source materials';
$string['nomaterialsselected'] = 'No materials selected';
$string['noquizzesyet'] = 'No quizzes have been generated in this course yet. Use the button above to create the first one.';
$string['optionsheader'] = 'Generation options';
$string['pluginname'] = 'AI Quiz generator';
$string['publish'] = 'Publish';
$string['questions'] = 'Questions';
$string['questionsheader'] = 'Questions';
$string['quizdeleted'] = 'The quiz and its generation record were deleted.';
$string['quizname'] = 'Quiz name';
$string['quizpublished'] = 'The quiz is now visible to students.';
$string['quizunpublished'] = 'The quiz is now hidden from students again.';
$string['review'] = 'Review';
$string['section'] = 'Course section';
$string['statefailed'] = 'Failed';
$string['stategenerating'] = 'Generating';
$string['statepublished'] = 'Published';
$string['stateready'] = 'Ready to review';
$string['sourcefiles'] = 'Materials';
$string['taskgenerate'] = 'Send AI quiz generation request';
$string['sourcefiles_help'] = 'Files published in this course as File or Folder resources. Questions are generated only from the materials you select here.';
$string['typemcmulti'] = 'Multiple choice (several answers)';
$string['typemcsingle'] = 'Multiple choice (one answer)';
$string['typeshortanswer'] = 'Short answer';
$string['typetruefalse'] = 'True / false';
$string['unpublish'] = 'Unpublish';
