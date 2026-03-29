# Blockchain-Based Attendance System - Admin UI Documentation

## Overview

This document provides a complete UI specification for all admin pages in the Blockchain-Based Attendance System. This is intended for Stitch AI and other UI generation tools to create new professional mockups and designs.

---

## System Information

- **Framework**: PHP 7.4+
- **Current Admin Template Engine**: HTML/PHP with CSS overrides
- **Icon Library**: Boxicons (bx- classes)
- **Responsive**: Mobile-first with breakpoints at 768px and 1024px
- **Color Scheme**: Professional blue (#1f5d99 primary), light backgrounds (#f3f7fc)

---

## Layout Architecture

### Overall Structure

```
┌─────────────────────────────────────────────────┐
│            Desktop Navbar (sticky)              │  ← New: Only visible on desktop
├──────────────────┬──────────────────────────────┤
│   Sidebar        │    Header (page title)       │  ← Header hidden on desktop
│  (toggleable)    ├──────────────────────────────┤
│                  │   Content Wrapper            │
│                  │   (main page content)        │
│                  ├──────────────────────────────┤
│                  │   Footer                     │
└──────────────────┴──────────────────────────────┘
```

### Responsiveness

- **Desktop (≥1025px)**: Navbar visible, sidebar fixed on left, full layout
- **Tablet/Mobile (<1025px)**: Navbar hidden, sidebar hamburger toggle, stacked layout
- **Mobile (<768px)**: Smaller fonts, condensed spacing, single-column forms

---

## Color Palette

```css
Primary Blue       #1f5d99  (main brand color)
Primary Blue 2     #3b7db6  (hover states)
Background Top     #f3f7fc  (page background gradient top)
Background Bottom  #eaf0f6  (page background gradient bottom)
Panel White        #ffffff  (card/panel backgrounds)
Text Dark          #142b45  (primary text)
Text Muted         #5f6d7d  (secondary text)
Border Line        #d7e0ea  (borders, dividers)
Soft Accent        #f4f8fc  (subtle backgrounds)
Error Red          #ff4757  (badges, alerts)
```

---

## Component Library

### 1. **Desktop Navbar** (`navbar.php`)

**Purpose**: Main navigation for desktop users (≥1025px)

**Components**:

- Logo + Brand Text ("Attendance Admin", "Management Console")
- Navigation Menu (Dashboard, Status, Logs, Courses, Manual Attendance, Announcement, Unlink Fingerprint, Support Tickets)
- Dropdown Menus (Logs submenu, Courses submenu)
- User Profile Section (Avatar/Initials, Name, Dropdown)
- Unread Badge (red #ff4757 on Support Tickets if count > 0)

**Features**:

- Sticky positioning (top: 0, z-index: 1000)
- Gradient background (135deg, #1f5d99 → #2f6ca4)
- 24px gap between brand and menu
- Flex layout for responsiveness
- Dropdown menus appear on click with white background
- User menu (Profile Settings, Manage Accounts, System Settings, Logout)
- All items have icons from Boxicons

**States**:

- Hover: Background becomes rgba(255, 255, 255, 0.15), text white
- Active: Background rgba(255, 255, 255, 0.25), text white, bold
- Dropdown Open: White panel with box-shadow

---

### 2. **Sidebar Navigation**

**Purpose**: Main navigation for mobile/tablet users

**Components**:

- Sidebar Brand (logo + "Attendance" text)
- Toggle Button (hamburger icon)
- Navigation List (same items as navbar)
- Collapsible Groups (Logs, Courses with <details>/<summary>)
- Support Tickets Badge
- Superadmin-only items (Manage Accounts icon visible only for superadmins)

**Styling**:

- Width: 260px (80px when collapsed)
- Gradient background: linear-gradient(180deg, #163456, #1f4b73)
- Text color: #dbe8f4 (light text on dark background)
- Smooth transition on collapse (0.3s)
- Icons from Boxicons (20-24px)

**States**:

- Default: Full width, labels visible
- Collapsed: 80px width, labels hidden (smooth transition)
- Hover on item: Background linear-gradient(135deg, #2f6ea6, #3d82bd)
- Active item: Same hover background with box-shadow

---

### 3. **Page Header** (old mobile header, hidden on desktop)

**Purpose**: Shows current page title/context on mobile

**Component Structure**:

- Brand section: Logo + Title + Subtitle
- User actions: Avatar menu

**Styling**:

- White background (#ffffff)
- Border: 1px solid #d7e0ea
- Border-radius: 14px
- Subtle shadow: 0 16px 36px rgba(24, 39, 75, 0.08)
- Padding: 14px 20px
- Flexbox: space-between

---

### 4. **Content Wrapper**

**Purpose**: Main content container for all page content

**Styling**:

- Background: #ffffff
- Border: 1px solid #d7e0ea
- Border-radius: 14px
- Padding: 22px
- Box-shadow: 0 16px 36px rgba(24, 39, 75, 0.08)
- Responsive: Full width on mobile, constrained on desktop

---

### 5. **Form Controls**

**Inputs** (text, number, email, password, select, textarea):

- Border: 1px solid #d7e0ea
- Border-radius: 8px
- Background: #ffffff
- Padding: 10px 12px
- Font-size: 0.95rem
- Focus state: Border #1f5d99 + box-shadow: 0 0 0 3px rgba(31, 93, 153, 0.14)

**Fieldsets**:

- Border: 1px solid #d7e0ea
- Border-radius: 10px
- Background: #fbfdff
- Padding: 16px
- Legend color: #142b45 (text-dark)

**Buttons**:

- Border-radius: 8px
- Background: #1f5d99 (primary) or #f3f7fc (secondary)
- Color: white (primary) or #1f5d99 (secondary)
- Padding: 10px 20px
- Hover: Slightly darker shade or increased shadow
- Transition: 0.3s ease

---

### 6. **Tables**

**Structure**:

- Width: 100%
- Border-collapse: collapse
- Background: #ffffff

**Thead**:

- Background: #f5f9fd
- Color: #142b45
- Font-weight: 700
- Border-bottom: 1px solid #d7e0ea

**Tbody/Rows**:

- Zebra striping: even rows background #fafcff
- Border-bottom: 1px solid #d7e0ea
- Hover: Light highlight (optional)

**Cells**:

- Padding: 12px
- Border-right: 1px solid #d7e0ea (optional)

---

### 7. **Status Cards / Stat Cards**

**Purpose**: Show key metrics (e.g., attendance count, active students)

**Components**:

- Title
- Large number/value
- Subtitle or trend indicator
- Optional icon

**Styling**:

- Background: #ffffff
- Border: 1px solid #d7e0ea
- Border-radius: 12px
- Padding: 16px
- Box-shadow: 0 16px 36px rgba(24, 39, 75, 0.08)
- Title: #142b45, font-weight: 700
- Value: #1f5d99, font-size: 2rem, font-weight: 700

---

### 8. **Badges & Labels**

**Support Tickets Badge** (unread count):

- Background: #ff4757 (error red)
- Color: white
- Border-radius: 999px
- Padding: 2px 6px
- Font-size: 0.75rem
- Font-weight: 700
- Positioned: top-right of link/menu item

---

### 9. **Dropdown Menus**

**Styling**:

- Background: #ffffff
- Border: 1px solid #d7e0ea
- Border-radius: 8px
- Box-shadow: 0 16px 36px rgba(24, 39, 75, 0.08)
- Min-width: 180px
- Padding: 8px 0
- Position: absolute (for navbar dropdowns)

**Items**:

- Padding: 10px 16px
- Color: #142b45
- Icon + text
- Hover: Background #f4f8fc, color #1f5d99
- Active: Color #1f5d99, font-weight: 700

**Divider**:

- Height: 1px
- Background: #d7e0ea
- Margin: 4px 0

---

## Admin Pages

### 1. **Dashboard** (`dashboard.php`)

**Purpose**: Overview of system statistics and recent logs

**Sections**:

1. **Statistics Cards**:
   - Attendance Count (today)
   - Total Courses
   - Failed Attempts
   - Active Course
   - Open Support Tickets
   - Linked Fingerprints
   - Total Students

2. **Charts**:
   - Line chart: Daily attendance trends
   - Pie chart: Attendance by course
   - Bar chart: Failed attempts by reason

3. **Recent Logs Table**:
   - Columns: Timestamp, Student ID, Course, Status (✓/✗), Action
   - Rows: 10-15 most recent entries
   - Pagination (optional)

4. **Quick Actions**:
   - Manual Attendance
   - View Logs
   - Settings

**Form Elements**: None (read-only)

**Styling Notes**:

- Cards use stat-card class
- Charts use 300px height
- Table uses standard table styling
- No flashy palette switcher (removed)

---

### 2. **Status** (`status.php`)

**Purpose**: Control check-in/check-out availability

**Sections**:

1. **Current Status**:
   - Display: Check-in enabled/disabled
   - Display: Check-out enabled/disabled
   - Display: Countdown timer (if scheduled close)

2. **Status Controls**:
   - "Enable Check-in" button
   - "Enable Check-out" button
   - "Disable All" button
   - Set End Time input (time picker)

3. **Countdown Display**:
   - Circular progress indicator (CSS animation)
   - Time remaining (HH:MM:SS)
   - Visual indicator changes color as time runs out

**Form**: POST form with CSRF token

- Buttons: status-btn class (POST)
- Auto-update countdown (JS setInterval)

**Styling Notes**:

- Status cards have status-card class
- Countdown circle uses SVG or CSS circular progress
- Buttons are action-focused (green for enable, red for disable)

---

### 3. **Logs Pages**

#### 3a. General Logs (`logs.php`)

**Purpose**: View all attendance logs

**Features**:

- Searchable table
- Filter by date range
- Filter by course
- Filter by student
- Export to CSV/PDF option

**Table Columns**:

- Timestamp
- Student ID / Name
- Course
- Device Info (MAC address)
- Location (Lat/Long if geofence enabled)
- Fingerprint Match (✓/✗)
- Reason (if provided)
- Status (Check-in/Check-out)

**Pagination**: 50 rows per page, with nav buttons

---

#### 3b. Chain Logs (`chain.php`)

**Purpose**: View blockchain chain validation

**Display**:

- Current chain hash
- Previous block hash
- Block count
- Validation status (Valid ✓ / Invalid ✗)
- Re-validate button
- Block history table (last 20 blocks)

**Table Columns**:

- Block ID
- Timestamp
- Hash
- Previous Hash
- Status

---

#### 3c. Failed Attempts (`failed_attempts.php`)

**Purpose**: View attendance failures (blocked attendance)

**Features**:

- Filter by reason
- Filter by date
- View detailed error message per row

**Table Columns**:

- Timestamp
- Student ID
- Course
- Reason (Fingerprint mismatch, Geofence violation, Token blocked, etc.)
- Device Info
- Actions (Revoke entry button)

---

#### 3d. Clear/Backup Logs (`clear_logs_ui.php`)

**Purpose**: Manage log data (backup, clear, restore)

**Sections**:

1. **Backup Logs**:
   - List of previous backups (date, size)
   - "Create Backup Now" button
   - Download link for each backup

2. **Clear Logs**:
   - Warning message (yellow background, red text)
   - Checkbox: "I understand this action is irreversible"
   - Date range picker (optional: clear only logs before date)
   - "Clear Logs" button (red, disabled until checkbox checked)

3. **Restore Session**:
   - Dropdown: Select backup date
   - "Restore" button (orange/warning color)
   - Confirmation dialog

---

#### 3e. Clear Tokens (`clear_tokens_ui.php`)

**Purpose**: Manage revoked/blocked tokens

**Sections**:

1. **Active Blocked Tokens**:
   - Table of blocked tokens (Device ID, Revocation Date, Reason)
   - "Clear All" button
   - Individual "Delete" button per row

2. **Token Settings**:
   - "Retention Days" number input (updated in System Settings)
   - Info: "Tokens older than X days are auto-purged"

---

#### 3f. Email Logs (`send_logs_email.php`)

**Purpose**: Send logs via email

**Form**:

- Recipient email input
- Select log type (General, Failed attempts, Chain)
- Date range pickers (from/to)
- "Send Email" button

**Result**: Confirmation message with sent timestamp

---

### 4. **Courses Management**

#### 4a. Add Course (`add_course.php`)

**Purpose**: Create a new course

**Form Fields**:

- Course Code (text input): e.g., "CS-101"
- Course Name (text input): e.g., "Introduction to Computer Science"
- Instructor Name (text input)
- Semester (select dropdown): Fall, Spring, Summer
- Description (textarea)
- "Add Course" button (green)

**Styling**: Standard form layout with fieldsets

---

#### 4b. Set Active Course (`set_active.php`)

**Purpose**: Choose which course is currently active for attendance

**Features**:

1. **Current Active Course**:
   - Display: Course Code, Name, Instructor
   - Display Active indicator (green badge)

2. **Available Courses Table**:
   - Columns: Course Code, Name, Instructor, Semester, Actions
   - Each row has "Set as Active" button
   - "Edit" link for each course

3. **Course Details Form** (when editing):
   - Same fields as Add Course
   - "Update" button

---

### 5. **Manual Attendance** (`manual_attendance.php`)

**Purpose**: Admin can manually record attendance for students

**Form Sections**:

1. **Student Selection**:
   - Student ID search/select
   - Shows student name once selected

2. **Attendance Details**:
   - Attendance type (Check-in / Check-out)
   - Timestamp (date + time picker, defaults to now)
   - Reason textarea (optional)

3. **Geofence Validation** (if enabled in settings):
   - Checkbox: "Override geofence check"
   - Display: Current geofence center location
   - Display: Geofence radius (m)
   - Test coordinates display if override unchecked

4. **Submit**:
   - "Record Attendance" button (blue)
   - "Cancel" button

**Feedback**: Success/error message at top of form

---

### 6. **Announcements** (`announcement.php`)

**Purpose**: Create and manage admin announcements (visible to students)

**Sections**:

1. **New Announcement Form**:
   - Title input
   - Content textarea (rich text editor optional)
   - Expiry date picker
   - "Post Announcement" button

2. **Active Announcements List**:
   - Table: Title, Posted Date, Expiry, Views, Actions
   - Each row has "Edit" and "Delete" buttons
   - Color-coded expiry (red if today/expired, orange if within 3 days, green if future)

---

### 7. **Unlink Fingerprint** (`unlink_fingerprint.php`)

**Purpose**: Remove fingerprint enrollment for a student

**Form**:

- Student ID/Name search
- Display: Current fingerprint status (Linked/Not linked)
  - If linked: Show "Fingerprint ID" and "Linked Date"
- "Unlink Fingerprint" button (red/warning)
- Confirmation dialog before unlinking

---

### 8. **Support Tickets** (`view_tickets.php`)

**Purpose**: Manage student support requests

**Sections**:

1. **Filters**:
   - Status filter (Open, Resolved, All)
   - Date range filter
   - Priority filter (Low, Medium, High)
   - Search by student name/ID

2. **Tickets Table**:
   - Columns: ID, Student, Subject, Priority (color-coded badge), Status, Posted Date, Actions
   - Each row has "View" and if open: "Resolve" button
   - Unresolved count badge in header

3. **Ticket Details Panel** (when "View" clicked):
   - Title
   - Student info
   - Description
   - Posted date
   - Messages/replies thread
   - Reply textarea
   - "Send Reply" button
   - "Mark Resolved" button (if unresolved)

---

### 9. **Manage Accounts** (`accounts.php`) [Superadmin only]

**Purpose**: Create, edit, delete admin users

**Sections**:

1. **New Account Form**:
   - Username (text input)
   - Email (email input)
   - Password (password input)
   - Confirm Password
   - Role (select): Admin, Superadmin
   - Avatar upload (file input)
   - "Create Account" button

2. **Accounts Table**:
   - Columns: Username, Email, Role (badge with color), Last Login, Actions
   - Each row has "Edit", "Reset Password", "Delete" buttons
   - Superadmin accounts indicated with special icon

3. **Edit Account Modal/Form**:
   - Same fields as create
   - "Update" button instead of create
   - "Change Password Only" option (optional new password fields)

---

### 10. **Profile Settings** (`profile_settings.php`)

**Purpose**: Current admin user profile management

**Sections**:

1. **Profile Info**:
   - Avatar upload (file input with preview)
   - Full Name (text input)
   - Email (email input, read-only)
   - Role (display, read-only)

2. **Change Password Section**:
   - Current Password (password input)
   - New Password (password input)
   - Confirm New Password
   - "Update Password" button

3. **Login History** (optional):
   - Recent login table (date, time, IP address)

---

### 11. **System Settings** (`settings.php`) [Superadmin only]

**Purpose**: Configure system-wide attendance rules and behavior

**Form Sections**:

1. **Attendance Enforcement** (Fieldset):
   - ☐ Require fingerprint match (checkbox)
   - ☐ Require reason keywords (checkbox)
   - Keywords list (textarea, pipe-separated): `Late|Sick Leave|Emergency|...`

2. **Geofence Settings** (Fieldset):
   - ☐ Enable geofence (checkbox)
   - Latitude (number input, -90 to 90)
   - Longitude (number input, -180 to 180)
   - Radius in meters (number input)
   - "Test Geofence" button → Opens test dialog
     - Input test latitude/longitude
     - Display: Distance from center (meters)
     - Display: Inside/Outside geofence (status)

3. **Log Retention** (Fieldset):
   - Blocked tokens retention days (number input, 1-3650)
   - Info text: "Logs older than X days will be auto-purged"

4. **Email Settings** (Fieldset) [if implemented]:
   - SMTP server (text input)
   - SMTP port (number input)
   - From email (email input)
   - "Send Test Email" button

5. **Submit**:
   - "Save Settings" button (blue)
   - "Reset to Defaults" button (gray)
   - Success message on save

**Styling Notes**:

- All sections use fieldset with legend
- Explanatory help text below each input
- Checkboxes aligned with labels
- Save button visually prominent

---

## Typography & Spacing

### Font Sizes

- **Page Title (H1)**: 1.8rem, font-weight: 700
- **Section Title (H2)**: 1.4rem, font-weight: 700
- **Subsection Title (H3)**: 1.1rem, font-weight: 600
- **Body Text**: 0.95rem
- **Small Text/Muted**: 0.85rem
- **Tiny Text (badges)**: 0.75rem

### Spacing

- **Margin/Padding Units**: 4px, 8px, 12px, 16px, 20px, 24px, 32px
- **Gap between elements**: 16px (cards), 8px (form fields)
- **Page padding**: 20px (mobile), 24px (desktop)
- **Content padding**: 22px

---

## Interactive Elements

### Buttons

**Primary Button**:

- Background: #1f5d99
- Color: white
- Border-radius: 8px
- Padding: 10px 20px
- Font-weight: 600
- Hover: Background #3b7db6 (lighter shade)

**Secondary Button**:

- Background: transparent
- Color: #1f5d99
- Border: 2px solid #1f5d99
- Font-weight: 600
- Hover: Background #f4f8fc

**Danger Button**:

- Background: #ff4757
- Color: white
- Matches primary button style
- Hover: Background #ff3838 (darker red)

### Forms & Inputs

**Focus State**:

- Border color: #1f5d99
- Box-shadow: 0 0 0 3px rgba(31, 93, 153, 0.14)
- Transition: 0.2s

**Error State**:

- Border color: #ff4757
- Background: #fff5f5 (light red)
- Error text below: 0.85rem, color #ff4757

**Success State**:

- Border color: #1e8E6A (green)
- Background: #f0fdf7 (light green)
- Success text: color #1e8E6A

---

## Animations & Transitions

- **Default Transition**: 0.3s ease
- **Hover Effects**: 0.2s ease
- **Menu/Dropdown**: Fade in (0.2s)
- **Sidebar Toggle**: Slide + width change (0.3s)
- **Navbar Sticky**: Smooth scroll (browser default)

---

## Accessibility

- All buttons have `type` attribute (submit, button, reset)
- All links that toggle menus have `aria-haspopup="true"` and `aria-expanded`
- Form labels associated with inputs via `for` attribute
- Color not sole indicator (use icons + text)
- Sufficient contrast: text #142b45 on #ffffff (19:1 ratio)
- Focus indicators visible for keyboard navigation

---

## Design Guidelines

1. **Clean & Professional**: No gradients except navbar and sidebar
2. **Consistent Spacing**: Use 4px/8px grid
3. **Icon Usage**: Every nav item and action has an icon from Boxicons
4. **Color Usage**: Max 2 colors per component (primary + neutral)
5. **Typography**: Maximum 3 text styles per page
6. **White Space**: Prefer generous padding over cramped layouts
7. **Mobile First**: Always test responsive breakpoints
8. **Accessibility**: Test with keyboard navigation and screen readers

---

## Files Modified/Created

- `admin/includes/navbar.php` - New desktop navigation component
- `admin/includes/sidebar.php` - Enhanced mobile sidebar
- `admin/includes/header.php` - Legacy mobile header (hidden on desktop)
- `admin/professional-overrides.css` - Global styling + responsive rules
- `admin/index.php` - Updated to include navbar

---

## Implementation Notes for Stitch AI

When generating new UI mockups:

1. **Maintain the responsive two-tier approach**: Navbar for desktop, sidebar for mobile
2. **Preserve all navigation links and menu structure**: Don't reorganize or rename pages
3. **Keep color palette consistent**: Use the primary blue (#1f5d99) for all key actions
4. **Ensure form fields match the style**: Border, border-radius, padding consistency
5. **Test all interactive states**: Hover, active, focus, disabled
6. **Maintain icon placement**: Always left of text in nav items
7. **Keep accessibility features**: ARIA labels, semantic HTML, sufficient contrast
8. **Preserve table designs**: Zebra striping, header styling, action columns
9. **Generate responsive mockups**: Show both mobile and desktop layouts
10. **Document any new components**: If adding custom UI elements, provide CSS

---

**Last Updated**: March 29, 2026
**System Version**: v2.5.0 (with responsive navbar)
**Contact**: For design questions, refer to professional-overrides.css color variables section
