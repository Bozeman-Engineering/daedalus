# Daedalus Blueprint

Bidirectional JSON entity serialization for Drupal. Serialize any fieldable
entity — including Layout Builder pages with nested paragraphs and inline
blocks — to a clean JSON format. Deserialize AI-generated or hand-crafted JSON
back into valid Drupal entities with two-phase validation and structured error
feedback.

## Features

- Serialize any fieldable entity (nodes, blocks, paragraphs, etc.) to JSON.
- Three serialization modes: full, summary (tree structure only), and element
  (single UUID with context).
- Deserialize JSON back to unsaved Drupal entities with structural and semantic
  validation.
- Targeted CRUD operations on individual elements without full round-trips.
- JSON schema generation for entity type and bundle combinations, with
  AI-facing context (guidance, defaults, and markup anatomy) merged in from
  YAML files and `hook_daedalus_blueprint_context_alter()`.
- Batch operations with atomic rollback.
- Opaque data protection: Layout Builder internals are preserved unless a module
  explicitly opts in via hook.
- Multilingual support for translation workflows.
- Extensible field handler system via tagged services.


No additional contrib modules are required for the base module.

### Optional

- **Layout Builder** (core): Required for Layout Builder serialization support.
- **Drupal AI** (`drupal/ai`): Required by the `daedalus_blueprint_ai` submodule
  to expose operations as AI agent tools.

### Recommended
- **Daedalus**: An AI powered page builder built on layout_builder.

## Installation

Install as you would normally install a contributed Drupal module. Visit
https://www.drupal.org/docs/extending-drupal/installing-modules for further
information.

```bash
composer require drupal/daedalus
drush en daedalus_blueprint
```

To enable the AI agent tools submodule:

```bash
drush en daedalus_blueprint_ai
```
## Usage

### Drush commands

```bash
# Serialize an entity to JSON.
drush daedalus-blueprint:serialize node 1

# Serialize structure only (no field values).
drush daedalus-blueprint:serialize node 1 --summary

# Serialize a single element by UUID.
drush daedalus-blueprint:serialize node 1 --element=UUID

# Deserialize JSON back to an entity (prints result, does not save).
drush daedalus-blueprint:deserialize blueprint.json

# Validate without hydrating.
drush daedalus-blueprint:deserialize blueprint.json --dry-run

# Read from stdin.
drush daedalus-blueprint:deserialize - < blueprint.json

# Generate a JSON schema for a bundle.
drush daedalus-blueprint:schema node page

# Activate a design system (alias dd-blueprint-activate).
drush daedalus-blueprint:design-system:activate olivero

# Ship a placed element from a SAVED page to a module as a stencil file plus
# its variants file (daedalus_blueprint_stencil; see "The distro lock").
drush daedalus-blueprint:stencil-ship node 4 ROOT_UUID mymodule
```

### Design systems

A design system is the unit a skin and its materials form: a `design_system`
config entity (id, label, description, icon, default skin, package). A
module that packages one ships it as
`config/install/daedalus_blueprint.design_system.ID.yml` with `package: MODULE`;
a site builder creates one at `/admin/appearance/design-systems`. The site
activates ONE (`daedalus_blueprint.settings` `active_design_system`, through the
listing, the drush command, or config), and the materials roster offers the
active system's members plus the global materials (those of no system). A
`save_material` mint joins the active system unless it passes `global: true`.
A package cannot be uninstalled while its system is active or still has
members, and the active system cannot be deleted.

### The distro lock (developer sites)

