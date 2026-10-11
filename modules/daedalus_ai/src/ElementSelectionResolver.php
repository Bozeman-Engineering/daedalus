<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\daedalus_blueprint\BlueprintElementResolverInterface;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Materials\ElementMaterials;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\ResolvedElement;

/**
 * Resolves selected spine keys into structured element descriptions.
 *
 * The wire format is the selection spine's own identity: each selected
 * element arrives as {key, label, number}, where `key` is a selectable key
 * (the data-selectable vocabulary — the same string identity that syncs the
 * canvas, the panel, and the chat chips). This resolver parses the key and
 * resolves it against the route entity's HOST via the element resolver;
 * it never loads arbitrary entities from client-supplied identifiers, so a
 * crafted key can only ever describe what the root entity already reaches
 * (the element resolver access-gates field values itself). Keys that do not
 * resolve fall back to key-vocabulary descriptions with no entity metadata.
 *
 * Key formats (see daedalus_blueprint_api's SelectableKey):
 * - entity:{entity_uuid} — a rendered entity root
 * - field:{entity_uuid}:{name}[:{delta}] — a field of an entity
 * - container:{section_uuid} — a Layout Builder section (a container at any
 *   depth; regions have no keys, a container's children are one ordered flow)
 * - block:{component_uuid} — a Layout Builder placement
 * - block:{component_uuid}:{property} — an inline block field, or `label`:
 *   the placement's display label rendered as page content.
 *
 * A selection may carry two styling FACTS, as the selection spine does.
 * The SUBJECT facet is what the user is looking at ON the selected
 * element (a part, a tag tier, a chain): the chips send it as a
 * `selector` beside the element's key (the relative selector in its
 * canonical spelling; a `{key}::{selector}` key spells the same subject).
 * It rides the description as `target`, and the worn materials are read
 * AT the subject (ElementMaterials::worn() reads a rule's own references
 * beneath its root's), so a rule that wears a variant is seen wearing it.
 * The WRITE ADDRESS is where styling acts: a rule on the element or on an
 * ancestor of it ("every list item in Welcome"), sent as `address`
 * ({key, selector}) and riding the description as its own sentence
 * (`address`) naming the rule, its owner and the style-map key, so the AI
 * writes at the rule the user picked rather than on the element. The
 * element described is ALWAYS the selection's own key: an address never
 * takes its place. A selector outside the grammar is dropped (the subject
 * alone, or the address alone), as is an address whose owner key is not
 * in the vocabulary: nothing crafted reaches the stack read or the
 * prompt.
 *
 * Beside its identity, each description names the materials the element
 * WEARS (its worn variants by label, each with the origin of the wearing,
 * read from its style stack) and the blueprint it was PLACED FROM (the
 * placement's provenance stamp by id and label), both read from the
 * STAGED copy of the root entity, because the canvas shows staged state:
 * "the wide one", "what do these share" and "update the blueprint this
 * came from" resolve through labels the print never carries and without a
 * print read. Labels and origins only, never values: what the stack
 * delivers is explain_element_styles' answer. Per-turn facts, allowed on
 * the message and barred from the cached prefix.
 */
class ElementSelectionResolver {

  public function __construct(
    protected HostResolver $hostResolver,
    protected ?BlueprintElementResolverInterface $elementResolver = NULL,
    protected ?ElementMaterials $elementMaterials = NULL,
    protected ?EntityStorageHandler $storageHandler = NULL,
  ) {}

