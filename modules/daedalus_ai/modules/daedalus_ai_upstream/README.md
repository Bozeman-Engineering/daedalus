# Daedalus AI Upstream

## Deprecated by design

This module exists to be deleted. It ships one provider plugin,
`daedalus_anthropic` ("Anthropic (Daedalus upstream)"), a subclass of
`ai_provider_anthropic`'s `AnthropicProvider` that implements the context
spine's accelerator interfaces (`Drupal\daedalus_ai\Provider\*`). Every
behavior it adds is proposed upstream, to drupal/ai (opaque provider
content on `ChatMessage`, rendering hints on `ChatInput`, `cachedWrite`
filled on `TokenUsageDto`, system-role passthrough semantics) and to
ai_provider_anthropic (the request shapes below). The day those land, the
recipe points the chat defaults back at `anthropic` and this module is
removed. Daedalus is itself contrib and its users cannot be asked to carry
patches, which is why the interim lives as a sub-module and not as a
patch set.

The plugin reads the Anthropic provider's own settings
(`ai_provider_anthropic.settings`: the key, the host, the prompt cache
toggle and TTL, the models cache TTL) and its `api_defaults`, so a site
switches by pointing `ai.settings` chat defaults at `daedalus_anthropic`
and nothing else. The recipe's Anthropic ingredient does exactly that.

## What the plugin does

With no `RenderingPlan` on the `ChatInput` (every other caller on the
site) it behaves exactly as the contrib provider. The context spine
attaches a plan to every leg it renders; with one, the plugin:

- places the cache breakpoints the plan asks for: one on the system text
  block at the plan's prefix TTL, one on the last content block of each
  planned message index, and no contrib-style top-level automatic marker
  (the spine places the tail marker itself); more than four is refused;
- passes a `role: system` message in the history through as a
  mid-conversation system message in `messages[]` wherever the Messages
  API allows one (directly after a user message), never folding it into
  the top-level system prompt; where the API would refuse it (after an
  assistant message, which is where the agent wrapper's "required tools"
  reminder lands) it sends the universal form, a user message carrying the
  text in a `<system-reminder>` wrapper;
- replays the plan's opaque content (the thinking and `redacted_thinking`
  blocks the earlier legs answered, signatures included) ahead of each
  assistant turn's text and tool calls when `replay_thinking` is on, and
  then sends `thinking.block_binding.prefix_mismatch_behavior: drop_block`
  under the `thinking-binding-controls-2026-08-01` beta so a block whose
  conversation prefix changed is dropped by the API instead of failing the
  request on an account that enforces the history-editing check.

Independent of a plan it sends `thinking: {type: adaptive, display:
summarized}` when `thinking_display` is `summarized` and the request's
thinking is adaptive (the parent's `thinking_mode` unset, in which case
the plugin adds the adaptive parameter the model runs by default anyway,
or `adaptive`; a budgeted `enabled` mode is left as the parent built it),
so the response's thinking blocks carry the reasoning summary instead of
empty text; the replay's `block_binding` rides the same parameter. It
also fills `TokenUsageDto::$cachedWrite` from the response usage
(drupal/ai 1.5; the metadata key the contrib provider writes stays),
answers a response's thinking blocks as opaque content for the transcript
to keep, and answers the model's maximum input from the Models API as the
context ceiling and as `getMaxInputTokens()` (the contrib provider answers
drupal/ai's 1024 placeholder, which the segment policy ignores).

## Settings

`/admin/config/ai/daedalus-upstream` (`daedalus_ai_upstream.settings`),
two settings:

- `replay_thinking` (off by default): whether stored thinking blocks are
  replayed. The transcript stores them either way. Off until the ledger
  shows what re-thinking each leg costs against the replayed tokens.
- `thinking_display` (`omitted` by default, or `summarized`): what the
  thinking blocks of a response carry. On the models that think by
  default (Sonnet 5.5, Haiku 5.5, Opus 5 and later) the API's own default
  is empty text. `summarized` asks for the model's reasoning summary
  instead; the transcript keeps it, the ai_logging rows carry it, and any
  tool that reads a conversation's record can show it. This is a
  diagnostic, nothing more: the model thinks and is billed the same under
  either value, only what is visible afterwards changes. A production
  site leaves it omitted; a dev site (the dev recipe) sets it summarized.

The per-turn blocks (current page, print, selection, attachments) are always
text inside the user message, on this provider as on every other: a block
kept in the history costs its cached-read price per later leg, a tenth of
its uncached price, and the API's form that clears a system message at the
next user-role message would clear it at the first `tool_result` of a tool
loop, so no switch for it exists.

## Known gaps

- The streamed path (`nativeChatStream`) is the contrib provider's: the
  streamed iterator reports provider id `anthropic`, and a streamed
  response answers no opaque content. The agent runner's legs do not
  stream.
- Mid-conversation system messages are rendered for the models that take
  them (Opus 5 and 5.5, Opus 4.8, Fable 5 and 5.1, Mythos 5 and 5.1,
  Sonnet 5.5, Haiku 5.5, by model id prefix); on any other model the
  capability answers false and the spine renders the floor.
