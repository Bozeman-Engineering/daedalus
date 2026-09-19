<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\Dialect\DialectPrintException;
use Drupal\daedalus_blueprint\Dialect\DialectPrinter;

/**
 * Renders the roster as the blueprint schema's `materials` fragment.
 *
 * The AI's one discovery lane for every material kind, projected from the
 * roster (never walked from the entities): the named values (tokens by
 * scale, swatches with their labels and descriptions), the saved variants
 * with their descriptions and where they apply, the stencils printed in
 * full as page-language fragments (sample content and worn looks, the
 * definition as a document without ids) with the looks each one owns,
 * and the animations with their keyframe steps, under one guidance
 * paragraph that teaches how each kind is read and referenced. The
 * fragment keeps the FULL text (descriptions unclipped); the cached
 * prompt's design system brief (DialectCatalogPrompt) is the twin that
 * clips every description to its first sentence and projects the same
 * roster in the active system's own structure.
 *
 * A stencil prints where the catalog is in hand: the printer spells a
 * worn variant by its record (an axis attribute or a class) and a block
 * by its declared contract, both catalog facts, so the schema builds the
 * catalog over the same roster it iterates (the catalog builder memoizes
 * on roster identity). A stencil's owned looks (the composition variants
 * only its placements may wear) list under the stencil, never under the
 * element or the flat variants map: they address the stencil's children
 * by position, and the guidance says so once.
 *
 * Grows-by-data per kind: a kind the site has none of is absent, not an
 * empty map under a reserved key, and the guidance says so once ("a kind
 * absent here has no materials on this site"), which keeps a writer from
 * inventing machine names as well as an empty roster would. Stencil
 * vocabulary (the stencils group, the fragment and looks sentences, the
 * placement spelling) rides only while the site has stencils, and the
 * animation vocabulary only while it has animations.
 *
 * The fragment iterates the roster's offered view (MaterialsRoster::
 * offered()): a material its author opted out of the AI has no entry and
 * no description here, as on the brief. The stencil prints run over the
 * full catalog, since a print spells what the definition actually wears
 * (legality, never advertisement): a stencil wearing an unoffered look
 * still prints it, and the look simply lists nowhere.
 */
class MaterialsSchema {

  /**
   * The applies sentence a root-only variant carries on its entry.
   */
  public const APPLIES_ROOT_ONLY = 'top-level sections only, never nested inside another section';

  /**
   * The placement value a band stencil carries on its entry.
   *
   * A stencil whose root wears a root-only variant (MaterialsRoster::
   * isBand()) places at the document's top level only; every other
   * stencil carries no placement key and places anywhere the grammar
   * allows.
   */
  public const PLACEMENT_TOP_LEVEL = 'top_level';

  /**
   * The one-line rule beside a stencil's owned looks.
   *
   * An owned look is a composition variant: it addresses the stencil's
   * children by position, so a placement whose shape drifts from the
   * fragment wears it differently, exactly as a person editing a
   * placement in the UI would find.
   */
  public const LOOKS_BY_POSITION = 'An owned look addresses the stencil\'s children by position: keep the shape where you keep the look.';

  public function __construct(
    protected MaterialsRosterBuilder $rosterBuilder,
    protected DialectCatalogBuilder $catalogBuilder,
    protected DialectPrinter $printer,
  ) {}

  /**
   * The fragment: guidance, then one group per kind the site has.
   */
  public function fragment(): array {
    $roster = $this->rosterBuilder->build()->offered();
    $fragment = ['guidance' => $this->guidance($roster)];
    if (($tokens = $this->tokens($roster)) !== []) {
      $fragment['tokens'] = $tokens;
    }
    if (($swatches = $this->swatches($roster)) !== []) {
      $fragment['swatches'] = $swatches;
    }
    if (($variants = $this->variants($roster)) !== []) {
      $fragment['variants'] = $variants;
    }
    if (($stencils = $this->stencils($roster)) !== []) {
      $fragment['stencils'] = $stencils;
    }
    if (($animations = $this->animations($roster)) !== []) {
      $fragment['animations'] = $animations;
    }
    return $fragment;
  }

  /**
   * What a cached schema carrying the fragment depends on.
   *
   * @return string[]
   *   The roster's cache tags.
   */
  public function cacheTags(): array {
    return $this->rosterBuilder->build()->getCacheableMetadata()->getCacheTags();
  }

