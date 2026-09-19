# Daedalus AI Memory

<!-- cspell:ignore Bozeman -->

## Introduction

The AI module's agent runner starts every conversation turn from a blank
slate: when an agent finishes a turn its serialized state is discarded, so
the next user message runs the agent fresh, with no memory of earlier tool
calls, results, or reasoning. Daedalus AI Memory decorates that runner and
persists agent state between turns in a private temp store, so an agent
enabled here carries its conversation forward within a chat session.

On the same path it also applies the fronting assistant's LLM configuration
(maximum tokens, temperature, and so on) to the agent's provider on every
turn, a setting that otherwise never reaches the agent.

For each turn of an enabled agent the runner:

- Classifies the request as fresh, same turn (the front end polling an agent
  that is still running), cross turn (a new user message after the agent
  finished), or stale (the agent finished and nothing new arrived; the old
  record is discarded and the turn runs fresh).
- Restores the persisted state and, on a cross turn, appends only the new
  user messages, resetting loop counters and tool results so the agent picks
  up cleanly.
- Trims chat history by whole turns when it grows past the configured
  limit, always keeping the first turn and the most recent turns, so a tool
  call and its result are never split apart.
- Strips binary file data from messages before persisting them.
- Drops the persisted record if the turn throws, so a corrupt thread cannot
  poison every later turn.

Agents that are not enabled pass straight through to the original runner,
untouched.

## Requirements

- [AI](https://www.drupal.org/project/ai), with its AI Assistant API
  submodule enabled.
- [AI Agents](https://www.drupal.org/project/ai_agents).

## Installation

Install as you would normally install a contributed Drupal module. For
further information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).

```
composer require drupal/daedalus
drush en daedalus_ai_memory
```

## Configuration

1. Go to Administration > Configuration > AI > Daedalus AI Memory
   (`/admin/config/ai/agent-memory`). The page requires the "Administer
   site configuration" permission.
2. Under Enabled agents, check each agent that should keep its state across
   turns. The list shows agent plugin ids (the agent an assistant runs, not
   the assistant itself).
3. Set Maximum history messages (default 100): the total number of messages
   kept in the persisted state. Older middle turns are dropped when the
   limit is exceeded.
4. Set Keep recent turns (default 5): how many of the most recent turns are
   always preserved regardless of trimming.
5. Save.

A recipe or module can opt an agent in without the form by setting
`enabled_agents` in `daedalus_ai_memory.settings`, for example with a recipe
config action:

```yaml
config:
  actions:
    daedalus_ai_memory.settings:
      simpleConfigUpdate:
        enabled_agents:
          my_agent: my_agent
```

## Maintainers

- Tim Bozeman - https://www.drupal.org/u/tim-bozeman
