<?php
/**
 * ASCE Ticket Matrix Validator
 * Centralized validation logic
 * 
 * @package ASCE_Ticket_Matrix
 * @version 2.1.4
 * @since 2.1.2
 */

class ASCE_TM_Validator {
    
    /**
     * Validate table configuration
     * 
     * @param array $table Table configuration array
     * @return array|WP_Error Array of sanitized data or WP_Error on failure
     */
    public static function validate_table_config( $table ) {
        if ( ! is_array( $table ) ) {
            return new WP_Error( 'invalid_table', __( 'Invalid table configuration', 'asce-tm' ) );
        }
        
        // Validate required fields
        if ( empty( $table['name'] ) ) {
            return new WP_Error( 'missing_name', __( 'Table name is required', 'asce-tm' ) );
        }
        
        // Validate numeric fields
        $num_events = absint( $table['num_events'] ?? 0 );
        $num_columns = absint( $table['num_columns'] ?? 0 );
        
        if ( $num_events < 1 || $num_events > ASCE_TM_MAX_EVENTS ) {
            return new WP_Error( 
                'invalid_events', 
                sprintf( __( 'Number of events must be between 1 and %d', 'asce-tm' ), ASCE_TM_MAX_EVENTS )
            );
        }
        
        if ( $num_columns < 1 || $num_columns > ASCE_TM_MAX_COLUMNS ) {
            return new WP_Error( 
                'invalid_columns', 
                sprintf( __( 'Number of columns must be between 1 and %d', 'asce-tm' ), ASCE_TM_MAX_COLUMNS )
            );
        }
        
        // Validate events array
        if ( ! empty( $table['events'] ) && ! is_array( $table['events'] ) ) {
            return new WP_Error( 'invalid_events', __( 'Events must be an array', 'asce-tm' ) );
        }
        
        // Validate columns array
        if ( ! empty( $table['columns'] ) && ! is_array( $table['columns'] ) ) {
            return new WP_Error( 'invalid_columns', __( 'Columns must be an array', 'asce-tm' ) );
        }
        
        return true;
    }
    
    /**
     * Validate event configuration
     * 
     * @param array $event Event configuration
     * @return bool|WP_Error
     */
    public static function validate_event( $event ) {
        if ( ! is_array( $event ) ) {
            return new WP_Error( 'invalid_event', __( 'Invalid event configuration', 'asce-tm' ) );
        }
        
        $event_id = absint( $event['event_id'] ?? 0 );
        
        if ( $event_id <= 0 ) {
            return new WP_Error( 'invalid_event_id', __( 'Valid event ID is required', 'asce-tm' ) );
        }
        
        // Verify event exists
        $EM_Event = em_get_event( $event_id );
        if ( ! $EM_Event || ! $EM_Event->event_id ) {
            return new WP_Error( 'event_not_found', sprintf( __( 'Event ID %d not found', 'asce-tm' ), $event_id ) );
        }
        
        return true;
    }
    
    /**
     * Validate ticket selection for cart
     * 
     * @param array $tickets Array of ticket selections
     * @return bool|WP_Error
     */
    public static function validate_cart_tickets( $tickets ) {
        if ( ! is_array( $tickets ) || empty( $tickets ) ) {
            return new WP_Error( 'no_tickets', __( 'No tickets selected', 'asce-tm' ) );
        }
        
        // Check ticket count limit
        if ( count( $tickets ) > ASCE_TM_MAX_CART_ITEMS ) {
            return new WP_Error( 
                'too_many_tickets', 
                sprintf( __( 'Maximum %d tickets allowed per transaction', 'asce-tm' ), ASCE_TM_MAX_CART_ITEMS )
            );
        }
        
        // Validate each ticket
        foreach ( $tickets as $ticket ) {
            if ( ! is_array( $ticket ) ) {
                return new WP_Error( 'invalid_ticket', __( 'Invalid ticket format', 'asce-tm' ) );
            }
            
            $required_fields = array( 'event_id', 'ticket_id', 'quantity' );
            foreach ( $required_fields as $field ) {
                if ( ! isset( $ticket[ $field ] ) ) {
                    return new WP_Error( 
                        'missing_field', 
                        sprintf( __( 'Missing required field: %s', 'asce-tm' ), $field )
                    );
                }
            }
            
            // Validate field types
            if ( absint( $ticket['event_id'] ) <= 0 ) {
                return new WP_Error( 'invalid_event_id', __( 'Invalid event ID', 'asce-tm' ) );
            }
            
            if ( absint( $ticket['ticket_id'] ) <= 0 ) {
                return new WP_Error( 'invalid_ticket_id', __( 'Invalid ticket ID', 'asce-tm' ) );
            }
            
            if ( absint( $ticket['quantity'] ) <= 0 ) {
                return new WP_Error( 'invalid_quantity', __( 'Quantity must be greater than 0', 'asce-tm' ) );
            }
        }
        
        return true;
    }
    
    /**
     * Sanitize table ID
     * 
     * @param string $table_id Table ID
     * @return string Sanitized table ID
     */
    public static function sanitize_table_id( $table_id ) {
        $table_id = sanitize_key( $table_id );
        
        // Ensure it starts with 'table_'
        if ( strpos( $table_id, 'table_' ) !== 0 ) {
            $table_id = 'table_' . $table_id;
        }
        
        return $table_id;
    }
    
    /**
     * Generate unique table ID
     * 
     * @return string Unique table ID
     */
    public static function generate_table_id() {
        return 'table_' . substr( md5( uniqid( rand(), true ) ), 0, 8 );
    }
}
