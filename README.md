# ASCE Ticket Matrix Plugin

A WordPress plugin that creates flexible ticket matrix tables for Events Manager, allowing users to select tickets from multiple events in a single view and add them to the cart.

## Features

- **Multiple Table Management**: Create unlimited ticket matrices, each with its own configuration
- **Flexible Matrix Layout**: Configure any number of events (rows) and columns per table
- **Custom Column Names**: Define your own column labels (pricing tiers, ticket types, etc.)
- **Per-Cell Ticket Mapping**: Assign specific tickets to each event/column combination
- **Smart Input Types**: Automatically displays checkboxes for single-ticket events, quantity inputs for multi-ticket events
- **Mutually Exclusive Events**: Define exclusive groups to prevent conflicting event selections
- **Booking Limit Enforcement**: Respects Events Manager booking limits on frontend
- **Table Management**: Duplicate existing tables and archive old configurations
- **Export/Import**: Export table configurations as JSON and import them later
- **Shopping Cart Integration**: Seamlessly integrates with Events Manager's Multiple Bookings Mode
- **Responsive Design**: Mobile-friendly table layout that stacks on smaller screens
- **Real-time Calculations**: Live total and item count updates
- **Performance Optimized**: Event caching and query optimization for fast page loads

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- **Events Manager** plugin (free version)
- **Events Manager Pro** plugin with Multiple Bookings Mode enabled

### Events Manager Pro Compatibility

**Version:** 5.0.8  
**Tested with:** Events Manager Pro 3.2+  
**API Compliance:** Fully compliant with EM Pro Multiple Bookings API

This plugin follows EM Pro's official API patterns and best practices:
- Uses documented public methods only
- Respects EM Pro filter hooks and action hooks
- Compatible with EM Pro session management
- Does not modify EM Pro core files

**Hybrid Mode:** This plugin works independently of the global Multiple Bookings Mode setting. Regular events can use single-event booking while the ticket matrix forces cart/checkout functionality as needed.

## Installation

1. **Upload Plugin Files**
   - Download or clone this plugin
   - Upload the `asce-ticket-matrix` folder to `/wp-content/plugins/`

2. **Activate Plugin**
   - Go to WordPress Admin → Plugins
   - Find "ASCE Ticket Matrix" and click Activate

3. **Enable Multiple Bookings Mode**
   - Go to Events → Settings → Bookings
   - Scroll to "Multiple Bookings Mode"
   - Enable "Multiple Bookings Mode?"
   - Set up Cart Page and Checkout Page
   - Save settings

## Quick Start

### Step 1: Create a New Table

1. Go to **Events → Ticket Matrix Tables** in the WordPress admin menu
2. Click **Add New Table**
3. Enter a **Table Name** (e.g., "Spring 2024 Workshops")
4. Set **Number of Events** (rows in your matrix)
5. Set **Number of Columns** (e.g., Early Bird, Regular, VIP)
6. Click **Create Table**

### Step 2: Configure Your Matrix

1. **Name Your Columns**: Enter descriptive names for each column (e.g., "Early Bird", "Regular Price")
2. **Select Events**: For each row, select an event and optionally override its display label
3. **Map Tickets**: For each event/column cell, select which ticket from that event should be used
4. **(Optional) Set Exclusive Groups**: Assign group names to events that conflict (same time slot)
5. Click **Save Table Configuration**

### Step 3: Add to a Page

1. Copy the shortcode shown at the top of the table editor (e.g., `[asce_ticket_matrix id="table_123"]`)
2. Create or edit a WordPress page
3. Paste the shortcode
4. Publish the page

## Configuration Details

### Matrix Setup

**Number of Events (Rows)**: Choose how many events will be displayed vertically. Each event occupies one row in the matrix.

**Number of Columns**: Define pricing tiers, ticket types, or any categorization. Examples:
- 2 columns: "Early Bird" and "Regular"
- 3 columns: "Member", "Non-Member", "Student"
- 4 columns: "Bronze", "Silver", "Gold", "Platinum"

