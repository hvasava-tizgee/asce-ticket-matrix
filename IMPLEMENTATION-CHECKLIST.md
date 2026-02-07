# Implementation Checklist

## ASCE Ticket Matrix v2.0.2 - Export/Import Feature

### Pre-Deployment Checklist

#### 1. File Verification
- [x] `asce-ticket-matrix.php` - Updated to v2.0.2
- [x] `includes/class-asce-tm-settings.php` - Added export/import functionality
- [x] `includes/class-asce-tm-matrix.php` - Complete rewrite (v2.0)
- [x] `assets/css/ticket-matrix.css` - Updated with admin styles
- [x] `assets/js/ticket-matrix.js` - Frontend scripts verified
- [x] `includes/class-asce-tm-ajax.php` - AJAX handlers verified

#### 1a. New Feature Verification (v2.0.2)
- [ ] Export button appears next to each table
- [ ] Import button appears at top of tables list
- [ ] Clicking Export downloads JSON file
- [ ] Clicking Import opens file selector
- [ ] Import prompts for new table name
- [ ] Imported table appears in list
- [ ] Exported JSON contains correct data structure
- [ ] Import validates JSON structure
- [ ] Import sanitizes all data properly

#### 2. Extraneous Files Removed
- [x] `check-tickets-debug.php` - Removed
- [x] `diagnostics.php` - Removed
- [x] `includes/class-asce-tm-settings-backup.php` - Removed
- [x] `includes/class-asce-tm-matrix-backup.php` - Removed
- [x] `README.txt` (incorrect) - Removed

#### 3. Documentation Updated
- [x] `README.md` - Updated to v2.0.1
- [x] `TABLE-SETUP-GUIDE.md` - Complete setup instructions
- [x] `USAGE-EXAMPLES.md` - Real-world examples
- [x] `UPDATE-SUMMARY.md` - Version history
- [x] `ARCHITECTURE.md` - Technical documentation
- [x] `IMPLEMENTATION-CHECKLIST.md` - This file

#### 4. WordPress Admin Testing
- [ ] Navigate to "Ticket Matrix" menu
- [ ] Verify tables list page loads
- [ ] Click "Create New Table"
- [ ] Fill in table name, events, columns
- [ ] Click "Update Table Structure"
- [ ] Verify configuration form appears
- [ ] Select events from dropdowns
- [ ] Verify ticket dropdowns populate
- [ ] Enter custom column names
- [ ] Select tickets for each event/column
- [ ] Click "Save Table"
- [ ] Verify redirect to tables list
- [ ] Verify shortcode is generated
- [ ] Test "Edit" button
- [ ] Test "Delete" button (with confirmation)

#### 5. Frontend Testing
- [ ] Create a test page/post
- [ ] Add shortcode: `[asce_ticket_matrix id="table_xxx"]`
- [ ] Preview/publish the page
- [ ] Verify table displays correctly
- [ ] Verify event names and dates show
- [ ] Verify column headers display
- [ ] Verify ticket prices display
- [ ] Verify quantity inputs appear
- [ ] Test quantity input changes
- [ ] Verify "Sold Out" shows for unavailable tickets
- [ ] Verify "Expired" shows for past-date tickets
- [ ] Verify "Low Stock" warning for limited tickets
- [ ] Test "Add to Cart" button
- [ ] Verify cart summary updates
- [ ] Test "Clear All" button

#### 6. Multiple Tables Testing
- [ ] Create 2-3 different tables
- [ ] Add multiple shortcodes to same page
- [ ] Verify each table displays independently
- [ ] Verify cart works with selections from multiple tables

#### 7. Edge Cases
- [ ] Test with 1 event, 1 column
- [ ] Test with 10 events, 10 columns
- [ ] Test with empty ticket selection (should show "N/A")
- [ ] Test with sold out event
- [ ] Test with expired ticket
- [ ] Test with event that has no tickets
- [ ] Test deleting an event used in a table
- [ ] Test shortcode with invalid table ID

#### 8. Browser Compatibility
- [ ] Test in Chrome
- [ ] Test in Firefox
- [ ] Test in Safari
- [ ] Test in Edge

