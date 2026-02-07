<?php
/*
 * ASCE Ticket Matrix → Stepper Checkout (Custom Frontend, EM Multiple Bookings backend)
 * Environment: WP Multisite + Events Manager Pro MB/cart enabled. DO NOT edit EM core.
 * We keep EM MB/cart/payment as backend source of truth, but we replace EM checkout UX with our own stepper frontend.
 * Steps: (1) Tickets (matrix) (2) Forms (dedupe forms by form name; include booking+attendee fields; omit recaptcha) (3) Payment (later) (4) Success (later).
 * Incremental rule: Do not revert prior working features. Make surgical edits only. Prefer additive changes. Preserve exclusive group logic and ticket selection UI.
 * Debug visibility: admin-only, behind isAdmin.
 */
/**
 * ASCE Ticket Matrix Display Class
 * 
 * Handles the shortcode and frontend matrix display.
 * Responsible for rendering ticket matrices, managing caching,
 * and optimizing database queries for performance.
 * 
 * @package ASCE_Ticket_Matrix
 * @version 2.9.3
 * @since 1.0.0
 */

class ASCE_TM_Matrix {
    
    // Track if assets have been enqueued to prevent duplicates
    private static $assets_enqueued = false;
    
    // Store table data for enqueuing
    private static $table_id = '';
    private static $blog_id = 0;
    
    public static function init() {
        add_shortcode( 'asce_ticket_matrix', array( __CLASS__, 'render_shortcode' ) );
        add_shortcode( 'asce_tm_forms', array( __CLASS__, 'render_forms_only_shortcode' ) );
    }
    
    /**
     * Get Events Manager maximum spaces per booking setting
     * 
     * Checks multiple option keys in order of precedence.
     * Returns 0 if unlimited (no limit set).
     * 
     * @return int Maximum spaces per booking, or 0 for unlimited
     */
    private static function get_max_spaces_per_booking() {
        // Check primary option key (EM Pro 3.7+)
        $max_spaces = absint( get_option( 'dbem_booking_feedback_spaces_limit', 0 ) );
        if ( $max_spaces > 0 ) {
            return $max_spaces;
        }
        
        // Fallback to legacy option keys
        $max_spaces = absint( get_option( 'dbem_bookings_max_spaces', 0 ) );
        if ( $max_spaces > 0 ) {
            return $max_spaces;
        }
        
        $max_spaces = absint( get_option( 'dbem_booking_spaces_limit', 0 ) );
        return $max_spaces; // 0 = unlimited
    }
    