### Event Configuration

For each event row:
- **Select Event**: Choose from upcoming Events Manager events
- **Custom Label** (optional): Override the event name in the display
- **Exclusive Group** (optional): Enter a group name (e.g., "morning-session") to make this event mutually exclusive with other events in the same group

### Ticket Mapping

Each cell in the configuration represents an event/column intersection:
- Select which ticket from that event should be offered in that column
- Leave blank if a ticket type isn't available for that event
- The ticket price is automatically displayed on the frontend

### Exclusive Groups

Events with the same exclusive group name are mutually exclusive - selecting a ticket from one automatically clears tickets from the others. Use this for:
- Time conflicts (morning vs afternoon sessions)
- Venue capacity limits
- Prerequisite requirements

Example: Mark all morning workshops with group "morning" and afternoon workshops with "afternoon" to prevent double-booking.

## Table Management

### Duplicate a Table

Click the **Duplicate** button to create a copy of an existing table. Useful for:
- Creating variations for different audiences
- Reusing structure for new event series
- A/B testing different configurations

### Archive Tables

Use **Archive** to hide old tables from the main list without deleting them:
- Archived tables won't appear in the active list
- Shortcodes still work (for historical pages)
- Click **Show Archived** to view and restore archived tables

### Export/Import Tables

**Export a table:**
- Click the **Export** button next to any table
- Downloads a JSON file with the complete configuration
- Use for backups, version control, or moving between sites

**Import a table:**
- Click the **📥 Import Table** button at the top
- Select a previously exported JSON file
- Enter a name for the imported table
- All events, columns, and ticket mappings are restored

**Use cases:**
- Backup before making changes
- Transfer configurations between development and production
- Share table setups with other team members
- Version control your event matrices

## Frontend Behavior

### Smart Input Types

The plugin automatically chooses the best input type:
- **Checkboxes**: For events with booking limit = 1 (single attendee)
- **Quantity Inputs**: For events allowing multiple bookings

### Booking Limits

The frontend enforces Events Manager booking limits:
- Maximum quantity is capped at the event's booking limit
- Quantity inputs show the max allowed
- Users cannot exceed limits even when using multiple tables

### Exclusive Groups

When a user selects a ticket from an event in an exclusive group:
- All other selections in that group are automatically cleared
- Visual feedback shows the clearing action
- Prevents accidental double-booking of conflicting events

### Cart Integration

Clicking "Add to Cart":
1. Groups all selected tickets by event
2. Creates/updates bookings for each event
3. Adds to Events Manager cart
4. Redirects to cart page

## Advanced Usage

### Multiple Tables on One Page

You can display multiple tables on the same page:

```
[asce_ticket_matrix id="table_123"]
[asce_ticket_matrix id="table_456"]
```

Each table maintains its own state and cart functionality.

### Custom Styling

Override styles in your theme's CSS:

```css
/* Change primary color */
.asce-tm-container {
    --primary-color: #your-color;
}

/* Style the matrix cells */
.asce-tm-matrix th,
.asce-tm-matrix td {
    padding: 15px;
    font-size: 16px;
}
```

## Performance Notes

The plugin includes several optimizations:
- **Event Caching**: Future events cached for 5 minutes
- **Pre-loading**: All event objects loaded once per table render
- **Static Caching**: Booking limits cached during page generation
- **Query Limits**: Only fetches next 50 upcoming events

For large sites with many events, consider:
- Using specific event categories in Events Manager
- Archiving old tables regularly
- Implementing object caching (Redis, Memcached)

## Troubleshooting

### "No events found"
- Ensure you have upcoming events in Events Manager
- Check that events are published and have start dates in the future
- Verify Events Manager is active

### Tickets not appearing in dropdowns
- Each event must have tickets created in Events Manager
- Tickets must be published and available for booking
- Check ticket start/end dates

### Cart not working
- Verify Multiple Bookings Mode is enabled in Events → Settings
- Ensure cart and checkout pages are configured
- Check Events Manager Pro is active

