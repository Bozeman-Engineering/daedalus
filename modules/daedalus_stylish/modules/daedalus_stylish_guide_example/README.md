# Guide Example design system (daedalus_stylish_guide_example)

The strict reference design system: a fully constrained site as data on
daedalus_stylish, in one module, no CSS, no templates. It is the second of the
two reference systems daedalus_stylish ships. `daedalus_stylish_olivero_skin` is the open
one (the styling recipe's finished look; its guide shapes offers and
never refuses), this is the strict one (every binding is law, the world
of stylable properties is closed, every `var()` must name a minted
token or a published swatch). Read it as three things: the contrib
authoring reference for a designed site (each file models the
recommended shape and says why), the fixture strict-posture scenarios
activate (a test that needs law activates this system rather than
forcing a mode onto a guide not written for it), and the live vehicle
for feeling the strict editing UX on a development site.

The look itself is deliberately neutral: an ink-and-amber palette, a
serif voice for prose, a sans-serif voice for headings, pill buttons,
rounded imagery, restrained motion. Nothing here is a brand; a site
that copies the package edits the skin's values and every reference
follows.

| File | Role |
|------|------|
| `config/install/daedalus_blueprint.design_system.guide_example.yml` | The design system itself: the unit the skin, guide, materials and blueprint are members of, the thing the site activates, the identity paragraph the AI is told the system is, and `enforcement: strict`. |
| `daedalus_stylish_guide_example.daedalus_stylish.skins.yml` | The skin: token records (light and dark, banded; `offered: false` on the tokens the rows should not lead with) on every declared scale, and site element defaults. The DATA half; on a site it is the package layer a tenant's site layer sits over. |
| `daedalus_stylish_guide_example.page_builder.yml` | The style guide: the token scales, the token contract (the names the skin must mint and the stops may name), the package's bindings as deltas over the suite's, the prose tags it opens, the container axes (each with its description and the properties it owns), the motion grants, the in-body ramp on the instances lane. The LAW half. |
| `daedalus_stylish_guide_example.page_builder.layout.daedalus_stylish_container.yml` | Birth defaults for page bands (surface, rhythm). |
| `daedalus_stylish_guide_example.page_builder.block_type.text.yml` | Text block stops; the link part as the site's button; the `button` variant axis and what it owns; element-bound controls. |
| `daedalus_stylish_guide_example.page_builder.block_type.image.yml` | Image part constraints; Rounded as the birth default. |
| `daedalus_stylish_guide_example.page_builder.block_type.formatted_text.yml` | Withdraws the Position control from prose. |
| `daedalus_stylish_guide_example.daedalus_stylish.variants.yml` | The component library: bands, rhythms, cards and tiles, text looks, buttons, image frames, motion. Every look carries a description saying what it is and when to reach for it. |
| `daedalus_stylish_guide_example.daedalus_stylish.swatches.yml` | The palette: three backgrounds beyond the role tokens, each with a description. |
| `daedalus_stylish_guide_example.daedalus_stylish.animations.yml` | Three keyframe materials paired with animation-axis variants. |
| `daedalus_stylish_guide_example.daedalus_blueprint.blueprints.card.yml` | The composition: a band of three cards, as a page-language fragment. |

## Enable

The module is not part of any recipe. On a site installed from
`daedalus` or `daedalus_ai` (which activates olivero):

```
drush en daedalus_stylish_guide_example
drush daedalus-blueprint:design-system:activate guide_example
```

