# ASCE Ticket Matrix - Quick Start Examples

## Example 1: Create Early Bird vs Regular Tables

### Step-by-Step Process

#### Table 1: Early Bird Pricing Table

1. Go to **Ticket Matrix > Create New Table**
2. Fill in:
   - **Table Name:** Early Bird Pricing
   - **Number of Events:** 5
   - **Number of Ticket Columns:** 1
3. Click **"Update Table Structure"**
4. Configure Events:
   - Row 1: Select "Spring Conference 2026"
   - Row 2: Select "Summer Workshop 2026"
   - Row 3: Select "Fall Seminar 2026"
   - Row 4: Select "Winter Summit 2026"
   - Row 5: Select "Annual Meeting 2026"
5. Configure Column 1:
   - **Column Name:** Early Bird
   - For each event, select the early bird ticket from the dropdown
6. Click **"Save Table"**
7. Copy the shortcode (e.g., `[asce_ticket_matrix id="table_12345"]`)

#### Table 2: Regular Pricing Table

1. Click **"Create New Table"** again
2. Fill in:
   - **Table Name:** Regular Pricing
   - **Number of Events:** 5
   - **Number of Ticket Columns:** 1
3. Click **"Update Table Structure"**
4. Configure the same 5 events
5. Configure Column 1:
   - **Column Name:** Regular Price
   - For each event, select the regular price ticket
6. Click **"Save Table"**
7. Copy the shortcode (e.g., `[asce_ticket_matrix id="table_67890"]`)

#### Display on Your Page

```html
<h2>Early Bird Pricing - Save Now!</h2>
<p>Register early and save on all our 2026 events.</p>
[asce_ticket_matrix id="table_12345"]

<hr style="margin: 40px 0;">

<h2>Regular Pricing</h2>
<p>Standard pricing for all events.</p>
[asce_ticket_matrix id="table_67890"]
```

---

## Example 2: Multiple Ticket Types Table

### Single Table with Student/Member/Non-Member Pricing

1. Go to **Ticket Matrix > Create New Table**
2. Fill in:
   - **Table Name:** All Ticket Types
   - **Number of Events:** 3
   - **Number of Ticket Columns:** 3
3. Click **"Update Table Structure"**
4. Configure Events:
   - Row 1: Select "Professional Development Workshop"
   - Row 2: Select "Technical Training Session"
   - Row 3: Select "Certification Exam"
5. Configure Columns:

   **Column 1:**
   - **Column Name:** Student
   - Event 1: Select "Student Ticket"
   - Event 2: Select "Student Rate"
   - Event 3: Select "Student Exam Fee"

   **Column 2:**
   - **Column Name:** ASCE Member
   - Event 1: Select "Member Ticket"
   - Event 2: Select "Member Rate"
   - Event 3: Select "Member Exam Fee"

   **Column 3:**
   - **Column Name:** Non-Member
   - Event 1: Select "Non-Member Ticket"
   - Event 2: Select "Non-Member Rate"
   - Event 3: Select "Non-Member Exam Fee"

6. Click **"Save Table"**
7. Use shortcode: `[asce_ticket_matrix id="table_xxxxx"]`

---

## Example 3: VIP Package vs General Admission

### Two Column Comparison Table

1. **Table Name:** VIP & General Admission
2. **Number of Events:** 4
3. **Number of Ticket Columns:** 2
4. Configure Events (your major conferences)
5. Configure Columns:
   - **Column 1:** VIP Package (all-access tickets)
   - **Column 2:** General Admission (standard tickets)
6. Save and use the shortcode

---

## Example 4: Regional Events Table

### Separate Tables by Region

You could create multiple tables for different regions:

**Northeast Events Table**
- Events: All northeast region conferences
- Columns: Early Bird, Regular

**Southeast Events Table**
- Events: All southeast region conferences
- Columns: Early Bird, Regular

**West Coast Events Table**
- Events: All west coast conferences
- Columns: Early Bird, Regular

Each table gets its own page with its regional shortcode.

---

## Tips for Success

### Naming Conventions
Use clear, descriptive names:
- ✅ "2026 Q1 Early Bird Pricing"
- ✅ "Professional Development - All Ticket Types"
- ✅ "Student Discount Programs"
- ❌ "Table 1"
- ❌ "Test"

### Column Labels
Make them instantly understandable:
- ✅ "Early Bird (Save 20%)"
- ✅ "Student Rate"
- ✅ "Member Pricing"
- ✅ "VIP All-Access"
- ❌ "Option 1"
- ❌ "Type A"

