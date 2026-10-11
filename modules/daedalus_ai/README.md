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
`<pb-blueprint name="id"/>`; a placed element's provenance names the
blueprint it came from.

## Configuration

Settings live on the Daedalus UI settings form (Administration »
Configuration » User interface » Daedalus UI): choose the AI assistant and
the placeholder media shown while generated images are produced.

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
