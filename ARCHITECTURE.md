# ASCE Ticket Matrix - Architecture & Implementation Guide

## System Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                    WordPress + Events Manager                    │
│                    with Multiple Bookings Mode                   │
└─────────────────────────────────────────────────────────────────┘
                              ▲
                              │
┌─────────────────────────────┴───────────────────────────────────┐
│              ASCE Ticket Matrix Plugin                           │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─────────────────┐  ┌──────────────────┐  ┌─────────────────┐│
│  │   Admin Panel   │  │  Frontend Matrix │  │  AJAX Handler   ││
│  │   (Settings)    │  │   (Shortcode)    │  │   (Cart Add)    ││
│  └─────────────────┘  └──────────────────┘  └─────────────────┘│
│           │                     │                      │          │
│           ├─────────────────────┼──────────────────────┤          │
│           │                     │                      │          │
│  ┌────────▼─────────────────────▼──────────────────────▼────────┐│
│  │            Events Manager Multiple Bookings API              ││
│  │  • get_multiple_booking()                                    ││
│  │  • add_booking()                                             ││
│  │  • save_bookings()                                           ││
│  │  • Cart Session Management                                   ││
│  └──────────────────────────────────────────────────────────────┘│
└───────────────────────────────────────────────────────────────────┘
```

## Data Flow

### 1. Configuration Phase (Admin)

```
Admin User
    ↓
Settings Page (class-asce-tm-settings.php)
    ↓
Create/Edit Table
    • Set table name
    • Define number of events (rows)
    • Define number of columns
    ↓
Configure Events
    • Select Event for each row
    • Optional: Override label
    • Optional: Set exclusive group
    ↓
Define Columns
    • Name each column
    • Select specific ticket for each event/column cell
    ↓
Save to WordPress Options
    • asce_tm_tables (all table configurations)
    • Unique table_id generated
    • Shortcode created: [asce_ticket_matrix id="table_xxx"]
```

### 2. Display Phase (Frontend)

```
User Visits Page with [asce_ticket_matrix]
    ↓
class-asce-tm-matrix.php::render_shortcode()
    ↓
Load Events from Options
    ↓
Query Events Manager for Event Objects & Tickets
    ↓
Match Tickets to Types (ticket_matches_type)
    ↓
Render Matrix HTML
    • Headers (Event Names/Dates)
    • Rows (Ticket Types)
    • Cells (Price + Quantity Input)
    ↓
Enqueue CSS & JavaScript
    ↓
User Sees Interactive Matrix
```

### 3. Interaction Phase (User Selection)

```
User Selects Quantities
    ↓
JavaScript (ticket-matrix.js)
    • handleQuantityChange()
    • updateSummary()
    ↓
Calculate Total in Real-Time
    • Loop visible inputs
    • Sum prices × quantities
    • Update display
    ↓
Enable "Add to Cart" Button
```

### 4. Cart Addition Phase (AJAX)

```
User Clicks "Add to Cart"
    ↓
JavaScript collects selected tickets:
    [{event_id, ticket_id, quantity}, ...]
    ↓
AJAX POST to wp-admin/admin-ajax.php
    action: asce_tm_add_to_cart
    nonce: security token
    tickets: JSON array
    ↓
class-asce-tm-ajax.php::add_to_cart()
    ↓
Get EM_Multiple_Bookings Session
    ↓
For Each Event:
    • Create/Get EM_Booking object
    • Set tickets via $_POST['em_tickets']
    • Validate booking
    • Add to Multiple Booking
    ↓
Return Success Response
    • cart_url
    • checkout_url
    • added_count
    ↓
Redirect User to Cart Page
```

### 5. Checkout Phase (Events Manager)

```
User on Cart Page
    ↓
Events Manager Multiple Bookings Template
    • Display all events in cart
    • Show ticket details
    • Allow remove/edit
    ↓
User Clicks "Proceed to Checkout"
    ↓
Checkout Page
    • Single form for all bookings
    • User registration fields
    • Payment gateway
    ↓
EM_Multiple_Booking::save_bookings()
    • Save master booking (event_id = 0)
    • Save individual bookings
    • Create relationships in DB
    • Process payment
    • Send emails
    ↓
