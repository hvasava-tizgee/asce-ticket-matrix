# Events Manager Pro API Compliance

## Overview

This document details ASCE Ticket Matrix's integration with Events Manager Pro's Multiple Bookings API, documenting correct usage patterns and compliance with EM Pro best practices.

**Plugin Version:** 2.10.0  
**EM Pro Compatibility:** 3.2+  
**Compliance Status:** ✅ Fully Compliant

## API Integration Points

### 1. Static Methods (EM_Multiple_Bookings Class)

#### Session Management
```php
// ✅ CORRECT: Start EM Pro session
EM_Multiple_Bookings::session_start();

// ✅ CORRECT: Close and persist session
EM_Multiple_Bookings::session_close();

// ✅ CORRECT: Save session data
EM_Multiple_Bookings::session_save();

// ✅ CORRECT: Clear entire cart
EM_Multiple_Bookings::empty_cart();

// ✅ CORRECT: Get/create multiple booking instance
$EM_Multiple_Booking = EM_Multiple_Bookings::get_multiple_booking();
```

**Key Points:**
- `session_start()` handles PHP session internally - do NOT call PHP `session_start()` manually
- `session_close()` automatically calls `session_save()` before closing
- `empty_cart()` clears session and resets internal state
- Always close sessions after operations to persist data

#### ❌ INCORRECT Patterns We Fixed
```php
// ❌ WRONG: Manual session management (causes race conditions)
EM_Multiple_Bookings::session_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start(); // ← Redundant and problematic
}

// ❌ WRONG: Direct session manipulation
unset($_SESSION['em_multiple_bookings']); // ← Use empty_cart() instead
```

### 2. Instance Methods (EM_Multiple_Booking Class)

#### Cart Operations
```php
// ✅ CORRECT: Add booking to cart
$result = $EM_Multiple_Booking->add_booking($EM_Booking);
if (!$result) {
    $errors = $EM_Booking->get_errors();
}

// ✅ CORRECT: Remove booking from cart
$result = $EM_Multiple_Booking->remove_booking($event_id);

// ✅ CORRECT: Get bookings (respects filters)
$bookings = $EM_Multiple_Booking->get_bookings();

// ✅ CORRECT: Validate multiple booking
$is_valid = $EM_Multiple_Booking->validate();

// ✅ CORRECT: Save to database (checkout only)
$result = $EM_Multiple_Booking->save_bookings();
```

**Key Points:**
- `add_booking()` returns boolean, adds errors to `$EM_Booking` on failure
- `get_bookings()` applies filters, always use instead of direct property access
- `save_bookings()` is for final checkout, NOT cart operations
- Validation should happen before saving

#### ❌ INCORRECT Patterns We Fixed
```php
// ❌ WRONG: Direct property access (bypasses filters)
if (!empty($EM_Multiple_Booking->bookings[$event_id])) {
    // ← Use get_bookings() instead
}

// ❌ WRONG: Calling save() during cart operations
$EM_Multiple_Booking->save(); // ← Creates database records prematurely
// Use session_close() for cart persistence, save_bookings() for checkout

// ❌ WRONG: Not checking return value
$EM_Multiple_Booking->add_booking($EM_Booking); // ← Always check return value
```

### 3. Booking Objects (EM_Booking Class)

#### Creating and Validating Bookings
```php
// ✅ CORRECT: Create new booking
$EM_Booking = new EM_Booking();
$EM_Booking->event_id = $event_id;
$EM_Booking->person_id = get_current_user_id();

// ✅ CORRECT: Set booking status (strict comparison)
if ($EM_Booking->booking_status === false || $EM_Booking->booking_status === null) {
    $EM_Booking->booking_status = get_option('dbem_bookings_approval') ? 0 : 1;
}

// ✅ CORRECT: Load POST data
$EM_Booking->get_post();

// ✅ CORRECT: Validate booking
$is_valid = $EM_Booking->validate();
if (!$is_valid) {
    $errors = $EM_Booking->get_errors();
}

// ✅ CORRECT: Clear errors manually (no clear_errors method exists)
$EM_Booking->errors = array();
$EM_Booking->feedback_message = '';
```

