# Daedalus Edit

Daedalus Edit adds inline editing functionality. It does so by loading the entity
form in the sidebar. When you click to edit a page element, it is replaced by
the hidden form element. Doing it in this way means that all the back end is
the same as the `/node/1/edit` page, so form_alters and what not still work.

## Editing the page title

The entity's label field presents AS the page row in the element model, but
on most sites the label never renders inside the entity: the H1 is the
theme's page-title block. Daedalus Edit attributes that render as the title field's
editable leaf (`PageTitleEditing`, mirroring the inline-block label
attribution), so clicking the H1 edits the title in place and stages it to
tempstore like any field. The page row's own Edit verb is a different door:
it opens the Page form in the sidebar on every theme (the panel is the root's
editor, whether or not a page-title block is placed) and never routes to the
H1. Attribution only happens when the page title is provably the label's ONE
render (a node's non-configurable title, or an unpreserved label on a Layout
Builder display); anything ambiguous stays inert so leaf identities never
duplicate.

# Troubleshooting

Daedalus Edit works by loading the relevant entity form into the sidebar as a
"context" sidebar. The form items are hidden initially, but when an editable
element is clicked on the page the form item is swapped into place for inline
editing.

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
