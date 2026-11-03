<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\BundleListBuilder;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\Dialect\DialectWalk;
use Drupal\daedalus_blueprint\Dialect\DomOperationHandler;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsProse;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;
use Drupal\daedalus_blueprint\Materials\MaterialsWeights;
use Drupal\daedalus_blueprint\Materials\MaterialsWeightSourceInterface;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\OperationHandler\OperationHandlerRegistry;

/**
 * Renders the dialect catalog as the cached-prompt page-language section.
 *
 * Facts in the cached prefix: the page bundles (the PAGES line, from the
 * bundle list builder's page projection: the one always-on home of which
 * bundles a page is made of, so a cold conversation never guesses
 * node/page), the component reference (each element's
 * line ending with what it renders as, from the catalog's render cards:
 * rendersProse()), the materials roster with current values, and the op set live HERE, in
 * the stable system prompt the provider caches, so every turn's writes
 * are grounded without a read ritual. Behavioral doctrine (design taste,
 * styling recipes) stays on-demand in the skills. Everything below is
 * DERIVED from the generated catalog, the bundle list and the materials
 * roster, never
 * hand-listed, so the prompt is a projection of live config exactly like
 * the schema surfaces.
 *
 * The design system brief is the styling half of the section: the ONE
 * thing the model is introduced to. It opens with the active design
 * system by name (its identity paragraph, the entity's description), its
 * law (the mode, the polarity sentence and the skin) with the bindings
 * that bind no token scale on one line beneath it, the membership,
 * preference, standing-procedure and stack sentences, then the roster
 * projected in the system's own structure: per element kind its axes
 * (each heading carrying the axis's whole description and the
 * properties it owns, one line per value beneath it), its class looks (a
 * root-only look says top-level only), its birth line and its deviations
 * (the places of that element whose value spaces narrow below the
 * bindings, in dialect words: the element's own, a part by its selector,
 * a plugin or undeclared block by its tag), then every shared block
 * blueprint as a menu, each with the looks it owns beneath it, and the
 * group index over them (the schema prints each as a page-language
 * fragment and one group alone on demand; the block line
 * above the brief states the placement contract, a blueprint being a
 * component placed with its stamp or the section built new; a page
 * blueprint is a start, not a placement, and reaches the model on demand
 * through a bundle's schema read, never through this cached prefix,
 * which has no bundle dimension), the named values as a nested list (one
 * heading per token scale carrying the scale's bindings, one line per
 * token beneath it),
 * the animations, the fonts, the tags the skin dresses, and one mint
 * line (the providers' gesture keys, then their postures: a lane the
 * site closed to the AI is said here, since a mint can be asked on any
 * turn). Every description is whole, as its author wrote it: the brief is
 * the one always-on layer and a small edit is made from it alone, and
 * the systems are written "what, then when", so the rule a description
 * carries after its first sentence ("never two Tint sections back to back")
 * rides here or reaches the model nowhere. The two exceptions are the
 * blueprint menu lines and the group index, clipped to a first sentence
 * (menuSentence()): a blueprint is placed only with its fragment, which
 * the schema prints with the whole description, and a group's
 * description is a label's aside, not a material's law. With no active
 * system the brief says so and carries the global materials under the
 * guarded posture.
 *
 * The whole section reads the catalog's offered view (DialectCatalog::
 * offered(), the roster's MaterialsRoster::offered()): a material its
 * author opted out of the AI is on no element line, in no brief block
 * and behind no mint gesture here, exactly as it is absent from the
 * schema, while it stays legal to wear. One seam, in render(); every line
 * below projects the catalog it is handed.
 *
 * The section is also the AI context budget's second weight source
 * (MaterialsWeightSourceInterface, beside MaterialsSchema): lines() builds
 * every line attributed (PromptLines), render() is those lines joined
 * and weights() the same lines weighed, so the gauge is a by-product of
 * the one render the model is served and the rendered text is byte for
 * byte what it was before it was weighed (the provider's prompt cache
 * must not shift). The attribution has three levels. A line is a
 * RECORD's when that record built it: a material's own line (id, label,
 * value, description), an axis value line (the variant that is the
 * value), a blueprint's menu line and the lines of the looks it owns
 * (they ride under the blueprint, where the schema files them, so they
 * are the blueprint's). A line is a KIND's SHARED when it stands over
 * several records of that kind and no one of them owns it: an axis
 * heading over the variants that are its values, a scale heading, and a
 * block heading that opens one kind's lines alone (NAMED VALUES, OTHER
 * LOOKS, BLUEPRINTS, ANIMATIONS, FONTS). Everything else is the
 * SYSTEM's, what no material toggle shrinks: the grammar (the opening,
 * PAGES, ELEMENTS, STYLES, the conditions, the values and class rules,
 * the rosters, OPS), the brief's head (its heading, identity, law,
 * bindings, doctrine), an element group's heading (over axes, looks,
 * birth and deviations alike), the birth line, the deviations, the
 * GROUPS index (the schema counts its index the same way), the
 * dressed-tags line and MINT.
 */
class DialectCatalogPrompt implements MaterialsWeightSourceInterface {

  /**
   * The longest a named value's rendered part may be on the prefix.
   *
   * Font stacks are the values that overrun; 32 characters keeps the
   * first family or two, which is what the model needs to pick one.
   */
  protected const VALUE_CLIP = 32;

  /**
   * The field element's kind, the one stylable kind Address names no constant for.
   *
   * The catalog's stylable rosters are keyed by element kind (container,
   * block, field); a deviation at kind:field narrows <d-field>.
   */
  protected const KIND_FIELD = 'field';

  public function __construct(
    protected DialectCatalogBuilder $catalogBuilder,
    protected OperationHandlerRegistry $operationHandlerRegistry,
    protected MaterialsRosterBuilder $rosterBuilder,
    protected ActiveDesignSystem $activeDesignSystem,
    protected BundleListBuilder $bundleListBuilder,
  ) {}

  /**
   * Renders the "## Page language" system prompt section.
   */
  public function render(): string {
    return $this->lines()->text();
  }

  /**
   * {@inheritdoc}
   *
   * The section's own lines weighed (PromptLines::weights()): the one
   * build render() serves, attributed as the class comment says.
   */
  public function weights(): MaterialsWeights {
    return $this->lines()->weights();
  }

  /**
   * {@inheritdoc}
   *
   * The roster's (the materials every attributed line projects), plus
   * the bundle and field definition tags the grammar lines derive from
   * (the PAGES bundles, the ELEMENTS block types and their fields).
   */
  public function cacheMetadata(): CacheableMetadata {
    return $this->rosterBuilder->cacheMetadata()->addCacheTags(['entity_bundles', 'entity_field_info']);
  }

