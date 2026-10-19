# Changelog

All notable changes. Versions follow semantic versioning; each release is the git tag `v<version>`.

## Unreleased

Release 1.2.0 (in progress).

- A form field can be shown on staff entry only (for example "Visitor type"): it appears in Save & New and on staff entries' edit and view pages, never on the public form, and is never checked there even when required. Its Arabic labels are optional. It counts toward the 15 active custom fields and is exported and sent to the ERP like any custom field.
- A list field can be shown as radio buttons, on the public form too: an optional group starts with "Not set", a required one has nothing chosen and says "Choose one". Database: one additive migration (`field_definitions.audience`, `field_definitions.widget`).

## [1.1.0] - 2026-10-16

Interface fixes from a usability and accessibility review, a public form language per exhibition, and the removal of the spam check. **Database:** one additive migration (`exhibitions.default_locale`, existing exhibitions get Arabic). Two new settings keys (`logo_width`, `logo_height`) need no migration.

Bugs and spam check
- CO-1: The public form no longer has a minimum fill time, a hidden trap field or a signed render time. Every valid submission is saved, including one sent a second after the page opened (for example with browser autofill), instead of being thrown away behind a "thank you" page. Rate limits, the request size cap, CSRF and the submission token still apply. "website" is now allowed as a custom field key.
- CO-2: Save & New's Retry and "Save as a new entry" buttons stay hidden until they are needed.
- CO-3: Every date, number, URL and file field has the same 44 px height and 16 px text as the other fields.
- CO-4: After a Save & New save the message names the visitor and stays 4 seconds; a possible duplicate is a warning that stays until the next save, with a link to the earlier entry for admins and super admins. The message area keeps its height, so the form no longer moves. A line counts the entries saved on the page.
- CO-5: The TEST ERP banner is inside the page header landmark.

Public form language
- CO-6: Each exhibition has a public form language, Arabic or English, chosen when it is created (Arabic preselected) and editable later; the exhibitions list and the exhibition page show it.
- CO-7: Public pages open in the exhibition's language. A language switch is remembered for that exhibition only; `?lang=` in the URL still wins.
- CO-8: The poster puts the exhibition's language first.

Mobile and accessibility
- CO-9: Every form uses one field component (label, optional hint, control, error), with the error tied to the control for screen readers. The ERP connection blocks show their errors next to the field, in that block only.
- CO-10: After a failed submit of the public form or the visitor edit form, the first wrong field has the focus, and the message at the top lists each problem as a link to its field.
- CO-11: A two-tone focus ring (yellow next to the control, dark outside it) is visible on white, grey and any brand colour.
- CO-12: On phones, stacked table rows show each column's name; visitor checkboxes are larger, Edit is easier to tap, and Delete is on the visitor page only. Every link and button is at least 24 px in each direction.
- CO-13: Staff navigation marks the current page. On phones it shows Save & New and a Menu; the settings pages share one sub-menu (Settings, Branding, Form fields, ERP, System). The forced password change page shows no navigation.

Flow and copy
- CO-14: The public form's heading is the exhibition name, with its dates under it; the Send button is visible without scrolling on a typical phone.
- CO-15: The poster says "Scan to register" in both languages, shows the dates, has a Print button and fits a phone screen; it still prints on one A4 page.
- CO-16: Staff open straight into Save & New. Admins and super admins see today's and total registrations per open exhibition and, with Odoo, items to check, whether sending is on, and the active connection.
- CO-17: With ERP mode None the visitor list has no ERP column or filter. With Odoo the states read Not sent, Waiting, Sent to TEST or LIVE, Failed and Check in Odoo.
- CO-18: Choosing the active ERP connection sits next to its button and is confirmed on a page that names the connection, its address and database; choosing the active one changes nothing. One main button per section.
- CO-19: On phones the visitor filters fold into "Filters" (open when any filter is set), and the list shows how many visitors match.
- CO-20: "Close registration" and "Reset password" ask for confirmation first.
- CO-21: A drawn lock icon instead of an emoji; red buttons for "Move to trash" and "Delete permanently"; the field form hides Choices for short text; error pages link back to the staff pages or the registration form; the sign-in page shows the organisation; batch states and errors in plain words with correct plurals; the closed page asks visitors to see the team at the stand; buttons react to hover and press; the logo carries its width and height (stored when it is uploaded; a logo uploaded earlier gets them when it is uploaded again); a Show button on password fields; all colours come from named tokens.
- CO-22: The interface says "Send" for the ERP action everywhere, with clearer texts for empty lists, a signed-out Save & New and the public thank-you page.

Release scripts (since 1.0.0)
- `bin/deploy.sh` leaves releases readable by the web server (directories 755, files 644, umask 022) and prunes old releases without process substitution, which CageFS does not support; `bin/build-release.sh` no longer packs an archive root of mode 700.

## [1.0.0] - 2026-10-13

First release.

- Public registration per exhibition through a QR code: Arabic (right to left, default) and English, works without JavaScript, spam traps, rate limits, replay-safe submissions.
- Staff Save & New with retry that never saves a visitor twice or loses typed values; staff may enter visitors for closed exhibitions (paper forms).
- Visitor list with search (name or phone in any format, Arabic digits included), filters, possible-duplicate flags, CSV export (UTF-8 with BOM).
- Edit log, record lock once sent to the live ERP, 30-day trash, permanent deletion by super admins, retention.
- Three roles: super admin, admin, staff.
- Odoo 18 lead push: explicit selection, batches, a bounded cron drain that searches by reference before every create (no duplicate leads), retries with backoff, review of ambiguous cases, Test and Live connections, encrypted API key, connection test.
- Branding per installation (name, logo, colour), configurable form fields, region data per country (Egypt included).
- `/healthz`, System page, deploy and rollback scripts for cPanel hosting.

New `.env` keys: none required. Optional: `DEFAULT_COUNTRY`, `TRUST_CLOUDFLARE_HEADER`.
Migrations: initial schema.
