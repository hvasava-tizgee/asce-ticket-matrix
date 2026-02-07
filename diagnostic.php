<!DOCTYPE html>
<html>
<head>
    <title>ASCE TM Diagnostic</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; background: #f5f5f5; }
        .diagnostic { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 800px; margin: 0 auto; }
        h1 { color: #333; margin-top: 0; }
        .section { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #0073aa; }
        .version { font-size: 24px; font-weight: bold; color: #0073aa; }
        .status { display: inline-block; padding: 5px 15px; border-radius: 4px; font-weight: bold; margin: 5px 0; }
        .status.ok { background: #46b450; color: white; }
        .status.warning { background: #ffb900; color: white; }
        .status.error { background: #dc3232; color: white; }
        .info-grid { display: grid; grid-template-columns: 200px 1fr; gap: 10px; margin: 10px 0; }
        .info-label { font-weight: bold; color: #666; }
        .info-value { color: #333; }
        code { background: #eee; padding: 2px 6px; border-radius: 3px; }
        .instructions { background: #fffbcc; border-left: 4px solid #ffb900; padding: 15px; margin: 20px 0; }
        .instructions h3 { margin-top: 0; color: #996600; }
    </style>
</head>
<body>
    <div class="diagnostic">
        <h1>🔍 ASCE Ticket Matrix - Diagnostic Check</h1>
        
        <?php
        // Check if we're in WordPress
        if (!defined('ABSPATH')) {
            // Try to load WordPress
            $wp_load_paths = array(
                '../../../wp-load.php',
                '../../../../wp-load.php',
                '../../../../../wp-load.php'
            );
            
            $loaded = false;
            foreach ($wp_load_paths as $path) {
                if (file_exists($path)) {
                    require_once($path);
                    $loaded = true;
                    break;
                }
            }
            
            if (!$loaded) {
                echo '<div class="status error">❌ Could not load WordPress</div>';
                echo '<p>Place this file in your plugin directory: <code>wp-content/plugins/asce-ticket-matrix/diagnostic.php</code></p>';
                echo '<p>Then access via: <code>https://yoursite.com/wp-content/plugins/asce-ticket-matrix/diagnostic.php</code></p>';
                exit;
            }
        }
        
        // Get plugin data
        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        $plugin_file = __DIR__ . '/asce-ticket-matrix.php';
        $plugin_data = get_plugin_data($plugin_file);
        $version = $plugin_data['Version'];
        $is_active = is_plugin_active('asce-ticket-matrix/asce-ticket-matrix.php');
        
        // Version check
        echo '<div class="section">';
        echo '<h2>Plugin Version</h2>';
        echo '<div class="version">' . esc_html($version) . '</div>';
        
        if (version_compare($version, '3.0.0', '>=')) {
            echo '<div class="status ok">✓ Running v3.0.0+ (Native EM Pro Checkout)</div>';
        } else {
            echo '<div class="status error">✗ Old version detected (Custom Checkout)</div>';
        }
        
        if ($is_active) {
            echo '<div class="status ok">✓ Plugin Active</div>';
        } else {
            echo '<div class="status error">✗ Plugin Inactive</div>';
        }
        echo '</div>';
        
        // Expected behavior
        echo '<div class="section">';
        echo '<h2>Expected Behavior in v' . esc_html($version) . '</h2>';
        
        if (version_compare($version, '3.0.0', '>=')) {
            echo '<div class="info-grid">';
            echo '<div class="info-label">Checkout Flow:</div>';
            echo '<div class="info-value">Matrix → EM Pro Native Checkout</div>';
            
            echo '<div class="info-label">Forms:</div>';
            echo '<div class="info-value">EM Pro handles all forms</div>';
            
            echo '<div class="info-label">Payment:</div>';
            echo '<div class="info-value">EM Pro payment gateways</div>';
            
            echo '<div class="info-label">Deprecated Methods:</div>';
            echo '<div class="info-value"><code>finalize_bookings()</code>, <code>get_payment_gateways()</code> should NOT be called</div>';
            echo '</div>';
        } else {
            echo '<div class="info-grid">';
            echo '<div class="info-label">Checkout Flow:</div>';
            echo '<div class="info-value">Matrix → Custom Forms → Custom Payment (OUTDATED)</div>';
            echo '</div>';
        }
        echo '</div>';
        
        // JavaScript check
        echo '<div class="section">';
        echo '<h2>JavaScript Version Check</h2>';
        echo '<div id="js-version-check">';
        echo '<div class="status warning">⏳ Checking...</div>';
        echo '</div>';
        echo '</div>';
        
        // EM Pro status
        echo '<div class="section">';
        echo '<h2>Events Manager Pro Status</h2>';
        
        $has_em = class_exists('EM_Event');
        $has_em_pro = class_exists('EM_Pro');
        $mb_enabled = get_option('dbem_multiple_bookings');
        $checkout_page = get_option('dbem_multiple_bookings_checkout_page');
        
        echo '<div class="info-grid">';
        echo '<div class="info-label">Events Manager:</div>';
        echo '<div class="info-value">' . ($has_em ? '<span class="status ok">✓ Installed</span>' : '<span class="status error">✗ Not Found</span>') . '</div>';
        
        echo '<div class="info-label">EM Pro:</div>';
        echo '<div class="info-value">' . ($has_em_pro ? '<span class="status ok">✓ Installed</span>' : '<span class="status error">✗ Not Found</span>') . '</div>';
        
        echo '<div class="info-label">Multiple Bookings:</div>';
        echo '<div class="info-value">' . ($mb_enabled ? '<span class="status ok">✓ Enabled</span>' : '<span class="status error">✗ Disabled</span>') . '</div>';
        
        echo '<div class="info-label">Checkout Page:</div>';
        if ($checkout_page) {
            $page = get_post($checkout_page);
            echo '<div class="info-value"><span class="status ok">✓ Configured</span> (' . esc_html($page->post_title) . ')</div>';
        } else {
            echo '<div class="info-value"><span class="status error">✗ Not Set</span></div>';
        }
        
        // Check payment gateways
        if (class_exists('EM_Gateways')) {
            $active_gateways = EM_Gateways::active_gateways();
            echo '<div class="info-label">Payment Gateways:</div>';
            if (!empty($active_gateways) && is_array($active_gateways)) {
                $gateway_names = array_keys($active_gateways);
                echo '<div class="info-value"><span class="status ok">✓ ' . count($gateway_names) . ' Active</span> (' . implode(', ', $gateway_names) . ')</div>';
            } else {
                echo '<div class="info-value"><span class="status warning">⚠ None Active</span></div>';
            }
        }
        
        echo '</div>';
        echo '</div>';
        
        // Troubleshooting
        if (version_compare($version, '3.0.0', '>=')) {
            echo '<div class="instructions">';
            echo '<h3>🔧 If You See "No Payment Methods Available" Error:</h3>';
            echo '<p>This means your browser is loading OLD cached JavaScript from v2.x. Follow these steps:</p>';
            echo '<ol>';
            echo '<li><strong>Clear Browser Cache:</strong> Use browser settings to clear all cached files</li>';
            echo '<li><strong>Hard Refresh:</strong> Press <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>F5</kbd> (Windows) or <kbd>Cmd</kbd>+<kbd>Shift</kbd>+<kbd>R</kbd> (Mac)</li>';
            echo '<li><strong>Check Version Below:</strong> Reload this page and verify JavaScript version matches plugin version</li>';
            echo '<li><strong>Incognito Mode:</strong> Try opening your site in incognito/private browsing mode</li>';
            echo '</ol>';
            echo '<p><strong>What should happen:</strong> When you click "Proceed to Checkout" on the matrix, you should be redirected DIRECTLY to the EM Pro checkout page. No custom forms, no payment gateway selection popup.</p>';
            echo '</div>';
        }
        
        // Debug log check
        if (defined('WP_DEBUG') && WP_DEBUG) {
            echo '<div class="section">';
            echo '<h2>Debug Mode</h2>';
            echo '<div class="status warning">⚠ WP_DEBUG is enabled</div>';
            echo '<p>Check <code>/wp-content/debug.log</code> for:</p>';
            echo '<ul>';
            echo '<li><strong>Good:</strong> "ASCE TM: Cart session active, adding tickets"</li>';
            echo '<li><strong>Bad (v3.0.0+):</strong> "finalize_bookings called (DEPRECATED)" or "get_payment_gateways called (DEPRECATED)"</li>';
            echo '<li><strong>Bad:</strong> "Corrupted cart detected"</li>';
            echo '</ul>';
            echo '</div>';
        }
        ?>
        
        <div class="section">
            <h2>Next Steps</h2>
            <ol>
                <li>Verify plugin version shows <code>3.0.2</code> or higher</li>
                <li>Verify JavaScript version (below) matches plugin version</li>
                <li>If versions don't match, clear cache and hard refresh</li>
                <li>Test checkout: Select tickets → Click "Proceed to Checkout" → Should redirect to EM Pro page</li>
            </ol>
        </div>
    </div>
    
    <script>
        // Check if JavaScript has the correct version
        document.addEventListener('DOMContentLoaded', function() {
            var checkDiv = document.getElementById('js-version-check');
            
            // Check if asceTM object exists (loaded from ticket-matrix.js)
            if (typeof asceTM !== 'undefined') {
                var jsVersion = asceTM.version || 'Unknown';
                var phpVersion = '<?php echo esc_js($version); ?>';
                
                var html = '';
                if (jsVersion === phpVersion) {
                    html = '<div class="status ok">✓ JavaScript version matches: ' + jsVersion + '</div>';
                    html += '<p>JavaScript is correctly loaded and up-to-date.</p>';
                } else {
                    html = '<div class="status error">✗ Version mismatch detected!</div>';
                    html += '<p>PHP version: <code>' + phpVersion + '</code></p>';
                    html += '<p>JavaScript version: <code>' + jsVersion + '</code></p>';
                    html += '<p><strong>Solution:</strong> Clear browser cache and hard refresh (Ctrl+Shift+F5).</p>';
                }
                
                checkDiv.innerHTML = html;
            } else {
                checkDiv.innerHTML = '<div class="status warning">⚠ Plugin JavaScript not loaded on this page</div>' +
                    '<p>This is normal for this diagnostic page. Test on a page with your ticket matrix.</p>';
            }
        });
    </script>
</body>
</html>
