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
- Style anything: entities, sections, regions, fields, blocks, declared
  sub-elements ("parts"), the anonymous children (`> *`), descendant type
  and tag rules, and every element's two generated boxes (`::before` and
  `::after`, see "Generated boxes" below), with conditions (hover and other
  pseudo-classes, container width bands, reduced motion).
- Materials: named, reusable styling vocabulary as one roster. Variants
  (an element's styles as a named look), swatches (the palette), animations
  (keyframes as a material), fonts (`@font-face` as a material, from
  self-hosted files) and the skin's design tokens, every one minted,
  changed and removed through the same operations the AI submits.
- Skins: the site's look as data, one `daedalus_skin` entity holding token
  records and site element defaults in two layers, what a package ships
  and what the site added or changed over it, authored on the canvas in
  Edit Mode's Materials place (see "Skins" below).
- Design systems: every material is a member of the design system its
  package ships (or global), the site activates one at a time, and a
  system may carry several skins the site chooses among.
- Style guide enforcement: developer-authored YAML declares what is stylable
  per element kind and block type, with constraints, the token contract
  and per-element token curation; the inspector's Law tab shows the law
  read-only with the file each fact came from.
- Shipped materials: a module ships fonts, skins, variants, swatches and
  animations as YAML files (font files beside them) that sync onto the
  site by hash, and one command ships a whole design system back to disk
  (see "The disk lane" below).
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

## Styles follow the host

A style map belongs to a page document's HOST (daedalus_blueprint's
`HostInterface`, the owner of the section storage core resolves), never to
the entity on screen. A page's map lives in its style field; a saved
blueprint's (daedalus_blueprint_library's `blueprint` entity, a page of
its own edited on its own canvas) in the same field on that entity, once
styling is enabled for the `blueprint` bundle; a template's (a Layout
Builder enabled entity view display, edited on core's manage-display
layout route) in the display's `daedalus_stylish.styles` third-party
setting, so a styled template ships in config with the rest of the
display. Every write (the inspector, the code view, an AI batch) reaches
a map through the host.

Storage is one flat map and the style ops address it by absolute key, but
the blueprint delivers it in the page document's one shape: each node of
`layout` carries its own entries under `styles`, keyed by the local
remainder of the selectable key (`''` the element itself, `:{field}`,
`::{selector}`), and the top-level `styles` holds only the keys no node
owns (`entity:`, `field:` and their rule forms). The map has ONE canonical
order, the document's (daedalus_blueprint's
`NodeTreeBuilder::styleMapOf()`): node-less keys first in stored order,
then every node in document order, each node's own keys as stored.
`CssStyleBackend` prints a host's map through `orderedStyleMap()`, and the
client derives its flat index from the mirror's tree with `styleMapOf()`
in `js/src/style-guide.js`, the one reader of `blueprint.styles`, in the
same order (`PrinterEquivalenceTest` locks the two printers' bytes). Order
between different nodes carries no cascade meaning (specificity, then
`@scope` proximity decide) and a node's own keys keep their stored order,
so nothing renders differently; the paint coverage list, a variant's
carriers and the library's page wearer follow document order because they
read the derived index.

Delivery follows the host of the RENDER: an entity with its own override
renders its own map; an entity without one is styled by its template's,
delivered once per display per page as a shared head tag cache-tagged on
the display's config (so a template style save restyles every page without
an override), with the template's document-level entries layered under
the entity's own. Delivery reads and never authors: a content host seeds
an empty override from its template on the first read of either document
door (its sections or its style map), so a never-edited page is read
through a host over a disposable clone of its entity (`RenderStyles`),
its inline tag prints the template's element entries under the seeded,
derived keys the edit canvas renders it under on arrival, and the saved
page stays un-overridden. A foreign element's styles belong to its document's
owner: on a page that renders a template it does not own, the spine's
ownership policy refuses style on the template's elements
(`FOREIGN_DOCUMENT`), so they are not selectable and the inspector offers
nothing for them, while the page's own root key stays the page's; the
notice at the top of the page elements panel names the template. The
usage tables (`variant-usage:rebuild` and its twins) enumerate templates
beside pages.

