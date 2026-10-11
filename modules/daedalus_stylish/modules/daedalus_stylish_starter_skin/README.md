# Starter design system (daedalus_stylish_starter_skin)

A blank canvas to build a design system off: the `starter` design
system as install config (`enforcement: open`, its own skin, this
module as package) and one skin that mints a neutral placeholder token
on every scale the suite declares, with base-tag defaults that
reference those tokens and nothing else. No guide fragment, no CSS, no
templates. It is not part of any recipe; a site enables it when it
wants to start from nothing rather than from a finished look.

Why a placeholder on every scale: the suite's own bindings
(`daedalus_stylish.page_builder.yml` `site.constraint_defaults`) bind
font-family, font-size, font-weight, line-height, padding, gap and
border-radius to the font, text, weight, leading, space and radius
scales on every site, and a bound scale minting nothing is an empty
row with no fallback by design. The starter is the smallest skin that
keeps every promise the suite makes, so every basic row offers
something the moment it is active, and tracking, transition and layout
are minted too so no declared scale is blank.

## Enable

```
drush en daedalus_stylish_starter_skin
drush daedalus-blueprint:design-system:activate starter
```

`drush en` installs the package as a dormant unit; activation syncs
the skin from disk under the system's law and makes it the skin the
system wears. Switch back with `... activate olivero`. After editing
the skin file, `drush daedalus_stylish:sync daedalus_stylish_starter_skin`
lands the change.

## Building a design system off it

Building a system is authoring on the canvas: activate a starting
point, make the look in Edit Mode's Materials place with the page as
the preview, and ship the result to disk. Nothing is re-valued in YAML
by hand any more; the skin file is the machine's to write.

**A site that never ships** (a tenant) needs no module of its own. With
`starter` active, the skin card in the Palette offers "Copy as my
skin": the copy is unlocked and a member of the active design system
(`starter` here; a copy always lands in the system the site is
running, whatever its source belonged to); `starter` is packaged and
names its own default, so the copy wears nothing until you choose it,
then author it directly. A tenant that keeps the shipped skin instead
extends and overrides it in the skin's site layer, and every update to
this module shows through wherever the site changed nothing.

**A package of your own** (a developer's site) starts from a unit of
your own, because a design system ships into its package and
`starter`'s package is this module. The road:

1. Create the design system at `/admin/appearance/design-systems` (id,
   label, the identity paragraph the AI is told the system is, `open`,
   no default skin) and activate it (`drush
   daedalus-blueprint:design-system:activate SYSTEM`). It has no skin
   yet, so nothing can be minted on it and the skin card says so.
2. In Edit Mode, open the Palette's skin card and copy the starter's
   skin (the copy's source select offers every skin on the site; pick
   `Starter`). The copy lands in your system, and because your unit has
   no package and no default skin yet, it becomes the unit's
   `default_skin` and the site wears it at once. A later copy into the
   same unit is only a member; choose it to wear it.
3. Author (below), then ship. The package module does not exist yet;
   the ship creates it.

Two alternates reach the same place:

- With the assistant: after step 1, ask it for the system's first skin
  from the starter's tokens (`eb_create_entity daedalus_skin` with the
  token records and `design_system` naming your unit; the skins skill
  teaches it), then set the unit's default skin on its form. The AI
  cannot copy or choose a skin; those are a person's gestures.
- By hand: copy this module under your own name (the info file,
  `config/install`'s design system with the ids renamed, and the skin
  saved as the per-id form `MODULE.daedalus_stylish.skins.SKIN.yml`, so
  the ship can rewrite it; the single file is a person's and a machine
  never writes it), enable it, activate your system, and lift the distro
  lock on the development site (`$settings['daedalus_blueprint_developer']
  = TRUE`, then `drush cr`) so skin writes land in the package layer
  the ship reads.

Then author: re-value tokens in the Palette's skin section (live on the
canvas, every condition the scale's shape allows), give each token the
description a designer would want, mark the tokens the basic rows
should not lead with `offered: false` (site-wide stop curation is the
token's flag, never a guide list), enter "Edit skin…" from any row the
skin supplies to change that scope's defaults, and mint variants,
swatches, animations and fonts as members. Stay `open` while the look
is being found (the guide shapes offers, a typed literal still
commits).

Then ship:

```
drush daedalus-blueprint:design-system:ship SYSTEM [MODULE]
```

writes the unit as install config, every member as a per-id file
(fonts with their files under `assets/fonts/`) and the system's content
blueprints into the module, creating and installing the module when it
does not exist. Commit the module; a sync of it reports `unchanged`.

As the look settles, add a `MODULE.page_builder.yml` carrying only your
deltas over the suite's bindings (the `token_contract` naming the
tokens your theme's CSS and your stops rely on; per-element `stops:`
curation in block type files, contract names only; the color and
letter-spacing bindings the suite leaves to packages; extra `allowed:`
literals; `null` withdrawals), and switch the system's enforcement to
`guarded` or `strict` once every value on the site is a token. Law is
authored in files and shown in the inspector's Law tab.
`daedalus_stylish_olivero_skin` is the finished open shape of that path and
`daedalus_stylish_guide_example` the finished strict one, with
per-block-type and per-layout files, variants, swatches, animations and
a blueprint as members; grow toward whichever posture the site wants.