  /**
   * The section's lines, each attributed to a kind or the system.
   *
   * The one composition render() and weights() share. The grammar is the
   * system's; the brief (briefLines()) carries the attributed lines.
   */
  public function lines(): PromptLines {
    $catalog = $this->catalogBuilder->build()->offered();
    $lines = new PromptLines();
    $lines->system('## Page language');
    $lines->system('');
    $lines->system('Pages are HTML in a small dialect: what you read (the "Current page print" tag) is exactly what you write (page_edit fragments, page_create documents). Element ids are minted uuids; on elements YOU add, choose any id (id="hero") and the result echoes id -> uuid (prints carry your name as data-alias, keep using it). Never author id, data-*, or src.');
    $lines->system('');
    $lines->system(...$this->pagesLines($this->bundleListBuilder->getPageBundles()));
    $lines->system('');
    $lines->system('ELEMENTS (attr(allowed values); (link) takes a path or URL, (ref) an id or exact label of existing content; file takes the name of a file this conversation holds, generate a prompt for a new image):');
    $lines->system($this->containerLine($catalog));
    $lines->system($this->slotLine());
    foreach ($catalog->blockTypes() as $type => $spec) {
      $lines->system($this->blockTypeLine($catalog, $type, $spec));
    }
    $lines->system($this->blockLine($catalog));
    $lines->system('');
    $lines->system($this->stylesLine($catalog));
    $lines->system($this->conditionsLine($catalog));
    $lines->system('Values are named values (var(--name), MATERIALS below) or single CSS values; clamp()/calc() and !important are not supported. Images in CSS: url(\'media:<uuid>\') references existing media on media-capable properties (background-image), and url(\'generate:<prompt>\') as a layer or the whole value mints a new image (linear-gradient(...), url(\'generate:<prompt>\') darkens a generated image in one write; it prints back as a media token once generated; never wait for it); url(\'file:<name>\') as a layer or the whole value places a file this conversation holds (it becomes media at once). Always single-quote url() arguments: a double quote ends a style="" attribute early.');
    $lines->system($this->classLine($catalog));
    $lines->system(...$this->stylableLines($catalog));
    $lines->system('');
    $lines->append($this->briefLines($catalog));
    $lines->system('');
    $lines->system('OPS (page_edit "ops": a JSON array applied as ONE atomic batch; each op {"op": name, ...}):');
    $lines->system('- insert {html, at: {ref, position: beforebegin|afterbegin|beforeend|afterend}} — html is a dialect fragment; ref is an element id or "root" (the page).');
    $lines->system('- set {ref, attrs: {...}, detach: [...]} — the canonical edit: patch attributes, class (the element\'s whole variant wear), body (the element\'s text or rich body), or style (style replaces the whole style="" value); null clears one. detach lists worn variants (a class, or an axis attribute name) to materialize into the element\'s own styles and unlink. ref "root" patches the page\'s own fields (title, ...: what page_create\'s fields takes).');
    $lines->system('- style {css, remove: [{selector, properties: [names]}]} — css rules merge into what is there; deleting a property needs the remove list (unset is a value, not a delete).');
    $lines->system('- replace {ref, html} — whole-element rewrite; styles the new html does not restate are gone, so prefer set for edits.');
    $lines->system('- move {ref, at}; remove {ref}.');
    $lines->system('- file {name, type, content}: declares bytes you author (an SVG, a stylesheet, CSV, text; never binary) as a file this conversation holds under name, type its MIME (image/svg+xml, text/css, text/csv, text/plain); put it ahead of the ops that place it with file="name" or url(\'file:name\'). In a page_create document the same declaration is a top-level <d-file name="..." type="...">the bytes</d-file> beside <style>.');
    $page_ops = $this->operationHandlerRegistry->getPageLanguageOperations();
    $guidance = $this->operationHandlerRegistry->getGuidance();
    foreach (array_diff($page_ops, DomOperationHandler::OPS) as $op) {
      $lines->system(sprintf('- %s {...} — %s.', $op, rtrim($guidance[$op] ?? 'a page gesture', '.')));
    }
    $lines->system('Failed batches roll back whole; errors are corrective, fix and resend. These ' . count($page_ops) . ' ops are the entire edit grammar: pages are never edited through eb_edit.');
    return $lines;
  }

  /**
   * The PAGES block: the bundles a page is made of, by name.
   *
   * The one always-on home of the bundle fact. A cold conversation
   * standing on no page names a bundle to page_create, and a model that
   * has read no bundle list guesses node/page, which a site need not
   * have; this line is read before any tool is picked, so the guess
   * never happens. Page bundles only (the full catalog is noise on the
   * cached prefix and stays eb_list_bundles'), each in the one spelling
   * every page surface uses (BundleListBuilder::pageBundleName(), the
   * spelling page_create's correctives repeat) with its whole description
   * collapsed to one line (a bundle's description is the site builder's
   * help text, written as markup), and a locked bundle saying it renders
   * its template, the mark eb_list_bundles gives it, since page_create
   * takes no layout for it. With no page bundle the line says so: the
   * same fact, stated the other way, is what keeps the guess from
   * happening on a site with no page type.
   *
   * @param array $pages
   *   The page bundles (BundleListBuilder::getPageBundles()).
   *
   * @return string[]
   *   The header and one line per bundle; one line when there is none.
   */
  protected function pagesLines(array $pages): array {
    if ($pages === []) {
      return ['PAGES: none on this site. No bundle\'s entities render as a page document, so page_create has nothing to make; eb_list_bundles lists what exists.'];
    }
    $lines = ['PAGES (the bundles page_create makes a page of; entity_type/bundle "Label": what its pages are for; every other bundle is eb_list_bundles\'):'];
    foreach ($pages as $page) {
      $line = '- ' . BundleListBuilder::pageBundleName($page);
      if ($page['renders_template']) {
        $line .= ' (renders its template)';
      }
      $description = trim(preg_replace('/\s+/', ' ', strip_tags($page['description'])));
      $lines[] = $description === '' ? $line : $line . ': ' . $description;
    }
    return $lines;
  }