### Exclusive groups not clearing selections
- Clear browser cache
- Check browser console for JavaScript errors
- Verify group names match exactly (case-sensitive)

### Slow performance
- Check event cache is working (look for 5-minute consistency)
- Reduce number of future events in Events Manager
- Enable WordPress object caching

## Support

For issues specific to:
- **Events Manager functionality**: Contact Events Manager support
- **This plugin**: Check GitHub repository or contact plugin author

## File Structure

```
asce-ticket-matrix/
├── asce-ticket-matrix.php          # Main plugin file
├── includes/
│   ├── class-asce-tm-settings.php  # Admin settings page
│   ├── class-asce-tm-matrix.php    # Frontend matrix display
│   └── class-asce-tm-ajax.php      # AJAX handlers
├── assets/
│   ├── css/
│   │   └── ticket-matrix.css       # Plugin styles
│   └── js/
│       └── ticket-matrix.js        # Plugin JavaScript
└── Documentation files:
    ├── README.md                    # This file
    ├── ARCHITECTURE.md              # Technical architecture
    ├── TABLE-SETUP-GUIDE.md         # Setup instructions
    ├── USAGE-EXAMPLES.md            # Real-world examples
    ├── UPDATE-SUMMARY.md            # Version history
    ├── IMPLEMENTATION-CHECKLIST.md  # Deployment checklist
    ├── PERFORMANCE-OPTIMIZATION-SUMMARY.md
    ├── PERFORMANCE-TROUBLESHOOTING.md
    └── DISPLAY-MODES.md
```

## Credits

- **Plugin Author**: Rune Storesund
- **Version**: 2.6.3
- **Integrates with**: Events Manager by Marcus Sykes

## Changelog

### Version 2.6.3 (January 17, 2026)
- **Patch**: Fixed missing $root parameter in form save AJAX error callback
- Ensures error messages display in correct table instance on multi-table pages
- Completes multi-table status messaging scoping (line 1329)

### Version 2.6.2 (January 17, 2026)
- **Surgical Multi-Table Fix**: Scoped stepper navigation and forms to specific table instances
- All button handlers now find and pass `$root` wrapper to scoped functions
- `goToStep()`, `goToFormsStep()`, `renderForms()`, and `saveFormsAndContinue()` now accept `$root` parameter
- Status messages, panel switching, and form validation all scoped to specific instance
- Eliminated all global selectors for stepper/panels (replaced with `$root.find()`)
- Multiple tables on same page can independently navigate steps without interference

### Version 2.6.1 (January 17, 2026)
- **Surgical Fix**: Improved per-table stepper visibility scoping
- Added `.asce-tm-instance` wrapper div around each shortcode output
- Enhanced JS to use `this.$root` for proper stepper scope per table
- Ensures multiple tables on same page work independently without interference

### Version 2.6.0 (January 17, 2026)
- **UI Enhancement**: Stepper hidden until user selects tickets (per table instance)
- **Forms Access**: Step B Forms AJAX now works for non-logged-in users
- **Security**: Debug Cart button now only visible to administrators
- Multi-table support: Each stepper shows/hides independently
- Added `updateStepperVisibility()` method for per-container visibility control
- Removed login checks from forms AJAX endpoints (nonce validation remains)
- Added `isAdmin` flag to localized script for admin-only features

### Version 2.4.8 (January 14, 2026)
- **Multisite EM Compatibility**: Fixed booking creation in multisite environments
- Properly populate both $_REQUEST and $_POST with event_id and em_tickets before EM_Booking->get_post()
- Save and restore previous $_REQUEST/$_POST values to avoid side effects between event loop iterations
- Return meaningful validation errors from AJAX handler when booking fails
- **SQL Query Hardening**: Use EM table constants for multisite global table support
- Replaced hardcoded table names with EM_TICKETS_BOOKINGS_TABLE and EM_BOOKINGS_TABLE
- Implemented proper prepared statements with placeholders instead of string concatenation
- Added guard against empty ticket arrays to prevent SQL errors

