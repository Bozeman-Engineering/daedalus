# Daedalus Layout

Daedalus Layout is a drop in replacement for Layout Builder.

### Installation
Install Daedalus Layout as normal. Visit https://www.drupal.org/node/1897420 for
more information.

### Daedalus Layout UI
- Goto /admin/structure/types/manage/page/display
- Check "Use Layout builder"
- Check "Allow each content item to have its layout customized"
- Click Save
- Configure a default layout section
- (Optional) Mark the block types editors reach for most as favorites: the
  "Favorite" box in each block type's Page builder tab (see "The Add
  panel")
- (Optional) Manage the default layout and remove all sections

### Nested Layouts
Sections nest natively: place a section inside another section, at any
depth. There is no special nestable kind and nothing to enable; a nested
section is an ordinary section that happens to live inside another one.
With the Daedalus's container layout, columns are styling (the row
arrangement variant on the container plus per-child flex-grow where a
ratio deviates from equal), never a layout choice.

### Templates
A bundle's default layout (core's "Manage layout" on the manage-display
page, which needs `field_ui`) is the TEMPLATE: the page builder's own canvas
and Edit Mode run on that route, with the same top bar, Add panel and
inspector a page has, against a display host (daedalus_blueprint's
`HostInterface`: the entity view display owns the document, its field
blocks show sample content and are inert). Three rules follow from the
host:

- A page of an overridable bundle is SEEDED from its template with fresh
  identity the first time it is read or edited, and its first save forks
  it, unless it was started from a page blueprint or blank at creation
  (see "Starting a page"). Pages seeded from one template share no
  element uuid, and a styled template's style entries, its page-level
  ones included, are re-keyed into the page's own map. The seed fires
  only on a truly empty layout field: a page holding core's blank section
  (what removing its last section leaves, or a blank start writes) is
  started and empty, and stays so.
- A page of a non-overridable bundle, or one without an override yet,
  renders the template's document as a FOREIGN document: the spine's
  capability resolver refuses every gesture on the template's containers
  and blocks (`FOREIGN_DOCUMENT`), so they are shown in the page elements
  panel and on the canvas but cannot be selected, dragged or styled,
  while the page's own fields (a field block presenting one of them
  included) edit in place. One notice at the top of the page elements
  panel names the template and carries "Edit the template" for an actor
  who may open it.
- A template's inline blocks are VALUE BLOCKS: the block's field values
  ride the component's `block_values` configuration (references by uuid),
  so a template ships in config, recipes and design-system packages with
  no content ids in it. A display still holding `block_revision_id` blocks
  (a config import, or one from before value blocks) is converted on save
  and by the `display_value_blocks` post update.

### The site frame
The outermost document of the stack is the theme's site frame: a
`daedalus_frame` config entity per theme (id the theme's machine name)
whose sections are rendered around every front-end route of that theme
by the `daedalus_page` page display variant (`DaedalusPageVariant`),
selected on core's page display variant event in place of the block
module's variant whenever the active theme has a frame (`FramePageVariant`,
after the block module's subscriber; never on an administration route,
which keeps the admin theme on block layout, and never over a route
another subscriber made chromeless). A theme without a frame keeps block
layout, so the frame is a front-theme capability, not a second path.