  /**
   * The guidance paragraph: roster-reading facts only.
   *
   * Facts about reading the roster live here (schema = facts); the
   * preference order and the mint gate ride the style guide's own guidance
   * and the design system brief (MaterialsProse, the one source), and the
   * membership sentence is the brief's. Door-neutral: it names the page
   * language's spellings and no JSON op.
   */
  protected function guidance(MaterialsRoster $roster): string {
    $has_stencils = $roster->stencils() !== [];
    $sentences = [
      'The site\'s materials; a request like "keep to the materials" or "ignore the materials" means this set, and a kind absent here has no materials on this site. Whose members they are (the active design system\'s plus the global ones) is the design system brief\'s sentence.',
      '"tokens" are the design tokens the active skin mints, grouped by scale, and "swatches" the published palette swatches with their labels; both are named values, keyed by the CSS custom property a style value references as var(--name) (e.g. var(--space-4)). A value is a plain string or a condition-keyed map of VALUES for that ONE name ("default" always applies; a key like "dark" is the condition under which the SAME name resolves to that value, never part of its name: var(--color-bg-dark) names nothing). Colors fit color-valued properties and stops inside gradients; gradients fit image-valued properties such as background-image, alone or as a comma-separated layer.',
      '"variants" are the saved reusable looks, keyed by machine name; "spelling" is how the page language wears one (class="name", or an axis attribute on the element\'s tag such as width="full"), an entry without an axis is on the built-in "style" axis, and an "applies" line narrows where a variant may be worn.',
    ];
    if ($has_stencils) {
      $has_bands = array_filter($roster->stencils(), $roster->isBand(...)) !== [];
      $sentences[] = '"stencils" are reusable composed subtrees (root: section or block), placed as <pb-stencil name="id"/> elements in a page-language fragment; each entry\'s "fragment" is the stencil printed in the page language, its sample content and worn looks included, and its "looks" are the looks the stencil owns, wearable only inside its placements.'
        . ' ' . self::LOOKS_BY_POSITION
        . ($has_bands ? ' A stencil with "placement": "' . self::PLACEMENT_TOP_LEVEL . '" is a page band (its root wears a top-level-only variant): place it at the document\'s top level, never inside another container.' : '')
        . ' When the user asks to save a composition as a stencil: build the subtree with ordinary ops, then save_material (kind stencil) its root element in the same page_edit batch.';
    }
    if ($roster->animations() !== []) {
      $sentences[] = '"animations" are the published keyframes animations, keyed by machine name; "spelling" is the ident an animation-name value (or the name in an animation shorthand) references, never a var(); "steps" are the keyframes as stored, offset => declarations.';
    }
    return implode(' ', $sentences);
  }

  /**
   * The tokens, grouped by scale, keyed by custom property, values as stored.
   */
  protected function tokens(MaterialsRoster $roster): array {
    $groups = [];
    foreach ($roster->tokens() as $record) {
      $groups[(string) $record->group][self::customProperty($record)] = $record->value;
    }
    return $groups;
  }

  /**
   * The swatches, keyed by custom property: label, kind, value, description.
   *
   * The description (when to reach for the swatch, in the system's
   * voice) rides only when the record carries one, the omit-when-nothing
   * rule the variants follow.
   */
  protected function swatches(MaterialsRoster $roster): array {
    $swatches = [];
    foreach ($roster->swatches() as $record) {
      $entry = [
        'label' => $record->label,
        'kind' => $record->group,
        'value' => $record->value,
      ];
      if ($record->description !== '') {
        $entry['description'] = $record->description;
      }
      $swatches[self::customProperty($record)] = $entry;
    }
    return $swatches;
  }

  /**
   * The unowned variants, keyed by machine name, applicability serialized on.
   *
   * Per entry: label, the page-language spelling when the variant has one,
   * scope, axis only when not the built-in style axis, an applies line
   * when the variant is root-only, and description when it carries one.
   * Keys with nothing to say are omitted rather than emitted empty. A
   * look a stencil owns is absent here: it lists under its stencil.
   */
  protected function variants(MaterialsRoster $roster): array {
    $variants = [];
    foreach ($roster->variants() as $record) {
      if ($record->ownedBy !== NULL) {
        continue;
      }
      $entry = ['label' => $record->label];
      $spelling = $this->spellingProse($record);
      if ($spelling !== '') {
        $entry['spelling'] = $spelling;
      }
      $entry['scope'] = $record->scope?->__toString();
      if ($record->group !== NULL && $record->group !== 'style') {
        $entry['axis'] = $record->group;
      }
      if ($record->rootOnly()) {
        $entry['applies'] = self::APPLIES_ROOT_ONLY;
      }
      if ($record->description !== '') {
        $entry['description'] = $record->description;
      }
      $variants[$record->id] = $entry;
    }
    return $variants;
  }

