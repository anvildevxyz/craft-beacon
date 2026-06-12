# Beacon Plugin - LLMs.txt Sections Editor Profile
Generated: 2026-06-12 | Focus: Sections Editor & Variable Insertion Helper

## Feature Overview
The llms.txt crawler settings now includes:
1. **Two-pane Sections Editor** - Select which sections to include in llms.txt
2. **Variable Insertion Helper** - Quick buttons to insert section placeholders into fullBody field

## CP Routes
- `beacon/crawlers/llms-txt` — Craft CP controller: `LlmsSettingsController::actionIndex`
- Settings saved via: `beacon/llms-settings/save` — `LlmsSettingsController::actionSave`

## Controllers & Actions
- **LlmsSettingsController**:
  - `actionIndex()` — GET, renders form, passes `allSections` to template
  - `actionSave()` — POST, persists `LlmsSettings`, invalidates cache

## Models
- **LlmsSettings** — stores: `enabled`, `siteNameOverride`, `summary`, `sections[]`, `policyUrl`, `licenseUrl`, `contactEmail`, `preferredAttribution`, `fullBody`

## Form Fields (llms-txt.twig)
- **Sections to include** — custom `sections-editor` field with:
  - Available sections list (left) + selected sections chips (right)
  - Search filter on left panel
  - Drag-to-reorder on right panel
  - Hidden inputs for form submission: `sections[]`
- **Full body** — textarea with variable insertion helper bar above
  - Helper shows buttons for all sections
  - Clicking button inserts `[sectionHandle]` at cursor position

## Assets & Localization
- Asset bundle: `SectionsEditorAsset` — loads `sections-editor.js`
- i18n keys: `beacon.{insert.section.variable, click.to.insert.section, available.sections, selected.sections, search.sections, add.section, remove.section, etc.}`

## Test Data
- 9 sections available: Error 404, Fragments, MD Test, News, News Categories, Notice Bar, Pages, User Guide, Products (Commerce)
- Current saved state (from manual testing): all 9 sections selected in order [error404, fragments, mdtest, news, newsCategories, noticeBar, pages, userGuide, __products__]
