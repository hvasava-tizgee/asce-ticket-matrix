# ASCE Ticket Matrix - Display Options

**Version:** 2.1.3  
**Last Updated:** January 12, 2026

---

## Overview

Version 2.0+ uses a flexible **multi-table system** where each table is independently configured and displayed. This replaces the old v1.0 system that had fixed "Early Bird" and "Regular" modes.

## Modern Approach: Multiple Independent Tables

Each table you create is a complete, independent matrix with its own:
- Event selection
- Column configuration
- Ticket mappings
- Shortcode

### Example: Pricing Comparison Display

You can create multiple tables and display them together on one page for comparison:

#### Table 1: Early Bird Pricing
```
[asce_ticket_matrix id="table_earlybird"]
```

#### Table 2: Regular Pricing
```
[asce_ticket_matrix id="table_regular"]
```

**On the same page:**
```
[asce_ticket_matrix id="table_earlybird"]

[asce_ticket_matrix id="table_regular"]
```

### Visual Layout Example

```
+-----------------------------------------------------------------+
�                      YOUR EVENT PAGE                             �
+-----------------------------------------------------------------�
�                                                                   �
�  EARLY BIRD PRICING (Ends March 1)                              �
�  +--------------------------------------------------+          �
�  � Event Name    � Member � Non-Member � Student   �          �
�  +---------------+--------+------------+-----------�          �
�  � Workshop A    �  $35   �    $50     �   $15     �          �
�  � Workshop B    �  $40   �    $60     �   $20     �          �
�  +--------------------------------------------------+          �
�                                                                   �
�  REGULAR PRICING                                                 �
�  +--------------------------------------------------+          �
�  � Event Name    � Member � Non-Member � Student   �          �
�  +---------------+--------+------------+-----------�          �
�  � Workshop A    �  $50   �    $75     �   $25     �          �
�  � Workshop B    �  $55   �    $85     �   $30     �          �
�  +--------------------------------------------------+          �
�                                                                   �
�  [ Total: $0.00 | 0 tickets ] [ Add to Cart ]                   �
+-----------------------------------------------------------------+
```

## Common Display Patterns

### Pattern 1: Price Tiers Side-by-Side

Create separate tables for each pricing tier:
- **Early Bird Table** - Discounted prices
- **Regular Table** - Standard prices
- **Last Minute Table** - Walk-up prices

Display them on the same page for easy comparison.

### Pattern 2: Event Categories

Create tables for different event types:
- **Workshops Table** - Technical training sessions
- **Networking Table** - Social events
- **Webinars Table** - Online sessions

### Pattern 3: Audience Segmentation

Create audience-specific tables:
- **Members Only Table** - Member-exclusive events
- **Public Events Table** - Open to all
- **Student Events Table** - Student-focused programs

### Pattern 4: Single Unified Table

Create one comprehensive table with all options:
```
+--------------------------------------------------------------+
� Event         � Early Bird � Regular � Member � Student     �
+---------------+------------+---------+--------+-------------�
� Workshop A    �    $45     �   $60   �  $35   �    $20      �
� Workshop B    �    $50     �   $65   �  $40   �    $25      �
+--------------------------------------------------------------+
```

## Responsive Behavior

All tables automatically respond to screen size:

- **Desktop (>1200px):** Full table layout with all columns visible
- **Tablet (768-1200px):** Horizontal scrolling if needed
- **Mobile (<768px):** Stacked layout for readability

## Customization Options

### Custom Styling

Add custom CSS to your theme:

```css
/* Highlight Early Bird table */
.asce-tm-container.early-bird {
    border: 3px solid #4CAF50;
    background: #f1f8f4;
}

/* Add custom spacing between tables */
.asce-tm-container + .asce-tm-container {
    margin-top: 40px;
}
```

### Shortcode Attributes

```
[asce_ticket_matrix id="table_xxx" cache="no"]
```

- `id` (required): The table ID to display
- `cache` (optional): Set to "no" to disable caching for that specific table

## Legacy Information (v1.0)

**Note:** The following modes from v1.0 are no longer supported:

- ? `pricing_mode="toggle"` - Toggle between Early Bird/Regular
- ? `pricing_mode="separate_tables"` - Two hardcoded tables
- ? `pricing_mode="both"` - Combined display

**Migration:** Create separate tables using the admin interface and use individual shortcodes.

## Best Practices

1. **Clear Labeling:** Give each table a descriptive name users will understand
2. **Visual Separation:** Add headers or dividers between tables on the same page
3. **Consistent Layout:** Use the same column structure across related tables
4. **Performance:** Limit to 2-3 tables per page for optimal loading
5. **Mobile Testing:** Always test table display on mobile devices

## Support

For questions about display options:
- Review `TABLE-SETUP-GUIDE.md` for configuration details
- See `USAGE-EXAMPLES.md` for real-world scenarios
- Check `README.md` for general information
