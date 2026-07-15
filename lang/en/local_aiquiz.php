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
$string['answersperquestion'] = 'Answers per question';
$string['answersperquestion_help'] = 'How many answer options each question offers, including the incorrect ones.';
$string['count'] = 'Number of questions';
$string['difficulty'] = 'Difficulty split';
$string['difficulty_help'] = 'The percentage of easy, medium and hard questions for this question type. The three values must add up to 100.';
$string['erroranswersperquestion'] = 'At least 2 answers per question are required.';
$string['errordifficultysum'] = 'The percentages must add up to 100 (currently {$a}).';
$string['errornofiles'] = 'Select at least one source material.';
$string['errornofilesincourse'] = 'This course has no files yet. Add a File or Folder resource first.';
$string['errornoquestions'] = 'Request at least one question of this type.';
$string['errornotypes'] = 'Select at least one question type.';
$string['errortoomanyquestions'] = 'The maximum is {$a} questions per quiz.';
$string['generalheader'] = 'General';
$string['generatequiz'] = 'Generate quiz';
$string['language'] = 'Question language';
$string['languageen'] = 'English';
$string['languagero'] = 'Romanian';
$string['learningobjectives'] = 'Learning objectives';
$string['learningobjectives_help'] = 'Optional. Tells the AI service which concepts from the selected materials the questions should focus on. Leave empty to cover the materials as a whole.';
$string['materialsheader'] = 'Source materials';
$string['nomaterialsselected'] = 'No materials selected';
$string['optionsheader'] = 'Generation options';
$string['pluginname'] = 'AI Quiz generator';
$string['questionsheader'] = 'Questions';
$string['quizname'] = 'Quiz name';
$string['section'] = 'Course section';
$string['sourcefiles'] = 'Materials';
$string['sourcefiles_help'] = 'Files published in this course as File or Folder resources. Questions are generated only from the materials you select here.';
$string['typemcmulti'] = 'Multiple choice (several answers)';
$string['typemcsingle'] = 'Multiple choice (one answer)';
$string['typeshortanswer'] = 'Short answer';
$string['typetruefalse'] = 'True / false';