  /**
   * The block element line, with the blueprint sentence where blueprints exist.
   *
   * The blueprint sentence is the ONE model-facing statement of the
   * blueprint contract: the two ways to copy one (<d-blueprint
   * name="id"/> for a copy as is, or the blueprint's printed fragment
   * written with the author's own content and blueprint="id" on the root,
   * the label being what makes an element a placement), the one way to
   * link one (<d-linked blueprint="id"/>, a block showing the saved
   * blueprint's live document), where a placement
   * lands (a section at the top level only, anything else wherever a
   * container or block sits), that the reference tag itself takes no
   * style or class, that the owned looks are wearable inside a placement
   * and address its children by position, that a saved blueprint is a
   * page of its own (its body read and edited on demand through page_read
   * and page_edit as entity_type blueprint, never guessed from the
   * placement), and that a blueprint is a component: placed with its
   * stamp when the section's job is its job, or the section built new,
   * never retyped without the stamp (the two outcomes; "build beside it"
   * is gone, since a retyped blueprint without its label is a hand-rolled
   * section the site cannot tell apart). The capture and update keys ride
   * the MINT line; the blueprints and their owned looks ride the
   * BLUEPRINTS lines, and their printed fragments the schema.
   */
  protected function blockLine(DialectCatalog $catalog): string {
    $block_axes = $this->axesProse($catalog->axesFor(FALSE, NULL, NULL));
    $line = sprintf('- <d-block field="name"%s/> renders one of the page\'s own fields (name is a field machine name of the page; the page supplies the block, so the same element fits any page that has the field). <d-block plugin="plugin_id"%s/> renders an existing plugin block. An undeclared block type is <d-block type="x"%s><d-field name="field_y">value</d-field></d-block>.', $block_axes, $block_axes, $block_axes);
    if ($catalog->roster()->blueprints(MaterialRecord::SCOPE_BLOCK) !== []) {
      $line .= ' <d-blueprint name="blueprint_id"/> places a saved blueprint (the design system\'s BLUEPRINTS below) as is, where the element sits: a section (top-level only) at the document\'s top level, never inside a container; any other blueprint wherever a container or block may sit; the reference tag itself takes no style or class. To place one with your own content, write its printed fragment (the schema prints each in full, one group alone with its group parameter) with your content and blueprint="blueprint_id" on the root: that label is what makes the element a placement, so keep it when you rewrite one. Inside a placement the blueprint\'s owned looks (listed under it) are wearable; they address the placement\'s children by position, so keep the shape where you keep the look. A blueprint saved on this site is a page of its own: page_read (entity_type blueprint, its entity_id) prints its body with ids and page_edit changes it for every later placement; a shipped one is read-only. <d-linked blueprint="blueprint_id"/> shows a saved blueprint by reference instead of copying it: one block, inside a container, whose content is the blueprint\'s live document (a field block in it shows this page\'s own field) and follows every later edit of the blueprint; it takes no children and no content of its own (edit the blueprint), only its own box styles; a shipped file cannot be linked. A blueprint is a component: place it with the stamp when the section\'s job is its job, or build the section new; never retype one without its stamp.';
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
      $line .= ' A variant outside the per-element rosters above applies where its scope fits (the brief\'s OTHER LOOKS, each with its scope).';
    }
    return $line;
  }

  /**
   * The container's component reference line, birth rule and renders included.
   */
  protected function containerLine(DialectCatalog $catalog): string {
    $axes = $catalog->axesFor(TRUE, NULL, NULL);
    $classes = array_keys($catalog->classRosterFor(TRUE, NULL, NULL));
    $line = sprintf(
      '- <d-container%s> — the one grouping element, nestable; children stack unless layout says otherwise, and every direct child of a columns container is a column.%s%s',
      $this->axesProse($axes),
      $classes === [] ? '' : ' class(' . implode('|', $classes) . ')',
      $this->totalStatementProse($axes),
    );
    $renders = $this->rendersProse($catalog->cardFor(RelativeSelector::TYPE_CONTAINER));
    return $renders === NULL ? $line : $line . ' It ' . $renders;
  }

  /**
   * An element's renders, from its card: the pairs, or the shells it renders through.
   *
   * The one print of a card for the prompt (the structure lives with the
   * catalog, the derivation with daedalus_ui), so a new block type gets
   * its renders for free and nothing is ever authored: each pair as the
   * dialect write, an arrow and the HTML it renders as, plainest first,
   * semicolon-separated; a card with shells (the fallback) says which
   * shells it renders through instead.
   *
   * @param array|null $card
   *   The element's card, NULL when no layer carded it.
   *
   * @return string|null
   *   "renders: <in> → <out>; …" or "renders through …", with its period;
   *   NULL for no card, so a line says nothing it cannot stand behind.
   */
  protected function rendersProse(?array $card): ?string {
    if ($card === NULL) {
      return NULL;
    }
    if ($card['renders'] === []) {
      return 'renders through ' . DialectCatalog::rendersThrough($card['shells']) . '.';
    }
    $pairs = [];
    foreach ($card['renders'] as $input => $html) {
      $pairs[] = $input . ' → ' . $html;
    }
    return 'renders: ' . implode('; ', $pairs);
  }

  /**
   * The slot section line: the one grammar of a page inside a locked template.
   *
   * The ONE model-facing statement of the slot contract (the schema's
   * d-slot entry teaches the element; this line teaches the page): what
   * a locked page's print is (the template read-only, then the page's own
   * slot sections writable), where the door is (the template's slot block,
   * data-can="insert"), how a slot is filled (insert inside the door; the
   * slot section is born with the first fill) and where <d-slot> is
   * authored directly (the top level of a document the page owns whole:
   * a page blueprint of slot fills, the start of a locked bundle's page).
   * Static text: the dialect has the tag whatever the site's rosters hold.
   */
  protected function slotLine(): string {
    return '- <d-slot name="slot_id"> — a top-level container filling a slot of the document around this page (a locked bundle\'s template); its children are the page\'s own content, shown where the slot sits. A locked page prints its template read-only (data-can="") followed by its own <d-slot> sections, writable: each slot block in the template (<d-block plugin="daedalus_slot:slot_id" data-can="insert"/>) is the door, so insert with at: {ref: that block\'s id, position: beforeend} lands in the page\'s <d-slot name="slot_id"> (born with the first fill); an existing <d-slot> is edited like any container, and the page\'s root takes no insert. Author <d-slot> yourself only at the top level of a document the page owns whole (a page blueprint of slot fills, the start of a locked bundle\'s page).';
  }