The frame renders through the spine's one render primitive as a document
root (`InnerRender::renderFrame()`): its root containers are what the
theme's regions used to be, its blocks are core block plugins placed in
it (the title block, the messages block, local tasks and actions, which
core's Layout Builder filter hides from every other document and the
frame's offer re-admits; branding, menus, breadcrumb), its header and
footer are `site_chrome` blueprints placed linked, and its `main` slot
(`daedalus_slot:main`, from the site's slot roster) is where the route's
output lands, inside the page's `<main>` landmark with the skip link's
target. The route's entity is the frame render's subject when the route
has one (a linked composition in the frame may show the page's own
field); a view page, a form or a 404 has none, and the frame then renders
with no subject: a block that needs one renders nothing. A placed title
block shows the route's title (`FrameTitle` hands it over at the component
build event); when no messages block is placed the status messages are
prepended to the body, and when no main slot is placed the main content
still shows after the frame, so a frame can never lose either. The main
content block is never offered on the frame. The frame's styles deliver
inside its layout root, with their keys stamped for visitors on demand as
a template's are. The theme's page template prints the `content` region
and nothing else (daedalus_theme declares that one region); core's own
variants fill the same region when there is no frame.

### Saved blueprints
A saved blueprint (daedalus_blueprint_library's `blueprint` entity) is a
page of its own with a subject: its canonical route `/blueprint/{id}` is
its canvas, with the same top bar, Add panel and inspector, against the
spine's blueprint host, whose subject is core's sample entity of the
blueprint's subject bundle. Field blocks render against the subject on
the canvas and on the live route alike (`LayoutBuilderEntityViewDisplay`
hands every section the host's subject as `layout_builder.entity`, the
one rule the canvas states), and the Add panel offers the subject's
fields (`PlacementRestrictions` resolves the subject bundle's display).
Four gestures live on a page's canvas, in `js/verbs/blueprint.js`:
"Save as blueprint…" on a container (never a block: capture its
container; the dialog takes label, description, the subject's allow-list
and whether to adopt the variants it wears), "Save page as blueprint…" on
the page root (the same dialog worded for a page, the same op with the
host's own uuid as the element, a page-scope blueprint; a root verb flagged
`document`, so the top bar's Save caret is where it shows, on a page the
actor may configure and never on a template or a blueprint's own canvas),
"Update blueprint 'X'" on a placement, which pushes the placed subtree back
into the blueprint it came from by provenance, and "Open blueprint 'X'", which
navigates to the canvas of the blueprint a join shows (a linked placement, a
slot door) when the actor may update it (`_element.showing.portal.url`,
stamped by daedalus_ui's annotator from the spine's showing). Open, Update
and Save as share the registry group `daedalus_layout.blueprint` (daedalus_ui's
`group` spec key), so on a placed blueprint the selection chip shows them as
ONE split control: "Update blueprint 'X'" is the main half and its caret
("More Update blueprint actions") opens a menu of "Open blueprint 'X'" and
"Save as new blueprint…" (the Save as verb's own name whenever the container
is a placement). A container that is no placement has one applicable member
and keeps its plain "Save as blueprint…" button; the context menu, the tree's
row menu and the hotkeys list the three flat, as before. The Add
panel's blueprint rows (`js/src/`, the bundled panel) are section
drops through the `place_blueprint` op (a copy); while an Insert anchor
is armed, a row's menu offers "Place" and "Place linked", the latter the
same op in linked mode, a block drop landing the blueprint shown by
reference; the panel offers only the
blueprints the canvas host OFFERS (the spine's `CanvasOffer`: block
scope, a root, and the fit of `BlueprintRequirements`: the subject bundle
has the fields the tree spells, the site has its block types, plugins and
layouts, the allow-list admits the bundle), on page load and in every
mutation response's delta alike, and a field block in the placed tree is
spelled by field name, so the host supplies the derivative. The capture verbs and the save dialog need
the create permission of some blueprint type (`create section blueprint`
for the type a capture mints by default; `PlaceBlock::canSaveBlueprints`
is the library's any-type gate); composing on a blueprint's canvas
follows core's grammar on the type's display, `configure editable section
blueprint layout overrides`.

The `layout_builder_plus` element wraps the layout in the canvas root
itself wherever no entity view supplies one (core's manage-display and
manage-layout routes) and fires `hook_daedalus_layout_canvas_root_alter()`
on that root (see `daedalus_layout.api.php`). A section storage with no
host (core's navigation layout) still falls back to core's element.

### The Add panel
The Add panel (the "Blocks" sidebar, `Plugin\Sidebar\PlaceBlock`, plugin
id `place_block`; the Insert verb, the `i` hotkey and the blank-page
button open it) is one list of everything the canvas can place, each
thing with one face, shown as folding sets: "Favorites" first, then each
group that holds anything, in declaration order, then "All", which holds
everything once, nested by kind: "Basics" (the Container and the block
types), "Blueprints", then core's block categories. Every heading is an
outline row in daedalus_ui's outline row grammar (`daedalus_ui.api.php`,
"The outline row grammar", the page elements tree's and code mode's
too): the disclosure triangle in the chevron column, the set's name in
the heading voice and its count as a fact at the row's end, the depth
the grammar's token, so a kind's heading and the tree's rows sit on one
geometry in the rail; the heading is itself the toggle (one button, no
inner disclosure button). A block shows in every set it belongs to, so
a row is keyed by set and id, never by a DOM id. Rows, not cards, and
not outline rows: a panel item (a raised, bordered, grabbable object)
with an icon, a label and a star, indented under its heading by the
same token spent as the card's margin. "Find a block" above
the sets answers from two characters with one flat list, each block once,
naming the kind it lives under. Favorites starts open and every other set
folded; after that the panel opens as the editor left it (localStorage
`daedalusLayoutAddPanel`, explicit postures only, purely visual state
that never reaches the server). Core's block categories are not groups:
they say what kind of thing a block is and stay as the kinds under All.

- **The face.** `AddPanel\AddPanelFaces` (`daedalus_layout.add_panel_faces`)
  answers the server's half for a canvas host, attached as
  `drupalSettings.daedalusLayout.addPanel`: one face per placeable block,
  `{id, kind, label, description, icon, groups, favorite, editable}`, for
  the Container and every block definition `PlacementRestrictions` admits
  on the host's own section storage (a saved blueprint's canvas offers its
  subject bundle's blocks); the site's group vocabulary in declaration
  order; and the editor's own marks. Blueprints are not in the answer:
  they reach the client live through the materials store, whose records
  carry the same keys (`groups`, `favorite`, a resolved `icon`), so one
  renderer draws every row from two feeds and a blueprint saved by a
  person, the AI or another window appears in the open panel the moment
  its delta lands. The server renders a mount and no item markup.
- **The face's icon is the element's icon in every outline.** daedalus_ui
  mints `_element.icon` on every delivered node beside `type` and `name`
  (its annotator's third word, read as data by the page elements tree
  and by code mode in the leader and in the satellite), and asks the
  tagged `daedalus_ui.element_icon_provider` services for the two kinds
  that are this module's: `ElementIconProvider` answers a container
  (`SectionBlock`) with the Container's asset and an inline block with
  its block type's `daedalus_layout.icon` setting resolved through the
  icon value grammar, the default block icon when the type has none or
  is gone, the same two sources `AddPanelFaces` draws from, so a thing's
  face in the panel and its row in an outline wear one icon by
  construction. What the provider reads (the block type, a media icon's
  tags) rides the cacheability of the render that delivers the
  annotation. A plugin block and a field block are daedalus_ui's own and
  get NULL from it.
- **A favorite is a fact on the thing, with each editor's marks over
  it.** Nothing is stored per display. The site's default lives where the
  thing's icon lives: a saved blueprint's `favorite` field (a shipped
  one's `favorite: true` envelope key; the "Favorite" box in the details
  dialog, `favorite` on `update_material`), and a block type's
  `third_party_settings.daedalus_layout.favorite` (the "Favorite" box in
  the "In the Add panel" group of the block type's Page builder tab,
  `Hook\BlockContentTypeFormAlter`). The Container is a favorite by the
  panel's own word and leads Favorites. A plugin that is not a block type
  has no face to store a default on, so it is never a site default. Each
  editor's marks are `favorites` under `daedalus_layout` in daedalus_ui's
  `EditorSettings` (the user's `daedalus_ui_settings` field), a map of id
  to TRUE (added) or FALSE (a default removed), ids being block plugin
  ids and `blueprint:ID`, written through the generic settings route
  `daedalus_ui.settings.save_setting` as a JSON body; the effective
  favorite is the default with the mark over it. The row's star and its
  menu's "Add to my favorites" / "Remove from my favorites" write the
  marks and repaint at once; `S` on a focused row stars it. An editor can
  mark anything under All, the page title or a view included. A blueprint
  you save is your favorite: when a materials delta lands a blueprint
  record this session's store did not hold (the change set is per request,
  so the record is one this editor's own gesture minted, "Save as
  blueprint…" or an AI turn in the editor's request alike), the open panel
  marks it through the same marks path the star uses, one POST for the
  batch, and its row sits under Favorites at once. An update upserts a held
  id and a page-scope save arrives as a remove, so neither marks; a boot of
  the store (the page's envelope, re-delivered on every editable-page
  render, which may carry what other editors saved) repaints and marks
  nothing. The mark is the creator's alone: the record's own `favorite`
  stays false, and the site default is opt-in through "Favorite for
  everyone" or the details dialog's "Favorite" box. The star wears the
  icons' grey in both states, on and off differing by fill alone.
- **"Favorite for everyone"** in the row menu ("Remove favorite for
  everyone" once it is one) writes the face itself, so it shows only
  where there is a face and the actor may edit it (the face's `editable`,
  never learned by trying): on a blueprint's row through the library's
  details route, the one "Edit details…" uses (the blueprint's type's
  edit permission); on a
  block type's row through `daedalus_layout.block_type_favorite` (`POST
  /daedalus-layout/block-type/{block_content_type}/favorite`,
  `{favorite: bool}`, `Controller\BlockTypeFavorite`), gated by the block
  type's update access and the CSRF header; `AddPanel\BlockTypeFace` is
  the one writer of the setting, shared with the block type form. A plain
  plugin's row never shows the item. The outcome is reported as a status
  sentence, since an editor's own mark can hide the row's move.
- **Groups file block types as well as blueprints.** The vocabulary is
  the site's (`site.groups` in the `PROVIDER.page_builder*.yml` family,
  parsed and validated by `SiteGroups` and contributed to the spine's
  dialect catalog, where every surface reads it; see daedalus_blueprint's
  README). A block type carries its group ids beside its icon
  (`third_party_settings.daedalus_layout.groups`, the "Groups" checkboxes
  in the same "In the Add panel" group, shown while the site declares a
  group or the type still carries one). A group id the site does not
  declare is kept by the form and ignored by the panel: the sets are
  built over the vocabulary, never over the ids things carry.
- **The peek.** Resting on a row for a moment (400 ms, hover or focus)
  shows beside the panel what dropping it here would give: a saved
  blueprint as it is, a view with its rows, a field block with this
  page's value, a simple block with the sample content a placement fills
  in. The client posts the row's OWN drop batch, the one the same
  builders compose for a click-to-place at the end of the page, to
  daedalus_blueprint_api's peek door (`POST
  /daedalus-blueprint/{type}/{id}/peek`), a dry run on the staged working
  copy that persists nothing, and shows the answer in one inert frame
  (`srcdoc`, scaled as the chooser's cards are), a bare site document in
  the skin with none of the page around it (daedalus_ui's third
  chromeless kind). Nothing is preloaded: a row asks the door on its
  first rest and its answer is cached per row for the page load, so a
  second rest shows it at once with no request (the dwell still gates
  it, so a sweep flashes nothing). A fired request is never aborted; a
  refusal is never cached. The cache is dropped whenever this session's
  staged copy changes (every mutation, undo, redo and history jump) and
  every blueprint row's entry whenever a blueprint record is delivered;
  nothing survives a reload. The shown frame is gone on leave, blur,
  Escape or a drag start. A row whose drop the client would refuse (a
  block blueprint's canvas with its one root placed) peeks nothing and
  asks nothing: the peek is composed under the same gates as a click.
- **What carries over as behaviour**: the three drag sources
  (`new_block`, `new_section`, `new_blueprint_section`) and the one
  caret; the Insert verb's pending anchor with click-to-place and its
  arrow-key highlight; the root armed on an empty canvas; roving focus
  (Down from the box enters the list, a heading is a stop that Enter or
  Space toggles, Left collapses, Right expands, Enter or Space on a row
  lifts it into the caret walk); the blueprint row's menu ("Edit
  details…", "Delete…", Shift+F10) and its live repaint from the store;
  a band placing at the top level only.
- **The build.** The panel is a bundled Preact surface: `js/src/` (the
  component under `components/`, the pure model under `model/`, which the
  node tests cover) built by `webpack.config.js` into
  `js/dist/add-panel.bundle.js` (`npm run build:prod` from the daedalus
  root builds all four module bundles; the bundle and its sidecars are
  committed). Folding is daedalus_ui's: `js/outline/collapse.js` for the
  model, the expand-all and collapse-all pair seated in the panel
  header's slot, the disclosure grammar and the outline row grammar the
  headings wear from `css/panel-controls.css` (so a change there or
  under `js/outline/` rebuilds this bundle with the other three);
  `css/add-panel.css` keeps only what is the panel's own, each rule with
  its reason (the sticky top-level heading, the item card's indent).
  The client's hooks are stable `data-daedalus-layout-add-panel-*`
  attributes (the mount, the search box, each set heading with its id and
  `aria-expanded`, each row with its face id and the set it sits in, the
  star, the peek layer); tests key on those, never on ids or classes,
  and `EditModeTrait::openAddPanelSet()` and `revealAddPanelRow()` reach
  a row inside a folded set.

### Starting a page
A page's first document has one source, picked once, when the page is
created: its bundle's template (the default), a page blueprint, or blank.
The spine's verb (`daedalus_blueprint.page_start`, see "Starting a page"
in daedalus_blueprint's README) applies the choice; this module is the
editor's door to it, three pieces in one place:

- **The setting.** "Offer starting designs" on a page bundle's edit form,
  in the "When a page is created" group of the Page builder section
  Daedalus UI owns (`Form\BundleEditFormAlter`): stored as the bundle's
  `third_party_settings.daedalus_layout.offer_starts`, a boolean, off by
  default. Its help counts the page blueprints that fit the bundle today
  and links to the blueprint listing; where the bundle's pages share the
  template's layout (overrides off) a page has no document of its own to
  start, so the box is disabled with the reason. The schema names the
  setting per page-capable bundle config type (node types, media types,
  vocabularies, comment types; a block type has no creation group), and
  daedalus_ui's `hasSettingsSchema($type, 'daedalus_layout')` is the probe,
  so the control never writes a key with no schema.
- **The chooser** (`Form\StartChooser`) is the add form itself, not a
  route: one `hook_form_alter` on a content entity form holding a NEW
  entity (an edit form, a translation's add form and a Layout Builder form
  never show it). With the setting on and at least one page blueprint
  offered for the bundle (`PageStart::starts()` asked of the bundle's
  unsaved host, never the form's entity, so no read can seed it), a
  request with no `start` in the URL shows the chooser in place of the
  fields: Blank first, then the offered page blueprints in the verb's one
  order, the site favorites first, then the rest, each run by label,
  natural and case-insensitive (the Add panel's order; the cards never
  sort again). A Template card leads only when the bundle's template
  holds a document to follow (`PageStart::templateOffered()`, the verb's
  judgement read off the display's own host, the seed's input): on a
  blank template the two reserved starts open the same empty page, so
  Blank is the one card for it, while `template` stays valid in the URL,
  the start line and the preview (it is the default a page gets when
  nothing is chosen). The bundle form's help follows the same judgement
  ("a page blueprint, the template, or blank" or "a page blueprint or
  blank"). Each card is one link to the same URL with `?start=<id>`; the
  groups the offered blueprints carry (the site's group vocabulary, read
  from the spine's dialect catalog, `DialectCatalog::groups()`) are
  filter links with `?group=<id>`, shown only when there are any, and the
  reserved cards stay under every filter. No radio, no Continue button,
  no JavaScript. With a valid
  `start` the normal form shows under a "Starting from X. Change" line,
  and an `#entity_builders` callback applies the start to the entity the
  form builds, on the submit pass alone (the builder also runs at
  validation), so the form's own save lands entity and document together,
  the rule `page_create` follows. An unknown or unoffered `start` shows
  the chooser again under a message. The setting off, or no page
  blueprint offered, is the plain form, and the page starts from its
  template as before.
- **The preview** (`Controller\StartPreview`, route
  `daedalus_layout.start_preview`,
  `/daedalus-layout/start-preview/{entity_type}/{bundle}/{start}`) is a
  card's face: a dry run of the start on a duplicate of core's sample
  entity of the bundle, unsaved (no entity, block or media is created;
  the test pins the counts), rendered in the full view mode as the page's
  canonical route would, chromeless through daedalus_ui's preview-mode
  door (`?navigationMode=preview`: the site page with the editor gone and
  the skin on, never the pop-out tool window). Access is the entity
  type's create access for the bundle; a non-page bundle or a start the
  chooser never offers is not found. Each card embeds the route in a
  lazily loaded, inert frame, laid out at the face's width over the scale
  and scaled onto the face by CSS, with the face's own paint showing until
  the document arrives. Nothing is stored, so a preview is never stale.

### Core's routes are closed
A hosted document is written through the op spine alone. Core's Layout
Builder write routes (add, choose, remove and move a block; add, choose
and remove a section; revert an override) carry the
`_daedalus_layout_hostless_storage` requirement
(`HostlessStorageAccessCheck`, set by `LayoutBuilderRouteSubscriber`):
forbidden for a storage whose host Daedalus resolves (an entity's
overrides, a display's defaults), allowed for a storage no host owns
(core's navigation layout), which keeps core's element, routes and
contextual links. The overrides and defaults forms lose Save layout and
Revert to defaults for a hosted storage; Discard stays, since it drops
the staged copy exactly as the top bar's Discard does. The two dialog
routes (update block, configure section) stay open: the Configure verb
opens core's forms as widget hosts on daedalus_edit's form transport.

### What a role may do
Structure follows core's Layout Builder override permissions, honored
by the spine's role policy: `configure any layout`, or `configure
all|editable {bundle} {type} layout overrides`, open arrange, insert and
configure on a page of that bundle; a template follows `configure any
layout` beside the display's update access; an inline block's fields
follow `create and edit custom blocks`. Styling is daedalus_stylish's own
permission. The Add panel mounts only where insert is allowed somewhere
on the host (`PlaceBlock::applies()`), its drag sources and
click-to-place are judged by daedalus_ui's drag controller (arrange of
the moved element at dragstart, insert of the destination container at
every drop, pointer and keyboard alike), and every verb this module
registers declares the capability it needs (Duplicate and Remove
arrange, Configure and the blueprint verbs configure, Insert insert of the
container); the op door enforces the same verdict on the mutation.

#### UI Colors
To set your UI colors to match your theme go to Manage > Configuration >
Content authoring > Daedalus Layout.

# Recommended modules
- [Field Sample Value](https://www.drupal.org/project/field_sample_value)
  - Are the generated sample values a bit too much? Field Sample Value gives
    you control over the generated values.
- [Layout Builder Restrictions](https://www.drupal.org/project/layout_builder_restrictions)
