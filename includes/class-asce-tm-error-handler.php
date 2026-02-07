<?php
/**
 * ASCE Ticket Matrix Error Handler
 * Centralized error handling and logging
 * 
 * @package ASCE_Ticket_Matrix
 * @version 2.1.4
 * @since 2.1.2
 */

class ASCE_TM_Error_Handler {
    
    /**
     * Error types
     */
    const ERROR_CRITICAL = 'critical';
    const ERROR_WARNING = 'warning';
    const ERROR_INFO = 'info';
    
    /**
     * Log an error
     * 
     * @param string $message Error message
     * @param string $type Error type (critical, warning, info)
     * @param array $context Additional context
     */
    public static function log( $message, $type = self::ERROR_WARNING, $context = array() ) {
        // Log to WordPress debug log if enabled
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $log_message = sprintf(
                'ASCE Ticket Matrix [%s]: %s',
                strtoupper( $type ),
                $message
            );
            
            if ( ! empty( $context ) ) {
                $log_message .= ' | Context: ' . print_r( $context, true );
            }
            
            error_log( $log_message );
        }
        
        // Trigger action for external logging systems
        do_action( 'asce_tm_error', $message, $type, $context );
    }
    
    /**
     * Display user-facing error message
     * 
     * @param string $message Message to display
     * @param string $type Type of notice (error, warning, info)
     * @return string HTML error notice
     */
    public static function get_notice( $message, $type = 'error' ) {
        $classes = array( 'asce-tm-notice' );
        
        if ( $type === 'error' ) {
            $classes[] = 'asce-tm-error';
        } elseif ( $type === 'warning' ) {
            $classes[] = 'asce-tm-warning';
        }
        
        return sprintf(
            '<div class="%s">%s</div>',
            esc_attr( implode( ' ', $classes ) ),
            wp_kses_post( $message )
        );
    }
    
    /**
     * Display error notice
     * 
     * @param string $message Message to display
     * @param string $type Type of notice
     */
    public static function display_notice( $message, $type = 'error' ) {
        echo self::get_notice( $message, $type );
    }
    
    /**
     * Get AJAX error response
     * 
     * @param string $message Error message
     * @param array $data Additional data
     * @return array Error response for wp_send_json_error
     */
    public static function get_ajax_error( $message, $data = array() ) {
        return array_merge(
            array( 'message' => $message ),
            $data
        );
    }
}
