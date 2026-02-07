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
 * Plugin Name: ASCE Ticket Matrix
 * Description: Create multiple customizable ticket matrix tables for ASCE events with flexible column configurations
 * Version: 5.0.13
 * Author: Rune Storesund
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'ASCE_TM_VERSION', '5.0.13' );
define( 'ASCE_TM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASCE_TM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ASCE_TM_PLUGIN_FILE', __FILE__ );

// Performance and limit constants
define( 'ASCE_TM_MAX_CART_ITEMS', 50 );        // Maximum tickets per cart transaction
define( 'ASCE_TM_MAX_EVENTS', 50 );            // Maximum events per table
define( 'ASCE_TM_MAX_COLUMNS', 20 );           // Maximum columns per table
define( 'ASCE_TM_CACHE_DURATION', 180 );       // Default cache duration in seconds (3 minutes)
define( 'ASCE_TM_LOW_STOCK_THRESHOLD', 10 );   // Show "low stock" warning when tickets remaining
define( 'ASCE_TM_MIN_MEMORY_MB', 10 );         // Minimum free memory required (MB)

/**
 * Direct Gateway Implementation - v5.0.0
 * 
 * New approach: Each table specifies its payment gateway directly
 * No more MB Mode forcing - gateways loaded on-demand per table
 * 
 * Benefits:
 * - MB Mode can stay OFF
 * - Regular events unaffected
 * - Explicit gateway control per matrix table
 * - No timing/initialization issues
 * - Clean, predictable behavior
 */

// Check if Events Manager is active
/**
 * Check plugin dependencies
 * 
 * Verifies that Events Manager is installed and activated.
 * 
 * @return bool True if dependencies are met, false otherwise
 */
function asce_tm_check_dependencies() {
    $dependencies_met = true;
    
    if ( ! class_exists( 'EM_Event' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>ASCE Ticket Matrix:</strong> This plugin requires the Events Manager plugin to be installed and activated.';
            echo '</p></div>';
        });
        $dependencies_met = false;
    }
    
    // Note: MB Mode is now enabled globally by this plugin via filters at line 42
    // No admin notice needed as this is intentional behavior
    
    return $dependencies_met;
}

// Include required files
require_once ASCE_TM_PLUGIN_DIR . 'includes/class-asce-tm-error-handler.php';  // Error logging and handling
require_once ASCE_TM_PLUGIN_DIR . 'includes/class-asce-tm-validator.php';      // Input validation and sanitization
require_once ASCE_TM_PLUGIN_DIR . 'includes/class-asce-tm-settings.php';       // Admin interface and configuration
require_once ASCE_TM_PLUGIN_DIR . 'includes/class-asce-tm-matrix.php';         // Frontend display and rendering
require_once ASCE_TM_PLUGIN_DIR . 'includes/class-asce-tm-ajax.php';           // AJAX cart operations

/**
 * Initialize plugin
 * 
 * Sets up translations, error logging, and initializes all components.
 * Called on 'plugins_loaded' hook to ensure WordPress environment is ready.
 * 
 * @return void
 */
function asce_tm_init() {
    // Load textdomain for translations
    load_plugin_textdomain( 'asce-tm', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    
    // Set up error logging if WP_DEBUG is enabled
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        add_action( 'asce_tm_error', function( $message, $context = array() ) {
            error_log( 'ASCE Ticket Matrix Error: ' . $message . ' | Context: ' . print_r( $context, true ) );
        }, 10, 2 );
    }
    
    // Check dependencies
    if ( ! asce_tm_check_dependencies() ) {
        return;
    }
    
    // Initialize settings
    ASCE_TM_Settings::init();
    
    // Initialize matrix display
    ASCE_TM_Matrix::init();
    
    // Initialize AJAX handlers
    ASCE_TM_Ajax::init();
    
    // v3.5.8: Initialize payment flow debug logging
    asce_tm_debug_payment_flow();
    
    // v3.5.16: Initialize booking success page customization
    asce_tm_init_success_page();
    
    // v4.0.11: Hide coupon fields on checkout/cart pages
    asce_tm_hide_coupon_fields();
}
add_action( 'plugins_loaded', 'asce_tm_init' );

/**
 * Hide coupon fields on checkout and cart pages
 * 
 * @since 4.0.11
 */
function asce_tm_hide_coupon_fields() {
    add_action( 'wp', function() {
        $checkout_id = absint( get_option( 'dbem_multiple_bookings_checkout_page' ) );
        $cart_id = absint( get_option( 'dbem_multiple_bookings_cart_page' ) );
        
        // Hide coupon field on checkout or cart pages
        if ( ( $checkout_id && is_page( $checkout_id ) ) || ( $cart_id && is_page( $cart_id ) ) ) {
            // Hide coupon field on checkout/cart pages
            add_action( 'wp_head', function() {
                echo '<style type="text/css">
                    /* Hide coupon/discount fields on ASCE checkout/cart pages */
                    .em-booking-form-section-coupons,
                    .em-bookings-form-coupon,
                    .em-coupon-code-fields,
                    .input-type.em-bookings-form-coupon,
                    .em-booking-form-coupons,
                    .em-bookings-form-coupons,
                    .em-booking-coupon,
                    .em-multiple-bookings-coupon,
                    form.em-booking-form .em-booking-form-coupons,
                    form.em-multiple-bookings-form .em-booking-form-coupons,
                    .em-checkout-form .em-booking-form-coupons,
                    input[name*="coupon"],
                    input[id*="coupon"],
                    label[for*="coupon"],
                    .coupon-code,
                    .discount-code,
                    .em-coupon-code,
                    button.em-coupon-code,
                    button[name*="coupon"],
                    input[type="submit"][value*="coupon" i],
                    input[type="submit"][value*="discount" i],
                    button:has(+ input[name*="coupon"]),
                    .em-coupon-submit,
                    .em-discount-submit,
                    .em-booking-form-coupons button,
                    .em-booking-form-coupons input[type="submit"] {
                        display: none !important;
                        visibility: hidden !important;
                    }
                </style>';
            }, 999 );
        }
    }, 1 );
}