    /**
     * Enqueue frontend assets for both Matrix and Forms pages
     * Shared method ensures consistent asset loading across all shortcodes
     * 
     * Handles both early (before wp_enqueue_scripts) and late (during shortcode rendering) calls.
     * WordPress will automatically move late-enqueued scripts to footer.
     * 
     * @param string $table_id Table identifier
     * @param int $blog_id Blog ID for multisite
     */
    private static function enqueue_tm_assets( $table_id = '', $blog_id = 0 ) {
        // Prevent duplicate enqueuing
        if ( self::$assets_enqueued ) {
            return;
        }
        self::$assets_enqueued = true;
        
        // Store for later use
        self::$table_id = $table_id;
        self::$blog_id = $blog_id;
        
        // Check if we're being called after wp_enqueue_scripts hook has already fired
        // This happens when shortcodes are rendered (during the_content filter)
        $late_enqueue = did_action( 'wp_enqueue_scripts' );
        
        // For late enqueueing, we need to ensure scripts/styles go in the footer
        // WordPress handles this automatically when we pass true to the $in_footer parameter
        
        // Enqueue styles
        wp_enqueue_style(
            'asce-tm-styles',
            ASCE_TM_PLUGIN_URL . 'assets/css/ticket-matrix.css',
            array(),
            ASCE_TM_VERSION
        );
        
        // Enqueue stepper styles
        wp_enqueue_style(
            'asce-tm-stepper',
            ASCE_TM_PLUGIN_URL . 'assets/css/stepper.css',
            array(),
            ASCE_TM_VERSION
        );
        
        // Enqueue scripts (always in footer for better performance)
        wp_enqueue_script(
            'asce-tm-scripts',
            ASCE_TM_PLUGIN_URL . 'assets/js/ticket-matrix.js',
            array( 'jquery' ),
            ASCE_TM_VERSION,
            true  // Load in footer
        );
        
        // Add jQuery alias shim only if $ is undefined (compatibility for themes)
        wp_add_inline_script(
            'jquery-core',
            'if (typeof window.$ === "undefined") { window.$ = window.jQuery; }',
            'after'
        );
        
        // Get current step from URL params
        $current_step = isset( $_GET['step'] ) ? sanitize_text_field( $_GET['step'] ) : 'tickets';
        
        // Get forms page URL if table exists
        $forms_page_url = '';
        if ( ! empty( $table_id ) ) {
            $tables = get_option( 'asce_tm_tables', array() );
            if ( isset( $tables[ $table_id ] ) ) {
                // Check for URL first (backward compatibility)
                if ( ! empty( $tables[ $table_id ]['forms_page_url'] ) ) {
                    $forms_page_url = $tables[ $table_id ]['forms_page_url'];
                } elseif ( ! empty( $tables[ $table_id ]['forms_page_id'] ) ) {
                    $forms_page_id = absint( $tables[ $table_id ]['forms_page_id'] );
                    $forms_page_url = $forms_page_id ? get_permalink( $forms_page_id ) : '';
                }
            }
        }
        
        // Localize script with AJAX URL and nonce
        $cart_id = absint( get_option( 'dbem_multiple_bookings_cart_page' ) );
        $checkout_id = absint( get_option( 'dbem_multiple_bookings_checkout_page' ) );
        
        wp_localize_script(
            'asce-tm-scripts',
            'asceTM',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'asce_tm_checkout' ),
                'blogId' => $blog_id ? $blog_id : get_current_blog_id(),
                'tableId' => $table_id,
                'currentStep' => $current_step,
                'formsPageUrl' => $forms_page_url,
                'cartPage' => $cart_id ? wp_make_link_relative( get_permalink( $cart_id ) ) : '',
                'checkoutPage' => $checkout_id ? wp_make_link_relative( get_permalink( $checkout_id ) ) : '',
                'isAdmin' => ( is_user_logged_in() && current_user_can( 'manage_options' ) ),
                'debug' => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( isset( $_GET['asce_tm_debug'] ) && $_GET['asce_tm_debug'] == '1' ),
                'strings' => array(
                    'addingToCart' => __( 'Adding to cart...', 'asce-tm' ),
                    'addedToCart' => __( 'Added to cart!', 'asce-tm' ),
                    'error' => __( 'An error occurred. Please try again.', 'asce-tm' ),
                    'selectTickets' => __( 'Please select at least one ticket.', 'asce-tm' ),
                    'viewCart' => __( 'View Cart', 'asce-tm' ),
                    'checkout' => __( 'Continue to Checkout', 'asce-tm' ),
                )
            )
        );
    }
    
    /**
     * Render stepper markup
     * Centralized method ensures consistent stepper HTML across all pages
     * 
     * @param int $active_step Active step number (1=tickets, 2=forms, 3=payment, 4=complete)
     * @return string Stepper HTML
     */
    private static function render_stepper( $active_step = 1 ) {
        $steps = array(
            1 => __( 'Select Tickets', 'asce-tm' ),
            // 2 => __( 'Booking Forms', 'asce-tm' ), // Hidden in v3.5.3 - bypassing custom forms
            2 => __( 'Payment', 'asce-tm' ),
            3 => __( 'Complete', 'asce-tm' )
        );
        
        // Always show stepper in v3.0+
        $stepper_classes = array( 'asce-tm-stepper', 'asce-tm-stepper--visible' );
        
        ob_start();
        ?>
        <div class="<?php echo esc_attr( implode( ' ', $stepper_classes ) ); ?>">
            <?php foreach ( $steps as $step_num => $step_label ) : ?>
                <?php 
                $step_classes = array( 'asce-tm-step' );
                if ( $step_num === $active_step ) {
                    $step_classes[] = 'active';
                } elseif ( $step_num < $active_step ) {
                    $step_classes[] = 'completed';
                }
                ?>
                <div class="<?php echo esc_attr( implode( ' ', $step_classes ) ); ?>" data-step="<?php echo esc_attr( $step_num ); ?>">
                    <span class="asce-tm-step-number"><?php echo esc_html( $step_num ); ?></span>
                    <span class="asce-tm-step-label"><?php echo esc_html( $step_label ); ?></span>
                    <?php if ( $step_num < count( $steps ) ) : ?>
                        <span class="asce-tm-step-connector"></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Check if enough memory is available
     */
    private static function check_memory() {
        $memory_limit = ini_get( 'memory_limit' );
        
        // Handle unlimited memory (-1)
        if ( $memory_limit == -1 || $memory_limit === '-1' ) {
            return true; // Unlimited memory, always return true
        }
        
        $memory_usage = memory_get_usage( true );
        
        // Convert memory limit to bytes
        $limit_bytes = self::convert_to_bytes( $memory_limit );
        
        // Check if we have at least required MB available
        $available = $limit_bytes - $memory_usage;
        return $available > ( ASCE_TM_MIN_MEMORY_MB * 1024 * 1024 );
    }
    
    /**
     * Convert PHP memory limit to bytes
     */
    private static function convert_to_bytes( $value ) {
        $value = trim( $value );
        
        // Handle unlimited memory
        if ( $value == -1 || $value === '-1' ) {
            return -1;
        }
        
        $last = strtolower( $value[ strlen( $value ) - 1 ] );
        $value = (int) $value;
        
        switch ( $last ) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }
        
        return $value;
    }
    
    /**
     * Render the ticket matrix shortcode
     */
    public static function render_shortcode( $atts ) {
        // CRITICAL: Force cart mode FIRST before anything else
        // This ensures EM Multiple Bookings classes work regardless of global setting
        add_filter( 'option_dbem_multiple_bookings', '__return_true', 999 );
        add_filter( 'pre_option_dbem_multiple_bookings', '__return_true', 999 );
        
        // Parse attributes first to get table_id
        $atts = shortcode_atts( array(
            'id' => '',
            'cache' => 'yes',
            'action_type' => 'cart'
        ), $atts, 'asce_ticket_matrix' );
        
        $table_id = sanitize_key( $atts['id'] );
        
        // Enqueue assets when shortcode is rendered
        // This happens during content parsing, which is after wp_enqueue_scripts hook
        // WordPress will add these to footer automatically (did_action check prevents warnings)
        self::enqueue_tm_assets( $table_id, get_current_blog_id() );
        
        // Validate table ID
        if ( empty( $table_id ) ) {
            return '<div class="asce-tm-notice asce-tm-error">' . 
                   __( 'Please specify a table ID. Example: [asce_ticket_matrix id="table_xxx"]', 'asce-tm' ) . 
                   '</div>';
        }
        
        // Check cache if enabled
        // Note: For ticketing, shorter cache prevents stale availability (recommended: 2-5 min)
        $use_cache = ( $atts['cache'] !== 'no' ) && ! is_user_logged_in(); // Don't cache for logged-in users
        if ( $use_cache ) {
            $cache_key = 'asce_tm_table_html_' . $table_id;
            $cached_html = get_transient( $cache_key );
            if ( false !== $cached_html ) {
                return $cached_html . '<!-- cached -->';
            }
        }
        
        // Get tables
        $tables = get_option( 'asce_tm_tables', array() );
        if ( ! isset( $tables[ $table_id ] ) ) {
            return '<div class="asce-tm-notice asce-tm-error">' . 
                   __( 'Table not found. Please check your shortcode ID.', 'asce-tm' ) . 
                   '</div>';
        }
        
        $table = $tables[ $table_id ];
        
        // Add table_id to the table array for use in rendering
        $table['table_id'] = $table_id;
        
        // Add action_type to table array (validate to 'cart' or 'checkout')
        $action_type = in_array( $atts['action_type'], array( 'cart', 'checkout' ), true ) ? $atts['action_type'] : 'cart';
        $table['action_type'] = $action_type;
        
        // Check available memory
        if ( ! self::check_memory() ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ASCE Ticket Matrix: Low memory warning when rendering table ' . $table_id );
            }
            
            return '<div class="asce-tm-notice asce-tm-error">' . 
                   __( 'Server memory is too low to render this table. Please contact the site administrator.', 'asce-tm' ) . 
                   '</div>';
        }
        
        // Cart mode filter already applied at start of function
        // Keeping this comment for clarity
        
        // Wrap rendering in try-catch for better error handling
        try {
            ob_start();
            self::render_table( $table );
            $html = ob_get_clean();
        } catch ( Exception $e ) {
            // Log the error if debug is enabled
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ASCE Ticket Matrix rendering error: ' . $e->getMessage() );
            }
            
            return '<div class="asce-tm-notice asce-tm-error">' . 
                   __( 'Error rendering ticket matrix. Please contact the site administrator.', 'asce-tm' ) . 
                   '</div>';
        }
        
        // Cache the output (if enabled)
        // Default: 3 minutes (balance between performance and real-time availability)
        // Filter allows customization: apply_filters('asce_tm_cache_duration', ASCE_TM_CACHE_DURATION, $table_id)
        // For high-traffic with slow sales: increase to 5-10 minutes
        // For fast-selling events: decrease to 1-2 minutes or disable (cache="no")
        $cache_time = apply_filters( 'asce_tm_cache_duration', ASCE_TM_CACHE_DURATION, $table_id );
        if ( $use_cache && $cache_time > 0 ) {
            set_transient( 'asce_tm_table_html_' . $table_id, $html, $cache_time );
        }
        
        return $html;
    }
    
    /**
     * Render forms-only shortcode for Event Forms page
     * Displays Step B (forms) without the ticket selection table
     */
    public static function render_forms_only_shortcode( $atts ) {
        // Get table_id from URL parameter
        $table_id = isset( $_GET['table_id'] ) ? sanitize_text_field( $_GET['table_id'] ) : '';
        
        if ( empty( $table_id ) ) {
            return '<div class="asce-tm-notice asce-tm-warning">' . 
                   __( 'No table selected. Please select tickets first.', 'asce-tm' ) . 
                   '</div>';
        }
        
        // Get table config
        $tables = get_option( 'asce_tm_tables', array() );
        if ( ! isset( $tables[ $table_id ] ) ) {
            return '<div class="asce-tm-notice asce-tm-error">' . 
                   __( 'Table not found. Please check your selection.', 'asce-tm' ) . 
                   '</div>';
        }
        
        $table = $tables[ $table_id ];
        
        // Enqueue assets when shortcode is rendered
        self::enqueue_tm_assets( $table_id, get_current_blog_id() );
        
        // Get forms page URL from settings (check URL first, then ID)
        $forms_page_url = '';
        if ( ! empty( $table['forms_page_url'] ) ) {
            $forms_page_url = $table['forms_page_url'];
        } elseif ( ! empty( $table['forms_page_id'] ) ) {
            $forms_page_id = absint( $table['forms_page_id'] );
            $forms_page_url = $forms_page_id ? get_permalink( $forms_page_id ) : '';
        }
        
        // Build instance config
        $instance_config = array(
            'tableId' => $table_id,
            'formsPageUrl' => $forms_page_url,
            'formsPageId' => ! empty( $table['forms_page_id'] ) ? absint( $table['forms_page_id'] ) : 0
        );
        $instance_config_json = wp_json_encode( $instance_config );
        
        // Render minimal wrapper for forms-only view
        ob_start();
        ?>
        <div class="asce-tm-instance asce-tm-forms-only" data-config="<?php echo esc_attr( $instance_config_json ); ?>">
            <?php echo self::render_stepper( 2 ); ?>
            
            <!-- Hidden container for table_id reference -->
            <div class="asce-ticket-matrix-container" data-table-id="<?php echo esc_attr( $table_id ); ?>" style="display:none;"></div>
            
            <!-- Panel B: Forms -->
            <div class="asce-tm-panel asce-tm-panel-forms active" data-panel="2">
                <div class="asce-tm-forms-panel">
                    <p class="asce-tm-forms-loading"><?php _e( 'Loading forms...', 'asce-tm' ); ?></p>
                </div>
                <div class="asce-tm-step-nav">
                    <button type="button" class="asce-tm-btn-prev"><?php _e( 'Back to Tickets', 'asce-tm' ); ?></button>
                    <button type="button" class="asce-tm-btn-next"><?php _e( 'Save & Continue', 'asce-tm' ); ?></button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render a ticket matrix table for preview (public method for admin)
     */
    public static function render_table_preview( $table ) {
        self::render_table( $table );
    }
    
    /**
     * Clear HTML cache for a specific table or all tables
     * Called automatically when bookings are made
     */
    public static function clear_table_cache( $table_id = null ) {
        global $wpdb;
        
        if ( $table_id ) {
            // Clear specific table cache (fast, single-key deletion)
            delete_transient( 'asce_tm_table_html_' . $table_id );
        } else {
            // Clear all table caches
            // OPTIMIZATION: Fetch keys first, then delete in batches (safer on large tables)
            $keys = $wpdb->get_col(
                "SELECT option_name FROM {$wpdb->options} 
                 WHERE option_name LIKE '\_transient\_asce\_tm\_table\_html\_%' 
                 OR option_name LIKE '\_transient\_timeout\_asce\_tm\_table\_html\_%'
                 LIMIT 500"
            );
            
            if ( ! empty( $keys ) ) {
                // Delete in batches to avoid long locks
                $batch_size = 50;
                $batches = array_chunk( $keys, $batch_size );
                
                foreach ( $batches as $batch ) {
                    $placeholders = implode( ',', array_fill( 0, count( $batch ), '%s' ) );
                    $wpdb->query(
                        $wpdb->prepare(
                            "DELETE FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
                            $batch
                        )
                    );
                }
            }
        }
    }
    
    /**
     * Render a ticket matrix table
     */
    private static function render_table( $table ) {
        global $wpdb;
        
        // Extract table_id for use in container markup
        $table_id = ! empty( $table['table_id'] ) ? $table['table_id'] : '';
        
        // Extract action_type for button behavior
        $action_type = ! empty( $table['action_type'] ) ? $table['action_type'] : 'cart';
        
        // Early bailout if no events configured
        if ( empty( $table['events'] ) ) {
            echo '<div class="asce-tm-notice">' . __( 'No events configured for this table.', 'asce-tm' ) . '</div>';
            return;
        }
        
        // Check for reasonable limits to prevent memory issues
        $max_events = apply_filters( 'asce_tm_max_events', ASCE_TM_MAX_EVENTS );
        $max_columns = apply_filters( 'asce_tm_max_columns', ASCE_TM_MAX_COLUMNS );
        
        if ( count( $table['events'] ) > $max_events || count( $table['columns'] ) > $max_columns ) {
            echo '<div class="asce-tm-notice asce-tm-error">' . 
                 sprintf( __( 'Table too large. Maximum %d events and %d columns allowed.', 'asce-tm' ), $max_events, $max_columns ) . 
                 '</div>';
            return;
        }
        
        // Pre-load all event objects AND tickets to reduce queries
        $event_objects = array();
        $ticket_objects = array();
        $event_ids = array();
        
        // Load events using EM event IDs (from em_events table)
        // The stored event_config['event_id'] is the EM event_id, not wp_posts.ID
        foreach ( $table['events'] as $idx => $event_config ) {
            if ( ! empty( $event_config['event_id'] ) ) {
                $event_id = absint( $event_config['event_id'] );
                // em_get_event() by default expects EM event_id
                $event = em_get_event( $event_id );
                if ( $event && $event->event_id ) {
                    $event_objects[ $idx ] = $event;
                    $event_ids[] = $event_id;
                }
            }
        }
        
        // Pre-load all tickets used in the table
        $ticket_ids = array();
        foreach ( $table['columns'] as $column ) {
            if ( ! empty( $column['tickets'] ) ) {
                $ticket_ids = array_merge( $ticket_ids, array_values( $column['tickets'] ) );
            }
        }
        $ticket_ids = array_unique( array_filter( $ticket_ids ) );
        
        // Load tickets via Events Manager API for proper object hydration
        if ( ! empty( $ticket_ids ) ) {
            foreach ( $ticket_ids as $tid ) {
                $tid = absint( $tid );
                if ( ! $tid ) continue;
                // Use EM API to ensure ticket object is properly loaded with ticket_id
                $t = function_exists( 'em_get_ticket' ) ? em_get_ticket( $tid ) : new EM_Ticket( $tid );
                if ( $t && ! empty( $t->ticket_id ) ) {
                    $ticket_objects[ $tid ] = $t;
                }
            }
        }
        
        // Get global booking limit from Events Manager settings
        // This applies to all events (EM doesn't support per-event limits in this context)
        $booking_limit_global = self::get_max_spaces_per_booking();
        
        // PERFORMANCE: Pre-compute ticket availability for all tickets in bulk
        // Instead of calling $ticket->get_available_spaces() 180+ times (expensive),
        // fetch all booked/reserved counts in 1-2 queries and compute availability once per ticket
        $ticket_availability = array();
        if ( ! empty( $ticket_ids ) ) {
            // Get booked spaces for all tickets in one query
            $booked_spaces = array();
            $reserved_spaces = array();
            
            // Sanitize and filter ticket IDs (remove empty/invalid values)
            $ticket_ids = array_values( array_filter( array_map( 'absint', $ticket_ids ) ) );
            
            // Guard against empty ticket IDs to avoid SQL errors
            if ( ! empty( $ticket_ids ) ) {
                // Build placeholders for prepared statement (multisite-safe)
                $placeholders = implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) );
                
                // Use EM constants for multisite global table support
                $tickets_bookings_table = EM_TICKETS_BOOKINGS_TABLE;
                $bookings_table = EM_BOOKINGS_TABLE;
                
                // Bulk query for booked spaces (approved bookings)
                $booked_sql = $wpdb->prepare(
                    "SELECT tb.ticket_id, SUM(tb.ticket_booking_spaces) as total_booked
                    FROM $tickets_bookings_table tb
                    INNER JOIN $bookings_table b ON tb.booking_id = b.booking_id
                    WHERE tb.ticket_id IN ($placeholders)
                    AND b.booking_status IN (1)
                    GROUP BY tb.ticket_id",
                    ...$ticket_ids
                );
                $booked_results = $wpdb->get_results( $booked_sql, ARRAY_A );
                foreach ( $booked_results as $row ) {
                    $booked_spaces[ $row['ticket_id'] ] = absint( $row['total_booked'] );
                }
                
                // Bulk query for reserved/pending spaces (status 0 = pending/reserved)
                $reserved_sql = $wpdb->prepare(
                    "SELECT tb.ticket_id, SUM(tb.ticket_booking_spaces) as total_reserved
                    FROM $tickets_bookings_table tb
                    INNER JOIN $bookings_table b ON tb.booking_id = b.booking_id
                    WHERE tb.ticket_id IN ($placeholders)
                    AND b.booking_status IN (0)
                    GROUP BY tb.ticket_id",
                    ...$ticket_ids
                );
                $reserved_results = $wpdb->get_results( $reserved_sql, ARRAY_A );
                foreach ( $reserved_results as $row ) {
                    $reserved_spaces[ $row['ticket_id'] ] = absint( $row['total_reserved'] );
                }
            }
            
            // Compute available spaces for each ticket (ticket_spaces - booked - reserved)
            foreach ( $ticket_ids as $ticket_id ) {
                $ticket = $ticket_objects[ $ticket_id ] ?? null;
                if ( ! $ticket ) {
                    $ticket_availability[ $ticket_id ] = 0;
                    continue;
                }
                
                $ticket_spaces = $ticket->get_spaces(); // Max capacity
                $booked = $booked_spaces[ $ticket_id ] ?? 0;
                $reserved = $reserved_spaces[ $ticket_id ] ?? 0;
                
                // Calculate ticket-level availability
                $ticket_available = $ticket_spaces - $booked - $reserved;
                
                // Also consider event-level availability (event might be sold out)
                $event = $ticket->get_event();
                if ( $event && $event->event_id ) {
                    $event_bookings = $event->get_bookings();
                    $event_available = $event_bookings ? $event_bookings->get_available_spaces() : 0;
                    // Use the lesser of ticket or event availability
                    $ticket_availability[ $ticket_id ] = min( $ticket_available, $event_available );
                } else {
                    $ticket_availability[ $ticket_id ] = $ticket_available;
                }
                
                // Ensure non-negative
                if ( $ticket_availability[ $ticket_id ] < 0 ) {
                    $ticket_availability[ $ticket_id ] = 0;
                }
            }
        }
        
        // Get forms_page_url from table settings (default to empty string)
        $forms_page_url = isset( $table['forms_page_url'] ) ? esc_url( $table['forms_page_url'] ) : '';
        
        // Build per-instance config for JS
        $instance_config = array(
            'tableId' => $table_id,
            'tableName' => isset( $table['name'] ) ? $table['name'] : '',
            'actionType' => $action_type,
            'formsPageUrl' => $forms_page_url
        );
        $instance_config_json = wp_json_encode( $instance_config );
        ?>
        <div class="asce-tm-instance" data-config="<?php echo esc_attr( $instance_config_json ); ?>">
        <?php echo self::render_stepper( 1 ); ?>
        
        <!-- Panel A: Ticket Selection -->
        <div class="asce-tm-panel asce-tm-panel-tickets active" data-panel="1">
        <div class="asce-ticket-matrix-container" 
             data-table-id="<?php echo esc_attr( $table_id ); ?>"
             data-table-name="<?php echo esc_attr( $table['name'] ); ?>"
             data-action-type="<?php echo esc_attr( $action_type ); ?>">
            <div class="asce-tm-matrix-wrapper">
                <table class="asce-tm-matrix-table">
                    <thead>
                        <tr>
                            <th class="asce-tm-header-corner"><?php _e( 'Event', 'asce-tm' ); ?></th>
                            <?php foreach ( $table['columns'] as $col_idx => $column ) : ?>
                                <th class="asce-tm-header-column">
                                    <?php echo esc_html( $column['name'] ); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $table['events'] as $event_idx => $event_config ) : 
                            // Use pre-loaded event object
                            $event = $event_objects[ $event_idx ] ?? null;
                            if ( ! $event || ! $event->event_id ) {
                                continue;
                            }
                            
                            $event_label = ! empty( $event_config['label'] ) ? $event_config['label'] : $event->event_name;
                            
                            // Use global booking limit from EM settings
                            $booking_limit = $booking_limit_global;
                            $exclusive_group = ! empty( $event_config['group'] ) ? sanitize_text_field( $event_config['group'] ) : '';
                        ?>
                        <tr class="asce-tm-row" 
                            data-event-id="<?php echo esc_attr( $event->event_id ); ?>" 
                            data-booking-limit="<?php echo esc_attr( $booking_limit ); ?>"
                            data-exclusive-group="<?php echo esc_attr( $exclusive_group ); ?>">
                            <td class="asce-tm-cell-event">
                                <div class="asce-tm-event-name">
                                    <strong><?php echo esc_html( $event_label ); ?></strong>
                                </div>
                                <div class="asce-tm-event-date">
                                    <?php echo esc_html( $event->output( '#_EVENTDATES' ) ); ?>
                                </div>
                            </td>
                            
                            <?php foreach ( $table['columns'] as $col_idx => $column ) : 
                                $ticket_id = $column['tickets'][ $event_idx ] ?? 0;
                                // Use pre-loaded ticket object
                                $ticket = $ticket_id && isset( $ticket_objects[ $ticket_id ] ) ? $ticket_objects[ $ticket_id ] : null;
                            ?>
                                <td class="asce-tm-cell-ticket">
                                    <?php if ( $ticket && $ticket->ticket_id ) : 
                                        // Use pre-computed availability (PERFORMANCE: avoids 180+ DB queries)
                                        $available_spaces = $ticket_availability[ $ticket->ticket_id ] ?? 0;
                                        $is_available = $available_spaces > 0;
                                        $ticket_end_date = $ticket->ticket_end ? strtotime( $ticket->ticket_end ) : null;
                                        $is_expired = $ticket_end_date && $ticket_end_date < current_time( 'timestamp' );
                                    ?>
                                        <div class="asce-tm-ticket-option <?php echo ! $is_available || $is_expired ? 'asce-tm-unavailable' : ''; ?>">
                                            <div class="asce-tm-price">
                                                <?php echo $ticket->get_price( true ); ?>
                                            </div>
                                            
                                            <?php if ( $is_expired ) : ?>
                                                <div class="asce-tm-status asce-tm-expired">
                                                    <?php _e( 'Expired', 'asce-tm' ); ?>
                                                </div>
                                            <?php elseif ( ! $is_available ) : ?>
                                                <div class="asce-tm-status asce-tm-sold-out">
                                                    <?php _e( 'Sold Out', 'asce-tm' ); ?>
                                                </div>
                                            <?php else : ?>
                                                <div class="asce-tm-quantity">
                                                    <label class="asce-tm-radio-label">
                                                        <input type="radio" 
                                                               name="asce_tm_choice[<?php echo esc_attr( $event->event_id ); ?>]"
                                                               class="asce-tm-ticket-radio"
                                                               value="<?php echo esc_attr( $ticket->ticket_id ); ?>"
                                                               data-event-id="<?php echo esc_attr( $event->event_id ); ?>"
                                                               data-ticket-id="<?php echo esc_attr( $ticket->ticket_id ); ?>"
                                                               data-ticket-name="<?php echo esc_attr( $ticket->ticket_name ); ?>"
                                                               data-price="<?php echo esc_attr( $ticket->get_price( false ) ); ?>"
                                                               data-exclusive-group="<?php echo esc_attr( $exclusive_group ); ?>">
                                                    </label>
                                                </div>
                                                
                                                <?php if ( $available_spaces <= ASCE_TM_LOW_STOCK_THRESHOLD ) : ?>
                                                    <div class="asce-tm-availability asce-tm-low-stock">
                                                        <?php printf( __( 'Only %d left', 'asce-tm' ), $available_spaces ); ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php else : ?>
                                        <div class="asce-tm-ticket-option asce-tm-not-available">
                                            <span class="asce-tm-na"><?php _e( 'N/A', 'asce-tm' ); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Cart Summary -->
            <div class="asce-tm-cart-summary">
                <div class="asce-tm-cart-header">
                    <h3><?php _e( 'Your Selection', 'asce-tm' ); ?></h3>
                    <button type="button" class="asce-tm-clear-cart button button-secondary">
                        <?php _e( 'Clear All', 'asce-tm' ); ?>
                    </button>
                </div>
                <div class="asce-tm-cart-items"></div>
                <div class="asce-tm-cart-total">
                    <strong><?php _e( 'Total:', 'asce-tm' ); ?></strong>
                    <span class="asce-tm-total-amount">$0.00</span>
                </div>
                <div class="asce-tm-cart-actions">
                    <button type="button" class="button button-primary button-large asce-tm-checkout">
                        <?php _e( 'Checkout', 'asce-tm' ); ?>
                    </button>
                </div>
            </div>
        </div>
        </div> <!-- End Panel A: Tickets -->
        
        <!-- Panel B: Forms - HIDDEN in v3.5.3 - Bypassing custom forms, going directly to EM Pro checkout -->
        <!--
        <div class="asce-tm-panel asce-tm-panel-forms" data-panel="2">
            <div class="asce-tm-forms-panel">
                <p class="asce-tm-forms-loading"><?php _e( 'Loading forms...', 'asce-tm' ); ?></p>
            </div>
        </div>
        -->
        
        </div><!-- .asce-tm-instance -->
        <?php
    }
}