  /**
   * The birth rule on the container line: an omitted axis is bare.
   *
   * The dialect is a total statement (DomOperationHandler runs every
   * document and fragment as a dressed batch, so the styling layer stamps
   * no birth default under it): a container wears exactly the axes it
   * states. A section therefore states its section axes (the top-level-only
   * ones the catalog lists) itself; width="site" leads as the example
   * when the site offers that spelling, since it is the content column
   * every section shares unless it is deliberately full-bleed. The house
   * VALUES of the other section axes are doctrine, carried by the site's
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
    $section_axes = array_keys(array_filter($axes, static fn (array $axis): bool => !empty($axis['top_level_only'])));
    if ($section_axes === []) {
      return $prose . '.';
    }
    $site = in_array('width', $section_axes, TRUE) && isset($axes['width']['values']['site']);
    $others = $site ? array_values(array_diff($section_axes, ['width'])) : $section_axes;
    if (!$site) {
      return $prose . sprintf(', so a section states each of its top-level-only axes (%s) itself.', implode(', ', $others));
    }
    $prose .= ', so a section states width="site" (the site\'s content column; omit it only for a deliberately full-bleed section)';
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
   * One block type's component reference line: axes, fields, body, classes, renders.
   *
   * The line ends with the type's renders (rendersProse() over its card:
   * each dialect write paired with what it renders as), after a semicolon
   * when a class roster precedes it.
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
    $renders = $this->rendersProse($catalog->cardFor($tag));
    if ($renders !== NULL) {
      $line .= ($classes === [] ? ' ' : '; ') . $renders;
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
   * before the box), the qualifier kinds a compound carries (a class,
   * an attribute, a position pseudo-class, each qualifying its own
   * compound; grammar is CSS's, admission the site's by enforcement
   * mode), where a state stands (a published state on the last
   * compound is the rule's condition, on any earlier compound a
   * qualifier of it, an unpublished pseudo-class selector text) and the
   * list fold (one `:is()` rule, its two refusals), leading into the
   * conditions line.
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
    // The declared parts by owner, each with the roster's label (the
    // condition a part exists under shows in its owner's renders), and the
    // chain example from the example type's own part, else the first
    // block type owning one (a part is reached after its owner's type).
    $by_owner = [];
    foreach ($catalog->selectors() as $owner => $roster) {
      if (($roster['parts'] ?? []) !== []) {
        $parts = [];
        foreach ($roster['parts'] as $selector => $label) {
          $parts[] = sprintf('%s (%s)', $selector, $label);
        }
        $by_owner[] = $owner . ' ' . implode(', ', $parts);
      }
    }
    $chain = sprintf('#section > %s > *', $type);
    foreach ([$type, ...array_keys($catalog->selectors())] as $owner) {
      $parts = $catalog->partsOf($owner);
      if ($owner !== RelativeSelector::TYPE_CONTAINER && $parts !== []) {
        $chain = sprintf('#section > %s %s', $owner, array_key_first($parts));
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
    $image = DialectCatalog::tagForBlockType('image');
    $other = in_array($image, $types, TRUE) ? $image : array_values(array_diff($types, [$type]))[0];
    $inside = $tags === [] ? $other : (in_array('a', $tags, TRUE) ? 'a' : $tag);
    $an = static fn (string $word): string => (preg_match('/^[aeiou]/', $word) ? 'an ' : 'a ') . $word;
    $line = 'STYLES: style="" is the element\'s own base look, plain CSS declarations. Every other rule is real CSS: a page_create document carries them in one trailing <style> block, a page_edit batch in the style op. A selector is #id plus an optional relative selector into #id\'s own subtree: ' . implode(', ', $shapes) . sprintf(', joined by a space (descendant), > (direct child), + (the next sibling) or ~ (any later sibling), as in %s and #section > %s + %s (a %s right after a %s among its children).', $chain, $sibling, $sibling, $sibling, $sibling);
    $line .= sprintf(' A compound narrows by :has() (a relative selector from it: #section > %s:has(%s) the %s children that contain %s, #section > %s:has(+ %s) the one right before %s) and :not() (one compound it is not: #section > *:not(%s) every child but the %s ones).', $type, $inside, $type, $an($inside), $type, $other, $an($other), $other, $other);
    $line .= sprintf(' Style a container\'s children with ONE rule on the container, never the same look copied onto each child; the nearest element\'s own rule wins (#child beats #section > %s on that child).', $type);
    $line .= sprintf(' A rule also wears a variant on every element it matches, as a declaration in its plain block, the attribute\'s twin: class: {class} (style classes, space-separated) or {axis}: {value} (an axis attribute of the matched element), as in #section > %s { class: {class}; color: red; }; never under a state or wrapper, never a composition or a top-level-only axis; take it off with remove naming class or the axis.', $type);
    if ($catalog->instances() !== []) {
      $prefix = 's-';
      $pattern = (string) array_key_first($catalog->instances());
      if (preg_match('/^\.([A-Za-z0-9_-]*)\*$/', $pattern, $m)) {
        $prefix = $m[1];
      }
      $line .= sprintf(' Rich text styles by element: the %s* classes in a body are element identities; #id .%sx styles that ONE element, #id .%sx > * one list\'s items; author your own %s* classes on the elements you mean to style and KEEP the %s* classes on elements you keep when rewriting a body (a dropped class orphans its styles); other tags are the text format\'s, not styling targets.', $prefix, $prefix, $prefix, $prefix, $prefix);
    }
    $line .= sprintf(' A selector may end in ::before or ::after, the generated box of what it addresses (#id::after the element\'s own box, #section > %s::before each %s child\'s), a rule of its own like the others: a box is born with content: "" (its existence switch; content takes "", none or one media reference url(\'media:<uuid>\') (the image at its own size; background-image on the box sizes and layers) and nothing else, since text belongs in the element\'s content), and states go before it (#id:hover::after); any other pseudo-element the browser knows ends a selector the same way (#id li::marker, #id input::placeholder, #id::selection, #id p::first-letter, #id p::first-line), stylable with the properties its specification allows and nothing else.', $type, $type);
    // The fold's members read back in the grammar's canonical order
    // (sorted by printed form), whatever order the list wrote them in.
    $members = [$type, $other];
    sort($members, SORT_STRING);
    return $line . sprintf(' A compound also narrows by a class (#section.open > %s the children while the element wears a class, #section > %s.active a child wearing one), an attribute (#section [aria-expanded="true"] > *, #section > %s[aria-current="page"]; the operators =, ~=, |=, ^=, $=, *=) or a position pseudo-class (:first-child, :not(:last-child), :nth-child(2n+1)), each qualifying the compound it stands on, like :has() and :not() (#section > *:not(:last-child), #section:first-child > %s); a published state on the LAST compound is the rule\'s condition (#section > %s:hover) and a state on any compound before it qualifies that compound (#section:hover > %s the children while the element is hovered, #section > %s:hover %s what a hovered %s contains); a pseudo-class the site does not publish stays selector text (:visited, :checked on their compound). A selector list is ONE rule, folded into :is() where its members differ (#section > %s, #section > %s { } is #section > *:is(%s, %s)); :is() is any of several compounds at one position (#section:is(:hover, :focus-within) > %s the children while the element is hovered or holds focus; :where() reads as :is()), and a list whose members share no shape, or whose fold would put two states on the last compound, is written as separate rules. The published states and wrappers:', $type, $type, $type, $type, $type, $type, $type, $inside, $type, $type, $other, $members[0], $members[1], $type);
  }

  /**
   * The published conditions by family, each named beside its wrapper.
   *
   * States spell as pseudo-classes; the width bands (container-query
   * wrappers) are named with their wrappers and taught base-out, the
   * default (no wrapper) being the wide look each band adapts downward,
   * with one example that steps a headline down on a phone: that is the
   * responsive grammar the value grammar's clamp() refusal points at.
   * Preferences (@media) close the roster. Whether a wrapper of the
   * model's own may follow (a literal feature query, supports test or
   * pseudo-class state beside the references) is the site's posture,
   * stated by the styling layer's condition sentence
   * (VocabularyProseInterface::conditionSentence(): strict closes the
   * set, open and guarded admit what the browser knows). Grows by data: a
   * family absent from the catalog prints nothing.
   */
  protected function conditionsLine(DialectCatalog $catalog): string {
    $named = static fn (array $conditions): string => implode(', ', array_map(
      static fn (string $name, string $wrapper): string => $name . ' ' . $wrapper,
      array_keys($conditions),
      $conditions,
    ));
    $parts = [];
    $wrappers = $catalog->wrappers();
    $states = array_map(static fn (string $name): string => $wrappers[$name], $catalog->stateConditions());
    if ($states !== []) {
      $parts[] = 'states ' . implode(', ', $states);
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
    $prose = $catalog->vocabularyProse();
    if ($prose !== NULL) {
      $line .= ' ' . $prose->conditionSentence();
    }
    return $line;
  }

  /**
   * The design system brief: the section the model is introduced through.
   *
   * Attributed as it is built: the head (heading, identity, law, the
   * bindings line, the doctrine) is the system's; the element groups,
   * the other looks, the blueprints, the named values, the animations
   * and the fonts carry their kinds; the dressed-tags line and MINT are
   * the system's.
   *
   * @return \Drupal\daedalus_blueprint_ai\PromptLines
   *   The lines, heading first.
   */
  protected function briefLines(DialectCatalog $catalog): PromptLines {
    $system = $this->activeDesignSystem->load();
    $roster = $catalog->roster();
    $prose = $catalog->vocabularyProse();
    $lines = new PromptLines();
    $lines->system('## Design system: ' . ($system === NULL ? 'none active' : $system->label()));
    $identity = $system === NULL ? '' : trim($system->getDescription());
    if ($identity !== '') {
      $lines->system($identity);
    }
    $law = sprintf('Law: %s.', $system?->getEnforcement() ?? DesignSystemInterface::ENFORCEMENT_GUARDED);
    if ($prose !== NULL) {
      $law .= ' ' . $prose->polaritySentence();
    }
    $lines->system($law . ' ' . $this->skinSentence($catalog));
    // The bindings by scale: a scale heading under NAMED VALUES carries
    // its own, and the rest (ranges, enumerations, a scale no offered
    // token names) are one line under the law.
    $spaces = $catalog->bindings();
    $binding_lines = $prose === NULL ? [] : $prose->bindingLines($spaces);
    $scales = [];
    foreach ($roster->tokens() as $record) {
      $scales[(string) $record->group] = TRUE;
    }
    $by_scale = [];
    $free = [];
    foreach (array_keys($binding_lines) as $property) {
      $scale = $spaces[$property]['scale'] ?? NULL;
      if (is_string($scale) && isset($scales[$scale])) {
        $by_scale[$scale][] = $property;
      }
      else {
        $free[] = $property;
      }
    }
    if ($free !== []) {
      $lines->system('Bindings with no scale (a scale\'s own ride its heading under NAMED VALUES): ' . $this->bindingProse($free, $binding_lines, $spaces) . '.');
    }
    $doctrine = MaterialsProse::membershipSentence($system?->label()) . ' ' . MaterialsProse::preferenceSentence() . ' ' . MaterialsProse::standingProcedureSentence();
    if ($prose !== NULL) {
      $doctrine .= ' ' . $prose->stackSentence();
    }
    $lines->system($doctrine);
    $lines->append($this->elementLines($catalog));
    $lines->append($this->otherLookLines($catalog));
    $lines->append($this->blueprintLines($catalog));
    $tokens = $this->tokenLines($roster, $by_scale, $binding_lines, $spaces);
    if (!$tokens->isEmpty()) {
      // The heading opens the token kind's lines alone, so it is the
      // tokens' shared like each scale heading beneath it; each value
      // line is its record's.
      $lines->of(MaterialKind::Token, NULL, 'NAMED VALUES (var(--name)):');
      $lines->append($tokens);
    }
    if ($roster->animations() !== []) {
      $lines->of(MaterialKind::Animation, NULL, 'ANIMATIONS (id "Label" (animation-name: spelling)):');
      foreach ($roster->animations() as $record) {
        $lines->of(MaterialKind::Animation, $record->id, '- ' . $this->materialLine($record, $this->spellingProse($record)));
      }
    }
    if ($roster->fonts() !== []) {
      $lines->of(MaterialKind::Font, NULL, 'FONTS (id "Label" (font-family: spelling); self-hosted faces, use the family in a font-family stack):');
      foreach ($roster->fonts() as $record) {
        $lines->of(MaterialKind::Font, $record->id, '- ' . $this->materialLine($record, $this->spellingProse($record)));
      }
    }
    if ($catalog->dressedTags() !== []) {
      $lines->system(sprintf('The skin dresses %s: pick the tag, the skin sizes it.', implode(', ', $catalog->dressedTags())));
    }
    $gestures = [];
    foreach ($this->rosterBuilder->gestureLines() as $kind => $line) {
      $gestures[] = MaterialKind::from($kind)->noun() . ': ' . rtrim($line, '.');
    }
    if ($gestures !== []) {
      // The providers' postures ride the same line: a lane a site closed
      // to the AI (daedalus_stylish's ai_minting) is said here, on the
      // always-on layer, since a mint can be asked on any turn; the
      // sentence is the provider's, stated once for both doors.
      $lines->system(rtrim(sprintf('MINT (save_material {kind, label, ...}; %s): %s. %s', rtrim(MaterialsProse::mintGateSentence(), '.'), implode('; ', $gestures), implode(' ', $this->rosterBuilder->mintPostures()))));
    }
    return $lines;
  }

  /**
   * The BLUEPRINTS block: every shared blueprint as a menu, then the index.
   *
   * Block scope only: a page blueprint is a start, never a placement,
   * and the bundle's schema read lists it under starts; scopes never mix
   * on a surface, so no line explains a scope. One menu line per OFFERED
   * block blueprint (MaterialRecord::$offered, the "share with the AI"
   * flag; its description's first sentence: a blueprint is placed only
   * with its fragment, which the schema prints with the whole
   * description, so the rule rides there with the thing it governs) with
   * the looks it owns beneath it, whole like every other look. Sharing is
   * the one lever: the block grows with every blueprint shared, and the
   * AI context budget (MaterialsBudget) is the ceiling that refuses
   * sharing past it; a site favorite (MaterialRecord::$favorite) is the
   * Add panel's listing and means nothing here. Then the GROUPS index in
   * the neighbours' shape: one line per declared group that holds a
   * shared block blueprint, in declaration order (id, label, how many,
   * the description's first sentence), then the reserved bucket for the
   * shared ones in no declared group (DialectCatalog::UNGROUPED, a count
   * alone), and the header says how one group is read alone
   * (eb_read_schema's group): the vocabulary the model files a saved
   * blueprint under.
   *
   * Weighed as the schema weighs its fragment: each blueprint's line and
   * the looks it owns are that blueprint's (an owned look prints only
   * under its blueprint, so the blueprint is what the toggle shrinks),
   * the heading is the kind's shared; the GROUPS index is the system's,
   * as the schema's groups key is.
   *
   * @return \Drupal\daedalus_blueprint_ai\PromptLines
   *   The lines; empty while the roster offers no block blueprint.
   */
  protected function blueprintLines(DialectCatalog $catalog): PromptLines {
    $roster = $catalog->roster();
    $lines = new PromptLines();
    $blueprints = $roster->blueprints(MaterialRecord::SCOPE_BLOCK);
    if ($blueprints === []) {
      return $lines;
    }
    $lines->of(MaterialKind::Blueprint, NULL, 'BLUEPRINTS (every blueprint shared with you; id "Label" (a section says top-level only; a saved one names the entity_id page_read and page_edit take as entity_type blueprint, a shipped one is read-only); the schema prints each in full; the looks beneath a blueprint are its own, wearable inside its placements):');
    $owned = $this->ownedLooks($roster);
    foreach ($blueprints as $record) {
      $details = [];
      if ($roster->isSection($record)) {
        $details[] = 'top-level only';
      }
      if ($record->entityId !== NULL) {
        $details[] = 'entity_id ' . $record->entityId;
      }
      $lines->of(MaterialKind::Blueprint, $record->id, '- ' . $this->menuLine($record->id, $record->label, implode('; ', $details), $record->description));
      foreach ($owned[$record->id] ?? [] as $look) {
        $lines->of(MaterialKind::Blueprint, $record->id, '  - ' . $this->materialLine($look, $this->lookDetail($look)));
      }
    }
    $lines->system(sprintf('GROUPS (the blueprints above by group: id "Label" (how many): what the group is for; %s holds the ones in no group; eb_read_schema with group: id prints that group\'s blueprints alone):', DialectCatalog::UNGROUPED));
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
        $lines->system('- ' . $this->menuLine($id, $vocabulary[$id]['label'], $this->countProse($count), $vocabulary[$id]['description']));
      }
    }
    if ($ungrouped > 0) {
      $lines->system(sprintf('- %s (%s)', DialectCatalog::UNGROUPED, $this->countProse($ungrouped)));
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
    $wrappers = $catalog->wrappers();
    $named = array_map(
      static fn (string $condition): string => isset($wrappers[$condition]) ? sprintf('%s (%s)', $condition, $wrappers[$condition]) : $condition,
      array_keys($conditions),
    );
    return sprintf('Skin: %s; its named values also resolve under %s.', $label, implode(', ', $named));
  }