/**
 * Debug logging for EM Pro payment flow
 * Helps troubleshoot cart clearing during payment
 * 
 * @since 3.5.8
 */
function asce_tm_debug_payment_flow() {
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
        return;
    }
    
    // Log when checkout page loads
    add_action( 'wp_footer', function() {
        static $page_load_timer;
        if ( ! $page_load_timer ) {
            $page_load_timer = microtime( true );
        }
        
        $checkout_id = absint( get_option( 'dbem_multiple_bookings_checkout_page' ) );
        if ( is_page( $checkout_id ) ) {
            $page_load_time = number_format( ( microtime( true ) - $page_load_timer ) * 1000, 2 );
            error_log( '========================================' );
            error_log( '=== EM PRO: Checkout Page Loaded [' . $page_load_time . 'ms] ===' );
            error_log( 'Page ID: ' . get_the_ID() );
            error_log( 'Cart has bookings: ' . ( class_exists( 'EM_Multiple_Bookings' ) && EM_Multiple_Bookings::get_multiple_booking() && count( EM_Multiple_Bookings::get_multiple_booking()->bookings ) > 0 ? 'YES' : 'NO' ) );
            
            // Check gateway availability
            error_log( 'Stripe Elements gateway class exists: ' . ( class_exists( 'EM_Gateway_Stripe_Elements' ) ? 'YES' : 'NO' ) );
            error_log( 'Stripe gateway class exists: ' . ( class_exists( 'EM_Gateway_Stripe' ) ? 'YES' : 'NO' ) );
            
            // Check active gateways
            if ( class_exists( 'EM_Gateways' ) ) {
                $active_gateways = EM_Gateways::active_gateways();
                error_log( 'Active gateways: ' . ( ! empty( $active_gateways ) ? implode( ', ', $active_gateways ) : 'NONE' ) );
                
                $available_gateways = EM_Gateways::available_gateways();
                error_log( 'Available gateways: ' . ( ! empty( $available_gateways ) ? implode( ', ', array_keys( $available_gateways ) ) : 'NONE' ) );
            } else {
                error_log( 'EM_Gateways class not found!' );
            }
            
            error_log( '========================================' );
        }
    }, 999 );
    
    // v5.0.11: Simplified filter - MB Mode already ON in database, just ensure AJAX works
    // Same logic as v5.0.8 which had working invoice, but without unnecessary database manipulation
    add_filter( 'pre_option_dbem_multiple_bookings', function( $value ) {
        // Only intercept for AJAX actions
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            $action = ! empty( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
            
            // Only activate for ASCE TM actions specifically (not EM Pro's booking_invoice, etc.)
            if ( strpos( $action, 'asce_tm_' ) === 0 ) {
                error_log( '[v5.0.11] MB Mode forced ON for ASCE TM AJAX: ' . $action );
                return 1;
            }
        }
        
        // For everything else, use database value (which is already ON)
        return $value;
    }, 1 );
    
    // Log any POST to checkout page
    add_action( 'init', function() {
        $checkout_id = absint( get_option( 'dbem_multiple_bookings_checkout_page' ) );
        if ( ! empty( $_POST ) && isset( $_GET['page_id'] ) && absint( $_GET['page_id'] ) === $checkout_id ) {
            error_log( '========================================' );
            error_log( '=== EM PRO: Checkout Page POST Received ===' );
            error_log( 'POST keys: ' . implode( ', ', array_keys( $_POST ) ) );
            error_log( 'Action: ' . ( ! empty( $_POST['action'] ) ? $_POST['action'] : 'not set' ) );
            error_log( 'Gateway: ' . ( ! empty( $_POST['gateway'] ) ? $_POST['gateway'] : 'not set' ) );
            error_log( '========================================' );
        }
    }, 1 );
    
    // Log checkout form submission
    add_action( 'init', function() {
        if ( ! empty( $_POST['action'] ) && $_POST['action'] === 'em_checkout_submit' ) {
            error_log( '========================================' );
            error_log( '=== EM PRO: Checkout Form POST Detected ===' );
            error_log( 'POST keys: ' . implode( ', ', array_keys( $_POST ) ) );
            error_log( 'Gateway: ' . ( ! empty( $_POST['gateway'] ) ? $_POST['gateway'] : 'not set' ) );
            error_log( 'User logged in: ' . ( is_user_logged_in() ? 'YES' : 'NO' ) );
            error_log( '========================================' );
        }
    }, 1 );
    
    // Log when EM Pro starts processing a multiple booking
    add_action( 'em_multiple_booking_save_pre', function( $EM_Multiple_Booking ) {
        static $save_start_timer;
        $save_start_timer = microtime( true );
        
        if ( ! session_id() ) session_start();
        error_log( '========================================' );
        error_log( '=== EM PRO: Multiple Booking Save START [' . date('H:i:s') . '] ===' );
        error_log( 'Session ID: ' . session_id() );
        error_log( 'Bookings in cart: ' . ( ! empty( $EM_Multiple_Booking->bookings ) ? count( $EM_Multiple_Booking->bookings ) : '0' ) );
        error_log( 'Bypass forms flag: ' . ( ! empty( $_SESSION['asce_tm_bypass_forms'] ) ? 'YES' : 'NO' ) );
        
        // Store timer in global for access in next hook
        $GLOBALS['asce_tm_save_timer'] = $save_start_timer;
        error_log( '========================================' );
    }, 1 );
    
    // Log after EM Pro saves multiple booking
    add_action( 'em_multiple_booking_save', function( $result, $EM_Multiple_Booking ) {
        $save_time = 0;
        if ( ! empty( $GLOBALS['asce_tm_save_timer'] ) ) {
            $save_time = number_format( ( microtime( true ) - $GLOBALS['asce_tm_save_timer'] ) * 1000, 2 );
        }
        
        error_log( '========================================' );
        error_log( '=== EM PRO: Multiple Booking Save COMPLETE [' . $save_time . 'ms] ===' );
        
        // Check if we received an object or just the boolean result
        if ( is_object( $EM_Multiple_Booking ) ) {
            error_log( 'Result: ' . ( $result ? 'SUCCESS' : 'FAILED' ) );
            error_log( 'Booking ID: ' . ( $EM_Multiple_Booking->booking_id ?: 'not set' ) );
            error_log( 'Status: ' . ( $EM_Multiple_Booking->booking_status ?: 'unknown' ) );
            error_log( 'Bookings saved: ' . ( ! empty( $EM_Multiple_Booking->bookings ) ? count( $EM_Multiple_Booking->bookings ) : '0' ) );
            
            // Log payment gateway status
            $gateway = ! empty( $EM_Multiple_Booking->booking_meta['gateway'] ) ? $EM_Multiple_Booking->booking_meta['gateway'] : 'not set';
            error_log( 'Payment Gateway: ' . $gateway );
            error_log( 'Price: ' . ( method_exists( $EM_Multiple_Booking, 'get_price' ) ? $EM_Multiple_Booking->get_price() : 'unknown' ) );
            
            $errors = $EM_Multiple_Booking->get_errors();
            if ( ! empty( $errors ) ) {
                error_log( 'ERRORS: ' . print_r( $errors, true ) );
            }
            
            // v5.0.11: Clear ASCE TM session flags after successful booking
            if ( $result && ! empty( $_SESSION['asce_tm_active'] ) ) {
                unset( $_SESSION['asce_tm_active'] );
                unset( $_SESSION['asce_tm_selected_gateway'] );
                error_log( '[v5.0.11] Cleared ASCE TM session flags after successful booking' );
            }
        } else {
            error_log( 'WARNING: Expected object but received: ' . gettype( $EM_Multiple_Booking ) );
            error_log( 'Result parameter: ' . ( $result ? 'true' : 'false' ) );
        }
        error_log( '========================================' );
        return $result;
    }, 1, 2 );
    
    // Log individual booking saves
    add_action( 'em_booking_save', function( $result, $EM_Booking ) {
        if ( ! $result ) {
            error_log( '========================================' );
            error_log( '=== EM PRO: Individual Booking Save FAILED ===' );
            error_log( 'Event ID: ' . $EM_Booking->event_id );
            error_log( 'Event: ' . ( $EM_Booking->get_event() ? $EM_Booking->get_event()->event_name : 'unknown' ) );
            error_log( 'Errors: ' . print_r( $EM_Booking->get_errors(), true ) );
            error_log( '========================================' );
        }
        return $result;
    }, 10, 2 );
    
    // Log payment gateway processing
    add_action( 'em_gateway_payment_processing', function( $EM_Booking, $gateway ) {
        error_log( '========================================' );
        error_log( '=== EM PRO: Payment Gateway Processing ===' );
        error_log( 'Gateway: ' . $gateway );
        error_log( 'Booking ID: ' . ( is_object( $EM_Booking ) ? $EM_Booking->booking_id : 'not an object' ) );
        if ( is_object( $EM_Booking ) && method_exists( $EM_Booking, 'get_price' ) ) {
            error_log( 'Total Price: ' . $EM_Booking->get_price() );
        }
        error_log( '========================================' );
    }, 1, 2 );
    
    // Log Stripe payment intent creation
    add_filter( 'em_gateway_stripe_payment_intent_args', function( $intent_args, $EM_Booking ) {
        error_log( '========================================' );
        error_log( '=== STRIPE: Payment Intent Args ===' );
        error_log( 'Booking ID: ' . ( is_object( $EM_Booking ) ? $EM_Booking->booking_id : 'not an object' ) );
        error_log( 'Intent Args: ' . print_r( $intent_args, true ) );
        error_log( '========================================' );
        return $intent_args;
    }, 10, 2 );
    
    // Log Stripe payment intent response
    add_action( 'em_gateway_stripe_payment_intent_created', function( $intent, $EM_Booking ) {
        error_log( '========================================' );
        error_log( '=== STRIPE: Payment Intent Created ===' );
        error_log( 'Booking ID: ' . ( is_object( $EM_Booking ) ? $EM_Booking->booking_id : 'not an object' ) );
        if ( is_object( $intent ) ) {
            error_log( 'Intent ID: ' . ( ! empty( $intent->id ) ? $intent->id : 'not set' ) );
            error_log( 'Status: ' . ( ! empty( $intent->status ) ? $intent->status : 'not set' ) );
            error_log( 'Amount: ' . ( ! empty( $intent->amount ) ? $intent->amount : 'not set' ) );
            error_log( 'Currency: ' . ( ! empty( $intent->currency ) ? $intent->currency : 'not set' ) );
        } else {
            error_log( 'Intent: ' . print_r( $intent, true ) );
        }
        error_log( '========================================' );
    }, 10, 2 );
    
    // Log Stripe errors
    add_action( 'em_gateway_stripe_error', function( $error_message, $EM_Booking ) {
        error_log( '========================================' );
        error_log( '=== STRIPE: ERROR ===' );
        error_log( 'Booking ID: ' . ( is_object( $EM_Booking ) ? $EM_Booking->booking_id : 'not an object' ) );
        error_log( 'Error: ' . $error_message );
        error_log( '========================================' );
    }, 10, 2 );
    
    // Log payment processed
    add_action( 'em_payment_processed', function( $EM_Booking, $result ) {
        error_log( '========================================' );
        error_log( '=== EM PRO: Payment Processed ===' );
        error_log( 'Result: ' . ( $result ? 'SUCCESS' : 'FAILED' ) );
        error_log( 'Booking ID: ' . ( is_object( $EM_Booking ) ? $EM_Booking->booking_id : 'not an object' ) );
        error_log( '========================================' );
    }, 1, 2 );
    
    // Log cart clearing
    add_action( 'em_multiple_bookings_deleted', function( $EM_Multiple_Booking ) {
        error_log( '========================================' );
        error_log( '=== EM PRO: Cart DELETED ===' );
        error_log( 'Timestamp: ' . current_time( 'mysql' ) );
        error_log( 'Reason: em_multiple_bookings_deleted action triggered' );
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 );
        error_log( 'Trace: ' . print_r( $trace, true ) );
        error_log( '========================================' );
    }, 1 );
}

