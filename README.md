# Daedalus

Daedalus is a page builder for Drupal: a single project whose sub-modules
are its roles. Enable the roles a site needs; every one of them depends on
`daedalus` itself.

| Sub-module | Role |
|---|---|
| `daedalus_ui` | The edit-mode UI: toolbar, sidebars, modes, tools and hotkeys, and the `Drupal.Daedalus` JavaScript root the other roles attach to. |
| `daedalus_layout` | A drop-in replacement for the Layout Builder UI, with nested sections. |
| `daedalus_edit` | Inline editing of fields and blocks in place. Ships `daedalus_edit_text_block`, `daedalus_edit_formatted_text_block`, `daedalus_edit_image_block` and `daedalus_edit_page` (the page content type). |
| `daedalus_tempstore` | Tempstore management for unsaved edits. |
| `daedalus_blueprint` | Bidirectional JSON entity serialization for AI workflows. Ships `daedalus_blueprint_api` (the HTTP endpoint editing clients use), `daedalus_blueprint_stencil` (reusable composed sections and blocks), `daedalus_blueprint_config` and `daedalus_blueprint_config_ai` (config entities), `daedalus_blueprint_ai` (agent tools), `daedalus_blueprint_ai_anthropic`, `daedalus_blueprint_ai_dev_tools` and `daedalus_blueprint_ai_log_repair`. |
| `daedalus_ai` | The AI assistant chat panel that edits the page through blueprints. Ships `daedalus_ai_anthropic`, `daedalus_ai_gemini` and `daedalus_ai_memory` (agent state across conversation turns). |
| `daedalus_stylish` | Style anything on the page: browser widgets and AI sharing one style model, validated against a style guide. Ships `daedalus_stylish_layouts` (the container layout), `daedalus_stylish_layers`, `daedalus_stylish_discover`, and the `daedalus_stylish_starter_skin`, `daedalus_stylish_olivero_skin`, `daedalus_stylish_carbon` and `daedalus_stylish_guide_example` design systems. |
| `daedalus_explode` | The Explode panel: the edit-mode canvas as a tilted, exploded 3D stack, every element lifted by its depth in the page hierarchy, with the real page content on each layer. An orientation view for editors and the shot for teaching the hierarchy on video. |

Each sub-module's own README documents it.

## Installation

The supported way to get a page-building site is the
[Daedalus recipe](https://www.drupal.org/project/daedalus_recipe), which
installs and configures this module, the
[Daedalus theme](https://www.drupal.org/project/daedalus_theme) and the
content types, block types and roles the page builder expects. To add the
module on its own:

```
composer require drupal/daedalus
```

The AI roles need the [AI](https://www.drupal.org/project/ai) module, its
agents and a provider; `composer.json` lists them under `suggest`, and the
recipe requires them.

Daedalus replaces the separate Navigation+, Layout Builder+, Edit+,
Tempstore+, Entity Blueprint, AI+, AI Agent Memory and Stylish projects, and
conflicts with them: a site carries one or the other.
A site on those projects' latest releases moves onto Daedalus with the
migration pathway shipped in the package: read
[`migrate/plus_suite/MIGRATING.md`](migrate/plus_suite/MIGRATING.md), or hand a coding agent
<https://git.drupalcode.org/project/daedalus/-/raw/1.0.x/migrate/plus_suite/PROMPT.md>
to drive it.

## Requirements

- Drupal 11.3 or 11.4.
- [Twig Events](https://www.drupal.org/project/twig_events) and
  [Field Sample Value](https://www.drupal.org/project/field_sample_value),
  required by Composer.

## Development

The JavaScript for `daedalus_ui`, `daedalus_stylish` and `daedalus_explode`
is built by webpack from one root `package.json`:

```
npm install
npm test             # the node specs under modules/*/tests/js
npm run build:prod   # rebuilds the tracked bundles under js/*/dist
```

PHP tests live under each sub-module's `tests/`; the bases and traits they
share live under `tests/src/` here, in the `Drupal\Tests\daedalus`
namespace. Static analysis runs from this directory with
`phpstan analyse -c phpstan.neon.dist`.