  /**
   * Resolves selected elements to description arrays.
   *
   * @param array $selectedElements
   *   Raw selection data from the client, each with 'key' (a selectable
   *   key, or a `{key}::{selector}` subject), 'number', optionally
   *   'label', optionally 'selector' (the subject facet's relative
   *   selector under the key) and optionally 'address' (the write
   *   address as {key, selector}, its key the element or an ancestor).
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The route host (the owner of the canvas the selection was made on: a
   *   page's entity or a template's display), or NULL for none.
   *
   * @return array<int, array{description: string, target?: string, address?: string, materials?: string, placed_from?: string}>
   *   Resolved elements keyed by selection number.
   */
  public function resolve(array $selectedElements, ?HostInterface $host): array {
    $resolved = [];
    $staged = $this->stagedCopy($host);

    foreach ($selectedElements as $element) {
      $key = $element['key'] ?? NULL;
      $number = $element['number'] ?? NULL;

      if (!is_string($key) || $key === '' || !$number) {
        continue;
      }

      [$key, $selector] = $this->subject($key, $element['selector'] ?? NULL);
      $result = $this->resolveKey($key, $host);
      if ($result) {
        $address = $selector === NULL ? $key : $key . RelativeSelector::SUFFIX . $selector;
        $write = $this->writeAddress($element['address'] ?? NULL, $key, $host);
        if ($selector !== NULL) {
          $result['target'] = $write === NULL
            ? sprintf('the rule "%s" under it (style-map key %s): styling acts on every element the rule matches, not on this element alone', $selector, $address)
            : sprintf('the rule "%s" under it (style-map key %s): the elements whose styles the user is reading; styling acts at the address below, not here', $selector, $address);
        }
        if ($write !== NULL) {
          $result['address'] = $write;
        }
        $materials = $this->wornMaterials($staged, $address);
        if ($materials !== '') {
          $result['materials'] = $materials;
        }
        $placed_from = $this->placedFrom($staged, $key);
        if ($placed_from !== '') {
          $result['placed_from'] = $placed_from;
        }
        $resolved[$number] = $result;
      }
    }

    ksort($resolved);
    return $resolved;
  }

  /**
   * The element key and the subject facet's selector a selection entry spells.
   *
   * Two spellings of one subject: a `{key}::{selector}` key, or a bare
   * key with a `selector` field (the chips' wire). The selector must be
   * a relative selector in the grammar (RelativeSelector::parse(),
   * canonicalized); one outside it is dropped, so the element is described
   * alone and nothing malformed reaches the stack read. A selector field
   * beside a `::` key would be two answers, so the key's own wins.
   *
   * @return array{0: string, 1: ?string}
   *   The element key and the canonical selector, or NULL for none.
   */
  protected function subject(string $key, mixed $selector): array {
    $pos = strpos($key, RelativeSelector::SUFFIX);
    if ($pos !== FALSE) {
      $selector = substr($key, $pos + strlen(RelativeSelector::SUFFIX));
      $key = substr($key, 0, $pos);
    }
    if (!is_string($selector) || $selector === '') {
      return [$key, NULL];
    }
    $relative = RelativeSelector::parse($selector);
    return [$key, $relative === NULL ? NULL : (string) $relative];
  }

  /**
   * The write address sentence a selection entry's `address` spells.
   *
   * The address is {key, selector}: the rule styling acts at, on the
   * element itself ("every paragraph here") or on an ancestor of it
   * ("every list item in Welcome"). The selector must be in the grammar
   * and the owner key in the vocabulary (resolveKey() answers for it, the
   * same access net as the element's own: never a load from a
   * client-supplied identifier); anything else drops the address, so a
   * crafted one never reaches the prompt. The owner is named by the first
   * clause of its own description (its kind and label; the style-map key
   * carries its identity in full), so the AI knows WHOSE rule it writes
   * at without the whole description repeated.
   *
   * @param mixed $address
   *   The raw `address` field.
   * @param string $key
   *   The selected element's key (the owner of a same-key address).
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The route host.
   *
   * @return string|null
   *   The sentence, or NULL for no (usable) address.
   */
  protected function writeAddress(mixed $address, string $key, ?HostInterface $host): ?string {
    if (!is_array($address) || !is_string($address['key'] ?? NULL) || $address['key'] === '' || !is_string($address['selector'] ?? NULL) || $address['selector'] === '') {
      return NULL;
    }
    $relative = RelativeSelector::parse($address['selector']);
    if ($relative === NULL) {
      return NULL;
    }
    $selector = (string) $relative;
    $mapKey = $address['key'] . RelativeSelector::SUFFIX . $selector;
    if ($address['key'] === $key) {
      return sprintf('styling acts at the rule "%s" on this element (style-map key %s): every element it matches, this one included', $selector, $mapKey);
    }
    $owner = $this->resolveKey($address['key'], $host);
    if ($owner === NULL) {
      return NULL;
    }
    $ownerName = rtrim(explode('. ', $owner['description'], 2)[0], '.');
    return sprintf('styling acts at the rule "%s" on its ancestor %s (style-map key %s): every element it matches, this one included', $selector, $ownerName, $mapKey);
  }

