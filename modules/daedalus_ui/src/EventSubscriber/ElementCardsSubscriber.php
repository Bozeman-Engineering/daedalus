<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\Event\DialectCatalogBuildEvent;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_ui\ElementModel\ElementResolver;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_ui\ElementModel\SketchPrinter;
use Drupal\field\FieldConfigInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Derives the render cards from the markup declarations, for the catalog.
 *
 * A render card is the AI's one read of the markup model: what a dialect
 * element renders as, taught the way a function is taught, by input and
 * output pairs (DialectCatalogBuildEvent::addCard() holds the shape).
 * Each pair is a dialect write and the HTML the resolver renders it as,
 * so the model keeps writing data (the print stays a data print) and
 * sees what the design system makes of it, the way the browser's live
 * preview shows a human. This subscriber builds one card per owner from
 * the layered page_builder declarations for the site theme
 * (PageBuilderDeclarations::siteTheme(), the one every off-page consumer
 * resolves) and hands them to the dialect catalog on its build event; the
 * cached prompt and the schema print them from there.
 *
 * The owners:
 * - `d-container`: the active container layout's effective declaration
 *   (the container kind, the layout's refinement over it), its pairs at
 *   both depths (top-level, nested) and both emptinesses.
 * - `d-{block type}`, one per bundle the catalog tags: the effective
 *   block-type declaration (the universal block kind underneath) when a
 *   layer declares the bundle; otherwise the FALLBACK card, `shells:
 *   ['block', 'field']`, saying the type renders through the theme's block
 *   template with each field in the universal field shell (adopted and
 *   site-builder bundles).
 * - `d-block[type=ID]` (DialectCatalog::ownerForGenericBlockType()), one
 *   per placeable bundle the contract cannot spell (the dialect prints it
 *   as <d-block type="ID"> with <d-field> children): the FALLBACK card,
 *   so the one element the AI writes generically still reads what it
 *   renders through.
 * A declared plugin block has no card: nothing composes (the template owns
 * the markup), and its labelled parts ride the styling roster.
 *
 * The pairs of one element are every distinct shape its declaration can
 * produce, each shown with the plainest write that produces it: the base
 * facade (the author sets nothing; every field holds its default), each
 * root form at its fullest (its own conditions plus every part condition
 * that can hold beside them without another form taking the root, so the
 * linked text form shows the anchor inside the heading), and each part
 * condition on its own over the base (the link on the default paragraph).
 * The resolver's own facade entry decides every match
 * (ElementResolver::resolveData(), matchedRootFormData()), so the builder
 * never re-decides first-match-wins; a shape two facades reach is shown
 * once, with the write that set less; the pairs are ordered by how much
 * the write sets. The input is printed from the facade in the dialect's
 * own spelling (the catalog builder's field-to-attribute derivation, a
 * sampled value or the placeholder), with the facts no attribute spells
 * (the media kind, the depth) in parentheses; the output is the resolved
 * tree printed by SketchPrinter.
 */
final class ElementCardsSubscriber implements EventSubscriberInterface {

  /**
   * The card of a tagged bundle no layer declares.
   */
  public const FALLBACK_CARD = [
    'renders' => [],
    'shells' => ['block', 'field'],
  ];

  /**
   * The container's content token: its children, claimed like a body.
   */
  private const CHILDREN_TOKEN = 'regions';

  /**
   * The entity type the dialect's block types are bundles of.
   */
  private const BLOCK_ENTITY_TYPE = 'block_content';

  /**
   * The words of the facts no attribute spells, in the input's parentheses.
   */
  private const WORDS = [
    'raster' => 'raster media',
    'vector' => 'vector media',
    ElementResolver::DEPTH_ROOT => 'top-level',
    ElementResolver::DEPTH_NESTED => 'nested',
  ];

  public function __construct(
    private readonly PageBuilderDeclarations $declarations,
    private readonly ElementResolver $resolver,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      DialectCatalogBuildEvent::EVENT_NAME => 'onCatalogBuild',
    ];
  }

  /**
   * Contributes one card per owner the dialect tags.
   */
  public function onCatalogBuild(DialectCatalogBuildEvent $event): void {
    $theme = $this->declarations->siteTheme();
    $layout_id = $event->getContainerLayoutId();
    if ($layout_id !== '') {
      $event->addCard(RelativeSelector::TYPE_CONTAINER, $this->containerCard($this->declarations->getLayout($layout_id, $theme)));
    }
    $declared = $this->declarations->getBlockTypes($theme);
    $tagged = $event->getBlockTypes();
    foreach ($tagged as $bundle => $contract) {
      $bundle = (string) $bundle;
      $card = in_array($bundle, $declared, TRUE)
        ? $this->blockTypeCard($this->declarations->getBlockType($bundle, $theme), $bundle, $contract)
        : self::FALLBACK_CARD;
      $event->addCard(DialectCatalog::tagForBlockType($bundle), $card);
    }
    // The placeable bundles the contract could not spell print
    // generically; the builder's own placement answer, carried on the
    // event (never asked of the spine here: a bare site, no block types,
    // must reach no block plugin discovery through a catalog build),
    // minus the tagged ones.
    foreach ($event->getPlaceableBundles() as $bundle) {
      if (!isset($tagged[$bundle])) {
        $event->addCard(DialectCatalog::ownerForGenericBlockType($bundle), self::FALLBACK_CARD);
      }
    }
  }

  /**
   * The card of a declared block type.
   *
   * @param array $declaration
   *   The effective block-type declaration.
   * @param string $bundle
   *   The bundle, for the field-to-attribute spelling.
   * @param array $contract
   *   The bundle's attribute contract from the catalog builder
   *   ({attributes, body?}).
   */
  private function blockTypeCard(array $declaration, string $bundle, array $contract): array {
    $body_field = $contract['body']['field'] ?? NULL;
    $element = [
      'owner' => DialectCatalog::tagForBlockType($bundle),
      'bundle' => $bundle,
      'attributes' => $contract['attributes'] ?? [],
      'content' => is_string($body_field) ? 'field:' . $body_field : NULL,
      // A type with no body prints self-closing (the catalog line's own
      // reading of the contract).
      'void' => !is_string($body_field),
    ];
    return ['renders' => $this->renders($declaration, $element), 'shells' => []];
  }

  /**
   * The card of the container layout.
   */
  private function containerCard(array $declaration): array {
    $element = [
      'owner' => RelativeSelector::TYPE_CONTAINER,
      'bundle' => NULL,
      'attributes' => [],
      'content' => self::CHILDREN_TOKEN,
      'void' => FALSE,
    ];
    return ['renders' => $this->renders($declaration, $element), 'shells' => []];
  }

  /**
   * The pairs of one element: every distinct shape, with its plainest write.
   *
   * @param array $declaration
   *   The effective declaration.
   * @param array $element
   *   The element being carded: 'owner' (its dialect tag), 'bundle' (the
   *   bundle whose fields the declaration reads, NULL for a container),
   *   'attributes' (the bundle's attribute contract, attr => {field,
   *   values?, key?, kind?}), 'content' (the content slot token: the
   *   body field's `field:NAME`, the container's children, NULL for an
   *   element with no content) and 'void' (whether the dialect prints it
   *   self-closing).
   *
   * @return array<string, string>
   *   Input => html, plainest first.
   */
  private function renders(array $declaration, array $element): array {
    $base = $this->baseFacade($element['bundle']);
    $depths = $this->usesDepth($declaration)
      ? [ElementResolver::DEPTH_ROOT, ElementResolver::DEPTH_NESTED]
      : [ElementResolver::DEPTH_ROOT];
    $candidates = [];
    foreach ($depths as $depth) {
      $candidates[] = [$base, $depth];
    }
    $part_conditions = $this->partConditions($declaration['elements'] ?? NULL);
    foreach ($this->rootForms($declaration) as $form) {
      $candidate = $this->fullest($declaration, $form, $part_conditions, $base, $element, $depths);
      if ($candidate !== NULL) {
        $candidates[] = $candidate;
      }
    }
    foreach ($part_conditions as $alternatives) {
      foreach ($alternatives as $present) {
        $facade = $this->facade($base, is_array($present) ? $present : [], $element, NULL);
        if ($facade === FALSE) {
          continue;
        }
        foreach ($depths as $depth) {
          $candidates[] = [$facade, $depth];
        }
      }
    }
    $pairs = [];
    foreach ($candidates as [$facade, $depth]) {
      $tree = $this->resolver->resolveData($declaration, $facade, $depth);
      $empty = ($facade['empty'] ?? FALSE) === TRUE;
      $html = SketchPrinter::print($tree, $empty ? NULL : $element['content']);
      $facts = $this->facts($facade, $base, $depth);
      if (!isset($pairs[$html]) || $pairs[$html]['facts'] > $facts) {
        $pairs[$html] = ['facts' => $facts, 'input' => $this->input($facade, $base, $depth, $element, $depths)];
      }
    }
    uasort($pairs, static fn (array $a, array $b): int => $a['facts'] <=> $b['facts']);
    $renders = [];
    foreach ($pairs as $html => $pair) {
      $renders[$pair['input']] = (string) $html;
    }
    return $renders;
  }

  /**
   * The declaration's root forms as declared: root.forms, else its one tag.
   *
   * @return array<int, array>
   *   Forms, each at least {tag}; a declaration naming no root at all is
   *   one tagless form (the theme's template owns the root).
   */
  private function rootForms(array $declaration): array {
    if (is_array($declaration['root']['forms'] ?? NULL)) {
      return array_values(array_filter($declaration['root']['forms'], 'is_array'));
    }
    return [['tag' => $declaration['tag'] ?? NULL]];
  }

  /**
   * Whether any form or part of the declaration turns on the depth.
   */
  private function usesDepth(array $declaration): bool {
    foreach ($this->rootForms($declaration) as $form) {
      if (isset($form['present']['depth'])) {
        return TRUE;
      }
    }
    foreach ($this->partConditions($declaration['elements'] ?? NULL) as $alternatives) {
      foreach ($alternatives as $present) {
        if (isset($present['depth'])) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Walks an elements: tree for the conditions its parts exist under.
   *
   * Depth-first in declaration order; pattern parts name class
   * namespaces, not elements, and are skipped.
   *
   * @param mixed $elements
   *   The level's elements map.
   *
   * @return array<int, array<int, mixed>>
   *   Per part, the present: maps that make it exist (one, or one per
   *   form of a multi-form part; NULL for an unconditional part).
   */
  private function partConditions(mixed $elements): array {
    $conditions = [];
    if (!is_array($elements)) {
      return $conditions;
    }
    foreach ($elements as $selector => $part) {
      if (!is_array($part) || preg_match(PageBuilderDeclarations::PART_PATTERN, (string) $selector)) {
        continue;
      }
      $alternatives = [];
      if (is_array($part['forms'] ?? NULL)) {
        foreach ($part['forms'] as $form) {
          if (is_array($form)) {
            $alternatives[] = $form['present'] ?? NULL;
          }
        }
      }
      else {
        $alternatives[] = $part['present'] ?? NULL;
      }
      $conditions[] = $alternatives;
      array_push($conditions, ...$this->partConditions($part['elements'] ?? NULL));
    }
    return $conditions;
  }

  /**
   * One root form at its fullest: the facade and depth that show the most of it.
   *
   * The form's own conditions as sample data (an attribute's field filled
   * with a placeholder, a tag-deciding field with a value from its roster,
   * mediaKind from {media}, empty from {empty}, the depth it names or the
   * first depth that reaches it), plus every part condition that can hold
   * beside them without another form taking the root; a multi-form part
   * takes the first of its forms that fits.
   *
   * @return array{0: ?array, 1: string}|null
   *   The facade and depth, or NULL when no sample facade reaches the
   *   form.
   */
  private function fullest(array $declaration, array $form, array $part_conditions, ?array $base, array $element, array $depths): ?array {
    $own = $form['present'] ?? NULL;
    $facade = $this->facade($base, is_array($own) ? $own : [], $element, $form['tag'] ?? NULL);
    if ($facade === FALSE) {
      return NULL;
    }
    $named_depth = is_array($own) ? ($own['depth'] ?? NULL) : NULL;
    $try = in_array($named_depth, $depths, TRUE) ? [$named_depth] : $depths;
    $depth = NULL;
    foreach ($try as $candidate) {
      if ($this->reaches($declaration, $form, $facade, $candidate)) {
        $depth = $candidate;
        break;
      }
    }
    if ($depth === NULL) {
      return NULL;
    }
    foreach ($part_conditions as $alternatives) {
      foreach ($alternatives as $present) {
        if (!is_array($present)) {
          continue;
        }
        $fuller = $this->facade($facade, $present, $element, NULL);
        if ($fuller !== FALSE && $this->reaches($declaration, $form, $fuller, $depth)) {
          $facade = $fuller;
          break;
        }
      }
    }
    return [$facade, $depth];
  }

  /**
   * Whether a facade resolves the declaration's root under the given form.
   *
   * A declaration with no root forms has one synthetic form, reached by
   * every facade.
   */
  private function reaches(array $declaration, array $form, ?array $facade, string $depth): bool {
    if (!isset($declaration['root']['forms'])) {
      return TRUE;
    }
    return $this->resolver->matchedRootFormData($declaration, $facade, $depth) === $form;
  }

  /**
   * The base facade: the author sets nothing, every field holds its default.
   *
   * A bundle's facade always carries its fields map (empty when no field
   * has a default: the element is then empty, as the resolver reads an
   * entity with no values); an element with no bundle has no facade.
   */
  private function baseFacade(?string $bundle): ?array {
    if ($bundle === NULL) {
      return NULL;
    }
    $fields = [];
    foreach ($this->fieldDefinitions($bundle) as $name => $definition) {
      $default = $this->fieldDefault($definition);
      if ($default !== NULL) {
        $fields[$name] = $default;
      }
    }
    return ['fields' => $fields];
  }

  /**
   * Adds a present: map's facts to a facade as sample data.
   *
   * @param array|null $facade
   *   The facade so far (NULL for an element with no bundle).
   * @param array $present
   *   The conditions to satisfy.
   * @param array $element
   *   The element being carded (see renders()).
   * @param mixed $tag
   *   The form's declared tag: a tag read from a field fills that field
   *   with a value that is a tag name and not the field's default, so the
   *   sketch shows the data-decided root instead of the default's.
   *
   * @return array|null|false
   *   The facade with the facts added, or FALSE when a fact contradicts
   *   one already in it (a second media kind, emptiness beside content)
   *   or cannot be sampled.
   */
  private function facade(?array $facade, array $present, array $element, mixed $tag): array|null|false {
    $bundle = $element['bundle'];
    $fields = $facade['fields'] ?? [];
    $media = $facade['mediaKind'] ?? NULL;
    $empty = ($facade['empty'] ?? FALSE) === TRUE;
    $wants = [];
    foreach ($present as $condition => $value) {
      $wants[] = [$condition, $value];
    }
    if (is_array($tag) && is_string($tag['field'] ?? NULL)) {
      $wants[] = ['tag', $tag['field']];
    }
    foreach ($wants as [$condition, $value]) {
      switch ($condition) {
        case 'field':
        case 'tag':
          if ($empty || !is_string($value) || $bundle === NULL) {
            return FALSE;
          }
          $sample = $this->sample($bundle, $value, $element['attributes']);
          if ($condition === 'tag') {
            if (!preg_match('/^[a-z][a-z0-9-]*$/', $sample)) {
              // The field decides the tag but offers no tag name to
              // sample: the form cannot be sketched honestly.
              return FALSE;
            }
            $fields[$value] = $sample;
          }
          else {
            $fields[$value] ??= $sample;
          }
          break;

        case 'media':
          if ($empty || ($media !== NULL && $media !== $value)) {
            return FALSE;
          }
          $media = $value;
          break;

        case 'empty':
          if ($fields !== [] || $media !== NULL) {
            return FALSE;
          }
          $empty = TRUE;
          break;
      }
    }
    $built = [];
    if ($bundle !== NULL || $fields !== []) {
      $built['fields'] = $fields;
    }
    if ($media !== NULL) {
      $built['mediaKind'] = $media;
    }
    if ($empty) {
      $built['empty'] = TRUE;
    }
    return $built === [] ? NULL : $built;
  }

  /**
   * How much a facade sets beyond the base: the pairs' order and tie-break.
   */
  private function facts(?array $facade, ?array $base, string $depth): int {
    $facts = 0;
    foreach ($facade['fields'] ?? [] as $name => $value) {
      if (($base['fields'][$name] ?? NULL) !== $value) {
        $facts++;
      }
    }
    if (($facade['mediaKind'] ?? NULL) !== NULL) {
      $facts++;
    }
    if (($facade['empty'] ?? FALSE) === TRUE) {
      $facts++;
    }
    if ($depth === ElementResolver::DEPTH_NESTED) {
      $facts++;
    }
    return $facts;
  }

  /**
   * The dialect write that sets what a facade sets.
   *
   * The owner's tag with one attribute per field set beyond the base, in
   * the contract's order (its sampled value, or the placeholder), the
   * media reference as its attribute with the kind in parentheses, the
   * content placeholder inside unless the element is empty or void, and
   * the depth in parentheses for an element whose shape turns on it.
   */
  private function input(?array $facade, ?array $base, string $depth, array $element, array $depths): string {
    $attrs = '';
    $words = [];
    $set = [];
    foreach ($facade['fields'] ?? [] as $name => $value) {
      if (($base['fields'][$name] ?? NULL) !== $value && is_string($value)) {
        $set[$name] = $value;
      }
    }
    $media = $facade['mediaKind'] ?? NULL;
    foreach ($element['attributes'] as $attr => $spec) {
      $field = $spec['field'] ?? NULL;
      if (!is_string($field) || isset($spec['key'])) {
        continue;
      }
      if (isset($set[$field])) {
        $attrs .= sprintf(' %s="%s"', $attr, $set[$field]);
      }
      elseif ($media !== NULL && $this->isMediaReference($element['bundle'], $field)) {
        $attrs .= sprintf(' %s="%s"', $attr, SketchPrinter::PLACEHOLDER);
      }
    }
    if ($media !== NULL) {
      $words[] = self::WORDS[$media];
    }
    if (count($depths) > 1) {
      $words[] = self::WORDS[$depth];
    }
    $open = '<' . $element['owner'] . $attrs;
    if ($element['void']) {
      $write = $open . '/>';
    }
    else {
      $empty = ($facade['empty'] ?? FALSE) === TRUE;
      $write = $open . '>' . ($element['content'] !== NULL && !$empty ? SketchPrinter::PLACEHOLDER : '') . '</' . $element['owner'] . '>';
    }
    return $words === [] ? $write : $write . ' (' . implode(', ', $words) . ')';
  }

  /**
   * The sample value a field holds in a sketch.
   *
   * A field with a value roster in the attribute contract (a list field)
   * samples its first value that is not its default, so a sketch of the
   * "attribute set" form differs from the default's; a field with a
   * string default samples that; anything else prints the placeholder (a
   * link's href).
   */
  private function sample(string $bundle, string $field_name, array $attributes): string {
    $definition = $this->fieldDefinitions($bundle)[$field_name] ?? NULL;
    $default = $definition === NULL ? NULL : $this->fieldDefault($definition);
    foreach ($attributes as $attribute) {
      if (($attribute['field'] ?? NULL) !== $field_name || isset($attribute['key']) || !is_array($attribute['values'] ?? NULL)) {
        continue;
      }
      foreach ($attribute['values'] as $value) {
        if (is_string($value) && $value !== $default) {
          return $value;
        }
      }
    }
    return $default ?? SketchPrinter::PLACEHOLDER;
  }

  /**
   * Whether a bundle's field is a media reference (the element's media).
   */
  private function isMediaReference(?string $bundle, string $field_name): bool {
    if ($bundle === NULL) {
      return FALSE;
    }
    $definition = $this->fieldDefinitions($bundle)[$field_name] ?? NULL;
    return $definition !== NULL && $definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'media';
  }

  /**
   * A bundle's content field definitions: the configurable fields.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   Field name => definition.
   */
  private function fieldDefinitions(string $bundle): array {
    return array_filter(
      $this->entityFieldManager->getFieldDefinitions(self::BLOCK_ENTITY_TYPE, $bundle),
      static fn ($definition): bool => $definition instanceof FieldConfigInterface && $definition->getType() !== 'layout_section',
    );
  }

  /**
   * A field's default value as a string, NULL when it has none.
   */
  private function fieldDefault(FieldDefinitionInterface $definition): ?string {
    $literal = $definition->getDefaultValueLiteral();
    $value = $literal[0]['value'] ?? NULL;
    return is_scalar($value) && (string) $value !== '' ? (string) $value : NULL;
  }

}
