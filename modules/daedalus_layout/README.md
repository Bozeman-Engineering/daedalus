# Daedalus Layout

Daedalus Layout is a drop in replacement for Layout Builder.

### Installation
Install Daedalus Layout as normal. Visit https://www.drupal.org/node/1897420 for
more information.

### Daedalus Layout UI
- Goto /admin/structure/types/manage/page/display
- Check "Use Layout builder"
- Check "Allow each content item to have its layout customized"
- Click Save
- Configure a default layout section
- Promote some blocks
- (Optional) Manage the default layout and remove all sections

### Nested Layouts
Sections nest natively: place a section inside another section, at any
depth. There is no special nestable kind and nothing to enable; a nested
section is an ordinary section that happens to live inside another one.
With the Daedalus's container layout, columns are styling (the row
arrangement variant on the container plus per-child flex-grow where a
ratio deviates from equal), never a layout choice.

#### UI Colors
To set your UI colors to match your theme go to Manage > Configuration >
Content authoring > Daedalus Layout.

# Recommended modules
- [Field Sample Value](https://www.drupal.org/project/field_sample_value)
  - Are the generated sample values a bit too much? Field Sample Value gives
    you control over the generated values.
- [Layout Builder Restrictions](https://www.drupal.org/project/layout_builder_restrictions)
