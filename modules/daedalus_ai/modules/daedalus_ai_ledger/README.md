# Daedalus AI Ledger

## Introduction

Every LLM call on the site becomes one `ai_usage` row: the user it ran as,
the conversation (the agent runner id from the request tags) and segment,
the provider and model, every token count the provider reported (uncached
input, cache reads, cache writes with their TTL split where known, output,
reasoning), the cost the provider reported when it reports one, the cost
the price table computes, and what became of the cached prompt prefix:
`prefix_drift` is TRUE when the agent runner tagged the leg
(`UsageLedger::PREFIX_DRIFT_TAG`) because the system prompt or the tool
list changed since the segment's previous leg, and `prefix_loss` is how
many tokens that previous leg had cached that this leg re-sent (its
prompt size less this leg's cache reads, a gap under
`UsageLedger::PREFIX_JITTER` counted as zero; NULL when there is no
predecessor in the same segment, a count is unreported, or the
predecessor ran on another provider or model, since a model switch
re-writes the cache once by design). `rebuilt` is TRUE on the first leg of
a conversation the runner rebuilt from the visible chat
(`UsageLedger::REBUILT_TAG`: the agent's record had expired or could not
be read, and the earlier messages were replayed); the status report's
prompt cache entry counts those conversations over the last seven days
as a number, not a warning, since a record expiring after a week idle is
legitimate. Nothing is estimated: the counts are
the provider's own accounting, read through daedalus_ai's `UsageReader` so
drupal/ai 1.4 and 1.5 shapes and provider metadata all land the same way.

The rows are fed from drupal/ai's post-response and post-streaming events,
so the page builder agent's legs, the image prompt rewriter and generation
calls all count.

## The price table

`daedalus_ai_ledger.settings` holds `prices`: USD per million tokens by
model id in four token buckets (`input`, `output`, `cache_read`,
`cache_write`) plus `per_call`, USD added once per leg on top of the
tokens. It is the one counting knob of the caps: raw tokens would misprice
cached reads by an order of magnitude, so the caps count dollars, and
tuning what counts means editing a bucket (zero stops counting it). A leg
always makes exactly one call, so `per_call` blank or zero means the call
itself is free, not unknown; a leg whose provider reports no tokens at
all costs the row's per-call figure, which is how a tokenless provider
still weighs against the caps.

A model missing from the table is priced by the fallback row, whose model
id is `*`: it is what makes every leg count whatever model it ran on, so
edit it rather than delete it (the shipped one copies the dearest row,
Fable 5.1, with 0.25 USD per call, so an unknown model is overcounted
rather than undercounted). `CostResolver::pricedBy()` answers which row
priced a model (its own id, `*`, or NULL when neither row exists), and
the status report names the models that rode the fallback so their own
rows can be added. A provider plugin that implements
`ReportedCostInterface` is believed over the table.

## The caps

