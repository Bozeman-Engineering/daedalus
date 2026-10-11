# Daedalus Blueprint

Bidirectional JSON entity serialization for Drupal. Serialize any fieldable
entity (Layout Builder pages with nested paragraphs and inline blocks
included) and any Layout Builder template (a bundle's default layout on its
entity view display) to a clean JSON format. Deserialize AI-generated or
hand-crafted JSON back into valid Drupal entities with two-phase validation
and structured error feedback. A page document has ONE shape, the lean node
tree with each node's styles on the node, and a page document and its
template are one grammar on one spine; see "The page document and its host"
below.

## Features

- Serialize any fieldable entity (nodes, blocks, paragraphs, etc.) and any
  Layout Builder template to JSON.
- Three serialization modes: full, summary (the same tree pared of field
  values), and element (one element's node, the same object the full read
  holds).
- Deserialize JSON back to unsaved Drupal entities with structural and semantic
  validation; a whole layout is written at creation through the one
  instantiation machinery, never onto an existing entity.
- Targeted CRUD operations on individual elements without full round-trips.
- JSON schema generation for entity type and bundle combinations, with
  AI-facing context (guidance, defaults, and markup anatomy) merged in from
  YAML files and `hook_daedalus_blueprint_context_alter()`.
- Batch operations with atomic rollback.
- One capability answer: every operation declares what it needs of each
  target, one resolver judges it for the batch's actor at the op door, and
  every read publishes the same verdicts (see "Capabilities" below).
- Opaque data protection: Layout Builder internals are preserved unless a module
  explicitly opts in via hook.
- Multilingual support for translation workflows.
- Extensible field handler system via tagged services.


No additional contrib modules are required for the base module.

### Optional

- **Layout Builder** (core): Required for Layout Builder serialization support.
- **Drupal AI** (`drupal/ai`): Required by the `daedalus_blueprint_ai` submodule
  to expose operations as AI agent tools.

### Recommended
- **Daedalus**: An AI powered page builder built on layout_builder.

## Installation

Install as you would normally install a contributed Drupal module. Visit
https://www.drupal.org/docs/extending-drupal/installing-modules for further
information.

```bash
composer require drupal/daedalus
drush en daedalus_blueprint
```

To enable the AI agent tools submodule:

```bash
drush en daedalus_blueprint_ai
```
## Usage

### Drush commands

```bash
# Serialize an entity to JSON.
drush daedalus-blueprint:serialize node 1

# Serialize structure only (no field values).
drush daedalus-blueprint:serialize node 1 --summary

# Serialize a single element by UUID.
drush daedalus-blueprint:serialize node 1 --element=UUID

# Deserialize JSON back to an entity (prints result, does not save).
drush daedalus-blueprint:deserialize blueprint.json

# Validate without hydrating.
drush daedalus-blueprint:deserialize blueprint.json --dry-run

# Read from stdin.
drush daedalus-blueprint:deserialize - < blueprint.json

# Generate a JSON schema for a bundle.
drush daedalus-blueprint:schema node page

# Activate a design system (alias dd-blueprint-activate).
drush daedalus-blueprint:design-system:activate olivero

# Ship a whole design system to its package module (alias dd-blueprint-ship):
# the unit as install config, its content blueprints as blueprint files, the
# site's materials as per-id files; the module is created when absent.
drush daedalus-blueprint:design-system:ship olivero
drush daedalus-blueprint:design-system:ship my_system my_brand --dry-run

# Ship a SAVED blueprint to a module as a blueprint file plus its variants
# file; the content blueprint is deleted after the ship
# (daedalus_blueprint_library; see "The distro lock").
drush daedalus-blueprint:blueprint-ship call_to_action mymodule --dry-run
```

### Design systems

A design system is the unit a skin and its materials form: a `design_system`
config entity (id, label, description, icon, default skin, package). A
module that packages one ships it as
`config/install/daedalus_blueprint.design_system.ID.yml` with `package: MODULE`;
a site builder creates one at `/admin/appearance/design-systems`. The site
activates ONE (`daedalus_blueprint.settings` `active_design_system`, through the
listing, the drush command, or config), and the materials roster offers the
active system's members plus the global materials (those of no system). A
`save_material` mint joins the active system unless it passes `global: true`.
A package cannot be uninstalled while its system is active or still has
members, and the active system cannot be deleted.

### The distro lock (developer sites)

