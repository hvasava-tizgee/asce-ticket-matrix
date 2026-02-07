<?php
/*
 * ASCE Ticket Matrix → EM Pro Integration (Custom Matrix, Native Checkout)
 * Environment: WP Multisite + Events Manager Pro MB/cart enabled. DO NOT edit EM core.
 * Architecture: Custom ticket matrix frontend → EM Pro cart session → EM Pro native checkout/payment
 * User Flow: (1) Tickets (custom matrix) → (2) Checkout (EM Pro native page with forms/payment/success)
 * Incremental rule: Do not revert prior working features. Make surgical edits only. Prefer additive changes. Preserve exclusive group logic and ticket selection UI.
 * Debug visibility: admin-only, behind isAdmin.
 */
/**
 * ASCE Ticket Matrix AJAX Handler Class
 * 
 * Handles AJAX requests for adding tickets to cart.
 * Interfaces with Events Manager Multiple Bookings API
 * to manage cart operations and booking validations.
 * 
 * Key Features:
 * - Validates ticket availability and capacity before adding to cart
 * - Bypasses non-critical form field validation during cart operations
 * - Clears validation errors when safely bypassing to prevent false negatives
 * - Groups tickets by event for efficient processing
 * - Maintains cart session persistence across EM configurations
 * 
 * @package ASCE_Ticket_Matrix
 * @version 2.9.22
 * @since 1.0.0
 */

class ASCE_TM_Ajax {
    
    public static function init() {
        // AJAX handlers for both logged in and non-logged in users
        add_action( 'wp_ajax_asce_tm_checkout', array( __CLASS__, 'checkout' ) );
        add_action( 'wp_ajax_nopriv_asce_tm_checkout', array( __CLASS__, 'checkout' ) );
        
        // Cart snapshot debug endpoint (admin only)
        add_action( 'wp_ajax_asce_tm_cart_snapshot', array( __CLASS__, 'cart_snapshot' ) );
        add_action( 'wp_ajax_nopriv_asce_tm_cart_snapshot', array( __CLASS__, 'cart_snapshot' ) );
        
        // v3.5.5+: Global bypass filter for checkout when forms are skipped
        // This runs for ALL booking validations when the bypass flag is set
        add_filter( 'em_booking_validate', array( __CLASS__, 'maybe_bypass_validation_globally' ), 99, 2 );
        
        // v5.0.5: Filter gateways based on table selection
        add_filter( 'em_gateways_active', array( __CLASS__, 'filter_active_gateways' ), 10, 1 );
        
        // Stepper forms endpoints (DEPRECATED in v3.0.0 - Now using EM Pro native checkout)
        // Keeping these for backward compatibility, but they are no longer used
        add_action( 'wp_ajax_asce_tm_get_forms_map', array( __CLASS__, 'get_forms_map' ) );
        add_action( 'wp_ajax_nopriv_asce_tm_get_forms_map', array( __CLASS__, 'get_forms_map' ) );
        add_action( 'wp_ajax_asce_tm_save_forms_data', array( __CLASS__, 'save_forms_data' ) );
        add_action( 'wp_ajax_nopriv_asce_tm_save_forms_data', array( __CLASS__, 'save_forms_data' ) );
        add_action( 'wp_ajax_asce_tm_get_session_tickets', array( __CLASS__, 'get_session_tickets' ) );
        add_action( 'wp_ajax_nopriv_asce_tm_get_session_tickets', array( __CLASS__, 'get_session_tickets' ) );
        add_action( 'wp_ajax_asce_tm_set_session_tickets', array( __CLASS__, 'set_session_tickets' ) );
        add_action( 'wp_ajax_nopriv_asce_tm_set_session_tickets', array( __CLASS__, 'set_session_tickets' ) );
        add_action( 'wp_ajax_asce_tm_clear_session_tickets', array( __CLASS__, 'clear_session_tickets' ) );
        add_action( 'wp_ajax_nopriv_asce_tm_clear_session_tickets', array( __CLASS__, 'clear_session_tickets' ) );
        
        // Payment step endpoints (DEPRECATED in v3.0.0 - Now using EM Pro native checkout)
        // Keeping these for backward compatibility, but they are no longer used
        add_action( 'wp_ajax_asce_tm_finalize_bookings', array( __CLASS__, 'finalize_bookings' ) );
        add_action( 'wp_ajax_nopriv_asce_tm_finalize_bookings', array( __CLASS__, 'finalize_bookings' ) );
        add_action( 'wp_ajax_asce_tm_get_payment_gateways', array( __CLASS__, 'get_payment_gateways' ) );
        add_action( 'wp_ajax_nopriv_asce_tm_get_payment_gateways', array( __CLASS__, 'get_payment_gateways' ) );
    }
    
    /**
     * Process checkout with deterministic cart reset
     * 
     * Processes AJAX requests to checkout with multiple tickets across multiple events.
     * Enforces strict validation - all tickets must be added successfully or returns error.
     * Automatically resets cart before processing to ensure deterministic behavior.
     * 
     * @since 2.0.0
     * @return void Sends JSON response (success or error) and terminates
     */
    public static function checkout() {
        // CRITICAL: Force cart mode BEFORE any EM classes are initialized
        // This must happen before session_start() and get_multiple_booking()
        add_filter( 'option_dbem_multiple_bookings', '__return_true', 999 );
        add_filter( 'pre_option_dbem_multiple_bookings', '__return_true', 999 );
        
        $timer_start = microtime( true );
        error_log( '========================================' );
        error_log( '=== ASCE TM CHECKOUT START [' . date('H:i:s') . '] ===' );
        error_log( 'Filters applied: option_dbem_multiple_bookings, pre_option_dbem_multiple_bookings' );
        error_log( 'Filter check - get_option(dbem_multiple_bookings): ' . ( get_option('dbem_multiple_bookings') ? 'TRUE' : 'FALSE' ) );
        error_log( '========================================' );
        
        check_ajax_referer( 'asce_tm_checkout', 'nonce' );
        error_log( '[OK] Nonce validated' );
        
        // Validate table_id is present (required for exclusive-group mapping)
        $table_id = isset( $_POST['table_id'] ) ? sanitize_text_field( wp_unslash( $_POST['table_id'] ) ) : '';
        if ( empty( $table_id ) ) {
            wp_send_json_error( array(
                'message' => __( 'Missing table_id. Please reload the page and try again.', 'asce-tm' )
            ) );
        }
        
        // Enforce blog context for multisite
        $posted_blog_id = isset( $_POST['blog_id'] ) ? absint( $_POST['blog_id'] ) : 0;
        $current_blog_id = get_current_blog_id();
        $switched_blog = false;
        
        if ( is_multisite() && $posted_blog_id && $posted_blog_id !== $current_blog_id ) {
            switch_to_blog( $posted_blog_id );
            $switched_blog = true;
        }
        
        // Initialize EM Multiple Bookings session for cart persistence
        // CRITICAL: EM Pro only loads MB classes if option is true during plugin init
        // Since we're forcing it via filter in AJAX context, we must manually load the class
        if ( ! class_exists( 'EM_Multiple_Bookings' ) ) {
            error_log( '[WARN] EM_Multiple_Bookings class not loaded - attempting manual load' );
            
            // Try to locate and load EM Pro Multiple Bookings classes
            $em_pro_path = defined('EMP_DIR') ? EMP_DIR : WP_PLUGIN_DIR . '/events-manager-pro';
            $em_pro_path = trailingslashit( $em_pro_path ); // Ensure trailing slash
            $mb_class_file = $em_pro_path . 'add-ons/multiple-bookings/multiple-bookings.php';
            
            error_log( 'Looking for MB class at: ' . $mb_class_file );
            error_log( 'File exists: ' . ( file_exists($mb_class_file) ? 'YES' : 'NO' ) );
            
            if ( file_exists( $mb_class_file ) ) {
                error_log( 'Attempting to include MB class file...' );
                include_once( $mb_class_file );
                
                if ( class_exists( 'EM_Multiple_Bookings' ) ) {
                    error_log( '[OK] EM_Multiple_Bookings class loaded successfully' );
                } else {
                    error_log( '[FATAL] MB file included but class still not available' );
                    if ( $switched_blog ) {
                        restore_current_blog();
                    }
                    wp_send_json_error( array(
                        'message' => __( 'Multiple Bookings class could not be loaded.', 'asce-tm' )
                    ) );
                }
            } else {
                error_log( '[FATAL] MB class file not found at expected location' );
                error_log( 'EMP_DIR constant: ' . ( defined('EMP_DIR') ? EMP_DIR : 'NOT DEFINED' ) );
                if ( $switched_blog ) {
                    restore_current_blog();
                }
                wp_send_json_error( array(
                    'message' => __( 'Multiple Bookings Mode is not enabled or EM Pro not installed.', 'asce-tm' )
                ) );
            }
        } else {
            error_log( '[OK] EM_Multiple_Bookings class already loaded' );
        }
        
        // Deterministically reset cart before processing using EM Pro API
        // empty_cart() handles: session_start(), unset session, null booking_data, session_close()
        $cart_empty_start = microtime( true );
        EM_Multiple_Bookings::empty_cart();
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $cart_empty_time = number_format( ( microtime( true ) - $cart_empty_start ) * 1000, 2 );
            error_log( '[PERF] Cart empty: ' . $cart_empty_time . 'ms' );
        }
        
