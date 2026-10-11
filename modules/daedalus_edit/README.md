# Daedalus Edit

Daedalus Edit adds inline editing functionality. It does so by loading the entity
form in the sidebar. When you open a page element's edit (double-click it, or
select it and press Enter), it is replaced by the hidden form element. Doing it
in this way means that all the back end is the same as the `/node/1/edit` page,
so form_alters and what not still work.

## One transport, every host

Daedalus Edit commits through the blueprint spine against the page
document's HOST (daedalus_blueprint's `HostInterface`), resolved once from
the route: a page, or a template (a Layout Builder enabled entity view
display on core's manage-display layout route). The routes address one
host as `{entity_type}/{entity}`: the entity form at
`/daedalus-edit/entity-form/{entity_type}/{entity}`, Save at
`/daedalus-edit/tempstore/save/{entity_type}/{entity}` and Discard at
`/daedalus-edit/tempstore/delete-confirm/{entity_type}/{entity}`, each
requiring the entity's update access beside the permission; the block
form endpoint (`/daedalus-edit/block-form/...`) asks update access on the
storage's host and is forbidden for a storage no host owns. What a write
may change is the spine's verdict, judged at the op door for the batch's
actor: the Edit verb declares `capability: content`, like every verb in
daedalus_ui's registry, and an element the actor cannot edit is not
selectable at all. On a template the sidebar's root door is inert (a
display has no entity form) and the field blocks show sample content
that is never editable. On a page that renders a template it does not
own, the template's elements cannot be selected, while a field block
presenting one of the page's own fields carries that field's content
verdict and edits in place; the notice at the top of the page elements
panel names the template.

## The Configure dialog

Every block plugin's Configure dialog commits through the spine as an
`update_element`: an inline block's form values ride as block fields, and
a plugin block's (a field block's formatter, a view's display id, a menu's
depth) as its configuration bag, derived from the plugin's own config
schema and diffed so only what changed travels. Core's tempstore submit
never runs on a hosted storage: the alters throw where they once fell
back onto it (an inline block whose dialog built no block form, the
add-section variant, a section without a uuid), since core's own Layout
Builder write routes are closed for any storage Daedalus hosts; a storage
with no host keeps core's path. A plugin that declares no config schema is
placeable but not configurable: its dialog answers `NO_PLUGIN_SCHEMA`, and
adding schema is how it joins.

## Editing the page title

The entity's label field presents AS the page row in the element model, but
on most sites the label never renders inside the entity: the H1 is the
theme's page-title block. Daedalus Edit attributes that render as the title field's
editable leaf (`PageTitleEditing`, mirroring the inline-block label
attribution), so the H1 is the title's editable leaf with the same pointer
door as any leaf: hovering it names the page row, a click selects the page
row, a double-click edits the title in place, staged to tempstore like any
field, and the Tab walk reaches it as the page row's leaf. The page row's
own Enter and Edit verb are a different door: they open the Page form in
the sidebar on every theme (the panel is the root's editor, whether or not
a page-title block is placed) and never route to the H1; the panel's Title
widget and the page elements tree's root rename are the title's other two
doors, and all three commit the same `update_element` on the label. A
label write from any road (the form, an AI turn, undo) re-renders the H1
by its key through the mutation response. Attribution only happens when the page title is
provably the label's ONE render (a node's non-configurable title, or a
label on a Layout Builder display); anything ambiguous stays
inert so leaf identities never duplicate.

# Troubleshooting

Daedalus Edit works by loading the relevant entity form into the sidebar as a
"context" sidebar. The form items are hidden initially, but when an editable
element's edit is opened on the page (a double-click, or Enter on the selection)
the form item is swapped into place for inline editing.

### Inline Editing z-index

If the inline editing z-index is undesirable for your theme override it with
the rule below. Since the inline editor is visually moved out of the sidebar,
it inherits whatever the sidebar z-index is.

```css
.daedalus-ui-sidebar {
  z-index: calc(var(--daedalus-ui-right-sidebar-z-index, 10) + 1);
}
```

### Field templates

In order for Daedalus Edit to be able to edit a field, the field template must have
the wrapper and item attributes printed to the page. All core field templates
do something like this to include the wrapper and item attributes. Ensure your
field templates do too if you want them to be inline editable.

```twig
<div{{ attributes }}>
  {% for item in items %}
    <div{{ item.attributes }}>
      {{ item }}
    </div>
  {% endfor %}
</div>
```

If you need to access the things inside the item you need a little ternary.

```twig
<div{{ attributes }}>
  {% for item in items %}
    {% set item = item.content.edit_mode_wrapper is defined
      ? item.content.edit_mode_wrapper
      : item %}
    {% set item_attributes = item.attributes is defined
      ? item.attributes
      : create_attribute(item['#attributes']) %}
    <div{{ item_attributes }}>
      {{ item[0]['start']['time']['#markup'] }}
    </div>
  {% endfor %}
</div>
```

Going into a node template and directly outputting a field with twig like the
following will prevent the Daedalus Edit UI from working since the field markup will
not be attributed.

```twig
# Don't do this! Use a field template instead.
{{ content.field_event_date[0]['start']['time']['#markup'] }}
{{ content|without('field_event_date') }}
```

Instead, move this kind of stuff to a field template.

### Messages

Messages are appended to the `<div data-drupal-messages-fallback></div>`
element. Ensure your pages have one of those.

### Inline CKEditor floating panel

If you have a fixed header or the ckeditor floating panel is for some other
reason looking funky when you scroll it to the top of the page, you can set
the offset with this. The value is the distance in pixels from the top of the
screen where you want the ckeditor buttons to stop.

```php
/**
 * Implements hook_page_attachments().
 */
function my_module_page_attachments(array &$attachments) {
  $settings = &$attachments['#attached']['drupalSettings'];
  $settings['DaedalusEdit']['InlineEditor']['viewportOffset'] = 113;
}
```
