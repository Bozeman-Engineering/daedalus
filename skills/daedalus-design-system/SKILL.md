---
name: daedalus-design-system
description: >-
  Customize a Daedalus site's look and starting material from disk: the
  design-system package module (skin, style guide, variants, blueprints,
  fonts) and the site's default content (site chrome, starter pages, demo
  media). Use when asked to change colors, type, spacing or dark mode, add or
  edit a variant or look, add a blueprint or starter page, tighten or loosen
  the style guide, ship canvas work to disk, sync a package, or export
  default content for a profile or recipe.
---

# Daedalus design system and default content

This skill drives Daedalus from the developer's side: files in a
module, drush, and the install path. Page composition on the canvas is
the editor's and the in-product assistant's job; this skill never
writes a page document by hand except as shipped material.

The module READMEs are the truth and win on conflict. This file tells
you which one to open for what, and the workflows and traps the READMEs
spread across a thousand lines. Paths below are relative to the
`daedalus` module directory.

## The mental model

- **A look is a module, never a theme.** A site's look is a design
  system packaged in one module (the "package"): the unit as install
  config, `config/install/daedalus_blueprint.design_system.ID.yml`
  (`package: MODULE`, `default_skin`, `enforcement`, and the
  `description` the AI is told the system is), plus its members as YAML
  files at the module root. Themes carry mechanics only; `daedalus_theme`
  has no look and is not sub-themed for a look.
- **One system is active.** `daedalus_blueprint.settings`
  `active_design_system` names it. A package's members are offered only
  while its system is active; a module that packages no system ships
  global materials, offered under every system.
- **Code and data.** Token scales, the token contract, bindings, stops
  and grants are LAW, authored in the `MODULE.page_builder*.yml` family.
  Token values, animations, fonts and element defaults are DATA, on the
  skin. Variants and blueprints are content entities synced from files.
- **Two directions, two words.** Sync is disk to site
  (`drush daedalus_stylish:sync [MODULE]`, also on every `drush cr` and
  module install). Ship is site to disk
  (`drush daedalus-blueprint:design-system:ship SYSTEM [MODULE]`).
- **Human files and machine files.** `MODULE.daedalus_stylish.KIND.yml`
  (one file per kind) is hand-authored and a machine never writes it.
  `MODULE.daedalus_stylish.KIND.ID.yml` (one file per id) is what the
  ship writes and rewrites whole. The same machine name in two files of
  one module fails the sync. Pick one form per kind per module.
- **Role tokens, never element tokens.** `--text-title`, `--color-accent`;
  never `--h2-size`. Elements differ through skin defaults (what a bare
  h2 looks like), per-element `stops:` curation (what it may change to)
  and which tokens are minted, with no new grammar.
- **A shipped material is read-only on a consuming site.** The site
  forks it ("Copy as my skin", "Copy as my variant") or overrides a
  shipped skin in the skin's site layer. The package author's
  development site lifts the lock (below) so canvas writes land in the
  package layer the ship reads.

## The reference packages

Copy from these, in `modules/daedalus_stylish/modules/`:

| Module | Posture | Use it for |
|--------|---------|------------|
| `daedalus_stylish_starter_skin` | `open`, blank | The smallest valid skin: one neutral token on every scale the suite binds, token-only base-tag defaults. Its README is the build-a-system walkthrough. |
| `daedalus_stylish_olivero_skin` | `open` | A finished open system (core's front-end look). What the `daedalus` recipe activates. |
| `daedalus_stylish_guide_example` | `strict` | The finished constrained shape: law file, per-block-type and per-layout files, a skin with palette and motion, variants, one blueprint. Its comments are the authoring reference. |

Enforcement is the unit's own property: `open` (law shapes the offer,
never refuses), `guarded` (authored bindings are law, `var()` must name
a minted token, unbound properties pass a grammar check), `strict`
(every binding is law, undeclared properties are rejected). Stay open
while the look is being found.

## The file roster

