@local @local_messagingsupercharger
Feature: Supercharged messaging in the message drawer
  In order to communicate richly
  As a user
  I need attachments, rich text, mentions, reactions and editing in messages

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | 1        | student1@example.com |
      | student2 | Student   | 2        | student2@example.com |
      | student3 | Student   | 3        | student3@example.com |
      | outsider | Out       | Sider    | outsider@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
      | student3 | C1     | student |
    And the following "groups" exist:
      | name    | course | idnumber | enablemessaging |
      | Group 1 | C1     | G1       | 1               |
    And the following "group members" exist:
      | user     | group |
      | student1 | G1    |
      | student2 | G1    |
      | student3 | G1    |
    And the following "group messages" exist:
      | user     | group | message           |
      | student2 | G1    | Welcome everyone! |
    And the following "private messages" exist:
      | user     | contact  | message       |
      | student2 | student1 | Hello there   |
    And the following config values are set as admin:
      | messaging        | 1 |
      | messagingminpoll | 1 |
    And the following config values are set as admin:
      | pollinterval | 5 | local_messagingsupercharger |
      | emaildelay   | 0 | local_messagingsupercharger |

  @javascript
  Scenario: Open the rich text editor from the drawer and send formatted text
    Given I log in as "student1"
    And I open messaging
    And I open the "Private" conversations list
    And I select "Student 2" conversation in messaging
    And I set the field with xpath "//textarea[@data-region='send-message-txt']" to "Draft from the drawer"
    When I click on "Rich text editor" "button" in the "[data-region='message-drawer']" "css_element"
    Then I should see "Rich text editor" in the ".modal-title" "css_element"
    And I set the field "Message" to "<p><strong>Important</strong> news</p>"
    And I click on "Send" "button" in the ".modal-dialog" "css_element"
    And "//*[@data-region='message-drawer']//*[@data-region='message' and @data-message-id]//strong[text()='Important']" "xpath_element" should exist
    And I should see "news" in the "//*[@data-region='message' and contains(., 'Important')]" "xpath_element"

  @javascript
  Scenario: Drag and drop an image into the message panel
    Given I log in as "student1"
    And I open messaging
    And I open the "Private" conversations list
    And I select "Student 2" conversation in messaging
    When I drop a file named "holiday.png" on the message conversation
    Then I should see "holiday.png" in the ".msgsc-pending" "css_element"
    And I set the field with xpath "//textarea[@data-region='send-message-txt']" to "Look at this"
    And I press the enter key
    And "//*[@data-region='message-drawer']//*[@data-region='message' and @data-message-id and contains(., 'Look at this')]//img[@alt='holiday.png']" "xpath_element" should exist

  @javascript
  Scenario: Mention someone in a group conversation with the keyboard
    Given I log in as "student1"
    And I open messaging
    And I select "Group 1" conversation in messaging
    And I click on "//textarea[@data-region='send-message-txt']" "xpath_element"
    When I type "Thanks @Student 3"
    And I wait until "[role='listbox'] [role='option']" "css_element" exists
    And I press the enter key
    And I type "for the notes"
    And I click on "Send message" "button"
    Then "//*[@data-region='message-drawer']//*[@data-region='message' and @data-message-id]//a[contains(@class, 'msgsc-mention') and text()='@Student 3']" "xpath_element" should exist
    And I log out
    And I log in as "student3"
    And I open the notification popover
    And I should see "Student 1 mentioned you"

  @javascript
  Scenario: React to a message
    Given I log in as "student1"
    And I open messaging
    And I open the "Private" conversations list
    And I select "Student 2" conversation in messaging
    When I click on "Add reaction" "button" in the "//*[@data-region='message' and contains(., 'Hello there')]" "xpath_element"
    And I click on "Thumbs up" "button" in the "//*[@data-region='message' and contains(., 'Hello there')]" "xpath_element"
    Then "//*[@data-region='message' and contains(., 'Hello there')]//button[@data-reaction='thumbsup' and @aria-pressed='true']" "xpath_element" should exist
    And the "aria-label" attribute of "//*[@data-region='message' and contains(., 'Hello there')]//button[@data-reaction='thumbsup']" "xpath_element" should contain "Student 1"

  @javascript
  Scenario: Edit a message and see the edited marker
    Given I log in as "student1"
    And I open messaging
    And I open the "Private" conversations list
    And I select "Student 2" conversation in messaging
    And I send "Meeting at 3 in teh hall" message in the message area
    When I click on "Edit" "button" in the "//*[@data-region='message' and contains(., 'Meeting at 3 in teh hall')]" "xpath_element"
    And I set the field "Message" to "Meeting at 3 in the hall"
    And I click on "Save changes" "button" in the ".modal-dialog" "css_element"
    Then I should see "Meeting at 3 in the hall" in the "[data-region='message-drawer']" "css_element"
    And I should see "Edited" in the "//*[@data-region='message' and contains(., 'Meeting at 3 in the hall')]" "xpath_element"
    And I log out
    And I log in as "student2"
    And I open messaging
    And I select "Student 1" conversation in messaging
    And I should see "Meeting at 3 in the hall" in the "[data-region='message-drawer']" "css_element"
    And I should see "Edited" in the "//*[@data-region='message' and contains(., 'Meeting at 3 in the hall')]" "xpath_element"

  Scenario: Only members of the conversation can fetch an attachment
    Given the following "local_messagingsupercharger > attachment messages" exist:
      | user     | contact  | message | filename    | content        |
      | student1 | student2 | Minutes | minutes.txt | Secret minutes |
    When I log in as "student2"
    And I visit the message attachment "minutes.txt"
    Then the response should contain "Secret minutes"
    And I log out
    And I log in as "outsider"
    And I should be refused the message attachment "minutes.txt"
