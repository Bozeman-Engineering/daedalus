# Where Daedalus settings live

The developer's roster of every Daedalus setting: what it is, which module
owns it, where it is written, and every door that leads there. Code is the
truth; this file says where to look. `tests/src/Unit/SettingsRosterTest.php`
keeps it honest: every `config_object` any `config/schema/*.schema.yml` in
this repo declares must be named here, so a new settings object cannot land
unrostered. Add the row before the schema, and the test stays green.

Two rules shape the roster:

- **One write path per setting.** A setting has one owner and one form,
  canvas, command or recipe that writes it. A second listing of that form is
  a *door*: a menu link with its own id on the same route, never a second
  form. Every second door is commented in its `*.links.menu.yml` with the
  first door and this file.
- **The front door is Configuration » Daedalus** (`/admin/config/daedalus`,
  route `daedalus.admin_config`, owned by the root module, rendered by core's
  `SystemController::overview`), with three sub-categories on
  `SystemController::systemAdminMenuBlockPage`: **AI**
  (`/admin/config/daedalus/ai`, `daedalus.admin_config_ai`), **UI**
  (`/admin/config/daedalus/ui`, `daedalus.admin_config_ui`) and **Styling**
  (`/admin/config/daedalus/styling`, `daedalus.admin_config_styling`). The
  four routes take `access administration pages`; each listed form keeps its
  own gate, and core shows a category only when it lists a link the actor may
  reach (an empty category is 403, not blank). Settings keep their natural
  homes (drupal/ai's menu, Appearance, Structure) and are listed under the
  door a second time.

## Site-wide settings (config objects)

Keyed by setting: the config object and the keys that share a write path.
"Doors" names each menu link (its id and parent) or other way in; the first
door is the one the owner module listed first. Permissions are the form's.

| Setting | Owner | Written at | Doors | Config-only or canvas-owned, and why |
|---|---|---|---|---|
| `daedalus_ui.settings`: `colors` (main, secondary, highlight: the chrome's CSS variables) | daedalus_ui | Editor chrome form, `/admin/config/daedalus/ui/chrome` (route `daedalus_ui.settings`, `daedalus_ui\Form\SettingsForm`, `administer Daedalus UI configuration`) | Daedalus » UI (`daedalus_ui.settings`, weight -10). One door. The form id is a contract: a module from outside the package may alter the form by id and add a details element of its own beneath the colors, and what it adds is that module's setting, not a Daedalus one. | |
| `daedalus_ai.settings`: `assistant_id`, `image_generation_enabled`, `placeholder_image`, `model_choices` | daedalus_ai | AI chat form, `/admin/config/daedalus/ai/chat` (route `daedalus_ai.settings`, `daedalus_ai\Form\SettingsForm`, `administer site configuration`) | Daedalus » AI (`daedalus_ai.settings`, first door, weight -10); AI » Tools & Automation (`daedalus_ai.settings.ai_tools`, parent `ai.admin_config_tools`). Recipe: the `daedalus_ai` ingredient sets `assistant_id: daedalus` and `image_generation_enabled: false`. | `model_choices` is the list the chat kebab's per-user model choice draws from (permission `choose ai model`); `image_generation_enabled` has a per-user twin, "Generate images", in the Edit mode settings panel (below). |
| `daedalus_ai_memory.settings`: `enabled_agents`, `cache_ttl`, `segment_budget_tokens`, `segment_budget_minimum_tokens`, `segment_budget_overrides`, `segment_headroom_tokens`, `reframe_minimum_tokens` | daedalus_ai_memory | Memory form, `/admin/config/ai/agent-memory` (route `daedalus_ai_memory.settings`, `administer site configuration`) | AI » Tools & Automation (`daedalus_ai_memory.settings`, first door, parent `ai.admin_config_tools`); Daedalus » AI (`daedalus_ai_memory.settings.daedalus`). Recipe: the `daedalus_ai` ingredient sets `enabled_agents`. | |
| `daedalus_ai_ledger.settings`: `prices`, `caps`, `retention_days` | daedalus_ai_ledger | Ledger form, `/admin/config/ai/usage-ledger` (route `daedalus_ai_ledger.settings`, `administer ai usage ledger`) | AI » Tools & Automation (`daedalus_ai_ledger.settings`, first door); Daedalus » AI (`daedalus_ai_ledger.settings.daedalus`). | The status report (`LedgerRequirements`) reads the price table for drift; read-only. |
| `daedalus_ai_upstream.settings`: `replay_thinking`, `thinking_display` | daedalus_ai_upstream | Upstream form, `/admin/config/ai/daedalus-upstream` (route `daedalus_ai_upstream.settings`, `administer ai providers`) | AI » Tools & Automation (`daedalus_ai_upstream.settings`, first door); Daedalus » AI (`daedalus_ai_upstream.settings.daedalus`). | |
| `daedalus_blueprint_ai.settings`: `hidden_entity_types`, `hidden_bundles` | daedalus_blueprint_ai | Blueprint AI form, `/admin/config/ai/daedalus-blueprint` (route `daedalus_blueprint_ai.settings`, `administer site configuration`) | AI (`daedalus_blueprint_ai.settings`, first door, parent `ai.admin_settings`); Daedalus » AI (`daedalus_blueprint_ai.settings.daedalus`). Recipe: the `daedalus_ai` ingredient sets `hidden_entity_types`. | |
| `daedalus_blueprint.settings`: `ai_context_budget_tokens`, `ai_context_characters_per_token` (the AI context budget, `MaterialsBudget`) | daedalus_blueprint | **The Blueprint AI form above** (a cross-module write through `#config_target`; the keys live on the spine's settings object because the budget is read where materials are assembled, not where the AI is configured). | The Blueprint AI form's two doors. | |
| `daedalus_blueprint.settings`: `active_design_system` (`ActiveDesignSystem`) | daedalus_blueprint | Design systems collection, `/admin/appearance/design-systems`, and `/admin/appearance/design-systems/{design_system}/activate` (route `entity.design_system.activate_form`, `administer design systems`); drush `daedalus-blueprint:design-system:activate`. Recipe: the `daedalus_styling` ingredient sets `olivero`. | Appearance (`entity.design_system.collection`, first door, parent `system.themes_page`); Daedalus » Styling (`entity.design_system.collection.daedalus`). | Collection-owned: the pointer must name an existing design system, and activation is an action on that entity (the collection and the drush command validate it); a free-text form would be a second validator. |
| `daedalus_blueprint.settings`: `slots` (the slot roster: id to label and tier) | daedalus_blueprint | **No UI.** The recipe's `daedalus_slots` ingredient (a `simpleConfigUpdate`); `SlotBlock` derives one block per entry. | None. | Config-only: a slot block derives per roster entry and documents place those blocks, so editing the roster by hand could orphan placed slot blocks; the recipe is the declaration and `SlotRosterTest` validates it. |
| `daedalus_blueprint_config.settings`: `allowed_create_types` | daedalus_blueprint_config | Blueprint config form, `/admin/config/development/daedalus-blueprint-config` (route `daedalus_blueprint_config.settings`, `ConfigCreateAccessForm`, `administer site configuration`) | Development (`daedalus_blueprint_config.settings`, first door, parent `system.admin_config_development`); Daedalus » AI (`daedalus_blueprint_config.settings.daedalus`: the allow-list is what the AI may create). | |
| `daedalus_stylish.settings`: `ai_minting`, `container_roots`, `media_image_style`, `breakpoints` (narrow, medium) | daedalus_stylish | Styling form, `/admin/config/daedalus/styling/settings` (route `daedalus_stylish.settings`, `daedalus_stylish\Form\SettingsForm`, `administer site configuration`); the module's `post_update` hooks fill keys on upgrade. | Daedalus » Styling (`daedalus_stylish.settings`, weight -10). One door. | |
| `daedalus_stylish.settings`: `skin_choice` (`SkinManager`) | daedalus_stylish | The skin card on the canvas, `/daedalus_stylish/skins/{reference}/choose` (route `daedalus_stylish.skin_choose`); drush `daedalus_stylish:skin:choose`. The skin library itself is `/admin/appearance/skins` (`administer skins`). | Appearance (`entity.daedalus_skin.collection`, first door, parent `system.themes_page`); Daedalus » Styling (`entity.daedalus_skin.collection.daedalus`). | Canvas-owned: choosing a skin is an action on a skin reference the card and the command validate; the Styling form never carries it. |
| `daedalus_stylish.settings`: `opt_outs` (bundles whose editors may not style) | daedalus_stylish | The bundle form's "Let editors style elements" box in the Page builder tab (`DaedalusStylishFieldManager`, which also creates or deletes the bundle's style map field). | Each bundle's own form under Structure (see the next table); mirrored read-only by the roster's Styling column. | Bundle-tab-owned: the key records which bundles turned the box off, so its one writer is the box; the Styling form never carries it. |
| `daedalus_stylish_layers.settings`: `enabled`, `wrapper_layer` | daedalus_stylish_layers | Cascade layer form, `/admin/config/daedalus/styling/cascade-layer` (route `daedalus_stylish_layers.settings`, `daedalus_stylish_layers\Form\SettingsForm`, `administer site configuration`) | Daedalus » Styling (`daedalus_stylish_layers.settings`, weight -5). One door. | |

Permissions in play, seven in all, none added by the door: `administer
site configuration` (AI chat, memory, blueprint AI, blueprint config,
styling, cascade layer), `administer ai usage ledger`, `administer ai
providers`, `administer Daedalus UI configuration` (editor chrome, the bundle
roster), `administer daedalus layout configuration` (the default section),
`administer design systems`, `administer skins`.

## Per-bundle, per-display, per-field and per-user settings

Third-party settings on other modules' config entities, and the editor's own
preferences. The write path is the owning entity's form or canvas; the
Daedalus door only mirrors or points.

| Setting | Owner | Written at | Doors and mirrors |
|---|---|---|---|
| `{node.type,block_content.type,media.type,taxonomy.vocabulary,comment.type}.*.third_party.daedalus_ui`: `enabled`, `initial_mode` (page bundles), `presentation` (block types); schema type `daedalus_ui.bundle.options`, probed by `BundleEditFormAlter::hasSettingsSchema()` | daedalus_ui | The "Page builder" vertical tab on every bundle form (`/admin/structure/types/manage/{type}`, `/admin/structure/block-content/manage/{type}`, `/admin/structure/media/manage/{type}`, vocabularies, comment types; fragment `#edit-page-builder`). daedalus_ui owns the section; other modules add controls into its groups. | Structure (each bundle form, the one write path); **the bundle roster**, `/admin/config/daedalus/ui/content-types` (route `daedalus_ui.bundle_roster`, `administer Daedalus UI configuration`, link `daedalus_ui.bundle_roster` under Daedalus » UI, weight -5): a read-only table per bundle config type with operations into each tab and manage display. Other modules add roster columns through `daedalus_ui\Event\BundleRosterEvent`. |
| `{node.type,media.type,taxonomy.vocabulary,comment.type}.*.third_party.daedalus_layout`: `offer_starts` | daedalus_layout | The tab's "Offer starting designs" box (creation group; disabled where pages carry no layout of their own, `PageStart::takesStart()`). | The roster's "Starting designs" column (`StartingDesignsColumn`; n/a where pages carry no layout). |
| `block_content.type.*.third_party.daedalus_layout`: `icon`, `favorite`, `groups` | daedalus_layout | The block type form's "In the Add panel" group (`Hook\BlockContentTypeFormAlter`). | Structure only. |
| The bundle's style map field (existence) and `daedalus_stylish.settings:opt_outs` | daedalus_stylish | The tab's "Let editors style elements" box (styling group), with the delete warning. | The roster's "Styling" column (`StylingColumn`, `DaedalusStylishFieldManager::isEnabled()`). |
| `core.entity_view_display.*.*.*.third_party.daedalus_layout`: `default_section` | daedalus_layout | `/admin/structure/types/manage/{entity}/default-layout-section` (`ConfigureDefaultSectionForm`, `administer daedalus layout configuration`), the "Configure default layout container" button daedalus_layout adds to manage display. | Structure only. |
| `core.entity_view_display.*.*.*.third_party.daedalus_stylish`: `styles` (the template's style map) | daedalus_stylish | The template canvas (core's manage-display layout route); never a form. | Structure (manage display). |
| `daedalus_blueprint.frame.*.third_party.daedalus_stylish`: `styles` (the frame's style map) | daedalus_stylish | The site frame canvas, `/site-frame/{daedalus_frame}` (`configure the site frame`). | Appearance (`daedalus_layout.frame:<theme>`, one child per frame off `FrameMenuLink`, first door); Daedalus » Styling (`daedalus_layout.frame.daedalus:<theme>`, the same deriver). |
| `field.field.*.*.*.third_party.daedalus_ui`: `presentation`; `field.field.*.*.*.third_party.daedalus_edit`: `disable`, `handle`, `link_target_types` | daedalus_ui, daedalus_edit | The field config form's "Page builder" details (`/admin/structure/types/manage/{type}/fields/{field}`, each module's `FieldConfigFormAlter`). | Structure only. |
| `layout_builder.section.third_party.daedalus_layout`: `uuid`; `layout_builder.section.third_party.daedalus_blueprint`: `slot` | daedalus_layout, daedalus_blueprint | Stored by the canvas on each placed section. | Not settings: document state, listed so the schema's third-party names are all accounted for. |
| Per-user editor settings: the `daedalus_ui_settings` base field on the user entity, through the `daedalus_ui.editor_settings` service | daedalus_ui | The Edit mode settings panel (`SettingsSidebar`, items contributed through `SettingsSidebarEvent`): Editing chrome (daedalus_ui), Hotkeys (`HotkeySettings`), Media file associations (`NewMediaFileAssociationSettings`), Container outlines (daedalus_layout `LayoutSettings`), Generate images (daedalus_ai `GenerateImagesSettings`, the per-user twin of `image_generation_enabled`); written by `/daedalus-ui/setting/{namespace}/{key}` (the value is the JSON body), `/daedalus-ui/save-user-hotkey/{binding_id}/{hotkey}` and `/daedalus-ui/remove-media-file-association/{file_extension}`, each a POST carrying the session token in `X-CSRF-Token`. The chat kebab's model choice (daedalus_ai, `choose ai model`) picks from the site-wide `model_choices`. | The right rail in Edit Mode. Not config; nothing under the door. |

## Pages the door points at that are not settings

- **Blueprints**, `/admin/content/blueprints` (`entity.blueprint.collection`):
  the saved blueprints offered in the Add panel and to the AI. First door is
  the Blueprints tab on the Content page (a local task, base route
  `system.admin_content`); the pointer `entity.blueprint.collection.daedalus`
  under Daedalus » UI is the collection's only menu link. Blueprint types
  have no admin UI by design.
- **Skins** and **Design systems** collections and the **Site frame** canvas:
  listed above beside the setting each writes; their pages stay under
  Appearance untouched.

## drupal/ai-owned config Daedalus depends on

`ai_assistant_api.ai_assistant.daedalus` and `ai_agents.ai_agent.daedalus`
(shipped by the recipe's `config/`, edited at `/admin/config/ai/ai-assistant`
and `/admin/config/ai/agents`), the default chat provider
(`/admin/config/ai/settings`) and provider keys. The recipe's config actions
point `daedalus_ai.settings:assistant_id` and
`daedalus_ai_memory.settings:enabled_agents` at them.

## Not Daedalus settings

Tooling that drives the builder from outside the package (a scenario
runner, a render harness, a diagnostics module) keeps its settings in its
own repos and declares no form here; it is not part of this roster.
`twig_events` and `field_sample_value` (the latter's per-field third-party
settings are its own) have no Daedalus surface either.
