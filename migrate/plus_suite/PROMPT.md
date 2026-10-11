# Migrate this site from the Plus Suite modules to Daedalus

You are working in a Drupal site's project directory. The site runs the
old Plus Suite page-builder modules (`lb_plus`, `navigation_plus`,
`edit_plus`, `tempstore_plus`, possibly with `section_library` and the
`edit_plus_*_block` example modules). Those projects are deprecated and
replaced by one project, [Daedalus](https://www.drupal.org/project/daedalus)
(`drupal/daedalus`), whose sub-modules have different machine names. Move
this site onto Daedalus.

Daedalus ships the pathway for that inside its package, in
`web/modules/contrib/daedalus/migrate/plus_suite/` (once the package is installed):
`MIGRATING.md` is the guide, `migrate.sh` the script that runs the
migration, `translate.php` the translator it drives, `recipe.yml` the
migration recipe and `mapping.tsv` the table of old names to new names.
The guide is the authority on the mechanics; this prompt tells you how to
work, not what the steps are. Do not reconstruct the steps from memory or
from what other Drupal migrations do: this is not a normal update, and the
order the guide gives is load-bearing.

## First: check and report, touch nothing

Before changing anything, establish and tell the person, in one short
report:

1. Which of the old modules the site has, from `composer.json` and from
   `drush updatedb:status` (which must report nothing pending: the pathway
   serves sites on the latest old releases with every update run, and
   refuses older ones).
2. That drush works (`drush status`), whether the site runs under ddev (then
   every `drush`, `composer` and `bash` command below runs through
   `ddev exec` or `ddev drush`), and the Drupal version (11.3 or later).
3. That the git tree is clean (`git status`), or what is uncommitted and
   why that must be dealt with first.
4. A database backup taken outside the site with
   `drush sql:dump --result-file=/path/outside/the/site/before-daedalus.sql`,
   and its path. Never skip this, even if the person says the site is
   disposable, and never proceed if the dump failed; `migrate.sh` takes its
   own dump too, but this one exists before anything changes.
5. Which files under `modules/custom` and `themes/custom` mention an old
   module (a plain search over the names in item 1): these are the site's
   own couplings and you will port them later.
6. Which enabled text formats (the site's `filter.format.*` config) limit
   allowed HTML and allow any of `p`, `h2`, `h3`, `h4`, `blockquote`, `ul`,
   `ol`, `li` without the `class` attribute. The guide's preconditions say
   why and what the allowed HTML must read; the person fixes those formats
   on the old code before the swap (the audit refuses otherwise).

Wait for the person's go-ahead if the site is one they care about (a
production copy, a client site); proceed on your own on a throwaway copy
once they have said so.

## Then: the composer swap, and follow the guide

Run the swap from the project root exactly as the guide's step 1 gives it,
naming only the old packages this site has:

```
composer remove drupal/lb_plus drupal/navigation_plus drupal/edit_plus drupal/tempstore_plus
composer require drupal/daedalus:^1.0@alpha
```

Leave `drupal/section_library` in place; the guide removes it at the end.
Then read `web/modules/contrib/daedalus/migrate/plus_suite/MIGRATING.md` in full and
follow it: step 2 is one command,
`bash web/modules/contrib/daedalus/migrate/plus_suite/migrate.sh`, and the guide
explains each step's output so you can read it.

## Rules of engagement

- **The backup rule.** Never skip or defer the backup, and never delete a
  dump `migrate.sh` wrote until the person has reviewed the migrated site.
- **The stop-and-show rule.** If the audit refuses the site, or any step of
  `migrate.sh` fails, stop. Show the person the refusal or the failing
  step's output verbatim (the whole message, not a paraphrase) and the dump
  path it names, and ask how to proceed. Do not work around a failure:
  do not edit the database by hand, do not run the phases out of order, do
  not rerun with steps skipped, do not "fix" the site until it passes. The
  guide's "When something goes wrong" section is the only playbook; a
  failure it does not cover is a bug report for the Daedalus issue queue,
  and the person decides.
- **Never edit Daedalus's files.** Nothing under `web/modules/contrib/daedalus`
  is yours to change, including `migrate/plus_suite/`. If something there seems wrong,
  say so and stop.
- **Port the site's own code with the table.** After the run, the closing
  audit lists the files under `modules/custom` and `themes/custom` naming
  the old suite. Port each real coupling (a `use` of an old class, an
  info.yml dependency, a hook or plugin id, a JavaScript root, a CSS
  prefix) by looking its literal up in `mapping.tsv` and using the `new`
  column; leave prose and links alone. The guide's step 3 has a worked
  example. Show the person the full diff of the port before applying it,
  then `drush cache:rebuild` and confirm a page loads in edit mode.
- **The finishing steps are the guide's step 5**, in its order; do not
  invent others, and do not run `drush config:export -y` or commit until
  the person has reviewed the site in the browser.
- **Read every step's output.** `migrate.sh` prints a report per phase and
  drush prints the updates it ran; the guide says what each should show.
  Something the guide says to expect and you did not see is a stop-and-show,
  not a shrug.

## The closing report

When the run has finished and the port is applied, give the person one
report, in this shape:

1. **What migrated.** From the phase reports: the schema versions the audit
   found, the counts the rename rewrote (config objects, key_value rows,
   sections), the updates `drush updatedb` ran (name them, with what the
   section library migration reported), what the recipe installed (or, on
   an environment after the first, what the config import brought), and the
   content phase's counts (heading components converted, per host type).
2. **What best effort dropped.** The content phase's dropped keys with
   their counts (heading alignment and size classes), older revisions left
   unconverted, block types that stay as they are (call to action, teaser,
   heading), and anything else the guide's "What does not carry over" names
   that applies to this site.
3. **What to review in the browser.** The pages to look at (every layout
   page on a small site, a sample on a large one), what to check on each
   (sections and headings present, edit mode loads, nested sections show
   their content), and the result of `drush watchdog:show --severity=Error`.
4. **The custom code you ported.** File by file, what changed and why, and
   that the port has been applied and the site still loads.
5. **The finishing steps** still to do, from the guide's step 5, and which
   of them you have done; the dump paths (yours and `migrate.sh`'s) and how
   to restore them; and the reminder that every other environment that
   must survive (production) runs the migration itself against the
   committed code, as the guide explains.