  /**
   * The host's staged copy (the canvas state), or the host itself.
   */
  protected function stagedCopy(?HostInterface $host): ?HostInterface {
    if ($host === NULL || $this->storageHandler === NULL) {
      return $host;
    }
    return $this->storageHandler->load($host->entityTypeId(), (string) $host->id()) ?? $host;
  }

  /**
   * What the element wears, as prose, one wearing per clause.
   *
   * A wearing applied here reads id "Label" (spelling), the identity the
   * page language writes; a wearing a composition distributed reads id
   * "Label" via composition "Label", and a fragment of a composition reads
   * fragment {slot} of id "Label": neither is the element's own to write
   * (the composition on the root put it there), so neither shows a
   * spelling, and the origin names what to change instead. A stale origin
   * prints by machine name. Only keys naming the root entity's own
   * elements are read (the style map is the root's), so a crafted key can
   * describe nothing the root does not already reach.
   */
  protected function wornMaterials(?HostInterface $staged, string $key): string {
    if ($staged === NULL || $this->elementMaterials === NULL) {
      return '';
    }
    $clauses = [];
    foreach ($this->elementMaterials->worn($staged, $key) as $wearing) {
      $record = $wearing->record;
      $clauses[] = match (TRUE) {
        $wearing->isFragment() => sprintf('fragment %s of %s "%s"', $wearing->fragment, $record->id, $record->label),
        $wearing->via !== NULL => sprintf('%s "%s" via %s', $record->id, $record->label, $this->materialName($wearing->via, $wearing->viaLabel)),
        default => MaterialsRoster::describe([$record], FALSE),
      };
    }
    return implode('; ', $clauses);
  }

  /**
   * A material's name for the reader: id "Label", or the bare id.
   *
   * The bare machine name when the roster no longer labels it (a stale
   * origin: inert, never hidden).
   */
  protected function materialName(string $id, ?string $label): string {
    return $label === NULL ? $id : sprintf('%s "%s"', $id, $label);
  }

  /**
   * The blueprint the element was placed from, as prose: id "Label".
   *
   * Identity only, no spelling: provenance is a fact to target (update the
   * blueprint by id), never a placement to write back, and a stamp the
   * roster no longer serves is not named (ElementMaterials::placedFrom()).
   * Reads the same staged copy the worn materials do.
   */
  protected function placedFrom(?HostInterface $staged, string $key): string {
    if ($staged === NULL || $this->elementMaterials === NULL) {
      return '';
    }
    $blueprint = $this->elementMaterials->placedFrom($staged, $key);
    return $blueprint === NULL ? '' : sprintf('%s "%s"', $blueprint->id, $blueprint->label);
  }

  /**
   * Resolves a single element key, never a `::` key.
   *
   * The key is the selection's own or a write address's owner.
   *
   * @param string $key
   *   The selectable key.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The route entity's host, or NULL when the route has none.
   *
   * @return array{description: string}|null
   *   The description array, or NULL for unusable keys.
   */
  protected function resolveKey(string $key, ?HostInterface $host): ?array {
    $segments = explode(':', $key);
    $prefix = $segments[0];

    return match ($prefix) {
      'entity' => isset($segments[1]) ? $this->resolveEntity($segments[1], $host) : NULL,
      'field' => isset($segments[1], $segments[2]) ? $this->resolveField($segments[1], $segments[2], $host) : NULL,
      'container' => isset($segments[1]) ? $this->resolveByUuid($segments[1], $host, sprintf('Container (UUID: %s)', $segments[1])) : NULL,
      'block' => isset($segments[1]) ? $this->resolveBlock($segments[1], $segments[2] ?? NULL, $host) : NULL,
      default => NULL,
    };
  }

  /**
   * Resolves an entity: key — the page root or a nested entity (paragraph).
   */
  protected function resolveEntity(string $uuid, ?HostInterface $host): ?array {
    if ($host && $uuid === $host->uuid()) {
      return [
        'description' => sprintf(
          'The whole %s "%s" (ID: %s) — the page itself.',
          $host->entityTypeId(),
          $host->label(),
          $host->id(),
        ),
      ];
    }

    return $this->resolveByUuid($uuid, $host, sprintf('Element (UUID: %s)', $uuid));
  }