Materials a package ships (variants, swatches and animations synced from
its files, stencils read from files) are read-only on a site: the package's
files are their one author, and a site that wrote into them would drift
away from every future sync. The site forks a material instead ("Copy as my
variant") or reverts toward the disk.

The package author works the other way round: the site is the canvas and
drush (`daedalus-blueprint:stencil-ship`, `daedalus_stylish:ship`) carries the result
back into the module's files. The lane's two directions have two words:
disk to site is sync (`daedalus_stylish:sync`, the same run a cache rebuild does),
site to disk is ship. On such a development site, lift the lock in
`settings.php`:

```php
$settings['daedalus_blueprint_developer'] = TRUE;
```

The flag opens every shipped-material gate at once (the
`daedalus_blueprint.distro_lock` service is the one fact they consult). It is
a setting rather than a permission because writable module directories are
an environment property: a development site needs no role setup, and a
production administrator cannot lift a lock that protects production. Roster
records are cached, so rebuild caches (`drush cr`) after flipping it.

### Tailing the AI log (`daedalus_blueprint_ai`)

`drush daedalus-blueprint:ai-tail` (alias `dd-blueprint-tail`) follows the `ai_log` table the
`ai_logging` module writes. Every LLM call logs the whole conversation so far,
so following one page-building thread raw means reading the same history on
every row. By default the command prints what each row adds: an `ai_agents`
runner is one thread, the thread's first row prints its whole prompt, and each
later row prints only its new messages under
`[+N messages since #id, H already printed]` (a rewritten history prints whole
with `[transcript diverged from #id; printed whole]`; rows outside any thread,
such as image generation, print whole). Base64 blobs collapse to their first
64 characters plus a byte count wherever they sit. `--full` restores the raw
dump: the whole prompt of every row as the model received it, every byte.

```bash
# Follow the log: 5 recent rows, then poll every 2 seconds.
drush dd-blueprint-tail

# Print the 10 most recent rows and exit.
drush dd-blueprint-tail --no-follow --limit=10

# The raw dump: whole prompts, no dedupe, no base64 collapse.
drush dd-blueprint-tail --full

# Only rows carrying a tag value (joins ai_log__tags).
drush dd-blueprint-tail --tag=ai_agents

# The last 3 messages of what each row prints; -m5 for five (no space).
drush dd-blueprint-tail -m

# Add the system prompt, tools, configuration and extra data sections.
drush dd-blueprint-tail --all
```

### Custom field handlers

Register a handler for custom field types by creating a tagged service:

```yaml
services:
  my_module.field_handler.custom:
    class: Drupal\my_module\FieldHandler\CustomFieldHandler
    tags:
      - { name: daedalus_blueprint.field_handler, field_types: 'my_field_type' }
```

Extend `FieldHandlerBase` and implement `doSerialize()` and `doDeserialize()`.

## Design Philosophy

- **On-demand context, not system prompt stuffing.** As context grows in a
  system prompt it becomes less relevant and the LLM loses awareness of it.
  Daedalus Blueprint delivers context through tool responses — schemas, guidance,
  managed key metadata, defaults, and anatomy — only when the LLM requests it
  and only for the specific operation at hand. This keeps the LLM's working
  context focused and actionable rather than diluted by a wall of up-front
  instructions.
- **Never save implicitly.** The deserializer and all operations return unsaved
  entities. The caller decides whether to save, tempstore, or discard.
- **Protect what you don't understand.** Opaque data (Layout Builder internals)
  is preserved from the existing entity by default. Only keys explicitly
  declared as managed are exposed to AI.
- **Fail fast, fail clearly.** Two-phase validation gives early, structured
  feedback with JSON paths, error codes, and correction hints.
- **UUID-based addressing.** All nested content is addressed by UUID, not by
  position or delta, making the API resilient to reordering.
- **Operations over round-trips.** Surgical edits (update one field, add one
  element) without re-serializing the entire entity.
- **Tempstore-first editing.** Edits route through tempstore when available so
  changes can be reviewed before publishing.
- **Workspace safety by default.** Content changes require an active workspace
  when the workspaces module is enabled.

## Submodules

### Daedalus Blueprint AI (`daedalus_blueprint_ai`)

Exposes daedalus_blueprint operations as AI agent function-call tools for the
Drupal AI module (`drupal/ai`). Requires `ai` and `daedalus_blueprint`.

## Maintainers

- Tim Bozeman - https://www.drupal.org/u/tim-bozeman
