# How to get this onto GitHub

You have two files:

- **`POS-NEW-STORE-feature-pos-enhancements.zip`** — the full project source, exactly as it looks on the new `feature/pos-enhancements` branch (one commit on top of your current `main`).
- **`pos-enhancements.patch`** — the same change as a git patch, in case you'd rather apply it to your own local clone directly instead of copying files.

## Option A — easiest (upload the branch via GitHub's web UI)
1. Unzip `POS-NEW-STORE-feature-pos-enhancements.zip`.
2. On GitHub, go to your repo → **Add file → Upload files**, switch the target branch dropdown (top left, next to "commit to") to a **new branch**, name it `feature/pos-enhancements`, and drag in the unzipped files (this will overwrite/add just what changed since GitHub diffs by path).
3. Open a Pull Request from `feature/pos-enhancements` into `main`.

## Option B — from your terminal (recommended, keeps history clean)
```bash
git clone https://github.com/KINGMANCHY/POS-NEW-STORE.git
cd POS-NEW-STORE
git checkout -b feature/pos-enhancements
git am /path/to/pos-enhancements.patch
git push -u origin feature/pos-enhancements
```
Then open a PR from `feature/pos-enhancements` into `main` on GitHub as usual.

If `git am` complains about a conflict, it means `main` has moved since this was built — use `git am --abort`, then `git apply --3way /path/to/pos-enhancements.patch` instead and resolve by hand.

## Before you deploy
1. Run these migrations against your existing database (all are safe/idempotent - they only insert/create if missing). Skip all if you're seeding a brand-new DB from `pos_store.sql`, which already includes them.
   - `database/migration_receipt_template_settings.sql`
   - `database/migration_cash_reconciliation.sql`
   - `database/migration_pwd_senior_discount.sql`
   - `database/migration_shifts.sql`
4. **Important**: run `migration_shifts.sql` and `migration_pwd_senior_discount.sql` together (or neither) — checkout() now correctly tolerates either being missing individually, but if you started testing Shifts before running its migration, restart the PHP process/clear any cached column-existence state by just reloading the page after migrating.
5. **If you use Cashier or other non-Administrator/Manager roles**: also run `database/migration_default_role_permissions.sql` and `database/migration_reconciliation_permission.sql`. These fix a real bug where most pages ignored the Roles & Permissions settings entirely and used a hard-coded Administrator/Manager-only check instead — see item 19 below.
2. The barcode/QR **camera** tab needs HTTPS (or `localhost`) to get camera permission from the browser — that's a browser requirement, not something this code can work around.
3. Everything else works with no extra setup.

## What changed
Twelve commits on `feature/pos-enhancements` (see each commit message, or the top of the patch file, for full details):
1. Receipt Templates, Barcode/QR Search, Reports filters, Full-screen, Dark/Light mode, cart totals.
2. Dark mode contrast fix, split-payment cash lock, Payment Complete modal, Transaction Record + End of Day Reconciliation.
3. Every split-payment method (not just cash) now locked to one use, Receipt now shows before Payment Complete, Change amount enlarged in Payment Complete.
4. "End of Day" button + modal on the POS Screen, and per-item bargained price/discount in the POS cart.
5. Moved "End of Day" into the Reconciliation page; POS cart price/discount now open modals instead of inline textboxes.
6. Fixed the cart's per-line Total (was double-adding tax), aligned the End of Day button's size, added Senior Citizen / PWD statutory discount.
7. Hardened checkout so client/server can't disagree on the amount owed, replaced the manual-discount textbox with an Additional Discount widget, fixed "Show receipt after sale" gating.
8. Fixed a per-item discount cap mismatch between client and server (found via fuzz testing).
9. Wired up "Prices are tax-inclusive" (previously stored but never applied to any pricing math), redesigned the Payment modal's payment method section (Single/Split toggle, numbered tenders, FILL button, Paid/Remaining bar), matched Additional Discount/Senior-PWD widget styling, added an itemized discount breakdown.
10. Fixed a bug where the Senior/PWD ID number and Additional Discount value fields only accepted one digit before the field disappeared out from under the cursor (added explicit Apply buttons), plus targeted fuzz-testing of the checkout guard across split-payment boundary cases.
11. Found and fixed the actual root cause of the recurring "combined payments are less than the total due" false rejections: a variable name collision inside `Sale::priceCart()` that silently discarded the Additional Discount on any multi-item cart.
12. Fixed the sidebar so "Settings" (the last nav item) is always reachable on mobile and at 100% browser zoom, and added a full Start Shift / End Shift feature (per-cashier cash-drawer sessions with a Quick Count denomination breakdown, a live shift badge/panel on the POS Screen, and an End Shift cash reconciliation with variance).
13. Fixed a tax/discount regression (tax was accidentally being computed on the post-discount amount instead of the original sticker price - restored to the app's original convention), hardened checkout so it can't be broken by any not-yet-run migration (was throwing a generic "Checkout failed." on unmigrated databases), moved the Shift feature into the persistent nav bar (visible from every page, styled like the notification bell), and polished the End Shift button styling.
14. Fixed a critical checkout crash (`SQLSTATE 23000` FK violation on `FK_SalePayments_Sale`) caused by a missing `$stmt->execute()` call introduced in the previous commit's dynamic-column INSERT rewrite — every sale was failing. Also redesigned the compact shift badge in the nav, which was overflowing/rendering broken due to a 3-line stacked layout crammed into a single-line pill.
15. Fixed the Shift Active panel's padding being silently zeroed out by a conflicting Bootstrap `p-0 !important` utility class, and widened its mobile-responsive breakpoint (575px → 767px) so it doesn't overflow the screen on more phone/tablet widths.
16. Collapsed the Shift badge to an icon-only view on mobile (matching the other nav icon buttons) instead of the full "Shift #6 · 7h 18m" text, which was crowding the navbar on phone widths.
17. Shortened "POS STORE" to just "POS" below 480px, and hid the Fullscreen toggle below 576px (least useful button on a phone) to relieve the remaining navbar overlap on the narrowest screens.
18. "Complete sale" now requires an active shift — if the cashier cancelled the Start Shift prompt earlier, clicking Complete Sale re-shows Start Shift instead of opening the Payment modal; cancelling again just returns to the cart, and Complete Sale proceeds normally once a shift is started.
19. **Fixed Roles & Permissions being ignored almost everywhere.** Only the POS Screen had ever been switched to use the app's granular permission system (`requirePermission()`); every other page and AJAX controller (Sales, Reports, Products, Customers, Reconciliation, Settings, etc. — 14 pages and 11 controllers) still used a hard-coded Administrator/Manager-only check that completely ignored whatever was actually configured on the Roles & Permissions page. Granting a Cashier (or any custom role) access to anything through that page had no effect. All of them now consult the real granted permissions, matching how POS already worked. Also added the one missing permission key (`reconciliation.manage`, for a feature built after the original permission seed).
20. **Fixed a major revenue/tax/discount inflation bug affecting the Dashboard and Reports page.** `Report::summary()` joined Sales → SaleDetails → Products in one query, then summed Sales-level totals (revenue, tax, discount) across that join — so a single sale with 3 different products in the cart was counted as 3x its actual revenue. Any store selling more than one item per transaction (almost all of them) had inflated numbers on the Dashboard's "Today's sales" card and every figure on the Reports page. Verified the fix with a real executable reproduction (not just reasoning) — confirmed a ₱100/3-item sale went from reporting ₱300 to the correct ₱100.
