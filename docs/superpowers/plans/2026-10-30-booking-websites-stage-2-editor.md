# Booking websites stage 2 — the drag-and-drop editor: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Websites form with a drag-and-drop page builder.
Pages are stacks of twelve ready-made blocks, with three themes, drafts and
publishing, and a live preview of the real site. The platform owner edits
every site; clients edit their own.

**Architecture:**
- A site's pages, blocks, theme and style are one JSON document, held twice
  on `websites`: `draft_content` for editors and `content` for guests.
- Block definitions live in PHP. The server validates them and renders them
  with Blade; the React editor reads the same definitions to build its
  settings panel.
- The editor's centre pane is an iframe of the real site rendered from the
  draft through a signed preview URL.

**Tech Stack:**
- Laravel 13 / PHP 8.4, PostgreSQL, Blade.
- React 19, TanStack Query, `@dnd-kit/core` 6.3.1, `@dnd-kit/sortable`
  10.0.0, `@dnd-kit/utilities` 3.2.2.
- Vitest with jsdom.

**Spec:** `docs/superpowers/specs/2026-10-30-booking-websites-stage-2-editor-design.md`

## Global Constraints

- Exactly one home page, first; up to 20 pages; up to 30 blocks a page.
- Page slugs: `^[a-z0-9]+(?:-[a-z0-9]+)*$`, unique, never one of `search`,
  `stay`, `checkout`, `booking`, `api`, `photos`, `media`, `images`,
  `preview`, `sitemap.xml`, `robots.txt`.
- Rich text allowlist: `p`, `br`, `strong`, `em`, `ul`, `ol`, `li`, `a`.
  `href` may only be `https:`, `mailto:`, `tel:` or a site path starting
  with `/`. Every other field is plain text, escaped when printed.
