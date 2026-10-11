<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\Dialect\DomOperationHandler;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsProse;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\OperationHandler\OperationHandlerRegistry;

/**
 * Renders the dialect catalog as the cached-prompt page-language section.
 *
 * Facts in the cached prefix: the component reference,
 * the materials roster with current values, and the op set live HERE, in
 * the stable system prompt the provider caches, so every turn's writes
 * are grounded without a read ritual. Behavioral doctrine (design taste,
 * styling recipes) stays on-demand in the skills. Everything below is
 * DERIVED from the generated catalog and the materials roster, never
 * hand-listed, so the prompt is a projection of live config exactly like
 * the schema surfaces.
 *
 * The design system brief is the styling half of the section: the ONE
 * thing the model is introduced to. It opens with the active design
 * system by name (its identity paragraph, the entity's description), its
 * law (the mode, the polarity sentence and the skin), the membership,
 * preference and stack sentences, then the roster projected in the
 * system's own structure: per element kind its axes (each with its
 * description, the properties it owns and its values), its class looks
 * and its birth line, then the favorite block blueprints, each with the
 * looks it owns beneath it, and the group index of the rest (the schema
 * prints a favorite as a page-language fragment and a group in full on
 * demand; the block line above the brief states the placement contract;
 * a page blueprint is a start, not a placement, and reaches the model on
 * demand through a bundle's schema read, never through this cached
 * prefix, which has no bundle dimension), the named
 * values (swatches with their descriptions), the animations, the tags
 * the skin dresses, and one mint line. Every description is clipped to
 * its first sentence: the prefix pays for what a material is and when
 * to reach for it, the schema keeps the full text (MaterialsSchema).
 * With no active system the brief says so and carries the global
 * materials under the guarded posture.
 *
 * The whole section reads the catalog's offered view (DialectCatalog::
 * offered(), the roster's MaterialsRoster::offered()): a material its
 * author opted out of the AI is on no element line, in no brief block
 * and behind no mint gesture here, exactly as it is absent from the
 * schema, while it stays legal to wear. One seam, in render(); every line
 * below projects the catalog it is handed.
 */
class DialectCatalogPrompt {

  /**
   * The longest a named value's rendered part may be on the prefix.
   *
   * Font stacks are the values that overrun; 32 characters keeps the
   * first family or two, which is what the model needs to pick one.
   */
  protected const VALUE_CLIP = 32;

  public function __construct(
    protected DialectCatalogBuilder $catalogBuilder,
    protected OperationHandlerRegistry $operationHandlerRegistry,
    protected MaterialsRosterBuilder $rosterBuilder,
    protected ActiveDesignSystem $activeDesignSystem,
  ) {}

  /**
   * Renders the "## Page language" system prompt section.
   */
  public function render(): string {
    $catalog = $this->catalogBuilder->build()->offered();
    $lines = [];
    $lines[] = '## Page language';
    $lines[] = '';
    $lines[] = 'Pages are HTML in a small dialect: what you read (the "Current page print" tag) is exactly what you write (page_edit fragments, page_create documents). Element ids are minted uuids; on elements YOU add, choose any id (id="hero") and the result echoes id -> uuid (prints carry your name as data-alias, keep using it). Never author id, data-*, or src.';
    $lines[] = '';
    $lines[] = 'ELEMENTS (attr(allowed values); (link) takes a path or URL, (ref) an id or exact label of existing content; file takes the name of a file this conversation holds, generate a prompt for a new image):';
    $lines[] = $this->containerLine($catalog);
    $lines[] = $this->slotLine();
    foreach ($catalog->blockTypes() as $type => $spec) {
      $lines[] = $this->blockTypeLine($catalog, $type, $spec);
    }
    $lines[] = $this->blockLine($catalog);
    $lines[] = '';
    $lines[] = $this->stylesLine($catalog);
    $lines[] = $this->conditionsLine($catalog);
    $lines[] = 'Values are named values (var(--name), MATERIALS below) or single CSS values; clamp()/calc() and !important are not supported. Images in CSS: url(\'media:<uuid>\') references existing media on media-capable properties (background-image), and url(\'generate:<prompt>\') as a layer or the whole value mints a new image (linear-gradient(...), url(\'generate:<prompt>\') darkens a generated image in one write; it prints back as a media token once generated; never wait for it); url(\'file:<name>\') as a layer or the whole value places a file this conversation holds (it becomes media at once). Always single-quote url() arguments: a double quote ends a style="" attribute early.';
    $lines[] = $this->classLine($catalog);
    array_push($lines, ...$this->stylableLines($catalog));
    $lines[] = '';
    array_push($lines, ...$this->briefLines($catalog));
    $lines[] = '';
    $lines[] = 'OPS (page_edit "ops": a JSON array applied as ONE atomic batch; each op {"op": name, ...}):';
    $lines[] = '- insert {html, at: {ref, position: beforebegin|afterbegin|beforeend|afterend}} — html is a dialect fragment; ref is an element id or "root" (the page).';
    $lines[] = '- set {ref, attrs: {...}, detach: [...]} — the canonical edit: patch attributes, class (the element\'s whole variant wear), body (the element\'s text or rich body), or style (style replaces the whole style="" value); null clears one. detach lists worn variants (a class, or an axis attribute name) to materialize into the element\'s own styles and unlink. ref "root" patches the page\'s own fields (title, ...: what page_create\'s fields takes).';
    $lines[] = '- style {css, remove: [{selector, properties: [names]}]} — css rules merge into what is there; deleting a property needs the remove list (unset is a value, not a delete).';
    $lines[] = '- replace {ref, html} — whole-element rewrite; styles the new html does not restate are gone, so prefer set for edits.';
    $lines[] = '- move {ref, at}; remove {ref}.';
    $lines[] = '- file {name, type, content}: declares bytes you author (an SVG, a stylesheet, CSV, text; never binary) as a file this conversation holds under name, type its MIME (image/svg+xml, text/css, text/csv, text/plain); put it ahead of the ops that place it with file="name" or url(\'file:name\'). In a page_create document the same declaration is a top-level <pb-file name="..." type="...">the bytes</pb-file> beside <style>.';
    $page_ops = $this->operationHandlerRegistry->getPageLanguageOperations();
    $guidance = $this->operationHandlerRegistry->getGuidance();
    foreach (array_diff($page_ops, DomOperationHandler::OPS) as $op) {
      $lines[] = sprintf('- %s {...} — %s.', $op, rtrim($guidance[$op] ?? 'a page gesture', '.'));
    }
    $lines[] = 'Failed batches roll back whole; errors are corrective, fix and resend. These ' . count($page_ops) . ' ops are the entire edit grammar: pages are never edited through eb_edit.';
    return implode("\n", $lines);
  }