`drush en` installs the package as a dormant unit on a site that still
wears olivero: the system exists, its members wait. Activation is what
lands and brings the package into force: it syncs the package's skin,
variants, swatches and animations from disk under the package's own law
(the guide's declarations layer above the suite's only while its system
is active, so the skin's own scales and the variants on its axes are
refused while it is dormant and land on the switch), the Guide Example
skin becomes the active skin, and every member joins the roster. The
site has no skin pointer to set: activation names a design system
(`daedalus_blueprint.settings active_design_system`). Switch back with
`... activate olivero`; both directions leave the other system whole
(its members leave the roster, the AI's brief and the guide's law, and
return on the next switch). Every part of this module is a member of
`guide_example` by default (a file-shipped definition belongs to its
module's shipped system unless it says `design_system: ~`).

After editing a shipped file, re-sync the module (the same sync a cache
rebuild runs, with every outcome on the terminal):

```
drush daedalus_stylish:sync daedalus_stylish_guide_example
```

Guide (`page_builder`) edits need only a cache rebuild; the design
system entity is config and re-imports with `drush config:import` or a
recipe. On a consuming site the shipped skin is locked and the site's
token and defaults edits land in its site layer (the Palette, "Edit
skin…"); a developer editing the skin itself lifts the distro lock and
ships the system back with `drush daedalus-blueprint:design-system:ship
guide_example`, which rewrites the per-id files and reports an edit to
this hand-authored skin file as `modified` for a person to carry over.

## What the suite binds and what this package adds

The suite's own site declaration (`daedalus_stylish.page_builder.yml`) binds
font-family, font-size, font-weight, line-height, padding, gap and
border-radius to the scales every shipped skin mints, with the zero
literal on the dimension blocks, and constraint blocks expand over
their shorthand families at the source (a `padding` block reaches its
four sides, `gap` its two, `border-radius` its four corners). Those
bindings define the minimum a skin mints: a bound scale minting nothing
is a closed empty row on a strict site, with no fallback by design.

A package never restates a block the suite authors. Constraint blocks
merge like every map, so this package's site section carries only
deltas:

- no site-wide `stops:` (a build error there): the type ramp top-down
  and the everyday six of the nine space steps are the skin's `offered`
  flags per token, in the skin's stored order; stops are curated per
  element in the block type files;
- the bindings the suite cannot promise for every skin: letter-spacing
  to `tracking`, text and border colors to `color` (with the
  transparent / currentColor literals);
- the bindings only this package's scales can carry: border widths to
  `stroke`, box-shadow to `shadow`, durations to `duration`, timing
  functions to `easing`, max-width to `layout`;
- the margin binding (the ladder, zero and auto; negative margins are
  outlawed by construction);
- closed lists and ranges: iteration counts, text-transform, z-index.

Backgrounds are deliberately unbound so the palette (published
swatches, `var(--swatch-*)`) works on bands and cards; padding-inline
is unbound because the suite's width variants spell it as `max()`;
opacity is unbound because every fade entrance starts at 0. The
`transition` shorthand is withdrawn from the container and block grants
so timing cannot bypass the scales.

## How the AI learns the system

There is no doctrine text. The AI is introduced to the active design
system by name in a cached brief (daedalus_blueprint_ai's design system
section), and everything the brief says about Guide Example is data in
this module:

- The entity's `description` is the identity paragraph: what the look
  IS, in a designer's voice, and nothing about what pages, copy or
  pictures must be. It is printed verbatim at the head of the brief.
- Each variant axis's `description` carries the rules across its values
  (alternate Plain and Tint, Accent once for the call to action, one
  Primary per band) and `owns` names the properties its values write
  (rhythm owns the band's vertical padding, width its horizontal inset
  and max-width, button the link part's fill, corners and padding): a
  hand-written owned property is answered with a warning naming the
  axis, never an error.
- Each variant, swatch, animation and blueprint carries a `description`
  written as "what it is; reach for it when", including its
  cardinalities (one Eyebrow and one Lead per band, never a Card in a
  Card).

A fact about one material or one axis lives on that record or
declaration, never in prose elsewhere; that is why the module ships no
skill.

## The design system in one page

**Tokens are roles.** Colors are `bg`, `surface`, `elevated`, `text`,
`muted`, `border`, `accent`, `accent-hover`, `accent-contrast` and the
`inverse-*` trio. The type ramp is `display`, `title-1`, `title-2`,
`title-3`, `body`, `caption`. Tracking is `tight`, `normal`, `wide`.
Space is a rem ladder plus the `gutter` and three banded `band-*`
tokens. Elevation is `shadow-sm|md|lg`; timing is `duration-*` and
`easing-*`; strokes are `hairline|thin|thick`; radii are `sm`, `md`,
`lg`, `pill`; layout is `max-width`, `narrow`, `measure`.

**Bindings are law on a strict site.** Font family, size, weight,
leading, tracking, text and border colors, padding, margin, gap,
radius, border width, shadow, durations, easings and max-width each
accept only their scale's tokens (plus a few literals such as `0`,
`auto`, `none`). Iteration counts, text-transform and z-index carry
closed lists or ranges.

**The contract names what the skin must mint.** `site.token_contract`
lists, per scale the guide curates stops on (text, tracking, space,
color, radius, stroke), the token names the system promises; every
per-element stop is one of them, checked at guide build, and the skin
conforms by minting them all (a site-minted token beyond the contract
is welcome, and on this strict system stays out of basic rows).

**The offer is the skin's, stops narrow it per element.** The rows lead
with the tokens the skin marks `offered` (the six type roles, the
everyday six space steps, three tracking steps, in the skin's order);
per element the block type files narrow further (a button offers three
sizes, six fills, two radii; the in-body ramp offers four roles).
Legality stays the whole scale. The Law tab in Edit Mode shows every
grant, constraint, stop list, axis and birth default of this system
with the file it came from.

**Bands.** Every top-level container wears a surface (Plain, Tint,
Elevated, Inverse, Accent) and a rhythm (Compact, Standard, Spacious),
both root-only axes. A band the editor adds arrives Plain and Standard
at Site width (the op door's birth defaults); a band the AI writes
wears exactly what its document states. Every container stacks its
children with the site gap by skin default (blocks carry no outer
margin), so a stacked container never writes a display or a gap;
Columns and Cluster swap the arrangement. Nested containers wear Card,
Panel or Tile; a row of buttons wears Cluster.

**Text and buttons.** Text blocks wear a text look (Eyebrow, Hero
title, Lead, Muted, Stat) and, when they carry a link, a button look on
the `button` axis (Primary, Secondary, On accent, Link). Buttons are
pills at the caption size.

**Images** are Rounded by birth, Panel for a featured shot, Plain for
logos.

**Motion** is worn, not written: Slide in and Pop on the animation
axis; Raise on hover. Every entrance carries its reduced-motion unset.
The suite's own entrance and hover variants (Fade in, Rise, Scale in,
Lift) carry literal timings so they resolve on any skin; under this
system's timing law they are withheld from the roster, and the on-guide
twins above stand in.

## The fixture posture

The kernel class `GuideExampleSystemTest` proves the package the way a
site meets it: olivero active while the suite's globals land, then the
switch to `guide_example`; the guide builds clean under strict; every
bound property resolves to a scale the skin mints; the skin's own
defaults validate under the site's law; the activation lands every
shipped member with nothing failed; every shipped variant conforms and
is offered. Standalone (daedalus_stylish, daedalus_stylish_layouts and this module, no
daedalus_edit) the block type files are declarations over bundles that do
not exist and parts that carry no markup, which is valid: they merge
over the Daedalus anatomy on a full site and cost nothing without it.