/**
 * Customize booking success page
 * Adds booking summary and receipt/invoice links after successful payment
 * 
 * @since 3.5.16
 */
function asce_tm_init_success_page() {
    // Intercept checkout page early to show success message
    add_action( 'template_redirect', 'asce_tm_maybe_show_success_page', 1 );
}

/**
 * Intercept page load and show success page if payment completed
 */
function asce_tm_maybe_show_success_page() {
    $timer_start = microtime( true );
    
    // Only run on checkout page
    $checkout_id = absint( get_option( 'dbem_multiple_bookings_checkout_page' ) );
    if ( ! is_page( $checkout_id ) ) {
        return;
    }
    
    // Check if payment was completed
    $has_payment_complete = ! empty( $_GET['payment_complete'] );
    $has_redirect_status = ! empty( $_GET['redirect_status'] ) && $_GET['redirect_status'] === 'succeeded';
    
    if ( ! $has_payment_complete && ! $has_redirect_status ) {
        return;
    }
    
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '========================================' );
        error_log( '=== ASCE TM Success Page Render START ===' );
    }
    
    // Get booking ID
    $booking_id = ! empty( $_GET['booking_id'] ) ? absint( $_GET['booking_id'] ) : 0;
    if ( ! $booking_id ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WARN] No booking ID in URL' );
        }
        return;
    }
    
    // Get the booking
    if ( ! class_exists( 'EM_Multiple_Booking' ) ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[ERROR] EM_Multiple_Booking class not found' );
        }
        return;
    }
    
    $booking_load_start = microtime( true );
    $EM_Multiple_Booking = new EM_Multiple_Booking( $booking_id );
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $booking_load_time = number_format( ( microtime( true ) - $booking_load_start ) * 1000, 2 );
        error_log( '[PERF] Booking load: ' . $booking_load_time . 'ms' );
    }
    
    // Output success page and exit
    asce_tm_render_success_page( $EM_Multiple_Booking, $booking_id );
    
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $total_time = number_format( ( microtime( true ) - $timer_start ) * 1000, 2 );
        error_log( '[PERF] Success page total: ' . $total_time . 'ms' );
        error_log( '=== ASCE TM Success Page Render END ===' );
        error_log( '========================================' );
    }
    
    exit;
}

