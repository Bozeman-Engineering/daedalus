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
  pseudo-classes, container width bands, color scheme, reduced motion, and
  on a site that admits them any feature query, supports test or state the
  browser knows, see "Conditions" below).
- Materials: named, reusable styling vocabulary as one roster. Variants
  (an element's styles as a named look, the one material that is its own
  entity) and the skin's three sections: tokens (named values per scale,
  the palette's colors and gradients among them), animations (keyframes)
  and fonts (`@font-face` from self-hosted files), every one minted,
  changed and removed through the same operations the AI submits.
- Skins: the site's look as data, one `daedalus_skin` entity holding
  tokens, animations, fonts and site element defaults in two layers, what
  a package ships and what the site added or changed over it, authored on
  the canvas in Edit Mode's Design system place (see "Skins" below).
- Design systems: every material is a member of the design system its
  package ships (or global), the site activates one at a time, and a
  system may carry several skins the site chooses among.
- Style guide enforcement: developer-authored YAML declares what is stylable
  per element kind and block type, with constraints, the token contract
  and per-element token curation; the inspector's Law tab shows the law
  read-only with the file each fact came from.
- Shipped materials: a module ships skins (tokens, animations, fonts and
  defaults in one file, the fonts' files beside it) and variants as YAML
  that syncs onto the site by hash, and one command ships a whole design
  system back to disk (see "The disk lane" below).
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

## Settings

The site mechanics of `daedalus_stylish.settings` are set on one form,
Configuration » Daedalus » Styling » Styling
(`/admin/config/daedalus/styling/settings`, `administer site
configuration`): whether the AI may mint tokens, animations and fonts
(`ai_minting`, see [Who may style](#who-may-style)), whether the
page-level width-band query container is emitted or the theme manages its
own (`container_roots`), the image style media referenced from style
values resolves through when no style guide constraint names one
(`media_image_style`, empty for the original file), and the two container
breakpoint ceilings in px (`breakpoints.narrow`, `breakpoints.medium`; the
medium band starts at narrow + 1, so the bands tile exactly; the two are
the published width REFERENCES, so a style keyed `narrow` follows a change
here while a literal query typed in a style keeps its own numbers, see
[Conditions](#conditions)). The object's
other two keys are never on the form: `skin_choice` is written by the skin
card and `drush daedalus_stylish:skin:choose` (see [Which skin a site
wears](#which-skin-a-site-wears)), `opt_outs` by the Styling box on each
bundle form's Page builder tab. Per-bundle styling is mirrored read-only
on Configuration » Daedalus » UI » Page builder on content types.
`SETTINGS.md` at the daedalus repo root rosters every setting and its
doors.

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

Whether a bundle's pages keep a style map at all is the style field's
existence (`DaedalusStylishFieldManager::isEnabled()`), switched on the
bundle form's Page builder tab ("Let editors style elements",
`Form\BundleEditFormAlter`, the one write path) and mirrored read-only as
the "Styling" column of daedalus_ui's bundle roster
(`/admin/config/daedalus/ui/content-types`, `EventSubscriber\StylingColumn`
on `BundleRosterEvent`).

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
usage tables (`variant-usage:rebuild` and `skin-material-usage:rebuild`)
enumerate templates beside pages.

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
`update_style`, `apply_variant`, `move_style` and every other style write; every read
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
  brand-neutral site as data (a skin with its palette tokens and
  animations, guide, per-block-type and per-layout files, variants, a
  blueprint), the authoring
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

`drush en` installs a package as a dormant unit: its batch syncs like
any other (a module's globals land as it installs, inside a site install
or out), but the package's own materials are deferred whole until its
system is active, which means landed and named by the pointer: an
install from exported config writes the pointer before the system's
entity imports, and the package lands when the entity does. Activation
makes its skin the active one and re-syncs every enabled module's
shipped definitions under the system's own law, which lands the
package. Skins wait for one more thing: the default theme, whose chain
declares the parts their chrome defaults address, so while `system.theme`
names a theme that is not installed (an install from exported config
imports `system.theme` after the package's system lands; core's `stark`
stands in until then) every module's skins are deferred and land when
the theme does (the default's save, or the named theme's install).
Variants never wait for the theme: the landing lane admits a part only
the theme declares and the verdict withholds it until the theme lands. A
global the law of the day refuses is never a
sync failure: it lands and the roster's verdict withholds it until a
skin mints what it names or the law opens, with no re-sync. Switch back
with `... activate olivero`.

## Generated boxes and the other pseudo-elements

Every element has two generated boxes, `::before` and `::after`, and the
relative selector addresses them as one more styling address: a terminal
pseudo-element on the selector, never inside `:has()` or `:not()` and
never followed by anything. The owner's own box is spelled with the CSS
nesting anchor, `&::before` (the anchor stands first, bare only with its
own box or carrying qualifiers, `&.open > ul`, `&:not(:last-child) > *`;
`& > p` is `> p`); a part's box is `.quote::after`, a descendant rule's
`> d-text::before`. In a style map the keys read `{key}::&::before`,
`{key}::.quote::after` and `{key}::> d-text::before`; the AI's dialect
spells the same boxes `#id::before`, `#id .quote::after` and
`#id > d-text::before`, and states go before the box (`#id:hover::after`).

The slot takes every other pseudo-element the browser knows the same way
(`li::marker`, `input::placeholder`, `&::selection`, `p::first-letter`,
`p::first-line`, `&::backdrop`; the grammar spells any bare name,
`data/wide-selectors.json` says which exist, and a functional one,
`::part()`, `::slotted()`, is refused). Whether THIS site admits one is
the roster's verdict by mode, declared-or-derived exactly as a generated
box: a `pseudo:` section may key by any of them. What a box may be styled
with is the browser's law before it is the site's: `::marker`,
`::placeholder`, `::selection`, `::first-letter` and `::first-line` carry
their own vocabularies (`data/pseudo-vocabularies.json`, hand-maintained
from each specification; `Vocabulary\PseudoVocabularies` and
`js/src/pseudo-vocabularies.js` read it), so an undeclared marker offers
the properties its spec allows (grouped by the curated groups the panel
serves) rather than its host's, and a property outside the allowlist is
refused in every mode with the allowlist named
(`STYLE_PROPERTY_NOT_ON_PSEUDO`), since every browser would ignore it.
Any other pseudo-element (`::backdrop`, `::file-selector-button`) derives
its host's vocabulary as the generated boxes do. Only the two generated
boxes have an existence switch: `content` is theirs alone, no other
pseudo-element is seeded or switched, and the page-elements tree lists
such a box once the map delivers a property there.

## Selectors

The relative selector after a key's `::` is CSS's own selector grammar
over the element's subtree (daedalus_blueprint's `RelativeSelector`,
its JS twin `daedalus_ui/js/edit_mode/materials/relative-selector.js`,
one shared battery): compounds joined by the four combinators, each a
subject (`*`, a tag, a `d-` type, or implied) carrying qualifiers of
CSS's kinds (a class `li.active`, an attribute `[aria-expanded="true"]`,
a pseudo-class with its argument: `:first-child`, `:nth-child(2n+1)`,
`:not(…)`, `:has(…)`, `:hover`, and `:is(…)` as any of several
compounds at one position, `:where(…)` read as `:is(…)`), and the
anchor `&` as the qualified owner. The grammar refuses only what cannot
be addressed under an owner (`html`, `body`, `:root`, a leading sibling
combinator, another element's id, anything after a pseudo-element,
`:is()` nested in `:not()` or in `:is()`);
whether a tag, class, attribute or state the declaration does not name
is admitted on THIS site is the roster's verdict by enforcement mode
(`StyleMapValidator::resolveRoster()`: open and guarded admit what the
browser knows, `data/wide-selectors.json`; strict refuses with the
`*_NOT_DECLARED` correctives), exactly as an undeclared property is
judged. Pose-ability draws the line between a qualifier and a
condition: a published state on the LAST compound is the entry's
condition (keyed `hover`, chip-able, force-stamped), a state on any
earlier compound is a qualifier of it (`li:hover > ul`, the reveal
patterns; the pose stamps the compound carrying it, `force-state.js`
`stampTargets`), and position, classes and attributes are never
conditions. Every typed or pasted selector is read by ONE digester,
daedalus_blueprint's `SelectorDigest` and its twin
`daedalus_ui/js/edit_mode/materials/selector-digest.js`
(`selector-digest-cases.mjs` locks both): the dialect's parser, the
`parse-css` door, the code view's typed line and brace, the prelude
editor and the paste door all call it; it reads the nesting sugar
(`&`, a bare relative prelude, a leading `:`), lowercases tags, types
and pseudo names, peels the published states off the last compound
into the condition and folds a selector list into one `:is()` rule
(`h1, h2` is `*:is(h1, h2)`, `li:hover > ul, li:focus-within > ul` is
`li:is(:focus-within, :hover) > ul`: a comma means what it means in
CSS, one rule with several targets), refusing a list whose members
share no shape (they differ at more than one compound, or in their
combinators; the corrective says separate rules) and a fold that would
put two posable states on the last compound (a disjunctive condition;
the corrective says two rules, one per state).
Every qualifier prints at its compound inside `:where()` (a published
state as the force-state dual), so no qualifier changes a rule's
weight.

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

## Conditions

A condition is the WHEN of a style entry, the twin of the selector's
WHERE, and the line between the two is pose-ability: a condition is
something the editor can pose (a state the force-state stamp forces, a
width band the stage renders, a color scheme or motion preference the
emulation vector makes true), a qualifier something the markup decides.
A condition key is a compound of CLAUSES, one per axis, joined by `+`
(`hover+narrow`, `narrow+dark`), read by one grammar on both sides
(daedalus_blueprint's `ConditionClause` and its twin
`daedalus_ui/js/edit_mode/materials/condition-clause.js`,
`condition-clause-cases.mjs` locking both). A clause is a REFERENCE, a
published condition's name (`hover`, `narrow`, `dark`: the condition type
plugins, `ConditionTypeInterface`, each a label, a wrapper and an axis),
whose CSS the site owns, so a threshold change in the settings rewrites
no map, exactly as `var(--space-2)` is a reference whose value the skin
owns; or a LITERAL the browser knows, written as its own canonical CSS
(`@container (min-width: 500px)`, `@media (hover: hover)`,
`@media (prefers-contrast: more)`, `@supports (display: grid)`, a
pseudo-class state `:checked` the roster does not publish). A literal's
axis is its feature (`width` for the width features, a published
wrapper's axis where one tests the same feature, else the feature's own
name; `supports`; `state`), so "one per axis" holds for literals too, and
a literal equal to a published wrapper IS that reference (the digester
peels a typed `@container (max-width: 767px)` to `narrow` as it peels
`:hover` to `hover`). Admission is the enforcement mode's: strict admits
references only (`INVALID_STYLE_CONDITION` naming the references on the
clause's axis), open and guarded admit a literal the browser knows
(`data/wide-conditions.json`, W3C's media and container features and
their value spaces; a typo is refused everywhere with the nearest names),
the same rule that admits an undeclared property or tag. The printers
nest a literal as its own at-rule, media and container told apart by the
clause's prefix, and print a posable pseudo-class clause as the
force-state dual (`:is(:checked, [data-force-state~="checked"])`).

Nothing declares the structured facts the canvas poses: the clause
grammar DERIVES a `band` (the inclusive px edges of a `@container` width
query) and a `preference` (a single-feature `prefers-*` media query's
feature and value) from each clause's CSS, references and literals
alike. The pose reads them (`editing-at.js`): the editing compound
writes land at is derived from the canvas vector and the force state
over the published table AND the literal clauses in use on the selected
element's rows (`literalClauses`: its own entry's and its rules'
conditions holding a value), so a literal row poses like a reference,
"drive the canvas there" poses a literal band's midpoint, a literal
preference's forced value or a literal state's stamp word, and the chip
row (`chip-row-model.js`) lists the published references on its axis
menus with each posable literal in use as an entry faced by its own
text; the stage's ruler marks a literal width band while it is in use
(`Drupal.Daedalus.CanvasVector.mark`, beside the permanent bands the
references register) and lights every band holding at the width. Where
a literal band in use and a published band both hold at a width, the
write target is the band the cascade delivers LAST at that width, the
literal (the printers emit a literal clause after every published
condition, so a property both set is the literal's on the canvas), and a
pose outside the literal's band edits the reference again; a clause the
canvas cannot pose (a `@media` width, the viewport's and the preview
window's; a `@supports` test; a preference no vector axis emulates;
`:visited`, `:target`) is never a chip and its row lights only through
the code view. In code mode a condition row is minted by the `&hover`
sigil completion (the published references) or by typing the at-rule
prelude whole (`@container (min-width: 500px) {`), and the row's
header prints the clause's own text.

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
completion pick, an `&` generated-box option, a braced `p {`,
`li:hover > ul {` or `h1, h2 {`, the list folded into one `:is()` row
with a notice naming the fold; the brace's gate is "the digester reads
the line", and the
`:` lane after a compound offers the published states, which peel into
a condition row on the last compound and stay qualifiers before it,
beside the position pseudo-classes, `:not(` and `:has(`; while the line
is typed without a brace its reach paints on the canvas through the
hover-selector facet), which
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

A skin is the site's look as data, in four sections: tokens (named
values on the scales the site declares in code; the palette's colors and
gradients are tokens on the color and gradient scales), animations
(named keyframes), fonts (`@font-face` families from self-hosted files)
and the default styles bare elements wear per scope (`base:h2`,
`block_type:text`, `kind:container`). A design system may carry several
skins (a seasonal skin changes its colors, type and motion together); a
site wears one at a time and authors it on the canvas; nothing about a
skin is edited through an admin form.

### Two layers on one entity

A shipped skin holds what its package ships (the package layer: `tokens`,
`animations`, `fonts` and `defaults`, the fields sync writes and the ship
reads) and what the site added or changed over it (the site layer:
`site_tokens`, `site_animations`, `site_fonts` and `site_defaults`, by
name down to one fact of a record or one property of a default). Every
reader but the disk lane reads the merge, so a
package update lands in its own layer and shows through wherever the
site changed nothing, and unsetting a site value reveals the package's
again. A skin with no provider (the site's own, or a copy) has one
layer, edited directly. A member skin is born wearable: one that
brings no defaults starts from the defaults its design system's default
skin wears, so a skin of tokens alone never renders a page bare. Both layers are revisionable; the identity
fields are not, so a revision restore changes what a skin says, never
what it is.

### The skin's materials

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
save_material   {kind: token, label: plum-veil, element: '#hero', property: background-image}
update_material {kind: token, id: space/xl, value?, description?, offered?}
remove_material {kind: token, id: space/xl}
```

The token name is the label (`label` is never slugged; lowercase, digits
and hyphens, the custom property's tail), the id is `scale/name`, and a
token's membership is its skin's (no `global`). `group` may be omitted:
the token lands on the first declared scale whose shape fits the value
(a color on `color`, a gradient on `gradient`; name the scale for any
other shape), and the second form captures the literal an element wears
instead of spelling a value, the gradient builder's and the color
field's "Save as token". A token's value may reference another token of
its skin, so a gradient may be written in the palette's colors.

An animation is a record `{steps, description, offered}` keyed by name
(lowercase, digits, hyphens or underscores; `steps` the keyframe offsets
to property maps), printed as `@keyframes motion-{name}` and worn as
`animation-name: motion-{name}`; a font is a record
`{family, faces, description, offered}` keyed by name, each face
`{weight, style, source: {file: UUID}}` naming one uploaded `woff2` or
`woff` file, printed as one `@font-face` per face and referenced as the
quoted family in a `font-family` stack (one font per family on a skin).
Both ride the same three operations with `kind: animation` and
`kind: font` (`save_material {kind: font, label, family, faces}` needs the
family; a font's faces edit through `update_material` too).

A material something references (a style, a variant, a skin default,
another token's `var()`, a keyframe step, a font-family stack) refuses
removal with `STYLE_TOKEN_IN_USE`; the Skin tab's delete rewrites every
reference first (a token to its literal, a worn animation to `none`, a
family out of every stack), under "delete skin materials in use". Usage
lives in one table, `daedalus_skin_material_usage`, keyed by the
reference form verbatim (`--space-xl`, `motion-fade_in`, `"Inter"`;
rebuild with `drush daedalus_stylish:skin-material-usage:rebuild`).
Shipped materials keep their identity on a consuming site: a value,
steps, faces, description or offer edit layers over the package's record
and "Revert to defaults" drops the layer, while rename and delete are
refused (`STYLE_TOKEN_SHIPPED_LOCKED`) unless the distro lock is lifted.

Site element defaults are written by one style operation, the site-scope
twin of `update_style`:

```
update_default {scope: 'base:h2', key?: '', condition?: default, properties: {font-size: 'var(--text-title-1)'}}
```

A patch of one scope's defaults under one condition; `null` unsets, and
an unset site value reveals the package's. The `defaults` field itself
has no blueprint write on any skin.

A rule's selector is changed by one operation too, beside `update_style`
on the same handler:

```
move_style {key: 'container:{uuid}::> d-text', to: '> d-text:has(a)'}
```

The rule's entry and every entry continuing it (`> d-text .link`,
`> d-text::before`, `> d-text > *`: `RelativeSelector::continues()`, the
one definition of "under a rule") move whole to the new selector
continued by the same suffix, conditions, properties and the `variants`
list alike, so the rule's worn variants continue under the new selector
by construction. The moved entries are judged at their landings through
the same validator every write passes before anything is written (a
strict site refuses typography landing on `> *`; a worn variant is
judged against the new landing's scope), a landing already present
refuses with `STYLE_MOVE_TARGET_EXISTS` (a move never merges two rules),
and `to` is a selector, never a key: a rule stays with its owner. The
plan is `RuleMove::plan()`, twinned by `js/src/rule-move.js` through the
shared battery `tests/js/rule-move-cases.mjs`. The op is JSON-only: the
page language spells a move as the rule restated under its new selector
with the old one removed.

On a consuming site every material gesture and every `update_default`
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
(both `administer skins`); the skin file recompiles on the choice.

### Authoring on the canvas

Everything a skin holds is edited in Edit Mode's **Design system**
place (the inspector's second place, headed by the active system's name
and enforcement mode), with the page as the preview. The place has two
tabs, **Skin** and **Variants**, and a third, **Law**, for the account
that may see it:

- **The Skin tab** lists the worn skin's tokens per scale, then its
  Animations and Fonts, under one search; "+ New" mints a token (on any
  declared scale: a color field, the gradient builder, a dimension or
  text field by the scale's shape), an animation (the keyframes editor)
  or a font (`woff2` or `woff` files, one face per file, weight and
  style read from the file name and editable). Every row is a preview,
  a name and a value summary, with no origin chip; it opens to the one
  **material card**: the spelling to reference it by, the description,
  the AI-offer toggle, the use count, a token's conditions, the kind's
  value slot (live on the canvas before commit), and the actions Edit,
  Copy to new (tokens and animations), Revert to defaults, Rename and
  Delete. On a consuming site the card says "Shipped by {provider}.
  Your value layers over it." and shows Rename and Delete disabled with
  the reason; rows the account cannot write render read-only. The
  typography Font row offers the skin's families as chips when building
  a stack; unbound color rows and image slots offer the offered tokens
  of the color- and gradient-shaped scales.
- **The skin card** at the top of the tab shows the skin worn, the
  conforming skins to choose from, "Copy as my skin" (a new unlocked
  skin holding the merge of any skin on the site, picked from a source
  select with the worn skin preselected; the copy is always a member of
  the ACTIVE design system whatever its source belonged to, and a
  site's own unit, one with no package and no default skin, takes its
  first copy as its `default_skin` and wears it at once, which is how
  a design system created on the site gets its first skin; any other
  copy is chosen as a separate gesture), the revision list with
  restore, and "Revert the site layer" on a shipped skin. The skin's
  revisions are the one lifecycle: a material has no revisions of its
  own, so a bad push is one revert away.
- **"Edit skin…"** on any property row or code line whose value the
  skin supplies enters a session on that scope's default (`base:h2` for
  a heading's size, `block_type:text` for a text block, `kind:container`
  for every container), staged and pushed as a variant's document is;
  Push commits `update_default` and every element of the scope
  restyles. The session follows the selection from one wearer of the
  scope to the next and exits on an element outside it.
- **The Variants tab** is the system's looks: members of the design
  system written in token names, so they survive a skin swap; unchanged
  but for the card, which states who ships a variant.
- **The Law tab** renders the style guide read-only: the scales and
  what binds to them, the contract, and per kind, block type, block
  plugin and layout the grants, constraints, curated stops, axes and
  birth defaults, each with the provider and file that supplied it,
  read under the active system's enforcement mode. A row whose value a
  declaration supplies opens the Law tab at that declaration. Law is
  authored in files; the tab shows it. The tab and that doorway exist
  only for an account holding `view style law`, a permission granted to
  no role by default (an administrator role holds it as it holds every
  permission); without it the row still names the declaration.

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

One family for the skin's three sections: `create skin materials`
(extend the skin with a token, an animation or a font), `update skin
materials` (change a material's facts, on a consuming site the site's
layer over the shipped record), `delete skin materials` and `delete skin
materials in use`, `edit skin defaults` (the `update_default`
operation), and `administer skins` for choose, copy, restore, revert and
the delete form, granting every gesture above as well. Variants keep
their own family (`create`, `update`, `delete`, `delete variants in use`,
`administer variants`). Styling a page's elements is not a material
permission: it is the capability permission under "Who may style"
(`style {bundle} {type} elements`, `style any elements`).

### The skin file

The compiled skin stylesheet (`SkinStylesheet`, library
`daedalus_stylish/skin`) is one print of the worn skin: its `@font-face`
blocks first (one per face, `font-display: swap`; the inspector re-reads
them from `GET /daedalus_stylish/skin/fonts/stylesheet` after a font
write), then the declared tier, the tokens, the `@keyframes` and the
defaults, sorted before the variants file so an explicit variant choice
beats a default at equal specificity and a variant may wear the skin's
motion. A font's files are held for the skin through core's file usage
(every file a face of either layer names, across the skin's revisions,
released when the skin is deleted); a font's upload door is
`POST /daedalus_stylish/skin/fonts/files` (daedalus_ui's file lane,
`woff2` and `woff`). Deleting a font in use drops the family from every
stack first (a stack that named nothing else becomes `initial`), and the
rename of a font is immediate because stacks name the family, never the
name. Both compiled files are projections of the law, so every law change
(a skin write, an enforcement edit, a settings save, a declaration change,
a default-theme change, a design-system switch) recompiles both and
carries the verdict's flips, the variants the change withheld or offered,
on the materials delta to every open editor.

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
| `MODULE.daedalus_stylish.skins.yml`, `MODULE.daedalus_stylish.variants.yml` | The hand-authored single file per kind: comment-heavy, written by a person, never by a machine. A skin entry carries `tokens:`, `animations:`, `fonts:` and `defaults:`; a font's files sit under `assets/fonts/` and a face names one by basename (`source: {file: inter-regular.woff2}`). |
| `MODULE.daedalus_stylish.<kind>.ID.yml` (e.g. `MODULE.daedalus_stylish.variants.card.yml`) | The per-id file: the machine-written form, one file per shipped id beside the single file. This is what the ship lane writes. |

A machine name shipped in two of one module's files is a developer error
and fails the sync. Per-id files are always written whole, so an entry the
ship no longer includes leaves disk. A skin file's tokens may spell a bare
value or the whole record per token (`{value, description, offered}`); a
machine-written skin file holds records, so a token's reasoning travels
as its description, never as a YAML comment.

A package's per-id lane writes one file per kind, each kind with its own
shipper: `MODULE.daedalus_stylish.skins.ID.yml` (with its fonts' files
under `assets/fonts/`) and `MODULE.daedalus_stylish.variants.ID.yml`. The
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
my_brand: skin dawn updated
my_brand: variant card_blue updated
my_brand: variant hero_title kept (site-edited; new defaults on disk)
```

Outcomes are `created`, `updated` (disk moved, the site had not), `unchanged`
(disk did not move, whether or not the site edited the entity: a sync never
carries a site edit to disk, only a ship does), `kept` (the site edited the
entity AND disk moved; the site's edit wins and the new defaults wait), and
`failed` (printed last with path, code, message and hint; non-zero exit).
A sync fails a definition on structure alone, so a variant the site's
current law refuses still lands (`created` or `updated`) and the roster's
verdict withholds it; the command names each such landing after the
outcomes, `MODULE: variant NAME landed, withheld under the current law
(ENFORCEMENT): path [CODE]: message; ...`, with the refusals a site author
would read at the save gate. It is not a failure and the log stays silent
about it: the variant is offered and delivered when the law changes,
with no re-sync.
Kinds sync in dependency order: skins, then variants (a variant may wear
a skin's animation). A skin's `fonts:` tier is hashed content-addressed
(a sha256 per face's bytes), so one hash compares the disk's basenames
with the site's files. A skin is never `kept`: the sync writes its package layer alone,
so new disk defaults always land there while the site's layer rides the
new revision untouched (each layer has one author; a developer editing the
package layer under the lifted lock ships before pulling). A module
packaging a design system that is not the active one is deferred whole;
its definitions land when its system becomes active (its activation, or
its config landing under a pointer that already names it), which re-syncs
every enabled module under the incoming law. Skins are deferred for every
module while `system.theme` names a default theme that is not installed
(`MODULE: skins deferred; the default theme "THEME" is not installed.
They land when it is.`); the theme landing re-syncs every enabled module
the same way.

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
current entities, and every skin and variant that is a member of the
system and nothing ships yet is adopted into the module, a new name
landing in `MODULE.daedalus_stylish.<kind>.NAME.yml`.

```bash
# Ship the my_system design system into its package, my_brand.
drush daedalus-blueprint:design-system:ship my_system

# The identical report, with no file written and no entity touched.
drush daedalus-blueprint:design-system:ship my_system --dry-run
```

One line per entry in the sync command's grammar (the unit and the
blueprints print first, then this module's kinds):

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
everything else ships. A skin's fonts ship with their files: each face's
file is copied under `assets/fonts/` before the yml is written. A skin
ships its package layer only; the site layer is the site's and never reaches disk
(on a development site with the lock lifted, skin writes land in the
package layer, so there is nothing to lose).

After a ship the site is consistent with disk: every shipped entity is
re-stamped with the module as provider and the written payload as its
source hash, and published, so `drush daedalus_stylish:sync MODULE` right after
reports `unchanged` for every name.

### The distro lock

Shipped variants are read-only on a site: the package's files are their
one author, and a site that wrote into them would drift away from every
future sync. The site forks a material instead ("Copy as my variant",
"Copy as my skin") or reverts toward the disk. A shipped skin's package
layer (its tokens, animations, fonts and defaults as shipped) is locked
the same way, and the skin is the one shipped material a consuming site
keeps authoring: every material gesture and every `update_default` lands
in the skin's own site layer, which sync never writes and which never
ships, so a tenant extends and overrides the shipped look without
touching it. A shipped token, animation or font cannot be deleted or
renamed on a consuming site; a site override on it can be dropped to
reveal the shipped record.

The package author works the other way round: the site is the canvas and
drush carries the result back into the module. On such a development site,
lift the lock in `settings.php`:

```php
$settings['daedalus_blueprint_developer'] = TRUE;
```

The flag is read by daedalus_blueprint's `daedalus_blueprint.distro_lock` service,
the one fact every shipped-material gate in daedalus_stylish consults: the variant
manager (push, details and value edits, revision restore, delete), the
skin material and skin gestures' layer rule (with the lock lifted a
material write, an `update_default` and a revision restore land in a
shipped skin's package layer, and a shipped token, animation or font may
be renamed or deleted), the blueprint lane field handlers and their guidance text, and
the materials provider's `updatable` record flag that the inspector's
library and push bar read. It is a setting rather than a permission because
writable module directories are an environment property: a development site
needs no role setup, and a production administrator cannot lift a lock that
protects production. Roster records are cached, so rebuild caches
(`drush cr`) after flipping it.

The author's flow: change the system on the site (re-value or mint
tokens, animations and fonts in the Skin tab, push a skin session's
defaults, override a placement and push into its variant), save the page, then
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
drush daedalus_stylish:skin-material-usage:rebuild
```

## Maintainers

- Tim Bozeman - https://www.drupal.org/u/tim-bozeman