### Who may style

Style is one of the spine's five capabilities (daedalus_blueprint's
capability resolver), and Daedalus Stylish contributes its half of the
role grammar as a policy (`StylePolicy`, tagged
`daedalus_blueprint.capability_policy`): on a content host, style on any
element is open to an actor holding `style {bundle} {type} elements` for
the host's bundle or the umbrella `style any elements`, so editors can
style landing pages and not articles; on a template style follows
`configure any layout` beside the display's update access, as structure
does. The per-bundle permission is minted for every bundle carrying the
style map field (`StylePermissions`, the shape of core's Layout Builder
override permissions) and depends on that field config, so it exists
exactly where styling is enabled. The op door enforces the verdict on
`update_style`, `apply_variant` and every other style write; every read
publishes it; the inspector reads it through daedalus_ui's one client
gate (`capable(key, 'style')`, the satellite from the leader's
broadcast), and `InspectorSidebar::applies()` mounts the panel only where
the host keeps a style store and style is allowed somewhere. Structure
follows core's override permissions, which the spine's role policy
answers.

## Shipped design systems

Daedalus Stylish's own style guide binds seven properties on every site
(`font-family`, `font-size`, `font-weight`, `line-height`, `padding`, `gap`
and `border-radius`, to the font, text, weight, leading, space and radius
scales), the rule being that the suite binds a property only when every
shipped skin mints its scale; a design-system package layers its own
deltas above (per-element stop curation, color and letter-spacing
bindings, extra literals, withdrawals; which tokens the rows lead with
site-wide is the skin's `offered` flag per token, never a guide list).
Three sub-modules ship a design system as install
config plus a skin that keeps every one of those promises:

- `daedalus_stylish_olivero_skin`: the open reference. Core's front-end look; the
  guide shapes offers and never refuses. The `daedalus` recipe activates
  it.
- `daedalus_stylish_guide_example`: the strict reference. A fully constrained,
  brand-neutral site as data (skin, guide, per-block-type and per-layout
  files, variants, swatches, animations, a blueprint), the authoring
  reference for a designed site and the fixture strict-posture tests
  activate.
- `daedalus_stylish_starter_skin`: the blank canvas. One open system and one skin
  minting a neutral placeholder token on every scale the suite declares,
  with token-only base-tag defaults and no guide fragment, for a site to
  build its own design system off. Not part of any recipe; its README
  walks through building a system off it (activate it, author on the
  canvas, ship) and growing toward guarded or strict.

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

## Generated boxes

Every element has two pseudo-elements, `::before` and `::after`, and the
relative selector addresses them as one more styling address: a terminal
pseudo-element on the selector, never inside `:has()` or `:not()` and
never followed by anything. The owner's own box is spelled with the CSS
nesting anchor, `&::before`, the one place `&` is admitted (`& > p` is not
a selector); a part's box is `.quote::after`, a descendant rule's
`> pb-text::before`. In a style map the keys read `{key}::&::before`,
`{key}::.quote::after` and `{key}::> pb-text::before`; the AI's dialect
spells the same boxes `#id::before`, `#id .quote::after` and
`#id > pb-text::before`, and states go before the box (`#id:hover::after`).

Three laws keep a generated box honest:

- **Existence is universal, vocabulary is declared or derived.** No
  declaration says an element "has" a `::before` (every element does in
  CSS); a declaration's `pseudo:` section declares only what may be
  styled there:

  ```yaml
  # my_module.page_builder.block_type.testimonial.yml
  pseudo:
    before:
      property_groups:
        background: {}
        sizing: {}
        border: {}
  elements:
    '.quote':
      label: 'Quote'
      tag: blockquote
      pseudo:
        after:
          property_groups:
            typography: {}
  ```

  Undeclared, a box derives its host's property groups: granted on an open
  or guarded site, refused on a strict one (`STYLE_PSEUDO_NOT_DECLARED`), so
  a design system that wants a closed world runs strict and declares its
  boxes. The children's box (`> *::before`) is always derived.
- **`content` is the existence switch, closed to `none | "" | one media
  reference`.** The curated `generated_content` group carries `content`
  alone and is granted at every generated box, declared or derived, and
  nowhere else: a `content` write on a real element is refused in every
  mode (`STYLE_PROPERTY_PSEUDO_ONLY`). Three spellings pass and nothing
  else: `""` (the box exists, empty), `none` (no box), and exactly one
  media reference as the whole value, `content: url('media:<uuid>')`, the
  same token `background-image` takes, on the same machinery (the media
  library chip on the Content row, the token gates, image styles,
  `_generate`, preview and delivery). No strings, `attr()`, counters or
  `quotes`: text belongs in a field (translated, searched, read aloud
  there; generated text is invisible to every content surface). No plain
  `url()`, gradient, `image-set()`, `/ "alt"` suffix or second reference.
  Two facts to weigh before putting the image on `content`: it renders as
  replaced content at its own size, so an image style is the way to size
  it (a declared box constrains `content` with `image_style` under its
  `generated_content` group, else the site default `media_image_style`
  applies), while `background-image` on the same box sizes, positions and
  layers; and a content image is exposed to assistive technology as an
  unlabelled image, where a background image is not. A media that no
  longer exists delivers `content: none`, so the box honestly disappears.
- **A box is born with `content: ""`.** A box without content is invisible,
  so an `update_style` patch that CREATES a pseudo-element entry seeds
  `content: ""` at `default` in the same op unless the write sets content
  there itself (a first write of `content: none` or of a media reference
  stands as written, nothing seeded beneath it). The seed lives at the
  operation handler, the one door every lane's writes pass (inspector,
  code view, AI), and nowhere else; an existing entry is never re-seeded,
  so deleting the content line is a deliberate act and the box honestly
  disappears. Variants and skins are authored, not born: a variant
  fragment or skin default at a box key carries its own `content`.

