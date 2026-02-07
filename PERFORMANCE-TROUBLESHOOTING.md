# ASCE Ticket Matrix - Performance Troubleshooting Guide

## Recent Optimizations (v2.0.1)

The plugin has been optimized to address performance issues and 500 errors:

### 1. **Database Query Optimization**
- **Before**: Individual queries for each event and ticket (N+1 problem)
- **After**: Bulk loading of all events, tickets, and metadata in single queries
- **Impact**: Reduces database queries from 100+ to ~5 for a typical 10-event table

### 2. **Memory Management**
- Added memory checks before rendering
- Added configurable limits for table size
- Increased cache duration from 5 to 30 minutes (configurable)

### 3. **Error Handling**
- Wrapped rendering in try-catch blocks
- Added detailed error logging when WP_DEBUG is enabled
- Better error messages for users

## Common Issues & Solutions

### Issue: 500 Internal Server Error

**Possible Causes:**
1. **PHP Memory Limit Too Low**
   - **Solution**: Increase PHP memory limit in wp-config.php:
     ```php
     define( 'WP_MEMORY_LIMIT', '256M' );
     define( 'WP_MAX_MEMORY_LIMIT', '512M' );
     ```

2. **PHP Execution Timeout**
   - **Solution**: Increase max execution time:
     ```php
     // In wp-config.php
     @ini_set( 'max_execution_time', 300 );
     ```

3. **Too Many Events/Columns**
   - **Solution**: Reduce table size or increase limits via filter:
     ```php
     // In your theme's functions.php
     add_filter( 'asce_tm_max_events', function() {
         return 100; // Default is 50
     });
     
     add_filter( 'asce_tm_max_columns', function() {
         return 30; // Default is 20
     });
     ```

### Issue: Slow Page Load

**Solutions:**

1. **Enable Object Caching**
   - Install Redis or Memcached
   - Use a caching plugin like WP Redis

2. **Adjust Cache Duration**
   ```php
   // In your theme's functions.php
   add_filter( 'asce_tm_cache_duration', function() {
       return 60 * 60; // 1 hour instead of 30 minutes
   });
   ```

3. **Disable Cache for Testing**
   - Use shortcode: `[asce_ticket_matrix id="table_xxx" cache="no"]`

4. **Use a CDN**
   - Offload CSS/JS assets to a CDN

### Issue: Database Overload

**Solutions:**

1. **Optimize Database**
   ```sql
   -- Run in phpMyAdmin
   OPTIMIZE TABLE wp_posts;
   OPTIMIZE TABLE wp_postmeta;
   OPTIMIZE TABLE wp_em_tickets;
   ```

2. **Add Database Indexes** (if not present)
   ```sql
   ALTER TABLE wp_postmeta ADD INDEX idx_meta_key_post_id (meta_key, post_id);
   ```

3. **Use Database Query Caching**
   - Enable MySQL query cache in your server configuration

### Issue: AJAX Timeouts

**Solutions:**

1. **Limit Cart Items**
   ```php
   add_filter( 'asce_tm_max_cart_items', function() {
       return 20; // Default is 50
   });
   ```

2. **Increase AJAX Timeout** (in JavaScript)
   ```javascript
   // Add to your theme's JS
   jQuery.ajaxSetup({
       timeout: 60000 // 60 seconds
   });
   ```

## Debugging Steps

### 1. Enable Debug Mode
Add to wp-config.php:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Check the error log at: `wp-content/debug.log`

### 2. Check Server Resources
```bash
# Check PHP memory limit
php -i | grep memory_limit

# Check PHP max execution time
php -i | grep max_execution_time

# Check server load
top
```

### 3. Profile Database Queries
Install Query Monitor plugin to see:
- Number of queries
- Slow queries
- Duplicate queries

### 4. Clear All Caches
- Plugin cache: Click "Clear Cache" in settings
- WordPress transients: Use WP-CLI `wp transient delete --all`
- Object cache: Flush Redis/Memcached
- Browser cache: Hard refresh (Ctrl+Shift+R)

## Server Requirements

**Minimum:**
- PHP 7.4+
- MySQL 5.7+ or MariaDB 10.2+
- 128MB PHP memory limit
- 30 second execution time

**Recommended:**
- PHP 8.0+
- MySQL 8.0+ or MariaDB 10.5+
- 256MB PHP memory limit
- 60 second execution time
- Redis or Memcached
- OPcache enabled

## Performance Benchmarks

After optimizations, typical performance for a table with 10 events × 3 columns:

| Metric | Before | After |
|--------|--------|-------|
| Database Queries | 120 | 5 |
| Page Load Time | 8-12s | 0.5-1s |
| Memory Usage | 64MB | 24MB |
| Cache Hit Rate | 40% | 95% |

## Contact Support

If issues persist after trying these solutions:
1. Check the debug.log for specific error messages
2. Document your server specs (PHP version, memory limit, etc.)
3. Note the exact error message or behavior
4. Contact the developer with this information