/**
 * Render the complete success page with HTML wrapper
 */
function asce_tm_render_success_page( $EM_Multiple_Booking, $booking_id ) {
    // Get site info
    $site_name = get_bloginfo( 'name' );
    $charset = get_bloginfo( 'charset' );
    
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php echo esc_attr( $charset ); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Booking Successful - <?php echo esc_html( $site_name ); ?></title>
        <?php wp_head(); ?>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 20px; background: #f8fafc; }
            .container { max-width: 800px; margin: 0 auto; }
        </style>
        <script>
        // Remove sensitive parameters from URL for security
        (function() {
            if (window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                var cleaned = false;
                
                // Remove Stripe client secret
                if (url.searchParams.has('payment_intent_client_secret')) {
                    url.searchParams.delete('payment_intent_client_secret');
                    cleaned = true;
                }
                
                // Remove payment intent ID (not sensitive but not needed)
                if (url.searchParams.has('payment_intent')) {
                    url.searchParams.delete('payment_intent');
                    cleaned = true;
                }
                
                // Remove redirect status
                if (url.searchParams.has('redirect_status')) {
                    url.searchParams.delete('redirect_status');
                    cleaned = true;
                }
                
                // Keep booking_id and payment_complete for success page logic
                if (cleaned) {
                    window.history.replaceState({}, document.title, url.toString());
                }
            }
        })();
        </script>
    </head>
    <body>
        <div class="container">
            <?php asce_tm_display_booking_success_html( $EM_Multiple_Booking, $booking_id ); ?>
        </div>
        <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}

/**
 * Display the booking success HTML
 */
