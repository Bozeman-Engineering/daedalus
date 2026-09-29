# Daedalus UI

Daedalus UI provides Mode plugins so that developers can create modes that take
over the Navigation sidebar.

## Edit mode

Daedalus UI provides Edit mode. When a user enters Edit mode the Navigation
sidebar is replaced with the editing chrome: a top bar of panel toggles and
document actions, and sidebar panels. Edit Mode is the foundation of the
Daedalus. It has one pointer and one keyboard grammar over the one selection;
editing is a state of the selection, opened by gesture (double-click a leaf,
Enter, the Edit chip), and other modules such as [Daedalus Layout](https://www.drupal.org/project/daedalus)
and [Daedalus Edit](https://www.drupal.org/project/daedalus) plug into it through
sidebar plugins, verbs, the editing engine and the registration seam. The goal
is remove the need for Edit and Layout tabs to create content. Daedalus enables
in place editing.

### Hotkeys
Panel toggles, verbs and the selection grammar's keys are hotkeys. Hover over a
top bar button to see its hotkey in the tooltip. Hotkeys can be remapped per
user from the Settings sidebar in Edit Mode.

### The selection frame
One frame (`js/edit_mode/components/selection-frame/`) owns everything anchored
to the selection: the strokes, a chip per member of the multi-select set reading
`N Type` (N is the member's set-order number, shown for a real set; Type comes
from the one type resolver, `js/edit_mode/selection/type-for-key.js`: what the
element IS ("Text", "Section"), since the canvas already shows what it says,
while the tree, breadcrumb, and AI chips read the one label resolver,
`selection/label-for-key.js`, what the element SAYS; neither resolver derives
a word: each reads the delivered field, `_element.type` and `_element.name`,
that `BlueprintElementAnnotator` mints once on the server with `t()`, see "The
element model", so every surface in every host words an element the same
way), and on the primary's chip the verb face, which follows the
chrome-density ladder: at full density every verb as icon plus text label, at
minimal the same verbs as icons only, at clean the registry's `tab`-flagged
verbs as icon buttons plus a chevron opening the context menu with the rest. The
stroke is dashed while an edit is open on the primary. Other modules anchor chrome to
the selection by registering verbs
(`Drupal.Daedalus.Verbs.register(spec)`), never with a positioning loop of
their own; an edit-scoped verb (`editScoped: true`) applies only while the
store's editingKey is the chip's key, and a verb with a `widget` renders its own
control in the face's widget slot, re-run on density flips so widget entries
follow the same density ladder as plain verbs.

## The element model

Daedalus UI owns the page builder's element model: one answer to "what does
this thing render as, and what of it is addressable?" that every consumer
(the Page elements panel, canvas attribution, styling guides, the AI schema)
projects from instead of keeping a registry of its own. The model keeps two
axes distinct:

- **Existence**: does anything answer to this key, in any view. A
  nonexistent field (`element: false`) has no row, no canvas element, no
  styling surface, and no view ever bypasses that.
- **Presentation**: how an existing element presents, one of `open`
  (structure shown), `unit` (one element, drill in to reach structure), or
  `merged` (a container presents AS its one meaningful child).

### Declarations: the PROVIDER.page_builder.yml file family

Modules and themes declare anatomy and element facts in one layered file
family at their root: `PROVIDER.page_builder.yml` (universal, keyed by
element kind, plus the reserved `site` key for site-scope vocabulary),
`PROVIDER.page_builder.block_type.BUNDLE.yml`, and
`PROVIDER.page_builder.layout.LAYOUT_ID.yml`. Themes layer over modules
(anatomy follows templates), and `hook_page_builder_declarations_alter()`
has final say. Sections daedalus_ui owns: `elements` (the markup-shell
anatomy tree), `fields` (existence + presentation per field),
`presentation`, and `collapsed`. Foreign sections (e.g. Daedalus Stylish's styling
vocabulary) ride the same files untouched. See
`\Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations` for the
format and `daedalus_ui.api.php` for the alter hook.

### The ElementModel service and the `_element` annotation

`daedalus_ui.element_model` resolves each decision with the precedence
site-builder third-party settings → declarations → inference (pluggable
`daedalus_ui.existence_feeder` tagged services; Daedalus Layout feeds
root-field existence from its preserved fields). Delivered blueprints carry
the result as one uniform `_element` annotation on every owning node, the
root included, and client code consumes only that annotation, never
hardcoded field lists.

The annotation also carries the element's two words, minted once with `t()`
by `BlueprintElementAnnotator` and never derived by a client: `_element.type`
(what the element IS: "Section" for a container at the root layout,
"Container" nested, an inline block's block type label, a field block's field
label, the root's bundle label) and `_element.name` (what it SAYS: a
section's custom label else its type, a merged block's content excerpt else
its presentation label else its unrendered configured label else its type, the
root's entity label). Both ride the lean `_tree`, so the Page elements tree,
the canvas chips, the breadcrumb, the stylish inspector in every host (leader
page, satellite pop-out, node specs), the explain tool and the AI dialect all
read the same delivered field.

### Field presentation UI

Site builders set a field's presentation on the field's configuration form,
in the shared "Page builder" section. Other page-builder modules co-locate
their field settings there via
`FieldConfigFormAlter::ensurePageBuilderSection()`: create the section if
missing, park elements under `$form['page_builder']`, and route values into
core's `third_party_settings` tree with `#parents` so core persists them
without custom submit mapping.

## The icon value grammar

The page builder's icon value grammar lives on the spine, so every surface
that stores "an icon" (block-type icons, stencil icons) stores the same
one-string value and resolves it through the same seam,
`daedalus_blueprint.icon_resolver` (`\Drupal\daedalus_blueprint\IconResolver`);
Daedalus UI chrome resolves through it like every other consumer:

- `media:<uuid>`: a media entity referenced by UUID. The user lane, written
  by media library pickers; resolves to the media source file's URL
  (original file, no image style).
- anything else: a root-relative URL path such as
  `/modules/contrib/daedalus/modules/daedalus_layout/assets/image.svg`. The shipped lane for configs
  and recipes, returned verbatim. Producers with friendlier spellings (e.g.
  a module-relative path in a YAML definition) must resolve them to
  root-relative before storage.

Empty values and dangling media references resolve to NULL, and each
consumer renders its own default icon; the resolver never imposes one. See
the class docblock for the full contract, including cache tag handling.

## Theming requirements
### Entity templates must print `{{ attributes }}` on their outermost element
The editing UI addresses entities through attributes stamped on the entity's
render array (`#attributes`): the AJAX-refresh wrapper id
(`data-daedalus-ui-entity-wrapper`), the view mode, the entity root's
selectable key, and the main-entity markers. Core merges `#attributes` into
the template's `{{ attributes }}` variable, so they only reach the page when
the entity template (node, block, media, ...) prints `{{ attributes }}` on
its outermost element, as all core and Olivero/Claro templates do.

If a template fails to print `{{ attributes }}`, Daedalus UI wraps that
entity's rendered output in a fallback `<div>` carrying the attributes and
logs a warning naming the template. Editing keeps working, but the editor
sees an extra wrapper element the live page does not have; fix the template
to restore markup fidelity.

## Debug logging
Daedalus UI owns the Daedalus's client-side debug logging, so no module has
to grow its own. Depend on the standalone `daedalus_ui/logging` library
(which also orders the file ahead of yours) and grab a tagged logger:

```js
const {log} = Drupal.Daedalus.logger('my_module');
log('apply', 'Applying variant', payload); // [my_module:apply] Applying variant
```

Nothing prints until that module is switched on from the browser console. The
choice persists in localStorage, so it survives reloads:

```js
Drupal.Daedalus.logging.enable('my_module');
Drupal.Daedalus.logging.enable();  // Everything.
Drupal.Daedalus.logging.disable('my_module');
Drupal.Daedalus.logging.disable(); // Everything off.
Drupal.Daedalus.logging.status();  // What is on, and what could be.
```

`status().modules` lists every module that asked for a logger on this page,
which is the menu `enable()` accepts. The logger also exposes a live `enabled`
flag, so a hot path can skip building expensive log data.

## Issues
### Module Defined Templates
If you need to define templates in a module you need [to temporarily set a `use_twig_events` flag](https://www.drupal.org/project/daedalus/issues/3548969) in the hook theme.
```php
/**
 * Implements hook_theme().
 */
function my_module_theme($existing, $type, $theme, $path) {
  return [
    'block__block_content__view_type__my_type__default' => [
      'base hook' => 'block',
      'path' => $path . '/templates',
      'use_twig_events' => TRUE,
    ],
  ];
}
```
