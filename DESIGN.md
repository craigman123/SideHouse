# DESIGN.md — Side House

Visual rules for every page. Read `AGENTS.md` first. Do not invent colors, fonts, radii or spacing outside this file.

## Direction

Dark, sporty and clean. Near-black pages with soft green glows, white Montserrat text, one strong green accent, pill buttons and rounded cards with thin gradient outlines. The style comes from a fitness-school reference. Take the **style system only** (colors, type, shapes). Do not copy its layouts, logo, photos, text or tilted hero mockup.

## Design tokens

Put these in `public/css/tokens.css`, loaded by `layouts/app.blade.php` and `layouts/user.blade.php` before any page CSS (standalone pages that do not extend a layout load it themselves). Page CSS files must use the variables, never hard-coded colors.

```css
:root {
  /* Color */
  --bg: #0A0A0A;            /* page background */
  --surface: #141414;       /* cards, panels, modals */
  --surface-2: #1C1C1C;     /* inputs, hover, nested panels (derived) */
  --border: rgba(255,255,255,.08);
  --text: #FFFFFF;
  --text-muted: #A3A3A3;    /* secondary text (derived) */
  --brand: #009D57;         /* main green */
  --brand-hover: #00B274;
  --brand-grad: linear-gradient(135deg, #00B274, #27A5FF);
  --info: #27A5FF;
  --danger: #F85149;        /* errors, blocked slots, destructive actions */
  --warning: #D29922;       /* pending payment, expiring bookings */

  /* Type */
  --font: 'Montserrat', system-ui, -apple-system, 'Segoe UI', sans-serif;

  /* Radius */
  --r-sm: 10px; --r-md: 14px; --r-lg: 20px; --r-pill: 999px;

  /* Spacing (4px base) */
  --s1: 4px; --s2: 8px; --s3: 12px; --s4: 16px; --s5: 24px;
  --s6: 32px; --s7: 48px; --s8: 64px; --s9: 96px;
}
```

Notes on the colors:
- The reference swatch labeled `#3C9D57` actually measures about `#009E52`, so the brand green is `#009D57`.
- The gradient `#00B274` to `#27A5FF` is for accents only: card outlines, a highlighted heading, small badges. Never a full-page background.
- Red and amber are not in the reference, so they are semantic additions for status.

## Page background

Near-black with soft green glows at the corners:

```css
body {
  background:
    radial-gradient(60% 45% at 85% 0%, rgba(0,157,82,.18), transparent 70%),
    radial-gradient(40% 40% at 0% 100%, rgba(0,157,82,.14), transparent 70%),
    var(--bg);
  color: var(--text);
  font-family: var(--font);
}
```

## Typography

Montserrat (free, Google Fonts, OFL license). Load weights 400, 500, 600, 700 with `display=swap`.

| Use | Size | Weight |
|---|---|---|
| H1 (hero) | `clamp(2.25rem, 5vw, 3.5rem)` | 700 |
| H2 (section) | `clamp(1.5rem, 3vw, 2.25rem)` | 700 |
| H3 (card title) | 1.125rem | 600 |
| Body | 1rem, line-height 1.6 | 400 |
| Small / labels | 0.875rem | 500 |
| Buttons | 1rem | 600 |

Signature touch: one key word in each H1 or H2 is colored `--brand` (for example "Book your **court**"). Use it once per heading, not more.

## Shape and components

**Cards**
```css
.card {
  background-image:
    linear-gradient(var(--surface), var(--surface)),
    linear-gradient(135deg, rgba(0,178,116,.55), rgba(39,165,255,.25));
  background-origin: border-box;
  background-clip: padding-box, border-box;
  border: 1px solid transparent;
  border-radius: var(--r-lg);
  padding: var(--s5);
}
```