  /**
   * The block element line, with the blueprint sentence where blueprints exist.
   *
   * The blueprint sentence is the ONE model-facing statement of the
   * blueprint contract: the two ways to copy one (<pb-blueprint
   * name="id"/> for a copy as is, or the blueprint's printed fragment
   * written with the author's own content and blueprint="id" on the root,
   * the label being what makes an element a placement), the one way to
   * link one (<pb-linked blueprint="id"/>, a block showing the saved
   * blueprint's live document), where a placement
   * lands (a page band at the top level only, anything else wherever a
   * container or block sits), that the reference tag itself takes no
   * style or class, that the owned looks are wearable inside a placement
   * and address its children by position, that a saved blueprint is a
   * page of its own (its body read and edited on demand through page_read
   * and page_edit as entity_type blueprint, never guessed from the
   * placement), and that blueprints are pre-approved blocks and examples,
   * never a boundary. The capture and update keys ride the MINT line; the
   * blueprints and their owned looks ride the BLUEPRINTS lines, and their
   * printed fragments the schema.
   */
  protected function blockLine(DialectCatalog $catalog): string {
    $block_axes = $this->axesProse($catalog->axesFor(FALSE, NULL, NULL));
    $line = sprintf('- <pb-block field="name"%s/> renders one of the page\'s own fields (name is a field machine name of the page; the page supplies the block, so the same element fits any page that has the field). <pb-block plugin="plugin_id"%s/> renders an existing plugin block. An undeclared block type is <pb-block type="x"%s><pb-field name="field_y">value</pb-field></pb-block>.', $block_axes, $block_axes, $block_axes);
    if ($catalog->roster()->blueprints(MaterialRecord::SCOPE_BLOCK) !== []) {
      $line .= ' <pb-blueprint name="blueprint_id"/> places a saved blueprint (the design system\'s BLUEPRINTS below) as is, where the element sits: a page band (top-level only) at the document\'s top level, never inside a container; any other blueprint wherever a container or block may sit; the reference tag itself takes no style or class. To place one with your own content, write its printed fragment (the schema prints the favorites in full, a group with its group parameter) with your content and blueprint="blueprint_id" on the root: that label is what makes the element a placement, so keep it when you rewrite one. Inside a placement the blueprint\'s owned looks (listed under it) are wearable; they address the placement\'s children by position, so keep the shape where you keep the look. A blueprint saved on this site is a page of its own: page_read (entity_type blueprint, its entity_id) prints its body with ids and page_edit changes it for every later placement; a shipped one is read-only. <pb-linked blueprint="blueprint_id"/> shows a saved blueprint by reference instead of copying it: one block, inside a container, whose content is the blueprint\'s live document (a field block in it shows this page\'s own field) and follows every later edit of the blueprint; it takes no children and no content of its own (edit the blueprint), only its own box styles; a shipped file cannot be linked. Blueprints are pre-approved blocks and examples to build with or beside, never a boundary: hand-rolled work from the same materials is as on brand.';
    }
    return $line;
  }

  /**
   * The class rule: variants by scope, the declared rosters as placement.
   *
   * The composition clause rides here because it is a fact about class
   * wear on every site with style variants: a composition (a variant
   * whose styles hold slot paths) distributes over the children present
   * when it is worn, so re-wearing it is the re-resolve gesture.
   */
  protected function classLine(DialectCatalog $catalog): string {
    $all = array_keys($catalog->styleVariants());
    if ($all === []) {
      return 'Utility classes do not exist: class is empty on this site (no saved style variants yet).';
    }
    $line = 'Utility classes do not exist: class="" wears saved style variants by name, one class per variant, and the class attribute IS the element\'s whole variant wear (restate all of them when you change one). A composition variant distributes over the children present when it is worn; children added later get nothing until you take the class off and wear it again.';
    if ($this->looksElsewhere($catalog) !== []) {
      $line .= ' A variant outside the per-element rosters above applies where its scope fits (the brief\'s OTHER LOOKS; scopes in the schema\'s materials).';
    }
    return $line;
  }

