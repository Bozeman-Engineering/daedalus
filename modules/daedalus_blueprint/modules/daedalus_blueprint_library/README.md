# Daedalus Blueprint Library

Saved blueprints: reusable page material, captured from a page's container
or composed from scratch, offered in the Add panel and to the AI, and
shippable to a module as a file. A saved blueprint is a PAGE of its own,
with its own document, its own canvas and its own revisions; what makes it
material rather than a page is that it is written FOR a kind of host (its
subject) and placed INTO one.

## The blueprint entity

`blueprint` is a bundle-less, revisionable, publishable content entity:

- `label`, `machine_name` (the roster id, unique across saved blueprints
  and shipped files; `blank` and `template` are RESERVED, the spine's
  `PageStart::RESERVED` through `BlueprintInterface::RESERVED_IDS`: the
  entity's presave refuses them, the generated name skips them, and
  discovery refuses a file under either as `INVALID_BLUEPRINT`, since
  they name the two starts that are not blueprints), `description`,
  `icon` (a media reference through the spine's icon value grammar),
  `ai_offered` (whether the AI's roster lists it), `design_system` (its
  membership), `origin`, `status`, `uid`, `created`, `changed`.
- `scope`: `block` (a composition placed into a page, exactly one root
  container) or `page` (a whole document, what a new page of the subject
  bundle may START as, never a placement into one; `BlueprintScopePolicy`,
  a capability policy, refuses a second root on a block-scope blueprint
  with `BLUEPRINT_ONE_ROOT`, discovery refuses a block-scope file of
  several roots as `INVALID_BLUEPRINT`, and `Blueprint::preSave()` refuses
  a block-scope entity saved with several sections, the storage twin that
  closes the road core's default-content importer takes with no op and no
  validation). Scopes never mix on a surface: the Add panel
  and the schema's `materials.blueprints` offer block scope, the chooser
  in front of a bundle's add form and the schema's `starts` offer page
  scope.
- `subject_type` and `subject_bundle`: the kind of host the blueprint is
  material for, stamped at capture from the source host's display target
  and editable in the details dialog (`BlueprintSubjects` lists every
  fieldable bundle a Layout Builder display lays out).
- `bundles`: an optional allow-list of `type:bundle` pairs ("Allowed
  hosts") narrowing where it fits.
- `groups`: zero or more group ids, the keys of the site's group
  vocabulary: the spine's dialect catalog carries it
  (`DialectCatalog::groups()`), declared as the `groups` map under `site`
  in the `PROVIDER.page_builder*.yml` family and contributed on the
  catalog's build event by daedalus_layout's `SiteGroups`, which parses
  and validates it (`ungrouped` is reserved for the things in no group).
  Editable in the details dialog and through `update_material`. A group
  is a filter category on the surfaces that offer blueprints and block
  types and nothing else (the chooser shows the groups its offered starts
  carry as filter links): no operation, fit or placement rule reads it,
  and a surface ignores an id the site does not declare.
- `favorite`: whether the blueprint is a site favorite, the default an
  offering surface lists under Favorites for everyone (the Add panel; the
  chooser lists favorites first), over which each editor keeps their own
  marks (daedalus_layout's editor settings). Off by default; editable in
  the details dialog and through `update_material`. A listing fact and
  nothing else, like the groups.

There is no body field. The document is core's `layout_builder__layout`
on each blueprint TYPE's `full` Layout Builder display, shipped as this
module's install config (the view mode, and per type the display with
overrides allowed and its field; the field storage once), and
daedalus_stylish's style map field, which daedalus_stylish opens by
itself: its gate is Layout Builder plus Edit Mode on the bundle, and a
blueprint type answers the Edit Mode gate as on by construction
(`BlueprintType::getThirdPartySetting()`), so the shipped display alone
opens the store (at the display's import, or at the sweep after an
applied recipe) and the `style {type} blueprint elements` permission
follows the field. A recipe that relies on the displays imports this
module's config (`config.import: daedalus_blueprint_library: '*'`); a
module's config entities never land from an install list alone.

The blueprint's bundle is its TYPE (`blueprint_type` config,
`src/Entity/BlueprintType.php`): the one axis every permission runs on
and the roster's grouping (the record's `group`). The module ships
`section` (what editors save from a page and place from the Add panel;
the type a capture mints unless `save_material` names another) and
`site_chrome` (headers and footers, the site frame's material, the site
builder's). A site adds a type by config: the type entity plus its `full`
display with overrides allowed, exactly what the module ships for its
two; the three permissions follow at once. A file blueprint names its
type with the optional `type` key (absent means `section`). The type is
identity like the machine name and the scope: never updated.

The host is the spine's `BlueprintHost` (daedalus_blueprint
`src/Host/BlueprintHost.php`): `subject()` is core's sample entity of the
subject bundle, `display()` that bundle's `full` render display, and the
sections load through the `blueprint` section storage plugin (core's
overrides storage with the subject in its preview contexts), so field
blocks render against the subject on the canvas and the live render, and
the Add panel offers the subject's fields. It never seeds, and
`Blueprint::preSave()` makes every commit a new revision. The spine knows
the type by `BlueprintHost::ENTITY_TYPE_ID` and the field-name constants;
nothing in daedalus_blueprint imports a class of this module. The one
adapter that does is daedalus_layout's `BlueprintSaveDialog`, the save,
update and details dialogs over this module's gestures.