**Buttons** (always pill-shaped, minimum 44px tall for touch)
- Primary: `--brand` background, white text, 600 weight. Hover: `--brand-hover`.
- Secondary: transparent, 1px `--brand` border, white text. Hover: `rgba(0,157,82,.12)` background.
- Destructive: transparent with `--danger` border and text.
- Disabled: `--surface-2` background, `--text-muted` text, no hover.
- Contrast note: white on `#009D57` is about 3.5:1, fine for 16px/600 buttons but not for small body text. Never put small white text on green.

**Inputs**: `--surface-2` background, 1px `--border`, `--r-md` radius, 48px tall. Focus: 2px `--brand` outline. Error: `--danger` border plus a message under the field.

**Navbar**: sticky, `--bg` at 85% opacity with a thin bottom `--border`. Logo on the left, links in the middle or right, login/book button on the far right. Collapses to a menu button below 768px.

**Glass overlay card** (info chips on top of photos): `rgba(20,20,20,.6)` background, `backdrop-filter: blur(8px)`, `--r-lg`. Use sparingly, since blur is heavy on low-end phones.

**Badges**: pill, small text. Confirmed = green, pending = amber, cancelled or blocked = red, neutral = `--surface-2`.

**Toasts**: bottom right (top center on mobile), `--surface` with a left color bar (green success, red error, amber warning). Auto-dismiss after 4 seconds.

**Modals** (Find My Booking, equipment, payment): `--surface`, `--r-lg`, dimmed `rgba(0,0,0,.65)` backdrop, close button top right.

## Booking flow screens (the most important part)

1. **Court cards**: photo, sport name, hourly rate, short features, Book button.
2. **Date and time-slot grid**: chips in a responsive grid.
   - Available: `rgba(0,157,82,.12)` fill with a `--brand` border.
   - Selected: solid `--brand`, white text.
   - Taken: `--surface-2` with `--text-muted` text, not clickable.
   - Blocked: `--danger` border at 50% opacity.
   - Overnight slots (until 12 AM) show the date clearly.
3. **Equipment rental**: small cards with a quantity stepper.
4. **Payment (QR Ph)**: centered card, large QR, amount, reference number, countdown timer, and an amber "waiting for payment" badge that turns green on confirmation.
5. **Booking summary panel**: court, date(s), hours, equipment, total. Sticky on desktop, a bottom bar on mobile.
6. Show the steps as a stepper at the top of the flow: Court, Date and time, Equipment, Payment.

## Dashboards

- **User**: upcoming bookings as cards, a past bookings list, a notification bell with an unread dot.
- **Admin**: left sidebar (`--surface`), top bar, stat cards, tables with a sticky header, 1px row dividers, status badges. Tables scroll horizontally on mobile.

## Layout

- Max content width 1200px, side padding 16px on mobile and 32px on desktop.
- Section spacing: `--s9` on desktop, `--s7` on mobile.
- **Mobile first.** Most players will book from a phone, so design 360px wide first, then 768px and 1024px.
- Grids: 1 column on mobile, 2 at 768px, 3 at 1024px for card lists.

## Motion and accessibility

- Transitions 150-200ms (`ease`). Buttons and cards lift 2px on hover.
- Respect `prefers-reduced-motion: reduce` (turn off transforms and animations).
- Body text contrast must be at least 4.5:1. Visible focus ring on every interactive element.
- Do not rely on color alone for slot status. Add text or an icon.
- Icons: Lucide or Heroicons (both free). One icon set only.

## Refactor rules (important)

The redesign must not break working features.

- Change CSS and Blade markup structure only. Do not change JS logic, routes, controllers, payment code or DB.
- Do not rename or remove any `id`, `data-*` attribute or class that JavaScript uses (including the `ShowPage()` navigation and fetch hooks) unless you update every reference in the same change.
- No Tailwind, no CSS framework, no new libraries. Keep separate CSS files per page and the shared `tokens.css`.
- Phase 0 comes first: fix every `asset()` path in the views so it points to the real file in the new css/js subfolders. No visual changes in this phase.
- Then work page by page: tokens and base styles, guest booking flow, landing page, user pages, admin. One page per commit on a separate branch, so each step can be reverted.
- After each page: test it on mobile width, and test the full booking and payment flow before moving on.