### Event Organization
- Group related events in the same table
- Use custom labels to shorten long event names
- Keep tables focused (don't mix unrelated events)

### Testing Workflow
1. Create table in admin
2. Save it
3. Copy shortcode
4. Add to a DRAFT page first
5. Preview the draft
6. Check all ticket selections are correct
7. Test the quantity inputs
8. Then publish or add to live page

---

## Visual Layout Ideas

### Side-by-Side Comparison
```html
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
    <div>
        <h3>🎉 Early Bird Special</h3>
        [asce_ticket_matrix id="table_earlybird"]
    </div>
    <div>
        <h3>Standard Pricing</h3>
        [asce_ticket_matrix id="table_regular"]
    </div>
</div>
```

### Tabbed Interface (with plugin like "Tabs")
```
[tabs]
[tab title="Early Bird"]
[asce_ticket_matrix id="table_earlybird"]
[/tab]
[tab title="Regular Price"]
[asce_ticket_matrix id="table_regular"]
[/tab]
[tab title="Group Rates"]
[asce_ticket_matrix id="table_group"]
[/tab]
[/tabs]
```

### Sequential Display
```html
<h2>Choose Your Ticket Type</h2>

<div class="ticket-option">
    <h3>Option 1: Early Bird (Best Value!)</h3>
    [asce_ticket_matrix id="table_earlybird"]
</div>

<div class="ticket-option">
    <h3>Option 2: Regular Pricing</h3>
    [asce_ticket_matrix id="table_regular"]
</div>

<div class="ticket-option">
    <h3>Option 3: VIP Package</h3>
    [asce_ticket_matrix id="table_vip"]
</div>
```

---

## Frequently Asked Questions

**Q: Can I have the same event in multiple tables?**
A: Yes! You can include the same event in as many tables as you want, with different ticket options in each.

**Q: What happens if I change a table that's already on a page?**
A: The changes update immediately. The shortcode always shows the current table configuration.

**Q: Can I reorder events after creating the table?**
A: You'll need to edit the table and reconfigure the events in the order you want.

**Q: What if a ticket sells out?**
A: The table automatically shows "Sold Out" for that ticket. Users can still see other options.

**Q: Can I have different numbers of columns per event?**
A: No, all events in a table have the same number of columns. Create separate tables if you need different structures.

**Q: How do I change column names after creating the table?**
A: Edit the table and change the "Column Name/Label" fields, then save.

**Q: Can I export/import table configurations?**
A: Yes! As of v2.0.2, you can export any table as a JSON file and import it later. This is perfect for backups, moving between sites, or sharing configurations with team members.

---

## Example 6: Using Export/Import for Backups and Migration

### Scenario: Moving from Development to Production

#### On Development Site

1. Go to **Ticket Matrix**
2. Find your tested table configuration
3. Click **"Export"** button
4. Save the downloaded JSON file (e.g., `asce-tm-table_12345.json`)
5. Store in version control or email to yourself

#### On Production Site

1. Go to **Ticket Matrix**
2. Click **"📥 Import Table"** at the top
3. Select the JSON file you exported
4. Enter a name: "Spring 2026 Events (Production)"
5. Click OK
6. The table is imported with a new ID
7. Copy the new shortcode to your production pages

### Scenario: Creating a Backup Before Major Changes

**Before editing:**
1. Go to **Ticket Matrix**
2. Find the table you're about to change
3. Click **"Export"**
4. Save as `backup-before-changes-2026-01-12.json`

**If something goes wrong:**
1. Click **"📥 Import Table"**
2. Select your backup file
3. Name it with the original name
4. Update shortcode if needed
5. Delete the broken version

### Scenario: Sharing Configuration with Team

**Team Lead:**
1. Create and test perfect table configuration
2. Export as `team-template-spring-2026.json`
3. Share file via email, Slack, or shared drive

**Team Members:**
1. Import the JSON file
2. Customize event selections for their region
3. Deploy to their respective sites
4. Everyone maintains consistent column structure

---

## Common Workflows

### Monthly Updates
1. Review all tables at the start of each month
2. Add new events as they're created
3. Update ticket selections if pricing changes
4. Remove or disable past events

### Seasonal Campaigns
1. Create "Summer 2026 Events" table with early bird pricing
2. Create "Summer 2026 - Last Chance" table with regular pricing
3. Replace shortcode on main page when early bird period ends

### A/B Testing
1. Create two versions of the same table with different layouts
2. Use different shortcodes on test pages
3. See which converts better
4. Keep the winning configuration