  /**
   * The roster per element kind: axes, class looks, birth line, deviations.
   *
   * The container (its kind and the container layout), every block (the
   * block kind's scope, worn by each block element), then each declared
   * block type with only what its own scope adds, then the field element
   * (no axis or look is scoped to it; it prints only where a deviation
   * narrows it). A kind with nothing to say prints no heading.
   *
   * @return \Drupal\daedalus_blueprint_ai\PromptLines
   *   The prompt lines, one group per element kind.
   */
  protected function elementLines(DialectCatalog $catalog): PromptLines {
    $lines = new PromptLines();
    $deviations = $this->deviationPlaces($catalog);
    $container_axes = $catalog->axesFor(TRUE, NULL, NULL);
    $layout = $catalog->containerLayoutId() === '' ? [] : $catalog->birthDefaults(Address::layout($catalog->containerLayoutId()));
    $lines->append($this->elementGroup($catalog, '<' . DialectWalk::CONTAINER_TAG . '>', $container_axes, $catalog->classRosterFor(TRUE, NULL, NULL), $layout, $container_axes, $deviations[Address::KIND_CONTAINER] ?? []));
    $block_axes = $catalog->axesFor(FALSE, NULL, NULL);
    $block_classes = $catalog->classRosterFor(FALSE, NULL, NULL);
    $lines->append($this->elementGroup($catalog, 'Every block element', $block_axes, $block_classes, [], $block_axes, $deviations[Address::KIND_BLOCK] ?? []));
    foreach (array_keys($catalog->blockTypes()) as $type) {
      $axes = $catalog->axesFor(FALSE, $type, NULL);
      $own_axes = array_diff_key($axes, $block_axes);
      $own_classes = array_diff_key($catalog->classRosterFor(FALSE, $type, NULL), $block_classes);
      $lines->append($this->elementGroup($catalog, '<' . DialectCatalog::tagForBlockType($type) . '>', $own_axes, $own_classes, $catalog->birthDefaults(Address::blockType($type)), $axes, $deviations[(string) Address::blockType($type)] ?? []));
    }
    $lines->append($this->elementGroup($catalog, '<' . DialectWalk::FIELD_TAG . '>', [], [], [], [], $deviations[self::KIND_FIELD] ?? []));
    return $lines;
  }

