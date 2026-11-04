# Daedalus AI

Adds an AI assistant to the Daedalus page builder. Site builders chat with an
assistant that understands the page they are viewing and can edit it in place —
placing blocks, rewriting fields, and generating images.

Daedalus AI is a thin integration layer: it wires together `daedalus_ui`,
`ai_chatbot`, `ai_agents`, and `daedalus_blueprint` rather than implementing its
own chat API or LLM handling.

## Documentation

Installing and configuring the AI assistant, including the provider keys, is
covered by the Daedalus recipe's README (see "The AI site recipe"):
<https://git.drupalcode.org/project/daedalus_recipe/-/blob/1.0.x/README.md>

## Requirements

- daedalus_ui
- ai (ai_chatbot)
- ai_agents
- daedalus_blueprint (+ daedalus_blueprint_ai)

## What the assistant knows about the page

The assistant reads the page through its HOST (daedalus_blueprint's
`HostInterface`), resolved from the route, and words it from the host's
kind (`RouteEntityResolver::describe()`, keyed on the host class, never
on which fields it answers): a page by its label and bundle; a template
as "the default template for page nodes", with the note that editing it
changes every page without a layout of its own; a saved blueprint
(daedalus_blueprint_library's `blueprint` entity, a page of its own with
a subject) as "the block blueprint "Card" for page nodes". The current
page, the selected elements and the context tags carry the same words,
and the page tools (`page_read`, `page_edit`, `page_create`) address a
saved blueprint as `entity_type: blueprint` by id, so the assistant edits
a blueprint on its canvas with the tools it edits a page with. The
prompt's BLUEPRINTS block lists the rostered blueprints to place with
`<d-blueprint name="id"/>`; a placed element's provenance names the
blueprint it came from.

## Files in the chat

The sidebar takes attachments (the attach button, drop, paste). Every file
goes through daedalus_ui's one file lane into a managed file the conversation
holds under its name, never a uuid: the handle is the lane's clean filename
(the canonical name the lane gives every file it saves, renamed on collision),
so the name the model is handed is a name it can repeat byte for byte. An
ATTACHMENTS block on the turn's message names each file and delivers its
bytes once; on every later turn the model carries only the name and reaches
the bytes again through `view_file` by that name.

## Configuration

Settings live on the AI chat form at `/admin/config/daedalus/ai/chat`
(Administration » Configuration » Daedalus » AI » AI chat; also listed under
Configuration » AI » Tools & Automation, and rostered in the daedalus repo's
`SETTINGS.md`): choose the AI assistant, turn image generation on or off and
name the placeholder image shown while generated images are produced, and
curate the models users may choose. A user with the "Choose the AI model the
chat runs on" permission picks one of the curated models from the chat's menu
(stored as an editor preference, `daedalus_ai` / `model`, in drupal/ai's
`provider__model` grammar); an empty list hides the choice, and the site's
default chat model stays the fallback. Every curated model is a model of the
provider the chat runs on without a choice (the assistant's own provider, else
the site's default chat provider): the table offers that provider's models only,
and a choice switches the model, never the provider. A row on another provider
is refused when the list is read; the update hook that introduced this rule
dropped such rows on existing sites, logging each.

## Debugging

The client scripts log through the Daedalus's shared per-module logger
(`daedalus_ui/logging`). Nothing prints until Daedalus AI is switched on from
the browser console:

```js
Drupal.Daedalus.logging.enable('daedalus_ai');
Drupal.Daedalus.logging.disable('daedalus_ai');
Drupal.Daedalus.logging.status();
```

The choice persists in localStorage, so it survives reloads. This replaces the
module's own `daedalusAiLogging` flag.

## Maintainers

- Tim Bozeman - https://www.drupal.org/u/tim-bozeman