function asce_tm_display_booking_success_html( $EM_Multiple_Booking, $booking_id ) {
    // Get booking details
    $total_price = $EM_Multiple_Booking->get_price();
    $currency_symbol = function_exists( 'em_get_currency_symbol' ) ? em_get_currency_symbol() : '$';
    $formatted_price = $currency_symbol . number_format( $total_price, 2 );
    $bookings = $EM_Multiple_Booking->get_bookings();
    $is_logged_in = is_user_logged_in();
    ?>
    <div class="asce-tm-booking-success" style="background: white; border-radius: 8px; padding: 40px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin: 40px 0;">
        <div style="text-align: center; margin-bottom: 30px;">
            <div style="width: 80px; height: 80px; background: #10b981; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                <svg width="50" height="50" fill="white" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
            </div>
            <h2 style="color: #0c4a6e; margin: 0 0 10px 0; font-size: 28px;">Booking Successful!</h2>
            <p style="color: #075985; font-size: 16px; margin: 0;">Thank you for your registration. Your booking has been confirmed.</p>
        </div>
        
        <div class="booking-details" style="border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; padding: 20px 0; margin: 30px 0;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                <span style="color: #64748b; font-weight: 500;">Booking Reference:</span>
                <span style="color: #1e293b; font-weight: 600; font-size: 18px;">#<?php echo esc_html( $booking_id ); ?></span>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                <span style="color: #64748b; font-weight: 500;">Total Amount:</span>
                <span style="color: #1e293b; font-weight: 600; font-size: 18px;"><?php echo esc_html( $formatted_price ); ?></span>
            </div>
            
            <div style="display: flex; justify-content: space-between;">
                <span style="color: #64748b; font-weight: 500;">Payment Status:</span>
                <span style="color: #10b981; font-weight: 600;">Paid</span>
            </div>
        </div>
        
        <div style="margin: 30px 0;">
            <h3 style="color: #1e293b; margin: 0 0 20px 0; font-size: 20px;">Registered Events</h3>
            <?php
            if ( ! empty( $bookings ) && is_array( $bookings ) ) {
                foreach ( $bookings as $EM_Booking ) {
                    $event = $EM_Booking->get_event();
                    if ( ! $event ) continue;
                    
                    $event_name = $event->event_name;
                    $event_date = $event->event_start_date ? date_i18n( 'F j, Y', strtotime( $event->event_start_date ) ) : '';
                    ?>
                    <div style="background: #f8fafc; padding: 15px; border-radius: 6px; margin-bottom: 10px; border-left: 4px solid #3b82f6;">
                        <div style="font-weight: 600; color: #1e293b; margin-bottom: 5px;"><?php echo esc_html( $event_name ); ?></div>
                        <?php if ( $event_date ) : ?>
                            <div style="color: #64748b; font-size: 14px;">📅 <?php echo esc_html( $event_date ); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php
                }
            }
            ?>
        </div>
        
        <div class="booking-actions" style="text-align: center; margin-top: 25px;">
            <?php
            // Generate invoice URL that works for both logged-in and non-logged-in users
            // Use nopriv method with UUID for public access (works in incognito mode)
            $booking_uuid = $EM_Multiple_Booking->booking_uuid;
            $invoice_url = add_query_arg( array(
                'action' => 'em_download_pdf_nopriv',
                'booking_uuid' => $booking_uuid,
                'what' => 'invoice',
                'nonce' => wp_create_nonce('em_download_booking_pdf-' . $booking_uuid),
                '_nonce' => wp_create_nonce('em_download_booking_pdf_invoice-' . $booking_id)
            ), home_url('/wp-admin/admin-ajax.php') );
            ?>
            <a href="<?php echo esc_url( $invoice_url ); ?>" 
               class="button" 
               target="_blank"
               style="background: #3b82f6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 5px; font-weight: 600; border: none; font-size: 16px;">
                📄 Download Invoice/Receipt
            </a>
            
            <?php if ( $is_logged_in ) : ?>
                <a href="<?php echo esc_url( get_permalink( get_option( 'dbem_my_bookings_page' ) ) ); ?>" 
                   class="button button-secondary" 
                   style="background: #64748b; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; display: inline-block; margin: 5px; font-weight: 600; border: none; font-size: 16px;">
                    📋 View All My Bookings
                </a>
            <?php endif; ?>
        </div>
        
        <div style="margin-top: 25px; padding: 20px; background: #eff6ff; border-radius: 6px; border-left: 4px solid #3b82f6;">
            <p style="margin: 0; color: #1e40af; font-size: 14px;">
                <strong>✉️ Confirmation Email:</strong> A confirmation email with your booking details has been sent to your registered email address.
            </p>
        </div>
    </div>
    <?php
}

/**
 * Suppress Events Manager script timing notices
 * 
 * Events Manager enqueues countdown scripts outside proper hooks, causing notices.
 * This filter suppresses those specific notices to keep debug logs clean.
 * The notices are from EM Pro, not this plugin, and don't affect functionality.
 */
add_filter( 'doing_it_wrong_trigger_error', function( $trigger, $function, $message, $version ) {
    // Suppress script enqueue timing notices for EM countdown scripts
    if ( in_array( $function, array( 'wp_register_script', 'wp_enqueue_script' ) ) && 
         ( strpos( $message, 'CountDown' ) !== false || strpos( $message, 'moment-countdown' ) !== false ) ) {
        return false;
    }
    return $trigger;
}, 10, 4 );

/**
 * Prevent caching on cart and checkout pages
 * 
 * Ensures cart and checkout pages show fresh data by disabling all caching.
 * Critical for multisite and session-based cart functionality.
 * Includes LiteSpeed Cache controls and defense-in-depth no-cache headers.
 */
function asce_tm_prevent_page_caching() {
    // Skip if in admin or AJAX context
    if ( is_admin() || wp_doing_ajax() ) {
        return;
    }
    
    // Get EM Pro Multiple Bookings page IDs
    $cart_id = absint( get_option( 'dbem_multiple_bookings_cart_page' ) );
    $checkout_id = absint( get_option( 'dbem_multiple_bookings_checkout_page' ) );
    
    // Check if current page is cart or checkout
    if ( ( $cart_id && is_page( $cart_id ) ) || ( $checkout_id && is_page( $checkout_id ) ) ) {
        // Define cache-bypass constants (defense-in-depth)
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
            define( 'DONOTCACHEOBJECT', true );
        }
        if ( ! defined( 'DONOTCACHEDB' ) ) {
            define( 'DONOTCACHEDB', true );
        }
        if ( ! defined( 'DONOTMINIFY' ) ) {
            define( 'DONOTMINIFY', true );
        }
        
        // LiteSpeed Cache: Set no-cache control
        if ( has_action( 'litespeed_control_set_nocache' ) ) {
            do_action( 'litespeed_control_set_nocache' );
        }
        
        // Send comprehensive no-cache headers
        nocache_headers();
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
        header( 'Pragma: no-cache', true );
        header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );
    }
}
add_action( 'template_redirect', 'asce_tm_prevent_page_caching', 0 );

/**
 * Output early inline script to initialize EM.bookings_form_observer
 * 
 * Sets up the EM.bookings_form_observer flag very early in wp_head (priority 1)
 * to ensure it's available before any other scripts that might need it.
 * Only outputs on EM Pro multiple bookings cart and checkout pages.
 */
function asce_tm_output_em_observer_flag() {
    $cart_id = absint( get_option( 'dbem_multiple_bookings_cart_page' ) );
    $checkout_id = absint( get_option( 'dbem_multiple_bookings_checkout_page' ) );
    
    if ( ( $cart_id && is_page( $cart_id ) ) || ( $checkout_id && is_page( $checkout_id ) ) ) {
        ?>
        <script>
        window.EM = window.EM || {};
        EM.bookings_form_observer = true;
        </script>
        <?php
    }
}
add_action( 'wp_head', 'asce_tm_output_em_observer_flag', 1 );