### Version 2.4.7 (January 14, 2026)
- **Critical Fixes**: Fixed two blocking SQL and validation errors
- Fixed undefined `$ticket_ids_str` causing invalid SQL "IN ()" clause
- Added guard to skip booked/reserved queries when ticket array is empty
- Fixed Events Manager compatibility by setting `$_REQUEST['event_id']` before `get_post()`
- EM internal methods now receive event_id correctly in $_REQUEST
- Prevents SQL errors and booking validation failures

### Version 2.4.6 (January 14, 2026)
- **Code Formatting**: Reformatted wp_add_inline_script call for better readability
- Multi-line format makes the jQuery shim code easier to audit and maintain
- No functional changes from 2.4.5

### Version 2.4.5 (January 14, 2026)
- **Hardened jQuery Alias**: Made $ alias conditional to avoid clobbering other libraries
- Now checks `if (typeof window.$ === 'undefined')` before setting alias
- Moved jQuery shim from global hook to ASCE_TM_Matrix::enqueue_assets()
- Only runs when matrix assets are loaded, not on every page
- Better compatibility with sites using multiple JavaScript libraries

### Version 2.4.4 (January 14, 2026)
- **jQuery Compatibility**: Added global $ alias to prevent "$ is not a function" errors
- Injects `window.$ = window.jQuery;` after jquery-core loads
- Lightweight compatibility shim for themes that don't properly set up jQuery
- Prevents JavaScript errors that can break matrix and cart interactions

### Version 2.4.3 (January 14, 2026)
- **Fixed Missing data-table-id**: Container now correctly outputs data-table-id attribute with shortcode ID
- table_id is added to table array in render_shortcode() and extracted in render_table()
- Ensures AJAX requests include correct table_id for exclusive-group validation
- Prevents JavaScript errors and enables proper frontend functionality

### Version 2.4.2 (January 14, 2026)
- **Early Validation**: add_to_cart() now validates table_id is present immediately after nonce check
- Fails fast with clear error message if table_id is missing
- Prevents exclusive-group mapping regressions and silent failures
- table_id validated once and reused throughout function (no redundant reads)

### Version 2.4.1 (January 14, 2026)
- **Production Hardening**: debug_blog_id in AJAX success response now only included when WP_DEBUG is enabled
- Prevents leaking internal blog IDs in production multisite environments
- Debug information still available for development/troubleshooting when needed

### Version 2.4.0 (January 14, 2026)
- **Multisite Blog Context Enforcement**: Explicitly passes and validates blog_id in AJAX requests
- Frontend sends current blog_id, backend switches context if needed using switch_to_blog/restore_current_blog
- Ensures all database queries and session operations run in correct blog context
- **Cart/Checkout Cache Prevention**: Added template_redirect hook to disable all caching on cart and checkout pages
- Sets DONOTCACHEPAGE, DONOTCACHEOBJECT, DONOTCACHEDB constants and calls nocache_headers()
- **Persistence Hardening**: Calls $EM_Multiple_Booking->save() before session_close() if method exists
- All error exit paths properly restore blog context in multisite environments
- Critical for reliable cart functionality in WordPress multisite installations

### Version 2.3.2 (January 14, 2026)
- **Multisite Fix**: Changed ajaxUrl to absolute URL for proper subsite context in WordPress multisite
- Now uses admin_url('admin-ajax.php') instead of relative path to ensure AJAX requests go to correct blog (e.g., /cais/wp-admin/admin-ajax.php)
- Prevents empty cart issues caused by AJAX requests hitting wrong blog context in multisite installations
- Added debug_blog_id to success response for multisite debugging

### Version 2.3.1 (January 14, 2026)
- **Fixed False Session Failures**: Removed reliance on EM_Multiple_Bookings::session_start() return value
- Now validates session success using only session_status() === PHP_SESSION_ACTIVE
- Prevents false "session failed" errors when session_start() returns void/null but actually succeeds
- Removed session_started from debug output (not reliable)

