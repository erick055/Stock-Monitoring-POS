# Responsive layout update — November 4, 2026

Shared styles now address phone, tablet, narrow laptop, wide desktop and short
landscape layouts across owner and staff pages. Breakpoints include 380, 640,
720, 900, 1,024 (coarse pointer), 1,200 and 1,800 pixels.

Changes include wrapping account cards and filter forms, preventing grid children
from expanding their panels, normal-size checkboxes with larger label hit areas,
44-pixel phone buttons, 16-pixel text fields on phones/coarse-pointer tablets,
scrollable short-screen dialogs, scrollable history tabs, and stacked pagination.
POS catalog/cart scrolling is bounded on phones so long catalogs do not push
checkout below thousands of pixels of product cards. Receipt screen text wraps;
the existing 80 mm print layout is preserved.

Wide tables stay horizontally scrollable rather than squeezing or hiding their
columns. A hint appears only when a table actually overflows. Scroll containers
become keyboard-focusable when needed, update after pagination/resizing, and
remove observations/hints when old tables are removed. Mobile sidebar links are
inert while closed; opening, Escape, keyboard focus containment and desktop
resizing preserve accessible navigation.

Validation passed: 303 PHP tests (3,113 assertions), 42 JavaScript tests, and the
production build. Seventeen real page templates were also rendered using
fictional records in an isolated in-memory database; all included viewport meta
tags and shared responsive styles. No application/VPS records were modified.
Temporary page snapshots and their local server were removed.

Visual device checks remain pending. The isolated browser could not reach the
local preview server, and its URL security policy blocked self-contained local
previews. No browser screenshots or actual viewport overflow measurements were
obtained. Automated tests verify behavior and rendering, not every physical
device's visual layout.

Before live deployment, visually check representative widths of 320/360/390/430,
768/820/1,024, 1,280/1,366, and 1,440/1,920/2,560 pixels in both themes. Check
landscape orientation and an open software keyboard, long product/SKU/email
strings, tablet account/filter layouts, table swiping and pagination, sidebar
focus, and each popup's final action buttons. Include `responsive-tables.js` in
the complete source release and deploy the newly built assets.
