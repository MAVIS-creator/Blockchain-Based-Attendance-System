# Design System Specification: Patcher Studio

## 1. Overview & Creative North Star
**The Creative North Star: "The Architectural Ledger"**
This design system moves away from the "flat web" and toward a high-density, multi-layered environment that mirrors the precision of blockchain engineering. We are building a "Digital Atelier" for developers—a space that feels as structural and permanent as the code it manages. 

To break the "template" look, we utilize **Tonal Architecture**. Instead of relying on borders to separate code blocks from sidebars, we use varying depths of darkness and subtle light-refraction (glassmorphism). The layout is intentionally dense but balanced by "editorial" typography headers that provide an authoritative, high-end feel.

---

## 2. Colors & Surface Philosophy
The palette is rooted in deep obsidian tones with electric, high-precision accents.

### The "No-Line" Rule
**Explicit Instruction:** Traditional 1px solid borders are prohibited for sectioning. Boundaries must be defined solely through background color shifts.
*   **Action:** To separate a File Explorer from the Editor, place a `surface-container-low` panel against the `surface` background. The change in hex value provides a sophisticated, "machined" edge that a border cannot replicate.

### Surface Hierarchy & Nesting
Treat the UI as a series of physical layers. Use the following tiers to define importance:
*   **Base Layer:** `surface` (#0b1326) – The canvas.
*   **Secondary Panels:** `surface-container-low` (#131b2e) – Sidebars and navigation.
*   **Content Cards:** `surface-container` (#171f33) – The primary working area.
*   **Active Overlays:** `surface-container-high` (#222a3d) – Active file tabs or hovered states.
*   **Floating Elements:** `surface-container-highest` (#2d3449) – Context menus and modals.

### The "Glass & Gradient" Rule
For "Patcher Studio" hero CTAs or critical status banners, use a subtle linear gradient: `primary` (#a6c8ff) to `primary-container` (#005ba9) at a 135-degree angle. Floating toolbars should utilize `surface-bright` (#31394d) with a 60% opacity and a `20px` backdrop-blur to create a "frosted ledger" effect.

---

## 3. Typography
We use a dual-font strategy to balance technical density with editorial authority.

*   **The Authority (Manrope):** Used for `display` and `headline` levels. Its geometric builds feel modern and trustworthy. Use `headline-sm` (1.5rem) for section titles to give the studio a premium, "magazine" feel.
*   **The Engine (Inter):** Used for `title`, `body`, and `label`. Inter’s high x-height is essential for the high-density requirements of a diff viewer and file tree.
*   **The Code (Monospaced):** For the Monaco-style editor, use a high-quality mono-font (e.g., JetBrains Mono) at `body-sm` (0.75rem) to maximize information density without sacrificing legibility.

---

## 4. Elevation & Depth
Depth is a functional tool, not a decoration.

*   **Tonal Layering:** Place `surface-container-lowest` (#060e20) cards on a `surface-container-low` section. This creates a "recessed" look, perfect for the code editor area, suggesting that the user is "stepping into" the logic.
*   **Ambient Shadows:** For floating modals, use a custom shadow: `0 12px 40px -12px rgba(6, 14, 32, 0.5)`. The shadow color must be derived from `surface-container-lowest` to ensure it feels like a natural occlusion of light.
*   **The "Ghost Border" Fallback:** If accessibility requires a container edge, use the `outline-variant` (#434654) at **15% opacity**. It should be felt, not seen.

---

## 5. Components

### Monaco-Style Editor & Diff Viewers
*   **Container:** Use `surface-container-lowest` for the editor gutter and `surface` for the code area.
*   **Diff Highlighting:** 
    *   **Addition:** `tertiary-container` (#006846) with `on-tertiary-container` text.
    *   **Deletion:** `error-container` (#93000a) with `on-error-container` text.
*   **Density:** Use `spacing-1` (0.2rem) for line-height adjustments in the file explorer to pack maximum data.

### Buttons
*   **Primary:** `primary` background with `on-primary` text. `md` (0.75rem) roundedness. 
*   **Secondary:** `secondary-container` background. No border.
*   **State:** On hover, shift background to `primary-fixed-dim`. 

### Cards & Lists
*   **Rule:** Forbid divider lines.
*   **Implementation:** Separate list items using a `spacing-2` (0.4rem) vertical gap. Use a subtle background shift to `surface-container-high` on hover to indicate interactivity.

### Status Indicators
*   **Blockchain Sync:** Use a small, pulsing `tertiary` (#4edea3) dot with a `4px` blur radius to indicate "Live" status.
*   **Attendance State:** Use `label-sm` caps for status chips (e.g., "VERIFIED") using `tertiary-fixed` for success and `error` for failed patches.

---

## 6. Do's and Don'ts

### Do
*   **Do** embrace high density. Use `spacing-2` and `spacing-3` for internal padding in code views.
*   **Do** use `manrope` for page titles to maintain a premium enterprise feel.
*   **Do** use `backdrop-blur` on the main navigation sidebar to allow the background blockchain visualizations to peek through.

### Don't
*   **Don't** use pure black (#000000). Always use the `surface` palette to maintain tonal depth.
*   **Don't** use 1px solid borders for layout. Rely on the `surface-container` tiers.
*   **Don't** use standard "drop shadows." Use the ambient, tinted shadow spec defined in Section 4.
*   **Don't** overcrowd with icons. Let the typography and color shifts do the heavy lifting.