  /**
   * The container's component reference line, birth rule included.
   */
  protected function containerLine(DialectCatalog $catalog): string {
    $axes = $catalog->axesFor(TRUE, NULL, NULL);
    $classes = array_keys($catalog->classRosterFor(TRUE, NULL, NULL));
    return sprintf(
      '- <pb-container%s> — the one grouping element, nestable; children stack unless layout says otherwise, and every direct child of a columns container is a column.%s%s',
      $this->axesProse($axes),
      $classes === [] ? '' : ' class(' . implode('|', $classes) . ')',
      $this->totalStatementProse($axes),
    );
  }

  /**
   * The slot section line: the one grammar of a page inside a locked template.
   *
   * The ONE model-facing statement of the slot contract (the schema's
   * pb-slot entry teaches the element; this line teaches the page): what
   * a locked page's print is (the template read-only, then the page's own
   * slot sections writable), where the door is (the template's slot block,
   * data-can="insert"), how a slot is filled (insert inside the door; the
   * slot section is born with the first fill) and where <pb-slot> is
   * authored directly (the top level of a document the page owns whole:
   * a page blueprint of slot fills, the start of a locked bundle's page).
   * Static text: the dialect has the tag whatever the site's rosters hold.
   */
  protected function slotLine(): string {
    return '- <pb-slot name="slot_id"> — a top-level container filling a slot of the document around this page (a locked bundle\'s template); its children are the page\'s own content, shown where the slot sits. A locked page prints its template read-only (data-can="") followed by its own <pb-slot> sections, writable: each slot block in the template (<pb-block plugin="daedalus_slot:slot_id" data-can="insert"/>) is the door, so insert with at: {ref: that block\'s id, position: beforeend} lands in the page\'s <pb-slot name="slot_id"> (born with the first fill); an existing <pb-slot> is edited like any container, and the page\'s root takes no insert. Author <pb-slot> yourself only at the top level of a document the page owns whole (a page blueprint of slot fills, the start of a locked bundle\'s page).';
  }

  /**
   * The birth rule on the container line: an omitted axis is bare.
   *
   * The dialect is a total statement (DomOperationHandler runs every
   * document and fragment as a dressed batch, so the styling layer stamps
   * no birth default under it): a container wears exactly the axes it
   * states. A page band therefore states its band axes (the top-level-only
   * ones the catalog lists) itself; width="site" leads as the example
   * when the site offers that spelling, since it is the content column
   * every band shares unless it is deliberately full-bleed. The house
   * VALUES of the other band axes are doctrine, carried by the site's
   * design skill, not by this data-derived line.
   *
   * Public and static because page_create's description states the same
   * rule from the same axes (one sentence, two surfaces).
   *
   * @param array $axes
   *   The container's axis views (DialectCatalog::axesFor()).
   */
  public static function totalStatementProse(array $axes): string {
    $prose = ' An omitted axis is not worn (a document or fragment is a total statement: nothing is stamped at birth)';
    $band = array_keys(array_filter($axes, static fn (array $axis): bool => !empty($axis['top_level_only'])));
    if ($band === []) {
      return $prose . '.';
    }
    $site = in_array('width', $band, TRUE) && isset($axes['width']['values']['site']);
    $others = $site ? array_values(array_diff($band, ['width'])) : $band;
    if (!$site) {
      return $prose . sprintf(', so a page band states each of its top-level-only axes (%s) itself.', implode(', ', $others));
    }
    $prose .= ', so a page band states width="site" (the site\'s content column; omit it only for a deliberately full-bleed band)';
    return $prose . ($others === [] ? '.' : sprintf(' and its other top-level-only axes (%s).', implode(', ', $others)));
  }

  /**
   * An element's axis attributes as attr(value|value; top-level only).
   *
   * One rendering for every tag's axes (the container's and each block
   * form's), leading space included; '' when the element has none.
   */
  protected function axesProse(array $axes): string {
    $attrs = [];
    foreach ($axes as $attr => $axis) {
      $values = implode('|', array_keys($axis['values']));
      $suffix = !empty($axis['top_level_only']) ? '; top-level only' : '';
      $attrs[] = sprintf('%s(%s%s)', $attr, $values, $suffix);
    }
    return $attrs === [] ? '' : ' ' . implode(' ', $attrs);
  }

  /**
   * One block type's component reference line: axes, fields, body, classes.
   */
  protected function blockTypeLine(DialectCatalog $catalog, string $type, array $spec): string {
    $tag = DialectCatalog::tagForBlockType($type);
    $attrs = [];
    foreach ($spec['attributes'] ?? [] as $name => $attr_spec) {
      $detail = '';
      if (isset($attr_spec['values'])) {
        $detail = '(' . implode('|', $attr_spec['values']) . ')';
      }
      elseif (($attr_spec['kind'] ?? NULL) === 'link') {
        $detail = '(link)';
      }
      elseif (($attr_spec['kind'] ?? NULL) === 'ref') {
        $detail = '(ref)';
      }
      $attrs[] = $name . $detail;
    }
    // Axis attributes lead, in print order; field attributes follow.
    $attr_string = $this->axesProse($catalog->axesFor(FALSE, $type, NULL)) . ($attrs === [] ? '' : ' ' . implode(' ', $attrs));
    $body = $spec['body'] ?? NULL;
    $line = $body === NULL
      ? sprintf('- <%s%s />', $tag, $attr_string)
      : sprintf(
        '- <%s%s>%s</%s> (the body is field %s)',
        $tag,
        $attr_string,
        $body['kind'] === 'rich' ? 'rich HTML body' : 'plain text body',
        $tag,
        $body['field'],
      );
    $classes = array_keys($catalog->classRosterFor(FALSE, $type, NULL));
    if ($classes !== []) {
      $line .= ' — class(' . implode('|', $classes) . ')';
    }
    return $line;
  }