### Version 2.3.0 (January 14, 2026)
- **Production Hardening**: Session cookie parameters only set when session inactive and headers not sent
- Added @ suppression to session_set_cookie_params to prevent edge-case warnings corrupting JSON
- Debug header X-ASCE-TM-SESSION now only sent when WP_DEBUG is enabled
- Debug array in error responses now only included when WP_DEBUG is enabled
- Changed stripslashes to wp_unslash for tickets JSON decode (WordPress best practice)
- Prevents session ID leakage in production environments

### Version 2.2.9 (January 14, 2026)
- **Session Diagnostics**: Added hard fail check if PHP session cannot start properly
- Set session cookie parameters before session_start() with path='/', secure (SSL), httponly, samesite=Lax
- Added X-ASCE-TM-SESSION debug header to track session name and ID
- Provides clear error message if session_status() != PHP_SESSION_ACTIVE
- Helps diagnose empty cart issues caused by missing session cookies

### Version 2.2.8 (January 13, 2026)
- **AJAX Response URLs**: Cart and checkout URLs in AJAX success response now use relative links
- Matches localized cartPage/checkoutPage format for consistency
- Prevents session/cookie issues from absolute URL mismatches in AJAX redirects
- Both frontend config and AJAX response now use wp_make_link_relative()

### Version 2.2.7 (January 13, 2026)
- **Host-Safe URLs**: Cart and checkout URLs now use relative links to avoid www/non-www or scheme mismatches
- Uses wp_make_link_relative() for cartPage and checkoutPage in localized script
- Prevents cookie and session issues caused by absolute URL redirects
- Adds validation to check page IDs exist before generating permalinks

### Version 2.2.6 (January 13, 2026)
- **Critical Fix**: Fixed admin-ajax 403 "-1" nonce verification failure
- Changed nonce action from 'asce_tm_nonce' to 'asce_tm_add_to_cart' for specificity
- Updated AJAX URL to use admin_url('admin-ajax.php', 'relative') for consistency
- Ensures nonce generation and verification use matching action names
- Resolves AJAX cart addition failures after cache purge or hard refresh

### Version 2.2.5 (January 13, 2026)
- **Hardened Validation**: Fixed "one ticket type per event" uniqueness check to use associative array
- Changed $unique_ticket_ids from numeric array to set-based structure ($unique_ticket_ids[$ticket_id] = true)
- Ensures true uniqueness when checking for duplicate ticket types, preventing edge cases
- No changes to booking/cart/session logic

### Version 2.2.4 (January 13, 2026)
- **Critical Fix**: Removed duplicate code block in updateSummary() function that caused JS syntax error
- Fixed broken frontend functionality: Clear All button, totals display, exclusive group enforcement, and Add to Cart
- Removed extra closing brace that prevented JavaScript from executing properly

### Version 2.2.3 (January 13, 2026)
- **Table-Specific Exclusive Group Validation**: Server-side validation now uses only the current table_id
- Added `data-table-id` attribute to matrix container for proper table identification
- Frontend passes table_id in AJAX requests to ensure exclusive groups are validated per table
- Prevents false conflicts between events in different tables with same group names
- More efficient validation by loading only relevant table config instead of all tables

### Version 2.2.2 (January 13, 2026)
- **Improved Exclusive Group Handling**: Enhanced JS handlers to get exclusive-group from input data attribute or fallback to row
- Updated `handleQuantityChange()` and `handleCheckboxChange()` for more robust exclusive group detection
- Ensures exclusive group enforcement works regardless of where the data attribute is placed
- Server-side validation already correctly loads table config and validates group conflicts

### Version 2.2.1 (January 13, 2026)
- **Exclusive Group Enforcement**: Implemented automatic mutual exclusion for events in the same group
- When selecting an event with an exclusive group, automatically deselects other events in that group
- Client-side JavaScript handler clears conflicting selections in real-time
- Server-side validation prevents adding tickets from multiple events in the same exclusive group
- Clear error message: "Only one event may be selected per exclusive group"
- Uses table config data to map event IDs to groups for accurate validation