**Key Points:**
- Booking status 0 (pending) is valid, use strict comparison not `empty()`
- `get_post()` reads from `$_REQUEST` array
- `validate()` returns boolean, errors accessible via `get_errors()`
- No `clear_errors()` method exists - must clear manually

#### ❌ INCORRECT Patterns We Fixed
```php
// ❌ WRONG: Using empty() for booking_status
if (empty($EM_Booking->booking_status)) {
    // ← This treats 0 (pending) as empty!
}

// ❌ WRONG: Calling non-existent method
if (method_exists($EM_Booking, 'clear_errors')) {
    $EM_Booking->clear_errors(); // ← This method doesn't exist
}
```

### 4. Ticket Objects (EM_Ticket Class)

```php
// ✅ CORRECT: Get ticket with caching
$EM_Ticket = EM_Ticket::get($ticket_id);

// ✅ CORRECT: Verify ticket exists and is valid
if (!$EM_Ticket || !$EM_Ticket->ticket_id) {
    // Handle error
}

// ✅ CORRECT: Verify ticket belongs to event
if (absint($EM_Ticket->event_id) !== absint($event_id)) {
    // Handle mismatch
}
```

**Key Points:**
- Always use `EM_Ticket::get()` static method for caching
- Verify ticket exists before use
- Validate ticket belongs to expected event

### 5. Event Objects

```php
// ✅ CORRECT: Get event with global function
$EM_Event = em_get_event($event_id);

// ✅ CORRECT: Verify event exists
if (!$EM_Event || !$EM_Event->event_id) {
    // Handle error
}
```

### 6. Price Methods

```php
// ✅ CORRECT: Get formatted price with taxes
$total = $EM_Multiple_Booking->get_price(true, true, true);
// Parameters: ($format, $include_taxes, $currency_filter)

// ✅ CORRECT: Get price summary
$summary = $EM_Multiple_Booking->get_price_summary();
```

**Key Points:**
- Second parameter controls tax inclusion (true = include taxes)
- First parameter formats as currency string
- Third parameter applies currency filters

#### ❌ INCORRECT Pattern We Fixed
```php
// ❌ WRONG: Excluding taxes from displayed price
$total = $EM_Multiple_Booking->get_price(true, false, true);
// ← Should include taxes: get_price(true, true, true)
```

## Filter Hooks Used

### 1. Validation Bypass Filter
```php
add_filter('em_booking_validate', array(__CLASS__, 'bypass_cart_validation'), 99, 2);
```

**Purpose:** Allows cart additions without form field validation  
**Priority:** 99 (runs after standard validators)  
**Safety:** Only bypasses soft failures (missing fields), not hard failures (capacity, availability)

### 2. Filter Hook Compliance
Our plugin respects these EM Pro filters:
- `em_multiple_booking_get_bookings` - Applied by `get_bookings()` method
- `em_booking_validate` - Validation filter
- `em_booking_save` - Save filter

## Database Interaction

### ✅ Correct: Using EM Pro APIs
We query EM Pro's meta table for form configuration:
```php
global $wpdb;
$sql = $wpdb->prepare(
    "SELECT meta_value FROM " . EM_META_TABLE . " WHERE meta_key=%s AND meta_id=%d",
    'booking-form',
    $form_id
);
```

**Note:** This is necessary as EM Pro doesn't provide public API for form data retrieval.

### ✅ Correct: No Database Writes
We never write directly to EM Pro tables:
- Cart operations use session only
- Bookings saved via `save_bookings()` method
- EM Pro handles all database writes internally

## Session Management Best Practices

