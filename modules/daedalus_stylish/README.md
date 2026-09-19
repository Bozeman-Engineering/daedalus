# Daedalus Stylish

Style anything on the page. Browser widgets and AI share one style model,
validated against your style guide, and the site's look itself (design
tokens, site element defaults) lives as data. Every style write, from the
inspector, the code view, or an AI batch operation, passes through one
validator and is judged against the vocabulary of the address it styles, so
"freedom within the style guide" is a server guarantee rather than a UI
convention.

## Features

- Style inspector sidebar: a properties panel that follows the current
  selection, in three modes over the same style map (basic controls declared
  per block type, advanced property groups, a CSS code view).
- Style anything: entities, sections, regions, fields, blocks, and declared
  sub-elements ("parts"), with conditions (hover and other pseudo-classes,
  container width bands, reduced motion).
- Materials: named, reusable styling vocabulary as one roster. Variants
  (an element's styles as a named look), swatches (the palette), animations
  (keyframes as a material), and the skin's design tokens.
- Skins: the site's look as data, one `daedalus_skin` entity holding token
  values and site element defaults.
- Design systems: every material is a member of the design system its
  package ships (or global), and the site activates one at a time.
- Style guide enforcement: developer-authored YAML declares what is stylable
  per element kind and block type, with constraints and token curation.
- Shipped materials: a module ships skins, variants, swatches and animations
  as YAML files that sync onto the site by hash, and ships the site's edits
  back to disk (see "The disk lane" below).
- Cascade-winning delivery: authored styles beat theme CSS through cascade
  layers, with no specificity hacks.

## Requirements

- Drupal 11.
- `daedalus_blueprint` (the materials spine, the JSON dialect, the design
  system unit) and `daedalus_ui` (the page builder declaration spine).

## Installation

Install as you would normally install a contributed Drupal module. Visit
https://www.drupal.org/docs/extending-drupal/installing-modules for further
information.

```bash
composer require drupal/daedalus
drush en daedalus_stylish
```

Daedalus Stylish is part of Daedalus, an AI powered page builder built on Layout
Builder; the `daedalus` recipe installs it with its siblings.

## Shipped design systems

Daedalus Stylish's own style guide binds seven properties on every site
(`font-family`, `font-size`, `font-weight`, `line-height`, `padding`, `gap`
and `border-radius`, to the font, text, weight, leading, space and radius
scales), the rule being that the suite binds a property only when every
shipped skin mints its scale; a design-system package layers its own
deltas above (stop curation, color and letter-spacing bindings, extra
literals, withdrawals). Three sub-modules ship a design system as install
config plus a skin that keeps every one of those promises:

- `daedalus_stylish_olivero_skin`: the open reference. Core's front-end look; the
  guide shapes offers and never refuses. The `daedalus` recipe activates
  it.
- `daedalus_stylish_guide_example`: the strict reference. A fully constrained,
  brand-neutral site as data (skin, guide, per-block-type and per-layout
  files, variants, swatches, animations, a stencil), the authoring
  reference for a designed site and the fixture strict-posture tests
  activate.
- `daedalus_stylish_starter_skin`: the blank canvas. One open system and one skin
  minting a neutral placeholder token on every scale the suite declares,
  with token-only base-tag defaults and no guide fragment, for a site to
  build its own design system off. Not part of any recipe; its README
  walks through copying it, re-valuing the tokens and growing toward
  guarded or strict.

```bash
drush en daedalus_stylish_starter_skin
drush daedalus-blueprint:design-system:activate starter
```

`drush en` installs a package as a dormant unit; activation makes its
skin the active one and re-syncs every enabled module's shipped
definitions under the system's own law: the package's own materials
land, and so does any global the previous law refused. A fresh install's
batch waits for the installer to finish (silently, nothing refused),
and the recipe's activation lands every enabled module's definitions;
an install that activates no system lands its globals at the site's
first cache rebuild. Switch back with `... activate olivero`.

## The disk lane

A module ships daedalus_stylish materials as YAML files at its root, keyed by machine
name. The lane has two directions with two words:

- **Sync** is disk to site. Every enabled module's shipped definitions
  land on the site as content entities on a cache rebuild, on module
  install, and by hand with `drush daedalus_stylish:sync [MODULE]`.
- **Ship** is site to disk. `drush daedalus_stylish:ship MODULE` writes the site's
  current entities back into the module's files; `drush
  daedalus-blueprint:stencil-ship` (daedalus_blueprint_stencil) does the same
  for a placed composition.

### The files

Each kind reads two file forms, merged:

| File | Role |
|------|------|
| `MODULE.daedalus_stylish.skins.yml`, `MODULE.daedalus_stylish.variants.yml`, `MODULE.daedalus_stylish.swatches.yml`, `MODULE.daedalus_stylish.animations.yml` | The hand-authored single file per kind: comment-heavy, written by a person, never by a machine. |
| `MODULE.daedalus_stylish.<kind>.ID.yml` (e.g. `MODULE.daedalus_stylish.variants.card.yml`) | The per-id file: the machine-written form, one file per shipped id beside the single file. This is what the ship lane writes. |

A machine name shipped in two of one module's files is a developer error
and fails the sync. Per-id files are always written whole, so an entry the
ship no longer includes leaves disk.

A package's per-id lane writes two files today, one per kind that has a
shipper: `MODULE.daedalus_stylish.skins.ID.yml` and `MODULE.daedalus_stylish.variants.ID.yml`.
Swatches and animations sync from disk but have no ship lane yet. The
stencil lane writes `MODULE.daedalus_blueprint.stencils.ID.yml` plus
`MODULE.daedalus_stylish.variants.ID.yml` for the variants the composition wears.

Keys a shipper does not own are carried from the existing entry when a file
is rewritten (the variants file's `stencil:` key belongs to the stencil
module; daedalus_stylish never learns what it means).

### Sync: `drush daedalus_stylish:sync`

The importer stamps each shipped entity with its provider module and a hash
of the shipped data, and that one hash answers both sync questions: did the
site modify the entity, and did the disk defaults change. One line per
definition, `MODULE: KIND NAME outcome`:

```
my_brand: skin dawn unchanged
my_brand: variant card_blue updated
my_brand: variant hero_title kept (site-edited; new defaults on disk)
```

Outcomes are `created`, `updated` (disk moved, the site had not), `unchanged`
(disk did not move, whether or not the site edited the entity: a sync never
carries a site edit to disk, only a ship does), `kept` (the site edited the
entity AND disk moved; the site's edit wins and the new defaults wait), and
`failed` (printed last with path, code, message and hint; non-zero exit). A
module packaging a design system that is not the active one is deferred whole;
its definitions land at the activation, which re-syncs every enabled module
under the incoming law.

```bash
# Every enabled module (the same sync a cache rebuild runs).
drush daedalus_stylish:sync

# One module, every outcome on the terminal.
drush daedalus_stylish:sync my_brand
```

### Ship: `drush daedalus_stylish:ship`

The other direction, for the package author working on a development site
(see "The distro lock" below). With no names, every per-id file of MODULE
is rebuilt from the current entities. With names, the named site materials
are adopted into MODULE.

```bash
# Rebuild every per-id file of my_brand from the site's entities.
drush daedalus_stylish:ship my_brand

# Adopt a site variant and a site skin into my_brand. Only the named
# materials ship; a new name lands in MODULE.daedalus_stylish.<kind>.NAME.yml.
drush daedalus_stylish:ship my_brand --variant card_blue --skin dusk

# The identical report, with no file written and no entity touched.
drush daedalus_stylish:ship my_brand --dry-run
```

One line per entry in the sync command's grammar:

```
my_brand: skin dusk created
my_brand: variant card_blue updated
my_brand: variant card_plain unchanged
my_brand: variant retired_look dropped
my_brand: variant hero_title modified; edit by hand in
  my_brand.daedalus_stylish.variants.yml
```

Outcomes are `created`, `updated`, `unchanged`, `dropped` (the entity is
gone, so the entry leaves the per-id file), and `modified` for an entry of
the hand-authored single file the site edited: it is reported, never
written, because a machine never touches a person's file. A name shipped by
another module or by another file of the target module, or a name with no
entity on the site, is refused (`SHIPPED_<KIND>_CONFLICT`,
`SHIPPED_<KIND>_ABSENT`); refusals print last and fail the run while
everything else ships.

After a ship the site is consistent with disk: every shipped entity is
re-stamped with the module as provider and the written payload as its
source hash, and published, so `drush daedalus_stylish:sync MODULE` right after
reports `unchanged` for every name.

### The distro lock

Shipped variants, swatches and animations are read-only on a site: the
package's files are their one author, and a site that wrote into them would
drift away from every future sync. The site forks a material instead ("Copy
as my variant") or reverts toward the disk. Skins carry no lock; a
site-edited shipped skin is kept by sync and reaches disk only through
`daedalus_stylish:ship`.

The package author works the other way round: the site is the canvas and
drush carries the result back into the module. On such a development site,
lift the lock in `settings.php`:

```php
$settings['daedalus_blueprint_developer'] = TRUE;
```

The flag is read by daedalus_blueprint's `daedalus_blueprint.distro_lock` service,
the one fact every shipped-material gate in daedalus_stylish consults: the variant,
swatch and animation managers (push, details and value edits, revision restore,
delete), the blueprint lane field handlers and their guidance text, and the
materials provider's `updatable` record flag that the inspector's library and
push bar read. It is a setting rather than a permission because writable module
directories are an environment property: a development site needs no role setup,
and a production administrator cannot lift a lock that protects production.
Roster records are cached, so rebuild caches (`drush cr`) after flipping it.

The author's flow: change a material on the site (override a placement and
push into its variant, or edit the variant in the library), save the page,
then `drush daedalus_stylish:ship MODULE` for a variant or skin change, or
`drush daedalus-blueprint:stencil-ship ENTITY_TYPE ID ROOT_UUID MODULE` when
the composition itself changed. Both read the saved page, never an unsaved
canvas.

## Other drush commands

```bash
# Scaffold style guide YAML for a block type from the classes its markup emits.
drush daedalus_stylish:discover BLOCK_TYPE

# Rebuild the usage tables from the saved default-revision style stores.
drush daedalus_stylish:variant-usage:rebuild
drush daedalus_stylish:swatch-usage:rebuild
drush daedalus_stylish:animation-usage:rebuild
```

## Maintainers

- Tim Bozeman - https://www.drupal.org/u/tim-bozeman
