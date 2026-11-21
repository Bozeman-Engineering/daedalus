# Core 11.3 compatibility

Daedalus is developed and tested on Drupal core 11.4 and declares
`>=11.3 <11.5`. The pieces below keep it running on 11.3, where an API it
uses does not exist yet. They are temporary: when Daedalus raises its floor
to 11.4, delete every one of them and this file in the same change, and set
`core_version_requirement` (every `*.info.yml`) and `drupal/core`
(`composer.json`) to `>=11.4`.

Each piece is marked in the code with the comment `Core 11.3
compatibility`, so `git grep -n 'Core 11.3 compatibility'` lists them all.

## Text format repository

Core 11.4 added `\Drupal\filter\FilterFormatRepositoryInterface` and
deprecated `filter_formats()` and `filter_default_format()` in its favor;
11.3 has only the functions. `Drupal\daedalus_blueprint\Compat\FilterFormats`
(service `daedalus_blueprint.compat.filter_formats`) answers the three
repository methods Daedalus uses (`getAllFormats()`,
`getFormatsForAccount()`, `getDefaultFormat()`) through core's repository
when it exists, else through the functions. Its consumers keep the property
name `$filterFormatRepository`, so removal only changes types and service
references.

To remove:

- Delete `modules/daedalus_blueprint/src/Compat/FilterFormats.php` (its
  three `@phpstan-ignore function.deprecated` lines go with it: the
  functions it falls back to are deprecated on 11.4), its service and its
  class alias in `daedalus_blueprint.services.yml`.
- `TextFieldHandler` (daedalus_blueprint): type `$filterFormatRepository` as
  `FilterFormatRepositoryInterface` again; the service is autowired.
- `FormattedTextComposedContent` (daedalus_edit_formatted_text_block): the
  same type change, and its service argument back to
  `'@Drupal\filter\FilterFormatRepositoryInterface'`, and drop the module's
  `daedalus:daedalus_blueprint` dependency, declared only for this service.

## Text editor filter

Core 11.4 moved `editor_filter_xss()` onto the `element.editor` service
(`\Drupal\editor\Element::filterXss()`) and deprecated the function; on
11.3 the service exists without the method. `Drupal\daedalus_edit\EditorSeed`
calls the method when the service has it, else the function.

To remove:

- `EditorSeed::seed()` (daedalus_edit): call
  `$this->editorElement->filterXss()` directly and drop the `method_exists`
  branch with its `@phpstan-ignore function.deprecated` line.
