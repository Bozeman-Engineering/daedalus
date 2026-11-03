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

For each request of an enabled agent the runner:

- Classifies the request by the identity of the session's last user message
  (its timestamp and text, never a message count: the user-visible chat
  memory caps its history, so a count stops growing while the conversation
  goes on) as fresh, same turn (the front end polling an agent that is still
  running), cross turn (a new user message after the last turn, or the same
  message sent again after it finished: it is appended as a new turn), or
  stale (a record from before the transcript existed, which cannot be read).
- Runs the usage gate (`daedalus_ai_ledger.usage_gate`) before any model
  call and answers a refusal through the assistant's error template, naming
  the window that is full and when it resets.
- Appends the user's turn to the transcript with the blocks the producers
  state for it (`Drupal\daedalus_ai\Context\ContextBlocksEvent`, once per
  turn), and on a fresh start replays the session's earlier messages as
  plain entries so the model reads what the user can see.
- Renders the transcript's current segment into the agent's history before
  every leg (with the rendering plan for a provider that declares a
  capability), absorbs what the leg appended after it, tags every leg with
  its segment for the usage ledger, and lets the segment policy close the
  segment on the leg just absorbed. Bytes reach the model once: the files
  delivered with the user's turn ride its first leg, and a tool's images
  (the screenshots message ai_agents' wrapper interjects after a tool
  returned them) ride the leg after the tool, lifted off the absorbed
  slice and placed on the rendering's newest user message for that leg
  only; the transcript records the message, never the bytes, and
  `view_file` is the road back.
- Persists the transcript and the agent's non-history state after the
  request; the agent's chat history itself is never stored (the transcript
  is the history) and the record holds no file bytes.
- Keeps the persisted record if the turn throws (nothing of the failed
  request is persisted, so the record is the last good state and the next
  turn builds on it, settling any pending tool calls as unanswered); a
  carry-over call the provider refuses leaves the segment open. The one
  record ever deleted is one that cannot be read back, and that request
  runs fresh once.

## Segments

The model-facing history is held as a transcript of segments. Inside a
segment nothing sent to the provider is ever edited, stripped or reordered,
so every leg's prompt is a byte prefix of the next and the provider's cache
reads it. A segment closes in two ways, decided by the segment policy
(`daedalus_ai_memory.segment_policy`):

- On budget: the last leg's measured prompt size (what the provider billed,
  read from the usage ledger's newest row for the conversation, never an
  estimate) passed the effective budget. Decided when a turn has finished,
  so a turn is never cut in half.
- On a reframe: a skill declaring `reframe: true` (the design doctrine)
  answered in the leg just absorbed and the segment
  was already past `reframe_minimum_tokens`. The current turn is re-sent
  after the carry-over, so the doctrine sits near the request.

The effective budget is the smallest of `segment_budget_tokens` (or the
model's row in `segment_budget_overrides`), the ceiling the provider
declares for the model, and the model's maximum input less
`segment_headroom_tokens`. The budget and every override are first clamped
up to `segment_budget_minimum_tokens` (default 50000): a budget under the
prompt's own floor (tools, system prompt and catalog run about 20000
tokens) would close every finished turn and pay a carry-over call each
time, so the settings form refuses one and the policy raises a value set
by other means. The ceiling and the maximum input still cut below the
minimum; they are the provider's facts.

Closing generates a carry-over through one chat call over the closing
segment's exact prefix (`daedalus_ai_memory.carry_over_generator`): the
user's intent, decisions and constraints, standing preferences, what was
tried and rejected, draft text still in the chat, the page in hand, the
files held and the facts loaded. The next segment opens with it. The
user-visible chat is untouched; a reset never reads as the assistant
forgetting.

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
3. Set the prompt cache TTL (default 5m) a provider that places cache
   breakpoints on request is asked for.
4. Set the segment budget (default 80000 tokens), its minimum (default
   50000; the budget and every override must be at or above it), per-model
   overrides as `model: tokens` lines, the headroom below the model's
   maximum input (default 32000) and the reframe minimum (default 25000).
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
