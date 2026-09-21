#!/usr/bin/env bash
#
# The site migration's orchestrator: one run moves a site from the old Plus
# Suite releases (lb_plus 3.6.x, navigation_plus 2.3.x, edit_plus 2.3.x,
# tempstore_plus 1.0.x) onto daedalus. MIGRATING.md beside this file is the
# guide; read it first.
#
# Run from the project root, after the composer swap (the old packages
# removed, drupal/daedalus required):
#
#   bash web/modules/contrib/daedalus/migrate/plus_suite/migrate.sh
#
# In order: the checks (drush found, the old modules gone from disk), a
# database dump, the audit, the rename phase, `drush updatedb`, the migration
# recipe (on the first environment) or the import of its export (on every
# other), the content phase, a cache rebuild, and the audit again as the
# closing report. The first failing step stops the run and prints the dump
# path; every phase that changes the site records its completion, so a rerun
# picks up where the run stopped and a rerun of a migrated site is a no-op.
#
# Environment: DRUSH names the drush to use (default: vendor/bin/drush in the
# current directory, else the drush on PATH); MIGRATE_DUMP_DIR is where the
# dump goes (default: the current directory).

set -euo pipefail

MIGRATE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
MAPPING="$MIGRATE_DIR/mapping.tsv"
TRANSLATE="$MIGRATE_DIR/translate.php"
DUMP=''

# Prints the failing step and where the dump is, then exits 1.
fail() {
  printf '\nmigrate.sh: stopped at "%s".\n' "$1" >&2
  if [ -n "$DUMP" ]; then
    printf 'The database as it was before this run: %s\n' "$DUMP" >&2
    printf 'Restore it with: drush sql:drop -y && drush sql:query --file=%s\n' "$DUMP" >&2
  fi
  exit 1
}

# Runs one step: a heading, the command, and fail() on a non-zero exit.
step() {
  local label=$1
  shift
  printf '\n==> %s\n' "$label"
  "$@" || fail "$label"
}

# Prints 1 when the site's config sync directory holds the export of a
# migrated environment (its core.extension lists daedalus_layout), else 0.
sync_holds_migrated_export() {
  "$DRUSH" php:eval 'echo (int) isset(\Drupal::service("config.storage.sync")->read("core.extension")["module"]["daedalus_layout"]);'
}

# Prints 1 when the module is installed, else 0.
module_installed() {
  "$DRUSH" php:eval "echo (int) \\Drupal::moduleHandler()->moduleExists('$1');"
}

# The drush to use.
if [ -z "${DRUSH:-}" ]; then
  if [ -x vendor/bin/drush ]; then
    DRUSH=vendor/bin/drush
  elif command -v drush > /dev/null 2>&1; then
    DRUSH=drush
  else
    printf 'migrate.sh: no drush found (vendor/bin/drush or on PATH); run from the project root or set DRUSH.\n' >&2
    exit 1
  fi
fi

# The package this script ships in must be the one the site loads.
if [ ! -f "$TRANSLATE" ] || [ ! -f "$MAPPING" ]; then
  printf 'migrate.sh: %s is not a daedalus migrate/plus_suite/ directory.\n' "$MIGRATE_DIR" >&2
  exit 1
fi

printf '==> Checks\n'
ROOT=$("$DRUSH" status --field=root)
if [ -z "$ROOT" ] || [ ! -d "$ROOT" ]; then
  printf 'migrate.sh: drush found no Drupal root (is this the project root?).\n' >&2
  exit 1
fi
printf 'drush: %s\nDrupal root: %s\n' "$DRUSH" "$ROOT"

# The old modules must be gone from disk: the pathway runs after the composer
# swap, and the rename phase would otherwise install daedalus beside them.
# The modules are the mapping's floor rows, the one list of what is served.
present=''
while IFS=$'\t' read -r kind old _; do
  [ "$kind" = 'floor' ] || continue
  found=$(find "$ROOT/modules" -name "$old.info.yml" -not -path '*/contrib/daedalus/*' 2> /dev/null | head -n 1)
  if [ -n "$found" ]; then
    present="$present $old"
  fi
done < "$MAPPING"
if [ -n "$present" ]; then
  printf 'migrate.sh: old modules still on disk:%s.\nRemove their packages first (composer remove drupal/lb_plus drupal/navigation_plus drupal/edit_plus drupal/tempstore_plus) and require drupal/daedalus.\n' "$present" >&2
  exit 1
fi
printf 'Old modules on disk: none\n'

DUMP_DIR=${MIGRATE_DUMP_DIR:-$PWD}
DUMP="$DUMP_DIR/daedalus-migrate-$(date +%Y%m%d-%H%M%S).sql"
step 'Database dump' "$DRUSH" sql:dump --result-file="$DUMP"
printf 'Dump: %s\n' "$DUMP"

step 'Audit (opening)' "$DRUSH" php:script "$TRANSLATE" -- audit
step 'Rename' "$DRUSH" php:script "$TRANSLATE" -- rename
step 'Updates (drush updatedb)' "$DRUSH" updatedb -y
# The config a migrated site needs and the old releases never had (the text,
# image and formatted text block types with their fields and displays, the
# Olivero design system, the settings objects) is created once, by the
# migration recipe on the first environment, and reaches every other
# environment as exported config: a recipe run there would create the same
# config entities again under new uuids, and the deploy's config import would
# then refuse them ("Entities exist of type Content block and Block type
# Text") or, past that, delete and recreate their fields with the migrated
# text in them. So when the site's config sync directory already holds a
# migrated export, the run imports it in place of the recipe. That export no
# longer lists section_library (the first environment uninstalled it), and
# core refuses to uninstall a module whose content exists, so the templates
# go first; the update step's section library migration has already read
# them, and the stencils carry them.
if [ "$(sync_holds_migrated_export)" = 1 ]; then
  if [ "$(module_installed section_library)" = 1 ]; then
    step 'Section library templates (the stencils carry them now)' "$DRUSH" entity:delete section_library_template -y
  fi
  step 'Config import (the export of the first environment)' "$DRUSH" config:import -y
else
  step 'Migration recipe' "$DRUSH" recipe "$MIGRATE_DIR"
fi
step 'Content' "$DRUSH" php:script "$TRANSLATE" -- content
step 'Cache rebuild' "$DRUSH" cache:rebuild
step 'Audit (closing)' "$DRUSH" php:script "$TRANSLATE" -- audit

printf '\nmigrate.sh: done. The closing audit above should name the old suite only under custom code; MIGRATING.md says how to port it and how to finish.\nDump: %s\n' "$DUMP"
