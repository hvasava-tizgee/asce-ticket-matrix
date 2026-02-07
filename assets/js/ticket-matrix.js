/*
 * ASCE Ticket Matrix → EM Pro Integration (Custom Matrix, Native Checkout)
 * Environment: WP Multisite + Events Manager Pro MB/cart enabled. DO NOT edit EM core.
 * Architecture: Custom ticket matrix frontend → EM Pro cart session → EM Pro native checkout/payment
 * User Flow: (1) Tickets (custom matrix) → (2) Checkout (EM Pro native page with forms/payment/success)
 * Incremental rule: Do not revert prior working features. Make surgical edits only. Prefer additive changes. Preserve exclusive group logic and ticket selection UI.
 * Debug visibility: admin-only, behind isAdmin.
 *
 * @version 3.0.3
 */
/**
 * ASCE Ticket Matrix JavaScript
 * Handles frontend interactions for ticket selection matrix
 * 
 * Supports multiple independent matrices on the same page with scoped state management.
 * Each matrix instance maintains its own selection state and operates independently.
 * 
 * @version 3.0.3
 * @requires jQuery
 */
(function($) {
    'use strict';
    
    /**
     * TicketMatrix Constructor
     * Creates an isolated instance for each matrix container
     * 
     * @param {jQuery} $container The matrix container element
     */
    function TicketMatrix($container) {
        this.$container = $container;
        this.$root = $container.closest('.asce-tm-instance');
        this.selectedTickets = [];
        this.totalAmount = 0;
        this.totalItems = 0;
        this.pricingMode = $container.data('pricing-mode') || 'separate_tables';
        this.eventLimits = {}; // Track quantities per event
        this.isProcessing = false; // Prevent duplicate submissions
        
        this.init();
    }
    
    /**
     * TicketMatrix Prototype Methods
     * All methods operate within the scope of their specific container
     */
    TicketMatrix.prototype = {
        
        init: function() {
            this.bindEvents();
            this.updateSummary();
            this.updateStepperVisibility();
        },
        
        /**
         * Get all checked radio buttons within this matrix instance
         * This is the single source of truth for selected tickets
         * 
         * @param {jQuery} $root Optional root element (defaults to this.$root)
         * @return {jQuery} Collection of checked radio inputs
         */
        getCheckedRadios: function($root) {
            $root = $root || this.$root;
            var $container = $root.find('.asce-ticket-matrix-container').first();
            if ($container.length === 0) {
                $container = this.$container;
            }
            return $container.find('input[type="radio"][data-event-id][data-ticket-id]:checked');
        },
        
        bindEvents: function() {
            var self = this;
            var $container = this.$container;
            
            // Pricing tier toggle - scoped to this container
            $container.find('input[name="pricing_tier"]').on('change', function() {
                var selectedTier = $(this).val();
                self.togglePricingTier(selectedTier);
            });
            
            // Delegated handler for radio changes - handles dynamically added radios
            $container.on('change', 'input[type="radio"][data-event-id][data-ticket-id]', function() {
                var $radio = $(this);
                var $root = $radio.closest('.asce-tm-instance');
                
                if ($radio.is(':checked')) {
                    // Enforce exclusive groups
                    var groupKey = ($radio.data('exclusive-group') || '').toString().trim();
                    if (groupKey !== '') {
                        // Uncheck other radios in the same exclusive group
                        $root.find('input[type="radio"][data-event-id][data-ticket-id]:checked')
                            .filter(function(){ return (($(this).data('exclusive-group') || '').toString().trim() === groupKey); })
                            .not($radio)
                            .prop('checked', false);
                    }
                }
                
                self.updateSummary();
                self.updateStepperVisibility();
            });
            
            // Clear all button - scoped to this container
            $container.on('click', '.asce-tm-clear-cart', function(e) {
                e.preventDefault();
                self.clearAll();
            });
            
            // Checkout button - scoped to this container
            // v3.5.29: Use direct checkout - EM Pro handles booking forms on checkout page
            $container.on('click', '.asce-tm-checkout', function(e) {
                e.preventDefault();
                console.log('Checkout button clicked - proceeding to EM Pro checkout');
                self.checkoutDirectly();
            });
            
            // Forms submit button - needs to be at instance level since forms panel is outside container
            var $instance = $container.closest('.asce-tm-instance');
            $instance.on('click', '.asce-tm-submit-forms', function(e) {
                e.preventDefault();
                console.log('Submit forms button clicked');
                self.submitFormsAndCheckout();
            });
            
            // Back to tickets button
            $instance.on('click', '.asce-tm-back-to-tickets', function(e) {
                e.preventDefault();
                console.log('Back to tickets clicked');
                $instance.find('.asce-tm-panel-forms').removeClass('active').hide();
                $instance.find('.asce-tm-panel-tickets').addClass('active').show();
                // Reset stepper
                $instance.find('.asce-tm-step').removeClass('active completed');
                $instance.find('.asce-tm-step[data-step="1"]').addClass('active');
            });
        },
        
        togglePricingTier: function(tier) {
            var $container = this.$container;
            
            // Only relevant for toggle mode
            if (this.pricingMode !== 'toggle') {
                return;
            }
            
            // Hide all ticket options in this container
            $container.find('.asce-tm-ticket-option').hide();
            
            // Show selected tier in this container
            $container.find('.asce-tm-ticket-option[data-pricing-tier="' + tier + '"]').show();
            
            // Clear hidden radios and recalculate
            $container.find('.asce-tm-ticket-option:hidden .asce-tm-ticket-radio').prop('checked', false);
            this.updateSummary();
        },
        
        handleQuantityChange: function($input) {
            var quantity = parseInt($input.val()) || 0;
            var max = parseInt($input.attr('max')) || 999;
            var eventId = $input.data('event-id');
            var bookingLimit = parseInt($input.data('booking-limit')) || 0;
            var exclusiveGroup = $input.data('exclusive-group') || $input.closest('.asce-tm-row').data('exclusive-group');
            
            // Validate quantity
            if (quantity < 0) {
                quantity = 0;
            } else if (quantity > max) {
                quantity = max;
            }
            
            // If quantity > 0 and event is in exclusive group, clear conflicting events
            if (quantity > 0 && exclusiveGroup) {
                this.clearExclusiveGroup(exclusiveGroup, eventId);
            }
            
            // Check event booking limit (if set)
            if (bookingLimit > 0) {
                var totalForEvent = this.getTotalQuantityForEvent(eventId, $input);
                
                if (totalForEvent > bookingLimit) {
                    // Reduce to fit within limit
                    var difference = totalForEvent - bookingLimit;
                    quantity = Math.max(0, quantity - difference);
                    
                    // Show warning message
                    this.showBookingLimitWarning(bookingLimit);
                }
            }
            
            $input.val(quantity);
            this.updateSummary();
        },
        
        handleCheckboxChange: function($checkbox) {
            var $container = this.$container;
            var eventId = $checkbox.data('event-id');
            var exclusiveGroup = $checkbox.data('exclusive-group') || $checkbox.closest('.asce-tm-row').data('exclusive-group');
            var isChecked = $checkbox.prop('checked');
            
            if (isChecked) {
                // Uncheck all other checkboxes for the same event within this container
                $container.find('.asce-tm-qty-checkbox[data-event-id="' + eventId + '"]').not($checkbox).prop('checked', false);
                
                // If this event is in an exclusive group, uncheck conflicting events
                if (exclusiveGroup) {
                    this.clearExclusiveGroup(exclusiveGroup, eventId);
                }
            }
            
            this.updateSummary();
        },
        
        clearExclusiveGroup: function(group, exceptEventId) {
            if (!group) return;
            
            var $container = this.$container;
            
            // Clear all radio buttons from events in the same exclusive group
            // Find rows with matching exclusive group, then uncheck radios for different events
            $container.find('.asce-tm-row[data-exclusive-group="' + group + '"]').each(function() {
                var rowEventId = $(this).data('event-id');
                if (rowEventId !== exceptEventId) {
                    $(this).find('.asce-tm-ticket-radio').prop('checked', false);
                }
            });
            
            // Also clear any inputs/checkboxes with exclusive-group directly on them (legacy support)
            $container.find('.asce-tm-qty-input[data-exclusive-group="' + group + '"]').each(function() {
                if ($(this).data('event-id') !== exceptEventId) {
                    $(this).val(0);
                }
            });
            
            $container.find('.asce-tm-qty-checkbox[data-exclusive-group="' + group + '"]').each(function() {
                if ($(this).data('event-id') !== exceptEventId) {
                    $(this).prop('checked', false);
                }
            });
            
            // Update summary after clearing to reflect changes
            this.updateSummary();
        },
        
        getTotalQuantityForEvent: function(eventId, $currentInput) {
            var total = 0;
            var $container = this.$container;
            var $inputs = $container.find('.asce-tm-qty-input[data-event-id="' + eventId + '"]');
            
            $inputs.each(function() {
                var qty = parseInt($(this).val()) || 0;
                total += qty;
            });
            
            return total;
        },
        
        showBookingLimitWarning: function(limit) {
            var message = 'This event has a maximum limit of ' + limit + ' ticket' + (limit > 1 ? 's' : '') + ' per booking.';
            this.showMessage(message, 'warning');
        },
        
        updateStepperVisibility: function() {
            var $root = this.$root.length ? this.$root : this.$container;
            var $stepper = $root.find('.asce-tm-stepper');
            
            // Fallback: If stepper not found in $root, try finding within the same .asce-tm-instance wrapper
            if ($stepper.length === 0) {
                var $instance = this.$container.closest('.asce-tm-instance');
                $stepper = $instance.find('.asce-tm-stepper');
            }
            
            // If still no stepper found, inject one at the top of the instance
            if ($stepper.length === 0) {
                var $instance = this.$container.closest('.asce-tm-instance');
                if ($instance.length > 0) {
                    var stepperHtml = '<div class="asce-tm-stepper asce-tm-stepper--hidden">' +
                        '<div class="asce-tm-step active" data-step="1">' +
                            '<span class="asce-tm-step-number">1</span>' +
                            '<span class="asce-tm-step-label">Choose Tickets</span>' +
                        '</div>' +
                        '<div class="asce-tm-step" data-step="2">' +
                            '<span class="asce-tm-step-number">2</span>' +
                            '<span class="asce-tm-step-label">Forms</span>' +
                        '</div>' +
                        '<div class="asce-tm-step" data-step="3">' +
                            '<span class="asce-tm-step-number">3</span>' +
                            '<span class="asce-tm-step-label">Payment</span>' +
                        '</div>' +
                        '<div class="asce-tm-step" data-step="4">' +
                            '<span class="asce-tm-step-number">4</span>' +
                            '<span class="asce-tm-step-label">Success</span>' +
                        '</div>' +
                    '</div>';
                    $instance.prepend(stepperHtml);
                    $stepper = $instance.find('.asce-tm-stepper');
                }
            }
            
            // Use table-scoped selections - simple DOM check
            var hasSelection = this.$container.find('input.asce-tm-ticket-radio:checked').length > 0;
            
            if (hasSelection) {
                $stepper.removeClass('asce-tm-stepper--hidden');
                $stepper.addClass('asce-tm-stepper--visible');
            } else {
                $stepper.removeClass('asce-tm-stepper--visible');
                $stepper.addClass('asce-tm-stepper--hidden');
            }
        },
        
        updateSummary: function() {
            var self = this;
            var $container = this.$container;
            
            this.selectedTickets = [];
            this.totalAmount = 0;
            this.totalItems = 0;
            
            // Process checked radio buttons using single source of truth
            this.getCheckedRadios().each(function() {
                var $radio = $(this);
                var sel = self.getSelectionFromRadio($radio);
                
                // Skip if missing or invalid IDs
                if (sel.event_id === 0 || sel.ticket_id === 0) {
                    if (console && console.warn) {
                        console.warn('ASCE-TM: Checked radio missing IDs, skipping from summary', $radio[0]);
                    }
                    return;
                }
                
                var ticketName = $radio.data('ticket-name');
                var price = parseFloat($radio.data('price')) || 0;
                
                self.selectedTickets.push({
                    event_id: sel.event_id,
                    ticket_id: sel.ticket_id,
                    ticket_name: ticketName,
                    quantity: 1,
                    price: price
                });
                
                self.totalAmount += price;
                self.totalItems += 1;
            });
            
            // Update display within this container
            $container.find('.asce-tm-total-amount').text(this.formatCurrency(this.totalAmount));
            
            // Update item count (create if doesn't exist)
            var $cartTotal = $container.find('.asce-tm-cart-total');
            var $itemCount = $cartTotal.find('.asce-tm-items-selected');
            if ($itemCount.length === 0) {
                $cartTotal.prepend('<div class="asce-tm-items-selected">' + this.totalItems + ' item' + (this.totalItems !== 1 ? 's' : '') + ' selected</div>');
            } else {
                $itemCount.text(this.totalItems + ' item' + (this.totalItems !== 1 ? 's' : '') + ' selected');
            }
            
            // Enable/disable add to cart button within this container
            if (this.totalItems > 0) {
                $container.find('.asce-tm-add-to-cart').prop('disabled', false);
            } else {
                $container.find('.asce-tm-add-to-cart').prop('disabled', true);
            }
            
            // Save tickets to sessionStorage for persistence (table-specific key)
            this.saveTicketsToSessionStorage();
        },
        
        saveTicketsToSessionStorage: function() {
            // Build normalized tickets array for sessionStorage
            var tickets = this.collectSelectedTickets();
            
            // Get table-specific key
            var tableId = this.$container.data('table-id') || '';
            var blogId = asceTM.blogId || 0;
            var key = 'asceTM:tickets:' + blogId + ':' + tableId;
            var fallbackKey = 'asceTM:tickets:' + blogId + ':__last';
            
            try {
                if (tickets.length > 0) {
                    // Save to table-specific key (Key A)
                    sessionStorage.setItem(key, JSON.stringify(tickets));
                    // Save to fallback key (Key B) for Forms page resilience
                    sessionStorage.setItem(fallbackKey, JSON.stringify(tickets));
                } else {
                    // Remove table-specific key if no tickets selected
                    sessionStorage.removeItem(key);
                    // Note: we don't remove fallbackKey here to preserve last selection
                }
            } catch (e) {
                if (console && console.warn) {
                    console.warn('ASCE-TM: Could not save tickets to sessionStorage:', e);
                }
            }
        },
        
        clearAll: function() {
            var $container = this.$container;
            var $root = this.$root.length ? this.$root : $container;
            
            // Always clear stepper visibility and status for THIS table instance
            var tableId = $container.data('table-id') || '';
            var blogId = asceTM.blogId || 0;
            var key = 'asceTM:tickets:' + blogId + ':' + tableId;
            
            // If no items selected, just ensure state is cleared without confirm
            if (this.totalItems === 0) {
                // Ensure radios are unchecked (no-op if already clear)
                $container.find('.asce-tm-ticket-radio').prop('checked', false);
                
                // Remove sessionStorage keys
                try {
                    sessionStorage.removeItem(key);
                    sessionStorage.removeItem('asceTM:tickets:' + blogId + ':__last');
                    sessionStorage.removeItem('asceTM:payload:' + blogId + ':' + tableId);
                    sessionStorage.removeItem('asceTM:payload:' + blogId + ':__last');
                    sessionStorage.removeItem('asce_tm_forms_data');
                } catch (e) {
                    console.warn('Could not clear sessionStorage:', e);
                }
                
                // Update summary and stepper visibility
                this.updateSummary();
                this.updateStepperVisibility();
                
                // Clear status for this instance
                $root.find('.asce-tm-stepper-status').removeClass('show error success info').empty();
                
                return;
            }
            
            // If items exist, show confirm dialog
            if (confirm('Are you sure you want to clear all selections?')) {
                // Uncheck all radios in this table
                $container.find('.asce-tm-ticket-radio').prop('checked', false);
                
                // Clear sessionStorage for this table and fallback keys
                try {
                    sessionStorage.removeItem(key);
                    sessionStorage.removeItem('asceTM:tickets:' + blogId + ':__last');
                    sessionStorage.removeItem('asceTM:payload:' + blogId + ':' + tableId);
                    sessionStorage.removeItem('asceTM:payload:' + blogId + ':__last');
                    sessionStorage.removeItem('asce_tm_forms_data');
                } catch (e) {
                    console.warn('Could not clear sessionStorage:', e);
                }
                
                // Update summary (this will also clear sessionStorage via saveTicketsToSessionStorage)
                this.updateSummary();
                
                // Update stepper visibility (will hide since no selections for THIS table)
                this.updateStepperVisibility();
                
                // Clear status for this instance
                $root.find('.asce-tm-stepper-status').removeClass('show error success info').empty();
                
                // Clear session tickets on server
                $.ajax({
                    url: asceTM.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'asce_tm_clear_session_tickets',
                        nonce: asceTM.nonce,
                        table_id: tableId,
                        blog_id: blogId
                    },
                    success: function(response) {
                        if (response.success) {
                            console.log('Session tickets cleared');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error clearing session tickets:', error);
                    }
                });
            }
        },
        
        collectSelectedTickets: function() {
            var self = this;
            var tickets = [];
            
            // Scan DOM for currently checked radios using single source of truth
            this.getCheckedRadios().each(function() {
                var $radio = $(this);
                var sel = self.getSelectionFromRadio($radio);
                
                // Skip if missing or invalid IDs
                if (sel.event_id === 0 || sel.ticket_id === 0) {
                    return;
                }
                
                tickets.push({
                    event_id: sel.event_id,
                    ticket_id: sel.ticket_id,
                    quantity: 1
                });
            });
            
            return tickets;
        },
        
        /**
         * Get selected tickets for this specific table only
         * This is table-scoped to support multiple independent matrices on the same page
         * @returns {Array} Normalized ticket selections for this table
         */
        getSelectedTicketsForTable: function() {
            return this.collectSelectedTickets();
        },
        
        /**
         * Go to forms step - save tickets to session and load booking forms
         */
        goToFormsStep: function() {
            var self = this;
            var $container = this.$container;
            
            console.log('\n========================================');
            console.log('STEP 1: goToFormsStep() INITIATED');
            console.log('========================================');
            console.log('Timestamp:', new Date().toISOString());
            
            // Prevent duplicate submissions
            if (this.isProcessing) {
                console.log('❌ STEP 1 BLOCKED: Already processing');
                return;
            }
            
            // Collect tickets from DOM
            console.log('\n--- Collecting tickets from DOM ---');
            var tickets = this.collectSelectedTickets();
            console.log('✅ Tickets collected:', tickets.length);
            console.log('   Ticket details:', tickets);
            
            if (tickets.length === 0) {
                console.log('❌ STEP 1 FAILED: No tickets selected');
                alert('Please select at least one ticket by choosing an option for each event you wish to attend.');
                return;
            }
            
            console.log('\n✅ STEP 1 VALIDATION PASSED');
            console.log('   - Tickets count: ' + tickets.length);
            console.log('   - AJAX URL: ' + asceTM.ajaxUrl);
            console.log('   - Nonce present: ' + (asceTM.nonce ? 'YES' : 'NO'));
            
            // Show loading state
            var $checkoutButton = $container.find('.asce-tm-checkout');
            var originalText = $checkoutButton.text();
            this.isProcessing = true;
            $checkoutButton.prop('disabled', true).text('Loading Forms...');
            
            // Save tickets to session and get forms
            console.log('\n========================================');
            console.log('STEP 2: SAVING TICKETS TO SESSION');
            console.log('========================================');
            console.log('AJAX Action: asce_tm_set_session_tickets');
            console.log('Payload:', JSON.stringify({tickets: tickets}, null, 2));
            
            $.ajax({
                url: asceTM.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asce_tm_set_session_tickets',
                    nonce: asceTM.nonce,
                    tickets: JSON.stringify(tickets)
                },
                success: function(response) {
                    console.log('\n--- STEP 2 AJAX Response ---');
                    console.log('Response:', response);
                    
                    if (response.success) {
                        console.log('✅ STEP 2 COMPLETE: Tickets saved to session');
                        console.log('   Proceeding to STEP 3...');
                        self.loadForms(tickets);
                    } else {
                        console.error('❌ STEP 2 FAILED: Server rejected ticket save');
                        console.error('   Error message:', response.data.message);
                        console.error('   Full response:', response.data);
                        self.showMessage(response.data.message || 'Failed to save selections.', 'error');
                        self.isProcessing = false;
                        $checkoutButton.text(originalText).prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ STEP 2 AJAX ERROR');
                    console.error('   Status:', status);
                    console.error('   Error:', error);
                    console.error('   XHR Status:', xhr.status);
                    console.error('   Response:', xhr.responseText);
                    self.showMessage('Error saving selections: ' + error, 'error');
                    self.isProcessing = false;
                    $checkoutButton.text(originalText).prop('disabled', false);
                }
            });
        },
        
        /**
         * Checkout directly - bypass forms panel and go straight to EM Pro checkout
         * Added in v3.5.3 to skip custom forms collection
         * 
         * @since 3.5.3
         */
        checkoutDirectly: function() {
            var self = this;
            var $container = this.$container;
            var $instance = $container.closest('.asce-tm-instance');
            
            console.log('========================================');
            console.log('STEP 1: CHECKOUT DIRECTLY (Bypass Forms)');
            console.log('========================================');
            
            // Validate tickets
            var selectedTickets = this.collectSelectedTickets();
            
            if (selectedTickets.length === 0) {
                alert(asceTM.i18n.noTicketsSelected || 'Please select at least one ticket.');
                return;
            }
            
            console.log('[OK] Tickets validated:', selectedTickets.length, 'tickets');
            
            // Show loading state
            $container.find('.asce-tm-checkout').prop('disabled', true).text('Processing...');
            
            // Save tickets to session and redirect to checkout
            console.log('========================================');
            console.log('STEP 2: SAVE TICKETS TO SESSION');
            console.log('========================================');
            
            $.ajax({
                url: asceTM.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asce_tm_checkout',
                    nonce: asceTM.nonce,
                    tickets: JSON.stringify(selectedTickets),
                    blog_id: asceTM.blogId,
                    table_id: $container.data('table-id')
                },
                success: function(response) {
                    console.log('[OK] STEP 2 COMPLETE: Tickets saved to session');
                    console.log('Response:', response);
                    console.log('Response.success:', response.success);
                    console.log('Response.data:', response.data);
                    
                    if (response.success && response.data && response.data.checkout_url) {
                        console.log('[OK] Redirecting to:', response.data.checkout_url);
                        window.location.href = response.data.checkout_url;
                    } else {
                        console.error('[FAIL] Invalid response structure');
                        console.error('Expected response.data.checkout_url but got:', response);
                        console.error('Full response.data object:', JSON.stringify(response.data, null, 2));
                        
                        // Show the actual error message from server
                        var errorMsg = 'Error from server';
                        if (response.data && response.data.message) {
                            errorMsg = response.data.message;
                        } else if (response.data && response.data.errors) {
                            errorMsg = 'Errors: ' + JSON.stringify(response.data.errors);
                        }
                        console.error('Server error message:', errorMsg);
                        alert(errorMsg);
                        $container.find('.asce-tm-checkout').prop('disabled', false).text('Checkout');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[FAIL] AJAX Error:', status, error);
                    console.error('Response:', xhr.responseText);
                    alert('Error: ' + error);
                    $container.find('.asce-tm-checkout').prop('disabled', false).text('Continue to Checkout');
                }
            });
        },
        
        /**
         * Load booking forms for selected tickets
         */
        loadForms: function(tickets) {
            var self = this;
            var $container = this.$container;
            var $instance = $container.closest('.asce-tm-instance');
            
            console.log('\n========================================');
            console.log('STEP 3: LOAD FORMS & TOGGLE PANELS');
            console.log('========================================');
            console.log('Input: ' + tickets.length + ' tickets');
            console.log('Timestamp:', new Date().toISOString());
            
            // Find DOM elements
            console.log('\n--- Locating DOM elements ---');
            var $ticketPanel = $instance.find('.asce-tm-panel-tickets');
            var $formsPanel = $instance.find('.asce-tm-panel-forms');
            var $formsPanelInner = $instance.find('.asce-tm-forms-panel');
            
            console.log('   Instance found:', $instance.length ? '✅ YES' : '❌ NO');
            console.log('   Ticket panel found:', $ticketPanel.length ? '✅ YES' : '❌ NO');
            console.log('   Forms panel found:', $formsPanel.length ? '✅ YES' : '❌ NO');
            console.log('   Forms panel inner found:', $formsPanelInner.length ? '✅ YES' : '❌ NO');
            
            if ($formsPanel.length === 0) {
                console.error('❌ STEP 3 FAILED: Forms panel HTML not found in DOM');
                console.error('   This means the PHP template did not render the forms panel');
                return;
            }
            
            // Toggle panels
            console.log('\n--- Toggling panel visibility ---');
            $ticketPanel.removeClass('active').hide();
            $formsPanel.addClass('active').show();
            $formsPanelInner.html('<p class="asce-tm-loading">Loading booking forms...</p>');
            
            console.log('   Ticket panel: hidden, active removed');
            console.log('   Forms panel: visible, active added');
            console.log('   Verification:');
            console.log('     - Ticket panel visible:', $ticketPanel.is(':visible') ? '❌ ERROR' : '✅ HIDDEN');
            console.log('     - Forms panel visible:', $formsPanel.is(':visible') ? '✅ VISIBLE' : '❌ ERROR');
            console.log('     - Ticket panel .active:', $ticketPanel.hasClass('active') ? '❌ ERROR' : '✅ REMOVED');
            console.log('     - Forms panel .active:', $formsPanel.hasClass('active') ? '✅ ADDED' : '❌ ERROR');
            
            // Update stepper to step 2
            $instance.find('.asce-tm-step').removeClass('active completed');
            $instance.find('.asce-tm-step[data-step="1"]').addClass('completed');
            $instance.find('.asce-tm-step[data-step="2"]').addClass('active');
            
            console.log('Stepper updated to step 2');
            
            // Get forms map from backend
            $.ajax({
                url: asceTM.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asce_tm_get_forms_map',
                    nonce: asceTM.nonce,
                    tickets: JSON.stringify(tickets)
                },
                success: function(response) {
                    console.log('AJAX get_forms_map response:', response);
                    if (response.success && response.data.groups) {
                        console.log('Forms loaded successfully:', response.data.groups.length, 'groups');
                        self.renderForms(response.data.groups, tickets);
                    } else {
                        console.error('Forms load failed:', response.data);
                        self.showMessage(response.data.message || 'Failed to load forms.', 'error');
                    }
                    self.isProcessing = false;
                    $container.find('.asce-tm-checkout').text('Continue to Checkout').prop('disabled', false);
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', {xhr: xhr, status: status, error: error});
                    console.error('Response text:', xhr.responseText);
                    self.showMessage('Error loading forms: ' + error, 'error');
                    self.isProcessing = false;
                    $container.find('.asce-tm-checkout').text('Continue to Checkout').prop('disabled', false);
                }
            });
        },
        
        /**
         * Render booking forms in the forms panel
         */
        renderForms: function(groups, tickets) {
            var $container = this.$container;
            var $instance = $container.closest('.asce-tm-instance');
            var $panel = $instance.find('.asce-tm-forms-panel');
            
            console.log('\n========================================');
            console.log('STEP 5: RENDERING FORM HTML');
            console.log('========================================');
            console.log('Input: ' + groups.length + ' form groups');
            console.log('Timestamp:', new Date().toISOString());
            
            $panel.empty();
            
            if (groups.length === 0) {
                console.log('⚠️ STEP 5: No form groups (skipping forms)');
                $panel.html('<p>No booking information required.</p>');
                console.log('✅ STEP 5 COMPLETE: No forms message displayed');
                return;
            }
            
            console.log('\n--- Processing ' + groups.length + ' form groups ---');
            
            // Render each form group
            groups.forEach(function(group) {
                var $groupDiv = $('<div class="asce-tm-form-group-wrapper"></div>');
                
                // Show form name and which events require it
                var formTitle = group.form_name || 'Event Information';
                var $header = $('<h3>' + formTitle + '</h3>');
                $groupDiv.append($header);
                
                // Show which events require this form
                if (group.event_names && group.event_names.length > 0) {
                    var eventsList = group.event_names.join(', ');
                    var $eventsInfo = $('<p class="asce-tm-form-events"><strong>Required for:</strong> ' + eventsList + '</p>');
                    $groupDiv.append($eventsInfo);
                }
                
                // Booking fields (applies to entire booking)
                if (group.booking_fields && group.booking_fields.length > 0) {
                    var $bookingFieldset = $('<fieldset class="asce-tm-booking-fields"><legend>Booking Information</legend></fieldset>');
                    group.booking_fields.forEach(function(field) {
                        $bookingFieldset.append(renderFormField(field, 'booking', group.form_id));
                    });
                    $groupDiv.append($bookingFieldset);
                }
                
                // Attendee fields (per ticket/attendee)
                if (group.attendee_fields && group.attendee_fields.length > 0) {
                    var $attendeeFieldset = $('<fieldset class="asce-tm-attendee-fields"><legend>Attendee Information</legend></fieldset>');
                    group.attendee_fields.forEach(function(field) {
                        $attendeeFieldset.append(renderFormField(field, 'attendee', group.form_id));
                    });
                    $groupDiv.append($attendeeFieldset);
                }
                
                $panel.append($groupDiv);
            });
            
            // Add submit button
            var $actions = $('<div class="asce-tm-form-actions"></div>');
            $actions.append('<button type="button" class="button asce-tm-btn-secondary asce-tm-back-to-tickets">Back to Tickets</button>');
            $actions.append('<button type="button" class="button button-primary button-large asce-tm-submit-forms">Continue to Checkout</button>');
            $panel.append($actions);
            
            console.log('\n✅ STEP 5 COMPLETE: Forms rendered successfully');
            console.log('   - Form groups rendered:', groups.length);
            console.log('   - Buttons added: Back to Tickets, Continue to Checkout');
            console.log('\n========================================');
            console.log('✅ ALL STEPS COMPLETE - FORMS READY');
            console.log('========================================');
            console.log('User can now fill forms and click "Continue to Checkout"');
            
            // Helper function to render individual form fields
            function renderFormField(field, section, formId) {
                var $fieldDiv = $('<div class="asce-tm-form-field"></div>');
                $fieldDiv.attr('data-field-id', field.fieldid);
                $fieldDiv.attr('data-field-type', field.type);
                
                var fieldName = 'asce_tm_' + section + '_' + formId + '_' + field.fieldid;
                var required = field.required ? ' required' : '';
                var requiredStar = field.required ? ' <span class="required">*</span>' : '';
                
                $fieldDiv.append('<label for="' + fieldName + '">' + field.label + requiredStar + '</label>');
                
                var $input;
                switch (field.type) {
                    case 'text':
                    case 'email':
                    case 'tel':
                        $input = $('<input type="' + field.type + '" name="' + fieldName + '" id="' + fieldName + '"' + required + '>');
                        break;
                    case 'textarea':
                        $input = $('<textarea name="' + fieldName + '" id="' + fieldName + '" rows="4"' + required + '></textarea>');
                        break;
                    case 'select':
                        $input = $('<select name="' + fieldName + '" id="' + fieldName + '"' + required + '></select>');
                        if (field.options && field.options.length) {
                            $input.append('<option value="">-- Select --</option>');
                            field.options.forEach(function(opt) {
                                $input.append('<option value="' + opt.value + '">' + opt.label + '</option>');
                            });
                        }
                        break;
                    case 'radio':
                    case 'checkbox':
                        var $optionsDiv = $('<div class="asce-tm-options"></div>');
                        if (field.options && field.options.length) {
                            field.options.forEach(function(opt, idx) {
                                var optId = fieldName + '_' + idx;
                                var $optLabel = $('<label></label>');
                                $optLabel.append('<input type="' + field.type + '" name="' + fieldName + '" id="' + optId + '" value="' + opt.value + '"' + required + '>');
                                $optLabel.append(' ' + opt.label);
                                $optionsDiv.append($optLabel);
                            });
                        }
                        $fieldDiv.append($optionsDiv);
                        return $fieldDiv;
                    default:
                        $input = $('<input type="text" name="' + fieldName + '" id="' + fieldName + '"' + required + '>');
                }
                
                $fieldDiv.append($input);
                return $fieldDiv;
            }
        },
        
        /**
         * Submit forms and proceed to checkout
         */
        submitFormsAndCheckout: function() {
            var self = this;
            var $container = this.$container;
            var $instance = $container.closest('.asce-tm-instance');
            
            // Collect form data
            var formData = {};
            $instance.find('.asce-tm-forms-panel input, .asce-tm-forms-panel textarea, .asce-tm-forms-panel select').each(function() {
                var $field = $(this);
                var name = $field.attr('name');
                if (name) {
                    if ($field.attr('type') === 'checkbox' || $field.attr('type') === 'radio') {
                        if ($field.is(':checked')) {
                            if (formData[name]) {
                                if (!Array.isArray(formData[name])) {
                                    formData[name] = [formData[name]];
                                }
                                formData[name].push($field.val());
                            } else {
                                formData[name] = $field.val();
                            }
                        }
                    } else {
                        formData[name] = $field.val();
                    }
                }
            });
            
            // Validate required fields
            var missingFields = [];
            var $firstMissingField = null;
            
            $instance.find('.asce-tm-forms-panel [required]').each(function() {
                var $field = $(this);
                var name = $field.attr('name');
                var fieldLabel = $field.closest('.asce-tm-form-field').find('label').first().text().replace('*', '').trim();
                var isMissing = false;
                
                if ($field.attr('type') === 'checkbox' || $field.attr('type') === 'radio') {
                    if (!$instance.find('[name="' + name + '"]:checked').length) {
                        isMissing = true;
                    }
                } else {
                    var val = $field.val();
                    if (!val || val.trim() === '') {
                        isMissing = true;
                    }
                }
                
                if (isMissing) {
                    missingFields.push(fieldLabel);
                    $field.closest('.asce-tm-form-field').addClass('asce-tm-field-error');
                    if (!$firstMissingField) {
                        $firstMissingField = $field;
                    }
                } else {
                    $field.closest('.asce-tm-form-field').removeClass('asce-tm-field-error');
                }
            });
            
            if (missingFields.length > 0) {
                alert('Please fill in all required fields:\n\n- ' + missingFields.join('\n- '));
                
                // Scroll to first missing field
                if ($firstMissingField) {
                    $firstMissingField[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    $firstMissingField.focus();
                }
                return;
            }
            
            // Clear any error classes
            $instance.find('.asce-tm-field-error').removeClass('asce-tm-field-error');
            
            console.log('=== ASCE TM - Submitting Forms ===');
            console.log('Form Data:', formData);
            console.log('===================================');
            
            // Show loading
            var $submitBtn = $instance.find('.asce-tm-submit-forms');
            var originalText = $submitBtn.text();
            $submitBtn.prop('disabled', true).text('Processing...');
            
            // Save form data and proceed to checkout
            $.ajax({
                url: asceTM.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'asce_tm_save_forms_data',
                    nonce: asceTM.nonce,
                    form_data: JSON.stringify(formData)
                },
                success: function(response) {
                    if (response.success) {
                        // Forms saved, now do actual checkout
                        self.checkout();
                    } else {
                        self.showMessage(response.data.message || 'Failed to save form data.', 'error');
                        $submitBtn.text(originalText).prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    self.showMessage('Error saving form data: ' + error, 'error');
                    console.error('Form save error:', error);
                    $submitBtn.text(originalText).prop('disabled', false);
                }
            });
        },
        
        /**
         * Original checkout method - now called after forms are submitted
         */
        checkout: function() {
            var self = this;
            var $container = this.$container;
            
            // Prevent duplicate submissions
            if (this.isProcessing) {
                return;
            }
            
            // Collect tickets from DOM at click-time
            var ticketsNow = this.collectSelectedTickets();
            
            if (ticketsNow.length === 0) {
                alert('Please select at least one ticket by choosing an option for each event you wish to attend.');
                return;
            }
            
            // Build modal content showing all checked radios and their status
            var linesHtml = '<ol style="text-align: left; margin: 0; padding-left: 20px;">';
            $container.find('.asce-tm-ticket-radio:checked').each(function() {
                var $radio = $(this);
                var sel = self.getSelectionFromRadio($radio);
                
                if (sel.event_id === 0 || sel.ticket_id === 0) {
                    linesHtml += '<li style="color: #d00; margin-bottom: 8px;">';
                    linesHtml += '<strong>⚠ WARNING:</strong> ';
                    if (sel.event_label) {
                        linesHtml += sel.event_label + ' - ';
                    }
                    linesHtml += 'Missing IDs (event_id=' + sel.event_id + ', ticket_id=' + sel.ticket_id + ')';
                    linesHtml += '</li>';
                } else {
                    linesHtml += '<li style="margin-bottom: 8px;">';
                    if (sel.event_label) {
                        linesHtml += '<strong>' + sel.event_label + '</strong> - ';
                    }
                    linesHtml += 'event_id=' + sel.event_id + ', ticket_id=' + sel.ticket_id;
                    linesHtml += '</li>';
                }
            });
            linesHtml += '</ol>';
            
            // Log cart items to console for debugging
            console.log('=== ASCE TM Checkout - Selected Tickets ===');
            console.log('Total tickets:', ticketsNow.length);
            console.log('Tickets:', ticketsNow);
            console.log('===========================================');
            
            // Show loading state on button within this container
            var $checkoutButton = $container.find('.asce-tm-checkout');
            var originalText = $checkoutButton.text();
            
            // Set in-flight lock
            this.isProcessing = true;
            $checkoutButton.prop('disabled', true).text('Processing...');
            
            // Build exact payload object that will be sent
            var ticketsJson = JSON.stringify(ticketsNow);
            var payloadObj = {
                action: 'asce_tm_checkout',
                nonce: asceTM.nonce,
                blog_id: asceTM.blogId || 0,
                table_id: $container.data('table-id') || '',
                tickets: ticketsJson
            };
            
            // Log payload to console for debugging
            console.log('=== ASCE TM Checkout - AJAX Payload ===');
            console.log('Payload:', payloadObj);
            console.log('Tickets JSON:', ticketsJson);
            console.log('========================================');
            
            // Send AJAX request
            $.ajax({
                url: asceTM.ajaxUrl,
                type: 'POST',
                data: payloadObj,
                success: function(response) {
                    console.log('=== ASCE TM Checkout - AJAX Response ===');
                    console.log('Response:', response);
                    console.log('=========================================');
                    
                    if (response.success) {
                        // Redirect to EM Pro native checkout page immediately
                        // Tickets are already in EM Pro cart session
                        if (response.data.checkout_url) {
                            var checkoutUrl = response.data.checkout_url;
                            console.log('Redirecting to:', checkoutUrl);
                            // Add cache buster to prevent stale page loads
                            checkoutUrl += (checkoutUrl.indexOf('?') > -1 ? '&' : '?') + 'nocache=' + Date.now();
                            window.location.href = checkoutUrl;
                        } else {
                            console.error('No checkout_url in response');
                            self.showMessage('Checkout URL not available. Please contact support.', 'error');
                        }
                    } else {
                        console.error('Response not successful:', response.data);
                        // Handle error response
                        var errorMessage = response.data.message || asceTM.strings.error;
                        
                        // Check if there are specific errors to display
                        if (response.data.errors && response.data.errors.length > 0) {
                            errorMessage += '<ul>';
                            response.data.errors.forEach(function(error) {
                                errorMessage += '<li>' + error + '</li>';
                            });
                            errorMessage += '</ul>';
                        }
                        
                        self.showMessage(errorMessage, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    self.showMessage(asceTM.strings.error, 'error');
                    console.error('AJAX Error:', error);
                },
                complete: function() {
                    // Always reset processing state
                    self.isProcessing = false;
                    $checkoutButton.text(originalText).prop('disabled', false);
                }
            });
        },
        
        showMessage: function(message, type) {
            var $container = this.$container;
            var $notice = $('<div class="asce-tm-notice"></div>');
            
            if (type === 'error') {
                $notice.addClass('asce-tm-error');
            } else if (type === 'warning') {
                $notice.addClass('asce-tm-warning');
            }
            
            $notice.html(message);
            $container.prepend($notice);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },
        
        formatCurrency: function(amount) {
            return '$' + amount.toFixed(2);
        },
        
        /**
         * Extract selection data from a radio button with fallback logic
         * @param {jQuery} $radio The radio button element
         * @returns {Object} { event_id, ticket_id, event_label }
         */
        getSelectionFromRadio: function($radio) {
            var eventId = 0;
            var ticketId = 0;
            var eventLabel = '';
            
            // Resolve event_id: try data-event-id, then closest row's data-event-id
            var eventIdAttr = parseInt($radio.attr('data-event-id'), 10);
            if (eventIdAttr && !isNaN(eventIdAttr)) {
                eventId = eventIdAttr;
            } else {
                var rowEventId = parseInt($radio.closest('.asce-tm-row').attr('data-event-id'), 10);
                if (rowEventId && !isNaN(rowEventId)) {
                    eventId = rowEventId;
                }
            }
            
            // Resolve ticket_id: try data-ticket-id, then value
            var ticketIdAttr = parseInt($radio.attr('data-ticket-id'), 10);
            if (ticketIdAttr && !isNaN(ticketIdAttr)) {
                ticketId = ticketIdAttr;
            } else {
                var ticketIdVal = parseInt($radio.val(), 10);
                if (ticketIdVal && !isNaN(ticketIdVal)) {
                    ticketId = ticketIdVal;
                }
            }
            
            // Extract event label from the row's event name
            var $row = $radio.closest('.asce-tm-row');
            var $eventName = $row.find('.asce-tm-event-name strong').first();
            if ($eventName.length > 0) {
                eventLabel = $.trim($eventName.text());
            }
            
            return {
                event_id: eventId,
                ticket_id: ticketId,
                event_label: eventLabel
            };
        },
        
        /**
         * Show processing modal with selection details
         * @param {String} linesHtml HTML content to display in modal body
         */
        showProcessingModal: function(linesHtml) {
            var self = this;
            var $overlay = this.$container.data('asceTmModal');
            
            // Create modal if it doesn't exist
            if (!$overlay || $overlay.length === 0) {
                $overlay = $('<div class="asce-tm-processing-overlay"></div>').css({
                    position: 'fixed',
                    top: 0,
                    left: 0,
                    width: '100%',
                    height: '100%',
                    backgroundColor: 'rgba(0, 0, 0, 0.7)',
                    zIndex: 99999,
                    display: 'none'
                });
                
                var $modal = $('<div class="asce-tm-processing-modal"></div>').css({
                    position: 'absolute',
                    top: '50%',
                    left: '50%',
                    transform: 'translate(-50%, -50%)',
                    backgroundColor: '#fff',
                    padding: '20px',
                    borderRadius: '5px',
                    maxWidth: '600px',
                    maxHeight: '80vh',
                    overflow: 'auto',
                    boxShadow: '0 4px 6px rgba(0, 0, 0, 0.3)'
                });
                
                var $title = $('<h3></h3>').text('Processing Checkout').css({
                    marginTop: 0,
                    marginBottom: '15px',
                    fontSize: '18px',
                    fontWeight: 'bold'
                });
                
                var $body = $('<div class="asce-tm-modal-body"></div>').css({
                    marginBottom: '15px',
                    maxHeight: '400px',
                    overflow: 'auto'
                });
                
                var $hideButton = $('<button type="button">Hide</button>').css({
                    padding: '8px 16px',
                    backgroundColor: '#0073aa',
                    color: '#fff',
                    border: 'none',
                    borderRadius: '3px',
                    cursor: 'pointer'
                }).on('click', function() {
                    $overlay.fadeOut();
                });
                
                $modal.append($title, $body, $hideButton);
                $overlay.append($modal);
                $('body').append($overlay);
                
                // Close on overlay click (not modal click)
                $overlay.on('click', function(e) {
                    if (e.target === $overlay[0]) {
                        $overlay.fadeOut();
                    }
                });
                
                this.$container.data('asceTmModal', $overlay);
            }
            
            // Update modal content and show
            $overlay.find('.asce-tm-modal-body').html(linesHtml);
            $overlay.fadeIn();
        },
        
        showPayloadModal: function(payloadObj) {
            var self = this;
            var $overlay = this.$container.data('asceTmPayloadModal');
            
            // Create modal if it doesn't exist
            if (!$overlay || $overlay.length === 0) {
                $overlay = $('<div class="asce-tm-payload-overlay"></div>').css({
                    position: 'fixed',
                    top: 0,
                    left: 0,
                    width: '100%',
                    height: '100%',
                    backgroundColor: 'rgba(0, 0, 0, 0.7)',
                    zIndex: 99998,
                    display: 'none'
                });
                
                var $modal = $('<div class="asce-tm-payload-modal"></div>').css({
                    position: 'absolute',
                    top: '50%',
                    left: '50%',
                    transform: 'translate(-50%, -50%)',
                    backgroundColor: '#fff',
                    padding: '20px',
                    borderRadius: '5px',
                    maxWidth: '700px',
                    maxHeight: '80vh',
                    overflow: 'auto',
                    boxShadow: '0 4px 6px rgba(0, 0, 0, 0.3)'
                });
                
                var $title = $('<h3></h3>').text('Checkout Payload').css({
                    marginTop: 0,
                    marginBottom: '15px',
                    fontSize: '18px',
                    fontWeight: 'bold'
                });
                
                var $body = $('<div class="asce-tm-payload-body"></div>').css({
                    marginBottom: '15px',
                    maxHeight: '400px',
                    overflow: 'auto'
                });
                
                var $hideButton = $('<button type="button">Hide</button>').css({
                    padding: '8px 16px',
                    backgroundColor: '#0073aa',
                    color: '#fff',
                    border: 'none',
                    borderRadius: '3px',
                    cursor: 'pointer'
                }).on('click', function() {
                    $overlay.fadeOut();
                });
                
                $modal.append($title, $body, $hideButton);
                $overlay.append($modal);
                $('body').append($overlay);
                
                // Close on overlay click (not modal click)
                $overlay.on('click', function(e) {
                    if (e.target === $overlay[0]) {
                        $overlay.fadeOut();
                    }
                });
                
                this.$container.data('asceTmPayloadModal', $overlay);
            }
            
            // Build modal content with payload details
            var bodyHtml = '';
            
            try {
                // Pretty-printed full payload object
                bodyHtml += '<h4 style="margin-top: 0; margin-bottom: 10px; font-size: 14px;">Full Payload Object:</h4>';
                bodyHtml += '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; border-radius: 3px; overflow-x: auto; font-size: 12px; line-height: 1.4;">';
                bodyHtml += JSON.stringify(payloadObj, null, 2);
                bodyHtml += '</pre>';
                
                // Raw tickets JSON string
                bodyHtml += '<h4 style="margin-top: 15px; margin-bottom: 10px; font-size: 14px;">Raw Tickets JSON (as sent):</h4>';
                bodyHtml += '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; border-radius: 3px; overflow-x: auto; font-size: 12px; line-height: 1.4;">';
                bodyHtml += payloadObj.tickets || '(empty)';
                bodyHtml += '</pre>';
            } catch (e) {
                bodyHtml = '<p style="color: #d00;">Error serializing payload: ' + e.message + '</p>';
            }
            
            // Update modal content and show
            $overlay.find('.asce-tm-payload-body').html(bodyHtml);
            $overlay.fadeIn();
        }
    };
    
    // Initialize when document is ready
    // Create a separate instance for each matrix container
    $(document).ready(function() {
        $('.asce-ticket-matrix-container').each(function() {
            var $container = $(this);
            
            // Prevent duplicate initialization (Elementor/optimization can cause double init)
            if ($container.data('asceTmInitialized')) {
                return;
            }
            
            $container.data('asceTmInitialized', true);
            new TicketMatrix($container);
        });
        
        // Checkout Debug Modal (admin only, checkout page only)
        initCheckoutDebug();
    });
    
    /**
     * Initialize checkout debug functionality
     * Only runs on checkout pages for admin users
     */
    function initCheckoutDebug() {
        // Only run on checkout pages
        var isCheckoutPage = window.location.pathname.indexOf('/checkout') > -1 || 
                           $('body').hasClass('em-checkout') ||
                           $('.em-booking-form, .em-checkout, .em-bookings').length > 0;
        
        if (!isCheckoutPage) {
            return;
        }
        
        // Only show for admins (will be enforced server-side too)
        var isAdmin = asceTM && asceTM.isAdmin === true;
        
        if (!isAdmin) {
            return;
        }
        
        // Add floating debug button
        var $debugButton = $('<button type="button" id="asce-tm-debug-cart-btn">Debug Cart</button>').css({
            position: 'fixed',
            bottom: '20px',
            right: '20px',
            padding: '10px 16px',
            backgroundColor: '#d54e21',
            color: '#fff',
            border: 'none',
            borderRadius: '4px',
            cursor: 'pointer',
            zIndex: 9999,
            fontSize: '13px',
            fontWeight: 'bold',
            boxShadow: '0 2px 4px rgba(0,0,0,0.3)'
        }).on('click', function() {
            fetchAndShowDebugModal();
        });
        
        $('body').append($debugButton);
    }
    
    /**
     * Fetch cart snapshot and show debug modal
     */
    function fetchAndShowDebugModal() {
        // Extract rendered items from DOM
        var renderedObj = extractRenderedCheckoutItems();
        
        // Fetch server snapshot
        $.ajax({
            url: asceTM.ajaxUrl,
            type: 'GET',
            data: {
                action: 'asce_tm_cart_snapshot'
            },
            success: function(response) {
                if (response.success) {
                    showCheckoutDebugModal(response.data.snapshot, renderedObj);
                } else {
                    showCheckoutDebugModal({ error: response.data.message || 'Failed to fetch snapshot' }, renderedObj);
                }
            },
            error: function(xhr, status, error) {
                showCheckoutDebugModal({ error: 'AJAX Error: ' + error + ' (Status: ' + xhr.status + ')' }, renderedObj);
            }
        });
    }
    
    /**
     * Extract rendered checkout items from DOM
     */
    function extractRenderedCheckoutItems() {
        var items = [];
        var $links = $('.em-booking-cart a, .em-checkout a, .em-bookings a, .em-booking-form a');
        
        $links.each(function() {
            var $link = $(this);
            var href = $link.attr('href') || '';
            var title = $link.text().trim();
            
            // Only include event links
            if (href.indexOf('/events/') > -1 && title) {
                var priceText = '';
                
                // Try to find price in same row or nearby
                var $row = $link.closest('tr, li, .em-booking-item, .em-cart-item');
                if ($row.length) {
                    var rowText = $row.text();
                    var priceMatch = rowText.match(/\$[\d,]+\.?\d*/);
                    if (priceMatch) {
                        priceText = priceMatch[0];
                    }
                }
                
                items.push({
                    title: title,
                    href: href,
                    priceText: priceText
                });
            }
        });
        
        return { items: items };
    }
    
    /**
     * Show checkout debug modal
     */
    function showCheckoutDebugModal(serverSnapshot, renderedObj) {
        var $overlay = $('#asce-tm-checkout-debug-overlay');
        
        // Create modal if it doesn't exist
        if ($overlay.length === 0) {
            $overlay = $('<div id="asce-tm-checkout-debug-overlay"></div>').css({
                position: 'fixed',
                top: 0,
                left: 0,
                width: '100%',
                height: '100%',
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                zIndex: 99999,
                display: 'none'
            });
            
            var $modal = $('<div class="asce-tm-checkout-debug-modal"></div>').css({
                position: 'absolute',
                top: '50%',
                left: '50%',
                transform: 'translate(-50%, -50%)',
                backgroundColor: '#fff',
                padding: '20px',
                borderRadius: '5px',
                maxWidth: '800px',
                maxHeight: '90vh',
                overflow: 'auto',
                boxShadow: '0 4px 10px rgba(0, 0, 0, 0.5)'
            });
            
            var $title = $('<h3></h3>').text('Checkout Debug').css({
                marginTop: 0,
                marginBottom: '15px',
                fontSize: '20px',
                fontWeight: 'bold',
                color: '#d54e21'
            });
            
            var $body = $('<div class="asce-tm-checkout-debug-body"></div>').css({
                marginBottom: '15px',
                maxHeight: '60vh',
                overflow: 'auto'
            });
            
            var $buttonRow = $('<div></div>').css({
                display: 'flex',
                gap: '10px'
            });
            
            var $copyButton = $('<button type="button">Copy All</button>').css({
                padding: '8px 16px',
                backgroundColor: '#0073aa',
                color: '#fff',
                border: 'none',
                borderRadius: '3px',
                cursor: 'pointer',
                flex: '1'
            }).on('click', function() {
                var textToCopy = $('#asce-tm-checkout-debug-overlay .asce-tm-checkout-debug-body').text();
                copyToClipboard(textToCopy);
                $(this).text('Copied!').css('backgroundColor', '#46b450');
                setTimeout(function() {
                    $copyButton.text('Copy All').css('backgroundColor', '#0073aa');
                }, 2000);
            });
            
            var $hideButton = $('<button type="button">Hide</button>').css({
                padding: '8px 16px',
                backgroundColor: '#666',
                color: '#fff',
                border: 'none',
                borderRadius: '3px',
                cursor: 'pointer',
                flex: '1'
            }).on('click', function() {
                $overlay.fadeOut();
            });
            
            $buttonRow.append($copyButton, $hideButton);
            $modal.append($title, $body, $buttonRow);
            $overlay.append($modal);
            $('body').append($overlay);
            
            // Close on overlay click (not modal click)
            $overlay.on('click', function(e) {
                if (e.target === $overlay[0]) {
                    $overlay.fadeOut();
                }
            });
        }
        
        // Build modal content
        var bodyHtml = '';
        
        bodyHtml += '<h4 style="margin-top: 0; margin-bottom: 10px; font-size: 15px; color: #d54e21;">Server Cart Snapshot (EM Multiple Bookings):</h4>';
        bodyHtml += '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; border-radius: 3px; overflow-x: auto; font-size: 11px; line-height: 1.4; max-height: 300px;">';
        bodyHtml += JSON.stringify(serverSnapshot, null, 2);
        bodyHtml += '</pre>';
        
        bodyHtml += '<h4 style="margin-top: 15px; margin-bottom: 10px; font-size: 15px; color: #d54e21;">Rendered Checkout Items (DOM):</h4>';
        bodyHtml += '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; border-radius: 3px; overflow-x: auto; font-size: 11px; line-height: 1.4; max-height: 300px;">';
        bodyHtml += JSON.stringify(renderedObj, null, 2);
        bodyHtml += '</pre>';
        
        // Update modal content and show
        $overlay.find('.asce-tm-checkout-debug-body').html(bodyHtml);
        $overlay.fadeIn();
    }
    
    /**
     * Copy text to clipboard
     */
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        } else {
            // Fallback for older browsers
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(text).select();
            document.execCommand('copy');
            $temp.remove();
        }
    }

})(jQuery);