Confirmation & Redirect
```

## Key Components

### 1. Main Plugin File (`asce-ticket-matrix.php`)

**Responsibilities:**
- Plugin initialization
- Dependency checks
- Asset enqueuing (conditional - only on pages with shortcode)
- Register activation hook
- Load component classes

**Key Functions:**
- `asce_tm_init()` - Initialize all components
- `asce_tm_enqueue_assets()` - Load CSS/JS on shortcode pages
- `asce_tm_activate()` - Set default options on activation

### 2. Settings Class (`class-asce-tm-settings.php`)

**Responsibilities:**
- Admin menu page
- Settings form rendering
- Table configuration UI (multiple tables support)
- Event/ticket/column configuration
- AJAX endpoints for loading tickets, managing tables
- Data sanitization & validation
- Import/export functionality

**Key Methods:**
- `add_admin_menu()` - Register admin page under Events Manager
- `render_settings_page()` - Output settings form
- `ajax_get_event_tickets()` - Load tickets for event selection
- `ajax_delete_table()` - Delete table configuration
- `ajax_duplicate_table()` - Duplicate existing table
- `ajax_toggle_archive()` - Archive/unarchive table
- `sanitize_tables()` - Validate table configuration

**Database Options:**
```php
// Version 2.x+ structure (flexible multi-table with configurable columns)
asce_tm_tables = [
    'table_abc123' => [
        'name' => 'Main Event Matrix',
        'num_events' => 5,
        'num_columns' => 3,
        'archived' => false,
        'events' => [
            0 => [
                'event_id' => 123,
                'label' => 'Custom Event Name',  // Optional override
                'group' => 'group_a'             // Optional exclusive group
            ],
            // ... more events
        ],
        'columns' => [
            0 => [
                'name' => 'Early Bird',
                'tickets' => [
                    0 => 456,  // ticket_id for event index 0
                    1 => 789,  // ticket_id for event index 1
                    // ...
                ]
            ],
            1 => [
                'name' => 'Regular',
                'tickets' => [...]
            ],
            // ... more columns
        ]
    ],
    // ... more tables
]
```

### 3. Matrix Display Class (`class-asce-tm-matrix.php`)

**Responsibilities:**
- Shortcode registration & rendering
- Matrix HTML generation
- Ticket matching logic
- Event/ticket data loading

**Key Methods:**
- `render_shortcode()` - Main shortcode handler
- `ticket_matches_type()` - Match ticket names to types

**HTML Structure:**
```html
<div class="asce-ticket-matrix-container">
    <div class="asce-tm-pricing-toggle">
        <!-- Radio buttons for Early Bird/Regular -->
    </div>
    
    <table class="asce-tm-matrix-table">
        <thead>
            <tr>
                <th>Ticket Type</th>
                <th>Event 1</th>
                <th>Event 2</th>
                <!-- ... -->
            </tr>
        </thead>
        <tbody>
            <tr data-ticket-type="nonmember_private">
                <td>Non-Member (Private)</td>
                <td>
                    <!-- Early Bird -->
                    <div data-pricing-tier="earlybird">
                        <div class="price">$50</div>
                        <input type="number" 
                               data-event-id="123"
                               data-ticket-id="456"
                               data-price="50">
                    </div>
                    <!-- Regular -->
                    <div data-pricing-tier="regular" style="display:none">
                        <div class="price">$75</div>
                        <input type="number" 
                               data-event-id="123"
                               data-ticket-id="789"
                               data-price="75">
                    </div>
                </td>
                <!-- ... -->
            </tr>
            <!-- ... other ticket types ... -->
        </tbody>
    </table>
    
    <div class="asce-tm-summary">
        <div class="total">Total: $XXX.XX</div>
        <div class="count">X tickets selected</div>
        <button id="asce-tm-add-to-cart">Add to Cart</button>
    </div>
</div>
```

### 4. AJAX Handler Class (`class-asce-tm-ajax.php`)

**Responsibilities:**
- Handle add to cart AJAX requests
- Interface with EM Multiple Bookings API
- Admin AJAX for ticket loading

**Key Methods:**
- `add_to_cart()` - Main cart addition logic
- `get_event_tickets()` - Load tickets for admin dropdown

**AJAX Flow:**
```php
// Client sends
{
    action: 'asce_tm_add_to_cart',
    nonce: 'xyz123',
    tickets: [
        {event_id: 123, ticket_id: 456, quantity: 2},
        {event_id: 124, ticket_id: 789, quantity: 1}
    ]
}

// Server processes
1. Verify nonce
2. Get EM_Multiple_Booking session
3. Group tickets by event
4. For each event:
    - Create EM_Booking
    - Set $_POST['em_tickets']
    - Validate
    - Add to cart
5. Return response