/**
 * Debug: Log cart contents when cart or checkout page loads
 * 
 * This helps diagnose cart display issues by logging what's actually
 * stored in the EM_Multiple_Booking session when the page loads.
 */
function asce_tm_debug_cart_contents() {
    // Only run on cart or checkout pages
    $cart_id = absint( get_option( 'dbem_multiple_bookings_cart_page' ) );
    $checkout_id = absint( get_option( 'dbem_multiple_bookings_checkout_page' ) );
    
    if ( ! ( ( $cart_id && is_page( $cart_id ) ) || ( $checkout_id && is_page( $checkout_id ) ) ) ) {
        return;
    }
    
    // Only log if debug mode is enabled
    if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
        return;
    }
    
    // Check if EM Multiple Bookings is available
    if ( ! class_exists( 'EM_Multiple_Bookings' ) ) {
        error_log( 'ASCE TM Cart Debug: EM_Multiple_Bookings class not found' );
        return;
    }
    
    // Start session if needed
    EM_Multiple_Bookings::session_start();
    
    // Get the multiple booking object
    $EM_Multiple_Booking = EM_Multiple_Bookings::get_multiple_booking();
    
    if ( ! $EM_Multiple_Booking ) {
        error_log( '==========================================' );
        error_log( '=== ASCE TM Cart Display - NO CART FOUND ===' );
        error_log( 'Page: ' . ( is_page( $cart_id ) ? 'Cart' : 'Checkout' ) );
        error_log( 'Session ID: ' . session_id() );
        error_log( '==========================================' );
        return;
    }
    
    // Get all bookings in cart
    $bookings = $EM_Multiple_Booking->get_bookings();
    
    error_log( '==========================================' );
    error_log( '=== ASCE TM Cart Display - Cart Contents ===' );
    error_log( 'Page: ' . ( is_page( $cart_id ) ? 'Cart' : 'Checkout' ) );
    error_log( 'Session ID: ' . session_id() );
    error_log( 'Total bookings in cart: ' . count( $bookings ) );
    error_log( 'Event IDs in cart: ' . ( ! empty( $bookings ) ? implode( ', ', array_keys( $bookings ) ) : 'none' ) );
    
    if ( ! empty( $bookings ) ) {
        foreach ( $bookings as $event_id => $EM_Booking ) {
            if ( ! $EM_Booking || ! is_object( $EM_Booking ) ) {
                error_log( '  Event ID ' . $event_id . ': INVALID BOOKING OBJECT' );
                continue;
            }
            
            $event = $EM_Booking->get_event();
            $event_name = $event ? $event->event_name : 'Unknown Event';
            
            error_log( '  Event ID ' . $event_id . ': ' . $event_name );
            error_log( '    Booking ID: ' . ( isset( $EM_Booking->booking_id ) ? $EM_Booking->booking_id : 'not set' ) );
            error_log( '    Spaces: ' . ( isset( $EM_Booking->get_spaces ) ? $EM_Booking->get_spaces() : 'unknown' ) );
            
            // Log tickets
            if ( method_exists( $EM_Booking, 'get_tickets_bookings' ) ) {
                $tickets = $EM_Booking->get_tickets_bookings();
                if ( ! empty( $tickets ) ) {
                    error_log( '    Tickets: ' . count( $tickets->tickets_bookings ) );
                    foreach ( $tickets->tickets_bookings as $ticket_id => $EM_Ticket_Booking ) {
                        $ticket_name = isset( $EM_Ticket_Booking->ticket_name ) ? $EM_Ticket_Booking->ticket_name : 'Unknown';
                        $quantity = isset( $EM_Ticket_Booking->ticket_booking_spaces ) ? $EM_Ticket_Booking->ticket_booking_spaces : 0;
                        error_log( '      Ticket ID ' . $ticket_id . ': ' . $ticket_name . ' (qty: ' . $quantity . ')' );
                    }
                }
            }
        }
    } else {
        error_log( '  Cart is empty!' );
    }
    
    error_log( '==========================================' );
    
    // Close session
    EM_Multiple_Bookings::session_close();
}
add_action( 'template_redirect', 'asce_tm_debug_cart_contents', 999 );

/**
 * Diagnostic: Log booking form configuration for events in cart
 * Runs on checkout page to help identify missing form fields
 * 
 * @since 3.5.28
 */