  /**
   * The vocabulary roster lines, in the words the styling layer teaches.
   *
   * The catalog carries one roster per element kind and the prose
   * generator of the layer that built them (VocabularyProseInterface,
   * daedalus_stylish's VocabularyProse): the rosters are taught by PROPERTY NAME
   * in every posture, the widest kind in full and the others as deltas,
   * and only the sentence saying how a property outside a roster is
   * judged varies with the site. Rendered there, never here, so the
   * cached prompt, the schema's stylable entries and the skill's law are
   * one text. One roster per kind at every placement depth, so no depth
   * line exists.
   *
   * @return string[]
   *   The roster lines; empty when the catalog carries no roster.
   */
  protected function stylableLines(DialectCatalog $catalog): array {
    $rosters = $catalog->vocabularies();
    $prose = $catalog->vocabularyProse();
    if ($rosters === [] || $prose === NULL) {
      return [];
    }
    return $prose->rosterLines($rosters);
  }

  /**
   * The STYLES rule: the base look, the selector grammar, the precedence.
   *
   * One line, read from the catalog: where the base look and every other
   * rule ride (a page_create document's trailing <style> block and the
   * page_edit style op, both named: a line that only says "through the
   * style op" reads as edit-only and leaves a new page without its
   * narrow rules), the relative-selector grammar under #id with the
   * site's own spellings (the block types as types, the base tags, the
   * declared parts with their owners, each family absent from the
   * catalog printing nothing; the sibling combinators and the two
   * qualifiers with examples in the site's spellings), the one rule the
   * grammar exists for
   * (style a container's children through one rule on the container,
   * never the same look copied onto each child) with the one precedence
   * sentence (the nearest element's own rule wins), the wear clause (a
   * rule wears a variant on every match as a declaration in its plain
   * block, class: or an axis, the attribute's twin; never under a
   * condition, never a composition or a top-level-only axis; remove
   * takes it off), the rich-text identity classes where the spine
   * declares a namespace, the generated boxes (a selector may end in
   * ::before or ::after, the element's own or a compound's; born with
   * content: "", which takes "", none or one media reference and
   * nothing else, text being content, the image rendering at its own
   * size while background-image on the box sizes and layers; states
   * before the box), and the pseudo-class position of states and
   * facts, leading
   * into the conditions line.
   */
  protected function stylesLine(DialectCatalog $catalog): string {
    $types = $catalog->types();
    $type = in_array(DialectCatalog::tagForBlockType('text'), $types, TRUE) ? DialectCatalog::tagForBlockType('text') : $types[0];
    $shapes = ['> * (its direct children)'];
    $shapes[] = sprintf('> %s (the %s children; types: %s)', $type, $type, implode(', ', $types));
    $tags = $catalog->textTags();
    $tag = $catalog->exampleTextTag();
    if ($tags !== []) {
      $shapes[] = sprintf('%s (every %s inside it, present and future; tags: %s)', $tag, $tag, implode(', ', $tags));
    }
    // The declared parts by owner, and the chain example from the example
    // type's own part, else the first block type owning one (a part is
    // reached after its owner's type).
    $by_owner = [];
    foreach ($catalog->selectors() as $owner => $roster) {
      if (($roster['parts'] ?? []) !== []) {
        $by_owner[] = $owner . ' ' . implode(', ', array_keys($roster['parts']));
      }
    }
    $chain = sprintf('#band > %s > *', $type);
    foreach ([$type, ...array_keys($catalog->selectors())] as $owner) {
      $parts = $catalog->partsOf($owner);
      if ($owner !== RelativeSelector::TYPE_CONTAINER && $parts !== []) {
        $chain = sprintf('#band > %s %s', $owner, array_key_first($parts));
        break;
      }
    }
    if ($by_owner !== []) {
      $shapes[] = sprintf('.part (declared parts: %s)', implode('; ', $by_owner));
    }
    // The relations, spelled with the site's own words: the sibling
    // example on the example tag (a type without a tag tier), the
    // qualifier examples narrowing the example type by a link (the
    // example tag, or another type without one) and by the image type
    // (else any other type).
    $sibling = $tags === [] ? $type : $tag;
    $other = in_array('pb-image', $types, TRUE) ? 'pb-image' : array_values(array_diff($types, [$type]))[0];
    $inside = $tags === [] ? $other : (in_array('a', $tags, TRUE) ? 'a' : $tag);
    $an = static fn (string $word): string => (preg_match('/^[aeiou]/', $word) ? 'an ' : 'a ') . $word;
    $line = 'STYLES: style="" is the element\'s own base look, plain CSS declarations. Every other rule is real CSS: a page_create document carries them in one trailing <style> block, a page_edit batch in the style op. A selector is #id plus an optional relative selector into #id\'s own subtree: ' . implode(', ', $shapes) . sprintf(', joined by a space (descendant), > (direct child), + (the next sibling) or ~ (any later sibling), as in %s and #band > %s + %s (a %s right after a %s among its children).', $chain, $sibling, $sibling, $sibling, $sibling);
    $line .= sprintf(' A compound narrows by :has() (a relative selector from it: #band > %s:has(%s) the %s children that contain %s, #band > %s:has(+ %s) the one right before %s) and :not() (one compound it is not: #band > *:not(%s) every child but the %s ones).', $type, $inside, $type, $an($inside), $type, $other, $an($other), $other, $other);
    $line .= sprintf(' Style a container\'s children with ONE rule on the container, never the same look copied onto each child; the nearest element\'s own rule wins (#child beats #band > %s on that child).', $type);
    $line .= sprintf(' A rule also wears a variant on every element it matches, as a declaration in its plain block, the attribute\'s twin: class: {class} (style classes, space-separated) or {axis}: {value} (an axis attribute of the matched element), as in #band > %s { class: {class}; color: red; }; never under a state or wrapper, never a composition or a top-level-only axis; take it off with remove naming class or the axis.', $type);
    if ($catalog->instances() !== []) {
      $prefix = 's-';
      $pattern = (string) array_key_first($catalog->instances());
      if (preg_match('/^\.([A-Za-z0-9_-]*)\*$/', $pattern, $m)) {
        $prefix = $m[1];
      }
      $line .= sprintf(' Rich text styles by element: the %s* classes in a body are element identities; #id .%sx styles that ONE element, #id .%sx > * one list\'s items; author your own %s* classes on the elements you mean to style and KEEP the %s* classes on elements you keep when rewriting a body (a dropped class orphans its styles); other tags are the text format\'s, not styling targets.', $prefix, $prefix, $prefix, $prefix, $prefix);
    }
    $line .= sprintf(' A selector may end in ::before or ::after, the generated box of what it addresses (#id::after the element\'s own box, #band > %s::before each %s child\'s), a rule of its own like the others: a box is born with content: "" (its existence switch; content takes "", none or one media reference url(\'media:<uuid>\') (the image at its own size; background-image on the box sizes and layers) and nothing else, since text belongs in the element\'s content), and states go before it (#id:hover::after).', $type, $type);
    return $line . sprintf(' States and position facts are pseudo-classes on the last compound, after its qualifiers (#band > %s:hover), inside the published wrappers:', $type);
  }