| File | Lane | Author |
|------|------|--------|
| `config/install/daedalus_blueprint.design_system.ID.yml` | The unit | Hand, or the ship |
| `MODULE.page_builder.yml` | Site-wide law: `site.token_scales`, `site.token_contract`, `site.constraint_defaults` deltas over the suite's bindings, prose tags, `variant_axes` per element kind | Hand |
| `MODULE.page_builder.block_type.BUNDLE.yml` | Per block type: `stops:` curation, `controls`, grants, `variant_axes`, `default_variants` | Hand |
| `MODULE.page_builder.layout.LAYOUT_ID.yml` | Per layout (`daedalus_stylish_container` is the one section layout): birth defaults, axes | Hand |
| `MODULE.daedalus_stylish.skins.yml` | Skin entries: `tokens:` (bare value or `{value, description, offered}` per scale and condition), `animations:`, `fonts:`, `defaults:` (`base:h1`, `kind:container`, ...) | Hand (single file) |
| `MODULE.daedalus_stylish.skins.ID.yml` | Same, one skin per file, records not bare values | The ship |
| `MODULE.daedalus_stylish.variants.yml` | Variant entries: `label`, `scope` (`kind:container`, `block:text`, ...), `axis`, `weight`, `description`, `styles` keyed by relative selector, optional `blueprint:` owner | Hand (single file) |
| `MODULE.daedalus_stylish.variants.ID.yml` | Same, per id | The ship |
| `MODULE.daedalus_blueprint.blueprints.ID.yml` | One blueprint: envelope (`label`, `description`, `icon`, `scope`, `subject`, `groups`, `favorite`, `ai`) plus `layout` and `styles` | Hand or the ship |
| `assets/fonts/*.woff2` and license texts | Font faces a skin's `fonts:` tier names by basename | Hand or the ship |
| `assets/blueprints/*.svg` | Blueprint palette icons | Hand |

Every material entry carries a `description`. The AI reads it as the
when-to-reach-for-it line, so write it for a designer, not a parser.

## Workflows

### Start a package

Two roads reach the same module. Decide which by who authors values.

**By hand** (a developer who wants files from the first minute):

1. Copy `daedalus_stylish_starter_skin` under the client's module name.
   Rename the unit id, `package`, `default_skin` and the skin's machine
   name. Keep the skin as the single-file form if a person will keep
   editing it, or rename it to the per-id form
   (`MODULE.daedalus_stylish.skins.SKIN.yml`) if the ship will own it.
2. `drush en MODULE`, then
   `drush daedalus-blueprint:design-system:activate ID`.
3. Read the sync report (below). Every entry should be `created`.

**On the canvas** (a designer finds the look in Edit Mode):

1. Create the design system at `/admin/appearance/design-systems` with
   no package and no default skin, and activate it.
2. In Edit Mode's Design system place, copy the starter's skin into the
   new system; it becomes the unit's default skin.
3. Lift the lock on the development site and rebuild caches:

   ```php
   $settings['daedalus_blueprint_developer'] = TRUE;
   ```

4. Author, save, then
   `drush daedalus-blueprint:design-system:ship ID MODULE --dry-run`,
   then without `--dry-run`. The ship creates and installs the module
   under `modules/custom` when it does not exist.

Once a package exists, keep the road: a hand-authored kind is edited in
its single file and synced; a shipped kind is authored on the canvas
and shipped. A ship reports a site-edited entry of a single file as
`modified` and never writes it; that is the signal a person has to edit
the file.

### Change the look by hand

1. Edit the skin file (a token's value per condition, a default, an
   animation, a font face) or the variants file.
2. `drush daedalus_stylish:sync MODULE`.
3. Read the report. `updated` is the expected outcome. `kept` means the
   site edited that entity and your new defaults are waiting; a skin is
   never kept (its package layer always lands; the site layer rides on
   top untouched). `failed` prints a path, a code and a hint; fix the
   file and sync again. A line reading `landed, withheld under the
   current law` is not a failure: the entity landed and the roster
   withholds it until a skin mints what it names.

Check the result in Edit Mode: the Skin tab for values, the inspector's
Law tab for what a binding allows, the page for the rendered effect.

### Change the law

Law is always hand-authored. Add or edit `MODULE.page_builder.yml` and
the per-block-type and per-layout files, then `drush cr`. Rules worth
knowing before editing:

