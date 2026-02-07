<?php
/**
 * ASCE Ticket Matrix Settings Class
 * 
 * Handles admin settings page and table configuration.
 * Manages multiple table configurations with flexible
 * event/column structures. Includes import/export functionality.
 * 
 * @package ASCE_Ticket_Matrix
 * @version 2.1.4
 * @since 1.0.0
 */

class ASCE_TM_Settings {
    
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'wp_ajax_asce_tm_get_event_tickets', array( __CLASS__, 'ajax_get_event_tickets' ) );
        add_action( 'wp_ajax_asce_tm_delete_table', array( __CLASS__, 'ajax_delete_table' ) );
        add_action( 'wp_ajax_asce_tm_get_table_config', array( __CLASS__, 'ajax_get_table_config' ) );
        add_action( 'wp_ajax_asce_tm_duplicate_table', array( __CLASS__, 'ajax_duplicate_table' ) );
        add_action( 'wp_ajax_asce_tm_toggle_archive', array( __CLASS__, 'ajax_toggle_archive' ) );
        add_action( 'wp_ajax_asce_tm_clear_cache', array( __CLASS__, 'ajax_clear_cache' ) );
        add_action( 'wp_ajax_asce_tm_preview_table', array( __CLASS__, 'ajax_preview_table' ) );
        add_action( 'wp_ajax_asce_tm_export_table', array( __CLASS__, 'ajax_export_table' ) );
        add_action( 'wp_ajax_asce_tm_import_table', array( __CLASS__, 'ajax_import_table' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_styles' ) );
    }
    
    /**
     * Add admin menu page
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=event',
            __( 'Ticket Matrix', 'asce-tm' ),
            __( 'Ticket Matrix', 'asce-tm' ),
            'manage_options',
            'asce-ticket-matrix',
            array( __CLASS__, 'render_settings_page' )
        );
    }
    
    /**
     * Register settings
     */
    public static function register_settings() {
        register_setting( 'asce_tm_settings', 'asce_tm_tables', array(
            'type' => 'array',
            'default' => array(),
            'sanitize_callback' => array( __CLASS__, 'sanitize_tables' )
        ) );
    }
    
    /**
     * Sanitize tables array
     */
    public static function sanitize_tables( $tables ) {
        if ( ! is_array( $tables ) ) {
            return array();
        }
        
        $sanitized = array();
        foreach ( $tables as $table_id => $table ) {
            if ( ! is_array( $table ) ) {
                continue;
            }
            
            $sanitized_table = array(
                'name' => sanitize_text_field( $table['name'] ?? '' ),
                'num_events' => absint( $table['num_events'] ?? 3 ),
                'num_columns' => absint( $table['num_columns'] ?? 2 ),
                'archived' => ! empty( $table['archived'] ),
                'forms_page_url' => ! empty( $table['forms_page_url'] ) ? esc_url_raw( $table['forms_page_url'] ) : '',
                'payment_gateway' => sanitize_text_field( $table['payment_gateway'] ?? 'stripe' ),
                'events' => array(),
                'columns' => array()
            );
            
            // Sanitize events
            if ( isset( $table['events'] ) && is_array( $table['events'] ) ) {
                foreach ( $table['events'] as $idx => $event ) {
                    $sanitized_table['events'][ $idx ] = array(
                        'event_id' => absint( $event['event_id'] ?? 0 ),
                        'label' => sanitize_text_field( $event['label'] ?? '' ),
                        'group' => sanitize_text_field( $event['group'] ?? '' )
                    );
                }
            }
            
            // Sanitize columns
            if ( isset( $table['columns'] ) && is_array( $table['columns'] ) ) {
                foreach ( $table['columns'] as $col_idx => $column ) {
                    $sanitized_table['columns'][ $col_idx ] = array(
                        'name' => sanitize_text_field( $column['name'] ?? '' ),
                        'tickets' => array()
                    );
                    
                    // Sanitize ticket selections for each event
                    if ( isset( $column['tickets'] ) && is_array( $column['tickets'] ) ) {
                        foreach ( $column['tickets'] as $event_idx => $ticket_id ) {
                            $sanitized_table['columns'][ $col_idx ]['tickets'][ $event_idx ] = absint( $ticket_id );
                        }
                    }
                }
            }
            
            $sanitized[ sanitize_key( $table_id ) ] = $sanitized_table;
        }
        
        return $sanitized;
    }
    
    /**
     * Render settings page
     */
    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        // Handle form submission
        if ( isset( $_POST['asce_tm_save_table'] ) && check_admin_referer( 'asce_tm_table_edit' ) ) {
            self::save_table_config();
        }
        
        // Check if editing a table
        $editing_table_id = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : '';
        $action = $editing_table_id ? 'edit' : 'list';
        
        // Get all tables
        $tables = get_option( 'asce_tm_tables', array() );
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            
            <?php if ( $action === 'list' ) : ?>
                <?php self::render_tables_list( $tables ); ?>
            <?php else : ?>
                <?php self::render_table_editor( $editing_table_id, $tables ); ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render list of tables
     */
    private static function render_tables_list( $tables ) {
        // Early bailout if no tables
        if ( empty( $tables ) ) {
            $tables = array();
        }
        
        // Check if showing archived
        $show_archived = isset( $_GET['archived'] ) && $_GET['archived'] === '1';
        
        // Filter tables based on archive status
        $filtered_tables = array_filter( $tables, function( $table ) use ( $show_archived ) {
            $is_archived = ! empty( $table['archived'] );
            return $show_archived ? $is_archived : ! $is_archived;
        });
        
        ?>
        <div class="asce-tm-tables-list">
            <div style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0;">
                <h2><?php echo $show_archived ? __( 'Archived Tables', 'asce-tm' ) : __( 'Ticket Matrix Tables', 'asce-tm' ); ?></h2>
                <div style="display: flex; gap: 10px;">
                    <button type="button" id="asce-tm-import-table" class="button" title="Import table configuration from JSON">
                        <?php _e( '📥 Import Table', 'asce-tm' ); ?>
                    </button>
                    <button type="button" id="asce-tm-clear-cache" class="button" title="Clear event and ticket caches">
                        <?php _e( '🔄 Clear Cache', 'asce-tm' ); ?>
                    </button>
                    <a href="<?php echo esc_url( add_query_arg( 'archived', $show_archived ? '0' : '1', admin_url( 'edit.php?post_type=event&page=asce-ticket-matrix' ) ) ); ?>" 
                       class="button">
                        <?php echo $show_archived ? __( 'View Active Tables', 'asce-tm' ) : __( 'View Archived Tables', 'asce-tm' ); ?>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( 'edit', 'new', admin_url( 'edit.php?post_type=event&page=asce-ticket-matrix' ) ) ); ?>" 
                       class="button button-primary">
                        <?php _e( 'Create New Table', 'asce-tm' ); ?>
                    </a>
                </div>
            </div>
            
            <!-- Hidden file input for import -->
            <input type="file" id="asce-tm-import-file" accept=".json" style="display: none;" />
            
            <p class="description">
                <?php _e( 'Create multiple ticket matrix tables with different configurations. Each table gets its own shortcode.', 'asce-tm' ); ?>
            </p>
            
            <?php if ( empty( $filtered_tables ) ) : ?>
                <div class="notice notice-info inline">
                    <p><?php echo $show_archived ? __( 'No archived tables.', 'asce-tm' ) : __( 'No tables configured yet. Click "Create New Table" to get started.', 'asce-tm' ); ?></p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="25%"><?php _e( 'Table Name', 'asce-tm' ); ?></th>
                            <th width="12%"><?php _e( 'Events', 'asce-tm' ); ?></th>
                            <th width="12%"><?php _e( 'Columns', 'asce-tm' ); ?></th>
                            <th width="28%"><?php _e( 'Shortcode', 'asce-tm' ); ?></th>
                            <th width="23%"><?php _e( 'Actions', 'asce-tm' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $filtered_tables as $table_id => $table ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $table['name'] ); ?></strong></td>
                            <td><?php echo esc_html( $table['num_events'] ); ?> events</td>
                            <td><?php echo esc_html( $table['num_columns'] ); ?> columns</td>
                            <td>
                                <code style="background: #f0f0f0; padding: 5px; display: inline-block;">
                                    [asce_ticket_matrix id="<?php echo esc_attr( $table_id ); ?>"]
                                </code>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( add_query_arg( 'edit', $table_id, admin_url( 'edit.php?post_type=event&page=asce-ticket-matrix' ) ) ); ?>" 
                                   class="button button-small">
                                    <?php _e( 'Edit', 'asce-tm' ); ?>
                                </a>
                                <button type="button" 
                                        class="button button-small asce-tm-duplicate-table"
                                        data-table-id="<?php echo esc_attr( $table_id ); ?>"
                                        data-table-name="<?php echo esc_attr( $table['name'] ); ?>">
                                    <?php _e( 'Duplicate', 'asce-tm' ); ?>
                                </button>
                                <button type="button" 
                                        class="button button-small asce-tm-export-table"
                                        data-table-id="<?php echo esc_attr( $table_id ); ?>"
                                        data-table-name="<?php echo esc_attr( $table['name'] ); ?>">
                                    <?php _e( 'Export', 'asce-tm' ); ?>
                                </button>
                                <button type="button" 
                                        class="button button-small asce-tm-toggle-archive"
                                        data-table-id="<?php echo esc_attr( $table_id ); ?>"
                                        data-archived="<?php echo $show_archived ? '1' : '0'; ?>">
                                    <?php echo $show_archived ? __( 'Unarchive', 'asce-tm' ) : __( 'Archive', 'asce-tm' ); ?>
                                </button>
                                <button type="button" 
                                        class="button button-small button-link-delete asce-tm-delete-table"
                                        data-table-id="<?php echo esc_attr( $table_id ); ?>"
                                        data-table-name="<?php echo esc_attr( $table['name'] ); ?>">
                                    <?php _e( 'Delete', 'asce-tm' ); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Export table handler
            $('.asce-tm-export-table').on('click', function() {
                var tableId = $(this).data('table-id');
                var tableName = $(this).data('table-name');
                var $btn = $(this);
                
                $btn.prop('disabled', true).text('Exporting...');
                
                $.post(ajaxurl, {
                    action: 'asce_tm_export_table',
                    table_id: tableId,
                    nonce: '<?php echo wp_create_nonce( 'asce_tm_admin' ); ?>'
                }, function(response) {
                    if (response.success) {
                        // Create a download link
                        var dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(response.data, null, 2));
                        var downloadAnchor = document.createElement('a');
                        downloadAnchor.setAttribute("href", dataStr);
                        downloadAnchor.setAttribute("download", "asce-tm-" + tableId + ".json");
                        document.body.appendChild(downloadAnchor);
                        downloadAnchor.click();
                        downloadAnchor.remove();
                        
                        $btn.text('✓ Exported!');
                        setTimeout(function() {
                            $btn.prop('disabled', false).text('Export');
                        }, 2000);
                    } else {
                        alert('Error exporting table: ' + (response.data || 'Unknown error'));
                        $btn.prop('disabled', false).text('Export');
                    }
                });
            });
            
            // Import table handler
            $('#asce-tm-import-table').on('click', function() {
                $('#asce-tm-import-file').click();
            });
            
            $('#asce-tm-import-file').on('change', function(e) {
                var file = e.target.files[0];
                if (!file) return;
                
                if (!file.name.endsWith('.json')) {
                    alert('Please select a valid JSON file.');
                    return;
                }
                
                var reader = new FileReader();
                reader.onload = function(e) {
                    try {
                        var tableData = JSON.parse(e.target.result);
                        
                        // Validate basic structure
                        if (!tableData.name || !tableData.events || !tableData.columns) {
                            alert('Invalid table configuration file.');
                            return;
                        }
                        
                        var newName = prompt('Enter a name for the imported table:', tableData.name + ' (Imported)');
                        if (!newName) return;
                        
                        $.post(ajaxurl, {
                            action: 'asce_tm_import_table',
                            table_data: JSON.stringify(tableData),
                            new_name: newName,
                            nonce: '<?php echo wp_create_nonce( 'asce_tm_admin' ); ?>'
                        }, function(response) {
                            if (response.success) {
                                alert('Table imported successfully!');
                                location.reload();
                            } else {
                                alert('Error importing table: ' + (response.data || 'Unknown error'));
                            }
                        });
                    } catch (err) {
                        alert('Error reading JSON file: ' + err.message);
                    }
                };
                reader.readAsText(file);
                
                // Reset file input
                $(this).val('');
            });
            
            $('#asce-tm-clear-cache').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Clearing...');
                
                $.post(ajaxurl, {
                    action: 'asce_tm_clear_cache',
                    nonce: '<?php echo wp_create_nonce( 'asce_tm_admin' ); ?>'
                }, function(response) {
                    if (response.success) {
                        $btn.text('✓ Cleared!');
                        setTimeout(function() {
                            $btn.prop('disabled', false).text('🔄 Clear Cache');
                        }, 2000);
                    } else {
                        alert('Error: ' + (response.data || 'Unknown error'));
                        $btn.prop('disabled', false).text('🔄 Clear Cache');
                    }
                });
            });
            
            $('.asce-tm-delete-table').on('click', function() {
                var tableId = $(this).data('table-id');
                var tableName = $(this).data('table-name');
                
                if (!confirm('Are you sure you want to delete the table "' + tableName + '"?')) {
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'asce_tm_delete_table',
                    table_id: tableId,
                    nonce: '<?php echo wp_create_nonce( 'asce_tm_admin' ); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error deleting table: ' + (response.data || 'Unknown error'));
                    }
                });
            });
            
            $('.asce-tm-duplicate-table').on('click', function() {
                var tableId = $(this).data('table-id');
                var tableName = $(this).data('table-name');
                var $btn = $(this);
                var newName = prompt('Enter name for duplicated table:', tableName + ' (Copy)');
                
                if (!newName) {
                    return;
                }
                
                // Show progress indicator
                $btn.prop('disabled', true).text('Duplicating...');
                
                $.post(ajaxurl, {
                    action: 'asce_tm_duplicate_table',
                    table_id: tableId,
                    new_name: newName,
                    nonce: '<?php echo wp_create_nonce( 'asce_tm_admin' ); ?>'
                }, function(response) {
                    if (response.success) {
                        $btn.text('✓ Duplicated!');
                        // Reload page to show the new table
                        setTimeout(function() {
                            location.reload();
                        }, 500);
                    } else {
                        alert('Error duplicating table: ' + (response.data || 'Unknown error'));
                        $btn.prop('disabled', false).text('Duplicate');
                    }
                });
            });
            
            $('.asce-tm-toggle-archive').on('click', function() {
                var tableId = $(this).data('table-id');
                var isArchived = $(this).data('archived') === '1';
                var action = isArchived ? 'unarchive' : 'archive';
                
                $.post(ajaxurl, {
                    action: 'asce_tm_toggle_archive',
                    table_id: tableId,
                    archive: !isArchived,
                    nonce: '<?php echo wp_create_nonce( 'asce_tm_admin' ); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + (response.data || 'Unknown error'));
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render table editor
     */
    private static function render_table_editor( $table_id, $tables ) {
        $is_new = ( $table_id === 'new' );
        $table = $is_new ? array(
            'name' => '',
            'num_events' => 3,
            'num_columns' => 2,
            'events' => array(),
            'columns' => array()
        ) : ( $tables[ $table_id ] ?? array() );
        
        // Get all events for dropdown (cached for 15 minutes)
        $cache_key = 'asce_tm_future_events';
        $all_events = get_transient( $cache_key );
        
        if ( false === $all_events ) {
            $all_events = EM_Events::get( array(
                'scope' => 'future',
                'limit' => 50, // Reduced from 100 for performance
                'orderby' => 'event_start_date',
                'order' => 'ASC'
            ) );
            set_transient( $cache_key, $all_events, 15 * MINUTE_IN_SECONDS );
        }
        
        ?>
        <div class="asce-tm-table-editor">
            <div style="margin: 20px 0;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=asce-ticket-matrix' ) ); ?>" class="button">
                    ← <?php _e( 'Back to Tables List', 'asce-tm' ); ?>
                </a>
            </div>
            
            <h2><?php echo $is_new ? __( 'Create New Table', 'asce-tm' ) : __( 'Edit Table', 'asce-tm' ); ?></h2>
            
            <form method="post" action="" id="asce-tm-table-form">
                <?php wp_nonce_field( 'asce_tm_table_edit' ); ?>
                <input type="hidden" name="table_id" value="<?php echo esc_attr( $is_new ? '' : $table_id ); ?>">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="table_name"><?php _e( 'Table Name', 'asce-tm' ); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="table_name" 
                                   name="table_name" 
                                   value="<?php echo esc_attr( $table['name'] ); ?>" 
                                   class="regular-text" 
                                   required>
                            <p class="description"><?php _e( 'A descriptive name for this table (e.g., "Early Bird Pricing" or "Regular Pricing")', 'asce-tm' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="num_events"><?php _e( 'Number of Events', 'asce-tm' ); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="num_events" 
                                   name="num_events" 
                                   value="<?php echo esc_attr( $table['num_events'] ); ?>" 
                                   min="1" 
                                   max="10" 
                                   class="small-text"
                                   required>
                            <p class="description"><?php _e( 'How many events will be displayed in this table? (1-10)', 'asce-tm' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="num_columns"><?php _e( 'Number of Ticket Columns', 'asce-tm' ); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="num_columns" 
                                   name="num_columns" 
                                   value="<?php echo esc_attr( $table['num_columns'] ); ?>" 
                                   min="1" 
                                   max="10" 
                                   class="small-text"
                                   required>
                            <p class="description"><?php _e( 'How many ticket option columns to display? (1-10)', 'asce-tm' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="forms_page_url"><?php _e( 'Forms Page URL', 'asce-tm' ); ?></label>
                        </th>
                        <td>
                            <input type="url" 
                                   id="forms_page_url" 
                                   name="forms_page_url" 
                                   value="<?php echo esc_attr( $table['forms_page_url'] ?? '' ); ?>" 
                                   class="regular-text">
                            <p class="description"><?php _e( 'Optional. If set, Step 2 (Forms) redirects here.', 'asce-tm' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="payment_gateway"><?php _e( 'Payment Gateway', 'asce-tm' ); ?></label>
                        </th>
                        <td>
                            <select id="payment_gateway" name="payment_gateway" class="regular-text">
                                <option value="stripe" <?php selected( $table['payment_gateway'] ?? 'stripe', 'stripe' ); ?>>Stripe</option>
                                <option value="stripe_elements" <?php selected( $table['payment_gateway'] ?? 'stripe', 'stripe_elements' ); ?>>Stripe Elements</option>
                                <option value="offline" <?php selected( $table['payment_gateway'] ?? 'stripe', 'offline' ); ?>>Offline Payment</option>
                            </select>
                            <p class="description"><?php _e( 'Select which payment gateway to use for this matrix checkout.', 'asce-tm' ); ?></p>
                        </td>
                    </tr>
                </table>
                
                <div style="margin: 20px 0; padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa;">
                    <strong style="display: block; margin-bottom: 10px;"><?php _e( 'Save Your Changes', 'asce-tm' ); ?></strong>
                    <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                        <?php submit_button( __( 'Save Table', 'asce-tm' ), 'primary large', 'asce_tm_save_table', false ); ?>
                        <button type="button" id="asce-tm-preview-table" class="button button-secondary">
                            <?php _e( 'Preview Table', 'asce-tm' ); ?>
                        </button>
                    </div>
                    <p class="description" style="margin: 0;"><?php _e( 'Click "Save Table" to save all changes including table settings, event selections, and ticket configurations.', 'asce-tm' ); ?></p>
                </div>
                
                <hr style="margin: 30px 0;">
                
                <div style="margin: 20px 0;">
                    <h3><?php _e( 'Change Table Dimensions', 'asce-tm' ); ?></h3>
                    <p class="description" style="margin-bottom: 10px;">
                        <?php _e( 'If you need to add or remove events/columns, click the button below. Note: This will reset the configuration form below, so save your work first!', 'asce-tm' ); ?>
                    </p>
                    <button type="button" id="asce-tm-update-structure" class="button button-secondary">
                        <?php _e( 'Update Table Structure', 'asce-tm' ); ?>
                    </button>
                </div>
                
                <div id="asce-tm-config-section" style="margin-top: 30px;">
                    <?php self::render_table_configuration( $table, $all_events ); ?>
                </div>
                
                <div style="margin: 20px 0; padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa;">
                    <strong style="display: block; margin-bottom: 10px;"><?php _e( 'Save Your Changes', 'asce-tm' ); ?></strong>
                    <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                        <?php submit_button( __( 'Save Table', 'asce-tm' ), 'primary large', 'asce_tm_save_table', false ); ?>
                        <button type="button" id="asce-tm-preview-table-2" class="button button-secondary asce-tm-preview-trigger">
                            <?php _e( 'Preview Table', 'asce-tm' ); ?>
                        </button>
                    </div>
                    <p class="description" style="margin: 0;"><?php _e( 'Click "Save Table" to save all changes including table settings, event selections, and ticket configurations.', 'asce-tm' ); ?></p>
                </div>
                
                <!-- Preview Container -->
                <div id="asce-tm-preview-container" style="display: none; margin-top: 30px; border: 2px solid #0073aa; padding: 20px; background: #f9f9f9;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h3 style="margin: 0;"><?php _e( 'Table Preview', 'asce-tm' ); ?></h3>
                        <button type="button" id="asce-tm-close-preview" class="button">
                            <?php _e( 'Close Preview', 'asce-tm' ); ?>
                        </button>
                    </div>
                    <div id="asce-tm-preview-content"></div>
                </div>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Update structure button
            $('#asce-tm-update-structure').on('click', function() {
                var numEvents = $('#num_events').val();
                var numColumns = $('#num_columns').val();
                var tableName = $('#table_name').val();
                
                if (!tableName) {
                    alert('Please enter a table name first.');
                    return;
                }
                
                // Load new configuration via AJAX
                $.post(ajaxurl, {
                    action: 'asce_tm_get_table_config',
                    num_events: numEvents,
                    num_columns: numColumns,
                    nonce: '<?php echo wp_create_nonce( 'asce_tm_admin' ); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#asce-tm-config-section').html(response.data.html);
                        initializeEventSelectors();
                    }
                });
            });
            
            // Initialize event selector change handlers
            function initializeEventSelectors() {
                $('.asce-tm-event-select').on('change', function() {
                    var eventIdx = $(this).data('event-idx');
                    var eventId = $(this).val();
                    
                    // Update ticket dropdowns for all columns
                    $('.asce-tm-ticket-select[data-event-idx="' + eventIdx + '"]').each(function() {
                        var $select = $(this);
                        var colIdx = $select.data('col-idx');
                        
                        if (!eventId) {
                            $select.html('<option value="">Select event first</option>');
                            return;
                        }
                        
                        // Load tickets for this event
                        $.post(ajaxurl, {
                            action: 'asce_tm_get_event_tickets',
                            event_id: eventId,
                            nonce: '<?php echo wp_create_nonce( 'asce_tm_admin' ); ?>'
                        }, function(response) {
                            if (response.success) {
                                $select.html(response.data.options_html);
                            }
                        });
                    });
                });
            }
            
            // Initialize on page load
            initializeEventSelectors();
            
            // Preview table button (both buttons trigger same preview)
            $('.asce-tm-preview-trigger, #asce-tm-preview-table, #asce-tm-preview-table-2').on('click', function() {
                var $button = $(this);
                var $container = $('#asce-tm-preview-container');
                var $content = $('#asce-tm-preview-content');
                
                // Gather current form data
                var formData = {
                    action: 'asce_tm_preview_table',
                    nonce: '<?php echo wp_create_nonce( 'asce_tm_admin' ); ?>',
                    table_id: $('input[name="table_id"]').val(),
                    table_name: $('#table_name').val(),
                    num_events: $('#num_events').val(),
                    num_columns: $('#num_columns').val(),
                    events: {},
                    columns: {}
                };
                
                // Gather events data
                $('select[name^="events["]').each(function() {
                    var name = $(this).attr('name');
                    var match = name.match(/events\[(\d+)\]\[event_id\]/);
                    if (match) {
                        var idx = match[1];
                        if (!formData.events[idx]) formData.events[idx] = {};
                        formData.events[idx].event_id = $(this).val();
                    }
                });
                
                $('input[name^="events["]').each(function() {
                    var name = $(this).attr('name');
                    var match = name.match(/events\[(\d+)\]\[(label|group)\]/);
                    if (match) {
                        var idx = match[1];
                        var field = match[2];
                        if (!formData.events[idx]) formData.events[idx] = {};
                        formData.events[idx][field] = $(this).val();
                    }
                });
                
                // Gather columns data
                $('input[name^="columns["]').each(function() {
                    var name = $(this).attr('name');
                    var match = name.match(/columns\[(\d+)\]\[name\]/);
                    if (match) {
                        var col = match[1];
                        if (!formData.columns[col]) formData.columns[col] = { name: '', tickets: {} };
                        formData.columns[col].name = $(this).val();
                    }
                });
                
                $('select[name^="columns["]').each(function() {
                    var name = $(this).attr('name');
                    var match = name.match(/columns\[(\d+)\]\[tickets\]\[(\d+)\]/);
                    if (match) {
                        var col = match[1];
                        var event = match[2];
                        if (!formData.columns[col]) formData.columns[col] = { name: '', tickets: {} };
                        formData.columns[col].tickets[event] = $(this).val();
                    }
                });
                
                // Show loading state
                $button.prop('disabled', true).text('<?php _e( 'Loading Preview...', 'asce-tm' ); ?>');
                $content.html('<p style="text-align: center;"><span class="spinner is-active" style="float: none; margin: 0;"></span> <?php _e( 'Loading preview...', 'asce-tm' ); ?></p>');
                $container.slideDown();
                
                // Load preview via AJAX
                $.post(ajaxurl, formData, function(response) {
                    $button.prop('disabled', false).text('<?php _e( 'Preview Table', 'asce-tm' ); ?>');
                    
                    if (response.success) {
                        $content.html(response.data.html);
                        // Scroll to preview
                        $('html, body').animate({
                            scrollTop: $container.offset().top - 50
                        }, 500);
                    } else {
                        $content.html('<div class="notice notice-error"><p>' + (response.data.message || '<?php _e( 'Error loading preview.', 'asce-tm' ); ?>') + '</p></div>');
                    }
                }).fail(function() {
                    $button.prop('disabled', false).text('<?php _e( 'Preview Table', 'asce-tm' ); ?>');
                    $content.html('<div class="notice notice-error"><p><?php _e( 'Error loading preview. Please try again.', 'asce-tm' ); ?></p></div>');
                });
            });
            
            // Close preview button
            $('#asce-tm-close-preview').on('click', function() {
                $('#asce-tm-preview-container').slideUp();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render table configuration form
     */
    private static function render_table_configuration( $table, $all_events ) {
        $num_events = absint( $table['num_events'] );
        $num_columns = absint( $table['num_columns'] );
        
        ?>
        <h3><?php _e( 'Column Names', 'asce-tm' ); ?></h3>
        <p class="description">
            <?php _e( 'First, name each ticket column (e.g., Early Bird, Regular, VIP, Student).', 'asce-tm' ); ?>
        </p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="15%"><?php _e( 'Column #', 'asce-tm' ); ?></th>
                    <th width="85%"><?php _e( 'Column Name', 'asce-tm' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php for ( $col = 0; $col < $num_columns; $col++ ) : 
                    $column = $table['columns'][ $col ] ?? array( 'name' => '', 'tickets' => array() );
                ?>
                <tr>
                    <td><strong><?php printf( __( 'Column %d', 'asce-tm' ), $col + 1 ); ?></strong></td>
                    <td>
                        <input type="text" 
                               name="columns[<?php echo $col; ?>][name]" 
                               value="<?php echo esc_attr( $column['name'] ); ?>" 
                               placeholder="<?php _e( 'e.g., Early Bird, Regular, VIP', 'asce-tm' ); ?>"
                               class="regular-text"
                               style="width: 100%;"
                               required>
                    </td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        
        <h3 style="margin-top: 30px;"><?php _e( 'Event & Ticket Selection Matrix', 'asce-tm' ); ?></h3>
        <p class="description">
            <?php _e( 'For each event (row), select which ticket option should appear in each column.', 'asce-tm' ); ?>
        </p>
        
        <div class="asce-tm-matrix-config-wrap">
            <table class="wp-list-table widefat fixed striped asce-tm-matrix-config">
                <thead>
                    <tr>
                        <th style="width: 50px;"><?php _e( '#', 'asce-tm' ); ?></th>
                        <th style="min-width: 250px;"><?php _e( 'Event', 'asce-tm' ); ?></th>
                        <th style="min-width: 180px;"><?php _e( 'Custom Label (Optional)', 'asce-tm' ); ?></th>
                        <th style="min-width: 120px;"><?php _e( 'Exclusive Group', 'asce-tm' ); ?></th>
                        <?php for ( $col = 0; $col < $num_columns; $col++ ) : 
                            $column = $table['columns'][ $col ] ?? array( 'name' => '' );
                        ?>
                            <th style="min-width: 200px;">
                                <?php echo $column['name'] ? esc_html( $column['name'] ) : sprintf( __( 'Column %d', 'asce-tm' ), $col + 1 ); ?>
                            </th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php for ( $i = 0; $i < $num_events; $i++ ) : 
                        $event = $table['events'][ $i ] ?? array( 'event_id' => 0, 'label' => '' );
                        $em_event = $event['event_id'] ? em_get_event( $event['event_id'] ) : null;
                    ?>
                    <tr>
                        <td><strong><?php echo $i + 1; ?></strong></td>
                        <td>
                            <select name="events[<?php echo $i; ?>][event_id]" 
                                    class="asce-tm-event-select" 
                                    data-event-idx="<?php echo $i; ?>"
                                    style="width: 100%;" 
                                    required>
                                <option value=""><?php _e( 'Select Event', 'asce-tm' ); ?></option>
                                <?php foreach ( $all_events as $em_event_option ) : ?>
                                    <option value="<?php echo esc_attr( $em_event_option->event_id ); ?>" 
                                            <?php selected( $event['event_id'], $em_event_option->event_id ); ?>>
                                        <?php echo esc_html( $em_event_option->event_name . ' - ' . $em_event_option->output( '#_EVENTDATES' ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="text" 
                                   name="events[<?php echo $i; ?>][label]" 
                                   value="<?php echo esc_attr( $event['label'] ); ?>" 
                                   placeholder="<?php _e( 'Leave blank to use event name', 'asce-tm' ); ?>"
                                   style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" 
                                   name="events[<?php echo $i; ?>][group]" 
                                   value="<?php echo esc_attr( $event['group'] ?? '' ); ?>" 
                                   placeholder="<?php _e( 'e.g., A, B, 1, 2', 'asce-tm' ); ?>"
                                   title="<?php _e( 'Events with the same group cannot both be selected', 'asce-tm' ); ?>"
                                   style="width: 100%;">
                        </td>
                        <?php for ( $col = 0; $col < $num_columns; $col++ ) : 
                            $column = $table['columns'][ $col ] ?? array( 'name' => '', 'tickets' => array() );
                            $selected_ticket = $column['tickets'][ $i ] ?? 0;
                        ?>
                        <td>
                            <select name="columns[<?php echo $col; ?>][tickets][<?php echo $i; ?>]" 
                                    class="asce-tm-ticket-select"
                                    data-event-idx="<?php echo $i; ?>"
                                    data-col-idx="<?php echo $col; ?>"
                                    style="width: 100%;">
                                <?php if ( $em_event && $em_event->event_id ) : 
                                    $tickets = $em_event->get_tickets()->tickets;
                                ?>
                                    <option value=""><?php _e( 'Select Ticket', 'asce-tm' ); ?></option>
                                    <?php foreach ( $tickets as $ticket ) : ?>
                                        <option value="<?php echo esc_attr( $ticket->ticket_id ); ?>"
                                                <?php selected( $selected_ticket, $ticket->ticket_id ); ?>>
                                            <?php echo esc_html( $ticket->ticket_name . ' - ' . $ticket->get_price( true ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <option value=""><?php _e( 'Select event first', 'asce-tm' ); ?></option>
                                <?php endif; ?>
                            </select>
                        </td>
                        <?php endfor; ?>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Save table configuration
     */
    private static function save_table_config() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        $table_id = isset( $_POST['table_id'] ) && $_POST['table_id'] ? sanitize_key( $_POST['table_id'] ) : 'table_' . uniqid();
        $tables = get_option( 'asce_tm_tables', array() );
        
        // Debug: Log incoming payment_gateway value
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[ASCE TM] Saving table - Payment Gateway from POST: ' . ( $_POST['payment_gateway'] ?? 'NOT SET' ) );
        }
        
        // Build unsanitized array first
        $tables[ $table_id ] = array(
            'name' => sanitize_text_field( $_POST['table_name'] ?? '' ),
            'num_events' => absint( $_POST['num_events'] ?? 3 ),
            'num_columns' => absint( $_POST['num_columns'] ?? 2 ),
            'forms_page_url' => esc_url_raw( $_POST['forms_page_url'] ?? '' ),
            'payment_gateway' => sanitize_text_field( $_POST['payment_gateway'] ?? 'stripe' ),
            'events' => $_POST['events'] ?? array(),
            'columns' => $_POST['columns'] ?? array()
        );
        
        // Debug: Log value before sanitization
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[ASCE TM] Payment Gateway before sanitization: ' . $tables[ $table_id ]['payment_gateway'] );
        }
        
        // Apply sanitization before saving (security: prevent XSS/garbage data)
        $tables = self::sanitize_tables( $tables );
        
        // Debug: Log value after sanitization
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[ASCE TM] Payment Gateway after sanitization: ' . ( $tables[ $table_id ]['payment_gateway'] ?? 'MISSING' ) );
        }
        
        update_option( 'asce_tm_tables', $tables );
        
        // Debug: Verify saved value
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $saved_tables = get_option( 'asce_tm_tables', array() );
            error_log( '[ASCE TM] Payment Gateway after save (verified): ' . ( $saved_tables[ $table_id ]['payment_gateway'] ?? 'MISSING' ) );
        }
        
        // Clear all caches when table is saved
        self::clear_all_caches();
        
        // Redirect back to list
        wp_redirect( admin_url( 'edit.php?post_type=event&page=asce-ticket-matrix&saved=1' ) );
        exit;
    }
    
    /**
     * AJAX: Get event tickets
     */
    public static function ajax_get_event_tickets() {
        check_ajax_referer( 'asce_tm_admin', 'nonce' );
        
        $event_id = absint( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) {
            wp_send_json_error( 'Invalid event ID' );
        }
        
        // Check cache first
        $cache_key = 'asce_tm_event_tickets_' . $event_id;
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            wp_send_json_success( array(
                'options_html' => $cached,
                'cached' => true
            ) );
        }
        
        $event = em_get_event( $event_id );
        if ( ! $event || ! $event->event_id ) {
            wp_send_json_error( 'Event not found' );
        }
        
        $tickets = $event->get_tickets()->tickets;
        if ( empty( $tickets ) ) {
            $html = '<option value="">No tickets found</option>';
        } else {
            $html = '<option value="">Select Ticket</option>';
            foreach ( $tickets as $ticket ) {
                $html .= sprintf(
                    '<option value="%d">%s - %s</option>',
                    $ticket->ticket_id,
                    esc_html( $ticket->ticket_name ),
                    $ticket->get_price( true )
                );
            }
        }
        
        // Cache for 15 minutes
        set_transient( $cache_key, $html, 15 * MINUTE_IN_SECONDS );
        
        wp_send_json_success( array(
            'options_html' => $html,
            'cached' => false
        ) );
    }
    
    /**
     * AJAX: Delete table
     */
    public static function ajax_delete_table() {
        check_ajax_referer( 'asce_tm_admin', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $table_id = sanitize_key( $_POST['table_id'] ?? '' );
        if ( ! $table_id ) {
            wp_send_json_error( 'Invalid table ID' );
        }
        
        $tables = get_option( 'asce_tm_tables', array() );
        if ( isset( $tables[ $table_id ] ) ) {
            unset( $tables[ $table_id ] );
            update_option( 'asce_tm_tables', $tables );
            wp_send_json_success();
        } else {
            wp_send_json_error( 'Table not found' );
        }
    }
    
    /**
     * AJAX: Get table configuration form
     */
    public static function ajax_get_table_config() {
        check_ajax_referer( 'asce_tm_admin', 'nonce' );
        
        $num_events = absint( $_POST['num_events'] ?? 3 );
        $num_columns = absint( $_POST['num_columns'] ?? 2 );
        
        $table = array(
            'name' => '',
            'num_events' => $num_events,
            'num_columns' => $num_columns,
            'forms_page_url' => '',
            'events' => array(),
            'columns' => array()
        );
        
        // Use cached events to match render_table_editor
        $cache_key = 'asce_tm_future_events';
        $all_events = get_transient( $cache_key );
        
        if ( false === $all_events ) {
            $all_events = EM_Events::get( array(
                'scope' => 'future',
                'limit' => 50,
                'orderby' => 'event_start_date',
                'order' => 'ASC'
            ) );
            set_transient( $cache_key, $all_events, 15 * MINUTE_IN_SECONDS );
        }
        
        ob_start();
        self::render_table_configuration( $table, $all_events );
        $html = ob_get_clean();
        
        wp_send_json_success( array( 'html' => $html ) );
    }
    
    /**
     * AJAX: Duplicate table
     */
    public static function ajax_duplicate_table() {
        check_ajax_referer( 'asce_tm_admin', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $table_id = sanitize_key( $_POST['table_id'] ?? '' );
        $new_name = sanitize_text_field( $_POST['new_name'] ?? '' );
        
        if ( ! $table_id || ! $new_name ) {
            wp_send_json_error( 'Invalid parameters' );
        }
        
        $tables = get_option( 'asce_tm_tables', array() );
        
        if ( ! isset( $tables[ $table_id ] ) ) {
            wp_send_json_error( 'Table not found' );
        }
        
        // Duplicate the table
        $new_table = $tables[ $table_id ];
        $new_table['name'] = $new_name;
        $new_table['archived'] = false; // New table is always active
        
        $new_table_id = 'table_' . uniqid();
        $tables[ $new_table_id ] = $new_table;
        
        update_option( 'asce_tm_tables', $tables );
        
        wp_send_json_success();
    }
    
    /**
     * AJAX: Toggle archive status
     */
    public static function ajax_toggle_archive() {
        check_ajax_referer( 'asce_tm_admin', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $table_id = sanitize_key( $_POST['table_id'] ?? '' );
        $archive = ! empty( $_POST['archive'] );
        
        if ( ! $table_id ) {
            wp_send_json_error( 'Invalid table ID' );
        }
        
        $tables = get_option( 'asce_tm_tables', array() );
        
        if ( ! isset( $tables[ $table_id ] ) ) {
            wp_send_json_error( 'Table not found' );
        }
        
        $tables[ $table_id ]['archived'] = $archive;
        update_option( 'asce_tm_tables', $tables );
        
        wp_send_json_success();
    }
    
    /**
     * AJAX: Clear all plugin caches
     */
    public static function ajax_clear_cache() {
        check_ajax_referer( 'asce_tm_admin', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        self::clear_all_caches();
        
        wp_send_json_success( array(
            'message' => 'Cache cleared successfully'
        ) );
    }
    
    /**
     * Clear all plugin caches
     */
    private static function clear_all_caches() {
        global $wpdb;
        
        // OPTIMIZATION: Instead of LIKE query (can lock large tables), fetch keys first then delete
        // This is safer on large wp_options tables and allows for better query optimization
        
        // Fetch transient keys (SELECT is non-blocking with index)
        $keys = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_asce_tm_%' 
             OR option_name LIKE '_transient_timeout_asce_tm_%'
             LIMIT 1000"
        );
        
        if ( empty( $keys ) ) {
            return;
        }
        
        // Delete in smaller batches using IN() for better performance
        // This avoids long-running DELETE with LIKE which can lock the table
        $batch_size = 100;
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
    
    /**
     * AJAX: Preview table
     */
    public static function ajax_preview_table() {
        check_ajax_referer( 'asce_tm_admin', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
        }
        
        // Build temporary table array from POST data
        $table = array(
            'name' => sanitize_text_field( $_POST['table_name'] ?? '' ),
            'num_events' => absint( $_POST['num_events'] ?? 0 ),
            'num_columns' => absint( $_POST['num_columns'] ?? 0 ),
            'events' => array(),
            'columns' => array()
        );
        
        // Parse events
        if ( ! empty( $_POST['events'] ) && is_array( $_POST['events'] ) ) {
            foreach ( $_POST['events'] as $idx => $event ) {
                $table['events'][ $idx ] = array(
                    'event_id' => absint( $event['event_id'] ?? 0 ),
                    'label' => sanitize_text_field( $event['label'] ?? '' ),
                    'group' => sanitize_text_field( $event['group'] ?? '' )
                );
            }
        }
        
        // Parse columns
        if ( ! empty( $_POST['columns'] ) && is_array( $_POST['columns'] ) ) {
            foreach ( $_POST['columns'] as $col_idx => $column ) {
                $table['columns'][ $col_idx ] = array(
                    'name' => sanitize_text_field( $column['name'] ?? '' ),
                    'tickets' => array()
                );
                
                if ( ! empty( $column['tickets'] ) && is_array( $column['tickets'] ) ) {
                    foreach ( $column['tickets'] as $event_idx => $ticket_id ) {
                        $table['columns'][ $col_idx ]['tickets'][ $event_idx ] = absint( $ticket_id );
                    }
                }
            }
        }
        
        // Validate minimum requirements
        if ( empty( $table['name'] ) ) {
            wp_send_json_error( array( 'message' => 'Please enter a table name.' ) );
        }
        
        if ( empty( $table['events'] ) ) {
            wp_send_json_error( array( 'message' => 'Please select at least one event.' ) );
        }
        
        // Generate preview HTML
        ob_start();
        echo '<div style="background: white; padding: 20px; border-radius: 4px;">';
        echo '<p class="description" style="margin-bottom: 15px;">';
        _e( 'This is a preview of how your table will appear on the frontend. Note: The cart functionality is disabled in preview mode.', 'asce-tm' );
        echo '</p>';
        
        // Use the same rendering function as frontend
        echo '<style>';
        // Include basic styles inline for preview
        echo file_get_contents( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/css/ticket-matrix.css' );
        echo '</style>';
        
        // Disable cache for preview
        add_filter( 'asce_tm_cache_duration', '__return_zero' );
        
        // Render the table
        try {
            ASCE_TM_Matrix::render_table_preview( $table );
        } catch ( Exception $e ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
        }
        
        echo '</div>';
        $html = ob_get_clean();
        
        wp_send_json_success( array( 'html' => $html ) );
    }
    
    /**
     * AJAX: Export table configuration
     */
    public static function ajax_export_table() {
        check_ajax_referer( 'asce_tm_admin', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $table_id = sanitize_key( $_POST['table_id'] ?? '' );
        if ( empty( $table_id ) ) {
            wp_send_json_error( 'Invalid table ID' );
        }
        
        $tables = get_option( 'asce_tm_tables', array() );
        if ( ! isset( $tables[ $table_id ] ) ) {
            wp_send_json_error( 'Table not found' );
        }
        
        $table_data = $tables[ $table_id ];
        
        // Add metadata
        $export_data = array(
            'version' => '2.0.1',
            'exported_at' => current_time( 'mysql' ),
            'table' => $table_data
        );
        
        wp_send_json_success( $export_data );
    }
    
    /**
     * AJAX: Import table configuration
     */
    public static function ajax_import_table() {
        check_ajax_referer( 'asce_tm_admin', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        
        $table_data_json = wp_unslash( $_POST['table_data'] ?? '' );
        $new_name = sanitize_text_field( $_POST['new_name'] ?? '' );
        
        if ( empty( $table_data_json ) || empty( $new_name ) ) {
            wp_send_json_error( 'Missing required data' );
        }
        
        $import_data = json_decode( $table_data_json, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( 'Invalid JSON data' );
        }
        
        // Extract table data (handle both direct table data and wrapped format)
        $table_data = isset( $import_data['table'] ) ? $import_data['table'] : $import_data;
        
        // Validate required fields
        if ( ! isset( $table_data['events'] ) || ! isset( $table_data['columns'] ) ) {
            wp_send_json_error( 'Invalid table structure' );
        }
        
        // Sanitize the imported data
        $sanitized_table = self::sanitize_tables( array( 'temp' => $table_data ) );
        if ( empty( $sanitized_table['temp'] ) ) {
            wp_send_json_error( 'Failed to sanitize table data' );
        }
        
        $table_data = $sanitized_table['temp'];
        $table_data['name'] = $new_name; // Override with user-provided name
        $table_data['archived'] = false; // Don't import as archived
        
        // Generate unique table ID
        $tables = get_option( 'asce_tm_tables', array() );
        $table_id = 'table_' . time() . '_' . wp_rand( 1000, 9999 );
        
        // Save the imported table
        $tables[ $table_id ] = $table_data;
        update_option( 'asce_tm_tables', $tables );
        
        wp_send_json_success( array(
            'message' => 'Table imported successfully',
            'table_id' => $table_id
        ) );
    }
    
    /**
     * Enqueue admin styles
     */
    public static function enqueue_admin_styles( $hook ) {
        if ( $hook === 'event_page_asce-ticket-matrix' ) {
            wp_enqueue_style( 
                'asce-tm-preview', 
                plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/ticket-matrix.css',
                array(),
                '1.0.0'
            );
        }
    }
}