  /**
   * The published conditions by family, each named beside its wrapper.
   *
   * States spell as pseudo-classes; the width bands (container-query
   * wrappers) are named with their wrappers and taught base-out, the
   * default (no wrapper) being the wide look each band adapts downward,
   * with one example that steps a headline down on a phone: that is the
   * responsive grammar the value grammar's clamp() refusal points at.
   * Preferences (@media) close the line. Every wrapper is verbatim and
   * the set is closed. Grows by data: a family absent from the catalog
   * prints nothing.
   */
  protected function conditionsLine(DialectCatalog $catalog): string {
    $named = static fn (array $conditions): string => implode(', ', array_map(
      static fn (string $name, string $wrapper): string => $name . ' ' . $wrapper,
      array_keys($conditions),
      $conditions,
    ));
    $parts = [];
    $wrappers = $catalog->conditions();
    $states = array_map(static fn (string $name): string => $wrappers[$name], $catalog->stateConditions());
    if ($states !== []) {
      $parts[] = 'states ' . implode(', ', $states);
    }
    $facts = array_map(static fn (string $name): string => $wrappers[$name], $catalog->factConditions());
    if ($facts !== []) {
      $parts[] = 'position facts ' . implode(', ', $facts) . ' (the addressed element\'s place among its siblings, spelled like a state: #band > pb-text:first-child)';
    }
    $bands = $catalog->widthBands();
    if ($bands !== []) {
      $narrow = array_key_first($bands);
      $parts[] = sprintf(
        'width bands %s (base-out: the default, no wrapper, IS the wide look and each band adapts it downward; %s { #hero-title { font-size: 2.5rem } } steps a headline down on phones; there is no wide wrapper)',
        $named($bands),
        $bands[$narrow],
      );
    }
    $preferences = $catalog->preferenceWrappers();
    if ($preferences !== []) {
      $parts[] = 'preferences ' . $named($preferences);
    }
    $line = implode('; ', $parts) . '.';
    if ($bands !== [] || $preferences !== []) {
      $line .= ' Wrappers verbatim: no other @media/@container preludes exist.';
    }
    return $line;
  }