  /**
   * One element kind's group: heading, axes, looks, birth line, deviations.
   *
   * An axis is a heading (the attribute, its qualifiers, its whole
   * description) over one line per value (the value, the variant's label,
   * its whole description): the nested list needs no separator, so a
   * rule with a semicolon in it ("Full width is never the way to get a
   * full-bleed background; a nested container inherits its section's width") reads
   * as written. A class look is one line, top-level only when its
   * variant is root-only.
   *
   * Weighed: a value line is its variant's and a class look its own, an
   * axis heading is the variants' shared (an axis prints only over the
   * variants that are its values, and none of them owns it); the group's
   * heading (over axes, looks, birth and deviations alike), the birth
   * line and the deviations are the system's.
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
   * @param array<string, array<string, array>> $deviations
   *   The element's deviating places (deviationPlaces()): place words =>
   *   property => value space.
   *
   * @return \Drupal\daedalus_blueprint_ai\PromptLines
   *   The group's lines: heading, axes, looks, birth line, deviations.
   */
  protected function elementGroup(DialectCatalog $catalog, string $heading, array $axes, array $classes, array $birth, array $all_axes, array $deviations): PromptLines {
    $roster = $catalog->roster();
    $lines = new PromptLines();
    foreach ($axes as $attr => $axis) {
      $qualifiers = [];
      if (!empty($axis['top_level_only'])) {
        $qualifiers[] = 'top-level only';
      }
      if ($axis['owns'] !== []) {
        $qualifiers[] = sprintf('owns %s: wear the axis, never write them', implode(', ', $axis['owns']));
      }
      $line = '- ' . $attr . ($qualifiers === [] ? '' : ' (' . implode('; ', $qualifiers) . ')') . ':';
      $description = trim((string) $axis['description']);
      $lines->of(MaterialKind::Variant, NULL, $description === '' ? $line : $line . ' ' . $description);
      foreach ($axis['values'] as $value => $id) {
        $record = $roster->get(MaterialKind::Variant, $id);
        $entry = sprintf('  - %s "%s"', $value, $record?->label ?? $id);
        $description = trim($record?->description ?? '');
        $lines->of(MaterialKind::Variant, $id, $description === '' ? $entry : $entry . ': ' . $description);
      }
    }
    foreach ($classes as $id) {
      $record = $roster->get(MaterialKind::Variant, $id);
      if ($record !== NULL) {
        $lines->of(MaterialKind::Variant, $record->id, '- ' . $this->materialLine($record, $this->lookDetail($record)));
      }
    }
    $worn = [];
    foreach ($birth as $attr => $id) {
      $value = array_search($id, $all_axes[$attr]['values'] ?? [], TRUE);
      $worn[] = $value === FALSE ? $id : sprintf('%s="%s"', $attr, $value);
    }
    if ($worn !== []) {
      $lines->system(sprintf('- Born from the palette wearing %s; a document states what it wears itself.', implode(' ', $worn)));
    }
    $lines->system(...$this->deviationLines($catalog, $deviations));
    if ($lines->isEmpty()) {
      return $lines;
    }
    return (new PromptLines())->system($heading . ':')->append($lines);
  }

