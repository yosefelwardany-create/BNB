# Booking websites — stage 2: the drag-and-drop editor

**Status:** approved to build (2026-10-30) · **Date:** 2026-10-30 ·
**Follows:** `2026-10-29-booking-websites-stage-1-design.md`

## Why

Stage 1 gave every site one fixed template, edited through a form of plain
fields. The owner found it ugly and hard to use, and wants what Lodgify
offers: a page builder anyone can use, where a page is put together from
ready-made blocks by dragging, and text is changed by clicking on it.

## Decisions taken

| Question | Answer |
|---|---|
| Who edits | The platform owner (every site) **and clients** (their own client site) |
| Builder style | A **stack of ready-made blocks** per page: drag to add and reorder, click to edit. Not a free-form canvas |
| Which pages | **Home and any extra pages** (About, The area, FAQ…) are built from blocks, with a menu. Search, property, checkout and booking pages keep their booking layout and follow the theme |
| When guests see changes | **Draft, then Publish.** Edits save as a draft only editors see; Publish puts them live; Discard changes returns to what is live |
| What a client may do | **Edit and publish their own client site.** Never the brand site or another client's. The platform keeps the address, the booking rules (hold and answer times) and taking a site online or offline |
| Look | **Three themes** (Coastal, Classic, Bold) plus style controls: main colour, button colour, a font pairing from six, button shape |
| How it is built | **Approach 1:** a React editor in Habitat's app beside a live preview that is the real site, rendered by the same Laravel templates guests get |

## The editor screen

A full-screen editor with three panels and a top bar.

- **Top bar:** back to the list, the site's name, draft status ("Saving…",
  "All changes saved", "Not saved — retrying"), undo and redo (also Ctrl+Z and
  Ctrl+Shift+Z), desktop or phone preview, **Discard changes**, **Publish**.
- **Left panel:**
  - **Pages:** drag to reorder the menu, show or hide each in the menu, add,
    rename, delete (not Home).
  - **Blocks on this page:** drag to reorder, click to select, "+ Add block".
  - **Theme**, **Homes on this site** (the stage 1 picker),
    **Site details** (name, contact, social links, logo).
- **Centre, the preview:** the real site in draft.
  - Hovering a block outlines it and shows a toolbar: a drag handle,
    duplicate, delete.
  - Plain-text fields (headings, taglines, button labels) are typed straight
    onto the page.
  - A "+" between blocks opens the block library there.
  - Blocks can be dragged up and down in the preview as well as in the left
    panel.
- **Right panel:** the selected block's settings in plain words — its texts,
  a photo picker, a few choices ("Show 3 or 6 homes", "Photo left or right"),
  its background. No CSS and no sizes.
- **Photos:** dropped onto the picker to upload, or chosen from the site's
  photo library or from the photos of the homes on the site.
- **A new site** starts with a home page already laid out (big photo, our
  homes, a short text, contact), so nobody starts from a blank page.

**Site details save at once** — they are used in emails and on every page
and are not part of the draft. The editor says so beside them. Pages, blocks,
theme and style are the draft.

## Blocks

Twelve blocks. Each is designed for phones first and has a **background**
choice: plain, soft tint, or the theme colour.

| Block | Its settings |
|---|---|
| `hero` Big photo + search | Heading, tagline, photo, light or dark text, search bar on or off |
| `homes` Our homes | Title; which: featured / all / chosen; how many: 3, 6 or all; grid or carousel |
| `text` Text | Heading, rich text (bold, italic, links, lists) |
| `text_image` Text + photo | Heading, rich text, photo, photo left or right, optional button (label and link) |
| `gallery` Photo gallery | Up to 12 photos, grid or slider |
| `reviews` Guest reviews | Title; best published reviews of the site's homes, minimum rating, how many (3, 6). Real reviews only |
| `highlights` Highlights | Title; 3–6 items, each an icon (from a fixed set) and a short line |
| `map` Map | Title, a short "getting here" text; an area map around the site's homes, approximate as on property pages |
| `faq` FAQ | Title; up to 20 questions and answers, opened on tap |
| `cta` Call to action | A sentence, a button label and link |
| `contact` Contact | Title, intro; shows the phone, email and address from Site details |
| `spacer` Spacer | Small, medium or large; with or without a line |

