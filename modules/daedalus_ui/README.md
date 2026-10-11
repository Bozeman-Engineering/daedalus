# Daedalus UI

Daedalus UI provides Mode plugins so that developers can create modes that take
over the Navigation sidebar.

## Edit mode

Daedalus UI provides Edit mode. When a user enters Edit mode the Navigation
sidebar is replaced with the editing chrome: a top bar of panel toggles and
document actions, and sidebar panels. Save is a split control: the button
commits, and the caret beside it opens the DOCUMENT menu, the top bar's face
of the verb registry, listing the root entity's verbs registered with
`document: true` (daedalus_layout's "Save page as blueprint…"); the caret
renders hidden until the root offers one, and the bar names no gesture of
its own. Edit Mode is the foundation of the
Daedalus. It has one pointer and one keyboard grammar over the one selection;
editing is a state of the selection, opened by gesture (double-click a leaf,
Enter, the Edit chip), and other modules such as [Daedalus Layout](https://www.drupal.org/project/daedalus)
and [Daedalus Edit](https://www.drupal.org/project/daedalus) plug into it through
sidebar plugins, verbs, the editing engine and the registration seam. The goal
is remove the need for Edit and Layout tabs to create content. Daedalus enables
in place editing.

### Hotkeys
Panel toggles, verbs and the selection grammar's keys are hotkeys. Hover over a
top bar button to see its hotkey in the tooltip. Hotkeys can be remapped per
user from the Settings sidebar in Edit Mode.

### The route host and the template surface
Edit Mode runs against the HOST of the route (daedalus_blueprint's
`HostInterface`; `DaedalusUi::deriveHostFromRoute()` resolves it from an
entity parameter, an overrides storage or a defaults storage): a canonical
page, a saved blueprint's canonical route (`/blueprint/{id}`, the
blueprint host of daedalus_blueprint_library's `blueprint` entity, whose
subject is core's sample entity of the blueprint's subject bundle), core's
manage-layout route of an entity, and core's manage-display layout route
(`field_ui`), where a Layout Builder enabled display is the TEMPLATE
host. The canvas is one socket (`daedalus_blueprint_api.canvas_builder`):
an entity view where one exists, else the `layout_builder_plus` element
wraps the layout in the main-entity root itself, so every route carries
the same top bar, mirror envelope, Add panel and inspector. A template root
has no fields: its `_element` type is "Template" and the blueprint carries
no `fields` key, which is what the panels gate on.

Editable attribution follows the host, never a preview flag: a field is
stamped editable only when its entity is the host's fields entity or a
block placed in the host's document (`DaedalusUi::ownsFields()`), so
core's sample entity on a template, a formatter-rendered media and a
linked blueprint's blocks stay inert by construction. Field blocks render
against the host's subject on every canvas (`LayoutBuilderPlus::
renderContexts()`: the subject is `layout_builder.entity` and the root
entity, one rule for a page, a template and a saved blueprint).

A join of the render stack (a slot door, a linked placement) carries one
more annotation: `BlueprintElementAnnotator` copies the spine's one
answer for what it shows, `_element.showing` (`VerdictStamper::showing()`:
what, whose document, the portal to the shown blueprint's canvas for an
actor who may update it, with its url resolved here, and whether this
canvas may claim it), read by daedalus_layout's "Open blueprint" verb and
the Page elements tree's shown-document row; a file-shipped, deleted or
forbidden blueprint shows nothing or carries no portal. A detached
placement is a copy, no join, and carries none. The client's materials
store keys blueprint records by the server's kind, `blueprint`, and a
placed root's provenance is `node.blueprint`.

### The one gate
What the actor may do to an element is the spine's answer, delivered
with every read: daedalus_blueprint stamps `_verdict` and
`BlueprintElementAnnotator` copies it onto every owning node as
`_element.can` (content, configure, style, arrange, insert, to booleans)
and `_element.refused`; the root's refusals, explained and carrying the
owner and the portal, ride the mirror envelope and every mutation
response as `verdict`. The client has ONE capability gate,
`capable(key, capability)` (`Drupal.Daedalus.Selection.capable`), read
over the projection index, where field rows and parts inherit their
element's verdict and an address with no verdict is refused. Every
gesture asks it: a verb declares the capability it requires (and of
which key, the element or its parent) and registration throws without
one; the drag controller asks arrange of the moved element at dragstart
and insert of the destination container at every resolved drop, pointer
and keyboard alike; rename, paste styles, the file drops, the Add
panel's click-to-place and the stylish inspector ask theirs. No surface
derives permission from DOM stamps, the owner or a role.

An element the actor can do nothing to is not selectable
(`Selection.selectable(key)`, "the verdict allows anything"): a click
resolves to the outermost selectable key in its chain, keyboard
traversal walks past it, the page elements tree still shows it as an
inert, expandable row, and the store's selection gate drops it whoever
dispatched it. So a page that renders a template it does not own
(overrides off, or no override yet) shows the template's sections and
blocks without offering them, and a field block presenting one of the
page's own fields resolves to that field, which edits in place. One
notice at the top of the page elements panel explains a host-level
refusal (the layout belongs to the template X, a non-default
translation, a required workspace) and carries "Edit the template" only
when the verdict carries a portal; no chip, menu or inspector offers a
portal of its own. Sidebars mount by capability: the inspector where
style is allowed somewhere, the Add panel where insert is, and Edit Mode
itself does not apply to an actor with no capability on anything.

### The selection frame
One frame (`js/edit_mode/components/selection-frame/`) owns everything anchored
to the selection: the strokes, a chip per member of the multi-select set reading
`N Type` (N is the member's set-order number, shown for a real set; Type comes
from the one type resolver, `js/edit_mode/selection/type-for-key.js`: what the
element IS ("Text", "Section"), since the canvas already shows what it says,
while the tree, breadcrumb, and AI chips read the one label resolver,
`selection/label-for-key.js`, what the element SAYS; neither resolver derives
a word: each reads the delivered field, `_element.type` and `_element.name`,
that `BlueprintElementAnnotator` mints once on the server with `t()`, see "The
element model", so every surface in every host words an element the same
way), and on the primary's chip the verb face, which follows the
chrome-density ladder: at full density every verb as icon plus text label, at
minimal the same verbs as icons only, at clean the registry's `tab`-flagged
verbs as icon buttons plus a chevron opening the context menu with the rest.
Verbs that share a registry `group` (the `group` spec key, see "The verb
contract" in `daedalus_ui.api.php`) are one split control on the face, the
same `daedalus-ui-split-button` the top bar's Save is: the group's lightest
applicable member is the main half and its caret ("More @label actions")
opens the shared menu over the rest in weight order, so on a placed
blueprint daedalus_layout's Update, Open and "Save as new blueprint…" are one
pill led by Update, while a group with one applicable member stays the plain
button it would be alone. At clean density the `tab` partition runs first and a
group's untabbed members go behind the chevron as flat items; menus never
group. The stroke is dashed while an edit is open on the primary. Other
modules anchor chrome to the selection by registering verbs
(`Drupal.Daedalus.Verbs.register(spec)`, each declaring the capability its
gesture needs, see "The one gate"), never with a positioning loop of
their own; an edit-scoped verb (`editScoped: true`) applies only while the
store's editingKey is the chip's key, and a verb with a `widget` renders its own
control in the face's widget slot, re-run on density flips so widget entries
follow the same density ladder as plain verbs.

### The page elements panel
The "Page elements" sidebar (`js/edit_mode/components/page-elements/`) is
the page as an outline: the mirror's tree projected into rows, one row per
element the page lists (`projection.js`), under a pinned root row that
names the page and carries expand all, collapse all and the picker. A row
is an ARIA treeitem: it selects, multi-selects, drags, renames, opens the
row menu (Shift+F10) and folds, with roving focus and arrow traversal.

**The row.** Every row wears daedalus_ui's outline row grammar
(`css/panel-controls.css`, `daedalus-ui-outline__row`; the contract is
`daedalus_ui.api.php`, "The outline row grammar"), the one row CSS the
three outlines in the left rail share: this tree, daedalus_stylish's code
mode (in the leader page and in its satellite window) and the Add
panel's headings. A row is four zones in a fixed order: the chevron
column (the disclosure button, or its footprint on a leaf), the element's
icon, its name and the facts at the row's end. A row prints icon then
name and never its type: the icon says what a row is, the name what it
says, and the type survives as the row's tooltip. The name is the one
zone that truncates; the facts never move. Depth is the row's own
padding from the grammar's token, so the hover and selection washes span
the panel, and the sections under the pinned root sit at the panel's
edge with their chevrons. The voice follows the kind: the page and a
container speak up, a block speaks plainly, a field, a region and a
value recede, rich-text rows read as content and anatomy rows (a
declared wrapper, a generated box) are italic. Selection is a wash with
the name and the icon in the selection color and no border; a row
another module marks (`app.tree.setRowMarks`, daedalus_stylish's Outline
Variant) wears the focus treatment.

**The icon** is the element's third minted word, `_element.icon` (see
"The element model"), read as data like the name. Pseudo rows the panel
alone draws (a shell part, a generated box, a rich-text carrier) and
rows the annotator does not name (a field, a region) pick a glyph
client-side from the tag they rendered as or their kind
(`row-glyphs.js`, a small map, not a seam). A rich-text carrier's row
reads its content excerpt as its name and its vocabulary word
("Paragraph", "List item", stamped by the annotator) as its tooltip; a
list reads as its word, since its text is only its items'.

**The facts** are what another module tells about a row that already
exists, through `app.tree.registerRowFacts({id, facts, doorways,
subscribe?})` (`daedalus_ui.api.php`, "The row-facts contract"): dots and
captions drawn in the facts zone, and doorways listed at the head of the
row menu, ahead of the verbs. A fact never adds a row and never touches
reach, so the pointer, the keyboard and the breadcrumb land where they
did; a provider is on while it is registered, with no toggle.
daedalus_stylish's variant provider draws a dot per variant an element
wears and one when it is styled on itself, and leads the menu with "Edit
{variant}", "Outline everything wearing it" and "Show its own styles in
code". The Markup view's tag (`img`, `a`, `div`; monospace, no angle
brackets) is the view's own fact and sits last in the zone.

**Rename** is one affordance over one op. Double-click a row's name, or
run the "Rename" verb (F2, the chip, the menu), and the row holds an
input: a section's and a block's configured label (`update_element
{label}`; the capability is configure), offered on every block, where an
empty commit clears the label and the row falls back to the name the
annotator mints without one; and the root row's title, when the
annotation routes the entity's label field to it (content; an empty or
unchanged commit cancels). The input's label is "Container name", "Block
name" or "Title". A block's input seeds from its configured label, never
from the name the row shows, and the row's name follows the annotator's
one rule unchanged: a merged block keeps reading its content after a
rename, and where the label renders as page content the rename changes
the page.

**Postures.** The root never folds. A unit row (the node's presentation
annotation) and rich text start collapsed: a formatted text block, the
field row carrying an open render's carriers and a carrier with rows of
its own (a list, a list item over its paragraph), because formatted text
is noisy. While collapsed, a rich-text row draws its direct row count as
a fact ("3 rows inside"). Expand all, collapse all and the selection
reveal reach every foldable row alike, and a posture the editor sets
survives re-projection (`tree-outline.js`, `js/outline/collapse.js`).

**Dropping onto rows.** The panel is a second drop surface beside the
canvas, resolved in `js/edit_mode/drag/tree-gaps.js`, and its drop unit
is the gap line between two visible rows, not the row. One line can mean
several drops, one per depth it sits at: after the deepest row above it,
then after each ancestor that row closes, up to the root flow (where a
block wraps in a new section). The cursor's horizontal position picks
the depth, the tree-editor convention: the deepest candidate whose
anchor row's content edge is at or left of the cursor, else the
shallowest, and the caret indents to the chosen depth so the line itself
says where the drop lands. A block row splits at its midpoint, its upper
half the line above it and its lower half the line below. The page row,
a container row, a region row and the Markup view's shell claiming the
root's sections are container-like: an 8px band at each edge is the line
above and the line below, and the middle appends (the server's
`inside`), so a container row's lower edge is the container's START,
before its first child or `inside` when it is empty. A collapsed
container shows no first child, so its line below means after it
(expand to reach its start; the middle still appends). Region rows are
drop surfaces and nothing more: a multi-region layout's region row
carries its owner and region name (`data-tree-owner`,
`data-tree-region`, still no key, no selection, no styling), its middle
appends to the region and its lower edge is the region's start. Inert
rows (fields, parts, values, other shells) resolve to nothing. Every
drop draws one panel face from its location (`treeCaretForLocation`):
the line brackets the anchor row's subtree, starts at the anchor row's
content edge and sits one outline step deeper for `inside`, and the chip
anchors to it when no canvas line exists. Tree targets carry no
geometric line, so the canvas drag's dead zone (which keeps a drop while
the cursor is near its caret) never measures them; the panel leaves it
by construction, not by a flag.

**The selection reveal.** A collapsed row cannot show a selected
descendant, so the selection opens the path to the selected row and
leaves that row's own posture alone (a unit row stays a unit when
selected). The row it reveals is the seated part's row when the panel
draws it, else the selected element's (`tree-outline.js` `revealId`):
a paragraph Cmd-clicked on the canvas inside a collapsed formatted text
block, a part row clicked in the tree or reached by keyboard traversal,
is the one gesture that opens the block's closed default, down to that
leaf and no further (a seated list item keeps its own rows folded), and
a seat under a block already selected still fires, since the path now
ends on the part row. A target the panel draws no row for (an ancestor
rule, a part the current view hides) reveals the element's row instead.
The reveal is keyed to the change of the path, so a row the editor
re-collapses while it still holds the selection stays collapsed. The
revealed row then scrolls into the panel's scrollport, a part row by
the same rule a selected element row runs, so a seated leaf is shown,
not merely opened.

**Views, and the selection following the rows.** The Show kebab in the
panel header lists the views other modules register
(`app.tree.registerViewProvider`); daedalus_ui's own is "Markup", which
draws the declared anatomy as shell rows under each element and the
generated boxes the cascade materializes. daedalus_stylish mirrors its
styling mode onto the same toggle (basic mode is Markup off; advanced
and code are Markup on), and both writers land in one view map. When a
view turns off, a selection standing on a row it alone drew (a shell
part, a generated box) moves up to the nearest row still drawn, its
owner, and a selected key the index places no row for falls to its
subject (`reseat.js`, committed through the one address road, so the
breadcrumb, the tree's tab stop, the inspector's session and the canvas
paint all see the move). Only the flip re-seats: a seat made while the
view is already off stands as a doorway seat with its target crumb. A
target no view draws (a tag tier, a code mode chain) is never moved, and
the write address (`Selection.getAddress()`, a rule on the selection or
an ancestor the styling writes rebase to) is no row: the re-seat never
reads it, and the breadcrumb never words it.

### Chromeless documents
Three kinds of document render without the page chrome (core navigation's
rail and top bar, the toolbar, this module's mode bars, sidebars and fabs,
and everything those attach), and `Chromeless` is the one predicate that
names them; `DaedalusUiHooks::preprocessHtml` drops `page_top` and
`page_bottom` for every one of them, so no route carries a chrome story of
its own. Each keeps something different of the site page:

- A **pop-out** (route option `_daedalus_ui.popout: true`): a tool window
  the editing page opens beside itself (the responsive preview,
  daedalus_stylish's inspector satellite). It takes the pop-out display
  variant `daedalus_ui_popout` (messages and main content, no block
  placement, no page title), wears the pop-out document chrome (the
  `daedalus-ui-popout` html class and the `daedalus_ui/popout` stylesheet,
  which neutralizes the theme around the mounted tool) and the keyboard half
  that senses every Edit Mode shortcut for the editing page, receives the
  edit session's identity as `drupalSettings.daedalusUi.popout.scope` from
  its `?scope` query, and is outside the skin's reach (daedalus_stylish's
  gate reads `Chromeless::isPopout()`).
- A **bare site document** (route option `_daedalus_ui.bare: true`): one
  piece of site content rendered alone in the skin (daedalus_blueprint_api's
  peek door, which answers one element of a dry run for a frame beside the
  Add panel). It takes core's `simple_page` variant (no block placement, so
  none of the theme's regions), keeps the skin (the token scope wraps its
  content and the host's own style tag applies inside it), wears no pop-out
  class, stylesheet or keyboard, carries no session identity, and never
  builds Edit Mode: `ShouldNotEditMode` vetoes the mode on it whatever mode
  the request names, so no mode-bound bar, sidebar or setting is built.
- **Preview mode** (`?navigationMode=preview`): the staged page as a visitor
  sees it, a site page with the editor gone. It keeps the theme's regions
  and the skin, and `ShouldNotEditMode` vetoes the mode on it as on a bare
  document.

`PopoutPageVariant` selects the variant for the two route options; a route
that wants a chromeless document declares one of them or is reached in
preview mode, never a drop of its own.

## The bundle's Page builder section

Whether the page builder is on for a bundle is one boolean on the bundle
config entity, `third_party_settings.daedalus_ui.enabled`, and the one
gate every reader asks: `ShouldNotEditMode` vetoes Edit Mode on a bundle
where it is off, and daedalus_stylish opens or keeps a style store by it.
There is no per-mode status map and no mode table: exactly one per-bundle
mode exists (Edit), it has no configuration, and the former Enable,
Disable and Configure operations, their routes and their permission are
gone. A site that carried the old map reinstalls; there is no update
path.

The setting lives in one "Page builder" vertical tab on every bundle edit
form whose bundle config type carries the settings
(`Form\BundleEditFormAlter`, this module's). The tab leads with the
switch ("Build X content in the page builder", saved with the form), and
under it sit groups named by OUTCOME, never by module, hidden while the
switch is off: on a page bundle, the layout (a fact with a link to the
display, not a control: pages own their layout, share the template's, or
have none to build), when a page is created (`initial_mode` as one
checkbox, "Open it in the page builder", stored as a mode id or `none`
and read by `InitialMode`, which queues the Edit Mode cookie for the page
an add form lands on; daedalus_layout's "Offer starting designs" sits
beside it) and styling (daedalus_stylish's box, with its warning and
count when cleared); on a block type, "In the Add panel" (daedalus_layout's
icon, its "Favorite" box and its "Groups" checkboxes), the page (the
presentation select) and styling. The tab's summary
line states the setup. Another module adds its control into a group
through `ensureGroup()` and never parks a section of its own; the
contract is in `daedalus_ui.api.php`.

The settings schema cannot be generated: core names a third-party
section per bundle config type, so one type, `daedalus_ui.bundle.options`
(`enabled`, `initial_mode`, `presentation`), is named for the bundle
config types of core's page-capable content entity types (node types,
block types, media types, vocabularies, comment types), and
`hasSettingsSchema()` is the one probe of whether a type carries it. A
contrib bundle config type joins by declaring the same one-line type for
its prefix.

## The element model

Daedalus UI owns the page builder's element model: one answer to "what does
this thing render as, and what of it is addressable?" that every consumer
(the Page elements panel, canvas attribution, styling guides, the AI schema)
projects from instead of keeping a registry of its own. The model keeps two
axes distinct:

- **Existence**: does anything answer to this key, in any view. A
  nonexistent field (`element: false`) has no row, no canvas element, no
  styling surface, and no view ever bypasses that.
- **Presentation**: how an existing element presents, one of `open`
  (structure shown), `unit` (one element, drill in to reach structure), or
  `merged` (a container presents AS its one meaningful child).

### Declarations: the PROVIDER.page_builder.yml file family

Modules and themes declare anatomy and element facts in one layered file
family at their root: `PROVIDER.page_builder.yml` (universal, keyed by
element kind, plus the reserved `site` key for site-scope vocabulary),
`PROVIDER.page_builder.block_type.BUNDLE.yml`, and
`PROVIDER.page_builder.layout.LAYOUT_ID.yml`. Themes layer over modules
(anatomy follows templates), and `hook_page_builder_declarations_alter()`
has final say. Sections daedalus_ui owns: `elements` (the markup-shell
anatomy tree), `fields` (existence + presentation per field),
`presentation`, and `collapsed`. Foreign sections (e.g. Daedalus Stylish's styling
vocabulary) ride the same files untouched. See
`\Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations` for the
format and `daedalus_ui.api.php` for the alter hook.

### The ElementModel service and the `_element` annotation

`daedalus_ui.element_model` resolves each decision with the precedence
site-builder third-party settings → declarations → inference (pluggable
`daedalus_ui.existence_feeder` tagged services; Daedalus Layout feeds
root-field existence on Layout Builder displays). Delivered blueprints carry
the result as one uniform `_element` annotation on every owning node, the
root included, and client code consumes only that annotation, never
hardcoded field lists.

The annotation also carries the element's three words, minted once by
`BlueprintElementAnnotator` and never derived by a client: `_element.type`
(what the element IS, with `t()`: "Section" for a container at the root
layout, "Container" nested, an inline block's block type label, a field
block's field label, the root's bundle label), `_element.name` (what it
SAYS: a section's custom label else its type, a merged block's content
excerpt else its presentation label else its unrendered configured label
else its type, the root's entity label) and `_element.icon` (what it LOOKS
like in an outline: a root-relative URL, or null when nothing answers).
daedalus_ui mints the icon of its own kinds from `assets/outline/` (the
root page, a template root, a field block, a plugin block); a container
and an inline block are daedalus_layout's concepts, so for those the
annotator asks the tagged `daedalus_ui.element_icon_provider` services
(priority order, the first non-null answer wins; daedalus_layout answers
the Container's asset and the block type's icon setting through the icon
value grammar, the Add panel's two sources, so a thing's face in the
panel and its row in an outline wear one icon). What a provider reads
rides the mirror's cacheability, so a changed block type icon
invalidates the boot render. All three ride the nodes of the blueprint's
`layout`, so the Page elements tree, the canvas chips, the breadcrumb,
the stylish inspector in every host (leader page, satellite pop-out, node
specs), the explain tool and the AI dialect all read the same delivered
fields. The contract is `daedalus_ui.api.php`, "Element icon providers".

### The mirror's payload

The client boots its blueprint mirror from `drupalSettings.daedalusUi.blueprint`
(`BlueprintMirror::envelope()`, one JSON string so core's settings merge can
never franken-merge two deliveries): `entityType`, `entityId`, `uuid`,
`label`, `langcode`, `viewMode`, the host-level `verdict` (the root's
explained refusals with the owner and the portal), and `blueprint`, the
spine's read annotated. `blueprint.layout` IS daedalus_blueprint's lean node
tree (kind-declared container, region and block nodes, one `children` axis,
each node's styles on the node), with `_element` stamped on every container
and block node and the spine's `_verdict` copied to `_element.can` /
`_element.refused`; `blueprint.styles` holds only the style keys no node
owns. There is no second tree: the projection index, the markup index, the
uuid lookups, the selection models and daedalus_stylish's `getTree` all walk
`layout`, and every mutation response replaces the mirror wholesale with the
same shape.

### Field presentation UI

Site builders set a field's presentation on the field's configuration form,
in the shared "Page builder" section. Other page-builder modules co-locate
their field settings there via
`FieldConfigFormAlter::ensurePageBuilderSection()`: create the section if
missing, park elements under `$form['page_builder']`, and route values into
core's `third_party_settings` tree with `#parents` so core persists them
without custom submit mapping.

## The icon value grammar

The page builder's icon value grammar lives on the spine, so every surface
that stores "an icon" (block-type icons, blueprint icons) stores the same
one-string value and resolves it through the same seam,
`daedalus_blueprint.icon_resolver` (`\Drupal\daedalus_blueprint\IconResolver`);
Daedalus UI chrome resolves through it like every other consumer:

- `media:<uuid>`: a media entity referenced by UUID. The user lane, written
  by media library pickers; resolves to the media source file's URL
  (original file, no image style).
- anything else: a root-relative URL path such as
  `/modules/contrib/daedalus/modules/daedalus_layout/assets/image.svg`. The shipped lane for configs
  and recipes, returned verbatim. Producers with friendlier spellings (e.g.
  a module-relative path in a YAML definition) must resolve them to
  root-relative before storage.

Empty values and dangling media references resolve to NULL, and each
consumer renders its own default icon; the resolver never imposes one. See
the class docblock for the full contract, including cache tag handling.

## Theming requirements
### Entity templates must print `{{ attributes }}` on their outermost element
The editing UI addresses entities through attributes stamped on the entity's
render array (`#attributes`): the AJAX-refresh wrapper id
(`data-daedalus-ui-entity-wrapper`), the view mode, the entity root's
selectable key, and the main-entity markers. Core merges `#attributes` into
the template's `{{ attributes }}` variable, so they only reach the page when
the entity template (node, block, media, ...) prints `{{ attributes }}` on
its outermost element, as all core and Olivero/Claro templates do.

If a template fails to print `{{ attributes }}`, Daedalus UI wraps that
entity's rendered output in a fallback `<div>` carrying the attributes and
logs a warning naming the template. Editing keeps working, but the editor
sees an extra wrapper element the live page does not have; fix the template
to restore markup fidelity.

## Debug logging
Daedalus UI owns the Daedalus's client-side debug logging, so no module has
to grow its own. Depend on the standalone `daedalus_ui/logging` library
(which also orders the file ahead of yours) and grab a tagged logger:

```js
const {log} = Drupal.Daedalus.logger('my_module');
log('apply', 'Applying variant', payload); // [my_module:apply] Applying variant
```

Nothing prints until that module is switched on from the browser console. The
choice persists in localStorage, so it survives reloads:

```js
Drupal.Daedalus.logging.enable('my_module');
Drupal.Daedalus.logging.enable();  // Everything.
Drupal.Daedalus.logging.disable('my_module');
Drupal.Daedalus.logging.disable(); // Everything off.
Drupal.Daedalus.logging.status();  // What is on, and what could be.
```

`status().modules` lists every module that asked for a logger on this page,
which is the menu `enable()` accepts. The logger also exposes a live `enabled`
flag, so a hot path can skip building expensive log data.

## Issues
### Module Defined Templates
If you need to define templates in a module you need [to temporarily set a `use_twig_events` flag](https://www.drupal.org/project/daedalus/issues/3548969) in the hook theme.
```php
/**
 * Implements hook_theme().
 */
function my_module_theme($existing, $type, $theme, $path) {
  return [
    'block__block_content__view_type__my_type__default' => [
      'base hook' => 'block',
      'path' => $path . '/templates',
      'use_twig_events' => TRUE,
    ],
  ];
}
```