### Version 2.2.0 (January 13, 2026)
- **Critical Fix**: Resolved cart/session persistence issues with AJAX cookie mismatch
- Changed AJAX URL to relative path using `wp_make_link_relative()` to prevent www/non-www cookie issues
- **Explicit Error**: Event already in cart now returns clear error instead of silently updating
- Message: "You already have a ticket for this event in your cart. Only one ticket per event is allowed."
- **Cache Prevention**: Added cache-buster query parameters to cart and checkout URLs
- Prevents users from seeing cached empty cart pages on first click
- Improved session management with proper `session_close()` on all error paths

### Version 2.1.9 (January 13, 2026)
- **Critical Fix**: Corrected Events Manager option key for max spaces validation (uses `dbem_bookings_form_max` for numeric limit)
- **New Validation**: Enforces one ticket type per event per submission (prevents mixed ticket selections)
- **Session Persistence**: Fixed cart/checkout persistence on first click using proper EM session methods
- Added `EM_Multiple_Bookings::session_start()` and `session_close()` at appropriate points
- Removed non-working `save_to_session()` code that doesn't exist in EM_Multiple_Booking
- Uses `dbem_booking_feedback_spaces_limit` as message template only (not as numeric value)
- All error paths now properly close EM session before returning

### Version 2.1.8 (January 13, 2026)
- **Critical Fix**: Corrected Events Manager option key for "Maximum spaces per booking" enforcement
- Uses correct option key precedence: `dbem_booking_feedback_spaces_limit` (primary), `dbem_bookings_max_spaces`, `dbem_booking_spaces_limit`
- Fixed front-end booking limit display to use global EM setting instead of incorrect postmeta query
- Removed database query that was using wrong event IDs (EM event_id vs WP post_id)
- Ensures consistent enforcement across server-side validation and UI display
- Compatible with Events Manager Pro 3.7.2.3

### Version 2.1.7 (January 13, 2026)
- **Server-Side Validation**: Added enforcement of Events Manager's "Maximum spaces per booking" setting
- Validates total ticket quantity per event against configured maximum before adding to cart
- Fail-fast behavior prevents cart additions that exceed the limit
- Clear error messages show both the limit and requested amount
- Enhances booking integrity and prevents overselling through cart manipulation

### Version 2.1.6 (January 13, 2026)
- **Critical Fix**: Resolved ticket display issue where cells showed "N/A" instead of ticket options
- Changed ticket loading from database array construction to proper Events Manager API calls
- Fixed event loading to use EM event_id instead of wp_posts ID
- Improved admin UI with expandable matrix configuration area and sticky headers
- Enhanced frontend rendering reliability with EM Pro 3.7.2.3

### Version 2.1.5 (January 13, 2026)
- **Frontend Fix**: Corrected event query to use Events Manager event IDs
- **Admin UI Enhancement**: Added viewport-based height and sticky headers for matrix configuration
- Improved admin matrix usability for large event tables

### Version 2.1.3 (January 12, 2026)
- **Enhanced Cart Validation**: Critical security and validation improvement
- Replaced permissive bypass_cart_validation() with intelligent validation
- Prevents adding sold-out, closed, or unavailable tickets to cart
- Distinguishes between "hard" failures (capacity) and "soft" failures (form fields)
- Enforces ticket-level validation while allowing guest checkouts
- Better user experience with immediate feedback on availability issues
- Maintains backward compatibility with existing workflows

### Version 2.1.2 (January 12, 2026)
- **Improved Events Manager Pro compatibility**: Better handling of booking validation
- Added bypass_cart_validation() filter to allow cart additions without form fields
- Explicit session persistence after cart mutations
- Supports events with required booking form fields (collected at checkout)
- Better guest user handling for anonymous cart additions
- Prevents validation failures when custom attendee details required

### Version 2.1.1 (January 12, 2026)
- **Optimized cache clearing for large sites**: Replaced DELETE LIKE queries with batched deletions
- Fetch transient keys first (non-blocking SELECT), then delete in 50-100 key batches
- Prevents table locks on large wp_options tables (100k+ rows)
- Better performance on high-traffic sites with many plugins using options table
- Limits queries to prevent runaway deletions