Routes: `/blueprint/{blueprint}` (canonical) IS the canvas, in Edit Mode
like any page; `/admin/content/blueprints` lists them with "Add blueprint"
(core's add page picks the type, then label, description, scope, subject;
saves and lands on the empty canvas in Edit Mode).
`BlueprintRouteSubscriber` removes core's `/blueprint/{id}/layout` routes,
so there is one canvas over one storage and one tempstore key.

## Permissions

Every grant is per blueprint type, three per type (`BlueprintPermissions`,
the one spelling): `create {type} blueprint` (Save as blueprint, the
`save_material` op, the add page), `edit any {type} blueprint`
(`update_material` and Update blueprint, the details dialog and form,
composing on the canvas, curating owned variants, seeing it unpublished)
and `delete any {type} blueprint` (the listing's delete, `remove_material`).
The entity's access handler judges each blueprint by its type; surfaces
about no one blueprint (the listing, the gesture routes, daedalus_layout's
dialogs, the Add panel's `canSaveBlueprints`, daedalus_stylish's ownership
gate) ask the any-type gate (`BlueprintTypeAccess`, route requirement
`_blueprint_type_access: 'create'` etc.). Composing on a blueprint's
canvas follows core's Layout Builder grammar through the spine's role
policy besides: `configure editable {type} blueprint layout overrides` (or
`configure any layout`), and styling follows daedalus_stylish's per-bundle
style permission. The recipe's roles ingredient grants `section` to the
editor and `site_chrome` to the site builder. Shipped (file) blueprints
are read-only whatever the role: the site forks one instead (see "The
distro lock" in daedalus_blueprint's README).

## One machinery

Every road is `NodeTreeBuilder::build(source host)` then
`DocumentInstantiator::instantiate(destination host, nodes, location)`
(`BlueprintManager`):

- **Capture** ("Save as blueprint…" on a container, "Save page as
  blueprint…" from the top bar's Save menu; `save_material {kind:
  blueprint, element, label, description, strip_values?,
  adopt_variants?, global?}`): the subtree at the container (the host's
  own uuid captures the whole document as a page-scope blueprint; a block
  is refused `BLUEPRINT_ROOT_NOT_CONTAINER`, capture its container) is
  instantiated into a new blueprint host with fresh identity and
  committed. The subject is the source host's display target. The tree is
  the definition, so a capture carries exactly what the serializer
  spells: `strip_values` keeps the skeleton (empty inline blocks keep
  their keys).
- **Update** ("Update blueprint 'X'" on a placement; `update_material
  {kind: blueprint, id, element?, label?, description?, subject?,
  bundles?, …}`): the blueprint's document and style map are emptied, the
  placed subtree instantiated in, a new revision. A placement knows its
  blueprint by provenance (`blueprint` on the placed root, under the
  section's `daedalus_blueprint_library` third-party key).
- **Placement** (the Add panel drop, `place_blueprint {blueprint,
  location, uuid?, mode?}`, the dialect's `<pb-blueprint name="id"/>`):
  detached (the default mode), a BLOCK
  blueprint's entry `layout` (a file's, or the saved entity's built tree)
  expands into the spine's ordinary operations on the destination host,
  with fresh identity and the provenance stamp; it places at the top
  level only by the band rule. `mode: linked` (the Add panel row menu's
  "Place linked", the dialect's `<pb-linked blueprint="id"/>`) lands one
  node instead, `{kind: linked, blueprint}`, the saved blueprint shown by
  reference and following its later edits; a shipped file cannot be
  linked (`BLUEPRINT_NOT_LINKABLE`), and the fit is the op door's for a
  linked node. A page blueprint is refused first,
  `BLUEPRINT_WRONG_SCOPE` (a whole page is a new page's start, never a
  placement into one; the hint names `page_create`'s `start`, and the
  dialect's `<pb-blueprint>` of one answers the same code), then
  `BLUEPRINT_EMPTY` (a saved blueprint not yet holding a root, the
  corrective naming its canvas) and `BLUEPRINT_DOES_NOT_FIT`, all before
  anything is placed (see "Fits" in daedalus_blueprint's README).
- **Detach** (`detach_blueprint {element}`, a page-language op: page_edit
  admits it with a ref): the road from linked to detached. The element is
  a linked node; the instantiator expands the blueprint's tree in the
  node's place (`DocumentInstantiator::replace()`: after the node, in its
  flow, fresh identity, the provenance stamp, absent fields
  sample-populated as a detached placement has them) and removes the
  node, one dressed batch, so the page owns the copy from then on and
  Update and Save as blueprint work on it as on any detached placement.
  The node's own box styles are not carried (the copy's root is the
  blueprint's root with the blueprint's node styles; the blueprint's
  root entry stays the blueprint's). Refusals: `BLUEPRINT_NOT_LINKED`
  (the element is no linked node), `ELEMENT_NOT_FOUND`,
  `BLUEPRINT_NOT_FOUND` (the node links a blueprint the site no longer
  serves: unpublished or deleted), then the placement's `BLUEPRINT_EMPTY`
  and `BLUEPRINT_DOES_NOT_FIT`. The op door asks arrange on the node; the
  nested batch judges the copy's insert and the removal again. No verb
  offers it yet; slot claiming ("Detach into this slot") is the first
  gesture to ride it.
- **Start** (the chooser in front of a bundle's add form, `page_create`'s
  `start`, a chooser card's preview): not a gesture of this module. The
  spine's `PageStart` reads the blueprint's roster record (its tree and
  its page-level styles) and copies the whole document onto the unsaved
  page with fresh identity and NO provenance stamp, so a started page is
  not a placement and "Update blueprint" never appears on it (see
  "Starting a page" in daedalus_blueprint's README). What this module
  contributes is the record: `BlueprintMaterialsProvider` carries the
  page-level `styles` beside the `tree`.
- **Open** ("Open blueprint" on a linked placement or a slot door):
  daedalus_ui's annotator stamps `_element.showing` on every join, its
  `portal.url` the blueprint's canvas when the actor may update it; the
  verb navigates there.
- **Ship** (`drush daedalus-blueprint:blueprint-ship BLUEPRINT MODULE
  [--label --description --dry-run --user]`, and the blueprint lane of
  `daedalus-blueprint:design-system:ship`): the saved blueprint's built
  tree and node-less styles, uuids and references stripped, are written
  as a blueprint file beside its icon, and its owned variants through
  daedalus_stylish's variant shipper under the `blueprint:` owner key.
  Every ship graduates: the content blueprint is deleted once the file is
  written, so a blueprint is either a file or an entity, never both
  (`Blueprint::preSave()` refuses a content blueprint under a shipped
  id, discovery refuses the reverse with `BLUEPRINT_CONFLICT`). A shipped
  file is revised by hand or by shipping a new blueprint under its own
  id.

## Field blocks name the field

A field block in a blueprint's tree is `{kind: block, field: body,
configuration?}`, never core's per-bundle `field_block:{type}:{bundle}:
{field}` derivative: the instantiator expands it against the destination
host's subject and refuses `FIELD_NOT_ON_HOST` with the subject's
placeable fields when it cannot. One tree therefore fits every host that
has the field, and the subject bundle is a fact about where the blueprint
was composed, not a limit on where it places (the `bundles` allow-list is
that limit, when set).

## Discovery and the file envelope

`BlueprintDiscovery` (`daedalus_blueprint_library.discovery`) reads two
lanes into one flat id namespace (a collision is `BLUEPRINT_CONFLICT`):

- Files `MODULE.daedalus_blueprint.blueprints.ID.yml` at a module's root:

  ```yaml
  label: 'Call to action'
  description: 'A heading, a sentence and a button in a band.'
  icon: assets/call_to_action.svg      # optional, module-relative
  ai: true                             # optional, offered to the AI
  design_system: olivero               # optional membership
  groups: [heroes]                     # optional group ids (filters)
  favorite: true                       # optional, a site favorite
  scope: block                         # block | page, required
  subject: 'node:page'                 # type:bundle, required
  layout:                              # a list of container nodes
    - kind: container
      layout_id: daedalus_container
      children:
        - kind: block
          block_type: text
          block_fields: {field_text: 'Ready?'}
        - kind: block
          field: body
  styles: {}                           # optional node-less style keys
  ```

  Every rule is loud at rebuild (`INVALID_BLUEPRINT`): `scope`, `subject`,
  `label`, `description` and `layout` are required, `layout` is a
  non-empty list of container nodes, a block-scope file has exactly one
  root, `subject` is shape-checked only (a package ships material for
  another module's bundle before that config lands), `styles` keys
  never start with `container:` or `block:` (a page blueprint's
  page-level entries are spelled bare, `entity:` and `entity:::> *`, the
  way the shipper writes them), `groups`, when given, is a non-empty list
  of distinct machine names, `favorite`, when given, is `true` (absence
  is the default's one spelling, as `ai: false` is the opt-out's), and the
  ID is neither reserved start (`blank`, `template`). The envelope carries no `bundles`, so a shipped
  blueprint fits any host that has what its layout needs.
- Published `blueprint` entities: each entry's `layout` and `styles` are
  `NodeTreeBuilder::build()` and the node-less styles over the saved
  host, built once per rebuild and cached under the tag
  `daedalus_blueprint_library`, which the entity's CRUD hooks invalidate.
  A content entry also carries `origin` and `entity_id` (the page id the
  AI reads its body through).

`BlueprintMaterialsProvider` serves discovery's set as roster records of
kind `blueprint` (`MaterialKind::Blueprint`): id, label, description,
icon, `blueprint_scope`, `offered`, `updatable`, `groups` and `favorite`
(the Add panel files and lists its rows by the last two), and server-side
the `tree`, the page-level `styles` and the derived `requires`.
Every surface reads those records, each asking the spine's offer for its
own scope: the Add panel, the prompt's BLUEPRINTS block and the schema's
`materials.blueprints` the block-scope ones (the two AI surfaces print
the site favorites in full and the rest as a group index, read one group
at a time with `eb_read_schema`'s `group`), the chooser in front of an
add form, its previews and the schema's `starts` the page-scope ones
(`PageStart::starts()`, favorites first, then by label), and
daedalus_stylish's ownership surfaces all of them.

## Variants a blueprint owns

A blueprint may own the style variants minted for it (adopted at capture
with `adopt_variants`, released or transferred through
`/daedalus-blueprint/blueprint-variant/{reference}/release|transfer`):
`BlueprintVariantFieldHooks` adds `blueprint` (an entity reference) and
`blueprint_file` (a shipped blueprint's machine name) to `daedalus_variant`,
and `BlueprintVariantOwnershipChecker` (registered only when
daedalus_stylish is installed) refuses an owned variant outside its
owner's placements with `VARIANT_OWNED_BY_BLUEPRINT`. daedalus_stylish never
learns what ownership means; it carries the `blueprint:` key of a variants
file onto the two fields and back.

## No update path

The library replaced an earlier saved-material module at full depth: a
different entity, a different document (the earlier module stored a
serialized element blob; a blueprint IS a page document), different
names on every surface. There is no update hook across it, by decision.
`daedalus_blueprint_update_requirements()` in `daedalus_blueprint.install`
halts update.php and `drush updb` on a site whose `core.extension` still
lists `daedalus_blueprint_stencil` or that still has a `stencil` table,
with "This site predates the blueprint library; there is no update path
across it: reinstall from the recipe."

## Maintainers

- Tim Bozeman - https://www.drupal.org/u/tim-bozeman