  /**
   * The design system brief: the section the model is introduced through.
   *
   * @return string[]
   *   The lines, heading first.
   */
  protected function briefLines(DialectCatalog $catalog): array {
    $system = $this->activeDesignSystem->load();
    $roster = $catalog->roster();
    $prose = $catalog->vocabularyProse();
    $lines = [];
    $lines[] = '## Design system: ' . ($system === NULL ? 'none active' : $system->label());
    $identity = $system === NULL ? '' : trim($system->getDescription());
    if ($identity !== '') {
      $lines[] = $identity;
    }
    $law = sprintf('Law: %s.', $system?->getEnforcement() ?? DesignSystemInterface::ENFORCEMENT_GUARDED);
    if ($prose !== NULL) {
      $law .= ' ' . $prose->polaritySentence();
    }
    $lines[] = $law . ' ' . $this->skinSentence($catalog);
    $doctrine = MaterialsProse::membershipSentence($system?->label()) . ' ' . MaterialsProse::preferenceSentence();
    if ($prose !== NULL) {
      $doctrine .= ' ' . $prose->stackSentence();
    }
    $lines[] = $doctrine;
    array_push($lines, ...$this->elementLines($catalog));
    array_push($lines, ...$this->otherLookLines($catalog));
    array_push($lines, ...$this->blueprintLines($catalog));
    $named = [...$this->tokenLines($roster), ...$this->swatchLines($roster)];
    if ($named !== []) {
      $lines[] = 'NAMED VALUES (var(--name)):';
      array_push($lines, ...$named);
    }
    if ($roster->animations() !== []) {
      $lines[] = 'ANIMATIONS (id "Label" (animation-name: spelling)):';
      foreach ($roster->animations() as $record) {
        $lines[] = '- ' . $this->materialLine($record, $this->spellingProse($record));
      }
    }
    if ($roster->fonts() !== []) {
      $lines[] = 'FONTS (id "Label" (font-family: spelling); self-hosted faces, use the family in a font-family stack):';
      foreach ($roster->fonts() as $record) {
        $lines[] = '- ' . $this->materialLine($record, $this->spellingProse($record));
      }
    }
    if ($catalog->dressedTags() !== []) {
      $lines[] = sprintf('The skin dresses %s: pick the tag, the skin sizes it.', implode(', ', $catalog->dressedTags()));
    }
    $gestures = [];
    foreach ($this->rosterBuilder->gestureLines() as $kind => $line) {
      $gestures[] = MaterialKind::from($kind)->noun() . ': ' . rtrim($line, '.');
    }
    if ($gestures !== []) {
      $lines[] = sprintf('MINT (save_material {kind, label, ...}; %s): %s.', rtrim(MaterialsProse::mintGateSentence(), '.'), implode('; ', $gestures));
    }
    return $lines;
  }

  /**
   * The BLUEPRINTS block: the site favorites in full, then the group index.
   *
   * Block scope only: a page blueprint is a start, never a placement,
   * and the bundle's schema read lists it under starts; scopes never mix
   * on a surface, so no line explains a scope. The prefix is one cached
   * text for every page and user, so one line per blueprint is affordable
   * for a short list and not for the hundred a site is designed for:
   * the lines are the SITE favorites (MaterialRecord::$favorite, the
   * default the Add panel lists for everyone; an editor's own marks never
   * reach a text cached for every user), each with the looks it owns
   * beneath it, as before. The rest ride as an index in the neighbours'
   * shape: one line per declared group that holds an offered block
   * blueprint, in declaration order (id, label, how many, the
   * description's first sentence), then the reserved bucket for the
   * offered ones in no declared group (DialectCatalog::UNGROUPED, a count
   * alone), and the header says how a group is read in full
   * (eb_read_schema's group). Flat by construction: the block's size
   * follows the favorites and the vocabulary, never the library.
   *
   * @return string[]
   *   The lines; [] while the roster offers no block blueprint.
   */
  protected function blueprintLines(DialectCatalog $catalog): array {
    $roster = $catalog->roster();
    $blueprints = $roster->blueprints(MaterialRecord::SCOPE_BLOCK);
    if ($blueprints === []) {
      return [];
    }
    $lines = ['BLUEPRINTS (the site favorites; id "Label" (a page band says top-level only; a saved one names the entity_id page_read and page_edit take as entity_type blueprint, a shipped one is read-only); the schema prints each in full; the looks beneath a blueprint are its own, wearable inside its placements):'];
    $owned = $this->ownedLooks($roster);
    foreach ($blueprints as $record) {
      if (!$record->favorite) {
        continue;
      }
      $details = [];
      if ($roster->isBand($record)) {
        $details[] = 'top-level only';
      }
      if ($record->entityId !== NULL) {
        $details[] = 'entity_id ' . $record->entityId;
      }
      $lines[] = '- ' . $this->materialLine($record, implode('; ', $details));
      foreach ($owned[$record->id] ?? [] as $look) {
        $lines[] = '  - ' . $this->materialLine($look, $this->spellingProse($look));
      }
    }
    $lines[] = sprintf('GROUPS (every offered blueprint, favorites included, by group: id "Label" (how many): what the group is for; %s holds the ones in no group; eb_read_schema with group: id prints a group in full in place of the favorites):', DialectCatalog::UNGROUPED);
    $vocabulary = $catalog->groups();
    $counts = array_fill_keys(array_keys($vocabulary), 0);
    $ungrouped = 0;
    foreach ($blueprints as $record) {
      $declared = array_intersect($record->groups, array_keys($vocabulary));
      if ($declared === []) {
        $ungrouped++;
      }
      foreach ($declared as $id) {
        $counts[$id]++;
      }
    }
    foreach ($counts as $id => $count) {
      if ($count > 0) {
        $lines[] = '- ' . $this->indexLine($id, $vocabulary[$id]['label'], $this->countProse($count), $vocabulary[$id]['description']);
      }
    }
    if ($ungrouped > 0) {
      $lines[] = sprintf('- %s (%s)', DialectCatalog::UNGROUPED, $this->countProse($ungrouped));
    }
    return $lines;
  }

  /**
   * A count of blueprints, as prose.
   */
  protected function countProse(int $count): string {
    return $count === 1 ? '1 blueprint' : $count . ' blueprints';
  }