`caps` is a list of spend windows, each `{scope, period, usd, anchor}`;
none means unlimited. `scope` is `site` (the window sums every priced leg
on the site) or `user` (the account's own legs). `period` is an ISO 8601
duration (`PT5H`, `P7D`, `P1M`). `anchor` is a date (`Y-m-d`, in the
site's timezone) or empty: anchored, the window runs from the latest
boundary at or before now to the next, the boundaries stepping from the
anchor by the period (a `P1M` window anchored on a billing day lands on
that day each month) and it clears whole at the next boundary; empty, the
window rolls over the last period and usage ages out continuously, the
way a provider's own rate windows do. `UsageGate::check()` reads the site
windows first, in config order, then the user windows, and refuses the
turn at the first window that has reached its cap, with a message naming
the window and when it clears. Users with `bypass ai usage caps` skip the
user windows only: a site window refuses everyone, bypassers included,
and is on everyone's meter; raising it is an edit to the cap.
`UsageGate::windowsFor()` is the one definition of which windows apply to
an account, and the gate's refusal, its status and the chat's meter all
follow it. The runner checks the gate before any model call.

The site scope exists for a site whose AI runs on a provider account
shared with other sites, under a budget set by whoever operates that
account: the provider's own limits (rate tiers, spend limits) are not per
site, and the site's administrators are who the budget binds, so a
bypass they hold cannot lift it. A role holding `administer ai usage
ledger` can edit the cap, so an operator grants that permission with
care. The cap is stored in USD; the settings form shows, for each window,
about how many million uncached input tokens of the site's default chat
model the cap comes to, read from the price table, so a budget can be
stated to users in tokens while the figure underneath is money.

## For the chat

`GET /daedalus-ai/usage` (route `daedalus_ai_ledger.usage`, permission
`use ai assistant`, logged-in users only, never cached) answers the
current user's windows as the gate sees them, for the chat's meter:

```json
{"windows": [{"scope": "site", "period": "P1M", "label": "1 month",
  "remaining": 0.42, "reached": false, "reset_at": 1767254400}]}
```

Site windows come first, in config order, then the user's.
`UsageGate::status()` is what the route reads (`WindowStatus` per
window): `remaining` is the fraction of the cap left (`max(0, 1 - spent
/ usd)`; a cap of zero reads 0), `reached` whether the gate would refuse
on the window, `label` the period in the date formatter's words (`P7D`
reads "1 week"), and `reset_at` the next moment the fraction changes: an
anchored window's next boundary, reached or not; a reached rolling
window's clearing moment; an open rolling window's moment its oldest
weighing leg ages out (`UsageWindows::oldestInside()`), or `null` when
nothing is inside. The list holds the windows that apply to the user, the
same ones the gate refuses by (`UsageGate::windowsFor()`): every site
window, and the user windows unless the user holds `bypass ai usage
caps`; it is empty when no cap is configured, and for a bypassing user
when only user caps are. No USD figure is in the payload: the cap and
the spend are the administrator's, on the settings form.

## Retention

`retention_days` says how many days of rows to keep (365 as shipped; 0
keeps every row). On cron, `UsageRetention::purge()` deletes the rows
older than that through the entity storage, oldest first, in batches of
`UsageLedger::PURGE_BATCH` and at most `UsageRetention::PURGE_LIMIT` per
run, so the entity hooks fire for every row and a long-lived ledger drains
over a few runs rather than holding one. The purge never cuts under the
longest span any configured window can cover (`Window::maxSeconds()`: a
rolling `P1M` is up to 31 days, an anchored window two periods), so a
shorter retention is raised to it and a window sum is never computed over
a hole; the settings form states that floor beside the field. The table
is indexed by `created` alone for this read and the site windows'.

## The status report

The entry "Daedalus AI prompt cache" at Administration > Reports > Status
report reads the last seven days of rows (`UsageLedger::REPORT_WINDOW`,
`UsageLedger::prefixReport()`): a warning when any leg re-sent a
rewritten prefix, naming how many legs, in how many conversations (up to
five ids), and how many tokens were re-paid, with what to do about it
(something varied the system prompt or the tool list between the legs of
a conversation; the agent runner logs the exact leg under the
`daedalus_ai_memory` channel); OK when the measured legs held their
prefix, with the tokens re-sent after the provider cache expired between
turns stated as a number (the TTL question's data, not a defect); and an
informational line until any leg has been measured. A fixed break clears
from the report by itself once its rows age out of the window.

The entry "Daedalus AI price table" reads the same seven days for the
models that ran legs with no price row of their own
(`UsageLedger::modelsWithoutRow()`, answered against the table as it is
now): a warning naming each such model with its leg count and whether its
legs were counted at the fallback row (`*`) or at nothing (no fallback
row either, so the caps never saw them), linking to the settings form
where the rows are added; OK when every model used in the window has a
row; and an informational line until any leg has landed. The entry
clears the moment the rows exist, since nothing about the fallback is
stamped on the row at write time.

## Reading the ledger

- `UsageLedger::legsForSite()` and `legsForUser()` are what the caps read.
- `UsageLedger::newestLeg()` answers a conversation's last prompt size for
  the segment window.
- `UsageLedger::prefixReport()`, `modelsWithoutRow()` and `legCount()` are
  what the status report reads.
- The `ai_usage` entity exposes Views data, so a site can report on it.

The price table, the caps and the retention are configured at
Administration > Configuration > AI > Daedalus AI Ledger.
