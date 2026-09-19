<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Handler for link field types.
 *
 * Serializes to {uri, title, options} objects enriched with a computed
 * "_displayable" string: the uri the way a person would type it ("entity:"
 * uris become "Label (id)", "internal:" uris the bare path, "route:" uris
 * the "<nolink>" family). Deserialization accepts the user-entered link
 * grammar (the LinkWidget vocabulary) and normalizes it to storage uris:
 * bare strings become {uri}, schemeless strings ("/about", "?page=2",
 * "#top", "<front>") gain the "internal:" scheme, and the
 * "<nolink>"/"<none>"/"<button>" family gains the "route:" scheme. The
 * read-only "_displayable" enrichment is stripped on write; Phase A admits
 * it as handler vocabulary under the grammar's underscore rule (see
 * StructuralValidator), so a serialized link round-trips through the
 * deserializer unchanged.
 */
class LinkFieldHandler extends FieldHandlerBase {

  /**
   * Constructs a LinkFieldHandler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to resolve "entity:" uri labels.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'link';
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    return 'Accepts {uri: "...", title: "Link text"} or a bare string. The uri accepts what a person would type: an internal path ("/about", "?page=2", "#top") works as-is, "<front>" is the front page, and "<nolink>", "<none>", or "<button>" produce a link without a destination. Entity links: "entity:node/42". External links need a scheme ("https://example.com"). An optional options object carries link attributes (e.g. {attributes: {target: "_blank"}}); when updating an existing link, include its current options or they are cleared. Read payloads include a computed "_displayable" string; it is read-only and ignored on write.';
  }

  /**
   * {@inheritdoc}
   */
  protected function doSerialize(FieldItemListInterface $items, FieldDefinitionInterface $definition): array {
    $values = [];

    foreach ($items as $item) {
      $serialized = [
        'uri' => $item->uri,
        '_displayable' => $this->getUriAsDisplayableString($item->uri),
      ];
      if (!empty($item->title)) {
        $serialized['title'] = $item->title;
      }
      if (!empty($item->options)) {
        $serialized['options'] = $item->options;
      }
      $values[] = $serialized;
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    if (is_array($value) && array_is_list($value)) {
      $cardinality = $definition->getFieldStorageDefinition()->getCardinality();

      // LLMs commonly send [{...}] instead of {...} for single-value fields.
      if ($cardinality === 1 && count($value) === 1) {
        return new FieldHandlerResult($this->normalizeItem($value[0]));
      }

      return new FieldHandlerResult(array_map($this->normalizeItem(...), $value));
    }

    return new FieldHandlerResult($this->normalizeItem($value));
  }

  /**
   * Normalizes one link item to storage shape.
   *
   * @param mixed $item
   *   A bare string or a {uri, title, options} array.
   *
   * @return mixed
   *   The normalized item: always an array with a storage-grammar uri, with
   *   any read-only "_displayable" enrichment stripped. Unrecognized shapes
   *   pass through for field validation to reject.
   */
  private function normalizeItem(mixed $item): mixed {
    if (is_string($item)) {
      return ['uri' => self::getUserEnteredStringAsUri($item)];
    }

    if (is_array($item)) {
      // Read payloads carry the computed "_displayable" enrichment; write
      // payloads spread the snapshot back, so it must be ignored here.
      unset($item['_displayable']);

      if (isset($item['uri']) && is_string($item['uri'])) {
        $item['uri'] = self::getUserEnteredStringAsUri($item['uri']);
      }
    }

    return $item;
  }

  /**
   * Gets a user-entered string as a storage uri.
   *
   * Replicates LinkWidget::getUserEnteredStringAsUri() minus its
   * "Label (42)" autocomplete parsing: that grammar is core widget
   * vocabulary (and hardcoded to nodes there); entity links arrive here
   * structurally as "entity:TYPE/ID".
   *
   * @param string $string
   *   The user-entered string. Storage uris pass through unchanged.
   *
   * @return string
   *   The storage uri.
   *
   * @see \Drupal\link\Plugin\Field\FieldWidget\LinkWidget::getUserEnteredStringAsUri()
   */
  private static function getUserEnteredStringAsUri(string $string): string {
    // By default, assume the entered string is a URI.
    $uri = trim($string);

    // Support linking to nothing.
    if (in_array($uri, ['<nolink>', '<none>', '<button>'], TRUE)) {
      return 'route:' . $uri;
    }

    // Detect a schemeless string, map to 'internal:' URI.
    if ($uri !== '' && parse_url($uri, PHP_URL_SCHEME) === NULL) {
      // '<front>' -> '/', '<front>#foo' -> '/#foo'.
      if (str_starts_with($uri, '<front>')) {
        $uri = '/' . substr($uri, strlen('<front>'));
      }
      return 'internal:' . $uri;
    }

    return $uri;
  }

  /**
   * Gets a storage uri as a displayable string.
   *
   * Replicates LinkWidget::getUriAsDisplayableString(), minus its node-only
   * limitation on "entity:" uris: any loadable entity type resolves to
   * "Label (id)".
   *
   * @param string $uri
   *   The storage uri.
   *
   * @return string
   *   The uri the way a person would type it: "internal:" uris as the bare
   *   path ("internal:/" as "<front>"), "entity:" uris as "Label (id)",
   *   "route:" uris as their "<nolink>" family token. Anything else (and any
   *   "entity:" uri that does not resolve) is the uri itself.
   *
   * @see \Drupal\link\Plugin\Field\FieldWidget\LinkWidget::getUriAsDisplayableString()
   */
  private function getUriAsDisplayableString(string $uri): string {
    $scheme = parse_url($uri, PHP_URL_SCHEME);

    if ($scheme === 'internal' || $scheme === 'route') {
      return static::getUriAsAuthorableString($uri);
    }

    if ($scheme === 'entity' && str_contains(substr($uri, 7), '/')) {
      [$entity_type_id, $entity_id] = explode('/', substr($uri, 7), 2);
      if ($this->entityTypeManager->hasDefinition($entity_type_id)
        && $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id)) {
        return EntityAutocomplete::getEntityLabels([$entity]);
      }
      return $uri;
    }

    return $uri;
  }

  /**
   * Gets a storage uri as the string a writer would author.
   *
   * The write grammar's inverse: exactly what getUserEnteredStringAsUri()
   * accepts, so a print of a stored link can be written back verbatim.
   * "internal:" uris become the bare path ("internal:/" is "<front>"),
   * "route:" uris their "<nolink>" family token; everything else
   * ("entity:TYPE/ID", external urls) is already authorable and passes
   * through. Unlike the displayable form, an "entity:" uri is NOT turned
   * into "Label (id)": that widget vocabulary is not accepted on write.
   *
   * @param string $uri
   *   The storage uri (an already-authorable string passes through).
   *
   * @return string
   *   The authorable string.
   */
  public static function getUriAsAuthorableString(string $uri): string {
    $scheme = parse_url($uri, PHP_URL_SCHEME);

    if ($scheme === 'internal') {
      $uri_reference = explode(':', $uri, 2)[1];
      // '<front>' is valid input for BC reasons in core; author it back.
      if (parse_url($uri, PHP_URL_PATH) === '/') {
        $uri_reference = '<front>' . substr($uri_reference, 1);
      }
      return $uri_reference;
    }

    if ($scheme === 'route') {
      return substr($uri, strlen('route:'));
    }

    return $uri;
  }

}