  /**
   * The skin sentence: the active skin and the conditions its values answer.
   *
   * The skin is the styling layer's contribution (the catalog's label);
   * which conditions its named values resolve under is read off the
   * token values themselves (a condition-keyed value), each named with
   * its wrapper from the catalog's condition vocabulary.
   */
  protected function skinSentence(DialectCatalog $catalog): string {
    $label = $catalog->skin();
    if ($label === NULL) {
      return 'Skin: none.';
    }
    $conditions = [];
    foreach ($catalog->roster()->tokens() as $record) {
      if (is_array($record->value)) {
        foreach (array_keys($record->value) as $condition) {
          if ($condition !== 'default') {
            $conditions[(string) $condition] = TRUE;
          }
        }
      }
    }
    if ($conditions === []) {
      return sprintf('Skin: %s.', $label);
    }
    $wrappers = $catalog->conditions();
    $named = array_map(
      static fn (string $condition): string => isset($wrappers[$condition]) ? sprintf('%s (%s)', $condition, $wrappers[$condition]) : $condition,
      array_keys($conditions),
    );
    return sprintf('Skin: %s; its named values also resolve under %s.', $label, implode(', ', $named));
  }

  /**
   * The roster per element kind: axes, class looks and the birth line.
   *
   * The container (its kind and the container layout), every block (the
   * block kind's scope, worn by each block element), then each declared
   * block type with only what its own scope adds. A kind with nothing to
   * say prints no heading.
   *
   * @return string[]
   *   The prompt lines, one group per element kind.
   */
  protected function elementLines(DialectCatalog $catalog): array {
    $lines = [];
    $container_axes = $catalog->axesFor(TRUE, NULL, NULL);
    $layout = $catalog->containerLayoutId() === '' ? [] : $catalog->birthDefaults(Address::layout($catalog->containerLayoutId()));
    array_push($lines, ...$this->elementGroup($catalog, '<pb-container>', $container_axes, $catalog->classRosterFor(TRUE, NULL, NULL), $layout, $container_axes));
    $block_axes = $catalog->axesFor(FALSE, NULL, NULL);
    $block_classes = $catalog->classRosterFor(FALSE, NULL, NULL);
    array_push($lines, ...$this->elementGroup($catalog, 'Every block element', $block_axes, $block_classes, [], $block_axes));
    foreach (array_keys($catalog->blockTypes()) as $type) {
      $axes = $catalog->axesFor(FALSE, $type, NULL);
      $own_axes = array_diff_key($axes, $block_axes);
      $own_classes = array_diff_key($catalog->classRosterFor(FALSE, $type, NULL), $block_classes);
      array_push($lines, ...$this->elementGroup($catalog, '<' . DialectCatalog::tagForBlockType($type) . '>', $own_axes, $own_classes, $catalog->birthDefaults(Address::blockType($type)), $axes));
    }
    return $lines;
  }

  /**
   * One element kind's group: a heading, its axes, its looks, its birth line.
   *
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param string $heading
   *   The group's heading line.
   * @param array $axes
   *   The axes to print (DialectCatalog::axesFor() entries).
   * @param array $classes
   *   The class looks to print: class => variant id.
   * @param array $birth
   *   The birth defaults for the element's placement scope: axis attribute
   *   => variant id.
   * @param array $all_axes
   *   The element's whole axis view, to spell a birth default by value.
   *
   * @return string[]
   *   The group's lines: heading, axes, looks, birth line.
   */
  protected function elementGroup(DialectCatalog $catalog, string $heading, array $axes, array $classes, array $birth, array $all_axes): array {
    $roster = $catalog->roster();
    $lines = [];
    foreach ($axes as $attr => $axis) {
      $qualifiers = [];
      if (!empty($axis['top_level_only'])) {
        $qualifiers[] = 'top-level only';
      }
      if ($axis['owns'] !== []) {
        $qualifiers[] = sprintf('owns %s: wear the axis, never write them', implode(', ', $axis['owns']));
      }
      $line = '- ' . $attr . ($qualifiers === [] ? '' : ' (' . implode('; ', $qualifiers) . ')') . ':';
      $description = $this->firstSentence((string) $axis['description']);
      if ($description !== '') {
        $line .= ' ' . $description . '.';
      }
      $values = [];
      foreach ($axis['values'] as $value => $id) {
        $record = $roster->get(MaterialKind::Variant, $id);
        $entry = sprintf('%s "%s"', $value, $record?->label ?? $id);
        $sentence = $this->firstSentence($record?->description ?? '');
        $values[] = $sentence === '' ? $entry : $entry . ': ' . $sentence;
      }
      $lines[] = $line . ' ' . implode('; ', $values);
    }
    foreach ($classes as $id) {
      $record = $roster->get(MaterialKind::Variant, $id);
      if ($record !== NULL) {
        $lines[] = '- ' . $this->materialLine($record, $this->spellingProse($record));
      }
    }
    $worn = [];
    foreach ($birth as $attr => $id) {
      $value = array_search($id, $all_axes[$attr]['values'] ?? [], TRUE);
      $worn[] = $value === FALSE ? $id : sprintf('%s="%s"', $attr, $value);
    }
    if ($worn !== []) {
      $lines[] = sprintf('- Born from the palette wearing %s; a document states what it wears itself.', implode(' ', $worn));
    }
    if ($lines === []) {
      return [];
    }
    return [$heading . ':', ...$lines];
  }

  /**
   * The variants no element roster places, each with its scope.
   *
   * @return string[]
   *   One line per look, naming its scope.
   */
  protected function otherLookLines(DialectCatalog $catalog): array {
    $elsewhere = $this->looksElsewhere($catalog);
    if ($elsewhere === []) {
      return [];
    }
    $lines = ['OTHER LOOKS (worn where their scope fits):'];
    foreach ($elsewhere as $record) {
      $detail = $this->spellingProse($record);
      if ($record->scope !== NULL) {
        $detail .= ($detail === '' ? '' : '; ') . 'scope ' . $record->scope;
      }
      $lines[] = '- ' . $this->materialLine($record, $detail);
    }
    return $lines;
  }

