<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\Dialect\Event\DialectCatalogBuildEvent;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\FieldHandler\MediaGenerationInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\ContainerLayout;
use Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Derives the site's DialectCatalog from live configuration.
 *
 * One canonical derivation from block types, field definitions, and the
 * styling vocabulary, consumed by the page tools, the cached-prompt
 * renderer, and the current-page print, so the dialect the model reads,
 * writes, and is taught against is always the same one. Tests and
 * scripts may still construct a DialectCatalog directly.
 *
 * What the spine derives here:
 * - Block types: the placement-allowed inline block bundles, each with an
 *   ATTRIBUTE CONTRACT derived from its configurable single-value fields.
 *   The attribute base name is the field name minus 'field_' and minus a
 *   leading '{bundle}_', with '_' spelled '-' (field_text_tag -> tag,
 *   field_image_url -> url). Per storage type: list_string and string are
 *   scalar attrs (allowed_values become the value roster); link fields are
 *   kind 'link'; an entity_reference to media expands into the media
 *   handler's authored keys (alt => alt_text, media => ref, and, when the
 *   handler generates (MediaGenerationInterface), generate => _generate
 *   plus the active provider's sizing key in kebab case, aspect-ratio =>
 *   aspectRatio) — bare when the base name IS the bundle name, prefixed
 *   'base-' otherwise; any other
 *   entity_reference is kind 'ref'; the FIRST long-text field is the body
 *   (string_long plain, text_long/text_with_summary rich with a 'format'
 *   attr carrying the handler's available_formats). A bundle with any
 *   field this contract cannot express stays UNDECLARED and prints/parses
 *   through the generic <pb-block type> form — coverage grows by
 *   extending the contract, never by a lossy declaration (the printer
 *   refuses lossy prints).
 * - The container layout id: the first placement-allowed layout that is a
 *   container.
 *
 * The site's MATERIALS (tokens, swatches, variants, stencils, animations) come
 * from the materials roster (MaterialsRosterBuilder over the tagged providers),
 * the one published set every material surface projects; the styling
 * DECLARATIONS the roster does not carry (each scope's axis order with the
 * axes' descriptions and owned properties, the condition vocabulary, parts,
 * instance namespaces, closed stylable rosters, birth defaults, the skin's
 * dressed tags) are contributed by DialectCatalogBuildEvent subscribers
 * (daedalus_stylish's DialectVocabularySubscriber on a Daedalus site), keeping the
 * spine styling-agnostic.
 */
class DialectCatalogBuilder {

  /**
   * The built catalog, memoized per request and per roster build.
   */
  protected ?DialectCatalog $catalog = NULL;

  public function __construct(
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FieldHandlerManager $fieldHandlerManager,
    protected PlacementRestrictionsInterface $placementRestrictions,
    protected EventDispatcherInterface $eventDispatcher,
    protected MaterialsRosterBuilder $rosterBuilder,
  ) {}

  /**
   * Builds (and memoizes) the site's dialect catalog.
   *
   * The memo follows the roster: a material written mid-request resets
   * the roster builder (the providers' entity hooks), and the next build
   * here sees a new roster instance and derives a fresh catalog, so a
   * gesture that mints and references in one request never parses
   * against the pre-mint vocabulary.
   */
  public function build(): DialectCatalog {
    $roster = $this->rosterBuilder->build();
    if ($this->catalog !== NULL && $this->catalog->roster() === $roster) {
      return $this->catalog;
    }

    $block_types = $this->deriveBlockTypes();
    $container_layout_id = $this->containerLayoutId();
    $event = new DialectCatalogBuildEvent($block_types, $container_layout_id);
    $this->eventDispatcher->dispatch($event, DialectCatalogBuildEvent::EVENT_NAME);

    $this->catalog = new DialectCatalog(
      containerLayoutId: $container_layout_id,
      axes: $event->getAxes(),
      blockTypes: $block_types,
      conditions: $event->getConditions(),
      parts: $event->getParts(),
      instances: $event->getInstances(),
      stylable: $event->getStylable(),
      roster: $roster,
      textTags: $event->getTextTags(),
      prose: $event->getVocabularyProse(),
      birthDefaults: $event->getBirthDefaults(),
      dressedTags: $event->getDressedTags(),
      skin: $event->getSkin(),
    );
    return $this->catalog;
  }

  /**
   * Drops the memoized catalog (config changed mid-request).
   */
  public function reset(): void {
    $this->catalog = NULL;
  }

  /**
   * The one container layout's id, from the placement spine.
   */
  protected function containerLayoutId(): string {
    foreach ($this->placementRestrictions->allowedLayoutDefinitions(NULL) as $id => $definition) {
      if (ContainerLayout::isContainer($definition)) {
        return (string) $id;
      }
    }
    return '';
  }

  /**
   * Derives the declared block types with their attribute contracts.
   */
  protected function deriveBlockTypes(): array {
    $bundles = $this->bundleInfo->getBundleInfo('block_content');
    if ($bundles === []) {
      return [];
    }
    $allowed = $this->placementRestrictions->allowedInlineBlockBundles(NULL);
    $types = [];
    foreach (array_intersect_key($bundles, array_flip($allowed)) as $bundle => $info) {
      $contract = $this->deriveContract($bundle);
      if ($contract !== NULL) {
        $types[$bundle] = $contract;
      }
    }
    return $types;
  }

  /**
   * A handler key as an attribute name (aspectRatio => aspect-ratio).
   */
  protected static function kebab(string $key): string {
    return strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', str_replace('_', '-', $key)));
  }

  /**
   * Derives one bundle's attribute contract, or NULL when inexpressible.
   */
  protected function deriveContract(string $bundle): ?array {
    $definitions = $this->entityFieldManager->getFieldDefinitions('block_content', $bundle);
    $attributes = [];
    $body = NULL;
    foreach ($definitions as $field_name => $definition) {
      if (!$definition instanceof FieldConfigInterface) {
        continue;
      }
      $storage = $definition->getFieldStorageDefinition();
      if ($storage->getType() === 'daedalus_stylish_style_map') {
        // The style map is infrastructure, never an authored field
        // (matching on the type string keeps this module daedalus-stylish-agnostic).
        continue;
      }
      if ($storage->getCardinality() !== 1) {
        return NULL;
      }
      $base = static::attrBase($field_name, $bundle);
      switch ($storage->getType()) {
        case 'list_string':
          $allowed_values = $storage->getSetting('allowed_values') ?? [];
          $attributes[$base] = [
            'field' => $field_name,
            'values' => array_map(strval(...), array_keys($allowed_values)),
          ];
          break;

        case 'string':
          $attributes[$base] = ['field' => $field_name];
          break;

        case 'link':
          $attributes[$base] = ['field' => $field_name, 'kind' => 'link'];
          break;

        case 'entity_reference':
          if ($definition->getSetting('target_type') === 'media') {
            $prefix = ($base === str_replace('_', '-', $bundle) || $base === '') ? '' : "$base-";
            $attributes[$prefix . 'alt'] = ['field' => $field_name, 'key' => 'alt_text'];
            $attributes[$prefix . 'media'] = ['field' => $field_name, 'kind' => 'ref'];
            // Generation and its sizing are the field handler's vocabulary
            // (MediaGenerationInterface): the sizing attribute is the active
            // provider's key in kebab case (aspectRatio => aspect-ratio) and
            // exists only while a provider takes one.
            $handler = $this->fieldHandlerManager->getHandler($definition);
            if ($handler instanceof MediaGenerationInterface) {
              $attributes[$prefix . 'generate'] = ['field' => $field_name, 'key' => '_generate'];
              $key = $handler->getDimensionKey();
              if ($key !== NULL) {
                $attributes[$prefix . static::kebab($key)] = [
                  'field' => $field_name,
                  'key' => $key,
                  'values' => $handler->getDimensionValues(),
                ];
              }
            }
          }
          else {
            $attributes[$base] = ['field' => $field_name, 'kind' => 'ref'];
          }
          break;

        case 'string_long':
          if ($body !== NULL) {
            return NULL;
          }
          $body = ['field' => $field_name, 'kind' => 'plain'];
          break;

        case 'text_long':
        case 'text_with_summary':
          if ($body !== NULL) {
            return NULL;
          }
          $body = ['field' => $field_name, 'kind' => 'rich'];
          $format_attr = ['field' => $field_name, 'key' => 'format'];
          $formats = $this->availableFormats($definition);
          if ($formats !== []) {
            $format_attr['values'] = $formats;
          }
          $attributes['format'] = $format_attr;
          break;

        default:
          // A field type outside the contract: the bundle stays
          // undeclared (generic <pb-block type> form) rather than
          // declared lossily.
          return NULL;
      }
    }
    $contract = ['attributes' => $attributes];
    if ($body !== NULL) {
      $contract['body'] = $body;
    }
    return $contract;
  }

  /**
   * The attribute base name for a field on a bundle.
   *
   * The one derivation behind every field-to-attribute spelling
   * (field_text_tag on the text bundle is `tag`); public so a consumer
   * that reads block fields outside a catalog can name an attribute the
   * way the dialect does.
   */
  public static function attrBase(string $field_name, string $bundle): string {
    $base = preg_replace('/^field_/', '', $field_name);
    if (str_starts_with($base, $bundle . '_')) {
      $base = substr($base, strlen($bundle) + 1);
    }
    return str_replace('_', '-', $base);
  }

  /**
   * The allowed text formats for a rich-text field, via its handler.
   */
  protected function availableFormats(FieldDefinitionInterface $definition): array {
    $additions = $this->fieldHandlerManager->getHandler($definition)->getSchemaAdditions($definition);
    return array_values($additions['available_formats'] ?? []);
  }

}