Seating a box is a doorway, not a write: "+ Before" / "+ After" on the
inspector's address chip in advanced mode, a "Before" / "After" row in
the Page elements tree (listed once something delivers content there),
or `&::before {` typed on the code view's root island target the box;
the first property write births the entry. Basic mode styles no box (its
rows are the declared controls, and no box declares any), so its chip
offers no doorway and a box seated from elsewhere shows an empty panel
that says where to go. Edit-mode chrome that itself lives on a `::before`
(daedalus_explode's plane label chips, daedalus_stylish_layouts' empty
container hint) asserts its own box with `all: initial` under the stage id
rather than moving off pseudo-elements, so it wins exactly while it exists.

## The two outlines

The page is outlined twice in the left rail, by daedalus_ui's page
elements panel and by the inspector's code mode, and both draw their
rows over daedalus_ui's one outline row grammar (`daedalus_ui.api.php`,
"The outline row grammar": the chevron column, the element's minted
icon, its minted name and the facts at the row's end; the type is the
row's tooltip, never printed). This module is a wearer of that grammar and a
contributor to the other outline's rows.

**Code mode's rows** (`js/src/code/page-view.js`) are mouse chrome over
editable islands: the keyboard walks the declarations, not the rows. A
row reads `_element.icon` and `_element.name` as `page-model.js` carries
them beside `type` (the page row reads the root's words from the
source's `getRootElement()`, since the lean tree holds no root node);
the page and a container speak in the grammar's structure voice. Every
row folds to its header; while collapsed, its facts zone prints the
fold's summary (`page-model.js` `foldSummary`): the element's own
declaration count and one dot per variant it wears, in the variant's
identity hue (`layers.js` `layerColor`, the hue the wear bar's dots, the
code view's run labels and the library speak). Depth is the grammar's
token, set on the node wrapper so the islands' rail reads it too. The
satellite window draws the same rows from the broadcast alone: its
document attaches `daedalus_ui/panel_controls`, so the grammar reaches
it with no wiring of its own. The listing's head is the "Showing" row,
the scope sentence said with the selected element's name ("Chronicle
only", "Chronicle and what's inside", "the whole page"; "the selection"
stands for the name while nothing is selected) with the outline's
collapse-all and expand-all pair at its end. The head is pinned: the
code view renders it into the inspector's top region above the
scrolling body, so a reader scrolled deep into the rows still reaches
the lens and the pair; the wear bar and the rows scroll beneath it, and
every island prints at its full height (the panel body is the one
scroller). Only the head says the element's name: the subject line's
address chip answers where a change lands ("This element", "Every link
here") and the head how much is listed, so the two never share words.

**The selected element's islands** (`js/src/code/element-view.js`,
`ElementIslands`) are what its row opens: the root island and one
island per part the style map delivers (an own part, a worn reference's
fragment, a rule's continuation), plus the drafts, islands that hold no
committed value yet and live as view state keyed to the element. A
draft is minted by a typing gesture on the root island (a selector
completion pick, an `&` generated-box option, a braced `p {`), which
hands the new island the cursor, or by a seat: a styling target landing
on a part of the selected element that no delivered island lists (a
paragraph Cmd-clicked on the canvas, a part row clicked in the page
elements tree, keyboard traversal) drafts that part's island, empty,
printed under the part's own word ("Paragraph") with its active mark,
and leaves the cursor where it was, since a seat is a selection
gesture, not a typing one. A draft's first committed declaration lands
in the map and the delivered roster takes the island over. The drafts
go with the selection: an island the editor left empty is dropped when
the selection leaves the element, and a row that is not the selection
prunes to its occupied islands, so an island the seat minted and nobody
typed into is gone on deselect by the drafts' one rule, with no rule of
its own. An ancestor rule seated from the address chip ("Every link
here") is not a part of the element: it mounts as its own island and
drafts nothing.

**The variant provider** (`js/src/row-facts.js`, registered in
`index.js` as `daedalus_stylish.variants` through
`app.tree.registerRowFacts`, daedalus_ui's row-facts seam) tells the
page elements panel's rows the same wearing story. Facts: one dot per
worn variant in its hue, titled "Wears {label}", then a dot in the
element hue titled "Styled on itself: N declarations" when the element
carries declarations of its own; a bare row has no facts, so the zone is
quiet exactly where there is nothing to tell. Always on (variants have
no switch), re-announced when the style map or the materials roster
changes. Doorways lead the row menu, ahead of the element's verbs: per
worn variant, "Edit {label}" (the variant's editing session anchored on
this element, what code mode's wearing row opens) and "Outline
everything wearing it" (the one toggled Outline Variant gesture, the
canvas outline plus the matching row marks, checked while on; named
"Outline everything wearing {label}" when several are worn), and "Show
its own styles in code" for an element styled on itself (select it, code
mode, the styling place, the panel open). The gestures reach the
inspector through the doors seam (`js/src/inspector-doors.js`) and, while
the panel is popped out, over the pop-out protocol to the satellite's
inspector; the Outline item's check reads the source's canvas paint, so
the leader's menu is honest either way. Facts never add a row and never
touch reach, so the pointer and the keyboard land where they did.

**The styling mode and the Markup view are tied.** Basic mode is the
editor's simple tree: entering it turns the panel's Markup view off, and
advanced and code turn it on (`index.js` mirrors `uiMode` onto
`app.tree.setViewVisibility('markup', …)` on change only, so the kebab
still overrides within a mode). When Markup turns off, a target standing
on a row only that view drew (a shell part, a generated box) moves up to
its owner, by daedalus_ui's rule; the inspector's session treats the
move as any selection move.

## Skins

A skin is the site's look as data: token values on the scales the site
declares in code, and the default styles bare elements wear per scope
(`base:h2`, `block_type:text`, `kind:container`). A site wears one skin
at a time and authors it on the canvas; nothing about a skin is edited
through an admin form.

### Two layers on one entity

A shipped skin holds what its package ships (the package layer: `tokens`
and `defaults`, the fields sync writes and the ship reads) and what the
site added or changed over it (the site layer: `site_tokens` and
`site_defaults`, by name down to one fact of a token or one property of
a default). Every reader but the disk lane reads the merge, so a
package update lands in its own layer and shows through wherever the
site changed nothing, and unsetting a site value reveals the package's
again. A skin with no provider (the site's own, or a copy) has one
layer, edited directly. Both layers are revisionable; the identity
fields are not, so a revision restore changes what a skin says, never
what it is.

### Tokens are materials

A stored token is a record, `{value, description, offered}`: the value
(a string or a condition map such as `{default, dark}`), the reasoning a
designer or the AI reads (`description`), and whether the token is
offered on the inspector's rows and to the AI (`offered`, TRUE unless
withdrawn). A hand-authored skin file may still spell a bare value
(`md: 1rem`); every door that takes authored text wraps it into the
record, and every store and machine-written file holds records. Tokens
ride the materials operations with `kind: token`:

```
save_material   {kind: token, group: space, label: xl, value: 4rem, description: '...', offered: true}
update_material {kind: token, id: space/xl, value?, description?, offered?}
remove_material {kind: token, id: space/xl}
```

The token name is the label (`label` is never slugged), the id is
`scale/name`, and a token's membership is its skin's (no `global`). A
token something references (a style, a variant, another token's
`var()`) refuses removal with `STYLE_TOKEN_IN_USE`; the Palette's delete
rewrites every reference to the literal first, as the swatch delete
does. Usage lives in `daedalus_token_usage` (rebuild with
`drush daedalus_stylish:token-usage:rebuild`).

Site element defaults are written by one style operation, the site-scope
twin of `update_style`:

```
update_default {scope: 'base:h2', key?: '', condition?: default, properties: {font-size: 'var(--text-title-1)'}}
```

A patch of one scope's defaults under one condition; `null` unsets, and
an unset site value reveals the package's. The `defaults` field itself
has no blueprint write on any skin.

On a consuming site every token gesture and every `update_default`
lands in the site layer (see "The distro lock" below); on the site's own
skin, or on a development site with the lock lifted, in the one layer
the skin has.

### The token contract and the offer

A guide that curates `stops:` on a scale declares that scale's contract,
`site.token_contract: {scale: [names]}`, and stops may name contract
names only (both checked at guide build). A skin conforms to the
contract when it mints every name it promises (`SkinConformance`); a
site-minted token beyond the contract is welcome. Which tokens a row
leads with site-wide is the token's own `offered` flag, in the skin's
stored order, never a site-wide `stops:` list (a build error under
`site.constraint_defaults`); a per-element `stops:` list in a block
type or layout file still narrows one row (a button offers three
sizes). A row bound to a scale shows the element's curated stops where
declared, else the scale's offered tokens; on a curated row the tokens
the site minted follow the curated ones on open and guarded systems and
stay out of basic rows on strict.

### Which skin a site wears

A design system names its package's answer (`default_skin` on the unit)
and the site may choose another member: `daedalus_stylish.settings`
`skin_choice` holds one choice per design system, so switching systems
and back returns to the skin the site chose. A skin is choosable while
it is published, a member of the active system and conforms to its
contract; a choice that stops qualifying falls back to the unit's
default on every read, and the stored choice survives. Choose from the
skin card in Edit Mode or with `drush daedalus_stylish:skin:choose SKIN`
(both `administer skins`); the defaults file recompiles on the choice.

### Authoring on the canvas

Everything a skin holds is edited in Edit Mode's Materials place, with
the page as the preview:

- **The Palette's skin section** lists the worn skin's tokens per scale
  beneath the swatches. A token's card edits its value (every condition
  the scale's shape allows, live on the canvas before commit), its
  description and its offered flag, renames it, deletes it, and on a
  consuming site unsets the site's change to reveal the shipped value;
  a mint dialog adds a token to any declared scale. Rows the account
  cannot write render read-only with the reason.
- **The skin card** over that section shows the skin worn, the
  conforming skins to choose from, "Copy as my skin" (a new unlocked
  skin holding the merge of any skin on the site, picked from a source
  select with the worn skin preselected; the copy is always a member of
  the ACTIVE design system whatever its source belonged to, and a
  site's own unit, one with no package and no default skin, takes its
  first copy as its `default_skin` and wears it at once, which is how
  a design system created on the site gets its first skin; any other
  copy is chosen as a separate gesture), the revision list with
  restore, and "Revert the site layer" on a shipped skin.
- **"Edit skin…"** on any property row or code line whose value the
  skin supplies enters a session on that scope's default (`base:h2` for
  a heading's size, `block_type:text` for a text block, `kind:container`
  for every container), staged and pushed as a variant's document is;
  Push commits `update_default` and every element of the scope
  restyles. The session follows the selection from one wearer of the
  scope to the next and exits on an element outside it.
- **The Fonts tab** mints a font from `woff2` or `woff` files (one face
  per file, weight and style read from the file name and editable),
  lists every rostered font with a specimen per face, its use count and
  its shipped state, renames, describes and deletes; the typography
  Font row offers minted families as chips when building a stack.
- **The Law tab** renders the style guide read-only: the scales and
  what binds to them, the contract, and per kind, block type, block
  plugin and layout the grants, constraints, curated stops, axes and
  birth defaults, each with the provider and file that supplied it,
  read under the active system's enforcement mode. A row whose value a
  declaration supplies opens the Law tab at that declaration. Law is
  authored in files; the tab shows it.

The AI authors the same things through the same operations (the skins
skill teaches them) and never wears a skin: choosing, copying and
reverting are a person's gestures.

One edge to know. The canvas follows a session's Push and the skin
card's gestures at once: each ends with `source.refreshDefaults()`, a
read of `GET /daedalus_stylish/defaults` that stamps the recompiled
defaults onto the open page. An `update_default` the AI submits during
a turn has no such wire: it lands on the server, and the response the
page applies carries the materials delta (tokens minted, changed or
removed) and nothing about the skin's defaults, so the recompiled
defaults reach an open editing page at its next page load, or at the
next session Push or card gesture, whichever comes first. Why there is
no wire is in `daedalus_stylish.api.php`; the skins skill tells the AI
to say so when asked why nothing moved.

### Permissions

The per-material pattern: `create tokens` (extend the skin), `update
tokens` (change a token's facts, on a consuming site the site's layer
over the shipped token), `delete tokens` and `delete tokens in use`,
`edit skin defaults` (the `update_default` operation), and `administer
skins` for choose, copy, restore, revert and the delete form, granting
every gesture above as well. Fonts carry the swatch pattern (`create`,
`update`, `delete`, `delete fonts in use`, `administer fonts`). Styling a
page's elements is not a material permission: it is the capability
permission under "Who may style" (`style {bundle} {type} elements`,
`style any elements`).

### Fonts

A font is an at-rule material on the animation pattern: a
`daedalus_font` entity with a family name and faces
`[{weight, style, source: {file: UUID}}]`, each face one uploaded file
(`woff2` or `woff`, through daedalus_ui's file lane; the source is a
typed map so a hosted kind can join `file` later). `FontStylesheet`
compiles every rostered font into one `@font-face` file with
`font-display: swap`, attached before the swatch file. A reference is
the quoted family in a `font-family` stack (`"Inter", sans-serif`) or
in a token on the scale the guide binds `font-family` to; usage lives
in `daedalus_font_usage` (rebuild with
`drush daedalus_stylish:font-usage:rebuild`), and deleting a font in use
drops the family from every stack first (a stack that named nothing
else becomes `initial`).

## The disk lane

A module ships daedalus_stylish materials as YAML files at its root, keyed by machine
name. The lane has two directions with two words:

- **Sync** is disk to site. Every enabled module's shipped definitions
  land on the site as content entities on a cache rebuild, on module
  install, and by hand with `drush daedalus_stylish:sync [MODULE]`.
- **Ship** is site to disk. `drush daedalus-blueprint:design-system:ship SYSTEM`
  (daedalus_blueprint) writes a whole design system into its package
  module: the unit, the site's materials into the module's files through
  this module's ship lane, and the system's content blueprints; `drush
  daedalus-blueprint:blueprint-ship BLUEPRINT MODULE`
  (daedalus_blueprint_library) does the same for one saved blueprint.

### The files

Each kind reads two file forms, merged:

| File | Role |
|------|------|
| `MODULE.daedalus_stylish.fonts.yml`, `MODULE.daedalus_stylish.skins.yml`, `MODULE.daedalus_stylish.variants.yml`, `MODULE.daedalus_stylish.swatches.yml`, `MODULE.daedalus_stylish.animations.yml` | The hand-authored single file per kind: comment-heavy, written by a person, never by a machine. A font's files sit under `assets/fonts/` and a face names one by basename (`source: {file: inter-regular.woff2}`). |
| `MODULE.daedalus_stylish.<kind>.ID.yml` (e.g. `MODULE.daedalus_stylish.variants.card.yml`) | The per-id file: the machine-written form, one file per shipped id beside the single file. This is what the ship lane writes. |

A machine name shipped in two of one module's files is a developer error
and fails the sync. Per-id files are always written whole, so an entry the
ship no longer includes leaves disk. A skin file's tokens may spell a bare
value or the whole record per token (`{value, description, offered}`); a
machine-written skin file holds records, so a token's reasoning travels
as its description, never as a YAML comment.

A package's per-id lane writes one file per kind, each kind with its own
shipper: `MODULE.daedalus_stylish.fonts.ID.yml` (with the faces' files under
`assets/fonts/`), `MODULE.daedalus_stylish.animations.ID.yml`,
`MODULE.daedalus_stylish.swatches.ID.yml`, `MODULE.daedalus_stylish.skins.ID.yml` and
`MODULE.daedalus_stylish.variants.ID.yml`. The
blueprint lane writes `MODULE.daedalus_blueprint.blueprints.ID.yml` plus
`MODULE.daedalus_stylish.variants.ID.yml` for the variants the composition wears.

Keys a shipper does not own are carried from the existing entry when a file
is rewritten (the variants file's `blueprint:` key belongs to the blueprint
module; daedalus_stylish never learns what it means).

### Variants a blueprint owns

A saved blueprint may own the variants minted for it (adopted when the
blueprint is captured, released or transferred from the library's
ownership routes). daedalus_blueprint_library adds two fields to
`daedalus_variant`, `blueprint` (a reference to a saved blueprint) and
`blueprint_file` (a shipped blueprint's machine name), and registers an
applicability checker (`VARIANT_OWNED_BY_BLUEPRINT`: an owned variant
applies inside its owner's placements only). This module carries the
fact without reading it: the inspector's library, wear bar and picker
show the owner from the materials payload (`blueprint`,
`blueprint_orphaned`), the importer writes a variants file's `blueprint:`
key onto the two fields (`VariantImporter::OWNER_KEY`), and the shipper
writes it back. A blueprint's own document is a page document, so every
host lane (usage tables, migrations, delivery) sweeps it as a page.

### Sync: `drush daedalus_stylish:sync`

The importer stamps each shipped entity with its provider module and a hash
of the shipped data, and that one hash answers both sync questions: did the
site modify the entity, and did the disk defaults change. One line per
definition, `MODULE: KIND NAME outcome`:

```
my_brand: font inter unchanged
my_brand: skin dawn updated
my_brand: variant card_blue updated
my_brand: variant hero_title kept (site-edited; new defaults on disk)
```

Outcomes are `created`, `updated` (disk moved, the site had not), `unchanged`
(disk did not move, whether or not the site edited the entity: a sync never
carries a site edit to disk, only a ship does), `kept` (the site edited the
entity AND disk moved; the site's edit wins and the new defaults wait), and
`failed` (printed last with path, code, message and hint; non-zero exit).
Kinds sync in dependency order: fonts, animations, swatches, skins,
variants. A skin is never `kept`: the sync writes its package layer alone,
so new disk defaults always land there while the site's layer rides the
new revision untouched (each layer has one author; a developer editing the
package layer under the lifted lock ships before pulling). A module
packaging a design system that is not the active one is deferred whole;
its definitions land at the activation, which re-syncs every enabled module
under the incoming law.

```bash
# Every enabled module (the same sync a cache rebuild runs).
drush daedalus_stylish:sync

# One module, every outcome on the terminal.
drush daedalus_stylish:sync my_brand
```

### Ship: `drush daedalus-blueprint:design-system:ship`

The other direction, for the package author working on a development site
(see "The distro lock" below). One command ships a whole design system
into its package module (daedalus_blueprint's; it creates the module when
it does not exist and lands the unit as install config); this module's
lane runs inside it: every per-id file of the module is rebuilt from the
current entities, and every font, animation, swatch, skin and variant
that is a member of the system and nothing ships yet is adopted into the
module, a new name landing in `MODULE.daedalus_stylish.<kind>.NAME.yml`.

```bash
# Ship the my_system design system into its package, my_brand.
drush daedalus-blueprint:design-system:ship my_system

# The identical report, with no file written and no entity touched.
drush daedalus-blueprint:design-system:ship my_system --dry-run
```

One line per entry in the sync command's grammar (the unit and the
blueprints print first, then this module's kinds):

```
my_brand: font inter created
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
everything else ships. A font ships with its files: each face's file is
copied under `assets/fonts/` before the yml is written. A skin ships its
package layer only; the site layer is the site's and never reaches disk
(on a development site with the lock lifted, skin writes land in the
package layer, so there is nothing to lose).

After a ship the site is consistent with disk: every shipped entity is
re-stamped with the module as provider and the written payload as its
source hash, and published, so `drush daedalus_stylish:sync MODULE` right after
reports `unchanged` for every name.

### The distro lock

Shipped variants, swatches, animations and fonts are read-only on a site:
the package's files are their one author, and a site that wrote into them
would drift away from every future sync. The site forks a material instead
("Copy as my variant", "Copy as my skin") or reverts toward the disk. A
shipped skin's package layer (its tokens and defaults as shipped) is locked
the same way, and the skin is the one shipped material a consuming site
keeps authoring: every token gesture and every `update_default` lands in
the skin's own site layer, which sync never writes and which never ships,
so a tenant extends and overrides the shipped look without touching it. A
shipped token cannot be deleted or renamed on a consuming site; a site
override on it can be dropped to reveal the shipped value.

The package author works the other way round: the site is the canvas and
drush carries the result back into the module. On such a development site,
lift the lock in `settings.php`:

```php
$settings['daedalus_blueprint_developer'] = TRUE;
```

The flag is read by daedalus_blueprint's `daedalus_blueprint.distro_lock` service,
the one fact every shipped-material gate in daedalus_stylish consults: the variant,
swatch, animation and font managers (push, details and value edits, revision
restore, delete), the token and skin gestures' layer rule (with the lock
lifted a token write, an `update_default` and a revision restore land in a
shipped skin's package layer, and a shipped token may be renamed or
deleted), the blueprint lane field handlers and their guidance text, and
the materials provider's `updatable` record flag that the inspector's
library and push bar read. It is a setting rather than a permission because
writable module directories are an environment property: a development site
needs no role setup, and a production administrator cannot lift a lock that
protects production. Roster records are cached, so rebuild caches
(`drush cr`) after flipping it.

The author's flow: change the system on the site (re-value or mint tokens in
the Palette, push a skin session's defaults, override a placement and push
into its variant, mint swatches, animations and fonts), save the page, then
`drush daedalus-blueprint:design-system:ship SYSTEM` for the system's
materials, or `drush daedalus-blueprint:blueprint-ship BLUEPRINT MODULE`
for one saved blueprint composed on its own canvas. Both read the saved
site, never an unsaved canvas: a token or defaults edit is saved the
moment it commits, a blueprint's document when its canvas is saved.

## Other drush commands

```bash
# Scaffold style guide YAML for a block type from the classes its markup emits.
drush daedalus_stylish:discover BLOCK_TYPE

# Choose the skin the active design system wears (administer skins).
drush daedalus_stylish:skin:choose SKIN

# Rebuild the usage tables from the saved default-revision style stores.
drush daedalus_stylish:variant-usage:rebuild
drush daedalus_stylish:swatch-usage:rebuild
drush daedalus_stylish:token-usage:rebuild
drush daedalus_stylish:animation-usage:rebuild
drush daedalus_stylish:font-usage:rebuild
```

## Maintainers

- Tim Bozeman - https://www.drupal.org/u/tim-bozeman