  /**
   * Resolves a field: key — a field of the root entity or a paragraph.
   */
  protected function resolveField(string $entityUuid, string $fieldName, ?HostInterface $host): ?array {
    if ($host && $entityUuid === $host->uuid()) {
      return $this->resolveNamed($fieldName, NULL, $host, sprintf('Field "%s" on this page', $fieldName));
    }

    // A paragraph's field: the qualifier is the paragraph's entity UUID,
    // which the locator resolves within the root entity.
    return $this->resolveNamed($fieldName, $entityUuid, $host, sprintf('Field "%s" (parent UUID: %s)', $fieldName, $entityUuid));
  }

  /**
   * Resolves a block: key — a placement or one of its named properties.
   */
  protected function resolveBlock(string $componentUuid, ?string $property, ?HostInterface $host): ?array {
    if ($property === NULL) {
      return $this->resolveByUuid($componentUuid, $host, $this->blockFallback($componentUuid, $host));
    }

    // The placement's display label renders as page content (heading-style
    // blocks). It is component configuration, not a field, so describe
    // it against the resolved placement.
    if ($property === 'label') {
      return $this->resolveBlockLabel($componentUuid, $host);
    }

    return $this->resolveNamed($property, $componentUuid, $host, sprintf('Field "%s" on block (UUID: %s)', $property, $componentUuid));
  }

  /**
   * Resolves a UUID through the element resolver with a fallback description.
   */
  protected function resolveByUuid(string $uuid, ?HostInterface $host, string $fallback): array {
    if ($host && $this->elementResolver) {
      $resolved = $this->elementResolver->resolve($host, $uuid);
      if ($resolved) {
        return $this->formatResolvedElement($resolved);
      }
    }
    return ['description' => $fallback];
  }

  /**
   * Resolves a named element (field/region) with a fallback description.
   */
  protected function resolveNamed(string $name, ?string $parentUuid, ?HostInterface $host, string $fallback): array {
    if ($host && $this->elementResolver) {
      $resolved = $this->elementResolver->resolve($host, $name, $parentUuid);
      if ($resolved) {
        return $this->formatResolvedElement($resolved);
      }
    }
    return ['description' => $fallback];
  }

  /**
   * Describes a block placement's display label (block:{uuid}:label).
   */
  protected function resolveBlockLabel(string $componentUuid, ?HostInterface $host): array {
    if ($host && $this->elementResolver) {
      $resolved = $this->elementResolver->resolve($host, $componentUuid);
      if ($resolved) {
        return [
          'description' => sprintf(
            'The displayed label of %s "%s". entity_type: %s, entity_id: %s, uuid: %s.',
            $resolved->elementType,
            $resolved->label,
            $resolved->entityType,
            $resolved->entityId,
            $resolved->uuid,
          ),
        ];
      }
    }

    return ['description' => sprintf('The displayed label of block (UUID: %s)', $componentUuid)];
  }

  /**
   * Builds the fallback description for an unresolved block placement.
   */
  protected function blockFallback(string $blockUuid, ?HostInterface $host): string {
    if ($host) {
      return sprintf(
        'Block (UUID: %s) on %s "%s" (ID: %s)',
        $blockUuid,
        $host->entityTypeId(),
        $host->label(),
        $host->id(),
      );
    }
    return sprintf('Block (UUID: %s)', $blockUuid);
  }

  /**
   * Formats a ResolvedElement into the description array.
   */
  protected function formatResolvedElement(ResolvedElement $element): array {
    $parts = [
      sprintf('%s "%s"', ucfirst($element->elementType), $element->label),
    ];

    if ($element->typeId !== $element->label) {
      $parts[] = sprintf('(type: %s)', $element->typeId);
    }

    if ($element->parentLabel) {
      $uuidSuffix = $element->parentUuid ? ', uuid: ' . $element->parentUuid : '';
      if ($element->parentLabel === $element->parentTypeId) {
        $parts[] = sprintf('on block (type: %s%s)', $element->parentTypeId, $uuidSuffix);
      }
      else {
        $parts[] = sprintf('on block "%s" (type: %s%s)', $element->parentLabel, $element->parentTypeId ?? $element->parentLabel, $uuidSuffix);
      }
    }

    $parts[] = sprintf(
      'entity_type: %s, entity_id: %s',
      $element->entityType,
      $element->entityId,
    );

    if ($element->uuid) {
      $parts[] = sprintf('uuid: %s', $element->uuid);
    }
    if ($element->fieldName) {
      $parts[] = sprintf('field_name: %s', $element->fieldName);
    }

    return ['description' => implode('. ', $parts) . '.'];
  }

}