// Server responds
{
    success: true,
    data: {
        message: 'Successfully added...',
        cart_url: '/cart/',
        checkout_url: '/checkout/',
        added_count: 2,
        events: ['Event 1', 'Event 2']
    }
}
```

## JavaScript Architecture (`ticket-matrix.js`)

### TicketMatrix Object

**Properties:**
- `selectedTickets[]` - Array of selected ticket objects
- `totalAmount` - Running price total
- `totalItems` - Count of tickets

**Methods:**

1. **init()**
   - Bind event handlers
   - Initialize summary

2. **togglePricingTier(tier)**
   - Show/hide ticket options based on selected tier
   - Clear hidden inputs
   - Recalculate summary

3. **handleQuantityChange($input)**
   - Validate quantity (min/max)
   - Trigger summary update

4. **updateSummary()**
   - Loop through visible quantity inputs
   - Build selectedTickets array
   - Calculate totals
   - Update UI
   - Enable/disable Add to Cart button

5. **addToCart()**
   - Validate selections
   - Show loading overlay
   - AJAX POST to server
   - Handle response
   - Show success message
   - Redirect to cart

6. **clearAll()**
   - Reset all quantity inputs
   - Update summary

## CSS Architecture (`ticket-matrix.css`)

### Component Structure

**Layout Components:**
- `.asce-ticket-matrix-container` - Main wrapper
- `.asce-tm-pricing-toggle` - Toggle switch area
- `.asce-tm-matrix-wrapper` - Scrollable table container
- `.asce-tm-summary` - Bottom summary bar

**Table Components:**
- `.asce-tm-matrix-table` - Main table
- `.asce-tm-header-event` - Event column headers
- `.asce-tm-cell-ticket` - Ticket cell
- `.asce-tm-ticket-option` - Individual ticket option container

**Interactive Components:**
- `.asce-tm-qty-input` - Number input
- `.asce-tm-btn-primary` - Primary button
- `.asce-tm-btn-secondary` - Secondary button

**State Indicators:**
- `.asce-tm-low-stock` - Low availability warning
- `.asce-tm-sold-out` - Sold out indicator
- `.asce-tm-not-available` - N/A state

### Responsive Design

**Breakpoint: 768px**
- Stack summary components vertically
- Reduce font sizes
- Adjust padding
- Full-width buttons

## Integration with Events Manager

### Multiple Bookings API Usage

**Getting Cart:**
```php
$EM_Multiple_Booking = EM_Multiple_Bookings::get_multiple_booking();
```

**Adding Booking:**
```php
$EM_Booking = new EM_Booking();
$EM_Booking->event_id = $event_id;
$EM_Booking->person_id = get_current_user_id();

// Set tickets via POST
$_POST['em_tickets'] = [
    ticket_id => ['spaces' => quantity]
];

$EM_Booking->get_post();
$EM_Booking->validate();

$EM_Multiple_Booking->add_booking($EM_Booking);
```

**Saving Cart:**
```php
// Done automatically by EM on checkout
$EM_Multiple_Booking->save_bookings();
```

### Database Tables Used

**Events Manager Tables:**
- `wp_em_events` - Event data
- `wp_em_tickets` - Ticket definitions
- `wp_em_bookings` - Individual bookings
- `wp_em_tickets_bookings` - Ticket quantities per booking
- `wp_em_bookings_relationships` - Links bookings to master booking

**WordPress Options:**
- `asce_tm_tables` - All table configurations (v2.0+)
- `dbem_multiple_bookings` - EM setting (must be 1)
- `dbem_multiple_bookings_cart_page` - Cart page ID
- `dbem_multiple_bookings_checkout_page` - Checkout page ID

## Security Considerations

### Nonce Verification
- All AJAX requests use WordPress nonces
- Admin forms use nonce fields
- Nonce checked before processing

### Capability Checks
- Admin pages: `manage_options`
- AJAX: No special capability (public)
- Data sanitization on all inputs

### Data Validation
- Event IDs validated as integers
- Quantities validated against ticket availability
- Price calculations done server-side
- No price data accepted from client

## Error Handling

### Frontend
- Input validation (min/max quantities)
- AJAX error handling with user messages
- Fallback for failed AJAX requests

### Backend
- Validation of all bookings
- Rollback on save failure
- Error collection and reporting
- Graceful degradation if EM not available

## Performance Considerations

### Asset Loading
- CSS/JS only loaded on pages with shortcode
- Conditional enqueue via `has_shortcode()`
- Minimized DOM queries

### Database Queries
- Events cached in options
- Ticket data loaded once per render
- No repeated queries in loops

### AJAX Efficiency
- Single request for all tickets
- Batch processing by event
- Minimal response payload

## Future Enhancement Possibilities

1. **Ticket Type Configuration UI**
   - Admin page to add/edit ticket types
   - Custom matching rules per type

2. **Event Filtering**
   - Show only events in date range
   - Category-based event selection

3. **Discount Codes**
   - Apply coupons to entire matrix
   - Show discounted prices

4. **Export Functionality**
   - Export matrix to PDF
   - Share configuration between sites

5. **Analytics**
   - Track which ticket combinations popular
   - Conversion tracking

6. **Multi-site Support**
   - Cross-site event selection
   - Centralized checkout

## Deployment Checklist

- [ ] Events Manager installed and activated
- [ ] Events Manager Pro installed and activated
- [ ] Multiple Bookings Mode enabled
- [ ] Cart page created and configured
- [ ] Checkout page created and configured
- [ ] 5 events created with tickets
- [ ] Tickets named with proper keywords
- [ ] Plugin uploaded and activated
- [ ] Plugin settings configured
- [ ] Test page created with shortcode
- [ ] Test booking flow end-to-end
- [ ] Verify email notifications
- [ ] Check mobile responsiveness

---

**Version:** 2.1.3  
**Last Updated:** January 12, 2026