- Colours are `#rrggbb`. Themes: `coastal` (default, today's look),
  `classic`, `bold`. Button shapes: `rounded`, `pill`, `square`.
- Font pairs, as six keys:
  - `fraunces-inter` (Fraunces / Inter) — the default
  - `playfair-source` (Playfair Display / Source Sans 3)
  - `lora-nunito` (Lora / Nunito Sans)
  - `dmserif-dmsans` (DM Serif Display / DM Sans)
  - `jakarta` (Plus Jakarta Sans / Plus Jakarta Sans)
  - `outfit-inter` (Outfit / Inter)
- Block backgrounds: `plain`, `tint`, `brand`.
- Image references:
  - `img:<website_images id>`
  - `photo:<property_photos id>`, only for a photo a home on the site shows
  - `site:hero` and `site:logo`, the stage 1 images
- Uploads: JPEG, PNG or WebP, up to 8 MB.
- Saving: an undo history of 50 steps. Autosave 800 ms after the last
  change. A stale `version` gets a 409 with the message "Someone else
  changed this site since you opened it. Reload to see their changes."
- Preview URLs are signed and last 2 hours. They are sent with
  `X-Robots-Tag: noindex` and `Cache-Control: no-store`.
- A block that fails to render on a guest's page is left out and logged;
  the page never fails.
- Copy is plain and guest-facing. No CSS or pixel sizes in the editor.

## Review Focus

- **Rich text pasted from Word or Google Docs** (spans, styles, classes,
  `<script>`, `onerror=`, `javascript:` links) is cleaned to the allowlist
  and keeps its words. Test in Task 5.1.
- **Two tabs or two people editing one draft** must not silently overwrite
  each other: the later save gets a 409, and the editor says so and offers
  a reload. Tests in Tasks 5.3 and 5.4.
- **A block pointing at a deleted photo or a home no longer on the site**
  renders without it and the page still answers 200. Test in Task 5.2.
- **A stage 1 site with no document** looks exactly as before on the public
  site, and its editor opens on its current heading, tagline, photo and
  colours. Tests in Tasks 5.2 and 5.3.
- **The draft never reaches a guest:** a page that exists only in the draft
  answers 404 publicly, and a preview URL without a valid signature is
  refused. Tests in Tasks 5.2 and 5.3.

---

## PR 5 — Pages, blocks and themes, with the editor

### File structure

```
database/migrations/2026_10_30_000100_add_website_content.php
app/Domain/Websites/Blocks/BlockField.php      — one field: name, kind, label, limits, options, default
app/Domain/Websites/Blocks/BlockType.php       — one block: type, label, icon, description, fields
app/Domain/Websites/Blocks/BlockRegistry.php   — the twelve definitions; all(), get(), toArray()
app/Domain/Websites/Content/RichText.php       — clean(string): string
app/Domain/Websites/Content/SiteStyle.php      — themes, font pairs, shapes; css variables; fonts href
app/Domain/Websites/Content/SiteContent.php    — published(), draft(), fromSettings(), newId(), page lookups
app/Domain/Websites/Content/ContentValidator.php — validate(Website, array): array (cleaned) or ValidationException
app/Domain/Websites/Content/BlockRenderer.php  — render(Website, array $block, array $content, bool $preview): string
app/Domain/Websites/Models/WebsiteImage.php
app/Domain/Websites/Services/WebsiteEditor.php — state, saveDraft, publish, discard, previewUrl, storeImage, deleteImage
app/Http/Controllers/Api/V1/PlatformConsole/WebsiteEditorController.php
app/Http/Controllers/Sites/SitePageController.php   (home, page, preview)
app/Http/Controllers/Sites/SiteAssetController.php  (image)
resources/views/sites/default/page.blade.php
resources/views/sites/blocks/{hero,homes,text,text_image,gallery,reviews,highlights,map,faq,cta,contact,spacer}.blade.php
resources/views/sites/default/layout.blade.php      (theme, style, menu)
public/sites/default/site.css                       (block styles)
public/sites/themes/{coastal,classic,bold}.css
frontend/src/pages/site-editor/{SiteEditorPage,PagesPanel,BlocksPanel,BlockLibrary,BlockSettings,ImagePicker,ThemePanel,SiteDetailsPanel,HomesPanel,PreviewPane}.tsx
frontend/src/pages/site-editor/document.ts     — pure document operations and the undo history
frontend/src/pages/site-editor/useAutosave.ts
```

### Task 5.1: The document, the block definitions and validation

**Files:**
- Create:
  - the migration
  - `Blocks/BlockField.php`, `Blocks/BlockType.php`, `Blocks/BlockRegistry.php`
  - `Content/RichText.php`, `Content/SiteStyle.php`, `Content/SiteContent.php`,
    `Content/ContentValidator.php`
  - `Models/WebsiteImage.php`
- Modify: `Models/Website.php`, which gains the casts and an `images()`
  relation.
- Test:
  - `tests/Unit/Websites/RichTextTest.php`
  - `tests/Feature/Websites/SiteContentValidationTest.php`

**Interfaces:**

Produces:

- **Migration:**
  - `websites` gains `draft_content` (jsonb, null), `content` (jsonb,
    null), `draft_version` (unsigned int, default 0) and
    `content_published_at` (timestamp, null).
  - New table `website_images`:
    - `ulid id`
    - `website_id` (foreign key, cascade on delete)
    - `path`
    - `mime_type`
    - `size_bytes`
    - `alt` (string 240, null)
    - timestamps
- **`BlockField`** — a readonly value object with `name`, `kind`, `label`,
  `?int max`, `array options`, `mixed default`, `array itemFields`,
  `int maxItems` and `?string hint`, plus `toArray(): array`.
  - Kinds: `text`, `rich_text`, `image`, `images`, `choice`, `toggle`,
    `items`, `link`, `listings`.
- **`BlockType`** — `type`, `label`, `icon` (a lucide name),
  `description`, `fields: list<BlockField>`.
  - `defaults(): array` gives each field's default value.
  - `toArray(): array`.
- **`BlockRegistry`** — `all(): array<string, BlockType>`,
  `get(string): ?BlockType`, `toArray(): list<array>`.
  The definitions, as `name kind(max) default`:
  - **hero:** `heading text(120)` "Find your place to stay";
    `tagline text(240)`; `image image`;
    `text_tone choice[light,dark]` light; `show_search toggle` true
  - **homes:** `title text(80)` "Our stays";
    `which choice[featured,all,chosen]` featured; `chosen listings`;
    `count choice[3,6,all]` 6; `layout choice[grid,carousel]` grid
  - **text:** `heading text(120)`; `body rich_text(5000)`
  - **text_image:** `heading text(120)`; `body rich_text(3000)`;
    `image image`; `image_side choice[left,right]` right; `button link`
  - **gallery:** `title text(80)`; `images images` (max 12);
    `layout choice[grid,slider]` grid
  - **reviews:** `title text(80)` "What guests say";
    `min_rating choice[any,80,90]` any (by `rating_percent`);
    `count choice[3,6]` 3
  - **highlights:** `title text(80)`; `items items` (max 6), each
    `icon choice[wifi,parking,pool,sea,mountain,pets,family,kitchen,workspace,air,heating,star]`
    and `text text(80)`
  - **map:** `title text(80)` "Where we are"; `body text(600)`
  - **faq:** `title text(80)` "Questions"; `items items` (max 20), each
    `question text(160)` and `answer text(1200)`
  - **cta:** `text text(160)`; `button link`
  - **contact:** `title text(80)` "Get in touch"; `intro text(400)`
  - **spacer:** `size choice[small,medium,large]` medium;
    `line toggle` false
- **Link value:** `{label: string≤40, kind: page|search|email|phone|url, target: string}`.
  The target is a page id for `page`, empty for `search`, an email, a
  phone number, or an `https://` address.
- **`RichText::clean(string $html): string`** — DOMDocument with the
  allowlist. Unknown elements are unwrapped (their text kept);
  `script`/`style` are dropped with their contents; every attribute is
  dropped except `a[href]`, which is checked; the output is capped at the
  field's `max` characters of text.
- **`SiteStyle`**:
  - constants `THEMES`, `FONT_PAIRS` (key ⇒ `[label, heading font, body font]`)
    and `BUTTONS`
  - `defaults(): array`
  - `cssVariables(array $style): string` returns
    `--brand:…;--accent:…;--font-head:…;--font-body:…;--button-radius:…`
  - `fontsHref(string $pair): string`, a Google Fonts css2 URL
- **`SiteContent`**:
  - `published(Website): array` — `content`, or else
    `fromSettings($site)`
  - `draft(Website): array` — `draft_content`, or else `published($site)`
  - `fromSettings(Website): array` — a home page with blocks `hero`
    (heading, tagline, image `site:hero` when set) and `homes` (featured),
    so the page looks as it did; theme `coastal`; style colours from
    `primary_colour` and `accent_colour`
  - `starter(Website): array` — for a new site: `hero`, `homes`, `text`
    (heading "About us", a one-line placeholder body) and `contact`
  - `newId(string $prefix): string` — `'pg_'` or `'bl_'` plus 12
    lowercase alphanumerics
  - `homePage(array $content): array`
  - `pageBySlug(array $content, string $slug): ?array`
- **`ContentValidator::validate(Website $site, array $content): array`**
  - Returns the cleaned document.
  - Throws `ValidationException` with messages keyed by path, e.g.
    `pages.0.blocks.2.fields.heading`.

- [ ] **Step 1: Write the failing tests**

`RichTextTest`:
- `test_keeps_the_allowed_formatting`: `<p>Hi <strong>there</strong></p>`
  comes back unchanged.
- `test_unwraps_unknown_elements_and_keeps_their_words`:
  `<span style="color:red" class="x">Sea</span>` becomes `Sea`.
- `test_removes_scripts_and_event_handlers`: `<p onclick="x()">A</p><script>alert(1)</script><img src=x onerror=alert(1)>`
  becomes `<p>A</p>`.
- `test_drops_unsafe_links_but_keeps_their_text`:
  `<a href="javascript:alert(1)">x</a>` becomes `x`;
  `<a href="https://a.test" target="_blank">x</a>` becomes
  `<a href="https://a.test" rel="noopener">x</a>`.
- `test_pasted_word_markup_is_reduced_to_paragraphs`:
  `<p class=MsoNormal><span lang=EN-GB style='font-size:11pt'>Cosy <b>flat</b></span></p>`
  becomes `<p>Cosy <strong>flat</strong></p>` (`b` ⇒ `strong`, `i` ⇒ `em`).

`SiteContentValidationTest` (Feature, RefreshDatabase, a brand `Website`):
- `test_a_starter_document_from_stage_one_settings_is_valid`:
  - Use settings `heading` "Sea & sun", `tagline` "Book direct",
    `primary_colour` "#112233".
  - `validate($site, SiteContent::fromSettings($site))` returns `theme`
    coastal, `style.primary` "#112233", and a first block `hero` with
    `fields.heading` "Sea & sun".
- `test_an_unknown_block_type_is_refused_by_its_path`: path
  `pages.0.blocks.0.type`.
- `test_a_too_long_heading_is_refused_by_its_path`: a 121-character hero
  heading, path `pages.0.blocks.0.fields.heading`.
- `test_unknown_fields_are_dropped_and_missing_ones_take_their_default`.
- `test_rich_text_is_cleaned_on_save`: a text block body with `<script>`
  comes back without it.
- `test_a_page_slug_may_not_be_reserved_or_repeated`: with `search` and
  with two pages `about`, paths `pages.1.slug` and `pages.2.slug`.
- `test_the_home_page_is_first_and_alone`: a document without a home page,
  or with two, is refused at `pages`.
- `test_limits_on_pages_and_blocks`: 21 pages is refused at `pages`;
  31 blocks at `pages.0.blocks`.
- `test_an_image_must_belong_to_the_site`: `img:` with another site's
  image is refused at `pages.0.blocks.0.fields.image`; this site's own is
  accepted.
- `test_a_link_must_be_safe`: `{kind:url,target:"javascript:x"}` and a
  `page` target that is not a page id are refused.
- `test_style_values_are_checked`: an unknown theme, font pair or button
  shape, or a colour `red`, is refused at `theme` or `style.*`.

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test tests/Unit/Websites/RichTextTest.php tests/Feature/Websites/SiteContentValidationTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement the migration and every class listed under
  Interfaces.**

- [ ] **Step 4: Run to see them pass.** The same command; expected: PASS.

- [ ] **Step 5: Commit** — "Website content: pages and blocks as a
  validated document".

### Task 5.2: Pages from the document, themes and style

**Files:**
- Create:
  - `Content/BlockRenderer.php`
  - `resources/views/sites/default/page.blade.php`
  - the twelve views `resources/views/sites/blocks/*.blade.php`
  - `public/sites/themes/{coastal,classic,bold}.css`
- Modify:
  - `SitePageController` — `home` and the new
    `page(string $site, string $page)`
  - `SiteAssetController` — the new
    `image(string $site, string $image)`
  - `routes/sites.php`
  - `layout.blade.php`
  - `public/sites/default/site.css`
- Test: `tests/Feature/Websites/SiteContentPagesTest.php`

**Interfaces:**
- Consumes: `SiteContent`, `SiteStyle`, `BlockRegistry` and `WebsiteImage`
  from Task 5.1, plus `WebsiteCatalogue::listings()`, `featured()`,
  `reviews()`, `photos()`, `photoUrl()` and `slugFor()`.
- Produces:
  - **`BlockRenderer::render(Website $site, array $block, array $content, bool $preview = false): string`**
    - Wraps each block in `<section class="block block--{type} bg--{background}" data-block-id="{id}">`.
      `data-block-id` is only there in preview.
    - Catches any `Throwable` from the view, logs
      `website_id, block_id, type, exception_type` and returns `''`.
  - **`BlockRenderer::imageUrl(Website $site, ?string $ref): ?string`**
    resolves `img:` (to `$site->url('images/'.$id)`, or the disk's URL when
    it has one), `photo:` (only when `WebsiteCatalogue::showsPhoto`
    holds) and `site:hero` / `site:logo` (`WebsiteMedia::url`). Anything
    unknown or missing is `null`.
  - **Routes:**
    - `GET /sites/{site}/images/{image}` (ULID, throttle `site-assets`),
      named `image`.
    - `GET /sites/{site}/{page}`, slug pattern, registered last, named
      `page`.
  - **The layout:**
    - Reads `$content` (passed by every page; `SiteContent::published($site)`
      when absent).
    - Links the theme stylesheet `sites/themes/{theme}.css` and
      `SiteStyle::fontsHref`.
    - Prints `SiteStyle::cssVariables` in `:root`.
    - The menu: Home, every other page with `in_menu`, "All stays", and the
      phone.
    - `body class="theme-{theme} buttons-{shape}"`.

- [ ] **Step 1: Write the failing tests** (`SiteContentPagesTest`; uses
  `BuildsSites`; a live brand site with one listing shown):
  - `test_a_stage_one_site_without_a_document_looks_as_before`: with
    settings `heading` "Sea & sun", `GET /sites/brand` shows "Sea & sun",
    the listing's title and `sites/themes/coastal.css`.
  - `test_the_home_page_is_built_from_the_published_document`: set
    `content` with a hero "Published hello" and `draft_content` with a hero
    "Draft hello". The page shows "Published hello" and never shows
    "Draft hello".
  - `test_a_custom_page_has_its_own_address_and_menu_entry`:
    - A page "About us", slug `about-us`, `in_menu` true, with a text
      block "We love guests".
    - `GET /sites/brand/about-us` shows "We love guests".
    - The home page's menu links `/sites/brand/about-us`.
    - With `in_menu` false the link is gone, and the page still answers.
  - `test_a_page_only_in_the_draft_is_not_found`: `GET /sites/brand/secret`
    answers 404 when `secret` is only in `draft_content`.
  - `test_existing_addresses_win_over_pages`: `GET /sites/brand/search`
    still renders the search page.
  - `test_every_block_renders_in_every_theme` (data provider: 3 themes ×
    12 blocks): each block type with its defaults on the home page answers
    200, shows `block--{type}` and links `sites/themes/{theme}.css`.
  - `test_a_block_pointing_at_missing_things_still_renders_the_page`:
    - A `text_image` with `img:` of a deleted image.
    - A `homes` block with `which` chosen and a listing id not on the site.
    - Expect 200: the text_image heading shows, no `<img` for it, and no
      unknown home.
  - `test_a_block_that_breaks_is_left_out`: a `hero` whose `fields.heading`
    is an array (bypassing validation, written straight to `content`). The
    page answers 200 and the other blocks show.
  - `test_style_reaches_the_page`: style
    `{primary:#123456, accent:#654321, fonts:lora-nunito, buttons:pill}`
    gives the page `--brand:#123456`, `family=Lora` and `buttons-pill`.
  - `test_a_site_image_is_served`: an uploaded `website_images` row on the
    local media disk; `GET /sites/brand/images/{id}` answers 200 with its
    bytes. Another site's image id answers 404.

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test tests/Feature/Websites/SiteContentPagesTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement the renderer, views, routes, layout and themes.**
  - `homes` shows `featured()` for "featured", `listings()` for "all",
    the chosen ids in their order for "chosen"; `count` limits all three;
    cards use `sites.default.partials.card`.
  - `reviews` reads `catalogue->reviews()` across the site's listings,
    filtered by `rating_percent`, best first.
  - `map` uses the approximate centre of the site's listings, as
    `property.blade.php` does, and shows nothing when none has
    coordinates.
  - `contact` prints the site details.
  - `hero` includes `partials.search-form` when `show_search` is on.
  - Coastal's stylesheet keeps today's look. The block styles live in
    `site.css`, keyed by CSS variables, so a theme file only overrides
    variables, headings, radii and spacing.

- [ ] **Step 4: Run the new tests and the existing site tests**

Run: `php artisan test tests/Feature/Websites`
Expected: PASS (all).

- [ ] **Step 5: Commit** — "Sites: pages built from blocks, with three
  themes".

### Task 5.3: The editor API

**Files:**
- Create:
  - `Services/WebsiteEditor.php`
  - `PlatformConsole/WebsiteEditorController.php`
- Modify:
  - `routes/api/platform-console.php`
  - `routes/sites.php` — the preview route
  - `SitePageController` — `preview`
  - `WebsiteResource` — drop `heading`, `tagline` and the colours from
    `SETTINGS`; add `unpublished_changes`
  - `WebsiteController::update` — the same removal from its rules
  - `WebsiteController::store` — a new site's `content` and
    `draft_content` start as `SiteContent::starter`
- Test: `tests/Feature/Websites/WebsiteEditorApiTest.php`

**Interfaces:**
- Consumes: Tasks 5.1 and 5.2.
- Produces:
  - **`WebsiteEditor::state(Website $site): array`** returns:
    - `content` (the draft) and `version`
    - `published_at` and `unpublished_changes` (bool: draft ≠ published)
    - `preview_url`
    - `definitions` (`BlockRegistry::toArray()`)
    - `themes`, `font_pairs` and `buttons` (`[{key,label}]`)
    - `images` (`[{id, ref, url, alt}]`)
    - `photos` (`[{ref, url, listing}]`, at most 8 per listing on the site)
  - **`saveDraft(Website $site, array $content, int $version): array`**
    - Locks the row and compares versions; on a mismatch it throws
      `WebsiteDraftConflict` (409, with the spec's message).
    - Validates the content, stores it and increments the version.
    - Returns `['version' => int, 'unpublished_changes' => bool]`.
  - **`publish(Website): void`** re-validates the draft and copies it to
    `content`, setting `content_published_at`.
  - **`discard(Website): void`** copies the published document back to the
    draft, incrementing the version.
  - **`previewUrl(Website $site, ?string $pageId = null): string`** —
    `URL::temporarySignedRoute('sites.preview', now()->addHours(2), …)`.
  - **`storeImage(Website $site, UploadedFile $file, ?string $alt): WebsiteImage`**
    and **`deleteImage(Website $site, WebsiteImage $image): void`**.
    Delete refuses (422) while the draft or the published document still
    uses the image.
  - **Routes** (platform admin), all under `websites/{website}/editor`:
    - `GET` → `state`
    - `PUT /draft {content, version}`
    - `POST /publish`
    - `POST /discard`
    - `POST /images` (multipart: `file`, `alt`)
    - `DELETE /images/{image}`
  - **`GET /sites/{site}/preview/{page?}`** — `signed` middleware, renders
    the draft whatever the site's status, with `$preview = true`.

- [ ] **Step 1: Write the failing tests** (`WebsiteEditorApiTest`):
  - `test_the_editor_opens_on_the_draft_with_everything_it_needs`:
    - A stage 1 site with `heading` "Sea & sun".
    - `GET …/editor` answers 200 with `data.version` 0,
      `data.content.pages.0.blocks.0.fields.heading` "Sea & sun" and
      `data.definitions` holding 12 entries.
    - `data.preview_url` contains `signature=`.
  - `test_a_draft_saves_and_counts_its_version`: `PUT …/editor/draft` with
    version 0 answers 200 with `data.version` 1 and
    `data.unpublished_changes` true. The public home page still shows the
    old heading.
  - `test_a_stale_draft_is_refused_and_nothing_is_lost`:
    - Two saves with version 0. The second answers 409 with the spec's
      message.
    - The stored draft is the first save's.
  - `test_an_invalid_draft_is_refused_by_its_path`: 422 with
    `errors["pages.0.blocks.0.fields.heading"]`; the version is unchanged.
  - `test_publishing_puts_the_draft_live`: save a draft "Draft hello",
    `POST …/publish`; the public page shows "Draft hello" and
    `unpublished_changes` is false.
  - `test_discarding_returns_the_draft_to_what_is_live`.
  - `test_the_preview_shows_the_draft_only_when_signed`:
    - `GET preview_url` shows "Draft hello", with `X-Robots-Tag` noindex
      and `Cache-Control` no-store, even while the site is a draft.
    - The same path without its signature answers 403.
  - `test_images_upload_and_are_offered_to_the_editor`:
    - `Storage::fake('media')`.
    - `POST …/editor/images` with a 10×10 JPEG answers 201 with `data.ref`
      starting `img:`.
    - A PDF answers 422.
    - `GET …/editor` lists the image.
  - `test_an_image_in_use_is_not_deleted`: 422 while a block uses it;
    204 once the draft no longer does.
  - `test_a_new_site_starts_with_a_laid_out_home_page`: `POST
    platform/websites`, then `GET …/editor`; the home page's block types
    are `hero, homes, text, contact`.
  - `test_only_the_platform_reaches_the_editor`: an organization admin
    gets 404 on every route above.

- [ ] **Step 2: Run to see them fail.**
  `php artisan test tests/Feature/Websites/WebsiteEditorApiTest.php` →
  FAIL.

- [ ] **Step 3: Implement the service, controller, routes and preview.**

- [ ] **Step 4: Run** `php artisan test tests/Feature/Websites` → PASS.

- [ ] **Step 5: Commit** — "Website editor API: drafts, publish, discard,
  preview, photos".

### Task 5.4: The editor screen

**Files:**
- Create: the `frontend/src/pages/site-editor/*` files listed above, with
  tests `document.test.ts` and `SiteEditorPage.test.tsx`.
- Modify:
  - `frontend/package.json` — the three dnd-kit packages at their exact
    versions
  - `frontend/src/api/types.ts`
  - `frontend/src/App.tsx` — `/websites/:websiteId/edit`, full screen,
    platform admins only
  - `frontend/src/pages/WebsitesPage.tsx` and its test

**Interfaces:**
- Consumes: Task 5.3's API.
- Produces:
  - **Types:** `SiteContent`, `SitePage`, `SiteBlock`,
    `BlockDefinition`, `BlockFieldDefinition`, `EditorState`.
  - **`document.ts`**:
    - Pure functions: `insertBlock(doc, pageId, type, index, defs)`,
      `moveBlock(doc, pageId, from, to)`,
      `duplicateBlock(doc, pageId, blockId)`,
      `removeBlock(doc, pageId, blockId)`,
      `updateBlock(doc, pageId, blockId, patch)`,
      `addPage(doc, title)` (slug from the title, made unique),
      `movePage(doc, from, to)`, `updatePage(doc, pageId, patch)`,
      `removePage(doc, pageId)` (never Home).
    - New ids: `pg_`/`bl_` plus 12 alphanumerics.
    - `createHistory(doc)`, `push(history, doc)` (keeps 50),
      `undo(history)`, `redo(history)`.
  - **`useAutosave(save: (doc) => Promise<number>, delay = 800)`** returns
    `{status: 'saved'|'saving'|'error'|'conflict', schedule(doc)}`.
  - **`SiteEditorPage`**, at `/websites/:websiteId/edit`:
    - Loads `platform/websites/{id}/editor`.
    - The left panel holds `PagesPanel` and `BlocksPanel`, both sortable
      with dnd-kit and keyboard-accessible, plus `ThemePanel`,
      `HomesPanel` (the stage 1 picker, moved) and `SiteDetailsPanel` (the
      stage 1 details, saved at once with PATCH).
    - The centre is `PreviewPane`, an iframe of `preview_url`, reloaded
      after each successful save at the selected page.
    - The right panel is `BlockSettings`, built from the selected block's
      definition: text input with a counter, a textarea for `rich_text`
      (formatting is typed on the page in PR 6), `ImagePicker`, a
      segmented control for `choice`, a switch for `toggle`, an item list
      for `items`, a link editor for `link`, and a checklist of the site's
      homes for `listings`.
    - The top bar: back, status ("Saving…" / "All changes saved" /
      "Not saved — retrying" / the conflict message with a Reload button),
      undo and redo, **Discard changes** (asks first), **Publish**.
  - **`WebsitesPage`**:
    - Each row gets an **Edit site** link to the editor.
    - The settings card keeps name, address, booking rules and the site's
      account.
    - **Publish/Unpublish** becomes **Go live** / **Take offline**.
    - The look, images and homes sections leave this screen.

- [ ] **Step 1: Write the failing tests**

`document.test.ts`:
- `insertBlock` places a hero, with the definition's defaults, at index 1.
- `moveBlock` from 0 to 2 keeps every block and orders them.
- `duplicateBlock` copies the fields under a new id, placed after the
  original.
- `removePage` refuses Home.
- `addPage('About us')` twice gives slugs `about-us` and `about-us-2`.
- `undo` after two pushes returns the first document; `redo` returns the
  second.
- The history keeps 50 steps.

`SiteEditorPage.test.tsx` (stubApi):
- "shows the pages and blocks of the draft": "Home" and "Big photo + search"
  are in the left panel, and the iframe's src is the `preview_url`.
- "adds a block from the library and saves the draft":
  - "+ Add block" → "Text" → a `PUT …/editor/draft` with version 0 and
    the new block last.
  - The status reads "All changes saved".
- "edits a field in the settings panel": selecting the hero and typing a
  heading saves it.
- "says when someone else changed the site": the PUT answers 409 and the
  message and a Reload button show.
- "publishes the draft": `POST …/editor/publish` is sent.
- "asks before discarding": the POST is sent only after "Yes, discard".
- "reorders blocks from the keyboard": focus the "Big photo + search"
  handle, Space, ArrowDown, Space → the saved draft has it second.

`WebsitesPage.test.tsx`:
- "Edit site" links to `/websites/web_1/edit`.
- The site's Publish button now reads "Go live".
- The old properties-panel tests move to `SiteEditorPage`'s
  `HomesPanel` tests, unchanged in substance.

- [ ] **Step 2: Run to see them fail.** `npx vitest run src/pages/site-editor src/pages/WebsitesPage.test.tsx`
  → FAIL.

- [ ] **Step 3: Implement** — `npm install` the three dnd-kit packages at
  their exact versions, then write the files.

- [ ] **Step 4: Run** `npm run lint && npm run typecheck && npm test -- --run && npm run build`
  → all PASS.

- [ ] **Step 5: Commit** — "Website editor: pages, blocks, theme and
  preview".

### Task 5.5: Ship PR 5

- [ ] **Step 1:** In a local server with Playwright and Chromium, open the
  editor:
  - add a block, reorder it, change a heading;
  - check that the preview updates, Publish shows the change on the public
    page, and Discard undoes it;
  - take screenshots of the three themes and look at them.
- [ ] **Step 2:** The full backend suite, `pint --test`, and the frontend
  checks.
- [ ] **Step 3:** A fresh reviewer on the PR 5 range. Fix Critical and
  Important findings with RED→GREEN tests.
- [ ] **Step 4:** Open the PR into `claude/wizardly-clarke-ixyxs2`, and
  merge when the suite and CI are green.

## PR 6 — Editing on the page

The preview stops being a picture of the draft and becomes the place to edit
it. A script loaded into the preview only, `overlay.js`, outlines blocks,
lets plain text be typed onto the page, and drags blocks; the editor keeps
the document and answers with blocks re-rendered in place. Nothing a guest
receives changes.

### File structure

- `public/sites/editor/overlay.js` — the script in the preview: no build
  step, no dependencies, one IIFE.
- `public/sites/editor/overlay.css` — outlines, toolbar, "+" buttons, drop
  line, placeholders for empty fields.
- `resources/views/sites/blocks/*.blade.php` and the `heading`/`button`
  partials — `data-field` on plain-text elements, in preview only.
- `resources/views/sites/default/layout.blade.php` — loads the overlay in
  preview only.
- `app/Http/Controllers/Api/V1/PlatformConsole/WebsiteEditorController.php`
  and `app/Domain/Websites/Services/WebsiteEditor.php` — `render`.
- `frontend/src/pages/site-editor/previewSync.ts` — what the preview needs
  after a change (pure).
- `frontend/src/pages/site-editor/usePreviewBridge.ts` — the editor's side of
  the messages.
- `frontend/src/pages/site-editor/PreviewPane.tsx`, `SiteEditor.tsx`,
  `ImagePicker.tsx`, `useAutosave.ts` — wiring and polish.

### Task 6.1: The overlay

**Files:**
- Create: `public/sites/editor/overlay.js`, `public/sites/editor/overlay.css`,
  `frontend/src/pages/site-editor/overlay.test.ts`
- Modify: the block views and partials, `layout.blade.php`,
  `tests/Feature/Websites/SiteContentPagesTest.php`

**Interfaces:**
- Consumes: Task 5.2's `data-block-id` on each block's `<section>` in
  preview, and `$preview` in every block view.
- Produces:
  - **Markup, preview only:** `data-field="<path>"` on the element holding a
    plain-text field: `heading` (hero h1, text, text + photo), `tagline`,
    `title` (every titled block), `text` (call to action),
    `button.label` (call to action, text + photo). Multi-line fields carry
    `data-multiline`. An empty field is still rendered in preview, empty,
    with `data-placeholder="<the field's label>"`.
  - **The layout** loads `overlay.css` and `overlay.js` (with `defer`) only
    when previewing.
  - **Messages**, all `{source, type, ...}` sent with `postMessage` to the
    page's own origin, and taken only from `window.parent` with that origin:
    - overlay → editor (`source: 'habitat-overlay'`):
      `ready`, `select {block}`, `text {block, field, value}`,
      `move {block, to}`, `duplicate {block}`, `remove {block}`,
      `insert-at {index}`, `undo`, `redo`.
    - editor → overlay (`source: 'habitat-editor'`):
      `replace {block, html}` (put this block's HTML in, adding it if it is
      not on the page), `order {blocks}` (these blocks in this order; any
      other leaves), `select {block|null}`.
  - **Behaviour:**
    - Hover outlines a block and shows a toolbar: drag handle, Duplicate,
      Remove.
    - A click on a block selects it; links and forms do nothing while
      editing.
    - `[data-field]` is editable as plain text (pasting strips formatting);
      Enter ends a one-line field, and adds a line in a multi-line one.
      Each input sends `text` with the element's text.
    - A "+" between blocks, and after the last, sends `insert-at`.
    - Dragging the handle shows a drop line and sends `move` on release;
      Escape cancels.
    - Ctrl+Z / Ctrl+Shift+Z outside a field send `undo` / `redo`.

- [ ] **Step 1: Write the failing tests**

`overlay.test.ts` (jsdom; reads the script from `public/` and runs it on a
page of two blocks):
- "says it is ready".
- "selects a block that is clicked, and keeps links from leaving the page".
- "sends what is typed into a field, as plain text".
- "ends a one-line field on Enter, and adds a line in a multi-line one".
- "offers a block between every two, and after the last".
- "duplicates and removes from the block's toolbar".
- "moves a block with the handle, and Escape cancels".
- "puts a block's new HTML in place, and orders blocks as it is told".
- "ignores messages from anywhere but its editor".

`SiteContentPagesTest`:
- `test_the_preview_marks_what_can_be_typed_on_the_page`: preview has
  `data-field="heading"`, `data-field="title"`, `overlay.js`; an empty
  tagline is there with `data-placeholder`.
- `test_a_guest_page_carries_nothing_of_the_editor`: no `data-field`,
  `data-placeholder` or `overlay.js`.

- [ ] **Step 2: Run to see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php artisan test tests/Feature/Websites` and
  `npx vitest run src/pages/site-editor/overlay.test.ts` → PASS.
- [ ] **Step 5: Commit** — "Website editor: edit on the page".

### Task 6.2: The editor answers the page

**Files:**
- Create: `previewSync.ts` with `previewSync.test.ts`,
  `usePreviewBridge.ts`
- Modify: `WebsiteEditor.php`, `WebsiteEditorController.php`,
  `routes/api/platform-console.php`, `WebsiteEditorApiTest.php`,
  `PreviewPane.tsx`, `SiteEditor.tsx`, `SiteEditor.test.tsx`

**Interfaces:**
- Consumes: Task 6.1's messages.
- Produces:
  - **`POST …/editor/render {content, block}` → `{data: {html}}`:** the
    document as it is on screen is checked as a save would check it (a
    refusal is a 422 by path, and nothing is saved), and the block is
    rendered in preview, with its wrapper.
  - **`previewSync(before, after, pageId, typed?)`** returns
    `{reload: boolean, render: string[], order: string[] | null}`:
    - `reload` when the theme, the style or anything of the pages but their
      blocks changed (titles, slugs, the menu, their order);
    - `render`: ids of the page's blocks that are new or changed, except a
      block whose only change is the field just typed on the page (`typed`
      = `{block, field}`);
    - `order`: the page's block ids, when blocks were added, removed or
      moved.
  - **`usePreviewBridge(frame, origin, handlers)`** takes messages only from
    that frame at that origin, and returns `send(message)`.
  - **The editor:**
    - Typing on the page updates the field (one undo step per field, as
      in the panel) and does not re-render.
    - Any other change, undo and redo included, is synced by
      `previewSync`: blocks re-rendered and swapped in place, newest answer
      winning, then the order. The page reloads only on `reload`, after the
      save.
    - Selection follows both ways.
    - `insert-at` opens the block library and inserts there; `move`,
      `duplicate`, `remove` (asking first, as in the panel), `undo`, `redo`
      act as their buttons do.

- [ ] **Step 1: Write the failing tests**

`WebsiteEditorApiTest`:
- `test_a_block_renders_for_the_preview_from_the_document_on_screen`.
- `test_a_block_that_would_be_refused_is_not_rendered` (422 by path, the
  draft unchanged).

`previewSync.test.ts`:
- a heading typed on the page → nothing to render, no order, no reload;
- the same heading changed in the panel → render that block;
- a block moved → order only; added → render it, then order; removed →
  order;
- theme, style, page title, menu or page order changed → reload;
- a change on another page → nothing.

`SiteEditor.test.tsx` (a stand-in frame posting messages):
- "types on the page into the draft, without re-rendering";
- "re-renders a block changed in the panel, in place";
- "selects on the page what is selected in the panel, and back";
- "opens the block library where '+' was pressed";
- "reloads the page for a new theme".

- [ ] **Step 2–4** as in Task 6.1, with `npm run lint && npm run typecheck`.
- [ ] **Step 5: Commit** — "Website editor: the page and the panels in step".

### Task 6.3: Polish

**Files:** `SiteEditor.tsx`, `PreviewPane.tsx`, `ImagePicker.tsx`,
`useAutosave.ts`, their tests, `site-editor.css`.

**Interfaces:**
- **Phone preview:** Desktop / Phone in the top bar; Phone shows the frame
  390 px wide, centred.
- **Drop to upload:** a JPEG, PNG or WebP dropped on an image field uploads
  and is chosen; any other file says what is accepted.
- **Leaving with changes not saved** (`pending`, `saving` or `error`) asks
  first (`beforeunload`).
- **Retries back off:** 1, 2, 4, 8 s, then every 15 s.

- [ ] **Step 1: Write the failing tests:**
  - "shows the page at phone width";
  - "uploads a photo dropped on the field";
  - "refuses a file that is not a photo";
  - "asks before leaving with changes not saved";
  - `useAutosave`: "tries again after 1, 2, 4 and 8 seconds, then every 15"
    (fake timers).
- [ ] **Step 2–4** as above.
- [ ] **Step 5: Commit** — "Website editor: phone preview, drop to upload, safer
  leaving".

### Task 6.4: Ship PR 6

As Task 5.5: a browser run (type a heading on the page, drag a block in the
preview, add one with "+", change a setting and watch only that block
change, phone preview, drop a photo), the full suite and frontend checks, a
fresh reviewer, the fix pass, the PR, and the merge when green.

## PR 7 — Clients edit their own site

A client signs in to the client portal, opens **Website**, and edits and
publishes their own client site in the same editor. The address, the booking
rules, whether the site is online, and which account it belongs to stay with
the platform. The portal stays read-only for everything else.

### Task 7.1: The portal API

**Files:**
- Create: `app/Http/Controllers/Api/V1/People/OwnerWebsiteController.php`,
  `tests/Feature/Websites/ClientWebsiteEditorTest.php`
- Modify: `routes/api/people.php`, `app/Http/Middleware/EnsureClientReadOnly.php`,
  `app/Http/Controllers/Api/V1/PlatformConsole/WebsiteController.php` (the
  details, media and homes logic it shares, moved where both can use it)

**Interfaces:**
- Consumes: `WebsiteEditor` (state, saveDraft, renderBlock, publish, discard,
  previewUrl, storeImage, deleteImage) and the stage 1 site-details, logo and
  homes logic.
- Produces, under `/api/v1/portal/owner/websites`, route names
  `portal.owner.websites.*`:
  - `GET /` — the account's client sites: id, name, status, public_url,
    unpublished_changes.
  - `GET /{website}` and `PATCH /{website}` — the site details a client may
    change: name, contact email and phone, address line, Instagram and
    Facebook links (https only). Nothing else is taken.
  - `POST /{website}/media` — the logo: JPEG, PNG or WebP (no SVG from a
    client).
  - `GET /{website}/listing-options` — the account's own homes, each with
    what it still needs; `PUT /{website}/listings` — the homes shown, only
    from those ready.
  - `…/{website}/editor/…` — the same routes as the platform's editor.
  - A site is found only among `kind = client` sites of the signed-in
    account; anything else answers 404.
- `EnsureClientReadOnly` lets a client's writes through only to routes named
  `portal.owner.websites.*`.

- [ ] **Step 1: Write the failing tests** (`ClientWebsiteEditorTest`):
  - `test_a_client_lists_their_own_sites`;
  - `test_a_client_edits_publishes_and_discards_their_site`;
  - `test_another_accounts_site_and_the_brand_site_answer_not_found`;
  - `test_a_client_changes_the_details_but_not_the_address_rules_or_status`;
  - `test_a_client_logo_cannot_be_svg_and_social_links_must_be_https`;
  - `test_a_client_chooses_only_their_own_ready_homes`;
  - `test_every_other_portal_write_is_still_refused`.
- [ ] **Step 2–4** as before, with `php artisan test tests/Feature/Websites`.
- [ ] **Step 5: Commit** — "Websites: clients edit their own site".

### Task 7.2: The portal screen

**Files:**
- Create: `frontend/src/pages/owner/OwnerWebsitePage.tsx` and its test
- Modify: `frontend/src/components/OwnerLayout.tsx`, `frontend/src/App.tsx`,
  `SiteEditor.tsx`, `HomesPanel.tsx`, `SiteDetailsPanel.tsx`, their tests

**Interfaces:**
- **Website** in the client portal's menu, at `/website`: the account's sites,
  each with its address, whether it is online, unpublished changes, and
  **Edit site**.
- `/website/:websiteId/edit` opens `SiteEditor` full screen with
  `apiBase = portal/owner/websites/{id}/editor`, `sitePath =
  portal/owner/websites/{id}`, `backTo = /website`.
- `SiteEditor` takes `who: 'platform' | 'client'` in place of `platform`. A
  client gets the Homes and Details tabs too; `HomesPanel` and
  `SiteDetailsPanel` take their paths from `sitePath`, and a client's Homes
  panel says what a home still needs, with no Publish button.
- In the platform's client view, the page lists the sites and links to the
  platform's editor instead of editing them.

- [ ] **Step 1: Write the failing tests:**
  - `OwnerWebsitePage.test.tsx`: "lists the account's sites with Edit site";
    "in the client view, sends the platform owner to the platform's editor".
  - `SiteEditor.test.tsx`: "a client edits through the portal's addresses";
    `HomesPanel.test.tsx`: "a client sees what a home needs, and no Publish".
- [ ] **Step 2–4** as before, with the frontend checks.
- [ ] **Step 5: Commit** — "Client portal: Website".

### Task 7.3: Ship PR 7

As Task 5.5: a browser run signed in as a client (open Website, edit, type on
the page, publish, see the public page), the full suite and frontend checks, a
fresh reviewer, the fix pass, the PR, and the merge when green.
