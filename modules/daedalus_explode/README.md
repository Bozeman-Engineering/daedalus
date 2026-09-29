# Daedalus Explode

Daedalus Explode turns the edit-mode canvas into a tilted, exploded 3D
stack: every projected element lifts along Z by its depth in the page
elements projection, so the hierarchy the page builder teaches (sections,
regions, blocks, fields, parts) reads as physical layers with the real
page content on each. It is an orientation view for editors and the shot
for teaching the hierarchy on video. Enable the module and the view is
there; nothing else to configure.

## The panel

Explode is a right-rail panel in edit mode, label "Explode". Its open
state IS the exploded state: open the panel and the canvas explodes,
close it and the canvas settles back flat (both animate). It has no
button in the top bar. Its opener is an entry in the Page elements
panel's Show menu (the kebab at the end of that panel's header): a
checked item with a stack glyph, checked while the panel is open, that
toggles the panel. The hotkey `x` toggles it too (a panel toggle binding,
remappable in the hotkey settings like every other panel's; with no
button to click, the binding toggles the panel through the sidebar
manager). It is not a tool, so it composes with every tool: select, edit
and style while exploded, and a click on a lifted plane still selects
that element, since the browser hit-tests the projected planes. The panel
takes the right rail's top slot, the slot the Styles inspector holds, so
the two are exclusive there and opening Explode parks the inspector.
Because the sidebar manager remembers open panels in a cookie, a page
reloaded with Explode open comes back exploded.

Each plane wears a muted outline, a translucent surface so the layers
below read through, a soft shadow and a label chip on its top edge naming
the element (blocks name their type, since the plane already shows what
the block says). The selection paints oversized: the primary in the
selection color with a heavy stroke standing off the plane, a glow and a
larger chip, members of a multi-selection lighter, hover dashed, every
measure divided by the stage scale so the treatment reads at the same
on-screen size at any zoom (the selection frame hides while exploded,
so this paint is what marks the selection). Keyboard traversal (ArrowUp and
ArrowDown walk the projection) reads as the selection climbing and
descending the stack.

## Controls

| Control | Range | What it does |
|---|---|---|
| Tilt | 0 to 70 degrees | How far the page leans back. |
| Turn | -60 to 60 degrees | How far the page turns on the spot. |
| Gap | 0 to 200 px | The space between one layer and the next. |
| Zoom | 10% to 200% | How large the exploded stack draws, as a percentage of the flat page: a slider scrubbing by the percent, with minus and plus steppers moving ten points at a time. The selection paint divides by it, so the selection stays the same on-screen size at any zoom. |
| Depth cap | 1 to the page's deepest layer, then All | Layers deeper than the cap settle onto the last lifted one, unlabeled. |
| Labels | on/off | Name each layer on its top edge. |
| Isolate selection | on/off, default off | Lift only the selection, its ancestors and its descendants; everything else stays flat. Inert with nothing selected. |
| Reset | | Return every control to its default. |

There is no Parts control: Explode lifts exactly what the Page elements
panel depicts, so the parts inside each element (headings, links, icons)
lift onto layers of their own while the panel's Markup view is on and lie
flat while it is off, and a rich-text carrier row lifts in every view. Part
planes print through the registered part behavior (daedalus_stylish's),
so without one no part planes appear.

The defaults (tilt 38, turn -22, zoom 62%, gap 90, all layers, labels on) are the
constants that proved the form. The values are visual toggles, not editor
preferences, so they persist client-side in one localStorage key,
`daedalusExplode`, per browser; nothing is sent to the server.

## How it works

The view is one printed stylesheet over the real DOM, never a copy of the
page and never a write into the canvas markup. The host reads the
projection index that daedalus_ui exposes on the app API (`app.tree.index()`
and `app.tree.root()`, the one map of the page's addressable elements) and
its reach (`app.tree.reachable`: which of those the Page elements panel
currently depicts), runs them through a pure plane model (depth from
ancestry, the cap, isolate) and a pure stylesheet printer, and injects the
result as `<style id="daedalus-explode">` in `<head>`. It re-prints on
every blueprint, selection, hover, view-visibility and settings change. Element planes are
addressed as `[data-selectable="key"]`; part planes go through the part
behavior's `selectorFor`, the same scoped-rule grammar the Styles
selector paint uses, so nothing is stamped onto the page.

Because the planes are the real elements under a real 3D transform,
everything that paints on the elements themselves (the Styles inspector's
selector paint, a hover outline) stays truthful while exploded, and the
Styles panel's canvas paint keeps lining up with what the video shows.

### The canvas-view attribute

While the panel is open the module sets
`data-daedalus-ui-canvas-view="exploded"` on `<html>` and removes it once
the return transition has settled. That attribute is daedalus_ui's contract
for a projected canvas (see `daedalus_ui.api.php`, section `canvas_view`):
every owner of chrome positioned from an element's bounding rect (the
selection frame, insertion caret and selection indicator, the canvas
vector's handles and ruler, daedalus_stylish's highlight bands and paint
canvas) hides under it in its own stylesheet, because a bounding rect
measured under a 3D transform is the flat box of a projected quad and the
chrome would float away from the planes. Explode only writes the
attribute; it never names another module's classes. A theme or module with
rect-measured chrome of its own should carry the same
`html[data-daedalus-ui-canvas-view] … { display: none }` rule.

### The neutralizer and its trade

A 3D stack needs `transform-style: preserve-3d` on every wrapper between
the stage and the lifted elements, and any wrapper that flattens 3D
(isolation, overflow clipping, opacity, filter, clip-path, contain,
will-change) would squash the layers beneath it back onto one plane. So
while exploded the printed sheet neutralizes those properties on every
wrapper inside `#daedalus-ui-stage` that contains a selectable element
(`#daedalus-ui-stage :has([data-selectable])`), and releases the stage's
own isolation; the stage's transform then supplies the stacking context
that keeps the theme's z-indexes confined.

The trade: those wrappers lose their clipping and compositing while the
view is open, and a theme's sticky or fixed elements inside the canvas
(a sticky header, a fixed back-to-top button, an overflow-scrolling
region) may sit oddly or scroll with the page while exploded, since a
transformed ancestor becomes their containing block. Structural wrappers
only are touched, and nothing outside the canvas stage (the admin
toolbar, the editing chrome) is affected. Closing the panel restores the
theme exactly; the sheet is inert once the attribute drops.

### Theming hooks

The printed sheet reads, and never sets, four custom properties a theme
may define: `--daedalus-explode-perspective` (default `2400px`),
`--daedalus-explode-duration` (the explode and return transition,
`600ms`), `--daedalus-explode-surface` (the plane's translucent surface,
`rgb(255 255 255 / 0.72)`) and `--daedalus-explode-shadow`.

## Development

The bundle `js/dist/explode.bundle.js` is built from `js/src/index.js` by
the root `package.json` (`npm run build:prod` from the daedalus
directory) and is committed. The plane model, the stylesheet printer and
the settings store are pure and covered by node specs: from this
directory, `node --test tests/js/*.test.mjs`. The browser wiring (the
hotkey to the attribute to a real `matrix3d` transform on a block, and
back) is a FunctionalJavascript scenario's job under
`tests/src/FunctionalJavascript`.