The guest message form on the contact block is not in this stage: it needs
spam protection of its own.

**Links** in buttons point to one of: a page of the site, the search page, an
email or phone, or a web address (`https://` only).

## Themes and style

- Three themes share every block and the same markup, and differ only in
  their stylesheet, so switching theme never loses or moves content:
  - **Coastal** — today's look: light, rounded corners, serif headings.
  - **Classic** — elegant serif throughout, thin lines, square corners.
  - **Bold** — large sans-serif type, strong colour bands, wide spacing.
- **Style:** main colour, button colour, a font pairing chosen from six
  tested pairs, button shape (rounded, pill, square). Colours are `#rrggbb`.
- The editor warns when the button colour and its text would be hard to
  read (contrast below 4.5:1).
- Search, property, checkout and booking pages use the site's theme and
  style too.

## The content document

A site's pages, blocks, theme and style are one JSON document, stored twice
on `websites`: `draft_content` (what editors see) and `content` (what guests
see), with `draft_version` (an integer, for saving safely) and
`content_published_at`.

```json
{
  "schema": 1,
  "theme": "coastal",
  "style": { "primary": "#1F4E5F", "accent": "#E07A5F", "fonts": "fraunces-inter", "buttons": "rounded" },
  "pages": [
    { "id": "pg_…", "kind": "home", "title": "Home", "slug": "", "in_menu": true,
      "blocks": [ { "id": "bl_…", "type": "hero", "background": "plain", "fields": { "heading": "…" } } ] },
    { "id": "pg_…", "kind": "page", "title": "About us", "slug": "about-us", "in_menu": true, "blocks": [] }
  ]
}
```

- Exactly one home page, first; up to 20 pages; up to 30 blocks a page. A
  page's address is `/sites/{site}/{slug}`; Home's is `/sites/{site}`.
- Page slugs are lowercase words joined by hyphens, unique, and never a name
  the site already uses (`search`, `stay`, `checkout`, `booking`, `api`,
  `photos`, `media`, `images`, `preview`, `sitemap.xml`, `robots.txt`).
- **Every save is validated whole** against the block definitions: known
  types, known fields, field kinds, lengths, item counts, choices, image
  references that belong to the site, link kinds. A refusal names the
  field's path (`pages.0.blocks.2.fields.heading`).
- **Rich text** is cleaned on save to an allowlist — `p`, `br`, `strong`,
  `em`, `ul`, `ol`, `li`, `a` (with `href` of `https:`, `mailto:`, `tel:` or a
  site path only) — and every other field is plain text, escaped when
  printed.
- **Block definitions** live in PHP (`app/Domain/Websites/Blocks`): each
  type's label, icon, fields (`text`, `rich_text`, `image`, `images`,
  `choice`, `toggle`, `items`, `link`), defaults and Blade view. The editor
  reads them from the API, so a block exists in one place.
- **Photos** uploaded in the editor go to the `media` disk and a
  `website_images` row (site, path, type, size, alt text). Blocks refer to
  a site image (`img:<id>`) or to a photo of a home on the site
  (`photo:<id>`).
- **Existing sites** get a document made from their stage 1 settings: the
  hero (heading, tagline, photo) and featured homes; theme Coastal; their
  two colours. Their pages look the same as before.
- **A new site** starts from the starter document: big photo, our homes, a
  short "About us" text and contact.

## Saving, publishing, discarding

- The editor keeps the document in memory with an undo history of 50 steps,
  and saves the draft 0.8 s after the last change:
  `PUT …/draft {content, version}`.
- The server answers with the new version. A save carrying an old version
  is refused (409): someone else changed the draft. The editor says so and
  offers to reload.
- **Publish** validates the draft again and copies it to `content`.
- **Discard changes** copies `content` back to the draft.
- Publishing content is not putting a site online. The site's own
  online/offline switch stays the platform's, renamed **Go live** and
  **Take offline** to keep the two apart.