  /**
   * The deviations by element group, each place in dialect words.
   *
   * The catalog keys a deviation by address print (DialectCatalog::
   * deviations(): "block_type:text::.text-block-link"); the brief has a
   * block per element and speaks dialect, so each is filed under the
   * group of the element it narrows with the place spelled as the STYLES
   * grammar would reach it: the element's own place is '' (the group's
   * "Deviations:" line), a part or the children are "under <selector>",
   * a plugin block or a block type the catalog declares no tag for is
   * "on <d-block plugin="id">" or "on <d-block type="id">" under the
   * block group, with "under <selector>" after it. The container layout
   * is the container element (the layout's spaces override the kind's at
   * the same place), and a declared block type's places drop what the
   * block kind already says at the same place, as its axes and looks
   * drop the kind's. A place the dialect has no element for (the entity
   * root, a region, a layout that is not the container's) is on no line:
   * nothing the model writes lands there.
   *
   * @return array<string, array<string, array<string, array>>>
   *   Group key (the container or block kind, a block type's address
   *   print, KIND_FIELD) => place words => property => value space.
   */
  protected function deviationPlaces(DialectCatalog $catalog): array {
    $places = [];
    $declared = array_keys($catalog->blockTypes());
    foreach ($catalog->deviations() as $print => $spaces) {
      $address = Address::fromString((string) $print);
      $on = '';
      if ($address->family === Address::FAMILY_KIND && $address->id === Address::KIND_CONTAINER
        || $address->family === Address::FAMILY_LAYOUT && $address->id === $catalog->containerLayoutId()) {
        $group = Address::KIND_CONTAINER;
      }
      elseif ($address->family === Address::FAMILY_KIND && $address->id === Address::KIND_BLOCK) {
        $group = Address::KIND_BLOCK;
      }
      elseif ($address->family === Address::FAMILY_KIND && $address->id === self::KIND_FIELD) {
        $group = self::KIND_FIELD;
      }
      elseif ($address->family === Address::FAMILY_BLOCK_TYPE && in_array($address->id, $declared, TRUE)) {
        $group = (string) $address->root();
      }
      elseif ($address->family === Address::FAMILY_BLOCK_TYPE) {
        $group = Address::KIND_BLOCK;
        $on = sprintf('on <%s type="%s">', DialectWalk::BLOCK_TAG, $address->id);
      }
      elseif ($address->family === Address::FAMILY_BLOCK_PLUGIN) {
        $group = Address::KIND_BLOCK;
        $on = sprintf('on <%s plugin="%s">', DialectWalk::BLOCK_TAG, $address->id);
      }
      else {
        continue;
      }
      $place = trim($on . ($address->selector === NULL ? '' : ' under ' . $address->selector));
      foreach ($spaces as $property => $space) {
        $places[$group][$place][$property] = $space;
      }
    }
    foreach ($declared as $type) {
      $group = (string) Address::blockType($type);
      foreach ($places[$group] ?? [] as $place => $spaces) {
        foreach ($spaces as $property => $space) {
          if (($places[Address::KIND_BLOCK][$place][$property] ?? NULL) === $space) {
            unset($places[$group][$place][$property]);
          }
        }
        if ($places[$group][$place] === []) {
          unset($places[$group][$place]);
        }
      }
    }
    return $places;
  }

  /**
   * One element's deviations lines, one per place, through the prose.
   *
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param array<string, array<string, array>> $places
   *   Place words => property => value space (deviationPlaces()).
   *
   * @return string[]
   *   "- Deviations[ <place>]: <line>" per place; [] when none, or when
   *   the catalog carries no prose to render them with.
   */
  protected function deviationLines(DialectCatalog $catalog, array $places): array {
    $prose = $catalog->vocabularyProse();
    if ($places === [] || $prose === NULL) {
      return [];
    }
    $lines = [];
    foreach ($prose->deviationLines($places, $catalog->bindings()) as $place => $line) {
      $lines[] = '- Deviations' . ($place === '' ? '' : ' ' . $place) . ': ' . rtrim($line);
    }
    return $lines;
  }