### Version 2.1.0 (January 12, 2026)
- **Improved Caching Strategy**: Reduced default cache TTL from 30 minutes to 3 minutes
- Automatic cache clearing when tickets are added to cart
- Better real-time availability display for fast-selling events
- Reduces "failed add to cart" attempts from stale data
- Added clear_table_cache() method for programmatic cache control
- Filter now receives table_id for per-table cache customization
- Enhanced documentation for cache tuning based on sales patterns

### Version 2.0.9 (January 12, 2026)
- **Major Performance Optimization**: Bulk ticket availability computation
- Previously called get_available_spaces() per cell (180+ queries for 30×6 table)
- Now fetches all booked/reserved counts in 2 bulk queries
- Computes availability once per unique ticket and caches result
- Reduces database load by ~99% for large matrices
- Dramatically faster page load times for tables with many cells

### Version 2.0.8 (January 12, 2026)
- **Bug Fix**: Fixed memory limit check when PHP memory_limit is set to -1 (unlimited)
- Previously converted -1 to negative bytes, causing false "low memory" errors
- Now correctly treats -1 as unlimited and always passes check
- Prevents plugin from refusing to render on servers with unlimited memory

### Version 2.0.7 (January 12, 2026)
- **Elementor/Page Builder Fix**: Assets now enqueue reliably with Elementor and page builders
- Previously relied on shortcode being in post_content (fails with Elementor widgets)
- Now enqueues CSS/JS directly in render_shortcode() for guaranteed loading
- Added duplicate prevention to avoid multiple enqueuing
- Maintains backward compatibility with standard WordPress posts
- Fixes common multisite + Elementor asset loading issues

### Version 2.0.6 (January 12, 2026)
- **Security Fix**: Added proper sanitization when saving table configurations
- Previously bypassed sanitize_tables() routine when saving via save_table_config()
- Now applies full sanitization to events and columns arrays before database storage
- Prevents potential XSS vulnerabilities from unsanitized stored data
- Improves data integrity and robustness

### Version 2.0.5 (January 12, 2026)
- Fixed event loading bug in bulk query operation
- Now correctly passes post ID to em_get_event() instead of full array
- Improved compatibility with Events Manager API expectations
- Prevents potential incorrect event loading or extra queries

### Version 2.0.4 (January 12, 2026)
- Removed duplicate AJAX handler that caused conflicts
- Cleaned up legacy code from v1.0 (get_event_tickets method)
- Improved code maintainability and reduced potential bugs
- Admin ticket loading now uses single consistent endpoint

### Version 2.0.3 (January 12, 2026)
- Fixed critical JavaScript selector bug where buttons weren't working
- Changed JS from ID selectors to class selectors to match HTML
- Fixed "Add to Cart" and "Clear All" button functionality
- Added dynamic item count display in cart summary
- Improved loading state handling during cart operations
- Used event delegation for better compatibility with dynamic content

### Version 2.0.2 (January 12, 2026)
- Added table export/import functionality
- Export table configurations as JSON files
- Import previously exported configurations
- Useful for backups, version control, and site migrations

### Version 2.0.1 (January 12, 2026)
- Fixed initialization order for better error handling
- Added proper textdomain loading for internationalization
- Improved dependency checking logic
- Enhanced security with proper URL escaping
- Removed debug and diagnostic files
- Improved code organization and consistency

### Version 2.0.0 (January 7, 2026)
- Complete rewrite with multi-table configuration system
- Unlimited tables with unique configurations
- Flexible event and column counts (1-10 each)
- Custom column names and per-cell ticket mapping
- Table management interface with duplicate and archive features
- Exclusive event groups for conflict prevention
- Enhanced performance with event caching

### Version 1.0.0
- Initial release
- Matrix display with 5 events
- Early Bird and Regular pricing support
- 5 standardized ticket types
- Toggle and side-by-side display modes
- Integration with Events Manager Multiple Bookings
