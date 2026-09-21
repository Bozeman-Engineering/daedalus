# Migrating a Plus Suite site to Daedalus

This is the pathway for a site running the latest releases of the old Plus
Suite modules (Layout Builder+ `lb_plus` 3.6.x, Navigation+
`navigation_plus` 2.3.x, Edit+ `edit_plus` 2.3.x, Tempstore+
`tempstore_plus` 1.0.x, with `section_library` and the Edit+ example block
modules) to move onto Daedalus 1.0.x in one hop. It ships inside the
`daedalus` package from `1.0.0-alpha1` on, in this `migrate/plus_suite/` directory:

| File | Role |
|---|---|
| `migrate.sh` | The orchestrator: runs everything below in order and stops at the first failure. |
| `translate.php` | The translator, a drush script with three phases: `audit`, `rename`, `content`. |
| `recipe.yml` | The migration recipe: the modules and config a migrated site needs and the old releases never had. |
| `mapping.tsv` | The one table of old names to new names, read by the translator and by you when porting the site's own code. |
| `PROMPT.md` | A prompt to hand a coding agent that drives this guide for you. |

It is not a normal Drupal update. Every old module is replaced by a module
with a different machine name that Drupal has never seen, so nothing about
the site's stored state carries over by itself: config, the update
registries, key_value entries and every Layout Builder section speak the old
names. The pathway translates that state into Daedalus's vocabulary first,
then lets Daedalus's own update hooks do the semantic work they already know
(layout blocks, permissions, the section library), then converts the one
block type that has a real counterpart (the old heading block becomes
Daedalus's text block).

It is best effort. What that means is listed under
[What does not carry over](#what-does-not-carry-over); read it before you
start.

## Before you start

Check, by hand:

- The old modules are at their latest releases and every update has run:
  `drush updatedb:status` reports nothing pending. The audit refuses a site
  whose `system.schema` versions are below `lb_plus` 10003,
  `navigation_plus` 10400, `edit_plus` 10001, `tempstore_plus` 11001 (the
  `floor` rows of `mapping.tsv`). Older lineages (`lb_plus` 2.x) are not
  served; update to the latest old release first, then come here.
- Drupal 11.3 or later, composer and a working drush (`drush status`
  succeeds).
- Every enabled text format (the site's `filter.format.*` config) that
  restricts HTML (the `Limit allowed HTML tags` filter) allows the `class` attribute on each of these tags it
  allows at all: `p`, `h2`, `h3`, `h4`, `blockquote`, `ul`, `ol`, `li`.
  They are Daedalus's identity vocabulary: `daedalus_edit` mints `s-*`
  identity classes onto them at every save of formatted text and refuses a
  save through a format that would strip them, so the update run would
  abort on its first save. Core's `basic_html` allows `<p>` and `<h2 id>`
  without `class`; edit the format's allowed HTML (Configuration, Text
  formats and editors) so those tags read `<p class> <h2 id class>
  <h3 id class> <h4 id class> <blockquote cite class> <ul type class>
  <ol start type class> <li class>` (the rest unchanged), `restricted_html`
  and any other restricting format the same, and export the config. The
  audit refuses a site whose formats strip the attribute, naming the
  format and the tags.
- A backup of the database and the code:
  `drush sql:dump --result-file=/path/outside/the/site/before-daedalus.sql`,
  and a clean git tree (`git status` shows nothing) so the code swap is one
  reviewable diff. `migrate.sh` takes its own dump too; this one is yours, taken before
  anything changes.
- A maintenance window. Between the composer swap and the rename phase the
  site's pages return 500 (the modules `core.extension` names are gone from
  disk), and the run is not something to do on a live site with editors in
  it.
- Rehearse on a copy. The pathway translates a database, so every database
  that must survive (production, staging) runs it itself against the swapped
  code; a config export from a migrated copy does not carry the translation
  (layouts, registries and key_value are not config). Migrate a copy of
  production first, port the site's own code there, and only then repeat
  the run on production with the ported code. Every one of those runs
  reads the section library's templates, so the module `section_library`
  stays in the committed tree until the last environment has run and
  uninstalled it; the first environment uninstalls the module by hand, the
  run's config import does it on every other, and the package leaves once,
  at the very end (step 5).

## Step 1. The composer swap (by hand)

From the project root:

```
composer remove drupal/lb_plus drupal/navigation_plus drupal/edit_plus drupal/tempstore_plus
composer require drupal/daedalus:^1.0@alpha
```

Remove only the old packages the site requires (a site without Edit+
removes three). `drupal/section_library` stays: Daedalus's section library
migration reads its templates on every environment that runs the migration,
and the module must be on disk for that, so the package stays in the tree
until the last environment has uninstalled the module (step 5 removes it
then, once). Composer refuses `drupal/daedalus` while any old package is
still locked (`drupal/daedalus conflicts with drupal/edit_plus *`), which
is why the remove comes first. Do not run `drush updatedb`, `drush deploy`
or `drush config:import` between the swap and the run below; the run does
the updates itself, in an order that matters.

## Step 2. The run

From the project root:

```
bash web/modules/contrib/daedalus/migrate/plus_suite/migrate.sh
```

(Under ddev: `ddev exec bash web/modules/contrib/daedalus/migrate/plus_suite/migrate.sh`,
and every `drush` and `composer` command in this guide runs as `ddev drush`
and `ddev composer`. `DRUSH=<path>` names another drush;
`MIGRATE_DUMP_DIR=<dir>` moves the dump.)

Read every step's output. The steps, in order, and why the order:

1. **Checks.** drush is found (`vendor/bin/drush`, else on PATH), and no
   old module is still on disk: the rename would otherwise install Daedalus
   beside them.
2. **Database dump.** `drush sql:dump --result-file=<project
   root>/daedalus-migrate-<timestamp>.sql`. If a later step fails, the
   failure message names this file and the command that restores it
   (`drush sql:drop -y && drush sql:query --file=<dump>`); the code is
   restored with git.
3. **Audit (opening).** `drush php:script
   web/modules/contrib/daedalus/migrate/plus_suite/translate.php -- audit`. Read-only.
   Lists what the site stores in the old vocabulary: the old modules'
   schema versions, every config object, key_value entry and layout section
   naming an old module, the heading components the content phase will
   rewrite, roles carrying old permission strings, and every file under
   `modules/custom` and `themes/custom` that names an old module, class,
   JavaScript root or CSS prefix (the list you port in step 3). It refuses,
   and the run stops, when a schema version is below the floor.
4. **Rename.** `translate.php -- rename`. Installs the foundation modules
   the old site never had (`daedalus`, `daedalus_blueprint`,
   `daedalus_blueprint_api`, `daedalus_blueprint_stencil`; the renamed
   modules' services need them at the next container build), then renames
   in one pass: the module and theme keys of `core.extension` (weights
   kept), the `system.schema` keys and the recorded post_update names (so
   the renamed modules are installed at their old versions and their
   pending updates are still pending), every config object's name and data
   (dependencies, third-party settings, asset paths, permission strings),
   the `users_field_data` column of the old toolbar settings field, every
   key_value value naming an old module (installed entity definitions,
   storage schema, config checkpoints), and every section in every
   `layout_section` field table, revisions included. An old module whose
   Daedalus name is no module on disk (`lb_plus_section_library`, the Edit+
   example block modules) leaves the registries instead, the way a module
   deleted from disk is removed from any site; its block types and config
   stay. Then it un-marks
   Daedalus's section library migration so the next step runs it, and
   flushes the caches. A failure inside the pass leaves the phase
   unrecorded, so a rerun repeats it after you have fixed the cause.
5. **Updates.** `drush updatedb -y`. Daedalus's own hooks now see a site
   they recognise and do the semantic work: `daedalus_layout_post_update_0001`
   to `0005` (layout blocks become nested sections, the per-display block
   icons become per-block-type icons), `daedalus_ui_update_10401` and
   `10402` (the permission renames), `daedalus_ui_update_10403` (the
   retired `place_block` default tool becomes `selector`), and
   `daedalus_blueprint_stencil_post_update_migrate_section_library` (every
   section library template becomes a stencil, best effort). Read the
   messages: the section library migration reports how many templates it
   carried. The requirements check first may warn that the site's own code
   still requires an old module ("Engineering Animation requires this
   module: unresolved dependency edit_plus"); the run answers yes, and step
   3 ports that code. Expect, too, a burst of warnings reading `The
   "inline_block:<type>" block plugin was not found`, one per component,
   from `daedalus_layout_post_update_0001`: it walks every revision of
   every layout host, and older revisions still place block types the site
   deleted long ago (the first site migrated printed 84 of them, for three
   deleted types). Core's block manager logs the warning and renders such a
   component as a broken block, which is what those revisions already were;
   nothing is changed by it, and the current revisions are unaffected.
6. **Migration recipe, or the import of its export.** On the first
   environment migrated, `drush recipe web/modules/contrib/daedalus/migrate`.
   Installs what a Daedalus site assumes and the old releases never had:
   `daedalus_stylish` with its layouts, layers and the Olivero design
   system (set active), and the `daedalus_edit_text_block`,
   `daedalus_edit_image_block` and `daedalus_edit_formatted_text_block`
   block types. It is non-strict: config the site already carries under the
   same name (the old image block type and its media field, the translated
   settings objects) is left exactly as it is, and a second apply changes
   nothing. Two `[error]` lines from the variant sync of the layouts and
   text block modules (`STYLE_VALUE_NOT_ON_SCALE`) are expected: they
   install before the design system is active, and its activation at the
   end of the recipe syncs those variants (the `row` and `button`
   variants exist afterwards). On every environment after the first, the
   run imports the exported config instead (`drush config:import -y`): the
   config the recipe creates (the block types, their fields and displays,
   the design system) exists once, on one environment, and reaches every
   other as exported config, Drupal's own rule. A second recipe run would
   create the same config entities again under new uuids, and the deploy's
   config import would then refuse them ("Entities exist of type Content
   block and Block type Text") or, past that, delete and recreate their
   fields with the migrated text in them. The run tells the two apart by
   the site's config sync directory: when the `core.extension` there
   already lists `daedalus_layout`, the export of a migrated environment
   is deployed and the run imports it. That import installs the same
   modules from the exported `core.extension`, lands the block types and
   fields under the uuids the export carries, and uninstalls
   `section_library` (the first environment did, in step 5); core refuses
   to uninstall a module while its content exists, so the run deletes the
   section library templates first (`drush entity:delete
   section_library_template -y`; the stencils carry them since the update
   step). Expect, on this path, two bursts of `[error]` lines from the
   shipped definitions sync of `daedalus_stylish` reading `... failed:
   entity_type [ACCESS_DENIED_ENTITY]: Access denied: cannot create
   daedalus_variant ...` (one per shipped variant, animation and skin, 23
   in all; the first burst as the import installs the modules, the second
   as it activates the design system): inside a config import that sync
   cannot create its entities, and the cache rebuild at the end of the run
   syncs them all (the site ends with the same 17 variants, 5 animations
   and 1 skin the recipe leaves). The import itself reports `The
   configuration was imported successfully`.
7. **Content.** `translate.php -- content`. Every old heading component
   (`inline_block:heading`) in the default revision of every layout host
   becomes a text block component (`inline_block:text`) over a new `text`
   block: the heading text (the component's label) to `field_text_text`,
   the tag to `field_text_tag`, the link to `field_text_url` when the link
   was enabled. The report counts the components per host type and the
   keys it dropped (see below).
8. **Cache rebuild.** `drush cache:rebuild`.
9. **Audit (closing).** The audit again, as the closing report. On a
   migrated site it lists zero config objects, key_value entries and
   sections naming the old suite; what remains is the custom-code file list,
   which is yours (step 3), and, on the first environment, the heading
   components inside the section library templates (a layout field the
   content phase leaves alone, as its report says) until `section_library`
   is uninstalled (step 5); on every other environment the run's import
   has uninstalled it already and the audit reports zero sections.

Each phase that changes the site records its completion in state, so if the
run stops, fix the cause and run `migrate.sh` again: completed phases report
"already done" and the run continues from the one that failed. On a migrated
site a rerun is a no-op (every phase done, no updates, the recipe clean or
nothing to import), apart from the fresh dump it takes each time.

## Step 3. Port the site's own code (by hand)

The pathway never edits code outside the `daedalus` package. The closing
audit lists every file under `modules/custom` and `themes/custom` naming
the old suite, with the literals it found per file, so you can tell prose
(a comment saying "Plus Suite", a drupal.org link) from a coupling that
breaks at runtime (a `use` of an old class, an info.yml dependency, a hook
implementation named after an old module, a JavaScript reference to an old
`Drupal.*` root, a CSS class with an old prefix). Port the couplings with
`mapping.tsv`: find each literal in the `old` column and use its `new`.
The rules are the table's header; in short, old machine names become the
new ones (`lb_plus` to `daedalus_layout`, `navigation_plus` to
`daedalus_ui`, `edit_plus` to `daedalus_edit`, `tempstore_plus` to
`daedalus_tempstore`) and everything derived from them follows by prefix:
sub-modules, hooks, plugin ids, config names, routes, services, libraries,
permissions and PHP namespaces.

The worked example, from the first site migrated: a custom module
`engineering_animation` adds fields to the inline block form through Edit+'s
block properties event. Two lines couple it to the old module.

`engineering_animation.info.yml`, the dependency:

```yaml
dependencies:
  - edit_plus:edit_plus
```

becomes

```yaml
dependencies:
  - daedalus:daedalus_edit
```

(the module `daedalus_edit` ships in the project `daedalus`, so the
project prefix changes too), and `src/EventSubscriber/BlockAnimationProperties.php`:

```php
use Drupal\edit_plus\Event\BlockPropertiesEvent;
```

becomes

```php
use Drupal\daedalus_edit\Event\BlockPropertiesEvent;
```

The event's API is unchanged (`getInlineBlockConfiguration()`, `getForm()`,
`setForm()`, and the subscriber still keys on `BlockPropertiesEvent::class`),
so the port is the two names, plus the docblock that repeats the class name.
Then `drush cache:rebuild` and load a page in edit mode.

Theme templates that switch on an old module (`page.html.twig` testing
`navigation_plus` somewhere) and CSS keyed on old prefixes (`np-` to
`daedalus-ui-`, `lb-plus-` to `daedalus-layout-`) follow the same table.
The audit does not scan the site's `vendor` or `node_modules`.

## Step 4. Review in the browser (by hand)

Log in and check, page by page for a small site or by sample for a large
one:

- Every page renders with its sections in place and every heading present.
  Headings are now text blocks; their text, tag and link are kept.
- Edit mode loads (the Daedalus toolbar appears) and a block can be edited
  and saved.
- Nested sections (the old layout blocks) show their content.
- The stencil library carries the old section library templates.
- `drush watchdog:show --severity=Error` is empty after a browse.

## What does not carry over

- **Heading alignment.** The old heading block's `horizontal_alignment`
  (left, centre, right) and its size class when it was not a tag are
  dropped; the content phase counts the drops per value. Re-author the
  alignment with Daedalus's styling.
- **Older revisions.** Only the default revision of each layout host is
  converted. Older revisions keep their old heading components (which is
  why the `heading` block type and its blocks stay) and are not a supported
  thing to revert to on a migrated site.
- **The call to action and teaser block types.** The Edit+ example modules
  `edit_plus_cta_block` and `edit_plus_teaser_block` have no Daedalus
  counterpart. Their block types stay on the site as ordinary inline block
  types, keep their content and their fields, and lose only the example
  module's form additions; the modules themselves leave the registries in
  the rename phase. `edit_plus_header_block` likewise; its `heading`
  type stays, empty of use once the content phase has run.
- **The landing page and non-LB node types.** Node types are not blocks;
  they stay as the site's own config. A site may switch to Daedalus's
  `page` type by hand.
- **The old image block's URL field.** Daedalus's image block has a
  `field_image_url`. The recipe adds the field storage and the field to
  the site's existing `image` block type but never rewrites the site's own
  form and view displays, so the field is invisible until you add it to
  `core.entity_form_display.block_content.image.default` by hand (Manage
  form display on the image block type). A site with no `image` block type
  gets Daedalus's whole, which requires a `vector_image` media type; if the
  recipe refuses that import, the site has no image block type and no
  vector media type, and it can add the media type first or leave the image
  block module out.
- **The section library's templates.** They are carried into stencils by
  `daedalus_blueprint_stencil_post_update_migrate_section_library`, best
  effort through the entity API; the templates themselves keep their old
  heading components until `section_library` is uninstalled below.
- **Site-specific keys on layout blocks.** A key the site's own code put
  on an old layout block component through the block properties event (an
  animation picker's `animation`, for one) is dropped when
  `daedalus_layout_post_update_0001` turns the component into a nested
  section: the container component carries only the section. Keys on
  other inline blocks and on the sections themselves stay.
- **Prose that names the old suite.** The rename translates every stored
  string, a block's label included, so a heading that read "Plus Suite"
  reads "Daedalus" afterwards and "Entity Blueprint" reads "Daedalus
  Blueprint"; field values (a body) are not touched. Re-author where the
  old name was meant.
- **Custom code.** Step 3, yours. A component the site's own code names
  after an old module (an SDC component `entity-blueprint`, a plugin id
  the layouts store) is renamed in the stored layouts too, so port the
  code the same way or its pages fail until you do.

## Step 5. Finish (by hand)

The section library is retired in two kinds of step, and the order between
them is load-bearing. Uninstalling the module is database state, so it
happens on every environment: by hand on the first, after its run (5.1),
and by the run's own config import on every other (5.4). Removing the
package changes the tree that every environment deploys, so it happens
once, after the last environment has uninstalled the module (5.5). Done
in the other order, the tree an environment migrates from no longer
carries `section_library`: the rename phase then stops at its first step
(Drupal's module installer refuses a site whose `core.extension` names a
module absent from disk), and past that the section library migration
would have no templates to read.

1. On this environment (the first one migrated), delete the section
   library templates (the stencils carry them now) and uninstall the
   module, in this order: core refuses to uninstall a module while content
   of its entity type exists.

   ```
   drush entity:delete section_library_template -y
   drush pm:uninstall section_library -y
   ```

   Do not `composer remove` the package yet (5.5).
2. Add `field_image_url` to the image block's form display if the site
   uses image blocks (above).
3. Export the config and commit the whole result: `drush config:export -y`,
   then `git add` the composer files, the vendor and contrib changes if the
   site tracks them, the exported config and the ported custom code, and
   commit. The tree still requires `drupal/section_library` and still
   carries the module on disk, while the exported `core.extension` no
   longer lists it (5.1 uninstalled it here); that is the state every
   other environment migrates from, and it is right. Do not commit the
   dumps (`daedalus-migrate-*.sql` at the project root; delete or move
   them).
4. On every other environment that must survive (production, staging),
   repeat from step 2 with the committed code: deploy that tree (the
   swapped code, the ported custom code, the exported config, and
   `section_library` still on disk) without running updates or a config
   import (an import before the run would uninstall `section_library` from
   the exported `core.extension` before the run had read its templates,
   and the rest of the export presumes the rename has run; a deploy hook
   that runs `drush deploy` on push must sit this deploy out). Set the
   text formats by hand as in `Before you start` (the export carries them,
   but nothing imports it before the audit, which refuses the old
   formats), then run `migrate.sh`. There the run imports the exported
   config in place of the recipe (step 2, item 6): the modules install
   from the exported `core.extension`, the block types and fields land
   under the uuids the export carries, the section library templates are
   deleted and `section_library` uninstalled, so 5.1 is done by the run
   and 5.2 and 5.3 are in the export already. Then resume the normal
   deploy: its config import finds nothing to import and its update step
   nothing pending (`drush config:status` and `drush updatedb:status`,
   both empty, are the check).
5. Once every environment has run and uninstalled the module, drop the
   package, once, and commit and deploy that as an ordinary code change:

   ```
   composer remove drupal/section_library
   ```

   Nothing runs against the section library from here on; a deploy of
   this commit removes code for a module no environment has installed.

## When something goes wrong

- The audit refused: the site is below the floor, or a text format strips
  the `class` attribute from an identity tag. Restore nothing; the site is
  untouched. Fix what it names on the old code (update the old modules and
  run their updates; allow the attribute in the format), then start again.
- A step failed: the message names the step and the dump. Read the error;
  a rename failure is a stored value the translator could not walk (the
  message names the row), an update failure is one of Daedalus's hooks, and
  either is worth reporting on the `daedalus` issue queue with the message
  and the audit's opening report. To start over, restore the dump and the
  code, fix what the message names, and run again; to carry on after fixing
  the cause in place, just run `migrate.sh` again.
- The site is a mess after the run and you want out: by hand, restore the
  dump, `git checkout` the code, `drush cache:rebuild`. The pathway wrote
  nothing outside the database and the dump.