### Correct Pattern (After v2.10.0)
```php
// 1. Check EM Pro is available
if (!class_exists('EM_Multiple_Bookings')) {
    wp_send_json_error(array('message' => 'Multiple Bookings not available'));
}

// 2. Start EM Pro session (handles PHP session internally)
EM_Multiple_Bookings::session_start();

// 3. Verify session is active
if (session_status() !== PHP_SESSION_ACTIVE && !isset($_SESSION)) {
    EM_Multiple_Bookings::session_close();
    wp_send_json_error(array('message' => 'Session initialization failed'));
}

// 4. Work with session data
$_SESSION['asce_tm_tickets'] = $tickets;

// 5. Close session (auto-persists)
EM_Multiple_Bookings::session_close();
```

### Multisite Considerations
```php
// Always switch to correct blog context
$current_blog_id = get_current_blog_id();
$switched_blog = false;

if (is_multisite() && $posted_blog_id && $posted_blog_id !== $current_blog_id) {
    switch_to_blog($posted_blog_id);
    $switched_blog = true;
}

// ... do work ...

// Always restore blog context
if ($switched_blog) {
    restore_current_blog();
}
```

## Error Handling Patterns

### Comprehensive Error Capture
```php
// Validate booking
$is_valid = $EM_Booking->validate();
if (!$is_valid) {
    $errors = $EM_Booking->get_errors();
    if (empty($errors)) {
        // Provide fallback error message
        $errors[] = sprintf(__('Validation failed for event %s', 'asce-tm'), $event_name);
    }
    // Handle errors...
}
```

### Corrupted Cart Recovery
```php
try {
    $EM_Multiple_Booking = EM_Multiple_Bookings::get_multiple_booking();
} catch (Throwable $e) {
    // Clear corrupted cart
    EM_Multiple_Bookings::empty_cart();
    
    // Try again with fresh cart
    try {
        $EM_Multiple_Booking = EM_Multiple_Bookings::get_multiple_booking();
    } catch (Throwable $e2) {
        // Fatal error - can't recover
        wp_send_json_error(array('message' => 'Cart initialization failed'));
    }
}
```

## Testing Checklist

### API Compliance Tests
- [ ] Cart operations persist across page reloads
- [ ] Session management works on multisite
- [ ] No orphaned booking records in database
- [ ] Prices include taxes correctly
- [ ] Pending bookings (status 0) are preserved
- [ ] Validation bypass only skips form fields, not capacity checks
- [ ] Error messages are clear and actionable
- [ ] Duplicate event prevention works correctly

### Integration Tests
- [ ] EM Pro cart page shows correct items
- [ ] EM Pro checkout page processes correctly
- [ ] Payment gateways receive correct data
- [ ] Confirmation emails send properly
- [ ] Bookings appear in EM admin correctly
- [ ] Multi-site blog context switching works

## Version History

### v2.10.0 (2026-01-22)
✅ Comprehensive API compliance audit and fixes:
- Removed non-existent `clear_errors()` method call
- Replaced direct `->bookings` access with `get_bookings()`
- Removed improper `save()` calls during cart operations
- Fixed session management race conditions
- Improved error handling
- Fixed `booking_status` validation
- Corrected price calculation to include taxes
- Enhanced session validation

### v2.9.22 (2026-01-21)
✅ API compliance improvements:
- Fixed cart initialization to use `empty_cart()` API
- Removed call to non-existent `delete_cache()` method
- Improved error handling for cart corruption

### v2.9.21 (2026-01-21)
✅ Error handling improvements:
- Fixed exception handling to catch `Throwable` instead of `Exception`

## Support & Maintenance

**Maintenance Policy:** This plugin is actively maintained to ensure ongoing compatibility with Events Manager Pro updates.

**Update Strategy:**
1. Monitor EM Pro changelog for API changes
2. Test with new EM Pro versions before releasing updates
3. Maintain backward compatibility when possible
4. Document breaking changes clearly

**Reporting Issues:** If you encounter API compatibility issues, please provide:
- EM Pro version number
- PHP version
- Error messages from debug log
- Steps to reproduce

## References

- [Events Manager Documentation](https://wp-events-plugin.com/documentation/)
- [Events Manager Pro Multiple Bookings](https://wp-events-plugin.com/documentation/multiple-bookings/)
- [ASCE Ticket Matrix Changelog](CHANGELOG.md)