  /**
   * The variants outside every element roster, in roster order.
   *
   * A look a blueprint owns is never "elsewhere": it is wearable only
   * inside that blueprint's placements and lists under the blueprint (the
   * BLUEPRINTS lines, the schema's blueprint entries), so it is left out
   * here exactly as the element rosters leave it out.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   The looks no element roster places.
   */
  protected function looksElsewhere(DialectCatalog $catalog): array {
    $placed = [];
    $elements = [[TRUE, NULL], [FALSE, NULL]];
    foreach (array_keys($catalog->blockTypes()) as $type) {
      $elements[] = [FALSE, $type];
    }
    foreach ($elements as [$is_container, $type]) {
      array_push($placed, ...array_values($catalog->classRosterFor($is_container, $type, NULL)));
      foreach ($catalog->axesFor($is_container, $type, NULL) as $axis) {
        array_push($placed, ...array_values($axis['values']));
      }
    }
    $placed = array_flip($placed);
    return array_values(array_filter($catalog->roster()->variants(), static fn (MaterialRecord $record): bool => $record->ownedBy === NULL && !isset($placed[$record->id])));
  }

  /**
   * The looks each blueprint owns, keyed by blueprint id, in roster order.
   *
   * The same grouping the schema's blueprint entries carry ("looks"): a
   * look whose owner is not on the roster lists nowhere, since no
   * placement on this site may wear it.
   *
   * @return array<string,\Drupal\daedalus_blueprint\Materials\MaterialRecord[]>
   *   The owned looks, keyed by the owning blueprint's id.
   */
  protected function ownedLooks(MaterialsRoster $roster): array {
    $owned = [];
    foreach ($roster->variants() as $record) {
      if ($record->ownedBy !== NULL) {
        $owned[$record->ownedBy][] = $record;
      }
    }
    return $owned;
  }

  /**
   * The token roster with current values, one line per scale.
   *
   * Projected from the roster's token records: the scale is the record's
   * group, the custom property the var() spelling's argument (what the
   * value grammar resolves), the value the record's. A token's
   * description (why it exists and when to reach for it, the skin
   * author's reasoning) rides as its first sentence in parentheses after
   * the property, the swatch line's rule; the schema keeps the whole
   * text.
   */
  protected function tokenLines(MaterialsRoster $roster): array {
    $by_scale = [];
    foreach ($roster->tokens() as $record) {
      $sentence = $this->firstSentence($record->description);
      $by_scale[(string) $record->group][] = sprintf('%s%s: %s', MaterialsSchema::customProperty($record), $sentence === '' ? '' : ' (' . $sentence . ')', MaterialsRoster::valueProse($record->value, self::VALUE_CLIP));
    }
    $lines = [];
    foreach ($by_scale as $scale => $entries) {
      $lines[] = '- ' . $scale . ': ' . implode('; ', $entries);
    }
    return $lines;
  }

  /**
   * The swatches as named values with labels, one line per swatch kind.
   *
   * A swatch's label is what a person asks for ("the lime green"), so it
   * rides beside the property and the value; its description's first
   * sentence (when to reach for it) rides in parentheses after the label.
   */
  protected function swatchLines(MaterialsRoster $roster): array {
    $by_kind = [];
    foreach ($roster->swatches() as $record) {
      $sentence = $this->firstSentence($record->description);
      $by_kind[(string) $record->group][] = sprintf('%s "%s"%s: %s', MaterialsSchema::customProperty($record), $record->label, $sentence === '' ? '' : ' (' . $sentence . ')', MaterialsRoster::valueProse($record->value, self::VALUE_CLIP));
    }
    $lines = [];
    foreach ($by_kind as $kind => $entries) {
      $lines[] = '- swatch ' . $kind . ': ' . implode('; ', $entries);
    }
    return $lines;
  }

  /**
   * One material's line: id "Label" (detail): the description's first sentence.
   */
  protected function materialLine(MaterialRecord $record, string $detail): string {
    return $this->indexLine($record->id, $record->label, $detail, $record->description);
  }

  /**
   * One indexed thing's line: id "Label" (detail): the description's first sentence.
   *
   * The shape every material line and every group line share.
   */
  protected function indexLine(string $id, string $label, string $detail, string $description): string {
    $line = sprintf('%s "%s"', $id, $label);
    if ($detail !== '') {
      $line .= ' (' . $detail . ')';
    }
    $description = $this->firstSentence($description);
    return $description === '' ? $line : $line . ': ' . $description;
  }

  /**
   * A record's spellings as written (class, axis attribute, animation name).
   */
  protected function spellingProse(MaterialRecord $record): string {
    $spellings = [];
    foreach ($record->spellings as $slot => $spelling) {
      $spellings[] = MaterialsRoster::spellingProse($slot, $spelling);
    }
    return implode(', ', $spellings);
  }

  /**
   * Clips a description to its first sentence, terminal punctuation off.
   *
   * The prefix budget rule: the prefix pays for what a material is and
   * when to reach for it (the first sentence, which the system's authors
   * write as "Reach for it when ..."), the schema keeps the whole text.
   */
  protected function firstSentence(string $description): string {
    $description = trim($description);
    if (preg_match('/^(.+?)[.!?](?:\s|$)/su', $description, $m)) {
      return $m[1];
    }
    return $description;
  }

}