- Maps merge across layers (suite, package, theme); lists replace
  wholesale. `variant_axes` is a list, so re-list the universal axes
  beside the one you add.
- A node's constraint block for a property replaces the site default
  whole on that node, so a curated property restates its scale and
  `allowed:` literals beside its `stops:`.
- `stops:` curate the offer per element, never legality, and name
  contract tokens only. Site-wide curation is the token's `offered`
  flag on the skin, never a guide list.
- `null` withdraws a control or grant inherited from below.
- A package's declarations layer only while its system is active.
  Switching systems switches law.

### Add a variant

A variant is one look on one axis for one element scope. By hand, add
an entry to the variants file with `scope`, `axis` (the built-in `style`
axis or one the law declares), `styles` keyed by relative selector
(`''` for the element itself, `::{selector}` for a part), and a
description. On an animation axis the variant names a skin animation
with literal timings and carries its own `reduced-motion` unset, since
the topmost setter wins. Sync. A variant the current law refuses still
lands and is withheld, not failed.

On the canvas: override a placement, push into a variant, save, ship.

### Add a blueprint

A blueprint is reusable page material that is a page of its own,
copied on placement. Two scopes:

- `scope: block` is a section or block composition offered in the Add
  panel and to the AI. Exactly one root container.
- `scope: page` is a starter page: a whole document a new page may
  start from (the chooser in front of a page's add form, and the AI's
  `start`). Ids `blank` and `template` are reserved.

`subject` is `type:bundle`, the page kind the material is for. A
`design_system:` key names membership; absent, the module's own system;
`~` makes it global.

By hand, author the file after `daedalus_stylish_guide_example`'s card
blueprint: a dressed placement receives no birth defaults, so every node
spells its whole wear in `styles.''.variants`. Block fields go in
`block_fields`; a field block names its `field`. A shipped file cannot
carry a site-local id, so leave media references out and let placement
sample-populate them. Rebuild caches; an invalid file is loud at rebuild
(`INVALID_BLUEPRINT` with the rule it broke).

On the canvas, compose it on its own canvas (Content > Blueprints) or
capture it from a page, then
`drush daedalus-blueprint:blueprint-ship BLUEPRINT MODULE --dry-run`.
The ship writes the blueprint file plus a per-id variants file for the
looks the blueprint owns, and deletes the content entity.

### Tighten the posture

When every value on the site is a token: add the `token_contract` to
`MODULE.page_builder.yml` (the tokens the stops and any theme CSS rely
on), curate `stops:` per block type, add the color and letter-spacing
bindings the suite leaves to packages, then set `enforcement: guarded`
or `strict` on the unit and re-import or `drush cr`. The Law tab shows
what each posture refuses before you switch.

## Default content

Default content is the material a fresh site starts with: the site
header and footer, a welcome or starter page, a demo media item so a
placed image block arrives with a picture. It is core's default content
format, the YAML `content:export` writes, imported from wherever the
site's install path reads it. A recipe reads its `content/` directory;
a profile that imports content reads its own; the format is the same
and this skill takes no position on which carries it.

The routing question for every piece of starting material: **does the
site edit it in place, or copy from it?**

| The site... | Ship it as | Example |
|-------------|------------|---------|
| Edits it in place on its own canvas | A content entity (default content) | The header and footer: `site_chrome` blueprint entities the theme's frame shows by reference on every page |
| Lands on it and edits it as a page | A content entity | The welcome page node, its menu link, `page.front` pointing at its alias |
| Copies it fresh each time | A blueprint file in the package | A card row, a hero, a starter page document |
| Needs it to exist for sampling | A content entity | One published image media item; `field_sample_value` picks the only published media for a new image block |

Export with core's command from the Drupal root, with dependencies when
the entity references files or media:

```bash
php core/scripts/dr content:export node 42 --with-dependencies \
  --dir=/path/to/content
php core/scripts/dr content:export blueprint 7 --with-dependencies \
  --dir=/path/to/content
php core/scripts/dr content:export media --bundle=image --dir=/path/to/content
```

