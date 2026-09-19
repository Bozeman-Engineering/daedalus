# Starter design system (daedalus_stylish_starter_skin)

A blank canvas to build a design system off: the `starter` design
system as install config (`enforcement: open`, its own skin, this
module as package) and one skin that mints a neutral placeholder token
on every scale the suite declares, with base-tag defaults that
reference those tokens and nothing else. No guide fragment, no CSS, no
templates. It is not part of any recipe; a site enables it when it
wants to start from nothing rather than from a finished look.

Why a placeholder on every scale: the suite's own bindings
(`daedalus_stylish.page_builder.yml` `site.constraint_defaults`) bind
font-family, font-size, font-weight, line-height, padding, gap and
border-radius to the font, text, weight, leading, space and radius
scales on every site, and a bound scale minting nothing is an empty
row with no fallback by design. The starter is the smallest skin that
keeps every promise the suite makes, so every basic row offers
something the moment it is active, and tracking, transition and layout
are minted too so no declared scale is blank.

## Building a design system off it

Copy the module under your own name (three files: the info file, the
skin, and `config/install`'s design system), rename the system and the
skin in all three, and re-value the tokens; the defaults keep working
because they speak token names, not values. Stay `open` while the look
is being found (the guide shapes offers, a typed literal still
commits), then add a `MODULE.page_builder.yml` carrying only your
deltas over the suite's bindings (`stops:` curation on padding, gap and
font-size; the color and letter-spacing bindings the suite leaves to
packages; extra `allowed:` literals; `null` withdrawals) as the look
settles, and switch the system's enforcement to `guarded` or `strict`
once every value on the site is a token. `daedalus_stylish_olivero_skin` is the
finished open shape of that path and `daedalus_stylish_guide_example` the
finished strict one, with per-block-type and per-layout files,
variants, swatches, animations and a stencil as members; grow toward
whichever posture the site wants.

## Enable

```
drush en daedalus_stylish_starter_skin
drush daedalus-blueprint:design-system:activate starter
```

`drush en` installs the package as a dormant unit; activation syncs
the skin from disk under the system's law and makes it the active
skin. Switch back with `... activate olivero`. After editing the skin
file, `drush daedalus_stylish:sync daedalus_stylish_starter_skin` lands the change.