add_action( 'template_redirect', 'asce_tm_diagnostic_booking_forms', 1000 );
function asce_tm_diagnostic_booking_forms() {
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
        return;
    }
    
    // Only run on checkout page
    $checkout_page_id = get_option( 'dbem_multiple_bookings_checkout_page' );
    if ( ! $checkout_page_id || ! is_page( $checkout_page_id ) ) {
        return;
    }
    
    if ( ! class_exists( 'EM_Multiple_Bookings' ) ) {
        return;
    }
    
    error_log( '==========================================' );
    error_log( '=== BOOKING FORM DIAGNOSTIC START ===' );
    error_log( 'Page: Checkout (ID: ' . $checkout_page_id . ')' );
    error_log( 'Timestamp: ' . current_time( 'mysql' ) );
    
    EM_Multiple_Bookings::session_start();
    $EM_Multiple_Booking = EM_Multiple_Bookings::get_multiple_booking();
    
    if ( ! $EM_Multiple_Booking || empty( $EM_Multiple_Booking->bookings ) ) {
        error_log( '  Cart is EMPTY - no bookings to diagnose' );
        error_log( '==========================================' );
        EM_Multiple_Bookings::session_close();
        return;
    }
    
    $bookings = $EM_Multiple_Booking->get_bookings();
    error_log( '  Events in cart: ' . count( $bookings ) );
    
    // Analyze each event's booking form
    foreach ( $bookings as $EM_Booking ) {
        $event = $EM_Booking->get_event();
        if ( ! $event || ! $event->event_id ) {
            continue;
        }
        
        error_log( '' );
        error_log( '--- Event ID ' . $event->event_id . ': ' . $event->event_name . ' ---' );
        
        // Get booking form ID for this event
        $booking_form_id = get_post_meta( $event->post_id, '_custom_booking_form', true );
        if ( empty( $booking_form_id ) || ! is_numeric( $booking_form_id ) ) {
            $booking_form_id = get_option( 'em_booking_form_fields' );
            error_log( '  Booking Form: Using GLOBAL default (ID: ' . $booking_form_id . ')' );
        } else {
            error_log( '  Booking Form: Custom event form (ID: ' . $booking_form_id . ')' );
        }
        
        // Fetch form fields from EM_META_TABLE
        global $wpdb;
        if ( defined( 'EM_META_TABLE' ) && $booking_form_id ) {
            $sql = $wpdb->prepare(
                "SELECT meta_value FROM " . EM_META_TABLE . " WHERE meta_key='booking-form' AND meta_id=%d",
                $booking_form_id
            );
            $form_data_raw = $wpdb->get_var( $sql );
            
            if ( $form_data_raw ) {
                $form_data = maybe_unserialize( $form_data_raw );
                
                if ( is_array( $form_data ) && isset( $form_data['form'] ) ) {
                    $fields = $form_data['form'];
                    $form_name = isset( $form_data['name'] ) ? $form_data['name'] : 'Unnamed Form';
                    
                    error_log( '  Form Name: ' . $form_name );
                    error_log( '  Total Fields: ' . count( $fields ) );
                    
                    // List all fields with required status
                    $required_fields = array();
                    $all_field_names = array();
                    
                    foreach ( $fields as $field_id => $field_config ) {
                        $label = isset( $field_config['label'] ) ? $field_config['label'] : $field_id;
                        $type = isset( $field_config['type'] ) ? $field_config['type'] : 'text';
                        $required = ! empty( $field_config['required'] );
                        
                        $all_field_names[] = $field_id;
                        
                        if ( $required ) {
                            $required_fields[] = $field_id;
                        }
                        
                        error_log( '    • ' . $field_id . ' (' . $type . '): "' . $label . '"' . ( $required ? ' [REQUIRED]' : '' ) );
                    }
                    
                    error_log( '  Required Fields: ' . ( empty( $required_fields ) ? 'NONE' : implode( ', ', $required_fields ) ) );
                    
                    // Check if email is in the form
                    $has_email = false;
                    foreach ( array( 'email', 'user_email', 'dbem_email', 'booking_email' ) as $email_field ) {
                        if ( in_array( $email_field, $all_field_names ) ) {
                            $has_email = true;
                            error_log( '  ✓ EMAIL FIELD FOUND: ' . $email_field );
                            break;
                        }
                    }
                    
                    if ( ! $has_email ) {
                        error_log( '  ✗ NO EMAIL FIELD in form definition' );
                    }
                    
                } else {
                    error_log( '  ✗ Form data structure invalid or empty' );
                }
            } else {
                error_log( '  ✗ Form ID ' . $booking_form_id . ' not found in database' );
            }
        }
    }
    
    error_log( '' );
    error_log( '--- EM Pro Settings ---' );
    error_log( '  Anonymous bookings: ' . ( get_option( 'dbem_bookings_anonymous', 1 ) ? 'YES (email may be collected)' : 'NO (login required)' ) );
    error_log( '  Registration mode: ' . get_option( 'dbem_bookings_registration_user', 'optional' ) );
    
    $user_fields = get_option( 'em_user_fields', 'optional' );
    error_log( '  User fields: ' . ( is_array( $user_fields ) ? print_r( $user_fields, true ) : $user_fields ) );
    
    error_log( '=== BOOKING FORM DIAGNOSTIC END ===' );
    error_log( '==========================================' );
    
    EM_Multiple_Bookings::session_close();
}

/**
 * Diagnostic: Capture rendered form HTML to identify displayed fields
 * Runs after EM Pro renders the checkout form
 * 
 * @since 3.5.28
 */
add_filter( 'em_booking_form_custom', 'asce_tm_diagnostic_rendered_form', 9999 );
function asce_tm_diagnostic_rendered_form( $form_html ) {
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
        return $form_html;
    }
    
    // Only run on checkout page
    $checkout_page_id = get_option( 'dbem_multiple_bookings_checkout_page' );
    if ( ! $checkout_page_id || ! is_page( $checkout_page_id ) ) {
        return $form_html;
    }
    
    error_log( '' );
    error_log( '==========================================' );
    error_log( '=== RENDERED FORM ANALYSIS ===' );
    error_log( 'Form HTML length: ' . strlen( $form_html ) . ' bytes' );
    
    // Extract all input/select/textarea field names
    $rendered_fields = array();
    preg_match_all( '/(?:name|id)=["\']([^"\']+)["\']/', $form_html, $matches );
    if ( ! empty( $matches[1] ) ) {
        $rendered_fields = array_unique( $matches[1] );
        error_log( 'Fields in rendered HTML (' . count( $rendered_fields ) . '):' );
        foreach ( $rendered_fields as $field ) {
            // Skip common non-booking fields
            if ( in_array( $field, array( 'gateway', 'coupon_code', 'booking_comment' ) ) ) {
                continue;
            }
            
            // Check if required
            $is_required = ( strpos( $form_html, 'name="' . $field . '"' ) !== false || 
                           strpos( $form_html, "name='" . $field . "'" ) !== false ) &&
                           ( strpos( $form_html, 'required' ) !== false );
            
            error_log( '  • ' . $field . ( $is_required ? ' [REQUIRED]' : '' ) );
        }
    } else {
        error_log( '  ✗ NO FIELDS detected in HTML' );
    }
    
    // Specific email field check
    $email_patterns = array(
        'name="email"',
        'name="user_email"',
        'name="dbem_email"',
        'name="booking_email"',
        'id="email"',
        'id="user_email"',
        'id="dbem_email"'
    );
    
    $email_found = false;
    foreach ( $email_patterns as $pattern ) {
        if ( stripos( $form_html, $pattern ) !== false ) {
            error_log( '  ✓ EMAIL INPUT FOUND: ' . $pattern );
            $email_found = true;
            break;
        }
    }
    
    if ( ! $email_found ) {
        error_log( '  ✗ NO EMAIL INPUT in rendered HTML' );
        error_log( '  → This confirms email field is NOT being rendered by EM Pro' );
    }
    
    error_log( '=== RENDERED FORM ANALYSIS END ===' );
    error_log( '==========================================' );
    
    return $form_html;
}