Materials a package ships (variants, swatches, animations and fonts synced
from its files, a skin's package layer, blueprints read from files) are
read-only on a site: the package's files are their one author, and a site
that wrote into them would drift away from every future sync. The site
forks a material instead ("Copy as my variant", "Copy as my skin") or
reverts toward the disk; a shipped skin alone keeps taking the site's
token and defaults edits, in its own site layer over the package's.

The package author works the other way round: the site is the canvas and
drush (`daedalus-blueprint:design-system:ship`, `daedalus-blueprint:blueprint-ship`) carries the result
back into the module's files. The lane's two directions have two words:
disk to site is sync (`daedalus_stylish:sync`, the same run a cache rebuild does),
site to disk is ship. On such a development site, lift the lock in
`settings.php`:

```php
$settings['daedalus_blueprint_developer'] = TRUE;
```

The flag opens every shipped-material gate at once (the
`daedalus_blueprint.distro_lock` service is the one fact they consult). It is
a setting rather than a permission because writable module directories are
an environment property: a development site needs no role setup, and a
production administrator cannot lift a lock that protects production. Roster
records are cached, so rebuild caches (`drush cr`) after flipping it.

### Tailing the AI log (`daedalus_blueprint_ai`)

`drush daedalus-blueprint:ai-tail` (alias `dd-blueprint-tail`) follows the `ai_log` table the
`ai_logging` module writes. Every LLM call logs the whole conversation so far,
so following one page-building thread raw means reading the same history on
every row. By default the command prints what each row adds: an `ai_agents`
runner is one thread, the thread's first row prints its whole prompt, and each
later row prints only its new messages under
`[+N messages since #id, H already printed]` (a rewritten history prints whole
with `[transcript diverged from #id; printed whole]`; rows outside any thread,
such as image generation, print whole). Base64 blobs collapse to their first
64 characters plus a byte count wherever they sit. `--full` restores the raw
dump: the whole prompt of every row as the model received it, every byte.

```bash
# Follow the log: 5 recent rows, then poll every 2 seconds.
drush dd-blueprint-tail

# Print the 10 most recent rows and exit.
drush dd-blueprint-tail --no-follow --limit=10

# The raw dump: whole prompts, no dedupe, no base64 collapse.
drush dd-blueprint-tail --full

# Only rows carrying a tag value (joins ai_log__tags).
drush dd-blueprint-tail --tag=ai_agents

# The last 3 messages of what each row prints; -m5 for five (no space).
drush dd-blueprint-tail -m

# Add the system prompt, tools, configuration and extra data sections.
drush dd-blueprint-tail --all
```

### Custom field handlers

Register a handler for custom field types by creating a tagged service:

```yaml
services:
  my_module.field_handler.custom:
    class: Drupal\my_module\FieldHandler\CustomFieldHandler
    tags:
      - { name: daedalus_blueprint.field_handler, field_types: 'my_field_type' }
```

Extend `FieldHandlerBase` and implement `doSerialize()` and `doDeserialize()`.

## The page document and its host

A page document is one tree of container, region and block nodes, each
node carrying its own styles, plus the style keys no node owns (see "The
document's one shape" below). The document has a
HOST (`\Drupal\daedalus_blueprint\Host\HostInterface`, resolved once at the
doors by `daedalus_blueprint.host_resolver`): the owner of the section
storage core resolves for a render, answering identity, sections, styles,
fields, subject and persistence in one object. Every public spine method
(the operations, the serializers, the lifecycle events, results, the
storage handler, the backends) takes the host for its root, and nothing
above the storage wrapper reads a field to find the document. Four hosts
exist:

- A **content host** with a document: a fieldable entity whose bundle
  allows layout overrides. Its sections live in core's
  `layout_builder__layout` field and its styles in the daedalus_stylish
  style field; the host maps both. Persistence revisions and is workspace
  aware.
- A **display host**: a Layout Builder enabled entity view display, the
  bundle's default layout, the TEMPLATE. Its sections are the display's
  defaults storage, its styles a `daedalus_stylish.styles` third-party
  setting, its subject core's sample entity, and it has no fields.
  Persistence is a config save: no revisions, no workspace, exempt from
  the workspace-required check.
- A **blueprint host** (`BlueprintHost extends ContentHost`): a SAVED
  BLUEPRINT, the `blueprint` content entity `daedalus_blueprint_library`
  owns (reusable material captured from a page or composed from scratch),
  which is a page of its own: its document lives in core's layout field on
  the entity and its styles in the style field like any content host, and
  it is edited on its own canvas. What differs is the SUBJECT: a blueprint
  is material for a kind of host (`subject_type`, `subject_bundle`, the
  kind it was captured from), so `subject()` is core's sample entity of
  that bundle, `display()` is that bundle's render display, and its
  sections load through the `blueprint` section storage plugin (core's
  overrides storage with the subject in its preview contexts), so field
  blocks render against the subject on the canvas and the Add panel offers
  the subject's fields. It never seeds, and every commit is a new
  revision. The spine knows a blueprint by `BlueprintHost::ENTITY_TYPE_ID`
  and its field-name constants alone; the entity, its routes and its
  gestures are the library module's (see its README).
- A **plain host**: a content host without a document (overrides off, or
  Layout Builder off). It renders its display's document as a FOREIGN
  document, readable and never writable through it: the ownership policy
  (see "Capabilities" below) refuses every capability on its elements with
  `FOREIGN_DOCUMENT` naming the owner, and the page keeps only its own
  fields and its own root style key.

`HostResolver::renderHost()` is the one rule for which document a render
shows, and it is core's: an overridable display with a non-empty override
shows the entity's own document, anything else on a Layout Builder
display shows the template.

### The document's one shape

The blueprint names the document by role, for every host: `layout` and
`styles` sit at the top level beside `fields`, and a document field
spelled inside `fields` is refused (`DOCUMENT_KEY_IN_FIELDS`). Core's
storage field keeps its name; only the content host knows it.

`layout` is the lean node tree, and `LayoutSerializer` is its one
producer (its class docblock is the shape's reference): a list of
container nodes in display order, every node declaring its `kind`.

- A **container** (every section, top-level or nested, the same shape at
  every depth): `{kind: 'container', layout_id, uuid, label?, blueprint?,
  slot?, layout_settings?, children, styles?}`. A container layout's
  ordered flow is its `children` directly; a multi-region layout's named
  slots are **region** nodes, `{kind: 'region', region, children}`, every
  region of the layout, empty ones included. A region is structure, not
  an element: no uuid, no styles. The tree has ONE child axis at every
  depth, so a path reads `layout[0].children[1]`. `slot` marks a **slot
  section**: a top-level section filling the slot of that id placed by
  the document around it (a locked bundle's template, the site frame);
  the id is a birth fact (`add_section`'s `slot`, stored in the section's
  third-party settings under `daedalus_blueprint.slot`, read by
  `LayoutSerializer::slotOf()`), a slot fills once per document
  (`BLUEPRINT_SLOT_DUPLICATE`), the section stays in the root flow
  (`BLUEPRINT_SLOT_NESTED`), and the dialect spells it
  `<pb-slot name="id">`.
- A **block**: `{kind: 'block', uuid, blueprint?, block_type | plugin_id |
  field, label?, label_rendered?, view_mode?, block_fields?,
  configuration?, styles?}`. An inline block carries its bundle, its
  fields and its managed configuration keys; a plugin block with config
  schema its label and its own settings under `configuration`; a
  schema-less plugin is its plugin id alone. A field block names the
  **field** it renders (`field: body`), never core's per-bundle
  `field_block:{type}:{bundle}:{field}` derivative: the host the tree is
  written to supplies the derivative from its subject's entity type and
  bundle (`DocumentInstantiator`, refusing `FIELD_NOT_ON_HOST` with the
  subject's placeable fields when it cannot), so one tree fits every host
  that has the field; the formatter is its `configuration`. The dialect
  spells it `<pb-block field="body"/>`, `read_schema` lists the bundle's
  placeable fields under `available_fields`, and the base plugin every
  field block styles under is `block_plugin:field_block`
  (`NodeTreeBuilder::pluginBase()`, mirrored by the client twins).
- A **linked** node: `{kind: 'linked', uuid, blueprint, styles?}`. A
  saved blueprint shown by reference (the
  `daedalus_blueprint_linked:{machine_name}` block, one derivative per
  published block-scope blueprint entity): its one fact is the blueprint
  it shows, whose document renders live inside the node through the
  render primitive (`InnerRender`) under the page's own contexts, so a
  field block in the blueprint shows the page's field and an edit of the
  blueprint reaches every page linking it. No children, no plugin id, no
  provenance (nothing was copied); its styles are the placement's own
  box, block-keyed. The dialect spells it `<pb-linked blueprint="id"/>`;
  writing one lands `add_element` of the linked block, where the op door
  judges the fit (`BLUEPRINT_DOES_NOT_FIT`) for every road. Its content
  is foreign on the page: `content` is refused `FOREIGN_DOCUMENT` with a
  portal to the blueprint canvas for an actor who may update it, while
  the placement itself stays the page's. Styles follow the blueprint
  host: the primitive delivers the inner host's map inside the render
  (`hook_daedalus_blueprint_inner_render_alter()`) and roots the
  blueprint's inline blocks on the blueprint.
- The tree is sparse: a key is present when it carries a fact.
  `label_rendered` (TRUE when the label renders as page content) is the
  one spelling of the display fact; core's `label_display` stays inside
  storage. `view_mode` rides only when it is not `full`. Storage residue
  (a section's stored index, a block's revision id, per-component region
  names and weights) never appears: order is the array order.

**Styles ride the node.** The host stores ONE flat style map (selectable
keys to condition-keyed entries) and the style ops address it by absolute
key; the blueprint distributes it onto the nodes as the tree is built. A
node's `styles` is keyed by the LOCAL remainder of the selectable key
after the node's identity (`block:{uuid}` / `container:{uuid}`): `''` the
element's own entry, `:{field}` a block field's, `::{selector}` a
relative selector under it, and their compounds exactly as the map spells
them. The top-level `styles` carries only the keys no node owns
(`entity:`, `field:` and their rule forms; absent when empty), so a
subtree is self-contained and the flat map is exactly reconstructible
from the tree plus that remainder. The reconstruction has one canonical
order, the document's (`NodeTreeBuilder::styleMapOf()`): the node-less
keys first, then every node in document order, each node's own keys as
stored. Both CSS printers print that order.

**A read is a write.** The summary answers the same nodes pared of
`block_fields`, `configuration` and `layout_settings`; the element read
(`serializeElement`, `read_element`, `--element`) answers the element's
node exactly as it sits inside `layout` (a container with its whole
subtree), stamped with its verdict; a paragraph still answers its fields.
Every read stamps `_verdict` on the root and on every container and block
node, and a delivered blueprint carries daedalus_ui's `_element` on the
same nodes; the write grammar ignores every key beginning with `_`, the
storage residue above and `blueprint` provenance below the root. So a full
read with its `_root_entity` removed is a valid create of an equal page
(`BlueprintSerializerTest::testFullReadCreatesAnEqualPage`; root
`entity:` / `field:` style keys name the source host and are the one
exception).

**The whole-layout write.** A `layout` is written once, at creation:
`BlueprintDeserializer` expands the tree through `DocumentInstantiator`
with the author's uuids kept (a node naming none is minted) and runs the
expansion as one dressed batch of the ordinary ops on the unsaved host,
so every spine check applies (block types, placement restrictions, field
validation, the style guide); node styles and the top-level `styles`
merge into one map (a key stated in both places is `DUPLICATE_STYLE_KEY`)
and go through the style store's one validation path after the structure
stands. A created entity carrying a `layout` is NOT seeded from its
template (`HostInterface::declineSeed()`); one created with a `start`
takes that instead (see "Starting a page" below); one created with
neither is seeded from its template on its first read, as before. A
`layout` arriving with `_root_entity` is refused:
`LAYOUT_ON_EXISTING_ENTITY`, whose hint names the ops road (`add_section`,
`add_element`, `move_element`, `remove_element` and the style ops in one
batch, or `page_edit` on a page-language site); a fields-only update onto
an existing entity is unaffected. `validate_only` runs the same expansion
on a disposable object and persists nothing. The grammar is the
instantiator's (`INVALID_NODE` with a corrective; a writer who spells a
`regions` map is told the region-node shape), and the same grammar
instantiates a blueprint, seeds a page from its template and lands a
`page_create` document.

### Value blocks

On a display host inline blocks are VALUE BLOCKS: the block's fields travel
in the component's `block_values` configuration in the spine's field
grammar, references spelled by uuid, so a template exports as config with
no content ids in it (`InlineBlockValues`; `HostPersistence::$valueBlocks`
is the fact the spine consults). A block carrying a field no handler
serializes is refused on a template with `FORM_ONLY_FIELDS`; a contrib
field type joins by adding a field handler. Content hosts keep
`block_content` entities, and a value block placed into a content host
becomes an unsaved block entity.

### Plugin block configuration

A plugin block's property bag is its own config schema
(`block.settings.{plugin_id}` through typed config): `update_element` and
`add_element` take `label`, `label_display` and the schema's own keys (on
the tree the display fact is `label_rendered`), validation is typed-data
validation of the resulting settings (`INVALID_CONFIGURATION`,
`UNKNOWN_PROPERTY`), `read_schema` advertises them
(`available_block_plugins`, each key described from its schema; the
bundle's own field blocks under `available_fields`, by field name), and a
plugin without schema is placeable bare but not configurable
(`NO_PLUGIN_SCHEMA`); adding schema is how a plugin joins. Field blocks
are the representative:
their formatter settings are the manage-display job. Managed keys (see
`daedalus_blueprint.api.php`) remain for the opaque extras of inline block
configuration and section layout settings.

### One instantiation

`DocumentInstantiator` (`daedalus_blueprint.document_instantiator`) places
a lean node tree into a host with fresh identity: uuids re-minted, style
entries re-keyed into the host's map, value blocks becoming unsaved blocks
on a content host; `instantiateDocument()` is the whole-document copy, the
roots plus the page-level (node-less) style entries re-addressed to the
host's root. Seeding a page from its template (`seed()`, run by the
content host on the first read of either of its document doors, the
sections or the style map, with identity derived from the page and the
template, so reads before the first save stay stable and a reader taking
the map first sees the whole seed; the seed copies the whole document,
the template's page background and gap included, laid beneath whatever
the page's own root entries already say, so the page's own property wins
exactly as it does on a template render; and it fires only on a
TRULY EMPTY override field: core's blank section, `layout_builder_blank`,
means "started, and purposefully emptied" on a page, as core's
`SectionListTrait::hasBlankSection()` says, and is left alone), the start
of a page from a page blueprint (`PageStart`, below), blueprint placement,
`page_create` and a created entity's `layout` (the deserializer: the HTTP
op endpoint, drush, and `eb_create_entity` for a bundle that is not a
page bundle) all run through it, and so does every saved-blueprint road:
a capture reads the source host's tree at a container and instantiates it
into a new blueprint host, a push does the same into the blueprint's
emptied document, a placement instantiates the blueprint's tree (a file's
`layout` or the saved entity's built tree) into the page, and a ship
writes the built tree to disk. A blueprint's body is any node-tree
subtree, plugin blocks included. Its grammar spells everything a read
emits (`view_mode`, an inline block's managed `configuration`,
`label_rendered`), so a tree read from one host instantiates onto another
without editing: that is what lets a field block spelled by field name
fit every host that has the field.

### Fits

What a blueprint needs of a host is derived from its tree
(`\Drupal\daedalus_blueprint\Materials\BlueprintRequirements`, service
`daedalus_blueprint.blueprint_requirements`): the field names its field
blocks spell, the block types of its inline blocks, its plugin ids, its
layouts, and the bundles allow-list the blueprint carries. `fits()` judges
a host against them (the subject bundle has the fields, the site has the
plugins, block types and layouts, the allow-list admits the bundle).

What a host is OFFERED is one judgement over the fit, asked with a scope
(`\Drupal\daedalus_blueprint\Materials\CanvasOffer::offered($record,
$host, $scope)`, service `daedalus_blueprint.canvas_offer`): a blueprint
is offered when it is of the asked scope, has a root and fits; every other
material kind is offered everywhere. Scopes never mix on an offering
surface, because each surface answers one question. "What can I place
here" is asked for BLOCK scope, by both halves of the Add panel's
delivery (`MaterialsDelivery::payload($host)` keeps only what the canvas
offers, and `MaterialsChangeSet::delta($canvas)` lists a touched blueprint
the canvas does not offer under `remove`: a details change that ends the
fit, a page-scope capture, an empty blueprint, so a filter the boot
applied is never undone by the next mid-session upsert) and by a bundle's
schema read (`materials.blueprints`, the site favorites among them, or
one group with `group`; see "Starting a page"). "What can a page of this bundle
start as" is asked for PAGE scope, of the bundle's unsaved host
(`PageHosts::bundleHost()`), by `PageStart::starts()`: the chooser in
front of the add form, a card's preview and the schema read's `starts`
all read that one answer. Every mutation door names the canvas its
payload is for (the browser doors' route host; an AI turn's
`current_route` context, since a turn may write another host than the
page it sits on); a flush with no canvas is unfiltered. The AI's
always-on BLUEPRINTS block prints block scope only, the site favorites
and a group index, and is not filtered by fit (it is one cached text for
every page). `place_blueprint` refuses a page
blueprint first, `BLUEPRINT_WRONG_SCOPE` (a whole page is a start, never
a placement; the hint names `page_create`'s `start`), then
`BLUEPRINT_EMPTY` for a saved blueprint not yet holding a root (naming
its canvas) and `BLUEPRINT_DOES_NOT_FIT` naming what is missing, all
before anything is placed; the instantiator's `FIELD_NOT_ON_HOST` remains
the field-level backstop.
Variants ride the derived requirements but are not judged by the fit:
whether a wearing applies is the style spine's gate at placement.

### Groups

A group is the site's word for what a block is for: a filter category
the surfaces that offer blueprints and block types file them under (the
Add panel, the chooser in front of an add form, the model's index), and
nothing else; no operation, fit or placement rule reads it. The
vocabulary is the spine's surface: the dialect catalog carries it beside
the variant axes (`DialectCatalog::groups()`: id to `label` and
`description`, '' when none, in declaration order), and every consumer
reads it there. The spine cannot read the declaration it comes from (the
`groups` map under the reserved `site` key of the `PROVIDER.page_builder*.yml`
family is daedalus_ui's lane, and daedalus_ui depends on the spine), so
the layer that parses it contributes it, validated, on
`DialectCatalogBuildEvent::addGroups()` the way daedalus_stylish
contributes the axes: daedalus_layout's `SiteGroups`, loudly, so a
misspelled declaration fails the catalog build instead of silently
filtering nothing. Each id is a machine name with a non-empty label; an
id contributed twice keeps its first declaration; `ungrouped`
(`DialectCatalog::UNGROUPED`) is the reserved bucket for the things in
no declared group and may never be declared. A blueprint carries its
group ids (the `groups` field, envelope key and record; `update_material`
takes "the site's group ids"), a block type carries its beside its icon,
and a surface ignores an id the site does not declare (a shipped
blueprint from a package whose groups this site lacks files under no
group).

### Pages, templates and the AI

The AI tools (`daedalus_blueprint_ai`) agree on one meaning of "page". On
a page-language site (the catalog declares a container layout) a page is
an entity whose render is a page document, its own or its template's: a
host that owns a document (`hasDocument()`: the override field filled or
empty, or a template) or a plain host whose render is its display's
(`HostResolver::renderHost($host) !== $host`). The predicate is the
`daedalus_blueprint.page_hosts` service (`PageHosts::isPage()`), and
`isPageBundle()` asks the same question of an unsaved entity of a bundle
for the askers with no host in hand. The bundle listing the create
workflow reads marks each page bundle (`[page]`, or `[page, renders its
template]` for a plain bundle), so the model chooses its creation door
from the listing. On a site without a container layout nothing is a page
and every door stays open.

Pages speak the page language and nothing else. `page_read`, `page_edit`
and `page_create` are their tools, and every JSON tool refuses a page with
a corrective naming the page tool for the gesture: `eb_read_entity` and
`eb_read_element` toward `page_read` (a page's own fields ride before the
print, so nothing a page knows is unreadable there, and no read of a page
through a JSON tool seeds it), `eb_edit` toward `page_edit` (a plain host
edits its fields there with `set` against `root` and its layout on the
template), `eb_create_entity` and `eb_validate` toward `page_create` for a
page bundle, before anything is built. `page_create` judges `start` and
`layout` by bundle: a bundle whose pages own their layout takes exactly
one of them (see "Starting a page" below), a bundle whose pages render
the template takes neither (fields only).

The page tools read every page. The print (`DialectPrinter`, composed for
a conversation by `daedalus_blueprint.host_printer`) is total over data:
the authored grammar is what the model may write back, and whatever a
page holds that the grammar cannot spell prints in the dialect's
read-only register instead of failing the document. Those annotations
are facts, never authored (the parser refuses any written `data-*` with
`READONLY_ATTR`): `data-layout` marks a section on a layout other than
the container (an adopted site's core layouts, or the `layout_onecol`
core seeds when Layout Builder is enabled on a bundle), `data-regions`
lists its regions when it has more than one, and `data-region` on each
child names the one it sits in; `data-unprinted` names fields an element
holds that the language cannot spell (a `set` keeps them, a `replace`
loses them); `data-partial` names printed attributes whose stored value
holds more than the print shows, a link's title or options (a `set` on
that attribute replaces the whole stored value); a `/* comment */` in the
`<style>` block names a style entry the grammar cannot spell, which
`style` and `set` leave alone. A print holding any of these is headed by
its reading notes, one sentence per kind present, composed once
(`DialectPrint::forReader()`) so `page_read`, the echoes of `page_edit`
and `page_create` and the per-turn "Current page print" teach alike and
the cached prompt never has to; a print with none is the document alone.
A write that landed is never answered as an error: `page_edit` and
`page_create` report the result, then echo. `MaterialsSchema` is the one
consumer that needs a write template rather than a read, so it offers a
blueprint's fragment only when its print is wholly authored
(`DialectPrint::isAuthored()`).

A region is an address, never an element. `at` on `insert` and `move`
takes an optional `region` (`at: {ref, position, region}`): `afterbegin`
or `beforeend` on a section the print marks with `data-regions` must name
one (`REGION_REQUIRED`, the regions listed, because the spine's default
would land it in the first region silently), a `region` anywhere else is
refused (`REGION_NOT_APPLICABLE`), and a name the layout lacks is the
spine's `INVALID_REGION`, addressed at `at.region`. A single-region
section needs no region. Nothing creates a multi-region section: the
`daedalus_stylish_layouts` filter offers only the container on every
door (`RESTRICTED_LAYOUT`), and columns are an arrangement of the one
container; the page language reaches the regions of a section that
already exists.

A template is addressed by its display: `page_read` and `page_edit` accept
`entity_view_display node.page.default`. A template is born by enabling
Layout Builder on the display, never through a blueprint, so `page_create`
refuses the type with a corrective pointing at `page_edit`. The
current-page line names a template on its route ("the default template
for page nodes") and says that editing it changes every page without a
layout of its own.

A saved blueprint is a page too: `page_read` and `page_edit` address it as
`entity_type: blueprint` with its id, `page_create {entity_type:
blueprint, fields: {label, description, scope, subject_type,
subject_bundle}, layout}` composes one from scratch, and the prompt's
BLUEPRINTS block is the model's index of the BLOCK blueprints the roster
offers the AI: one line per SITE FAVORITE (`MaterialRecord::$favorite`,
the default the Add panel lists under Favorites for everyone; an editor's
own marks never reach a text cached for every user), saying how it is
placed (`<pb-blueprint name="id"/>`), whether it is top-level only and,
for a saved one, the `entity_id` to read its body on demand, with the
looks it owns beneath it; then a GROUPS index, one line per declared
group that holds an offered block blueprint, in declaration order (id,
label, how many, the description's first sentence), then `ungrouped`
with a count for the offered ones in no declared group, and the header
says a group is read in full with `eb_read_schema`'s `group`. The block
is flat by construction: its size follows the favorites and the
vocabulary, never the library, which is designed for a hundred. A
page blueprint is on no line of the always-on prompt: it reaches the
model through a bundle's schema read, as a start. The current-page line
on a blueprint's canvas reads "the block blueprint "Card" for page nodes".

### Starting a page

A page's first document has one source, picked once, when the page is
created: its bundle's template (the default when nothing is chosen), a
page blueprint, or blank. One spine verb applies the choice,
`daedalus_blueprint.page_start` (`PageStart`), to the IN-MEMORY entity
before its first save, so every creating door lands entity and document
in one save and the seed keeps no "choice pending" state. Every door
calls it: the chooser daedalus_layout puts in front of a bundle's add
form, `page_create`'s `start`, and the chooser card's preview (a dry run,
rendered and never saved). The three starts, by what they leave on the
unsaved host: `template` leaves the override field truly empty, and the
page follows its template until its first read seeds it; `blank` writes
core's blank marker (`removeAllSections(TRUE)`: started, and empty), so
a blank page stays blank on a bundle whose template is not, with no new
field; a page blueprint's id copies the roster record's whole document,
its tree and its page-level styles, through `instantiateDocument()` with
fresh identity, NO provenance stamp (a started page is not a placement:
"Update blueprint" never appears on it) and absent fields
sample-populated, as a `system` and `admitted` batch like the seed. The
spine knows the blueprint through its roster record alone, and what a
bundle may start as is the offer asked for page scope
(`PageStart::starts()`, see "Fits"). `blank` and `template` are reserved
ids (`PageStart::RESERVED`): no blueprint may take a start's name. The
refusals, every one at path `start`: `START_ON_EXISTING_ENTITY` (a saved
entity never takes a start, any start; the sibling of
`LAYOUT_ON_EXISTING_ENTITY`), `NO_LAYOUT` (`blank` or a blueprint on a
bundle whose pages have no document of their own; `template` passes
there as a no-op), `BLUEPRINT_NOT_FOUND` (its context lists what is
available: `blank`, `template`, then the offered ids),
`BLUEPRINT_WRONG_SCOPE` (a block blueprint; the hint names
`place_blueprint`), `BLUEPRINT_EMPTY` and `BLUEPRINT_DOES_NOT_FIT`.

The AI meets the same three starts. `page_create` takes `start` (a page
blueprint id, `blank` or `template`) beside `layout`, and the bundle
decides what it takes: a bundle whose pages own their layout takes
exactly one of the two (neither and both are refused before anything is
built, the corrective naming the three starts and the authored
document), a plain bundle neither. The parameter is named `start`, never
`blueprint`, the word `eb_create_entity` uses for the entity JSON. A
start refusal comes back in the spine's shape with nothing created. A
bundle's schema read (`read_schema`) tells the model what it may start
from: a page bundle's schema carries a top-level `starts` map, the page
blueprints offered for the bundle that the AI may see (label,
description and a saved one's `entity_id`; no fragment, since a whole
page's print is heavy and `page_read` reads it on demand), in the verb's
one order, the site favorites first, then the rest, each run by label,
natural and case-insensitive (`PageStart::starts()` sorts once; the
chooser's cards and the schema take the answer as handed), present only
when at least one fits (the two reserved starts ride `page_create`'s
description). The BLOCK blueprints the bundle's host is offered, the
same answer the Add panel gives on a canvas of that bundle, reach the
model as an index, since a blueprint's entry prints its whole body and a
site designed for a hundred cannot pay a hundred prints per read:
`materials.groups` is the site's group vocabulary with how many offered
block blueprints each holds (every declared group, a zero count
included, since the vocabulary is also the ids `update_material`'s
`groups` takes, then `ungrouped` while it holds any), and
`materials.blueprints` holds the SITE FAVORITES in full. One group is
read in full on demand: `eb_read_schema`'s optional `group` (a declared
id, or `ungrouped`) puts that group's offered block blueprints in place
of the favorites, narrowed AFTER the cached schema is read (the cache
key never carries a group); an unknown id answers a corrective naming
the ids, and a bundle whose schema carries no materials refuses the
parameter. A schema read with no bundle host keeps block scope
unfiltered by fit.

## Capabilities: what an actor may do

What an actor may do to an element is one answer, asked once. The
vocabulary is closed, five capabilities
(`\Drupal\daedalus_blueprint\Capability\Capability`): `content` (field
values the host owns: the root's fields, a block's fields), `configure`
(an element's settings: plugin configuration, label, layout settings),
`style` (style entries and variants addressed at the element), `arrange`
(move, remove or duplicate the element) and `insert` (place something
into a container; asked of the destination).

**The declaration.** Every operation declares what it requires of its
targets: the built-in operations in `BlueprintOperations`
(`update_element` per property, an inline block's fields being content
and its label and configuration configure; a placement asks insert of
the container its location resolves to; the paragraph family asks
content of the parent), and every registered handler through
`OperationHandlerInterface::getRequirements()`, one entry per operation
as `Requirement` shapes (`root()`, `element()`, `styleKey()`,
`destination()`), an empty list for an operation with nothing to judge
on an element (a material gesture, a dialect op that expands into spine
operations judged themselves). The registry refuses a handler whose
declaration misses an operation, so a handler never judges ownership,
translation, workspace or access itself.

**The resolver.** `daedalus_blueprint.capability_resolver`
(`CapabilityResolver::verdict($host, $actor, $capability, $target)`,
the target an element uuid or the host's uuid for the root) intersects
the policies registered as services tagged
`daedalus_blueprint.capability_policy`: a capability is allowed when no
policy refuses it, and the first refusal in priority order explains. A
`Refusal` is a stable code, a sentence, a hint, the cause as context and,
when the actor may make the gesture elsewhere, a `Portal` naming that
host. The policies in the suite, highest priority first:

- host access (100, `ACCESS_DENIED_ENTITY`): update access on the host's
  entity, the same door routes keep from the outside;
- workspace (90, `WORKSPACE_REQUIRED`): a workspace-aware host is frozen
  while the workspaces module has no active workspace, published in
  advance so a client freezes everything with one notice (a template,
  being config, never lives in workspaces);
- ownership (50, `FOREIGN_DOCUMENT`): every element of a document the
  host does not own is refused everything, the root's structure being the
  template's; the root's content and its own style key stay the page's,
  and a field block presenting one of the host's own fields keeps that
  field's content verdict and nothing else; the portal is the owner, only
  when the actor may update it;
- translation (40, `LAYOUT_NOT_TRANSLATABLE`): on a non-default
  translation configure, style, arrange and insert are refused, since the
  document is one structure shared by every translation; content stays
  the translation's own;
- role (30, `ACCESS_DENIED_PERMISSION`, `context.permissions` naming the
  grants that open the gesture): core's Layout Builder grammar is the one
  grammar. On a content host configure, arrange and insert follow core's
  override permissions (`configure any layout`, `configure all|editable
  {bundle} {type} layout overrides`); an inline block's fields follow
  `create and edit custom blocks`; on a template everything follows
  `configure any layout` beside the display's update access. Style is
  daedalus_stylish's own policy under the same tag (`style {bundle}
  {type} elements` per styled bundle, or `style any elements`).

Adding a reason to refuse adds a policy, never a check on a surface (see
`daedalus_blueprint.api.php`).

**The door.** `BlueprintOperations::applyOperation()` asks the resolver
before dispatch, for every op of every batch, nested batches included;
the deserializer asks it at the root (content always, arrange and insert
for a `layout` key, style per `styles` entry). The actor is a batch
fact: `batchOperations()` takes an `AccountInterface` in its `actor`
option, defaults to the current user, and nested batches inherit it. A
batch declaring `system => TRUE` acts for the site rather than an actor
and is not judged (the template seed a read can trigger, the shipped
material importers); nested batches inherit the posture. Not judged
covers every check that asks about the current user: the capability
gate, field edit access, a field handler's own checks (handlers receive
the `system` option; the text handler does not ask whether the format
may be used) and core's allowed-values constraint where it holds a value
to what the current user may set (the value must still be possible).
Hydrating a value block is a load of stored values and runs its handlers
under the same option. A refusal is a
`BlueprintError` at the operation's parameter path, so the HTTP API, the
AI tools and module code answer it alike.

**Every read publishes the verdict.** `daedalus_blueprint.verdict_stamper`
(`VerdictStamper`) stamps `_verdict` on the blueprint's root and on every
container and block node of `layout` in the full, summary and element
reads (the element read being the node itself), and on any tree a
consumer prints for an actor: `can` (the five
capabilities to booleans) and `refused` (one entry per distinct refusal,
`{code, capabilities}`; the root's entries also carry `message`, `hint`
and `context` with the owner and the portal, since host-level refusals
are what a surface explains). A delivery annotation like `_element`,
never stored, ignored on echo. The AI's page print carries `data-can` on
an element the actor cannot fully edit and heads a read with the root's
refusals, so a model never interprets ownership itself.

## Design Philosophy

- **On-demand context, not system prompt stuffing.** As context grows in a
  system prompt it becomes less relevant and the LLM loses awareness of it.
  Daedalus Blueprint delivers context through tool responses (schemas, guidance,
  managed key metadata, defaults, and anatomy) only when the LLM requests it
  and only for the specific operation at hand. This keeps the LLM's working
  context focused and actionable rather than diluted by a wall of up-front
  instructions.
- **Never save implicitly.** The deserializer and all operations return unsaved
  entities. The caller decides whether to save, tempstore, or discard.
- **Protect what you don't understand.** Opaque data (Layout Builder internals)
  is preserved from the existing entity by default. Only keys explicitly
  declared as managed are exposed to AI.
- **Fail fast, fail clearly.** Two-phase validation gives early, structured
  feedback with JSON paths, error codes, and correction hints.
- **UUID-based addressing.** All nested content is addressed by UUID, not by
  position or delta, making the API resilient to reordering.
- **Operations over round-trips.** Surgical edits (update one field, add one
  element) without re-serializing the entire entity.
- **Tempstore-first editing.** Edits route through tempstore when available so
  changes can be reviewed before publishing.
- **Workspace safety by default.** Content changes require an active workspace
  when the workspaces module is enabled: the workspace policy refuses every
  op in advance (`WORKSPACE_REQUIRED`) and the storage handler asserts it
  again at commit.
- **Publish the consequence, not the cause.** A surface never reads a fact
  such as ownership and decides for itself; it reads the verdict the
  resolver computed, the same one the op door enforces, so a gate every
  surface would have to remember exists once.

## Submodules

### Daedalus Blueprint AI (`daedalus_blueprint_ai`)

Exposes daedalus_blueprint operations as AI agent function-call tools for the
Drupal AI module (`drupal/ai`). Requires `ai` and `daedalus_blueprint`.

### Daedalus Blueprint API (`daedalus_blueprint_api`)

The HTTP doors the editing client uses (`BlueprintApiController`): the
reads serve the staged working copy, and the mutation door (`POST
/daedalus-blueprint/{type}/{id}`, `daedalus_blueprint_api.mutate`)
applies a batch of the ordinary operations to it and persists, answering
the mutation-response contract.
Beside it sits the peek door (`POST /daedalus-blueprint/{type}/{id}/peek`,
`daedalus_blueprint_api.peek`, body `{operations, show, langcode?,
view_mode?}`): the same batch grammar applied to the same staged copy in
memory and persisted nowhere (no history entry, no materials delta; the
next request finds the staged copy as it was), answered as a document
whose main content is the host's own style tag and the one element
`show` names (`block:{uuid}` or `container:{uuid}`, the key the client
minted for it), with the render's attachments. The route declares
daedalus_ui's bare option, so under daedalus_ui the document is a bare
site document (the skin kept, no page chrome, none of the theme's
regions, no Edit Mode); without it, the theme's plain page around the
element. It is what the Add panel's peek shows in its frame. Access is
the mutation door's (the entity's update access and the CSRF header); a
request outside the contract answers 400, an entity or element not found
404, and a batch the spine refuses answers the mutation contract's error
shape with 422 and no document.

### Daedalus Blueprint Library (`daedalus_blueprint_library`)

Saved blueprints: the `blueprint` content entity (a page of its own, see
"The page document and its host"), discovery over module-shipped blueprint
files and published entities as one roster, the capture, push, placement
and ship gestures on the one instantiation machinery, and the blueprint
kind of the materials roster the Add panel and the AI read. Its README
tells the whole story. The library replaced an earlier saved-material
module with no update path: `daedalus_blueprint_update_requirements()`
(this module's `.install`) halts update.php and `drush updb` on a site
that still carries the retired module or its table, with "This site
predates the blueprint library; there is no update path across it:
reinstall from the recipe."

## Maintainers

- Tim Bozeman - https://www.drupal.org/u/tim-bozeman