Traps, each already bitten once in `daedalus_recipe`'s ingredients:

- Import skips an entity that already exists, so a re-apply never
  clobbers a site that edited its header, and never updates it either.
- Config applies before a recipe's own content, but a sub-recipe applies
  completely first. Content another piece of config points at (a front
  page alias named by `page.front`) lives one level down so it exists in
  time.
- Content needs its bundles, the styling layer, the block types it
  places and the navigation module's menu block to exist first. Declare
  those dependencies on the ingredient, profile or module that carries
  the content.
- A `site_chrome` blueprint's subject is the page bundle its canvas
  renders against; that bundle must exist before the chrome imports.

To see a page's document the way the spine does, before deciding how to
ship it: `drush daedalus-blueprint:serialize node 42 --summary`.

## Reading the reports

Sync, one line per definition, `MODULE: KIND NAME outcome`: `created`,
`updated`, `unchanged`, `kept` (site-edited and disk moved; the site
wins), `failed` (last, with path, code and hint; non-zero exit).

Ship, same grammar: `created`, `updated`, `unchanged`, `dropped` (the
entity is gone, the entry leaves the per-id file), `modified` (a
single-file entry the site edited; reported, never written). Refusals
(`SHIPPED_<KIND>_CONFLICT`, `SHIPPED_<KIND>_ABSENT`) print last and fail
the run while the rest ships. After a ship, a sync of the module reports
`unchanged` for every name; anything else means the two disagree.

## Gotchas

- A package whose system is not active is deferred whole: its sync
  prints nothing landed, and nothing is wrong. Activate the system.
- Skins wait for the default theme. While `system.theme` names a theme
  that is not installed, every module's skins are deferred and land
  when the theme does.
- Scale ids are the CSS prefix (`--text-*`), the AI's applicability
  signal and the law's key at once. Prefixes must not equal or
  hyphen-extend one another.
- A skin must mint something on every scale the suite binds (font,
  text, weight, leading, space, radius); a bound scale with nothing
  minted is an empty row, by design, with no fallback.
- Never edit a per-id file by hand while the canvas road is in use; the
  next ship rewrites it whole. Edit the site and ship.
- The ship reads the saved site, never an unsaved canvas.
- A package cannot be uninstalled while its system is active or still
  has members; the active system cannot be deleted.
- Roster records are cached: `drush cr` after flipping the developer
  lock or editing law.
- The page's top-level container is a section; a nested one is a
  container. Use those words in descriptions and labels.

## Where to read deeper

| Question | Read |
|----------|------|
| The skin's four tiers, the two layers, which skin a site wears, canvas authoring | `modules/daedalus_stylish/README.md`, "Skins" |
| Sync and ship outcomes, the file forms, the distro lock | `modules/daedalus_stylish/README.md`, "The disk lane" |
| Selectors (`::part`, `:has()`, relation selectors) and conditions in a variant's `styles` | `modules/daedalus_stylish/README.md`, "Selectors" and "Conditions" |
| The `page_builder.yml` family, layering, the alter hook | `modules/daedalus_ui/README.md`, "Declarations" |
| The law file's sections, with the reasoning per section | `modules/daedalus_stylish/modules/daedalus_stylish_guide_example/*.page_builder*.yml` comments |
| Design system units, activation, drush | `modules/daedalus_blueprint/README.md`, "Design systems" and "Drush commands" |
| The blueprint file envelope and its rules, blueprint-owned variants | `modules/daedalus_blueprint/modules/daedalus_blueprint_library/README.md`, "Discovery and the file envelope" |
| Page starts (template, blank, page blueprint) | `modules/daedalus_blueprint/README.md`, "Starting a page" |
| The document shape a blueprint's `layout` must follow | `modules/daedalus_blueprint/README.md`, "The document's one shape" |
| Why themes carry no look, the site frame, when to sub-theme | `../../themes/contrib/daedalus_theme/README.md` |
| How the shipped recipe composes chrome, front page and demo media | `../../../recipes/daedalus_recipe/ingredients/daedalus_site_chrome`, `daedalus_front_page`, `daedalus_demo_content` |
