/*
 * ASCE Ticket Matrix → EM Pro Integration (Custom Matrix, Native Checkout)
 * Environment: WP Multisite + Events Manager Pro MB/cart enabled. DO NOT edit EM core.
 * Architecture: Custom ticket matrix frontend → EM Pro cart session → EM Pro native checkout/payment
 * User Flow: (1) Tickets (custom matrix) → (2) Checkout (EM Pro native page with forms/payment/success)
 * Incremental rule: Do not revert prior working features. Make surgical edits only. Prefer additive changes. Preserve exclusive group logic and ticket selection UI.
 * Debug visibility: admin-only, behind isAdmin.
 */
/**
 * ASCE Ticket Matrix JavaScript
 * Handles frontend interactions for ticket selection matrix
 * 
 * Supports multiple independent matrices on the same page with scoped state management.
 * Each matrix instance maintains its own selection state and operates independently.
 * 
 * @version 2.9.20
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
            $container.on('click', '.asce-tm-checkout', function(e) {
                e.preventDefault();
                if ($(e.target).closest('.asce-tm-btn-next-forms').length) return;
                self.checkout();
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
            
            // Clear all inputs and checkboxes from events in the same exclusive group within this container
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
            
            // Show the processing modal
            this.showProcessingModal(linesHtml);
            
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
            
            // Show payload debug modal
            this.showPayloadModal(payloadObj);
            
            // Send AJAX request
            $.ajax({
                url: asceTM.ajaxUrl,
                type: 'POST',
                data: payloadObj,
                success: function(response) {
                    if (response.success) {
                        // Redirect to EM Pro native checkout page immediately
                        // Tickets are already in EM Pro cart session
                        if (response.data.checkout_url) {
                            var checkoutUrl = response.data.checkout_url;
                            // Add cache buster to prevent stale page loads
                            checkoutUrl += (checkoutUrl.indexOf('?') > -1 ? '&' : '?') + 'nocache=' + Date.now();
                            window.location.href = checkoutUrl;
                        } else {
                            self.showMessage('Checkout URL not available. Please contact support.', 'error');
                        }
                    } else {
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
        if (!$root || $root.length === 0) {
            console.warn('loadTicketsFromSession called without valid $root');
            return;
        }
        
        // Try sessionStorage FIRST (immediate, no network delay)
        var blogId = asceTM.blogId || 0;
        var key = 'asceTM:tickets:' + blogId + ':' + tableId;
        var fallbackKey = 'asceTM:tickets:' + blogId + ':__last';
        var payloadKey = 'asceTM:payload:' + blogId + ':' + tableId;
        var payloadFallback = 'asceTM:payload:' + blogId + ':__last';
        
        try {
            // Try table-specific key first (Key A)
            var storedTickets = sessionStorage.getItem(key);
            
            // If table-specific key is empty, try fallback key (Key B)
            if (!storedTickets) {
                storedTickets = sessionStorage.getItem(fallbackKey);
                if (storedTickets) {
                    console.log('Using fallback key (__last) since table-specific key was empty');
                }
            }
            
            if (storedTickets) {
                var parsed = JSON.parse(storedTickets);
                var tickets = null;
                
                // Self-healing: handle both array and object formats
                if (Array.isArray(parsed)) {
                    // Correct format: array of tickets
                    tickets = parsed;
                } else if (parsed && typeof parsed === 'object' && Array.isArray(parsed.tickets)) {
                    // Legacy v2.9.5 format: object with .tickets array
                    console.warn('Self-healing: Found object payload in tickets key, extracting array');
                    tickets = parsed.tickets;
                    
                    // Self-heal: write correct array format back to sessionStorage tickets keys
                    try {
                        sessionStorage.setItem(key, JSON.stringify(tickets));
                        sessionStorage.setItem(fallbackKey, JSON.stringify(tickets));
                        // Move object to payload keys where it belongs
                        sessionStorage.setItem(payloadKey, JSON.stringify(parsed));
                        sessionStorage.setItem(payloadFallback, JSON.stringify(parsed));
                        console.log('Self-healing complete: separated array and payload');
                    } catch (healError) {
                        console.warn('Self-healing write failed:', healError);
                    }
                }
                
                if (tickets && tickets.length > 0) {
                    console.log('Loaded tickets from sessionStorage (primary):', tickets);
                    
                    // Store debug data
                    $root.data('debug-tickets', tickets);
                    $root.data('debug-table-id', tableId);
                    $root.data('debug-blog-id', blogId);
                    $root.data('debug-tickets-source', 'sessionStorage');
                    
                    // Clear any previous status and show loading
                    $root.find('.asce-tm-stepper-status').removeClass('show error success info').empty();
                    
                    // Load forms immediately with sessionStorage tickets (will show 'Loading forms...' in loadFormsStep)
                    loadFormsStep($root, tickets);
                    
                    // Show stepper since we have tickets
                    $root.find('.asce-tm-stepper').removeClass('asce-tm-stepper--hidden').addClass('asce-tm-stepper--visible');
                    
                    // Re-seed the PHP session in background (fire-and-forget) - only for admin/debug
                    if (asceTM.isAdmin || asceTM.debug) {
                        $.ajax({
                            url: asceTM.ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'asce_tm_set_session_tickets',
                                nonce: asceTM.nonce,
                                table_id: tableId,
                                blog_id: blogId,
                                tickets: JSON.stringify(tickets)
                            },
                            success: function(response) {
                                console.log('Background session re-seed succeeded');
                            },
                            error: function(xhr, status, error) {
                                console.warn('Background session re-seed failed (non-blocking):', error);
                            }
                        });
                    }
                    
                    return; // Exit early since we have tickets
                }
            }
        } catch (e) {
            console.warn('Could not load tickets from sessionStorage:', e);
        }
        
        // Fallback: Try loading from session (only if sessionStorage failed)
        $root.find('.asce-tm-forms-panel').html('<p class="asce-tm-forms-loading">Loading tickets from session...</p>');
        
        $.ajax({
            url: asceTM.ajaxUrl,
            type: 'POST',
            data: {
                action: 'asce_tm_get_session_tickets',
                nonce: asceTM.nonce,
                table_id: tableId
            },
            success: function(response) {
                if (response.success && response.data.tickets && response.data.tickets.length > 0) {
                    // Store debug data
                    $root.data('debug-tickets', response.data.tickets);
                    $root.data('debug-table-id', response.data.table_id);
                    $root.data('debug-blog-id', response.data.blog_id);
                    $root.data('debug-tickets-source', 'session');
                    
                    // Show debug modal if admin or debug enabled
                    if (asceTM.isAdmin || asceTM.debug) {
                        showPayloadDebugModal('Forms Incoming Payload', response.data);
                    }
                    
                    // Clear any previous status and show loading
                    $root.find('.asce-tm-stepper-status').removeClass('show error success info').empty();
                    
                    // Tickets loaded successfully, now load forms (will show 'Loading forms...' in loadFormsStep)
                    loadFormsStep($root, response.data.tickets);
                    
                    // Show stepper since we have tickets
                    $root.find('.asce-tm-stepper').removeClass('asce-tm-stepper--hidden').addClass('asce-tm-stepper--visible');
                } else {
                    // Both sessionStorage and session failed
                    showNoTicketsError($root);
                }
            },
            error: function(xhr, status, error) {
                console.warn('Session tickets load failed:', error);
                // Both sessionStorage and session failed
                showNoTicketsError($root);
            }
        });
    }
    
    /**
     * Show "no tickets" error message
     */
    function showNoTicketsError($root) {
        var errorMsg = '<div class="asce-tm-notice asce-tm-error">' +
                      '<p><strong>No tickets found.</strong></p>' +
                      '<p>Please return to the tickets page and click "Next: Forms" again.</p>' +
                      '</div>';
        $root.find('.asce-tm-forms-panel').html(errorMsg);
        showStatus('No tickets selected.', 'error', $root);
        
        // Hide stepper since no tickets
        $root.find('.asce-tm-stepper').removeClass('asce-tm-stepper--visible');
    }
    
    /**
     * Add debug modal button
     */
    function addDebugModalButton() {
        // Only add if admin
        if (!asceTM.isAdmin) {
            return;
        }
        
        // Add debug button to each instance
        $('.asce-tm-instance').each(function() {
            var $root = $(this);
            
            // Don't add duplicate buttons
            if ($root.find('.asce-tm-debug-btn').length > 0) {
                return;
            }
            
            var $debugBtn = $('<button type="button" class="asce-tm-debug-btn" style="position:fixed;bottom:20px;right:20px;z-index:9999;padding:10px 15px;background:#0073aa;color:#fff;border:none;border-radius:3px;cursor:pointer;font-size:12px;">Debug Info</button>');
            $root.append($debugBtn);
            
            $debugBtn.on('click', function(e) {
                e.preventDefault();
                showDebugModal($root);
            });
        });
    }
    
    /**
     * Show debug modal with tickets and forms data
     */
    function showDebugModal($root) {
        var urlParams = new URLSearchParams(window.location.search);
        var step = urlParams.get('step');
        var tableId = urlParams.get('table_id');
        
        var debugHtml = '<div class="asce-tm-debug-modal" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:20px;border:1px solid #ccc;border-radius:5px;z-index:10000;max-width:800px;max-height:80vh;overflow:auto;box-shadow:0 2px 10px rgba(0,0,0,0.3);">';
        debugHtml += '<h3 style="margin-top:0;">Debug Information</h3>';
        
        // URL Parameters section
        debugHtml += '<div class="asce-tm-debug-section" style="margin-bottom:20px;">';
        debugHtml += '<h4>URL Parameters</h4>';
        debugHtml += '<pre style="background:#f5f5f5;padding:10px;border-radius:3px;overflow:auto;">';
        debugHtml += 'step: ' + (step || 'none') + '\\n';
        debugHtml += 'table_id: ' + (tableId || 'none');
        debugHtml += '</pre>';
        debugHtml += '<button type="button" class="asce-tm-copy-btn" data-copy-target="url-params" style="margin-top:5px;padding:5px 10px;background:#0073aa;color:#fff;border:none;border-radius:3px;cursor:pointer;">Copy</button>';
        debugHtml += '</div>';
        
        // Check if on Matrix page (has checked radios)
        var $container = $root.find('.asce-ticket-matrix-container').first();
        var $checkedRadios = $container.find('input[type=\"radio\"][data-event-id][data-ticket-id]:checked');
        
        if ($checkedRadios.length > 0) {
            // Matrix page: show tickets being saved
            var tickets = [];
            $checkedRadios.each(function() {
                var $radio = $(this);
                tickets.push({
                    event_id: parseInt($radio.data('event-id'), 10) || 0,
                    ticket_id: parseInt($radio.data('ticket-id'), 10) || 0,
                    quantity: 1
                });
            });
            
            debugHtml += '<div class="asce-tm-debug-section" style="margin-bottom:20px;">';
            debugHtml += '<h4>Tickets to be Saved (Matrix Page)</h4>';
            debugHtml += '<pre id=\"debug-tickets-payload\" style=\"background:#f5f5f5;padding:10px;border-radius:3px;overflow:auto;\">';
            debugHtml += JSON.stringify(tickets, null, 2);
            debugHtml += '</pre>';
            debugHtml += '<button type=\"button\" class=\"asce-tm-copy-btn\" data-copy-target=\"tickets-payload\" style=\"margin-top:5px;padding:5px 10px;background:#0073aa;color:#fff;border:none;border-radius:3px;cursor:pointer;\">Copy</button>';
            debugHtml += '</div>';
        }
        
        // Forms page: show tickets from session
        var debugTickets = $root.data('debug-tickets');
        if (debugTickets) {
            debugHtml += '<div class="asce-tm-debug-section" style="margin-bottom:20px;">';
            debugHtml += '<h4>Tickets from Session (Forms Page)</h4>';
            debugHtml += '<pre id=\"debug-session-tickets\" style=\"background:#f5f5f5;padding:10px;border-radius:3px;overflow:auto;\">';
            debugHtml += JSON.stringify(debugTickets, null, 2);
            debugHtml += '</pre>';
            debugHtml += '<button type=\"button\" class=\"asce-tm-copy-btn\" data-copy-target=\"session-tickets\" style=\"margin-top:5px;padding:5px 10px;background:#0073aa;color:#fff;border:none;border-radius:3px;cursor:pointer;\">Copy</button>';
            debugHtml += '</div>';
            
            debugHtml += '<div class="asce-tm-debug-section" style="margin-bottom:20px;">';
            debugHtml += '<h4>Session Metadata</h4>';
            debugHtml += '<pre style="background:#f5f5f5;padding:10px;border-radius:3px;overflow:auto;">';
            debugHtml += 'table_id: ' + ($root.data('debug-table-id') || 'none') + '\\n';
            debugHtml += 'blog_id: ' + ($root.data('debug-blog-id') || 'none');
            debugHtml += '</pre>';
            debugHtml += '</div>';
        }
        
        // Forms map (if available)
        var debugForms = $root.data('debug-forms-map');
        if (debugForms) {
            debugHtml += '<div class="asce-tm-debug-section" style="margin-bottom:20px;">';
            debugHtml += '<h4>Forms Map Response</h4>';
            debugHtml += '<pre id=\"debug-forms-map\" style=\"background:#f5f5f5;padding:10px;border-radius:3px;overflow:auto;\">';
            debugHtml += JSON.stringify(debugForms, null, 2);
            debugHtml += '</pre>';
            debugHtml += '<button type=\"button\" class=\"asce-tm-copy-btn\" data-copy-target=\"forms-map\" style=\"margin-top:5px;padding:5px 10px;background:#0073aa;color:#fff;border:none;border-radius:3px;cursor:pointer;\">Copy</button>';
            debugHtml += '</div>';
        }
        
        debugHtml += '<button type="button" class="asce-tm-debug-close" style="padding:8px 15px;background:#dc3232;color:#fff;border:none;border-radius:3px;cursor:pointer;">Close</button>';
        debugHtml += '</div>';
        
        // Add overlay
        var $overlay = $('<div class="asce-tm-debug-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;"></div>');
        $('body').append($overlay);
        $('body').append(debugHtml);
        
        // Close handlers
        $('.asce-tm-debug-close, .asce-tm-debug-overlay').on('click', function() {
            $('.asce-tm-debug-modal, .asce-tm-debug-overlay').remove();
        });
        
        // Copy handlers
        $('.asce-tm-copy-btn').on('click', function() {
            var target = $(this).data('copy-target');
            var text = '';
            
            if (target === 'url-params') {
                text = 'step: ' + (step || 'none') + '\\ntable_id: ' + (tableId || 'none');
            } else if (target === 'tickets-payload') {
                text = $('#debug-tickets-payload').text();
            } else if (target === 'session-tickets') {
                text = $('#debug-session-tickets').text();
            } else if (target === 'forms-map') {
                text = $('#debug-forms-map').text();
            }
            
            // Copy to clipboard
            navigator.clipboard.writeText(text).then(function() {
                alert('Copied to clipboard!');
            }).catch(function(err) {
                console.error('Failed to copy:', err);
            });
        });
    }
    
    /**
     * Show admin-only debug modal on Forms page with tickets payload and Copy button
     */
    function showFormsDebugModal($root, tickets) {
        var source = $root.data('debug-tickets-source') || 'unknown';
        var tableId = $root.data('debug-table-id') || '';
        var blogId = $root.data('debug-blog-id') || '';
        
        // Try to load payload object from payload keys for richer debug info
        var payloadKey = 'asceTM:payload:' + blogId + ':' + tableId;
        var payloadFallback = 'asceTM:payload:' + blogId + ':__last';
        var payloadObject = null;
        try {
            var storedPayload = sessionStorage.getItem(payloadKey) || sessionStorage.getItem(payloadFallback);
            if (storedPayload) {
                payloadObject = JSON.parse(storedPayload);
            }
        } catch (e) {
            console.warn('Could not load payload for debug:', e);
        }
        
        var modalHtml = '<div class="asce-tm-forms-debug-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:99999;"></div>';
        modalHtml += '<div class="asce-tm-forms-debug-modal" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:25px;border:2px solid #0073aa;border-radius:8px;z-index:100000;max-width:700px;max-height:85vh;overflow:auto;box-shadow:0 4px 20px rgba(0,0,0,0.5);">';
        modalHtml += '<h3 style="margin-top:0;color:#0073aa;border-bottom:2px solid #0073aa;padding-bottom:10px;">Forms Page Debug Info (Admin Only)</h3>';
        
        // Source
        modalHtml += '<div style="margin-bottom:20px;">';
        modalHtml += '<h4 style="margin-bottom:5px;font-size:15px;color:#333;">Tickets Source</h4>';
        modalHtml += '<p style="margin:5px 0;padding:8px 12px;background:' + (source === 'session' ? '#d4edda' : '#fff3cd') + ';border:1px solid ' + (source === 'session' ? '#c3e6cb' : '#ffeaa7') + ';border-radius:4px;font-weight:bold;">' + source.toUpperCase() + '</p>';
        modalHtml += '</div>';
        
        // Tickets payload
        modalHtml += '<div style="margin-bottom:20px;">';
        modalHtml += '<h4 style="margin-bottom:5px;font-size:15px;color:#333;">Tickets Payload (Normalized)</h4>';
        modalHtml += '<pre id="asce-forms-debug-tickets" style="background:#f5f5f5;padding:12px;border:1px solid #ddd;border-radius:4px;overflow-x:auto;font-size:12px;line-height:1.5;margin:5px 0;">';
        modalHtml += JSON.stringify(tickets, null, 2);
        modalHtml += '</pre>';
        modalHtml += '<button type="button" id="asce-forms-debug-copy-btn" style="padding:8px 16px;background:#0073aa;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;font-weight:600;">Copy JSON</button>';
        modalHtml += '</div>';
        
        // Metadata
        modalHtml += '<div style="margin-bottom:20px;">';
        modalHtml += '<h4 style="margin-bottom:5px;font-size:15px;color:#333;">Metadata</h4>';
        modalHtml += '<pre style="background:#f5f5f5;padding:12px;border:1px solid #ddd;border-radius:4px;font-size:12px;line-height:1.5;margin:5px 0;">';
        modalHtml += 'table_id: ' + tableId + '\\n';
        modalHtml += 'blog_id: ' + blogId + '\\n';
        modalHtml += 'ticket_count: ' + tickets.length;
        modalHtml += '</pre>';
        modalHtml += '</div>';
        
        modalHtml += '<button type="button" class="asce-forms-debug-close-btn" style="padding:10px 20px;background:#dc3232;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:14px;font-weight:600;">Close</button>';
        modalHtml += '</div>';
        
        $('body').append(modalHtml);
        
        // Close handlers
        $('.asce-forms-debug-close-btn, .asce-tm-forms-debug-overlay').on('click', function() {
            $('.asce-tm-forms-debug-modal, .asce-tm-forms-debug-overlay').remove();
        });
        
        // Copy button handler
        $('#asce-forms-debug-copy-btn').on('click', function() {
            var text = $('#asce-forms-debug-tickets').text();
            
            // Try modern clipboard API first
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    alert('Tickets JSON copied to clipboard!');
                }).catch(function(err) {
                    console.error('Clipboard copy failed:', err);
                    fallbackCopyToClipboard(text);
                });
            } else {
                fallbackCopyToClipboard(text);
            }
        });
        
        // Fallback copy method
        function fallbackCopyToClipboard(text) {
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(text).select();
            try {
                document.execCommand('copy');
                alert('Tickets JSON copied to clipboard!');
            } catch (err) {
                alert('Copy failed. Please copy manually from the debug window.');
            }
            $temp.remove();
        }
    }
    
    /**
     * Show payload debug modal (copy/paste friendly)
     * Used for both outgoing (Matrix->Forms) and incoming (Forms page load) payloads
     */
    function showPayloadDebugModal(title, data) {
        var modalHtml = '<div class="asce-tm-payload-debug-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:99998;"></div>';
        modalHtml += '<div class="asce-tm-payload-debug-modal" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:25px;border:2px solid #0073aa;border-radius:8px;z-index:99999;max-width:700px;max-height:85vh;overflow:auto;box-shadow:0 4px 20px rgba(0,0,0,0.5);">';
        modalHtml += '<h3 style="margin-top:0;color:#0073aa;border-bottom:2px solid #0073aa;padding-bottom:10px;">' + title + '</h3>';
        
        // JSON Payload
        modalHtml += '<div style="margin-bottom:20px;">';
        modalHtml += '<h4 style="margin-bottom:5px;font-size:15px;color:#333;">Response Data</h4>';
        modalHtml += '<pre id="asce-payload-debug-json" style="background:#f5f5f5;padding:12px;border:1px solid #ddd;border-radius:4px;overflow-x:auto;font-size:12px;line-height:1.5;margin:5px 0;">';
        modalHtml += JSON.stringify(data, null, 2);
        modalHtml += '</pre>';
        modalHtml += '<button type="button" id="asce-payload-debug-copy-btn" style="padding:8px 16px;background:#0073aa;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;font-weight:600;">Copy JSON</button>';
        modalHtml += '</div>';
        
        modalHtml += '<button type="button" class="asce-payload-debug-close-btn" style="padding:10px 20px;background:#dc3232;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:14px;font-weight:600;">Close</button>';
        modalHtml += '</div>';
        
        $('body').append(modalHtml);
        
        // Close handlers
        $('.asce-payload-debug-close-btn, .asce-tm-payload-debug-overlay').on('click', function() {
            $('.asce-tm-payload-debug-modal, .asce-tm-payload-debug-overlay').remove();
        });
        
        // Copy button handler
        $('#asce-payload-debug-copy-btn').on('click', function() {
            var text = $('#asce-payload-debug-json').text();
            
            // Try modern clipboard API first
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    alert('JSON copied to clipboard!');
                }).catch(function(err) {
                    console.error('Clipboard copy failed:', err);
                    fallbackCopyToClipboard(text);
                });
            } else {
                fallbackCopyToClipboard(text);
            }
        });
        
        // Fallback copy method
        function fallbackCopyToClipboard(text) {
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(text).select();
            try {
                document.execCommand('copy');
                alert('JSON copied to clipboard!');
            } catch (err) {
                alert('Copy failed. Please copy manually from the debug window.');
            }
            $temp.remove();
        }
    }
    
    /**
     * Initialize stepper navigation
     */
    function initStepper() {
        // Clear forms data on hard reload
        try {
            var navEntry = performance.getEntriesByType('navigation')[0];
            var isReload = navEntry ? navEntry.type === 'reload' : (performance.navigation && performance.navigation.type === 1);
            if (isReload) {
                sessionStorage.removeItem('asce_tm_forms_data');
                if (typeof formsData !== 'undefined') {
                    formsData = null;
                }
                console.log('Forms data cleared due to page reload');
            }
        } catch (e) {
            // Silently ignore if performance API not available
        }
        
        // Only run if stepper exists
        if ($('.asce-tm-stepper').length === 0) {
            return;
        }
        
        // Check if URL has step=forms query parameter (post-redirect scenario)
        var urlParams = new URLSearchParams(window.location.search);
        var step = urlParams.get('step');
        var tableId = urlParams.get('table_id');
        
        // Handle forms-only shortcode instances (asce_tm_forms)
        $('.asce-tm-instance.asce-tm-forms-only').each(function() {
            var $root = $(this);
            var $container = $root.find('.asce-ticket-matrix-container[data-table-id]');
            
            if ($container.length > 0) {
                var instanceTableId = $container.attr('data-table-id');
                
                // Update stepper to show step 2 (Forms) as active
                var $stepper = $root.find('.asce-tm-stepper');
                if ($stepper.length > 0) {
                    $stepper.find('.asce-tm-step').each(function() {
                        var $step = $(this);
                        var stepNum = parseInt($step.data('step'), 10);
                        
                        $step.removeClass('active completed');
                        
                        if (stepNum === 2) {
                            $step.addClass('active');
                        } else if (stepNum < 2) {
                            $step.addClass('completed');
                        }
                    });
                }
                
                // Load tickets from session
                loadTicketsFromSession($root, instanceTableId);
                
                // Stepper visibility will be handled by loadTicketsFromSession based on whether tickets are found
            }
        });
        
        if (step === 'forms' && tableId) {
            // Find the matching instance by table_id
            $('.asce-tm-instance').each(function() {
                var $root = $(this);
                var $container = $root.find('.asce-ticket-matrix-container[data-table-id="' + tableId + '"]');
                
                if ($container.length > 0) {
                    // Update stepper to show step 2 (Forms) as active
                    // DO NOT rebuild stepper DOM - just update classes
                    var $stepper = $root.find('.asce-tm-stepper');
                    if ($stepper.length > 0) {
                        $stepper.find('.asce-tm-step').each(function() {
                            var $step = $(this);
                            var stepNum = parseInt($step.data('step'), 10);
                            
                            $step.removeClass('active completed');
                            
                            if (stepNum === 2) {
                                // Step 2 (Forms) is active/current
                                $step.addClass('active');
                            } else if (stepNum < 2) {
                                // Step 1 (Tickets) is completed
                                $step.addClass('completed');
                            }
                        });
                    }
                    
                    // This is the right instance, load tickets from session
                    loadTicketsFromSession($root, tableId);
                    // Stepper visibility will be handled by loadTicketsFromSession based on whether tickets are found
                    return false; // break loop
                }
            });
        }
        
        // Add debug modal button to both pages
        addDebugModalButton();
        
        // Override checkout button to go to forms
        $(document).on('click', '.asce-tm-btn-next-forms', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            var $root = $btn.closest('.asce-tm-instance');
            if ($root.length === 0) {
                $root = $btn.closest('.asce-ticket-matrix-container').closest('.asce-tm-panel').parent();
            }
            goToFormsStep($root);
        });
        
        // Back to tickets button
        $(document).on('click', '.asce-tm-panel-forms .asce-tm-btn-prev', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $root = $btn.closest('.asce-tm-instance');
            if ($root.length === 0) {
                $root = $btn.closest('.asce-tm-panel').parent();
            }
            
            // Show tickets panel and update stepper
            $root.find('.asce-tm-panel').removeClass('active').hide();
            $root.find('.asce-tm-panel-tickets').addClass('active').show();
            
            // Update stepper state
            $root.find('.asce-tm-step').removeClass('active completed');
            $root.find('.asce-tm-step[data-step="1"]').addClass('active');
            
            // Restore ticket selections from sessionStorage
            restoreTicketSelectionsFromSession($root);
            
            // Clear any status messages
            $root.find('.asce-tm-stepper-status').removeClass('show error success info').empty();
        });
        
        // Save & Continue button
        $(document).on('click', '.asce-tm-panel-forms .asce-tm-btn-next', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $root = $btn.closest('.asce-tm-instance');
            if ($root.length === 0) {
                $root = $btn.closest('.asce-tm-panel').parent();
            }
            saveFormsAndContinue($root);
        });
    }
    
    /**
     * Restore ticket selections from sessionStorage
     * Re-checks radio buttons and updates visual state
     */
    function restoreTicketSelectionsFromSession($root) {
        if (!$root || $root.length === 0) {
            console.warn('restoreTicketSelectionsFromSession: no $root');
            return;
        }
        
        // Get table ID
        var $container = $root.find('.asce-ticket-matrix-container');
        if ($container.length === 0) {
            console.warn('restoreTicketSelectionsFromSession: no matrix container found');
            return;
        }
        
        var tableId = $container.data('table-id') || '';
        var blogId = asceTM.blogId || 0;
        var key = 'asceTM:tickets:' + blogId + ':' + tableId;
        var fallbackKey = 'asceTM:tickets:' + blogId + ':__last';
        
        try {
            // Try to get tickets from sessionStorage
            var storedTickets = sessionStorage.getItem(key);
            if (!storedTickets) {
                storedTickets = sessionStorage.getItem(fallbackKey);
            }
            
            if (!storedTickets) {
                console.log('No stored tickets found to restore');
                return;
            }
            
            var tickets = JSON.parse(storedTickets);
            if (!Array.isArray(tickets) || tickets.length === 0) {
                console.log('No tickets to restore');
                return;
            }
            
            console.log('Restoring ticket selections:', tickets);
            
            // First, uncheck all radios
            $container.find('input[type="radio"]').prop('checked', false);
            
            // Check the radios that match stored selections
            tickets.forEach(function(ticket) {
                var selector = 'input[type="radio"]' +
                    '[data-event-id="' + ticket.event_id + '"]' +
                    '[data-ticket-id="' + ticket.ticket_id + '"]';
                
                var $radio = $container.find(selector);
                if ($radio.length > 0) {
                    $radio.prop('checked', true);
                    console.log('Restored selection:', ticket.event_id, ticket.ticket_id);
                } else {
                    console.warn('Could not find radio for ticket:', ticket);
                }
            });
            
            // Trigger update on the TicketMatrix instance to refresh summary
            var instance = $container.data('ticketMatrix');
            if (instance && typeof instance.updateSummary === 'function') {
                instance.updateSummary();
            }
            
        } catch (e) {
            console.error('Error restoring ticket selections:', e);
        }
    }
    
    /**
     * Navigate to specific step
     */
    function goToStep(stepNum, $root) {
        if (!$root || $root.length === 0) {
            console.warn('goToStep called without valid $root, using fallback');
            $root = $('.asce-tm-instance').first();
        }
        
        // Update stepper visual state
        $root.find('.asce-tm-step').each(function() {
            var $step = $(this);
            var thisStep = parseInt($step.data('step'));
            
            $step.removeClass('active completed');
            
            if (thisStep === stepNum) {
                $step.addClass('active');
            } else if (thisStep < stepNum) {
                $step.addClass('completed');
            }
        });
        
        // Show appropriate panel
        $root.find('.asce-tm-panel').removeClass('active').hide();
        $root.find('.asce-tm-panel[data-panel="' + stepNum + '"]').addClass('active').show();
        
        // Clear status
        $root.find('.asce-tm-stepper-status').removeClass('show error success info').empty();
        
        // Scroll to top
        var $stepper = $root.find('.asce-tm-stepper');
        if ($stepper.length && $stepper.offset()) {
            $('html, body').animate({
                scrollTop: $stepper.offset().top - 50
            }, 300);
        }
    }
    
    /**
     * Get instance config from data-config attribute
     * @param {jQuery} $root The instance root element
     * @return {Object} Parsed config object or empty object on failure
     */
    function getInstanceConfig($root) {
        try {
            var configStr = $root.attr('data-config');
            if (configStr) {
                return JSON.parse(configStr);
            }
        } catch (e) {
            console.warn('Failed to parse instance config:', e);
        }
        return {};
    }
    
    /**
     * Show status message
     */
    function showStatus(message, type, $root) {
        if (!$root || $root.length === 0) {
            $root = $('.asce-tm-instance').first();
        }
        var $status = $root.find('.asce-tm-stepper-status');
        $status.removeClass('error success info').addClass('show ' + type).html(message);
    }
    
    /**
     * Go to Forms step
     */
    function goToFormsStep($root) {
        if (!$root || $root.length === 0) {
            console.warn('goToFormsStep called without valid $root');
            return;
        }
        
        // Find the matrix container within this instance
        var $container = $root.find('.asce-ticket-matrix-container').first();
        if ($container.length === 0) {
            showStatus('Error: Matrix container not found.', 'error', $root);
            return;
        }
        
        // Get matrix instance
        var matrixInstance = $container.data('asceTmMatrixInstance');
        
        // Collect selected tickets using checked radios as single source of truth
        var tickets = [];
        var $checkedRadios = $container.find('input[type="radio"][data-event-id][data-ticket-id]:checked');
        
        // Build tickets array from checked radios
        $checkedRadios.each(function() {
            var $radio = $(this);
            var eventId = parseInt($radio.data('event-id'), 10) || 0;
            var ticketId = parseInt($radio.data('ticket-id'), 10) || 0;
            
            if (eventId > 0 && ticketId > 0) {
                tickets.push({
                    event_id: eventId,
                    ticket_id: ticketId,
                    quantity: 1
                });
            }
        });
        
        if (tickets.length === 0) {
            showStatus('Please select at least one ticket before continuing.', 'error', $root);
            return;
        }
        
        // VALIDATION GATE: Check for exclusive group violations
        var exclusiveGroupCounts = {};
        $checkedRadios.each(function() {
            var groupKey = ($(this).data('exclusive-group') || '').toString().trim();
            if (groupKey !== '') {
                exclusiveGroupCounts[groupKey] = (exclusiveGroupCounts[groupKey] || 0) + 1;
            }
        });
        
        // Check if any exclusive group has more than one selection
        for (var groupKey in exclusiveGroupCounts) {
            if (exclusiveGroupCounts.hasOwnProperty(groupKey) && exclusiveGroupCounts[groupKey] > 1) {
                showStatus('Exclusive group selection error — only one ticket may be selected in group "' + groupKey + '".', 'error', $root);
                return;
            }
        }
        // End of validation gate
        
        // Get instance config
        var instanceConfig = getInstanceConfig($root);
        var tableId = instanceConfig.tableId || $container.data('table-id') || asceTM.tableId || '';
        var blogId = asceTM.blogId || 0;
        
        // Save tickets ARRAY to sessionStorage for persistence (before AJAX)
        // Save to both table-specific key (Key A) and fallback key (Key B)
        var key = 'asceTM:tickets:' + blogId + ':' + tableId;
        var fallbackKey = 'asceTM:tickets:' + blogId + ':__last';
        try {
            // Store tickets ARRAY only (not object payload)
            sessionStorage.setItem(key, JSON.stringify(tickets));
            sessionStorage.setItem(fallbackKey, JSON.stringify(tickets));
        } catch (e) {
            console.warn('Could not save tickets to sessionStorage:', e);
        }
        
        // Store payload OBJECT separately for debugging (not used for loading)
        var payloadKey = 'asceTM:payload:' + blogId + ':' + tableId;
        var payloadFallback = 'asceTM:payload:' + blogId + ':__last';
        try {
            var payloadObject = {
                tickets: tickets,
                meta: {
                    table_id: tableId,
                    blog_id: blogId
                },
                timestamp: Date.now()
            };
            sessionStorage.setItem(payloadKey, JSON.stringify(payloadObject));
            sessionStorage.setItem(payloadFallback, JSON.stringify(payloadObject));
        } catch (e) {
            console.warn('Could not save payload to sessionStorage:', e);
        }
        
        // Get forms page URL
        var formsPageUrl = instanceConfig.formsPageUrl || asceTM.formsPageUrl || '';
        var currentUrl = window.location.href;
        
        // Check if we should redirect to a separate forms page
        if (formsPageUrl && formsPageUrl.length > 0 && currentUrl.indexOf(formsPageUrl) === -1) {
            // Build redirect URL with query params
            var separator = formsPageUrl.indexOf('?') > -1 ? '&' : '?';
            var redirectUrl = formsPageUrl + separator + 'step=forms&table_id=' + encodeURIComponent(tableId) + '&blog_id=' + encodeURIComponent(blogId);
            
            // Redirect immediately (non-blocking)
            window.location.href = redirectUrl;
            
            // Fire-and-forget: save tickets to session via AJAX (non-blocking) - only for admin/debug
            // This is a background operation that should not block the redirect
            if (asceTM.isAdmin || asceTM.debug) {
                $.ajax({
                    url: asceTM.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'asce_tm_set_session_tickets',
                        nonce: asceTM.nonce,
                        table_id: tableId,
                        blog_id: blogId,
                        tickets: JSON.stringify(tickets)
                    },
                    success: function(response) {
                        console.log('Fire-and-forget session save succeeded:', response);
                    },
                    error: function(xhr, status, error) {
                        console.warn('Fire-and-forget session save failed (non-blocking):', error);
                    }
                });
            }
            
            return;
        }
        
        // No redirect: show forms on same page
        loadFormsStep($root, tickets);
    }
    
    /**
     * Load forms step (used for both same-page and post-redirect scenarios)
     */
    function loadFormsStep($root, tickets) {
        // Show loading in THIS instance's forms panel
        $root.find('.asce-tm-forms-panel').html('<p class="asce-tm-forms-loading">Loading forms...</p>');
        goToStep(2, $root);
        
        // Fetch forms map
        $.ajax({
            url: asceTM.ajaxUrl,
            type: 'POST',
            data: {
                action: 'asce_tm_get_forms_map',
                nonce: asceTM.nonce,
                tickets: tickets ? JSON.stringify(tickets) : '' // If empty, backend will use session
            },
            success: function(response) {
                if (response.success && response.data.groups) {
                    // Store for debug modal
                    $root.data('debug-forms-map', response.data);
                    renderForms(response.data.groups, tickets, $root);
                    
                    // Clear loading status after forms render
                    $root.find('.asce-tm-stepper-status').removeClass('show error success info').empty();
                    
                    // Show admin-only debug modal on Forms page (once)
                    if (asceTM.isAdmin && !$root.data('debug-modal-shown')) {
                        $root.data('debug-modal-shown', true);
                        showFormsDebugModal($root, tickets);
                    }
                } else {
                    showStatus(response.data.message || 'Failed to load forms.', 'error', $root);
                }
            },
            error: function(xhr, status, error) {
                showStatus('Error loading forms: ' + error, 'error', $root);
                console.error('Forms map error:', error);
            }
        });
    }
    
    /**
     * Render forms in panel B
     */
    function renderForms(groups, tickets, $root) {
        if (!$root || $root.length === 0) {
            $root = $('.asce-tm-instance').first();
        }
        
        var $panel = $root.find('.asce-tm-forms-panel');
        $panel.empty();
        
        // Set autocomplete=off to prevent browser autofill
        $panel.attr('autocomplete', 'off');
        
        // Store tickets for later
        $panel.data('tickets', tickets);
        
        if (groups.length === 0) {
            $panel.html('<p>No forms to display.</p>');
            return;
        }
        
        // Separate booking and attendee groups for organized display
        var bookingGroups = [];
        var attendeeGroups = [];
        
        groups.forEach(function(group) {
            if (group.form_type === 'booking') {
                bookingGroups.push(group);
            } else if (group.form_type === 'attendee') {
                attendeeGroups.push(group);
            }
        });
        
        // Render booking forms first
        if (bookingGroups.length > 0) {
            var $bookingSection = $('<div class="asce-tm-forms-section asce-tm-booking-section"></div>');
            $bookingSection.append('<h3>Booking Information</h3>');
            
            bookingGroups.forEach(function(group) {
                var $group = $('<div class="asce-tm-form-group"></div>');
                $group.attr('data-form-id', group.group_id);
                $group.attr('data-form-key', group.form_key);
                $group.attr('data-form-name', group.form_name);
                $group.attr('data-form-type', 'booking');
                $group.attr('autocomplete', 'off');
                
                // Event info with names list
                var eventInfo = '';
                if (group.event_names && group.event_names.length > 0) {
                    var eventsList = group.event_names.join(', ');
                    eventInfo = '<p><small><em>Applies to: ' + eventsList + '</em></small></p>';
                } else {
                    eventInfo = '<p><small><em>This form applies to ' + group.event_ids.length + ' event(s)</em></small></p>';
                }
                $group.append(eventInfo);
                
                // Form header
                var $header = $('<div class="asce-tm-form-section-header"></div>').html('<strong>' + group.form_name + '</strong>');
                $group.append($header);
                
                // Render fields
                if (group.booking_fields && group.booking_fields.length > 0) {
                    group.booking_fields.forEach(function(field) {
                        var $field = renderFormField(field, 'booking', group.group_id);
                        $group.append($field);
                    });
                }
                
                $bookingSection.append($group);
            });
            
            $panel.append($bookingSection);
        }
        
        // Render attendee forms
        if (attendeeGroups.length > 0) {
            var $attendeeSection = $('<div class="asce-tm-forms-section asce-tm-attendee-section"></div>');
            $attendeeSection.append('<h3>Attendee Information</h3>');
            
            attendeeGroups.forEach(function(group) {
                var $group = $('<div class="asce-tm-form-group"></div>');
                $group.attr('data-form-id', group.group_id);
                $group.attr('data-form-key', group.form_key);
                $group.attr('data-form-name', group.form_name);
                $group.attr('data-form-type', 'attendee');
                $group.attr('autocomplete', 'off');
                
                // Event info with names list
                var eventInfo = '';
                if (group.event_names && group.event_names.length > 0) {
                    var eventsList = group.event_names.join(', ');
                    eventInfo = '<p><small><em>Applies to: ' + eventsList + '</em></small></p>';
                } else {
                    eventInfo = '<p><small><em>This form applies to ' + group.event_ids.length + ' event(s)</em></small></p>';
                }
                $group.append(eventInfo);
                
                // Form header
                var $header = $('<div class="asce-tm-form-section-header"></div>').html('<strong>' + group.form_name + '</strong>');
                $group.append($header);
                
                // Render fields
                if (group.attendee_fields && group.attendee_fields.length > 0) {
                    group.attendee_fields.forEach(function(field) {
                        var $field = renderFormField(field, 'attendee', group.group_id);
                        $group.append($field);
                    });
                }
                
                $attendeeSection.append($group);
            });
            
            $panel.append($attendeeSection);
        }
        
        // Try to restore saved values from sessionStorage
        try {
            var saved = sessionStorage.getItem('asce_tm_forms_data');
            if (saved) {
                var formData = JSON.parse(saved);
                restoreFormValues(formData, $root);
            }
        } catch (e) {
            console.warn('Could not restore from sessionStorage:', e);
        }
    }
    
    /**
     * Render a single form field
     */
    function renderFormField(field, section, groupId) {
        var $field = $('<div class="asce-tm-form-field"></div>');
        $field.attr('data-field-id', field.fieldid);
        $field.attr('data-section', section); // 'booking' or 'attendee'
        $field.attr('data-group-id', groupId); // Track which form group this field belongs to
        
        var requiredMark = field.required ? ' <span class="required">*</span>' : '';
        var $label = $('<label></label>').html(field.label + requiredMark);
        $field.append($label);
        
        var $input;
        var fieldType = field.type || 'text';
        
        // Render input by type
        if (fieldType === 'textarea') {
            $input = $('<textarea></textarea>');
        } else if (fieldType === 'checkbox') {
            $input = $('<input type="checkbox" value="1">');
        } else if (fieldType === 'select') {
            $input = $('<select></select>');
            if (field.options && Array.isArray(field.options)) {
                field.options.forEach(function(opt) {
                    var optValue = typeof opt === 'object' ? opt.value : opt;
                    var optLabel = typeof opt === 'object' ? opt.label : opt;
                    $input.append('<option value="' + optValue + '">' + optLabel + '</option>');
                });
            } else if (field.options && typeof field.options === 'string') {
                // String-based options, split by newline
                var opts = field.options.split('\n');
                opts.forEach(function(opt) {
                    opt = opt.trim();
                    if (opt) {
                        $input.append('<option value="' + opt + '">' + opt + '</option>');
                    }
                });
            } else {
                // No options available - fallback to text input with warning
                $input = $('<input type="text">');
                $field.append('<small class="asce-tm-form-field-warning">Note: Options not available, using text input.</small>');
            }
        } else if (fieldType === 'user_email' || fieldType === 'email') {
            $input = $('<input type="email">');
        } else {
            // Default to text input
            $input = $('<input type="text">');
        }
        
        // Use fieldid as name attribute (CRITICAL for mapping)
        $input.attr('name', field.fieldid);
        $input.attr('data-required', field.required ? '1' : '0');
        $input.attr('data-section', section);
        $input.attr('autocomplete', 'off');
        
        $field.append($input);
        $field.append('<div class="asce-tm-form-field-error"></div>');
        
        return $field;
    }
    
    /**
     * Restore form values from saved data
     */
    function restoreFormValues(formData, $root) {
        if (!$root || $root.length === 0) {
            $root = $('.asce-tm-instance').first();
        }
        
        $.each(formData, function(groupId, sections) {
            var $group = $root.find('.asce-tm-form-group[data-form-id="' + groupId + '"]');
            if ($group.length) {
                // Handle new structure: { booking: {...}, attendee: {...} }
                if (sections && typeof sections === 'object') {
                    // Try new structure first
                    if (sections.booking || sections.attendee) {
                        $.each(['booking', 'attendee'], function(_, section) {
                            if (sections[section]) {
                                $.each(sections[section], function(fieldId, value) {
                                    var $input = $group.find('[name="' + fieldId + '"][data-section="' + section + '"]');
                                    if ($input.length === 0) {
                                        $input = $group.find('[name="' + fieldId + '"]');
                                    }
                                    if ($input.attr('type') === 'checkbox') {
                                        $input.prop('checked', value == 1);
                                    } else {
                                        $input.val(value);
                                    }
                                });
                            }
                        });
                    } else {
                        // Old flat structure fallback
                        $.each(sections, function(fieldId, value) {
                            var $input = $group.find('[name="' + fieldId + '"]');
                            if ($input.attr('type') === 'checkbox') {
                                $input.prop('checked', value == 1);
                            } else {
                                $input.val(value);
                            }
                        });
                    }
                }
            }
        });
    }
    
    /**
     * Validate and save forms
     */
    function saveFormsAndContinue($root) {
        if (!$root || $root.length === 0) {
            console.warn('saveFormsAndContinue called without valid $root');
            return;
        }
        
        var formData = {};
        var isValid = true;
        
        // Clear previous errors in this instance only
        $root.find('.asce-tm-form-field').removeClass('error');
        $root.find('.asce-tm-form-field-error').empty();
        
        // Collect and validate each form group in this instance
        $root.find('.asce-tm-form-group').each(function() {
            var $group = $(this);
            var groupId = $group.data('form-id');
            formData[groupId] = {
                booking: {},
                attendee: {}
            };
            
            $group.find('.asce-tm-form-field').each(function() {
                var $field = $(this);
                var fieldId = $field.data('field-id');
                var section = $field.data('section') || 'booking'; // 'booking' or 'attendee'
                var $input = $field.find('input, textarea, select');
                var value;
                var required = $input.data('required') == 1; // Use == to handle string "1" or number 1
                
                // Handle checkbox specially
                if ($input.attr('type') === 'checkbox') {
                    value = $input.is(':checked') ? '1' : '0';
                } else {
                    value = $input.val();
                }
                
                // Store in appropriate section
                formData[groupId][section][fieldId] = value;
                
                // Validate required fields (checkboxes exempt from empty check)
                if (required && $input.attr('type') !== 'checkbox' && (!value || value.trim() === '')) {
                    $field.addClass('error');
                    $field.find('.asce-tm-form-field-error').text('This field is required.');
                    isValid = false;
                }
                
                // Validate email
                if ($input.attr('type') === 'email' && value) {
                    var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(value)) {
                        $field.addClass('error');
                        $field.find('.asce-tm-form-field-error').text('Please enter a valid email address.');
                        isValid = false;
                    }
                }
            });
        });
        
        if (!isValid) {
            showStatus('Please correct the errors in the form.', 'error', $root);
            // Scroll to first error in this instance
            var $firstError = $root.find('.asce-tm-form-field.error').first();
            if ($firstError.length) {
                $('html, body').animate({
                    scrollTop: $firstError.offset().top - 100
                }, 300);
            }
            return;
        }
        
        // Save to sessionStorage
        try {
            sessionStorage.setItem('asce_tm_forms_data', JSON.stringify(formData));
        } catch (e) {
            console.warn('Could not save to sessionStorage:', e);
        }
        
        // Get tickets from this instance's forms panel
        var tickets = $root.find('.asce-tm-forms-panel').data('tickets') || [];
        
        // Save to server
        $.ajax({
            url: asceTM.ajaxUrl,
            type: 'POST',
            data: {
                action: 'asce_tm_save_forms_data',
                nonce: asceTM.nonce,
                form_data: JSON.stringify(formData),
                tickets: JSON.stringify(tickets)
            },
            success: function(response) {
                if (response.success) {
                    // Forms saved, now finalize bookings and move to payment
                    finalizeBookingsAndGoToPayment($root, formData, tickets);
                } else {
                    showStatus(response.data.message || 'Failed to save forms.', 'error', $root);
                }
            },
            error: function(xhr, status, error) {
                showStatus('Error saving forms: ' + error, 'error', $root);
                console.error('Save forms error:', error);
            }
        });
    }
    
    /**
     * Finalize bookings and transition to payment step
     */
    function finalizeBookingsAndGoToPayment($root, formData, tickets) {
        if (!$root || $root.length === 0) {
            $root = $('.asce-tm-instance').first();
        }
        
        showStatus('Finalizing bookings...', 'info', $root);
        
        $.ajax({
            url: asceTM.ajaxUrl,
            type: 'POST',
            data: {
                action: 'asce_tm_finalize_bookings',
                nonce: asceTM.nonce,
                form_data: JSON.stringify(formData),
                tickets: JSON.stringify(tickets)
            },
            success: function(response) {
                if (response.success) {
                    // Store booking info
                    $root.data('booking-ids', response.data.booking_ids);
                    $root.data('total-price', response.data.total_price);
                    
                    // Load payment step
                    loadPaymentStep($root);
                } else {
                    showStatus(response.data.message || 'Failed to finalize bookings.', 'error', $root);
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = 'Error finalizing bookings: ' + error;
                if (xhr.responseText) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.data && response.data.message) {
                            errorMsg = response.data.message;
                            if (response.data.errors && response.data.errors.length > 0) {
                                errorMsg += ' Details: ' + response.data.errors.join(', ');
                            }
                        }
                    } catch(e) {
                        errorMsg += ' (Response: ' + xhr.responseText.substring(0, 200) + ')';
                    }
                }
                showStatus(errorMsg, 'error', $root);
                console.error('Finalize bookings error:', error);
                console.error('XHR:', xhr);
                console.error('Status:', status);
            }
        });
    }
    
    /**
     * Load payment step with gateways
     */
    function loadPaymentStep($root) {
        if (!$root || $root.length === 0) {
            $root = $('.asce-tm-instance').first();
        }
        
        showStatus('Loading payment options...', 'info', $root);
        
        $.ajax({
            url: asceTM.ajaxUrl,
            type: 'POST',
            data: {
                action: 'asce_tm_get_payment_gateways',
                nonce: asceTM.nonce
            },
            success: function(response) {
                if (response.success && response.data.gateways) {
                    renderPaymentStep($root, response.data);
                    goToStep(3, $root);
                    
                    // Clear status after transition
                    setTimeout(function() {
                        $root.find('.asce-tm-stepper-status').removeClass('show info').empty();
                    }, 500);
                } else {
                    showStatus(response.data.message || 'Failed to load payment options.', 'error', $root);
                }
            },
            error: function(xhr, status, error) {
                showStatus('Error loading payment options: ' + error, 'error', $root);
                console.error('Load payment error:', error);
            }
        });
    }
    
    /**
     * Render payment step UI
     */
    function renderPaymentStep($root, paymentData) {
        if (!$root || $root.length === 0) {
            $root = $('.asce-tm-instance').first();
        }
        
        var $panel = $root.find('.asce-tm-panel[data-panel="3"]');
        if ($panel.length === 0) {
            // Create payment panel if it doesn't exist
            $panel = $('<div class="asce-tm-panel asce-tm-panel-payment" data-panel="3"></div>');
            $root.find('.asce-tm-stepper').after($panel);
        }
        
        $panel.empty();
        
        // Order summary section
        var $summary = $('<div class="asce-tm-payment-summary"></div>');
        $summary.append('<h3>Order Summary</h3>');
        
        if (paymentData.bookings && paymentData.bookings.length > 0) {
            var $bookingsList = $('<ul class="asce-tm-bookings-list"></ul>');
            paymentData.bookings.forEach(function(booking) {
                var $item = $('<li></li>');
                $item.append('<span class="event-name">' + booking.event_name + '</span>');
                $item.append('<span class="spaces">' + booking.spaces + ' ticket(s)</span>');
                $item.append('<span class="price">' + paymentData.currency_symbol + booking.price + '</span>');
                $bookingsList.append($item);
            });
            $summary.append($bookingsList);
        }
        
        // Total
        var $total = $('<div class="asce-tm-payment-total"></div>');
        $total.append('<strong>Total:</strong> ');
        $total.append('<span class="amount">' + paymentData.currency_symbol + paymentData.total_price + '</span>');
        $summary.append($total);
        
        $panel.append($summary);
        
        // Payment gateways section
        if (paymentData.gateways && paymentData.gateways.length > 0) {
            var $gateways = $('<div class="asce-tm-payment-gateways"></div>');
            $gateways.append('<h3>Select Payment Method</h3>');
            
            paymentData.gateways.forEach(function(gateway, index) {
                var $gateway = $('<div class="asce-tm-gateway"></div>');
                $gateway.attr('data-gateway', gateway.key);
                
                var radioId = 'gateway-' + gateway.key;
                var $radio = $('<input type="radio" name="payment_gateway">');
                $radio.attr('id', radioId);
                $radio.attr('value', gateway.key);
                if (index === 0) $radio.prop('checked', true);
                
                var $label = $('<label></label>');
                $label.attr('for', radioId);
                $label.text(gateway.title);
                if (gateway.description) {
                    $label.append('<span class="description">' + gateway.description + '</span>');
                }
                
                $gateway.append($radio);
                $gateway.append($label);
                
                // Add gateway-specific form if present
                if (gateway.form_html && gateway.form_html.trim() !== '') {
                    var $form = $('<div class="asce-tm-gateway-form"></div>');
                    $form.html(gateway.form_html);
                    $gateway.append($form);
                }
                
                $gateways.append($gateway);
            });
            
            $panel.append($gateways);
        } else {
            $panel.append('<p class="asce-tm-error">No payment methods available.</p>');
        }
        
        // Action buttons
        var $actions = $('<div class="asce-tm-panel-actions"></div>');
        $actions.append('<button class="asce-tm-btn asce-tm-btn-prev">← Back to Forms</button>');
        $actions.append('<button class="asce-tm-btn asce-tm-btn-primary asce-tm-btn-pay">Complete Payment</button>');
        $panel.append($actions);
        
        // Bind payment button click
        $panel.find('.asce-tm-btn-pay').off('click').on('click', function(e) {
            e.preventDefault();
            submitPayment($root);
        });
        
        // Bind back button
        $panel.find('.asce-tm-btn-prev').off('click').on('click', function(e) {
            e.preventDefault();
            goToStep(2, $root);
        });
    }
    
    /**
     * Submit payment to EM Pro
     */
    function submitPayment($root) {
        if (!$root || $root.length === 0) {
            $root = $('.asce-tm-instance').first();
        }
        
        var $panel = $root.find('.asce-tm-panel-payment');
        var selectedGateway = $panel.find('input[name="payment_gateway"]:checked').val();
        
        if (!selectedGateway) {
            showStatus('Please select a payment method.', 'error', $root);
            return;
        }
        
        // For now, show success message
        // In production, this would submit to EM Pro's payment endpoint
        showStatus('Payment processing... (gateway: ' + selectedGateway + ')', 'success', $root);
        
        // Transition to success step after short delay
        setTimeout(function() {
            renderSuccessStep($root);
            goToStep(4, $root);
        }, 1500);
    }
    
    /**
     * Render success step
     */
    function renderSuccessStep($root) {
        if (!$root || $root.length === 0) {
            $root = $('.asce-tm-instance').first();
        }
        
        var $panel = $root.find('.asce-tm-panel[data-panel="4"]');
        if ($panel.length === 0) {
            // Create success panel if it doesn't exist
            $panel = $('<div class="asce-tm-panel asce-tm-panel-success" data-panel="4"></div>');
            $root.find('.asce-tm-stepper').after($panel);
        }
        
        $panel.empty();
        
        var $success = $('<div class="asce-tm-success-message"></div>');
        $success.append('<h2>✓ Booking Confirmed!</h2>');
        $success.append('<p>Thank you for your booking. You will receive a confirmation email shortly.</p>');
        
        var bookingIds = $root.data('booking-ids');
        if (bookingIds && bookingIds.length > 0) {
            $success.append('<p class="booking-ids"><strong>Booking Reference(s):</strong> ' + bookingIds.join(', ') + '</p>');
        }
        
        $panel.append($success);
        
        // Clear status
        setTimeout(function() {
            $root.find('.asce-tm-stepper-status').removeClass('show success').empty();
        }, 500);
    }
    
    // Initialize stepper when document is ready
    $(document).ready(function() {
        // ============================================================================
        // MIGRATION BLOCK (ONLY localStorage access allowed in entire file)
        // ============================================================================
        // Move any legacy localStorage data to sessionStorage (one-time migration).
        // ALL runtime state MUST use sessionStorage — localStorage is only accessed
        // here for migration/cleanup. Any other localStorage access is a bug.
        // ============================================================================
        try {
            var keysToMigrate = [
                'asce_tm_forms_data'
            ];
            
            // Migrate form data key
            keysToMigrate.forEach(function(key) {
                var oldValue = localStorage.getItem(key);
                if (oldValue && !sessionStorage.getItem(key)) {
                    sessionStorage.setItem(key, oldValue);
                }
                localStorage.removeItem(key);
            });
            
            // Migrate ticket/payload keys (pattern-based)
            var blogId = asceTM.blogId || 0;
            for (var i = 0; i < localStorage.length; i++) {
                var key = localStorage.key(i);
                if (key && key.indexOf('asceTM:') === 0) {
                    var value = localStorage.getItem(key);
                    if (value && !sessionStorage.getItem(key)) {
                        sessionStorage.setItem(key, value);
                    }
                    localStorage.removeItem(key);
                    i--; // Adjust index after removal
                }
            }
        } catch (e) {
            console.warn('ASCE-TM: Migration from localStorage to sessionStorage failed:', e);
        }
        // ============================================================================
        // END MIGRATION BLOCK
        // ============================================================================
        
        initStepper();
    });
    
    // Clear forms data on bfcache restore (back/forward navigation)
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            sessionStorage.removeItem('asce_tm_forms_data');
        }
    });
    
})(jQuery);