  /**
   * The stencils by id: label, description, root, fragment, looks, placement.
   *
   * The fragment is the record's tree printed through the dialect printer
   * over the site's catalog (DialectPrinter::printFragment(): no ids, no
   * <style> block, every worn variant spelled as the page language wears
   * it, every block's sample content as its attributes and body). A tree
   * the page language cannot print (a multi-region layout, a part or
   * condition style on a definition, a worn variant the catalog spells
   * nowhere) carries "unprintable" with the printer's reason instead: the
   * stencil stays placeable by name and the schema still answers, the
   * one recovery a whole-site read can take for one definition. The
   * looks are the variants the stencil owns, keyed by machine name; the
   * placement key rides only a band stencil (top_level). Keys with
   * nothing to say are omitted.
   */
  protected function stencils(MaterialsRoster $roster): array {
    $records = $roster->stencils();
    if ($records === []) {
      return [];
    }
    $catalog = $this->catalogBuilder->build();
    $owned = [];
    foreach ($roster->variants() as $variant) {
      if ($variant->ownedBy !== NULL) {
        $owned[$variant->ownedBy][$variant->id] = $this->lookEntry($variant);
      }
    }
    $stencils = [];
    foreach ($records as $record) {
      $entry = [
        'label' => $record->label,
        'description' => $record->description,
        'root' => $record->rootKind,
      ];
      if ($record->tree !== NULL) {
        $entry += $this->fragmentEntry($record, $catalog);
      }
      if (isset($owned[$record->id])) {
        $entry['looks'] = $owned[$record->id];
      }
      if ($roster->isBand($record)) {
        $entry['placement'] = self::PLACEMENT_TOP_LEVEL;
      }
      $stencils[$record->id] = $entry;
    }
    return $stencils;
  }

  /**
   * A stencil's printed fragment, or the reason the page language has none.
   *
   * @return array
   *   ['fragment' => the print] or ['unprintable' => the printer's reason].
   */
  protected function fragmentEntry(MaterialRecord $record, DialectCatalog $catalog): array {
    try {
      return ['fragment' => $this->printer->printFragment($record->tree, $catalog)];
    }
    catch (DialectPrintException $e) {
      return ['unprintable' => sprintf('The page language cannot print this stencil (%s); place it as <pb-stencil name="%s"/> and style the placed elements once they print with ids.', $e->getMessage(), $record->id)];
    }
  }

  /**
   * An owned look's entry under its stencil: label, spelling, description.
   *
   * No scope and no owner key: the stencil it sits under is the owner,
   * and its placements are the only place the look is worn.
   */
  protected function lookEntry(MaterialRecord $record): array {
    $entry = ['label' => $record->label];
    $spelling = $this->spellingProse($record);
    if ($spelling !== '') {
      $entry['spelling'] = $spelling;
    }
    if ($record->description !== '') {
      $entry['description'] = $record->description;
    }
    return $entry;
  }

  /**
   * A record's spellings as the page language writes them, comma-joined.
   */
  protected function spellingProse(MaterialRecord $record): string {
    $spellings = [];
    foreach ($record->spellings as $slot => $spelling) {
      $spellings[] = MaterialsRoster::spellingProse($slot, $spelling);
    }
    return implode(', ', $spellings);
  }

  /**
   * The animations, keyed by machine name: label, spelling, steps, description.
   *
   * The steps ride the record's value (offset => declarations) so a
   * writer sees what an animation does before wearing it; the spelling is
   * the ident as animation-name writes it, never a var() (customProperty()
   * refuses an animation record on purpose). Description only when the
   * record carries one.
   */
  protected function animations(MaterialsRoster $roster): array {
    $animations = [];
    foreach ($roster->animations() as $record) {
      $entry = ['label' => $record->label];
      $spelling = $this->spellingProse($record);
      if ($spelling !== '') {
        $entry['spelling'] = $spelling;
      }
      $entry['steps'] = $record->value;
      if ($record->description !== '') {
        $entry['description'] = $record->description;
      }
      $animations[$record->id] = $entry;
    }
    return $animations;
  }

  /**
   * The custom property a named value's style spelling references.
   *
   * The style spelling is the whole reference as written, var(--x); the
   * schema keys named values by the property inside it (--x), the one
   * spelling the JSON door's bindings and the page language's var() share.
   */
  public static function customProperty(MaterialRecord $record): string {
    $spelling = $record->spelling(MaterialRecord::SLOT_STYLE);
    if ($spelling === NULL || !preg_match('/^var\(\s*(--[A-Za-z0-9_-]+)\s*\)$/', $spelling, $m)) {
      throw new \LogicException(sprintf('%s "%s" has no var() style spelling to key a named value by.', ucfirst($record->kind->noun()), $record->id));
    }
    return $m[1];
  }

}
