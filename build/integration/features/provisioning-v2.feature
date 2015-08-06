Feature: provisioning
  Background:
    Given using api version "2"

  Scenario: Getting an not existing user
    Given As an "admin"
    When sending "GET" to "/cloud/users/test"
    Then the status code should be "404"