  /**
   * Bindings as sentences: the properties sharing a line, then the line.
   *
   * "padding, gap take only space values; also allowed: 0" per distinct
   * line, the sentences joined by a period: a scale binding says "only"
   * (that scale's named values only, the polarity sentence's reading),
   * a range or an enumeration says what it takes. The property names
   * lead each sentence, so the semicolons inside a line never read as
   * a boundary between two.
   *
   * @param string[] $properties
   *   The properties to spell, in binding order.
   * @param array<string, string> $lines
   *   Property => line (VocabularyProseInterface::bindingLines()).
   * @param array<string, array> $spaces
   *   Property => value space (DialectCatalog::bindings()).
   */
  protected function bindingProse(array $properties, array $lines, array $spaces): string {
    $groups = [];
    foreach ($properties as $property) {
      $groups[$lines[$property]][] = $property;
    }
    $sentences = [];
    foreach ($groups as $line => $names) {
      $only = isset($spaces[$names[0]]['scale']) ? 'only ' : '';
      $sentences[] = sprintf('%s %s %s%s', implode(', ', $names), count($names) === 1 ? 'takes' : 'take', $only, $line);
    }
    return implode('. ', $sentences);
  }

  /**
   * The variants no element roster places, each with its scope.
   *
   * The block is variants alone, heading included.
   *
   * @return \Drupal\daedalus_blueprint_ai\PromptLines
   *   One line per look, naming its scope; empty when none.
   */
  protected function otherLookLines(DialectCatalog $catalog): PromptLines {
    $lines = new PromptLines();
    $elsewhere = $this->looksElsewhere($catalog);
    if ($elsewhere === []) {
      return $lines;
    }
    $lines->of(MaterialKind::Variant, NULL, 'OTHER LOOKS (worn where their scope fits):');
    foreach ($elsewhere as $record) {
      $detail = $this->lookDetail($record);
      if ($record->scope !== NULL) {
        $detail .= ($detail === '' ? '' : '; ') . 'scope ' . $record->scope;
      }
      $lines->of(MaterialKind::Variant, $record->id, '- ' . $this->materialLine($record, $detail));
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
   * The token roster with values: a heading per scale, a line per token.
   *
   * Projected from the roster's token records: the scale is the record's
   * group, the custom property the var() spelling's argument (what the
   * value grammar resolves), the value the record's. The scale heading
   * carries the bindings that name it ("bindings: font-size takes only
   * text values"): the model reads the scale when it picks a value, and
   * the heading is where the law that a property takes that scale's
   * values belongs. Each token is one line beneath it, the whole
   * description (why it exists, when to reach for it and the rule that
   * follows: "Never pure black on white") after the value, the brief
   * being the one home of a token's text.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialsRoster $roster
   *   The offered roster.
   * @param array<string, string[]> $by_scale
   *   Scale => the bound properties naming it (briefLines()).
   * @param array<string, string> $binding_lines
   *   Property => line (VocabularyProseInterface::bindingLines()).
   * @param array<string, array> $spaces
   *   Property => value space (DialectCatalog::bindings()).
   *
   * @return \Drupal\daedalus_blueprint_ai\PromptLines
   *   The lines: each scale heading the tokens' shared, each token line
   *   its record's; empty when the roster offers no token.
   */
  protected function tokenLines(MaterialsRoster $roster, array $by_scale, array $binding_lines, array $spaces): PromptLines {
    $by_scale_records = [];
    foreach ($roster->tokens() as $record) {
      $by_scale_records[(string) $record->group][] = $record;
    }
    $lines = new PromptLines();
    foreach ($by_scale_records as $scale => $records) {
      $heading = '- ' . $scale;
      if (isset($by_scale[$scale])) {
        $heading .= ' (bindings: ' . $this->bindingProse($by_scale[$scale], $binding_lines, $spaces) . ')';
      }
      $lines->of(MaterialKind::Token, NULL, $heading . ':');
      foreach ($records as $record) {
        $lines->of(MaterialKind::Token, $record->id, '  - ' . $this->namedValueLine(MaterialsSchema::customProperty($record), NULL, $record));
      }
    }
    return $lines;
  }

  /**
   * One named value's line: --property ["Label"]: value — the description.
   *
   * The value takes the colon a material line gives its description, so
   * the description follows a dash: last on the line like every other.
   */
  protected function namedValueLine(string $property, ?string $label, MaterialRecord $record): string {
    $line = $property . ($label === NULL ? '' : sprintf(' "%s"', $label)) . ': ' . MaterialsRoster::valueProse($record->value, self::VALUE_CLIP);
    $description = trim($record->description);
    return $description === '' ? $line : $line . ' — ' . $description;
  }

  /**
   * One material's line: id "Label" (detail): the whole description.
   *
   * The shape every look, animation and font shares; a blueprint's menu
   * line is menuLine()'s.
   */
  protected function materialLine(MaterialRecord $record, string $detail): string {
    $line = sprintf('%s "%s"', $record->id, $record->label);
    if ($detail !== '') {
      $line .= ' (' . $detail . ')';
    }
    $description = trim($record->description);
    return $description === '' ? $line : $line . ': ' . $description;
  }

  /**
   * A look's detail: its spellings, and top-level only when it is root-only.
   *
   * A class look worn only at the top level (a section's look) says so on
   * its line as a section axis says it on its heading, since the brief is
   * where the model learns what it may wear where.
   */
  protected function lookDetail(MaterialRecord $record): string {
    $detail = $this->spellingProse($record);
    if ($record->rootOnly()) {
      $detail .= ($detail === '' ? '' : '; ') . 'top-level only';
    }
    return $detail;
  }

  /**
   * One menu line: id "Label" (detail): the description's first sentence.
   *
   * The shape the blueprint menu lines and the group index share, the
   * two places the clip survives (menuSentence()).
   */
  protected function menuLine(string $id, string $label, string $detail, string $description): string {
    $line = sprintf('%s "%s"', $id, $label);
    if ($detail !== '') {
      $line .= ' (' . $detail . ')';
    }
    $description = $this->menuSentence($description);
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
   * The menu clip, for the two lines that are a menu and not a
   * material's law: a blueprint's BLUEPRINTS line (a blueprint is placed
   * only with its fragment, which the schema prints with the whole
   * description, so the rule rides there) and a group's index line (a
   * group's description is a label's aside). Every other description on
   * the brief is whole.
   */
  protected function menuSentence(string $description): string {
    $description = trim($description);
    if (preg_match('/^(.+?)[.!?](?:\s|$)/su', $description, $m)) {
      return $m[1];
    }
    return $description;
  }

}
