@ou @ou_vle @mod @mod_oucontent
Feature: Export all
  In order to download exported content from SC across all courses
  As an admin
  I need to use the export all screen

  Background:
    # Set up documents in every course.
    Given the following "categories" exist:
      | name        | category | idnumber     |
      | Recycle bin | 0        | RECYCLEBINID |
      | Normal      | 0        | Normal       |
    Given the following "courses" exist:
      | fullname | shortname | visible | category     |
      | Course 1 | C1        | 1       | Normal       |
      | Course 2 | C2        | 1       | RECYCLEBINID |
      | Course 3 | B3        | 0       | Normal       |
      | Course 4 | C4        | 0       | RECYCLEBINID |
    And Structured Content thinks the time is "2020-01-01"
    And the following "activities" exist:
      | course   | activity  | xmlfile | idnumber |
      | C1       | oucontent | minimal | SC1      |
    And Structured Content thinks the time is "2020-02-01"
    And the following "activities" exist:
      | course   | activity  | xmlfile | idnumber |
      | C2       | oucontent | minimal | SC2      |
    And Structured Content thinks the time is "2020-03-01"
    And the following "activities" exist:
      | course   | activity  | xmlfile | idnumber |
      | B3       | oucontent | minimal | SC3      |
    And Structured Content thinks the time is "2020-04-01"
    And the following "activities" exist:
      | course   | activity  | xmlfile | idnumber |
      | C4       | oucontent | minimal | SC4      |
    And the following config values are set as admin:
      | exportalldelay | 0 | oucontent |
    And I run the scheduled task "\mod_oucontent\task\export_all"

  Scenario: Test full behaviour on a non-dataload server
    When I am on the "Admin notifications" page logged in as "admin"
    And I navigate to "Reports > Exported Structured Content" in site administration
    Then I should see "Available websites"

    # Download links should exist.
    And "C1" "link" should exist
    And "C2" "link" should exist
    And "B3" "link" should exist
    And "C4" "link" should exist

    # Links should be in alphabetical order.
    And "C1" "link" should appear after "B3" "link"
    And "C2" "link" should appear after "C1" "link"
    And "C4" "link" should appear after "C2" "link"

    # Links should show size (around 830 bytes) and date.
    And I should see "bytes" in the "C1" "link"
    And I should see "1/01/20, 00:00" in the "C1" "list_item"

    # Links should show hidden / recycle bin status.
    And I should see "Hidden from students" in the "B3" "list_item"
    And I should see "Hidden from students" in the "C4" "list_item"
    And I should see "In recycle bin" in the "C2" "list_item"
    And I should see "In recycle bin" in the "C4" "list_item"

    # Link data attributes should include computer-readable information.
    And the "data-shortname" attribute of "C1" "link" should contain "C1"
    And the "data-lastpublished" attribute of "C1" "link" should contain "2020-01-01T00:00:00+08:00"
    And the "data-sizebytes" attribute of "C1" "link" should contain "8"
    And the "data-hidden" attribute of "C4" "link" should contain "true"
    And the "data-inrecyclebin" attribute of "C4" "link" should contain "true"

    # Downloading the link should work.
    And following "C1" should download between "800" and "900" bytes

  Scenario: Test different page organisation on a dataload server
    Given the local OU dataload course table contains:
      | vle_course_short_name | course_code | pres_code | pres_code_5 | full_course_title | resit_only | vle_control_course | vle_course_page_in_stud_home | pres_start_date | pres_finish_date |
      | C1                    | F100        | 19B       | 2019B       | Learning to fail  | N          | Y                  | Y                            | 2019-02-02      | 2019-12-07       |
      | C1                    | FZX100      | 19B       | 2019B       | Learning to fail  | N          | N                  | Y                            | 2019-02-02      | 2019-12-07       |
      | C2                    | F101        | 19B       | 2019B       | Learning to fail  | N          | Y                  | Y                            | 2019-02-02      | 2019-12-07       |
    And the local OU dataload award table contains:
      | academic_award_code | academic_award_desc | vle_award_short_name |
      | Q01                 | BSc in frog studies | B3                   |
    When I am on the "Admin notifications" page logged in as "admin"
    And I navigate to "Reports > Exported Structured Content" in site administration

    # Check section layout and contents.
    Then I should not see "Available websites"
    And I should see "Module websites"
    And I should see "C1" in the "#oucontent-modules" "css_element"
    And I should see "C2" in the "#oucontent-modules" "css_element"
    And I should see "Subject websites"
    And I should see "B3" in the "#oucontent-subjects" "css_element"
    And I should see "Other websites"
    And I should see "C4" in the "#oucontent-other" "css_element"

    # Check modules and awards are displayed in their respective boxes.
    And I should see "F100-19B" in the "C1" "list_item"
    And I should see "FZX100-19B" in the "C1" "list_item"
    And I should see "F101-19B" in the "C2" "list_item"
    And I should see "Q01" in the "B3" "list_item"