        // Restart session after empty_cart() closed it
        $session_start_time = microtime( true );
        EM_Multiple_Bookings::session_start();
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $session_time = number_format( ( microtime( true ) - $session_start_time ) * 1000, 2 );
            error_log( '[PERF] Session start: ' . $session_time . 'ms' );
        }
        
        // Verify session is actually active and usable
        if ( session_status() !== PHP_SESSION_ACTIVE && ! isset( $_SESSION ) ) {
            EM_Multiple_Bookings::session_close(); // Cleanup attempt
            if ( $switched_blog ) {
                restore_current_blog();
            }
            wp_send_json_error( array(
                'message' => __( 'Session could not be initialized. Please check your server PHP session configuration or contact the site administrator.', 'asce-tm' )
            ) );
        }
        
        // Get fresh multiple booking - creates new EM_Multiple_Booking automatically
        $EM_Multiple_Booking = EM_Multiple_Bookings::get_multiple_booking();
        if ( ! $EM_Multiple_Booking ) {
            EM_Multiple_Bookings::session_close();
            if ( $switched_blog ) {
                restore_current_blog();
            }
            wp_send_json_error( array(
                'message' => __( 'Failed to initialize cart session.', 'asce-tm' )
            ) );
        }
        
        // Add debug header with session info (only in debug mode)
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            header( 'X-ASCE-TM-SESSION: ' . session_name() . '=' . session_id() );
        }
        
        nocache_headers();
        
        // Increase timeout for large cart operations
        @set_time_limit( 60 );
        
        // Get tickets from POST data
        $tickets = isset( $_POST['tickets'] ) ? json_decode( wp_unslash( $_POST['tickets'] ), true ) : array();
        
        error_log( '--- POST Data ---' );
        error_log( 'Tickets received: ' . count($tickets) . ' tickets' );
        error_log( 'Raw tickets JSON: ' . ( isset($_POST['tickets']) ? $_POST['tickets'] : 'NOT SET' ) );
        
        if ( empty( $tickets ) ) {
            error_log( '[FATAL] No tickets in POST data' );
            EM_Multiple_Bookings::session_close();
            if ( $switched_blog ) {
                restore_current_blog();
            }
            wp_send_json_error( array(
                'message' => __( 'No tickets selected.', 'asce-tm' )
            ) );
        }
        error_log( '[OK] Tickets validated: ' . count($tickets) . ' tickets' );
        
        // Limit number of tickets to prevent memory issues
        $max_tickets = apply_filters( 'asce_tm_max_cart_items', ASCE_TM_MAX_CART_ITEMS );
        if ( count( $tickets ) > $max_tickets ) {
            EM_Multiple_Bookings::session_close();
            if ( $switched_blog ) {
                restore_current_blog();
            }
            wp_send_json_error( array(
                'message' => sprintf( __( 'Too many tickets. Maximum %d tickets allowed per transaction.', 'asce-tm' ), $max_tickets )
            ) );
        }
        
        $errors = array();
        $added_count = 0;
        $events_added = array();
        $requested_count = count( $tickets );
        
        // v3.5.5+: Set flag to bypass form validation since we're skipping custom forms
        // This flag will persist through checkout and tell EM to skip required field validation
        $_SESSION['asce_tm_bypass_forms'] = true;
        
        // Group tickets by event
        $tickets_by_event = array();
        foreach ( $tickets as $ticket_data ) {
            $event_id = absint( $ticket_data['event_id'] );
            if ( ! isset( $tickets_by_event[ $event_id ] ) ) {
                $tickets_by_event[ $event_id ] = array();
            }
            $tickets_by_event[ $event_id ][] = $ticket_data;
        }
        
        // Debug logging: Log incoming tickets
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '=========================================' );
            error_log( '=== ASCE TM Checkout - Ticket Processing Start ===' );
            error_log( 'Total tickets received: ' . count( $tickets ) );
            error_log( 'Unique events: ' . count( $tickets_by_event ) );
            error_log( 'Events: ' . implode( ', ', array_keys( $tickets_by_event ) ) );
            error_log( '=========================================' );
        }
        
        // v5.0.6: Store table's gateway preference in session for checkout page
        // This allows the gateway filter to show only the selected gateway
        if ( ! empty( $table_id ) ) {
            $all_tables = get_option( 'asce_tm_tables', array() );
            $selected_gateway = ! empty( $all_tables[ $table_id ]['payment_gateway'] ) 
                ? $all_tables[ $table_id ]['payment_gateway'] 
                : 'stripe';
            
            $_SESSION['asce_tm_selected_gateway'] = $selected_gateway;
            $_SESSION['asce_tm_active'] = true; // Flag to indicate ASCE TM checkout
            error_log( '[v5.0.6] Table gateway preference stored in session: ' . $selected_gateway );
        }
        
        // Build event_id to exclusive group mapping from current table only
        $event_groups = array();
        
        if ( ! empty( $table_id ) ) {
            $all_tables = get_option( 'asce_tm_tables', array() );
            
            if ( ! empty( $all_tables[ $table_id ]['events'] ) && is_array( $all_tables[ $table_id ]['events'] ) ) {
                foreach ( $all_tables[ $table_id ]['events'] as $event_config ) {
                    $event_id = absint( $event_config['event_id'] );
                    $group = ! empty( $event_config['group'] ) ? sanitize_text_field( $event_config['group'] ) : '';
                    
                    // Only track events that have a group AND are in the current request
                    if ( ! empty( $group ) && isset( $tickets_by_event[ $event_id ] ) ) {
                        $event_groups[ $event_id ] = $group;
                    }
                }
            }
        }
        
        // Check for exclusive group conflicts
        $groups_in_request = array();
        foreach ( $event_groups as $event_id => $group ) {
            if ( isset( $groups_in_request[ $group ] ) ) {
                // Two events with same exclusive group
                EM_Multiple_Bookings::session_close();
                if ( $switched_blog ) {
                    restore_current_blog();
                }
                wp_send_json_error( array(
                    'message' => __( 'Only one event may be selected per exclusive group. Please choose only one event from this group.', 'asce-tm' )
                ) );
            }
            $groups_in_request[ $group ] = $event_id;
        }
        
        // Process each event
        foreach ( $tickets_by_event as $event_id => $event_tickets ) {
            // Debug logging for each event
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( '--- Processing Event ID: ' . $event_id . ' ---' );
                error_log( 'Tickets for this event: ' . count( $event_tickets ) );
            }
            
            // Get event
            $EM_Event = em_get_event( $event_id );
            
            if ( ! $EM_Event || ! $EM_Event->event_id ) {
                $errors[] = sprintf( __( 'Event ID %d not found.', 'asce-tm' ), $event_id );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    error_log( 'ERROR: Event ID ' . $event_id . ' not found!' );
                }
                continue;
            }
            
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( 'Event found: ' . $EM_Event->event_name );
            }
            
            // Build tickets_bookings array and validate
            $tickets_bookings = array();
            $total_qty = 0;
            $unique_ticket_ids = array();
            
            foreach ( $event_tickets as $ticket_data ) {
                $ticket_id = absint( $ticket_data['ticket_id'] );
                
                // HARD VALIDATION: Ticket must exist and belong to this event
                $EM_Ticket = EM_Ticket::get( $ticket_id );
                if ( ! $EM_Ticket || empty( $EM_Ticket->ticket_id ) ) {
                    $errors[] = sprintf( __( 'Ticket ID %d not found for event ID %d.', 'asce-tm' ), $ticket_id, $event_id );
                    continue;
                }
                if ( absint( $EM_Ticket->event_id ) !== absint( $event_id ) ) {
                    $errors[] = sprintf(
                        __( 'Ticket mapping error: ticket ID %d belongs to event ID %d, but was submitted for event ID %d.', 'asce-tm' ),
                        $ticket_id,
                        absint( $EM_Ticket->event_id ),
                        $event_id
                    );
                    continue;
                }
                
                // ENFORCE: Always max = 1 ticket per event (hard cap)
                $quantity = 1;
                
                if ( $quantity > 0 ) {
                    $tickets_bookings[ $ticket_id ] = array(
                        'spaces' => $quantity
                    );
                    $total_qty += $quantity;
                    $unique_ticket_ids[ $ticket_id ] = true;
                }
            }
            
            // Enforce rule: only ONE ticket type per event per submission
            if ( count( $unique_ticket_ids ) > 1 ) {
                EM_Multiple_Bookings::session_close();
                if ( $switched_blog ) {
                    restore_current_blog();
                }
                wp_send_json_error( array(
                    'message' => __( 'You may only select ONE ticket type per event. Please choose a single ticket option for this event.', 'asce-tm' )
                ) );
            }
            
            // Enforce Events Manager "Maximum spaces per booking" setting
            $max_spaces = absint( get_option( 'dbem_bookings_form_max', 0 ) );
            if ( $max_spaces > 0 && $total_qty > $max_spaces ) {
                $tpl = (string) get_option( 'dbem_booking_feedback_spaces_limit', '' );
                $msg = ( $tpl && strpos( $tpl, '%d' ) !== false ) 
                    ? sprintf( $tpl, $max_spaces )
                    : sprintf( __( 'You cannot book more than %d space(s) for this event.', 'events-manager' ), $max_spaces );
                
                EM_Multiple_Bookings::session_close();
                if ( $switched_blog ) {
                    restore_current_blog();
                }
                wp_send_json_error( array(
                    'message' => $msg
                ) );
            }
            
            // Check if event is already in cart - return error instead of updating
            $existing_bookings = $EM_Multiple_Booking->get_bookings();
            if ( ! empty( $existing_bookings[ $event_id ] ) ) {
                // Event already in cart - do not allow adding/updating
                EM_Multiple_Bookings::session_close();
                if ( $switched_blog ) {
                    restore_current_blog();
                }
                wp_send_json_error( array(
                    'message' => __( 'You already have a ticket for this event in your cart. Only one ticket per event is allowed. Remove it from the cart before choosing a different ticket type.', 'asce-tm' )
                ) );
            }
            
            // Create new booking for this event
            $EM_Booking = new EM_Booking();
            $EM_Booking->event_id = $event_id;
            $EM_Booking->person_id = get_current_user_id(); // 0 if not logged in
            
            // Set booking status if not already set (use strict check - 0 is valid pending status)
            if ( $EM_Booking->booking_status === false || $EM_Booking->booking_status === null ) {
                $EM_Booking->booking_status = get_option( 'dbem_bookings_approval' ) ? 0 : 1;
            }
            
            // COMPATIBILITY: Save previous $_REQUEST/$_POST values to avoid side effects
            $prev_request_event_id = isset( $_REQUEST['event_id'] ) ? $_REQUEST['event_id'] : null;
            $prev_request_em_tickets = isset( $_REQUEST['em_tickets'] ) ? $_REQUEST['em_tickets'] : null;
            $prev_request_booking_comment = isset( $_REQUEST['booking_comment'] ) ? $_REQUEST['booking_comment'] : null;
            $prev_post_event_id = isset( $_POST['event_id'] ) ? $_POST['event_id'] : null;
            $prev_post_em_tickets = isset( $_POST['em_tickets'] ) ? $_POST['em_tickets'] : null;
            $prev_post_booking_comment = isset( $_POST['booking_comment'] ) ? $_POST['booking_comment'] : null;
            
            // Set both $_REQUEST and $_POST for EM_Booking::get_post() compatibility
            // EM reads from $_REQUEST['event_id'] and overwrites $EM_Booking->event_id
            $_REQUEST['event_id'] = (int) $event_id;
            $_REQUEST['em_tickets'] = $tickets_bookings;
            $_POST['event_id'] = (int) $event_id;
            $_POST['em_tickets'] = $tickets_bookings;
            
            // Apply form data from session to $_POST so EM can validate attendee fields
            // Map our custom field names (asce_tm_booking_3660_phone) back to EM's expected names (phone)
            if ( ! empty( $_SESSION['asce_tm_form_data'] ) ) {
                foreach ( $_SESSION['asce_tm_form_data'] as $field_name => $field_value ) {
                    // Extract original field ID from our custom naming: asce_tm_{section}_{formid}_{fieldid}
                    // Pattern: asce_tm_booking_3660_phone -> phone
                    if ( preg_match( '/^asce_tm_(booking|attendee)_\d+_(.+)$/', $field_name, $matches ) ) {
                        $original_field_name = $matches[2]; // Extract the original field ID
                        $_POST[ $original_field_name ] = $field_value;
                        $_REQUEST[ $original_field_name ] = $field_value;
                    }
                    // Also keep our custom name for reference
                    $_POST[ $field_name ] = $field_value;
                    $_REQUEST[ $field_name ] = $field_value;
                }
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    error_log( '--- Applying Form Data to Event ' . $event_id . ' ---' );
                    error_log( 'Form fields applied: ' . print_r( $_SESSION['asce_tm_form_data'], true ) );
                }
            }
            
            // COMPATIBILITY: Temporarily bypass non-critical validation for cart additions
            // This allows adding tickets without requiring booking form fields (name, email, custom fields)
            // while still enforcing ticket availability and capacity limits.
            // Form fields will be collected and validated during EM's checkout process.
            // Priority 99 ensures this runs after other validators have added their errors.
            add_filter( 'em_booking_validate', array( __CLASS__, 'bypass_cart_validation' ), 99, 2 );
            
            // Get POST data and validate (minimal validation for cart)
            $EM_Booking->get_post();
            
            // Set status based on approval settings
            if ( empty( $EM_Booking->booking_status ) ) {
                $EM_Booking->booking_status = get_option( 'dbem_bookings_approval' ) ? 0 : 1;
            }
            
            // Validate the booking
            // Our bypass filter will handle distinguishing between:
            // - Hard failures (capacity, availability) that must fail
            // - Soft failures (missing form fields) that can be deferred to checkout
            $validation_result = $EM_Booking->validate();
            
            // Remove our validation bypass filter to restore normal validation behavior
            remove_filter( 'em_booking_validate', array( __CLASS__, 'bypass_cart_validation' ), 99 );
            
            // Clean up form data from $_POST and $_REQUEST after processing
            if ( ! empty( $_SESSION['asce_tm_form_data'] ) ) {
                foreach ( $_SESSION['asce_tm_form_data'] as $field_name => $field_value ) {
                    // Remove our custom field name
                    unset( $_POST[ $field_name ] );
                    unset( $_REQUEST[ $field_name ] );
                    
                    // Also remove the mapped original field name
                    if ( preg_match( '/^asce_tm_(booking|attendee)_\d+_(.+)$/', $field_name, $matches ) ) {
                        $original_field_name = $matches[2];
                        unset( $_POST[ $original_field_name ] );
                        unset( $_REQUEST[ $original_field_name ] );
                    }
                }
            }
            
            // Restore previous $_REQUEST/$_POST values
            if ( $prev_request_event_id !== null ) {
                $_REQUEST['event_id'] = $prev_request_event_id;
            } else {
                unset( $_REQUEST['event_id'] );
            }
            if ( $prev_request_em_tickets !== null ) {
                $_REQUEST['em_tickets'] = $prev_request_em_tickets;
            } else {
                unset( $_REQUEST['em_tickets'] );
            }
            if ( $prev_request_booking_comment !== null ) {
                $_REQUEST['booking_comment'] = $prev_request_booking_comment;
            } else {
                unset( $_REQUEST['booking_comment'] );
            }
            if ( $prev_post_event_id !== null ) {
                $_POST['event_id'] = $prev_post_event_id;
            } else {
                unset( $_POST['event_id'] );
            }
            if ( $prev_post_em_tickets !== null ) {
                $_POST['em_tickets'] = $prev_post_em_tickets;
            } else {
                unset( $_POST['em_tickets'] );
            }
            if ( $prev_post_booking_comment !== null ) {
                $_POST['booking_comment'] = $prev_post_booking_comment;
            } else {
                unset( $_POST['booking_comment'] );
            }
            
            // Check validation and return meaningful errors
            if ( ! $validation_result ) {
                $booking_errors = $EM_Booking->get_errors();
                if ( ! empty( $booking_errors ) ) {
                    EM_Multiple_Bookings::session_close();
                    if ( $switched_blog ) {
                        restore_current_blog();
                    }
                    // Flatten errors array if needed
                    $error_strings = array();
                    foreach ( $booking_errors as $err ) {
                        if ( is_array( $err ) ) {
                            $error_strings[] = implode( ' ', $err );
                        } else {
                            $error_strings[] = $err;
                        }
                    }
                    wp_send_json_error( array(
                        'message' => implode( ' ', $error_strings )
                    ) );
                }
                // If no specific errors, use generic message
                $errors = array_merge( $errors, $EM_Booking->get_errors() );
                continue;
            }
            
            if ( $validation_result ) {
                // Add to cart
                $booking_add_start = microtime( true );
                if ( $EM_Multiple_Booking->add_booking( $EM_Booking ) ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        $booking_add_time = number_format( ( microtime( true ) - $booking_add_start ) * 1000, 2 );
                        error_log( '[PERF] Add booking Event ' . $event_id . ': ' . $booking_add_time . 'ms' );
                    }
                    $added_count++;
                    $events_added[] = $EM_Event->event_name;
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                        error_log( 'SUCCESS: Added booking for Event ID ' . $event_id . ' (' . $EM_Event->event_name . ')' );
                    }
                } else {
                    // Capture errors immediately and provide fallback message
                    $booking_errors = $EM_Booking->get_errors();
                    if ( ! empty( $booking_errors ) ) {
                        $errors = array_merge( $errors, $booking_errors );
                    } else {
                        // Generic error if no specific errors provided
                        $errors[] = sprintf( __( 'Failed to add booking for event %s.', 'asce-tm' ), $EM_Event->event_name );
                    }
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                        error_log( 'ERROR: Failed to add booking for Event ID ' . $event_id );
                        error_log( 'Booking errors: ' . print_r( $booking_errors, true ) );
                    }
                }
            } else {
                $errors = array_merge( $errors, $EM_Booking->get_errors() );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                    error_log( 'ERROR: Validation failed for Event ID ' . $event_id );
                    error_log( 'Validation errors: ' . print_r( $EM_Booking->get_errors(), true ) );
                }
            }
        }
        
        // Debug logging: Summary
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '=========================================' );
            error_log( '=== ASCE TM Checkout - Processing Complete ===' );
            error_log( 'Requested: ' . $requested_count . ' tickets' );
            error_log( 'Added: ' . $added_count . ' tickets' );
            error_log( 'Errors: ' . count( $errors ) );
            if ( ! empty( $errors ) ) {
                error_log( 'Error details: ' . print_r( $errors, true ) );
            }
            error_log( '=========================================' );
            
            // Check cart immediately after adding
            error_log( '=== Cart Status Before session_close() ===' );
            $EM_Multiple_Booking = EM_Multiple_Bookings::get_multiple_booking();
            if ( $EM_Multiple_Booking && ! empty( $EM_Multiple_Booking->bookings ) ) {
                error_log( 'Bookings in cart object: ' . count( $EM_Multiple_Booking->bookings ) );
                foreach ( $EM_Multiple_Booking->bookings as $idx => $booking ) {
                    error_log( '  Booking ' . ($idx + 1) . ': Event ' . $booking->event_id . ' (' . $booking->get_event()->event_name . ')' );
                }
            } else {
                error_log( 'Cart is empty or not initialized' );
            }
            
            // Check each booking's validation state before session close
            // NOTE: These validation checks may show INVALID due to missing form fields,
            // but this is expected when bypassing custom forms. The bypass_cart_validation filter
            // clears these errors during cart addition. EM Pro will collect the required data during checkout.
            error_log( '=== Pre-Session-Close Booking Validation ===' );
            
            // Temporarily enable bypass for this debugging validation check
            add_filter( 'em_booking_validate', array( __CLASS__, 'bypass_cart_validation' ), 99, 2 );
            
            foreach ( $EM_Multiple_Booking->bookings as $idx => $booking ) {
                $is_valid = $booking->validate();
                $booking_errors = $booking->get_errors();
                error_log( '  Booking ' . ($idx + 1) . ' (Event ' . $booking->event_id . '): ' . ( $is_valid ? 'VALID' : 'INVALID' ) );
                if ( ! empty( $booking_errors ) ) {
                    error_log( '    Errors: ' . print_r( $booking_errors, true ) );
                }
            }
            
            // Remove bypass filter after debugging check
            remove_filter( 'em_booking_validate', array( __CLASS__, 'bypass_cart_validation' ), 99 );
            
            error_log( '==========================================' );
        }
        
        if ( $added_count > 0 ) {
            // Success - at least some tickets were added
            // Clear HTML caches since availability has changed
            if ( class_exists( 'ASCE_TM_Matrix' ) ) {
                ASCE_TM_Matrix::clear_table_cache(); // Clear all table caches
            }
            
            $checkout_id = absint( get_option( 'dbem_multiple_bookings_checkout_page' ) );
            $checkout_page = $checkout_id ? wp_make_link_relative( get_permalink( $checkout_id ) ) : '';
            
            $cart_id = absint( get_option( 'dbem_multiple_bookings_cart_page' ) );
            $cart_page = $cart_id ? wp_make_link_relative( get_permalink( $cart_id ) ) : '';
            
            // Close EM session to persist cart changes
            // Note: session_close() automatically persists cart data via session_save()
            // Do NOT call save() here - that creates permanent database records
            $session_close_start = microtime( true );
            EM_Multiple_Bookings::session_close();
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $session_close_time = number_format( ( microtime( true ) - $session_close_start ) * 1000, 2 );
                $total_time = number_format( ( microtime( true ) - $timer_start ) * 1000, 2 );
                error_log( '[PERF] Session close: ' . $session_close_time . 'ms' );
                error_log( '[PERF] TOTAL CHECKOUT: ' . $total_time . 'ms' );
                error_log( '=== ASCE TM CHECKOUT END ===' );
            }
            
            // Restore blog context if switched
            if ( $switched_blog ) {
                restore_current_blog();
            }
            
            // Strict validation: all tickets must be added successfully
            if ( $added_count !== $requested_count || ! empty( $errors ) ) {
                error_log( '[FATAL] Strict validation failed!' );
                error_log( '  Added: ' . $added_count . ' / Requested: ' . $requested_count );
                error_log( '  Errors: ' . print_r( $errors, true ) );
                error_log( '========================================' );
                wp_send_json_error( array(
                    'message' => __( 'Unable to proceed to checkout. All tickets must be available and valid.', 'asce-tm' ),
                    'errors' => $errors,
                    'added_count' => $added_count,
                    'requested_count' => $requested_count
                ) );
            }
            
            error_log( '[OK] Strict validation passed: All ' . $requested_count . ' tickets added successfully' );
            
            // All tickets added successfully - return cart/checkout URL
            // Redirect directly to checkout page where EM Pro shows booking forms
            $redirect_url = $checkout_page; // Checkout page has forms for user info collection
            
            // Log cart contents to debug log
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                error_log( '=== ASCE TM Checkout - Cart Contents ===' );
                error_log( 'Added ' . $added_count . ' tickets to cart' );
                error_log( 'Events added: ' . print_r( $events_added, true ) );
                error_log( 'Tickets requested: ' . print_r( $tickets, true ) );
                error_log( 'Redirect URL: ' . $redirect_url );
                error_log( '=========================================' );
            }
            
            $response = array(
                'message' => sprintf(
                    _n(
                        'Ticket added successfully! Redirecting to cart...',
                        '%d tickets added successfully! Redirecting to cart...',
                        $added_count,
                        'asce-tm'
                    ),
                    $added_count
                ),
                'checkout_url' => $redirect_url,
                'added_count' => $added_count,
                'events' => $events_added
            );
            
            // Add debug info only in debug mode
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $response['debug_blog_id'] = get_current_blog_id();
            }
            
            wp_send_json_success( $response );
        } else {
            // Failed to add any tickets
            // Close EM session even on failure
            EM_Multiple_Bookings::session_close();
            
            // Restore blog context if switched
            if ( $switched_blog ) {
                restore_current_blog();
            }
            
            wp_send_json_error( array(
                'message' => __( 'Unable to proceed to checkout. Please review the errors below:', 'asce-tm' ),
                'errors' => $errors,
                'added_count' => 0,
                'requested_count' => $requested_count
            ) );
        }
    }
    
    /**
     * Intelligently bypass non-critical validation for cart additions
     * 
     * This filter hook allows tickets to be added to cart without requiring booking form fields
     * (name, email, custom fields) which will be collected during the checkout process.
     * 
     * IMPORTANT: This method enforces strict validation for:
     * - Ticket availability and capacity (sold out, fully booked, etc.)
     * - Booking window status (bookings closed, registration closed)
     * - Event availability (not available, unavailable)
     * 
     * When bypassing soft validation failures (missing form fields), this method
     * clears the errors to prevent false negative feedback during cart operations.
     * 
     * @since 1.0.0
     * @param bool $valid Current validation status from Events Manager
     * @param EM_Booking $EM_Booking The booking object being validated
     * @return bool True when safe to bypass (missing form fields only), false for hard failures
     */
    public static function bypass_cart_validation( $valid, $EM_Booking ) {

        // Step 1: If Events Manager already validated successfully, don't interfere
        if ( $valid ) {
            return true;
        }

        // Step 2: NEVER bypass ticket availability/capacity validation
        // This ensures sold-out tickets cannot be added to cart
        // In EM 7.x this is typically on $EM_Booking->tickets_bookings
        if ( isset( $EM_Booking->tickets_bookings ) && is_object( $EM_Booking->tickets_bookings ) && method_exists( $EM_Booking->tickets_bookings, 'validate' ) ) {
            if ( ! $EM_Booking->tickets_bookings->validate() ) {
                return false; // Hard fail: ticket validation failed (capacity/availability issue)
            }
        }

        // Step 3: Inspect validation errors to distinguish between hard and soft failures
        // Only bypass "missing fields" style errors, not hard errors
        $errors = array();

        // Retrieve errors using the most portable method across EM versions
        // get_errors() is the preferred method in most EM versions
        if ( is_object( $EM_Booking ) && method_exists( $EM_Booking, 'get_errors' ) ) {
            $raw = $EM_Booking->get_errors();

            // Normalize errors to array format for consistent processing
            // EM may return errors as array or string depending on version/context
            if ( is_array( $raw ) ) {
                $errors = $raw;
            } elseif ( is_string( $raw ) && strlen( trim( $raw ) ) ) {
                $errors = array( $raw );
            }
        } elseif ( isset( $EM_Booking->errors ) && is_array( $EM_Booking->errors ) ) {
            // Fallback to direct property access for older EM versions
            $errors = $EM_Booking->errors;
        }

        // Conservative approach: If we can't inspect errors, assume it's unsafe to bypass
        if ( empty( $errors ) ) {
            return false;
        }

        // Step 4: Check for hard failure phrases that indicate real problems
        // These errors indicate actual business logic failures that must not be bypassed
        $hard_phrases = array(
            // Capacity / Availability errors
            'sold out',
            'fully booked',
            'no spaces',
            'not enough spaces',
            'insufficient spaces',
            'capacity',
            'event is full',
            'booking is full',

            // Booking window / Status errors
            'bookings closed',
            'booking closed',
            'booking has ended',
            'booking is closed',
            'registration closed',

            // General availability errors
            'not available',
            'unavailable',
            'online bookings are not available',
            'online bookings are not available for this event',
        );

        // Scan all errors for hard failure indicators
        foreach ( $errors as $err ) {
            // Handle both string errors and array errors
            if ( is_array( $err ) ) {
                // Flatten nested arrays recursively
                $flat_err = array();
                array_walk_recursive( $err, function( $item ) use ( &$flat_err ) {
                    if ( ! is_array( $item ) ) {
                        $flat_err[] = $item;
                    }
                });
                $msg = strtolower( wp_strip_all_tags( implode( ' ', $flat_err ) ) );
            } else {
                $msg = strtolower( wp_strip_all_tags( (string) $err ) );
            }
            foreach ( $hard_phrases as $phrase ) {
                if ( strpos( $msg, $phrase ) !== false ) {
                    // Hard failure detected - do NOT bypass this validation failure
                    return false;
                }
            }
        }

        // Step 5: Safe to bypass - these are soft failures (missing form fields)
        // Clear the errors to prevent false negative feedback during cart operations
        // This is critical: without clearing, EM may display "invalid booking" messages
        // even though we're intentionally deferring field validation to checkout
        // Note: EM_Object doesn't provide clear_errors() method, manual clearing is required
        if ( isset( $EM_Booking->errors ) ) {
            $EM_Booking->errors = array();
        }
        if ( isset( $EM_Booking->feedback_message ) ) {
            $EM_Booking->feedback_message = '';
        }

        return true;
    }
    
    /**
     * Global validation bypass check for forms-skipped checkout
     * 
     * When users bypass the custom forms step (v3.5.4+), we set a session flag
     * and this filter automatically bypasses form field validation globally.
     * This ensures the entire checkout process works without form data collection.
     * 
     * @since 3.5.5
     * @param bool $valid Current validation status
     * @param EM_Booking $EM_Booking The booking being validated
     * @return bool Validation result
     */
    public static function maybe_bypass_validation_globally( $valid, $EM_Booking ) {
        // Check if we're in bypass mode
        if ( ! session_id() ) {
            session_start();
        }
        
        if ( ! empty( $_SESSION['asce_tm_bypass_forms'] ) ) {
            // Use the existing bypass logic
            return self::bypass_cart_validation( $valid, $EM_Booking );
        }
        
        // Not in bypass mode, return original validation result
        return $valid;
    }
    
    /**
     * Get cart snapshot for checkout debugging (admin only)
     * 
     * Returns a JSON snapshot of the current EM Multiple Bookings cart/session.
     * Used by checkout debug modal to verify server-side cart state.
     * 
     * @since 2.5.6
     * @return void Sends JSON response
     */
    public static function cart_snapshot() {
        // Security: Admin only
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }
        
        // Check if Multiple Bookings is available
        if ( ! class_exists( 'EM_Multiple_Bookings' ) ) {
            wp_send_json_error( array( 'message' => 'Multiple Bookings not available' ) );
        }
        
        // Get current cart
        $EM_Multiple_Booking = EM_Multiple_Bookings::get_multiple_booking();
        $snapshot = array();
        
        $bookings = $EM_Multiple_Booking ? $EM_Multiple_Booking->get_bookings() : array();
        if ( ! empty( $bookings ) && is_array( $bookings ) ) {
            foreach ( $bookings as $event_id => $booking ) {
                $item = array(
                    'event_id' => (int) $event_id,
                    'spaces' => method_exists( $booking, 'get_spaces' ) ? (int) $booking->get_spaces() : null,
                    'errors' => method_exists( $booking, 'get_errors' ) ? $booking->get_errors() : array(),
                    'ticket_bookings' => array()
                );
                
                // Extract ticket bookings
                if ( isset( $booking->tickets_bookings ) && isset( $booking->tickets_bookings->tickets_bookings ) ) {
                    foreach ( $booking->tickets_bookings->tickets_bookings as $ticket_id => $tb ) {
                        $item['ticket_bookings'][] = array(
                            'ticket_id' => (int) $ticket_id,
                            'spaces' => is_object( $tb ) && isset( $tb->ticket_booking_spaces ) ? (int) $tb->ticket_booking_spaces : null
                        );
                    }
                }
                
                $snapshot[] = $item;
            }
        }
        
        wp_send_json_success( array( 'snapshot' => $snapshot ) );
    }
    
    /**
     * Get forms map for Step B (Forms)
     * Groups events by booking form ID and returns real EM Pro form schema
     * Includes both booking and attendee form fields
     * 
     * @since 2.5.7
     * @return void Sends JSON response
     */
    public static function get_forms_map() {
        error_log('========================================'  );
        error_log('SERVER STEP 4: GET_FORMS_MAP START');
        error_log('========================================'  );
        error_log('Timestamp: ' . current_time('mysql'));
        
        check_ajax_referer( 'asce_tm_checkout', 'nonce' );
        error_log('[OK] Nonce validated');
        
        // Get tickets from POST, or fall back to session
        $tickets = isset( $_POST['tickets'] ) ? json_decode( wp_unslash( $_POST['tickets'] ), true ) : array();
        
        error_log('--- Retrieving Tickets ---');
        error_log('  Tickets from POST: ' . (empty($tickets) ? 'NONE' : count($tickets) . ' tickets'));
        
        if ( empty( $tickets ) ) {
            error_log('  No tickets in POST, checking session...');
            // Try to load from session
            if ( ! session_id() ) {
                session_start();
            }
            $tickets = isset( $_SESSION['asce_tm_tickets'] ) ? $_SESSION['asce_tm_tickets'] : array();
            error_log('  Tickets from session: ' . (empty($tickets) ? 'NONE' : count($tickets) . ' tickets'));
        }
        
        if ( empty( $tickets ) ) {
            error_log('[FAIL] SERVER STEP 4 FAILED: No tickets found');
            error_log('========================================'  );
            wp_send_json_error( array( 'message' => __( 'No tickets selected.', 'asce-tm' ) ) );
        }
        
        error_log('[OK] Tickets retrieved: ' . count($tickets) . ' tickets');
        error_log('  Ticket details: ' . print_r($tickets, true));
        
        // Extract unique event IDs
        $event_ids = array();
        foreach ( $tickets as $ticket_data ) {
            $event_id = absint( $ticket_data['event_id'] );
            if ( $event_id > 0 ) {
                $event_ids[ $event_id ] = true;
            }
        }
        $event_ids = array_keys( $event_ids );
        
        // Deduplicate forms: collect unique booking and attendee forms separately
        $booking_forms = array(); // key = booking_form_id
        $attendee_forms = array(); // key = attendee_form_id
        $errors = array();
        
        foreach ( $event_ids as $event_id ) {
            // Load EM_Event
            $EM_Event = em_get_event( $event_id );
            if ( ! $EM_Event || ! $EM_Event->post_id ) {
                $errors[] = sprintf( __( 'Event %d not found.', 'asce-tm' ), $event_id );
                continue;
            }
            
            error_log('--- Analyzing Event ' . $event_id . ': ' . $EM_Event->event_name . ' ---');
            
            // Determine booking_form_id
            $booking_form_id = get_post_meta( $EM_Event->post_id, '_custom_booking_form', true );
            if ( empty( $booking_form_id ) || ! is_numeric( $booking_form_id ) ) {
                $booking_form_id = (int) get_option( 'em_booking_form_fields' );
                error_log('  Booking Form: Using global default (ID: ' . $booking_form_id . ')');
            } else {
                error_log('  Booking Form: Custom form (ID: ' . $booking_form_id . ')');
            }
            $booking_form_id = absint( $booking_form_id );
            
            // Determine attendee_form_id
            $attendee_form_id = get_post_meta( $EM_Event->post_id, '_custom_attendee_form', true );
            if ( empty( $attendee_form_id ) || ! is_numeric( $attendee_form_id ) ) {
                $attendee_form_id = (int) get_option( 'em_attendee_form_fields' );
                error_log('  Attendee Form: Using global default (ID: ' . $attendee_form_id . ')');
            } else {
                error_log('  Attendee Form: Custom form (ID: ' . $attendee_form_id . ')');
            }
            $attendee_form_id = absint( $attendee_form_id );
            error_log('  Attendee Form ID resolved to: ' . $attendee_form_id);
            
            // Collect unique booking form
            if ( ! isset( $booking_forms[ $booking_form_id ] ) ) {
                // Get booking form data
                $booking_form_data = self::get_em_form_data( $booking_form_id );
                
                if ( is_wp_error( $booking_form_data ) ) {
                    $errors[] = sprintf( __( 'Event %d: Booking form %d could not be loaded: %s', 'asce-tm' ), $event_id, $booking_form_id, $booking_form_data->get_error_message() );
                    continue;
                }
                
                $booking_form_name = isset( $booking_form_data['name'] ) ? $booking_form_data['name'] : __( 'Default Booking Form', 'asce-tm' );
                $booking_fields_raw = isset( $booking_form_data['form'] ) ? $booking_form_data['form'] : array();
                $booking_fields = self::normalize_form_fields( $booking_fields_raw, true );
                
                $booking_forms[ $booking_form_id ] = array(
                    'form_id' => $booking_form_id,
                    'group_id' => 'booking_' . $booking_form_id,
                    'form_type' => 'booking',
                    'booking_form_id' => $booking_form_id,
                    'form_name' => $booking_form_name,
                    'form_key' => sanitize_title( $booking_form_name ),
                    'label' => $booking_form_name,
                    'event_ids' => array(),
                    'event_names' => array(),
                    'booking_fields' => $booking_fields,
                    'attendee_fields' => array() // Empty for booking-only groups
                );
            }
            
            $booking_forms[ $booking_form_id ]['event_ids'][] = $event_id;
            $booking_forms[ $booking_form_id ]['event_names'][] = $EM_Event->event_name;
            
            // Collect unique attendee form (only if globally enabled AND has fields)
            if ( $attendee_form_id > 0 && ! isset( $attendee_forms[ $attendee_form_id ] ) ) {
                // Check if attendee forms are globally enabled in EM Pro settings
                $attendee_enabled = get_option('em_attendee_fields_enabled', false);
                
                error_log('  Attendee forms globally enabled: ' . ($attendee_enabled ? 'YES' : 'NO'));
                
                if ( $attendee_enabled ) {
                    error_log('  Fetching attendee form fields...');
                    // Get attendee form data
                    $attendee_fields = self::get_attendee_form_fields( $event_id, $booking_form_id );
                    
                    error_log('  Attendee fields retrieved: ' . count($attendee_fields) . ' fields');
                    
                    // Only create attendee form group if it actually has fields
                    if ( ! empty( $attendee_fields ) ) {
                        error_log('  ✓ Attendee form HAS fields - will be displayed');
                        $attendee_form_name = '';
                        $attendee_form_data = self::get_em_form_data_by_key( 'attendee-form', $attendee_form_id );
                        if ( ! is_wp_error( $attendee_form_data ) && isset( $attendee_form_data['name'] ) ) {
                            $attendee_form_name = $attendee_form_data['name'];
                        } else {
                            $attendee_form_name = __( 'Default Attendee Form', 'asce-tm' );
                        }
                        
                        $attendee_forms[ $attendee_form_id ] = array(
                            'form_id' => $attendee_form_id,
                            'group_id' => 'attendee_' . $attendee_form_id,
                            'form_type' => 'attendee',
                            'attendee_form_id' => $attendee_form_id,
                            'form_name' => $attendee_form_name,
                            'form_key' => sanitize_title( $attendee_form_name ),
                            'label' => $attendee_form_name,
                            'event_ids' => array(),
                            'event_names' => array(),
                            'booking_fields' => array(), // Empty for attendee-only groups
                            'attendee_fields' => $attendee_fields
                        );
                    } else {
                        error_log('  ✗ Attendee form has NO fields - will NOT be displayed');
                    }
                } else {
                    error_log('  ✗ Attendee forms disabled globally - will NOT be displayed');
                }
            } else if ( $attendee_form_id === 0 ) {
                error_log('  ✗ No attendee form configured (ID = 0)');
            }
            
            if ( $attendee_form_id > 0 && isset( $attendee_forms[ $attendee_form_id ] ) ) {
                $attendee_forms[ $attendee_form_id ]['event_ids'][] = $event_id;
                $attendee_forms[ $attendee_form_id ]['event_names'][] = $EM_Event->event_name;
            }
        }
        
        // Merge booking and attendee forms into single array
        $form_groups = array_merge( array_values( $booking_forms ), array_values( $attendee_forms ) );
        
        error_log('--- Form Generation Complete ---');
        error_log('  Form groups generated: ' . count($form_groups));
        error_log('  Booking forms: ' . count($booking_forms));
        error_log('  Attendee forms: ' . count($attendee_forms));
        
        if ( count($form_groups) > 0 ) {
            error_log('--- Form Group Details ---');
            foreach ( $form_groups as $idx => $group ) {
                error_log('  Group ' . ($idx + 1) . ':');
                error_log('    - Form Type: ' . $group['form_type']);
                error_log('    - Form Name: ' . $group['form_name']);
                error_log('    - Booking Fields: ' . count($group['booking_fields']));
                error_log('    - Attendee Fields: ' . count($group['attendee_fields']));
            }
        }
        
        if ( ! empty( $errors ) ) {
            error_log('[WARN] Errors encountered:');
            error_log(print_r($errors, true));
            error_log('[FAIL] SERVER STEP 4 FAILED: Errors in form generation');
            error_log('========================================'  );
            wp_send_json_error( array( 
                'message' => implode( ' ', $errors ),
                'errors' => $errors
            ) );
        }
        
        error_log('[OK] SERVER STEP 4 COMPLETE: Sending ' . count($form_groups) . ' form groups');
        error_log('========================================'  );
        wp_send_json_success( array( 'groups' => $form_groups ) );
    }
    
    /**
     * Get EM form data from EM_META_TABLE
     * 
     * @since 2.5.7
     * @param int $form_id Form ID (meta_id from EM_META_TABLE)
     * @return array|WP_Error Form data array or WP_Error on failure
     */
    private static function get_em_form_data( $form_id ) {
        return self::get_em_form_data_by_key( 'booking-form', $form_id );
    }
    
    /**
     * Get EM form data from EM_META_TABLE by meta_key and meta_id
     * 
     * @since 2.7.0
     * @param string $meta_key Meta key ('booking-form' or 'attendee-form')
     * @param int $form_id Form ID (meta_id from EM_META_TABLE)
     * @return array|WP_Error Form data array or WP_Error on failure
     */
    private static function get_em_form_data_by_key( $meta_key, $form_id ) {
        global $wpdb;
        
        if ( ! defined( 'EM_META_TABLE' ) ) {
            return new WP_Error( 'em_meta_table_undefined', __( 'EM_META_TABLE constant not defined.', 'asce-tm' ) );
        }
        
        // Fetch form record from EM_META_TABLE
        $sql = $wpdb->prepare(
            "SELECT meta_id, meta_value FROM " . EM_META_TABLE . " WHERE meta_key=%s AND meta_id=%d",
            $meta_key,
            $form_id
        );
        
        $result = $wpdb->get_row( $sql, ARRAY_A );
        
        if ( ! $result || empty( $result['meta_value'] ) ) {
            return new WP_Error( 'form_not_found', sprintf( __( 'Form %d not found in database.', 'asce-tm' ), $form_id ) );
        }
        
        // Unserialize form data
        $form_data = maybe_unserialize( $result['meta_value'] );
        
        if ( ! is_array( $form_data ) ) {
            return new WP_Error( 'form_data_invalid', __( 'Form data could not be unserialized.', 'asce-tm' ) );
        }
        
        return $form_data;
    }
    
    /**
     * Normalize EM form fields for frontend consumption
     * 
     * @since 2.5.7
     * @param array $fields_raw Raw form fields from EM_META_TABLE
     * @param bool $filter_captcha Whether to filter out captcha fields (default: true)
     * @return array Normalized fields schema
     */
    private static function normalize_form_fields( $fields_raw, $filter_captcha = true ) {
        if ( ! is_array( $fields_raw ) ) {
            return array();
        }
        
        $normalized = array();
        
        foreach ( $fields_raw as $fieldid => $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            
            // Filter out captcha fields if requested
            if ( $filter_captcha && self::is_captcha_field( $fieldid, $field ) ) {
                continue;
            }
            
            // Safe keys to include in JS
            $safe_field = array(
                'fieldid' => $fieldid,
                'label' => isset( $field['label'] ) ? $field['label'] : '',
                'type' => isset( $field['type'] ) ? $field['type'] : 'text',
                'required' => absint( isset( $field['required'] ) ? $field['required'] : 0 )
            );
            
            // Include options if present
            if ( isset( $field['options'] ) && ! empty( $field['options'] ) ) {
                $safe_field['options'] = $field['options'];
            }
            
            // Include options_callback if present
            if ( isset( $field['options_callback'] ) && ! empty( $field['options_callback'] ) ) {
                $safe_field['options_callback'] = $field['options_callback'];
            }
            
            // Include any options_* keys
            foreach ( $field as $key => $value ) {
                if ( strpos( $key, 'options_' ) === 0 ) {
                    $safe_field[ $key ] = $value;
                }
            }
            
            $normalized[] = $safe_field;
        }
        
        return $normalized;
    }
    
    /**
     * Check if a field is a captcha field
     * 
     * @since 2.6.4
     * @param string $fieldid Field ID
     * @param array $field Field configuration
     * @return bool True if captcha field
     */
    private static function is_captcha_field( $fieldid, $field ) {
        // Check field type
        $type = isset( $field['type'] ) ? strtolower( $field['type'] ) : '';
        if ( strpos( $type, 'captcha' ) !== false ) {
            return true;
        }
        
        // Check field label
        $label = isset( $field['label'] ) ? strtolower( $field['label'] ) : '';
        if ( strpos( $label, 'recaptcha' ) !== false || strpos( $label, 'captcha' ) !== false ) {
            return true;
        }
        
        // Check fieldid
        $fieldid_lower = strtolower( $fieldid );
        if ( strpos( $fieldid_lower, 'captcha' ) !== false || strpos( $fieldid_lower, 'recaptcha' ) !== false ) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get attendee/registration form fields for an event
     * 
     * @since 2.6.4
     * @param int $event_id Event ID
     * @param int $form_id Booking form ID (not used, kept for compatibility)
     * @return array Normalized attendee fields
     */
    private static function get_attendee_form_fields( $event_id, $form_id ) {
        global $wpdb;
        
        // Load EM_Event to get post_id
        $EM_Event = em_get_event( $event_id );
        if ( ! $EM_Event || ! $EM_Event->post_id ) {
            return array();
        }
        
        // Determine attendee_form_id from post meta or global option
        $attendee_form_id = get_post_meta( $EM_Event->post_id, '_custom_attendee_form', true );
        if ( empty( $attendee_form_id ) || ! is_numeric( $attendee_form_id ) ) {
            $attendee_form_id = (int) get_option( 'em_attendee_form_fields' );
        }
        $attendee_form_id = absint( $attendee_form_id );
        
        if ( ! $attendee_form_id ) {
            return array();
        }
        
        // Query EM_META_TABLE for meta_key='attendee-form' and meta_id=attendee_form_id
        if ( ! defined( 'EM_META_TABLE' ) ) {
            return array();
        }
        
        $sql = $wpdb->prepare(
            "SELECT meta_value FROM " . EM_META_TABLE . " WHERE meta_key='attendee-form' AND meta_id=%d",
            $attendee_form_id
        );
        
        $result = $wpdb->get_var( $sql );
        
        if ( ! $result ) {
            return array();
        }
        
        // Unserialize attendee form data
        $attendee_form_data = maybe_unserialize( $result );
        
        if ( ! is_array( $attendee_form_data ) ) {
            return array();
        }
        
        // Extract form fields array
        $attendee_fields_raw = isset( $attendee_form_data['form'] ) ? $attendee_form_data['form'] : array();
        
        // Normalize and filter captcha
        return self::normalize_form_fields( $attendee_fields_raw, true );
    }
    
    /**
     * Save forms data to session
     * Expects form_data keyed by form_id: { [form_id]: { booking: {...}, attendee: {...} } }
     * 
     * @since 2.5.7
     * @return void Sends JSON response
     */
    public static function save_forms_data() {
        check_ajax_referer( 'asce_tm_checkout', 'nonce' );
        
        // Get form data from POST
        $form_data = isset( $_POST['form_data'] ) ? json_decode( wp_unslash( $_POST['form_data'] ), true ) : array();
        $tickets = isset( $_POST['tickets'] ) ? json_decode( wp_unslash( $_POST['tickets'] ), true ) : array();
        $table_id = isset( $_POST['table_id'] ) ? sanitize_text_field( wp_unslash( $_POST['table_id'] ) ) : '';
        $blog_id = isset( $_POST['blog_id'] ) ? absint( $_POST['blog_id'] ) : get_current_blog_id();
        
        // Allow empty form_data if tickets are provided (redirect scenario)
        if ( empty( $form_data ) && empty( $tickets ) ) {
            wp_send_json_error( array( 'message' => __( 'No form data or tickets provided.', 'asce-tm' ) ) );
        }
        
        // Let EM Pro manage session lifecycle exclusively to avoid race conditions
        if ( ! class_exists( 'EM_Multiple_Bookings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Multiple Bookings not available.', 'asce-tm' ) ) );
        }
        
        EM_Multiple_Bookings::session_start();
        // EM Pro has now started PHP session internally - just use $_SESSION directly
        
        // Store form data in session (keyed by form_id)
        $_SESSION['asce_tm_form_data'] = $form_data;
        $_SESSION['asce_tm_tickets'] = $tickets;
        $_SESSION['asce_tm_table_id'] = $table_id;
        $_SESSION['asce_tm_blog_id'] = $blog_id;
        
        wp_send_json_success( array( 
            'message' => __( 'Form data saved successfully.', 'asce-tm' ),
            'form_data' => $form_data,
            'tickets' => $tickets,
            'table_id' => $table_id,
            'blog_id' => $blog_id
        ) );
    }
    
    /**
     * Get session tickets
     * Returns tickets stored in session for forms page
     */
    public static function get_session_tickets() {
        check_ajax_referer( 'asce_tm_checkout', 'nonce' );
        
        // Let EM Pro manage session lifecycle exclusively
        if ( ! class_exists( 'EM_Multiple_Bookings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Multiple Bookings not available.', 'asce-tm' ) ) );
        }
        
        EM_Multiple_Bookings::session_start();
        
        $tickets = isset( $_SESSION['asce_tm_tickets'] ) ? $_SESSION['asce_tm_tickets'] : array();
        $table_id = isset( $_SESSION['asce_tm_table_id'] ) ? $_SESSION['asce_tm_table_id'] : '';
        $blog_id = isset( $_SESSION['asce_tm_blog_id'] ) ? $_SESSION['asce_tm_blog_id'] : get_current_blog_id();
        
        if ( empty( $tickets ) ) {
            wp_send_json_error( array( 
                'message' => __( 'No tickets found in session.', 'asce-tm' )
            ) );
        }
        
        wp_send_json_success( array(
            'tickets' => $tickets,
            'table_id' => $table_id,
            'blog_id' => $blog_id
        ) );
    }
    
    /**
     * Set session tickets
     * Stores tickets in PHP session BEFORE navigating to Forms page
     * 
     * @since 2.9.0
     * @return void Sends JSON response
     */
    public static function set_session_tickets() {
        error_log('========================================'  );
        error_log('SERVER STEP 2: SET_SESSION_TICKETS START');
        error_log('========================================'  );
        error_log('Timestamp: ' . current_time('mysql'));
        
        check_ajax_referer( 'asce_tm_checkout', 'nonce' );
        error_log('[OK] Nonce validated');
        
        // Get POST data
        $table_id = isset( $_POST['table_id'] ) ? sanitize_text_field( wp_unslash( $_POST['table_id'] ) ) : '';
        $blog_id = isset( $_POST['blog_id'] ) ? absint( $_POST['blog_id'] ) : get_current_blog_id();
        $tickets = isset( $_POST['tickets'] ) ? json_decode( wp_unslash( $_POST['tickets'] ), true ) : array();
        
        error_log('--- POST Data Received ---');
        error_log('  table_id: ' . $table_id);
        error_log('  blog_id: ' . $blog_id);
        error_log('  tickets (raw): ' . $_POST['tickets']);
        
        // Normalize tickets if it's already an array
        if ( is_string( $tickets ) ) {
            $tickets = json_decode( $tickets, true );
        }
        
        error_log('  tickets (parsed): ' . print_r($tickets, true));
        error_log('  tickets count: ' . count($tickets));
        
        if ( empty( $tickets ) || ! is_array( $tickets ) ) {
            error_log('[FAIL] SERVER STEP 2 FAILED: No valid tickets');
            error_log('========================================'  );
            wp_send_json_error( array( 'message' => __( 'No tickets provided.', 'asce-tm' ) ) );
        }
        
        error_log('[OK] Tickets validation passed');
        
        // Let EM Pro manage session lifecycle exclusively
        if ( ! class_exists( 'EM_Multiple_Bookings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Multiple Bookings not available.', 'asce-tm' ) ) );
        }
        
        EM_Multiple_Bookings::session_start();
        
        // Normalize tickets array
        $normalized = array();
        foreach ( $tickets as $ticket ) {
            if ( isset( $ticket['event_id'] ) && isset( $ticket['ticket_id'] ) ) {
                $normalized[] = array(
                    'event_id' => absint( $ticket['event_id'] ),
                    'ticket_id' => absint( $ticket['ticket_id'] ),
                    'quantity' => isset( $ticket['quantity'] ) ? absint( $ticket['quantity'] ) : 1
                );
            }
        }
        
        // Store in session
        $_SESSION['asce_tm_tickets'] = $normalized;
        $_SESSION['asce_tm_table_id'] = $table_id;
        $_SESSION['asce_tm_blog_id'] = $blog_id;
        
        error_log('[OK] SERVER STEP 2 COMPLETE: Tickets saved to session');
        error_log('   Ticket count: ' . count($normalized));
        error_log('========================================'  );
        
        wp_send_json_success( array(
            'count' => count( $normalized ),
            'blog_id' => $blog_id,
            'table_id' => $table_id,
            'tickets' => $normalized
        ) );
    }
    
    /**
     * Clear session tickets
     * Called when Clear All is clicked
     */
    public static function clear_session_tickets() {
        check_ajax_referer( 'asce_tm_checkout', 'nonce' );
        
        // Let EM Pro manage session lifecycle exclusively
        if ( ! class_exists( 'EM_Multiple_Bookings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Multiple Bookings not available.', 'asce-tm' ) ) );
        }
        
        EM_Multiple_Bookings::session_start();
        
        // Clear session data
        unset( $_SESSION['asce_tm_tickets'] );
        unset( $_SESSION['asce_tm_form_data'] );
        unset( $_SESSION['asce_tm_table_id'] );
        unset( $_SESSION['asce_tm_blog_id'] );
        
        wp_send_json_success( array(
            'message' => __( 'Session cleared successfully.', 'asce-tm' )
        ) );
    }
    
    /**
     * Finalize bookings and prepare for payment
     * Creates EM_Booking records from form data and returns payment information
     * 
     * @since 2.9.15
     * @deprecated 3.0.0 This method is part of the old custom checkout flow. In v3.0.0+, use EM Pro native checkout instead.
     */
    public static function finalize_bookings() {
        check_ajax_referer( 'asce_tm_checkout', 'nonce' );
        
        // DEPRECATED WARNING
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'ASCE TM WARNING: finalize_bookings() is deprecated since v3.0.0. This method should NOT be called. You may be using cached JavaScript. Clear browser cache and hard refresh (Ctrl+Shift+F5).' );
        }
        
        // Get form data and tickets
        $form_data = isset( $_POST['form_data'] ) ? json_decode( wp_unslash( $_POST['form_data'] ), true ) : array();
        $tickets = isset( $_POST['tickets'] ) ? json_decode( wp_unslash( $_POST['tickets'] ), true ) : array();
        
        // Debug logging
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'ASCE TM finalize_bookings called (DEPRECATED - should not happen)' );
            error_log( 'Form data count: ' . count( $form_data ) );
            error_log( 'Tickets count: ' . count( $tickets ) );
        }
        
        if ( empty( $tickets ) ) {
            wp_send_json_error( array( 'message' => __( 'No tickets selected.', 'asce-tm' ) ) );
        }
        
        // Check if EM Multiple Bookings is available
        if ( ! class_exists( 'EM_Multiple_Bookings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Events Manager Pro Multiple Bookings is required.', 'asce-tm' ) ) );
        }
        
        // Start EM Pro session
        EM_Multiple_Bookings::session_start();
        
        // Verify session is actually active and usable
        if ( session_status() !== PHP_SESSION_ACTIVE && ! isset( $_SESSION ) ) {
            EM_Multiple_Bookings::session_close(); // Cleanup attempt
            wp_send_json_error( array( 'message' => __( 'Session could not be initialized. Please check PHP session configuration.', 'asce-tm' ) ) );
        }
        
        // Get or create Multiple Booking object
        // Wrap in try-catch to handle corrupted session data
        try {
            $EM_Multiple_Booking = EM_Multiple_Bookings::get_multiple_booking();
        } catch ( Throwable $e ) {
            // Session data is corrupted, clear it and create new cart
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ASCE TM: Corrupted cart detected, clearing and creating new: ' . $e->getMessage() );
            }
            
            // Clear corrupted cart using EM Pro API
            EM_Multiple_Bookings::empty_cart();
            
            // Create a fresh cart object
            $EM_Multiple_Booking = new EM_Multiple_Booking();
            
            // Save the clean cart to session to replace corrupted data
            EM_Multiple_Bookings::session_save();
        }
        
        if ( empty( $EM_Multiple_Booking ) || ! is_object( $EM_Multiple_Booking ) ) {
            EM_Multiple_Bookings::session_close();
            wp_send_json_error( array( 'message' => __( 'Could not create Multiple Booking object.', 'asce-tm' ) ) );
        }
        
        // Get existing bookings from cart
        $bookings = $EM_Multiple_Booking->get_bookings();
        
        // If cart is empty, create bookings from tickets data
        if ( empty( $bookings ) ) {
            // Recreate bookings from tickets array
            $added_count = 0;
            $errors = array();
            
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ASCE TM: Cart is empty, recreating bookings from tickets data' );
            }
            
            try {
                foreach ( $tickets as $ticket_data ) {
                    $event_id = absint( $ticket_data['event_id'] );
                    $ticket_id = absint( $ticket_data['ticket_id'] );
                    
                    if ( empty( $event_id ) || empty( $ticket_id ) ) {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( 'ASCE TM: Skipping ticket with missing IDs' );
                        }
                        continue;
                    }
                    
                    // Load event
                    $EM_Event = em_get_event( $event_id );
                    if ( ! $EM_Event || ! $EM_Event->event_id ) {
                        $errors[] = sprintf( __( 'Event %d not found.', 'asce-tm' ), $event_id );
                        continue;
                    }
                    
                    // Create booking
                    $EM_Booking = new EM_Booking();
                    $EM_Booking->event_id = $event_id;
                    $EM_Booking->booking_status = 0; // Pending
                    
                    // Add ticket to booking
                $EM_Ticket = EM_Ticket::get( $ticket_id );
                if ( ! $EM_Ticket || ! $EM_Ticket->ticket_id ) {
                    $errors[] = sprintf( __( 'Ticket %d not found for event %d.', 'asce-tm' ), $ticket_id, $event_id );
                    continue;
                }
                
                // Verify ticket belongs to this event
                if ( absint( $EM_Ticket->event_id ) !== absint( $event_id ) ) {
                    $errors[] = sprintf( __( 'Ticket %d does not belong to event %d.', 'asce-tm' ), $ticket_id, $event_id );
                    }
                    
                    // Set ticket quantity
                    $EM_Ticket_Booking = new EM_Ticket_Booking( array(
                        'ticket_id' => $ticket_id,
                        'ticket_quantity' => 1
                    ) );
                    $EM_Booking->tickets_bookings = new EM_Tickets_Bookings();
                    $EM_Booking->tickets_bookings->booking = $EM_Booking;
                    $EM_Booking->tickets_bookings->tickets_bookings[] = $EM_Ticket_Booking;
                    
                    // Validate booking
                    if ( ! $EM_Booking->validate() ) {
                        $booking_errors = $EM_Booking->get_errors();
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( 'ASCE TM: Booking validation failed: ' . print_r( $booking_errors, true ) );
                        }
                        $errors = array_merge( $errors, $booking_errors );
                        continue;
                    }
                    
                    // Add to cart
                    if ( $EM_Multiple_Booking->add_booking( $EM_Booking ) ) {
                        $added_count++;
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( 'ASCE TM: Successfully added booking for event ' . $event_id );
                        }
                    } else {
                        $booking_errors = $EM_Booking->get_errors();
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( 'ASCE TM: Failed to add booking: ' . print_r( $booking_errors, true ) );
                        }
                        $errors = array_merge( $errors, $booking_errors );
                    }
                }
            } catch ( Exception $e ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'ASCE TM: Exception in booking recreation: ' . $e->getMessage() );
                }
                EM_Multiple_Bookings::session_close();
                wp_send_json_error( array(
                    'message' => __( 'Error creating bookings: ', 'asce-tm' ) . $e->getMessage(),
                    'errors' => array( $e->getMessage() )
                ) );
            }
            
            if ( $added_count === 0 ) {
                EM_Multiple_Bookings::session_close();
                wp_send_json_error( array(
                    'message' => __( 'Could not create bookings from ticket data.', 'asce-tm' ),
                    'errors' => $errors
                ) );
            }
            
            // Persist the recreated cart via session only (do not create database records)
            EM_Multiple_Bookings::session_save();
            
            // Refresh bookings array
            $bookings = $EM_Multiple_Booking->get_bookings();
        }
        
        // Apply form data to each booking
        if ( ! empty( $form_data ) ) {
            // Extract person details from forms
            $person_data = self::extract_person_data_from_forms( $form_data );
            
            // Apply person data to Multiple Booking person object
            $EM_Multiple_Booking->get_person()->name = $person_data['name'];
            $EM_Multiple_Booking->get_person()->first_name = $person_data['first_name'];
            $EM_Multiple_Booking->get_person()->last_name = $person_data['last_name'];
            $EM_Multiple_Booking->get_person()->email = $person_data['email'];
            $EM_Multiple_Booking->get_person()->phone = $person_data['phone'];
            
            // Apply person data to each individual booking
            foreach ( $bookings as $EM_Booking ) {
                $EM_Booking->get_person()->name = $person_data['name'];
                $EM_Booking->get_person()->first_name = $person_data['first_name'];
                $EM_Booking->get_person()->last_name = $person_data['last_name'];
                $EM_Booking->get_person()->email = $person_data['email'];
                $EM_Booking->get_person()->phone = $person_data['phone'];
            }
            
            // Store form data for later use
            $_SESSION['asce_tm_form_data'] = $form_data;
        }
        
        // Validate bookings have required data
        foreach ( $bookings as $EM_Booking ) {
            if ( empty( $EM_Booking->get_person()->email ) ) {
                EM_Multiple_Bookings::session_close();
                wp_send_json_error( array( 'message' => __( 'Email is required for all bookings.', 'asce-tm' ) ) );
            }
        }
        
        // Get price summary
        $price_summary = $EM_Multiple_Booking->get_price_summary_array();
        // get_price( $format, $include_taxes, $currency_filter ) - include taxes in displayed price
        $total_price = $EM_Multiple_Booking->get_price( true, true, true );
        
        // Get booking IDs
        $booking_ids = array();
        foreach ( $EM_Multiple_Booking->get_bookings() as $EM_Booking ) {
            if ( $EM_Booking->booking_id ) {
                $booking_ids[] = $EM_Booking->booking_id;
            }
        }
        
        // Save session
        EM_Multiple_Bookings::session_save();
        EM_Multiple_Bookings::session_close();
        
        wp_send_json_success( array(
            'booking_ids' => $booking_ids,
            'total_price' => $total_price,
            'price_summary' => $price_summary,
            'message' => __( 'Bookings finalized successfully.', 'asce-tm' )
        ) );
    }
    
    /**
     * Get available payment gateways
     * Returns list of active payment gateways with their forms
     * 
     * @since 2.9.15
     * @deprecated 3.0.0 This method is part of the old custom checkout. Use EM Pro native checkout instead.
     */
    public static function get_payment_gateways() {
        check_ajax_referer( 'asce_tm_checkout', 'nonce' );
        
        // DEPRECATED WARNING
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'ASCE TM WARNING: get_payment_gateways() is deprecated since v3.0.0. This should NOT be called. Clear browser cache and hard refresh (Ctrl+Shift+F5).' );
        }
        
        // Check if EM Pro gateways are available
        if ( ! class_exists( 'EM_Gateways' ) ) {
            wp_send_json_error( array( 'message' => __( 'Payment gateways not available.', 'asce-tm' ) ) );
        }
        
        // Start EM Pro session
        $session_started = EM_Multiple_Bookings::session_start();
        if ( ! $session_started ) {
            wp_send_json_error( array( 'message' => __( 'Could not start session.', 'asce-tm' ) ) );
        }
        
        // Get Multiple Booking from session with error handling
        try {
            $EM_Multiple_Booking = EM_Multiple_Bookings::get_multiple_booking();
        } catch ( Throwable $e ) {
            // Session data is corrupted, clear and retry
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ASCE TM get_payment_gateways: Corrupted cart detected: ' . $e->getMessage() );
            }
            EM_Multiple_Bookings::empty_cart();
            EM_Multiple_Bookings::session_close();
            wp_send_json_error( array( 'message' => __( 'Cart data was corrupted. Please try adding tickets again.', 'asce-tm' ) ) );
        }
        
        if ( empty( $EM_Multiple_Booking ) || ! is_object( $EM_Multiple_Booking ) ) {
            EM_Multiple_Bookings::session_close();
            wp_send_json_error( array( 'message' => __( 'No booking found in session.', 'asce-tm' ) ) );
        }
        
        // Get active gateways
        $active_gateways = EM_Gateways::active_gateways();
        
        // Check if gateways loaded properly
        if ( $active_gateways === null || ! is_array( $active_gateways ) ) {
            EM_Multiple_Bookings::session_close();
            wp_send_json_error( array( 
                'message' => __( 'Payment gateways not available. Please ensure at least one payment gateway is enabled in Events Manager settings.', 'asce-tm' ) 
            ) );
        }
        
        $gateways_data = array();
        
        foreach ( $active_gateways as $gateway_key => $gateway ) {
            if ( is_object( $gateway ) && ! empty( $gateway->title ) ) {
                // Get gateway payment form HTML
                ob_start();
                $gateway->booking_form();
                $form_html = ob_get_clean();
                
                $gateways_data[] = array(
                    'key' => $gateway_key,
                    'title' => $gateway->title,
                    'description' => ! empty( $gateway->label ) ? $gateway->label : '',
                    'form_html' => $form_html,
                    'button_enabled' => ! empty( $gateway->button_enabled )
                );
            }
        }
        
        // Get price information
        // get_price( $format, $include_taxes, $currency_filter ) - include taxes in displayed price
        $total_price = $EM_Multiple_Booking->get_price( true, true, true );
        $price_summary = $EM_Multiple_Booking->get_price_summary_array();
        
        // Get booking information for display
        $bookings_info = array();
        foreach ( $EM_Multiple_Booking->get_bookings() as $EM_Booking ) {
            if ( $EM_Booking->booking_id ) {
                $bookings_info[] = array(
                    'booking_id' => $EM_Booking->booking_id,
                    'event_name' => $EM_Booking->get_event()->event_name,
                    'price' => $EM_Booking->get_price( true, true, true ),
                    'spaces' => $EM_Booking->get_spaces()
                );
            }
        }
        
        EM_Multiple_Bookings::session_close();
        
        wp_send_json_success( array(
            'gateways' => $gateways_data,
            'total_price' => $total_price,
            'price_summary' => $price_summary,
            'bookings' => $bookings_info,
            'currency_symbol' => ! empty( get_option( 'dbem_bookings_currency_symbol' ) ) ? get_option( 'dbem_bookings_currency_symbol' ) : '$'
        ) );
    }
    
    /**
     * Extract person data from form data
     * Helper function to map form fields to EM person fields
     * 
     * @param array $form_data Form data from frontend
     * @return array Person data
     */
    private static function extract_person_data_from_forms( $form_data ) {
        $person_data = array(
            'name' => '',
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'phone' => ''
        );
        
        // Look through form groups for common person fields
        foreach ( $form_data as $group_id => $sections ) {
            if ( isset( $sections['booking'] ) && is_array( $sections['booking'] ) ) {
                foreach ( $sections['booking'] as $field_id => $value ) {
                    $field_id_lower = strtolower( $field_id );
                    
                    // Match common field patterns
                    if ( strpos( $field_id_lower, 'email' ) !== false || strpos( $field_id_lower, 'user_email' ) !== false ) {
                        $person_data['email'] = $value;
                    } elseif ( strpos( $field_id_lower, 'first' ) !== false && strpos( $field_id_lower, 'name' ) !== false ) {
                        $person_data['first_name'] = $value;
                    } elseif ( strpos( $field_id_lower, 'last' ) !== false && strpos( $field_id_lower, 'name' ) !== false ) {
                        $person_data['last_name'] = $value;
                    } elseif ( strpos( $field_id_lower, 'phone' ) !== false || strpos( $field_id_lower, 'tel' ) !== false ) {
                        $person_data['phone'] = $value;
                    } elseif ( strpos( $field_id_lower, 'name' ) !== false && empty( $person_data['name'] ) ) {
                        $person_data['name'] = $value;
                    }
                }
            }
        }
        
        // Build full name if not provided
        if ( empty( $person_data['name'] ) && ! empty( $person_data['first_name'] ) ) {
            $person_data['name'] = trim( $person_data['first_name'] . ' ' . $person_data['last_name'] );
        }
        
        return $person_data;
    }
    
    /**
     * Load payment gateway directly
     * 
     * v5.0.0: Loads specific payment gateway without requiring MB Mode
     * This allows matrix checkout to use gateways while MB Mode stays OFF
     * 
     * @param string $gateway_name Gateway identifier (stripe, stripe_elements, offline)
     * @return bool True if gateway loaded successfully, false otherwise
     */
    private static function load_payment_gateway( $gateway_name ) {
        global $EM_Gateways;
        
        error_log( '[v5.0.0] Attempting to load gateway: ' . $gateway_name );
        
        // Initialize EM_Gateways array if not exists
        if ( ! isset( $EM_Gateways ) ) {
            $EM_Gateways = array();
            error_log( '[v5.0.0] Initialized $EM_Gateways array' );
        }
        
        // Check if gateway already loaded
        if ( isset( $EM_Gateways[ $gateway_name ] ) ) {
            error_log( '[v5.0.0] Gateway ' . $gateway_name . ' already loaded' );
            return true;
        }
        
        $gateway_loaded = false;
        
        switch ( $gateway_name ) {
            case 'stripe':
                // Load Stripe gateway from EM Pro Stripe plugin
                $stripe_file = WP_PLUGIN_DIR . '/events-manager-pro-stripe/gateway.stripe.php';
                error_log( '[v5.0.0] Looking for Stripe gateway at: ' . $stripe_file );
                error_log( '[v5.0.0] File exists: ' . ( file_exists( $stripe_file ) ? 'YES' : 'NO' ) );
                
                if ( file_exists( $stripe_file ) ) {
                    include_once( $stripe_file );
                    error_log( '[v5.0.0] File included. Class exists: ' . ( class_exists( 'EM_Gateway_Stripe' ) ? 'YES' : 'NO' ) );
                    
                    if ( class_exists( 'EM_Gateway_Stripe' ) ) {
                        // Initialize gateway
                        EM_Gateway_Stripe::init();
                        $gateway_loaded = true;
                        error_log( '[v5.0.0] Stripe gateway loaded and initialized' );
                    }
                } else {
                    error_log( '[v5.0.0] Stripe file not found. Checking WP_PLUGIN_DIR: ' . WP_PLUGIN_DIR );
                }
                break;
                
            case 'stripe_elements':
                // Load Stripe Elements gateway from EM Pro Stripe plugin
                $stripe_elements_file = WP_PLUGIN_DIR . '/events-manager-pro-stripe/gateway.stripe-elements.php';
                error_log( '[v5.0.0] Looking for Stripe Elements gateway at: ' . $stripe_elements_file );
                error_log( '[v5.0.0] File exists: ' . ( file_exists( $stripe_elements_file ) ? 'YES' : 'NO' ) );
                
                if ( file_exists( $stripe_elements_file ) ) {
                    // Get classes before include
                    $classes_before = get_declared_classes();
                    
                    include_once( $stripe_elements_file );
                    
                    // Get classes after include
                    $classes_after = get_declared_classes();
                    $new_classes = array_diff( $classes_after, $classes_before );
                    error_log( '[v5.0.0] File included. New classes defined: ' . ( count( $new_classes ) > 0 ? implode( ', ', $new_classes ) : 'NONE' ) );
                    error_log( '[v5.0.0] Class EM_Gateway_Stripe_Elements exists: ' . ( class_exists( 'EM_Gateway_Stripe_Elements' ) ? 'YES' : 'NO' ) );
                    
                    // Try alternate class names
                    $possible_classes = array(
                        'EM_Gateway_Stripe_Elements',
                        'EM_Gateway_Stripe_Element',
                        'Gateway_Stripe_Elements',
                        'Stripe_Elements_Gateway'
                    );
                    
                    foreach ( $possible_classes as $class_name ) {
                        if ( class_exists( $class_name ) ) {
                            error_log( '[v5.0.0] Found gateway class: ' . $class_name );
                            // Try to initialize
                            if ( method_exists( $class_name, 'init' ) ) {
                                call_user_func( array( $class_name, 'init' ) );
                                $gateway_loaded = true;
                                error_log( '[v5.0.0] Gateway initialized via ' . $class_name . '::init()' );
                            } else {
                                error_log( '[v5.0.0] Class ' . $class_name . ' exists but has no init() method' );
                            }
                            break;
                        }
                    }
                    
                    if ( ! $gateway_loaded ) {
                        error_log( '[v5.0.0] Could not find or initialize gateway class' );
                    }
                } else {
                    error_log( '[v5.0.0] Stripe Elements file not found. Checking WP_PLUGIN_DIR: ' . WP_PLUGIN_DIR );
                    // List available files in that directory
                    $plugin_dir = WP_PLUGIN_DIR . '/events-manager-pro-stripe';
                    if ( is_dir( $plugin_dir ) ) {
                        $files = scandir( $plugin_dir );
                        error_log( '[v5.0.0] Files in events-manager-pro-stripe: ' . implode( ', ', $files ) );
                    } else {
                        error_log( '[v5.0.0] Directory does not exist: ' . $plugin_dir );
                    }
                }
                break;
                
            case 'offline':
                // Load offline gateway from EM Pro
                $em_pro_path = defined('EMP_DIR') ? EMP_DIR : WP_PLUGIN_DIR . '/events-manager-pro';
                $offline_file = trailingslashit( $em_pro_path ) . 'add-ons/gateways/gateway.offline.php';
                error_log( '[v5.0.0] Looking for Offline gateway at: ' . $offline_file );
                error_log( '[v5.0.0] File exists: ' . ( file_exists( $offline_file ) ? 'YES' : 'NO' ) );
                
                if ( file_exists( $offline_file ) ) {
                    include_once( $offline_file );
                    error_log( '[v5.0.0] File included. Class exists: ' . ( class_exists( 'EM_Gateway_Offline' ) ? 'YES' : 'NO' ) );
                    
                    if ( class_exists( 'EM_Gateway_Offline' ) ) {
                        // Initialize gateway
                        EM_Gateway_Offline::init();
                        $gateway_loaded = true;
                        error_log( '[v5.0.0] Offline gateway loaded and initialized' );
                    }
                }
                break;
        }
        
        error_log( '[v5.0.0] Gateway ' . $gateway_name . ' load result: ' . ( $gateway_loaded ? 'SUCCESS' : 'FAILED' ) );
        return $gateway_loaded;
    }
    
    /**
     * Filter active gateways to show only selected gateway from table
     * v5.0.5: Filters gateways when user has table-specific gateway preference
     * 
     * @param array $gateways Active gateways array
     * @return array Filtered gateways array
     */
    public static function filter_active_gateways( $gateways ) {
        // Only filter if we have a session with gateway preference
        if ( empty( $_SESSION['asce_tm_selected_gateway'] ) ) {
            return $gateways;
        }
        
        $selected_gateway = $_SESSION['asce_tm_selected_gateway'];
        error_log( '[v5.0.5] Filtering gateways to show only: ' . $selected_gateway );
        
        // If selected gateway exists in active gateways, return only that one
        if ( isset( $gateways[ $selected_gateway ] ) ) {
            error_log( '[v5.0.5] Gateway ' . $selected_gateway . ' found, filtering others' );
            return array( $selected_gateway => $gateways[ $selected_gateway ] );
        }
        
        error_log( '[v5.0.5] Gateway ' . $selected_gateway . ' not found in active gateways' );
        return $gateways;
    }
}
