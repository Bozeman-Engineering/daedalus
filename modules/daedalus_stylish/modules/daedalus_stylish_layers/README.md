# Daedalus Stylish Layers

Makes user-authored Daedalus Stylish CSS **structurally** win the cascade — no
specificity hacks, no `!important` arms race — by wrapping everything else
(core, contrib, theme) in a CSS cascade layer while Daedalus Stylish's own output
stays unlayered. For normal declarations, unlayered author styles beat ALL
layered author styles regardless of specificity.

## The layer contract

On every page the module emits, as the **first stylesheet content in
`<head>`**:

```css
@layer daedalus_stylish_force, site;
```

The first occurrence of a layer name establishes layer order, so this
statement is the single source of truth for precedence. It is attached as a
`html_head` element with weight `-999` (directly after the charset meta,
which must stay first for security), and `html_head` renders into
`head-placeholder`, which `html.html.twig` places before `css-placeholder`
in core, Olivero, and Claro alike — the statement therefore always precedes
every pipeline stylesheet.

The resulting cascade, weakest to strongest, for **normal** declarations:

1. `daedalus_stylish_force` layer (ships empty)
2. `site` wrapper layer — ALL pipeline CSS: core, contrib, theme
3. Unlayered styles — Daedalus Stylish's emitted `@scope` styles (and any pipeline
   escapees: other modules' inline `<style>` tags, JS-injected stylesheets,
   `html_head` links — those never pass through the asset pipeline and keep
   competing on raw specificity)

### `daedalus_stylish_force`: the empty `!important` escape hatch

Importance REVERSES layer order: `!important` in a layered rule beats
`!important` in an unlayered rule, and the EARLIEST layer's `!important`
beats everything else. Without the force layer, layering would make a theme
`!important` stronger than a user `!important`.

`daedalus_stylish_force` is declared first and **ships empty**. Its contract:

- It exists solely so Daedalus Stylish can route rare must-win `!important`
  declarations into it (`@layer daedalus_stylish_force { ... !important }`) — those
  then outrank every other declaration on the page, layered or not.
- Nothing else may add rules to it, and normal (non-`!important`)
  declarations must never be placed in it — as the earliest layer it is the
  WEAKEST place for normal declarations.

### Wrapper layer name

`site` by default; configurable via `daedalus_stylish_layers.settings.wrapper_layer`
(validated as a CSS identifier, falls back to `site`). A theme stylesheet
declaring its own `@layer` blocks simply nests inside the wrapper
(`site.foo`) and can never outrank unlayered styles. Collision — escaped
(never-wrapped) CSS declaring the same top-level name — is theoretical and
accepted; rename via settings if it ever bites.

## How wrapping happens: the stamp

Config never reaches the asset decorators directly. `hook_css_alter()` stamps
every non-exempt CSS asset with the wrapper layer name
(`$asset['daedalus_stylish_layers'] = 'site'`), and everything downstream keys on that
stamp alone:

- the **grouper** decorator splits aggregate groups so each is entirely
  stamped or entirely unstamped;
- the **collection optimizer** decorator wraps stamped aggregates in
  `@layer <stamp> { ... }`, re-emitting surviving `@import`s as
  `@import ... layer(<stamp>) ...;` before the block;
- the **collection renderer** decorator rewrites stamped non-aggregate links
  (dev mode, `preprocess: false`, external) into
  `<style>@import url("...") layer(<stamp>);</style>`.

Because item-level asset keys feed core's aggregate filename hash, any config
or exemption change produces **new aggregate URLs** — stale files at old URLs
are simply never referenced again, and the alter hook fires identically on
page render and in the asset controller, so URL and content always agree.

### AJAX delivery: plain links, no rewrite

Core's `add_css` AJAX command only carries attribute maps that the client
rebuilds as `<link>` elements. A rewritten `<style>@import ...</style>`
element has no attributes and is silently dropped from the command (and a
response whose new CSS is all layered yields an *empty* `add_css` command,
which never resolves client-side and hangs the command queue). An
`ajax_response.attachments_processor` decorator therefore suppresses the
renderer's rewrite while AJAX attachments are processed: an AJAX-delivered,
non-aggregated stylesheet loads **unlayered** until the next full page
render. Aggregated responses lose nothing: the layer is inside the
aggregate file itself.

### Exempting a library

Set the key on any CSS entry in a `*.libraries.yml` to keep it out of the
wrapper layer (it then competes unlayered, like Daedalus Stylish's own output):

```yaml
css:
  component:
    css/chrome.css: { daedalus_stylish_layers_exempt: true }
```

Use this for editor/admin chrome that must never lose to page CSS, or for a
stylesheet whose own `@layer` usage must keep page-level meaning (wrapped, it
would become a sub-layer). Daedalus Stylish's inspector chrome is exempt this way, and
so is the compiled variant stylesheet — user-authored CSS delivered as a file
must compete unlayered exactly like the inline output. The key works the same
in dynamic definitions from `hook_library_info_build()`. Modules can also set
`daedalus_stylish_layers_exempt` on assets from their own `hook_css_alter()` to exempt
stylesheets they don't define — stamping is forced to run after all other
modules' css alters.

Links carrying extra attributes (`integrity`, `crossorigin`, ...) are never
rewritten — `@import` cannot express them — so such stylesheets stay
unlayered as well.

## Runtime switch

`daedalus_stylish_layers.settings.enabled` (default TRUE — installing the module is
the switch). Disabling stops emitting the statement and, by withholding the
stamps, all wrapping/rewriting.

## Scope

This module is generic — it does not depend on the parent `daedalus_stylish` module —
so extraction to standalone contrib (or upstreaming) stays cheap. The Daedalus Stylish
recipe enables it by default.