#### 9. Mobile Responsiveness
- [ ] Test on mobile phone
- [ ] Test on tablet
- [ ] Verify horizontal scrolling works
- [ ] Verify buttons are tappable

#### 10. Performance
- [ ] Check page load time with table
- [ ] Check admin page load time
- [ ] Verify no console errors
- [ ] Verify no PHP errors in debug log

---

## Post-Deployment Tasks

### Immediate (Day 1)
- [ ] Monitor for error reports
- [ ] Check WordPress debug log
- [ ] Verify caching plugins aren't causing issues
- [ ] Test on staging site first if possible

### Week 1
- [ ] Gather user feedback
- [ ] Document any issues encountered
- [ ] Create any needed custom tables for existing events
- [ ] Update any pages using old shortcode format

### Month 1
- [ ] Review table usage analytics (if tracking)
- [ ] Optimize based on real-world usage
- [ ] Consider additional features needed

---

## Migration Path for Existing Users

If you had v1.0 configured:

### Step 1: Document Old Configuration
- [ ] Screenshot old settings page
- [ ] Note which events were enabled
- [ ] Note which tickets were "early bird"
- [ ] Note which tickets were "regular"
- [ ] Note custom labels used

### Step 2: Create Equivalent Tables
- [ ] Create "Early Bird" table
  - Same events as before
  - One column named "Early Bird"
  - Select early bird tickets for each event
- [ ] Create "Regular" table
  - Same events as before
  - One column named "Regular"
  - Select regular tickets for each event

### Step 3: Update Pages
- [ ] Find all pages with `[asce_ticket_matrix]`
- [ ] Update to `[asce_ticket_matrix id="table_earlybird"]`
- [ ] Or add both tables side-by-side

### Step 4: Test Thoroughly
- [ ] Test each updated page
- [ ] Verify events display correctly
- [ ] Verify tickets are correct
- [ ] Test cart functionality

---

## Common Issues & Solutions

### Issue: "Please specify a table ID"
**Solution:** Add `id="table_xxx"` to shortcode

### Issue: Ticket dropdowns empty
**Solution:** 
1. Check event still exists
2. Check event has tickets
3. Check JavaScript console for errors

### Issue: Table doesn't save
**Solution:**
1. Check PHP error log
2. Verify user has admin permissions
3. Check for plugin conflicts

### Issue: Styling looks wrong
**Solution:**
1. Clear browser cache
2. Clear site cache (if using caching plugin)
3. Check CSS file loaded (view source)

### Issue: AJAX not working
**Solution:**
1. Check JavaScript console for errors
2. Verify jQuery is loaded
3. Check AJAX URL in page source

---

## Support Resources

### Documentation
- `TABLE-SETUP-GUIDE.md` - How to create tables
- `USAGE-EXAMPLES.md` - Real-world examples
- `UPDATE-SUMMARY.md` - What changed
- `README.md` - General plugin info

### WordPress Resources
- Events Manager documentation
- WordPress Shortcode Codex
- WordPress Options API

### Debug Tools
- Browser DevTools (F12)
- WordPress Debug Log
- Query Monitor plugin
- Debug Bar plugin

---

## Future Enhancements to Consider

Based on usage, consider adding:
- [ ] Table duplication feature
- [ ] Import/export functionality
- [ ] Drag-and-drop event ordering
- [ ] Visual table preview in admin
- [ ] Bulk ticket selection
- [ ] Table templates
- [ ] Advanced filtering options
- [ ] Mobile-optimized column collapsing
- [ ] Analytics dashboard

---

## Sign-Off

### Developer
- [ ] Code reviewed
- [ ] Tests passed
- [ ] Documentation complete
- [ ] Ready for deployment

**Developer:** _____________________ **Date:** _____

### QA
- [ ] Manual testing complete
- [ ] Edge cases tested
- [ ] Cross-browser tested
- [ ] Mobile tested

**QA:** _____________________ **Date:** _____

### Deployment
- [ ] Deployed to staging
- [ ] Staging tested
- [ ] Deployed to production
- [ ] Production verified

**DevOps:** _____________________ **Date:** _____

---

## Notes

_Use this space for any additional notes during implementation:_

