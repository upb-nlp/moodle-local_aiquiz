@local @local_aiquiz
Feature: Reaching the AI quiz generator
  In order to build quizzes from my course materials
  As a teacher
  I need the generator to be available to me and hidden from my students

  Background:
    Given the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  Scenario: A teacher sees an empty dashboard before generating anything
    When I am on the "C1" "local_aiquiz > Dashboard" page logged in as "teacher1"
    Then I should see "AI Quiz generator"
    And I should see "No quizzes have been generated in this course yet"
    And I should see "Generate new quiz"

  Scenario: A teacher reaches the generation form from the dashboard
    Given I am on the "C1" "local_aiquiz > Dashboard" page logged in as "teacher1"
    When I click on "Generate new quiz" "button"
    Then I should see "Source materials"
    And I should see "Questions"
    And I should see "Generation options"

  Scenario: The form explains that the course has no materials to generate from
    When I am on the "C1" "local_aiquiz > Generate" page logged in as "teacher1"
    Then I should see "This course has no files yet"
    And I should not see "No materials selected"

  Scenario: A student is not offered the generator in the course
    When I am on the "C1" "course" page logged in as "student1"
    Then I should not see "AI Quiz generator"
