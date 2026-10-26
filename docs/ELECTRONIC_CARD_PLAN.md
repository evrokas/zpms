# Electronic business card in ZPMS -- plan (draft, nothing built yet)

Status: planning. Written 2026-10-26. Source: `mweb/web/card.html` +
`mweb/web/css/styles-card.css` (a static mobile page: logo, photo, name/specialty, phones,
website, e-mail, address, social icons, and a list of five partner clinics with phone numbers
and addresses).

## 1. What the card is today

- A standalone static page in mweb (public site, evangelosrokasmd.gr), not part of ZeusFW
  routing; its own CSS, boxicons, font "Cyntho Slab Pro", and assets from `web/assets/`.
- Greek only, all content hard-coded in the HTML, including the clinics.
- Reached by URL only; no QR, no "save contact", no link from any app.

## 2. Problems to fix before reusing it (found by reading the file)

- Markup is broken: `div.clinics`, `div.contact-list` and `section.container` are never
  closed (the footer ends up inside them).
- Typos: "Buiness Card" (title), "Reserverd" (footer); copyright says 2025.
- Wrong/inconsistent data: Iatriko Psychikou link `tel:+2106974298` vs text `210 69 74 278`;
  the address line links to `mailto:` instead of a map; the two Iatriko entries both link to
  the same site; social icons point to `#`; the `logo-dunant.png` asset is unused.
- Phone links lack a consistent international format; no `alt` text on the photo/logo.
- No `lang` switch (English version missing) and no Open Graph tags for link previews.

## 3. Options (decide first)

A. **Minimal -- link only.** Leave the card in mweb. ZPMS only offers its URL: a "Copy card
   link" button and the text/QR for staff to give out. Cheapest; no duplicated content.
B. **Serve it from ZPMS.** A public, login-free `/card` route in ZPMS rendered from data
   (settings + the clinics/locations tables) so the doctor edits contact details once.
   Needs the logos/photo copied or shared, and keeps two cards in sync unless mweb's is
   retired or redirects.
C. **Both:** keep mweb's page as the public face, and make it read the same data from a
   small shared source (JSON file or ZPMS endpoint) -- most flexible, most moving parts.

Recommendation: do the clean-up (section 2) in mweb now, then A; move to B only if the
clinics/contact data should live in ZPMS anyway.

## 4. Pieces regardless of option

1. **Clean-up of `card.html`** (section 2), bilingual el/en with a language switch.
2. **vCard**: a downloadable `.vcf` ("Add to contacts") with name, role, phones, e-mail,
   website, address, photo. One button on the card.
3. **QR code** of the card URL (static image or generated once) for the business card,
   waiting room, and the e-mails.
4. **Link previews**: Open Graph/`<title>`/description so the link looks right in
   Viber/WhatsApp/iMessage.

## 5. Where ZPMS would use it

- Patient-facing messages (appointment confirmation text/e-mail, "copy message" buttons):
  a footer line with the card link. Note: ZPMS's current e-mails are internal (to doctors,
  assignment/reschedule); patient-facing messages would be new or live in DocArc's
  message templates, which already have editable bilingual text.
- Patient page: a "Copy card link" / "Send card" action next to the contact details.
- Staff: the QR image printable from Settings.

## 6. If option B (served by ZPMS)

- Route `/card` with `access: anonymous` and no session/audit side effects (analytics are
  off by default); no patient data on the page.
- Data from settings (name, specialty, phones, e-mail, website, address, social URLs) and
  the clinics list (name, logo, phones, address, order, enabled) -- admin-editable in
  Settings like the other cards; logos stored under `web/assets/` or uploaded.
- Strings through `t()` (el/en); the card's own CSS as a ZeusFW library, fonts self-hosted;
  `.vcf` endpoint generated from the same data.
- Tests in the existing suite: `/card` is 200 without login, contains no private data,
  `.vcf` parses (BEGIN/END, escaped fields), every `tel:`/`mailto:` link is well-formed,
  disabled clinics don't show.

## 7. Open questions

- Which option (A, B or C)? Who edits the contact/clinic data, and how often?
- Should patients get the card automatically (confirmation message) or only on request?
- Keep the "Cyntho Slab Pro" font (licence for self-hosting) and the current design?
- Languages: Greek only, or Greek + English?
- Is the card meant to replace mweb's page, or live next to it?