/**
 * Suppress "Save my information" checkbox for logged-in users
 * Removes the account creation/save info option when user is already logged in
 * 
 * @since 3.5.30
 * @param string $form_html The checkout form HTML
 * @return string Modified form HTML
 */
add_filter( 'em_booking_form_custom', 'asce_tm_suppress_save_info_for_logged_in', 10000 );
function asce_tm_suppress_save_info_for_logged_in( $form_html ) {
    // Only suppress for logged-in users
    if ( ! is_user_logged_in() ) {
        return $form_html;
    }
    
    // Only run on checkout page
    $checkout_page_id = get_option( 'dbem_multiple_bookings_checkout_page' );
    if ( ! $checkout_page_id || ! is_page( $checkout_page_id ) ) {
        return $form_html;
    }
    
    // Remove various "save information" checkboxes and related text
    // These patterns cover different EM Pro versions and configurations
    $patterns_to_remove = array(
        // Save information checkbox with label
        '/<div[^>]*>\s*<label[^>]*>\s*<input[^>]*name=["\'](?:user_)?save_?(?:my)?_?(?:info|information|details)["\'][^>]*>.*?<\/label>\s*<\/div>/is',
        // Create account checkbox
        '/<div[^>]*>\s*<label[^>]*>\s*<input[^>]*name=["\'](?:create_?)?account["\'][^>]*>.*?<\/label>\s*<\/div>/is',
        // Direct checkbox patterns
        '/<input[^>]*name=["\'](?:user_)?save_?(?:my)?_?(?:info|information|details)["\'][^>]*>/i',
        '/<input[^>]*name=["\'](?:create_?)?account["\'][^>]*>/i',
        // Label patterns
        '/<label[^>]*for=["\'](?:user_)?save_?(?:my)?_?(?:info|information|details)["\'][^>]*>.*?<\/label>/is',
        '/<label[^>]*for=["\'](?:create_?)?account["\'][^>]*>.*?<\/label>/is',
    );
    
    foreach ( $patterns_to_remove as $pattern ) {
        $form_html = preg_replace( $pattern, '', $form_html );
    }
    
    // Also remove common text phrases associated with this option
    $text_patterns = array(
        '/Save my information for faster checkout/i',
        '/Create an account\?/i',
        '/Register for an account/i',
    );
    
    foreach ( $text_patterns as $pattern ) {
        $form_html = preg_replace( $pattern, '', $form_html );
    }
    
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '=== ASCE TM: Suppressed "Save Info" checkbox for logged-in user ===' );
    }
    
    return $form_html;
}

/**
 * Pre-populate EM Pro checkout form fields with data from our custom forms
 * Hook into EM Pro's form rendering to fill in values
 */
add_filter( 'em_booking_form_custom', 'asce_tm_prepopulate_checkout_forms', 1 );
function asce_tm_prepopulate_checkout_forms( $form_html ) {
    // Only run on checkout page
    if ( ! class_exists( 'EM_Multiple_Bookings' ) ) {
        return $form_html;
    }
    
    EM_Multiple_Bookings::session_start();
    
    // Check if we have saved form data
    if ( empty( $_SESSION['asce_tm_form_data'] ) ) {
        EM_Multiple_Bookings::session_close();
        return $form_html;
    }
    
    $form_data = $_SESSION['asce_tm_form_data'];
    
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '=== ASCE TM Pre-Populate Checkout Forms ===' );
        error_log( 'Form data count: ' . count( $form_data ) );
    }
    
    // Map our custom field names back to EM's expected names and inject into form HTML
    foreach ( $form_data as $field_name => $field_value ) {
        // Extract original field ID: asce_tm_booking_3660_phone -> phone
        if ( preg_match( '/^asce_tm_(booking|attendee)_\d+_(.+)$/', $field_name, $matches ) ) {
            $field_type = $matches[1]; // 'booking' or 'attendee'
            $original_field_name = $matches[2];
            
            // Set in $_POST for EM to pick up during form field rendering
            if ( ! isset( $_POST[ $original_field_name ] ) ) {
                $_POST[ $original_field_name ] = $field_value;
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( "  Mapped: $field_name -> $original_field_name = $field_value" );
                }
            }
        }
    }
    
    EM_Multiple_Bookings::session_close();
    return $form_html;
}

// Assets are enqueued by ASCE_TM_Matrix class when shortcode is rendered
// This ensures compatibility with Elementor and page builders
// Assets load only when needed, preventing unnecessary HTTP requests

/**
 * Add settings link to plugins page
 * 
 * Adds a convenient "Settings" link to the plugin row on the Plugins page.
 * 
 * @param array $links Existing plugin action links
 * @return array Modified plugin action links
 */
function asce_tm_plugin_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=asce-ticket-matrix' ) ) . '">' . __( 'Settings', 'asce-tm' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'asce_tm_plugin_action_links' );

/**
 * Plugin activation hook
 * 
 * Initializes plugin options on first activation.
 * Creates default table storage option if it doesn't exist.
 * 
 * @return void
 */
function asce_tm_activate() {
    // Initialize tables option if it doesn't exist
    if ( ! get_option( 'asce_tm_tables' ) ) {
        add_option( 'asce_tm_tables', array() );
    }
    
    // Legacy options preserved for potential migration (not actively used in v2.x)
}
register_activation_hook( __FILE__, 'asce_tm_activate' );