## Preview

- The centre pane loads `/sites/{site}/preview/{page?}?token=…`, the real
  page rendered from the **draft**. It works whether or not the site is live
  and is never indexed (`noindex`, `no-store`).
- The token is a signed URL that lasts two hours; the editor asks for a new
  one when it expires.
- In preview only, each block's wrapper carries `data-block-id`, editable
  plain-text elements carry `data-field`, and the page loads
  `public/sites/editor/overlay.js`. A guest's page has none of this.
- **The overlay script and the editor talk by `postMessage`.** Both are on
  the same origin, and each checks the other's origin.
  - Overlay → editor: `select`, `text` (a field's new value),
    `move` (block, new index), `duplicate`, `remove`, `insert-at` (index).
  - Editor → overlay: `replace` (a block's new HTML), `order` (block ids),
    `select`.
- When a block's settings change, the editor asks
  `POST …/render {block, theme, style}` for that block's HTML and the overlay
  swaps it in place. The page reloads only when the theme, the style, the
  menu or the page itself changes.

## Who may edit

- **Platform owner:** every site, from the Websites screen. **Edit site**
  opens the editor full screen at `/websites/{id}/edit`. Address, booking
  rules, Go live / Take offline and the site's account are only on the
  Websites screen.
- **Clients:** a **Website** page in the client portal lists their account's
  client sites. **Edit site** opens the same editor at `/website/{id}/edit`.
  - The API is `/api/v1/portal/owner/websites/{id}/…`. It finds the site
    among the client sites of the signed-in client's own account, and
    answers 404 for any other site.
  - **The client portal is read-only today** (`EnsureClientReadOnly`). Only
    the editor's own routes are let through, by route name, and nothing else
    changes.
  - A client can:
    - edit the draft, theme, style and site details,
    - upload and remove site photos,
    - choose which of their published homes the site shows,
    - publish and discard.
  - A client cannot publish a listing from the editor; they see what a home
    still needs.

## Errors

- A block that fails to render on a guest's page is left out and logged;
  the page never fails.
- A save that fails shows "Not saved — retrying" and retries with backoff.
  Leaving the editor with unsaved changes asks first.
- A refused save (422) points at the field and selects its block.
- Uploads accept JPEG, PNG and WebP up to 8 MB.

## Testing

- **PHP:**
  - document validation, field by field, and the limits;
  - the rich-text cleaner against script, event-handler, `javascript:` and
    style attacks;
  - every block rendering in every theme;
  - publish, discard and the version conflict;
  - the preview token, and the draft never reaching a guest;
  - custom pages, their menu and reserved slugs;
  - the stage 1 migration;
  - client access to their own site, and 404 for others';
  - the read-only exception covering only the editor routes.
- **Frontend (Vitest):**
  - the document operations (insert, move, duplicate, remove, undo, redo);
  - autosave and the conflict message;
  - the settings panel built from block definitions;
  - publish and discard.
- **Overlay script:** loaded into jsdom, with hover, typing and moving
  checked as messages.
- **One run in a real browser** (Playwright, locally) before each PR.

## Delivery

1. **PR 5 — Pages, blocks and themes, with the editor.**
   - The document, the block definitions and all twelve views, the three
     themes and style, rendering home and custom pages from the published
     document, the menu, the migration of existing sites, the photo library.
   - The preview and the editor API.
   - The editor screen: panels, drag to reorder in the left panel, the
     settings panel, the block library, autosave, publish, discard, a preview
     that reloads after each save.
   - The old look fields leave the Websites screen.
2. **PR 6 — Editing on the page.** The overlay: hover toolbar, typing on the
   page, drag in the preview, "+" between blocks, a block re-rendered in
   place, undo and redo, phone preview, drop-to-upload.
3. **PR 7 — Clients edit their own site.** The client portal page, the
   portal API and the read-only exception, with their tests.

## Out of scope for stage 2

Free-form layout, custom CSS or HTML blocks (stage 3), more themes
(stage 3), a guest message form, per-page SEO fields (stage 5), translations
(stage 4), version history beyond the live document.
