Feature: Queued order records can be sent to the Klevu analytics endpoint programmatically,
    appropriately taking into consideration order status and store level configuration

    Scenario: No orders to synchronise in integrated and enabled store
        Given There are 0 queued orders in store "default"
        And Store "default" is integrated with Klevu
        And Order sync is enabled for store "default"
        And No order statuses are excluded from sync
        When I execute the ProcessOrderEvents service with no search criteria
        Then 0 requests are sent to Klevu analytics API
        And The returned status should be NOOP
        And The returned pipeline result should contain 0 items
        And The returned messages should contain 0 items

    Scenario: Orders to synchronise in unintegrated and enabled store
        Given There are 1 queued orders in store "default" with order status "processing"
        And Store "default" is not integrated with Klevu
        And Order sync is enabled for store "default"
        And No order statuses are excluded from sync
        When I execute the ProcessOrderEvents service with no search criteria
        Then 0 requests are sent to Klevu analytics API
        And The returned status should be NOOP
        And The returned pipeline result should contain 0 items
        And The returned messages should contain 0 items

    Scenario: Orders to synchronise in integrated and disabled store
        Given There are 1 queued orders in store "default" with order status "processing"
        And Store "default" is integrated with Klevu
        And Order sync is disabled for store "default"
        And No order statuses are excluded from sync
        When I execute the ProcessOrderEvents service with no search criteria
        Then 0 requests are sent to Klevu analytics API
        And The returned status should be NOOP
        And The returned pipeline result should contain 0 items
        And The returned messages should contain 0 items

    Scenario: Queued orders with excluded status in integrated and enabled store
        Given There are 1 queued orders in store "default" with order status "processing"
        And Store "default" is integrated with Klevu
        And Order sync is disabled for store "default"
        And Order statuses "processing" are excluded from sync
        When I execute the ProcessOrderEvents service with no search criteria
        Then 0 requests are sent to Klevu analytics API
        And The returned status should be NOOP
        And The returned pipeline result should contain 0 items
        And The returned messages should contain 0 items
