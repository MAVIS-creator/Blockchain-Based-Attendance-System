# Design System Specification: The Immutable Ledger

## 1. Overview & Creative North Star
**Creative North Star: "The Architectural Truth"**
This design system moves beyond the standard "SaaS Dashboard" aesthetic to create an environment of absolute authority and clarity. For a blockchain-based attendance system, the UI must mirror the technology it represents: immutable, transparent, and layered. 

We achieve a high-end editorial feel by rejecting traditional "boxed-in" layouts. Instead, we use **Intentional Asymmetry** and **Tonal Depth**. By placing high-density data visualizations against expansive, "breathing" white space, we create a rhythmic experience that guides the administrator's eye toward critical anomalies and verified records. We are not just building a table of names; we are designing a digital vault of presence.

---

## 2. Colors: Tonal Architecture
The palette is rooted in a spectrum of deep, professional blues and crystalline neutrals. We avoid high-contrast "vibrant" colors in favor of sophisticated, muted tones that reduce cognitive load during long periods of data analysis.

### The "No-Line" Rule
**Strict Prohibition:** 1px solid borders are forbidden for sectioning. 
Boundaries must be defined solely through background shifts. For example, a `surface-container-low` section sitting on a `surface` background creates a clear but soft structural change. This forces the designer to rely on proximity and color weight rather than "crutch" lines.

### Surface Hierarchy & Nesting
Treat the UI as a series of physical layers—like stacked sheets of fine architectural vellum.
- **Base Layer:** `surface` (#f6faff)
- **Primary Content Areas:** `surface-container-low` (#f0f4f9)
- **High-Priority Data Cards:** `surface-container-lowest` (#ffffff) for maximum "pop" against the base.
- **Active Interactive Elements:** `surface-container-high` (#e4e9ed)

### The "Glass & Gradient" Rule
To elevate the "Blockchain" narrative, use Glassmorphism for floating navigation and modal overlays. Use a backdrop-blur of `12px` combined with a `surface-variant` at 60% opacity. MAIN CTAs should utilize a subtle linear gradient from `primary` (#00457b) to `primary-container` (#1f5d99) at a 135° angle to provide a "jewel-toned" depth that feels premium.

---

## 3. Typography: Editorial Authority
We utilize **Inter** as our typographic backbone. The scale is intentionally dramatic to create a clear hierarchy between high-level statistics and granular logs.

*   **Display-MD (2.75rem):** Reserved for "Hero" stats (e.g., Total Attendance Rate). Tight letter-spacing (-0.02em).
*   **Headline-MD (1.75rem):** Page titles. Bold weight. This is the "Anchor" of the page.
*   **Title-SM (1rem):** Used for card headers. Medium weight, `on-surface-variant` color (#424750).
*   **Body-MD (0.875rem):** The workhorse for data tables. Line height set to 1.6 for maximum readability.
*   **Label-MD (0.75rem):** Used for metadata and blockchain hashes. Monospaced or uppercase with +0.05em tracking.

---

## 4. Elevation & Depth: The Layering Principle
Hierarchy is achieved through **Tonal Layering** rather than structural scaffolding.

*   **Ambient Shadows:** For floating elements (Modals/Popovers), use the signature shadow: `0 16px 36px rgba(24, 39, 75, 0.06)`. The color is a tinted blue-grey, never pure black.
*   **The Layering Principle:** Place a `surface-container-lowest` card on a `surface-container-low` section. This creates a soft, natural lift that mimics paper under studio lighting.
*   **The "Ghost Border" Fallback:** If a boundary is strictly required for accessibility (e.g., input fields), use `outline-variant` (#c2c7d1) at **20% opacity**. Never 100%.
*   **Interactive Depth:** On hover, a card should not just change color; it should transition from `surface-container-lowest` to a `surface` with the signature ambient shadow, simulating a physical "lift" toward the user.

---

## 5. Components: Precision Primitives

### Buttons
*   **Primary:** Gradient fill (`primary` to `primary-container`), 8px radius, white text. No shadow on rest; signature shadow on hover.
*   **Secondary:** `secondary-fixed` (#cfe5ff) background with `on-secondary-fixed-variant` (#004a78) text. No border.
*   **Tertiary:** Ghost style. `on-surface-variant` text. Background appears only on hover as `surface-container-high`.

### Input Fields
*   **Base:** `surface-container-lowest` fill with a 1px "Ghost Border" (20% opacity).
*   **Focus State:** Border opacity increases to 100% using `primary`, with a 4px soft outer glow of `primary-fixed`.
*   **Roundedness:** Constant 8px (`md`).

### Data Tables & Lists
*   **Forbid Dividers:** Use vertical white space (`spacing-4` or `1rem`) to separate rows. 
*   **Alternating Tones:** Use a subtle shift between `surface` and `surface-container-low` for row zebra-striping if data density is extremely high.
*   **Blockchain Verification Chip:** A specialized `tertiary` chip with a soft `backdrop-blur` to indicate "Verified on Ledger" status.

### The "Chain-Link" Progress Indicator
A custom component for this system. Use a series of `surface-container-highest` dots connected by a 2px `primary-fixed` line. When a block is "confirmed," the dot transitions to a `primary` checkmark icon.

---

## 6. Do’s and Don’ts

### Do:
*   **Do** use `spacing-8` (2rem) as your default margin between major sections to maintain an "Editorial" feel.
*   **Do** use Boxicons with a "Regular" weight (2px stroke) to match the Inter typeface.
*   **Do** use `tertiary-container` (#475c79) for secondary data points like "Time-out" or "Location Lat/Long."

### Don’t:
*   **Don’t** use pure black (#000000) for text. Always use `on-surface` (#171c20).
*   **Don’t** use sharp 0px corners. Every element must feel "machined" with our `md` (8px) or `xl` (14px) rounding scale.
*   **Don’t** crowd the navbar. Use the "Sticky Top" layout to house only primary navigation and a "System Status" indicator (Green/Red ledger heartbeat).