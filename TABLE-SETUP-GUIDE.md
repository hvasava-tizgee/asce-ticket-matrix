# ASCE Ticket Matrix - Table Setup Guide

## Overview

The plugin has been reconfigured to support **multiple ticket matrix tables** with custom configurations. Each table can have:
- A different number of events (rows)
- A different number of ticket columns
- Custom column names (e.g., "Early Bird", "Regular", "VIP")
- Specific ticket selections for each event/column combination
- Its own unique shortcode

## How to Create Tables

### Step 1: Access the Table Setup Area

1. Go to **WordPress Admin > Ticket Matrix**
2. You'll see a list of all configured tables
3. Click **"Create New Table"** to start

### Step 2: Basic Table Configuration

When creating/editing a table, you'll configure:

1. **Table Name** - A descriptive name (e.g., "Early Bird Pricing", "Regular Pricing")
2. **Number of Events** - How many events to display as rows (1-10)
3. **Number of Ticket Columns** - How many ticket option columns to show (1-10)

### Step 3: Update Table Structure

After setting the number of events and columns:
1. Click **"Update Table Structure"**
2. This generates the configuration form below

### Step 4: Configure Events (Rows)

For each event row:
1. **Select Event** - Choose from your Events Manager events
2. **Custom Label** - (Optional) Override the event name with a custom label

### Step 5: Configure Ticket Columns

For each column:
1. **Column Name/Label** - Give it a descriptive name (e.g., "Early Bird", "Regular", "VIP", "Student")
2. **Ticket Selection** - For each event row, select which specific ticket option should appear in that cell

This gives you complete control over which ticket appears in each cell of the matrix.

### Step 6: Save the Table

Click **"Save Table"** and you'll see your new table with its unique shortcode.

---

## Exporting and Importing Tables

### Export a Table Configuration

1. Go to **WordPress Admin > Ticket Matrix**
2. Find the table you want to export
3. Click the **"Export"** button next to the table
4. A JSON file will download automatically (e.g., `asce-tm-table_123.json`)
5. Save this file for backup or transfer

**What's included in the export:**
- Table name and structure (number of events/columns)
- All event selections and custom labels
- All column names
- All ticket mappings for each cell
- Exclusive group assignments
- Version metadata

### Import a Table Configuration

1. Go to **WordPress Admin > Ticket Matrix**
2. Click the **"📥 Import Table"** button at the top
3. Select a previously exported JSON file from your computer
4. Enter a name for the imported table (or accept the suggested name)
5. Click OK
6. The table will be imported and appear in your tables list

**Important notes:**
- The imported table gets a new unique ID
- Event IDs must exist in your WordPress site
- If events don't exist, cells will show as empty
- All data is validated and sanitized during import
- You can import the same configuration multiple times with different names

### Use Cases for Export/Import

**Backups**
- Export tables before making major changes
- Keep versioned backups of your configurations
- Quick restore if something goes wrong

**Site Migration**
- Export from development site
- Import into staging for testing
- Import into production when ready

**Template Reuse**
- Create a template table with common structure
- Export and import to create variations
- Faster than recreating from scratch

**Team Collaboration**
- Share table configurations via email or file sharing
- Team members can import and modify
- Maintain consistency across multiple sites

## Using the Shortcodes

Each table generates a unique shortcode. Example:

```
[asce_ticket_matrix id="table_abc123"]
```

To display the table on a page:
1. Copy the shortcode from the tables list
2. Paste it into any page or post
3. The table will display with your custom configuration

## Example Use Cases

### Scenario 1: Early Bird vs Regular Pricing

**Table 1: Early Bird Pricing**
- Name: "Early Bird Pricing"
- 5 events
- 1 column: "Early Bird"
- Each cell shows the early bird ticket for that event
- Shortcode: `[asce_ticket_matrix id="table_earlybird"]`

**Table 2: Regular Pricing**
- Name: "Regular Pricing"
- 5 events
- 1 column: "Regular"
- Each cell shows the regular ticket for that event
- Shortcode: `[asce_ticket_matrix id="table_regular"]`

### Scenario 2: Multiple Ticket Types

**Table: All Ticket Options**
- Name: "All Ticket Options"
- 5 events
- 3 columns: "Student", "Member", "Non-Member"
- Each row shows the three different ticket types for that event
- Shortcode: `[asce_ticket_matrix id="table_all_options"]`

### Scenario 3: VIP and General Admission

**Table: VIP & General**
- Name: "VIP & General Admission"
- 3 events
- 2 columns: "VIP Tickets", "General Admission"
- Each event shows both VIP and general ticket options
- Shortcode: `[asce_ticket_matrix id="table_vip_general"]`

## Managing Tables

### Editing Tables
1. Go to **Ticket Matrix** admin page
2. Click **"Edit"** next to any table
3. Make your changes
4. Click **"Save Table"**

### Deleting Tables
1. Go to **Ticket Matrix** admin page
2. Click **"Delete"** next to any table
3. Confirm the deletion
4. **Note:** This will break any pages using that table's shortcode

### Copying Tables
To create a similar table:
1. Create a new table
2. Manually configure it with similar settings
3. Save with a different name

## Important Notes

### Multiple Bookings Mode
- This plugin requires **Events Manager Multiple Bookings Mode** to be enabled
- Enable it at: **Events Manager > Settings > Bookings**

### Ticket Availability
- Tickets automatically check availability
- Expired tickets show as "Expired" (based on ticket end date)
- Sold out tickets show as "Sold Out"
- Low stock shows "Only X left" when less than 10 tickets remain

### Ticket Selection
- You can select any ticket from the event's available tickets
- Each column can have a different ticket from the same event
- Leave a ticket selection empty to show "N/A" in that cell

## Migration from Old System

If you were using the old single-table system:

1. **Create a new table** with your preferred configuration
2. **Select the same events** you had configured before
3. **For Early Bird pricing:** Create one table with early bird tickets
4. **For Regular pricing:** Create another table with regular tickets
5. **Update your pages** with the new shortcodes
6. Test to ensure everything works correctly

## Troubleshooting

### "Please specify a table ID" error
- Make sure your shortcode includes `id="table_xxx"`
- Copy the shortcode directly from the admin tables list

### "Table not found" error
- The table may have been deleted
- Check the tables list to find the correct ID

### Tickets not loading
- Ensure the event still exists in Events Manager
- Check that the tickets haven't been deleted
- Verify Multiple Bookings Mode is enabled

### Changes not appearing
- Clear your site cache (if using caching plugins)
- Save the table again
- Refresh the frontend page

## Best Practices

1. **Descriptive Names** - Use clear table names like "Early Bird 2026" or "VIP Tickets"
2. **Column Labels** - Make column names clear and concise
3. **Consistent Events** - Use the same events across related tables for easy comparison
4. **Test Before Deploying** - Preview tables in draft pages before going live
5. **Backup** - Keep a list of your table IDs and their purposes

## Support

For issues or questions:
- Check Events Manager is properly configured
- Verify Multiple Bookings Mode is enabled
- Review ticket availability dates
- Check browser console for JavaScript errors
