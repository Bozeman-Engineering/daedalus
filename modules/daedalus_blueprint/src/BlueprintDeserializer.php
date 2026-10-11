<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\CapabilityResolver;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Utility\ElementKey;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\daedalus_blueprint\Validator\SemanticValidator;
use Drupal\daedalus_blueprint\Validator\StructuralValidator;
use Psr\Log\LoggerInterface;

/**
 * Deserializes blueprint JSON into Drupal entities.
 *
 * Performs phased validation: structural (Phase A) then semantic with entity
 * hydration (Phase B). Returns a BlueprintResult containing the unsaved
 * entity or collected errors.
 *
 * The whole-document path is an op door like the batch: it asks the
 * capability resolver at the root (content for the fields it writes,
 * arrange and insert for a layout) and style of every element a styles
 * entry addresses, for the batch's actor (`actor` option, the current
 * user by default); a `system` option skips the gate, as a system batch
 * does.
 *
 * The layout is the lean node tree (DocumentInstantiator's grammar: kind
 * nodes, region nodes for a multi-region layout's slots, styles on the
 * node), written once, at creation: the tree expands through the
 * instantiator with the author's uuids kept and lands as one dressed
 * batch of the ordinary operations, so everything the spine enforces
 * applies with no second validation surface. A created entity carrying a
 * layout is not seeded from its display's template: a page's starting
 * layout has exactly one source. A layout arriving for an existing entity
 * (_root_entity) is refused with the ops road named; a fields-only update
 * is unaffected. The node styles and the top-level `styles` map merge into
 * one map (a key stated in both places is refused) before the style
 * store's one validation path, its field handler.
 *
 * validate_only runs everything the write would on a disposable object (a
 * new entity, or a clone of the loaded one), so a create's layout expands
 * as a batch on the unsaved host and the merged style map is judged
 * against the elements it landed; the result carries no entity and
 * nothing persists.
 */
class BlueprintDeserializer implements BlueprintDeserializerInterface {

  /**
   * Constructs a BlueprintDeserializer.
   *
   * @param \Drupal\daedalus_blueprint\Validator\StructuralValidator $structuralValidator
   *   The structural validator.
   * @param \Drupal\daedalus_blueprint\Validator\SemanticValidator $semanticValidator
   *   The semantic validator.
   * @param \Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager $fieldHandlerManager
   *   The field handler manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator $instantiator
   *   The document instantiator: the whole-layout write.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The uuid generator, for a layout node that names no identity.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\daedalus_blueprint\Host\HostResolver $hostResolver
   *   The host resolver.
   * @param \Drupal\daedalus_blueprint\Capability\CapabilityResolver $capabilityResolver
   *   The capability resolver, asked at the root and per styled element.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user, the actor when the options name none.
   */
  public function __construct(
    protected StructuralValidator $structuralValidator,
    protected SemanticValidator $semanticValidator,
    protected FieldHandlerManager $fieldHandlerManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DocumentInstantiator $instantiator,
    protected UuidInterface $uuid,
    protected LoggerInterface $logger,
    protected HostResolver $hostResolver,
    protected CapabilityResolver $capabilityResolver,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function deserialize(array $data, array $options = []): BlueprintResult {
    $validate_only = !empty($options['validate_only']);

    // Phase A: Structural validation.
    $errors = $this->structuralValidator->validate($data);
    if (!empty($errors)) {
      $this->logger->notice('Structural validation failed for @type:@bundle with @count error(s).', [
        '@type' => $data['entity_type'] ?? 'unknown',
        '@bundle' => $data['bundle'] ?? 'unknown',
        '@count' => count($errors),
      ]);
      return BlueprintResult::structuralFailure($errors);
    }

    // Phase B: Semantic validation + hydration.
    $entity_type = $data['entity_type'];
    $bundle = $data['bundle'];
    $langcode = $data['langcode'] ?? NULL;
    $fields = $data['fields'] ?? [];
    $is_full_create = !isset($data['_root_entity']);
    $layout = isset($data['layout']) && is_array($data['layout']) ? $data['layout'] : NULL;

    // Form-only stubs (FULL-mode structure completeness) are identity, not
    // data: drop them from the submitted set so a FULL blueprint echoed
    // back neither writes stubs into fields nor scopes constraint
    // validation to fields it never touched, the same PATCH semantics as
    // an omitted key.
    $fields = array_filter($fields, fn($value) => !FormOnlyStub::isStub($value));

    // Load or create entity. A dry run over an existing entity works on a
    // clone: the loaded object is the request's shared one.
    $entity_or_error = $this->loadOrCreateEntity($entity_type, $bundle, $data, disposable: $validate_only);
    if ($entity_or_error instanceof BlueprintError) {
      return BlueprintResult::semanticFailure([$entity_or_error]);
    }
    $entity = $entity_or_error;

    // Handle langcode/translation.
    $is_non_default_translation = FALSE;
    if ($langcode !== NULL && $entity instanceof TranslatableInterface) {
      $default_langcode = $entity->getUntranslated()->language()->getId();
      if ($langcode !== $default_langcode) {
        $is_non_default_translation = TRUE;
        if (!$entity->hasTranslation($langcode)) {
          $entity = $entity->addTranslation($langcode);
        }
        else {
          $entity = $entity->getTranslation($langcode);
        }
      }
    }
    // The host of the object the writes land on (the translation when one
    // was named; the document is one storage shared by every translation).
    $host = $this->hostResolver->fromEntity($entity);

    // A created entity carrying a layout has its starting document here,
    // not in its display's template: the seed is declined before any read
    // of the storage could run it.
    if ($layout !== NULL && $is_full_create) {
      $host->declineSeed();
    }

    // The capability gate at the root: a whole document is a content write
    // of the host (the host access and workspace policies answer here, as
    // they do for every op), judged for the batch's actor unless the
    // caller acts for the site.
    $actor = $this->actor($options);
    if ($actor !== NULL) {
      $refused = $this->refusal($host, $actor, Capability::Content, $host->uuid(), 'entity_type');
      if ($refused !== NULL) {
        return BlueprintResult::semanticFailure([$refused]);
      }
    }

    // Field-level access check. The document never sits in fields: the
    // layout carries no per-user field signal (core hardcodes the section
    // list's edit access to forbidden for everyone, LayoutSectionItemList::
    // defaultAccess), so per-entity layout access is the capability gate
    // above and below (mirroring core's OverridesSectionStorage) and the
    // layout itself lands through the op door below; the style map is
    // gated on its own store below. The system posture names no actor to
    // judge, here as at the gate above; the handlers receive it through
    // the options.
    $field_access_errors = $actor === NULL ? [] : $this->semanticValidator->validateFieldAccess(
      $entity,
      array_keys($fields),
    );
    $denied_field_names = array_map(
      fn(BlueprintError $error) => str_replace('fields.', '', $error->getPath()),
      $field_access_errors,
    );

    // Apply fields via field handlers.
    $errors = $field_access_errors;
    $warnings = [];
    $error_field_names = $denied_field_names;
    $processable_fields = $this->fieldHandlerManager->getProcessableFieldDefinitions($entity);

    foreach ($fields as $field_name => $value) {
      // Skip fields the user can't access.
      if (in_array($field_name, $denied_field_names, TRUE)) {
        continue;
      }

      if (!isset($processable_fields[$field_name])) {
        continue;
      }

      $definition = $processable_fields[$field_name];

      // For non-default translations, skip non-translatable fields.
      if ($is_non_default_translation && !$definition->isTranslatable()) {
        continue;
      }

      $handler = $this->fieldHandlerManager->getHandler($definition);
      $handler_result = $handler->deserialize($value, $definition, [
        'host' => $host,
        'entity' => $entity,
        'fields' => $fields,
        'element_uuid' => $entity->uuid(),
      ] + $options);

      if ($handler_result->hasErrors()) {
        array_push($errors, ...$handler_result->getErrors());
        $error_field_names[] = $field_name;
      }
      else {
        $entity->set($field_name, $handler_result->getValue());
      }

      array_push($warnings, ...$handler_result->getWarnings());
    }

    // The layout rides as `layout`, the lean node tree, written through the
    // host at creation: arrange and insert at the root (a non-default
    // translation and a plain host rendering its template are refused
    // there, by the resolver's policies), then the tree expands through
    // the instantiator with the author's uuids kept and its structural
    // operations run as one dressed batch on the unsaved host; its node
    // styles join the style map below. An existing entity's document is
    // not replaced: it changes through operations. A host with no document
    // at all has nowhere to put it.
    $node_styles = [];
    $layout_landed = TRUE;
    if ($layout !== NULL) {
      $layout_landed = FALSE;
      $refused = $actor === NULL ? NULL : ($this->refusal($host, $actor, Capability::Arrange, $host->uuid(), 'layout') ?? $this->refusal($host, $actor, Capability::Insert, $host->uuid(), 'layout'));
      if ($refused !== NULL) {
        $errors[] = $refused;
      }
      elseif (!$is_full_create) {
        $errors[] = new BlueprintError(
          path: 'layout',
          code: 'LAYOUT_ON_EXISTING_ENTITY',
          message: 'A whole layout is written only when the entity is created; an existing entity\'s document is changed through operations.',
          hint: 'Omit layout to update the fields alone, and change the document through the ops road: add_section, add_element, move_element, remove_element and the style ops in one batch (eb_edit), or page_edit on a page-language site.',
        );
      }
      elseif (!$host->hasDocument() || $this->hostResolver->foreignOwner($host) !== NULL) {
        // A whole layout lands only on a host owning the whole document
        // it renders: a page rendered inside its template (a locked
        // bundle) owns no more than its slots there.
        $errors[] = new BlueprintError(
          path: 'layout',
          code: 'NO_LAYOUT',
          message: $host->hasDocument()
            ? sprintf('%s %s has no page document of its own to author: its render is its template\'s.', $host->entityTypeId(), $host->bundle())
            : 'Entity does not have a Layout Builder layout.',
          hint: $host->hasDocument()
            ? 'Omit layout for a bundle whose pages share the template\'s layout; edit the layout on the template.'
            : 'Enable Layout Builder on this entity type, or omit layout.',
        );
      }
      else {
        $expansion = $this->instantiator->expand($host, $layout, ['anchor' => $host->uuid(), 'placement' => 'inside'], [
          // The author's identity is the identity: a node naming a uuid
          // keeps it (a later styles key or operation may address it), a
          // node naming none is minted here.
          'mint' => fn (?string $source): string => $source ?? $this->uuid->generate(),
          'path' => 'layout',
          'indexed' => TRUE,
        ]);
        if (!$expansion->isValid()) {
          array_push($errors, ...$expansion->errors);
        }
        else {
          $layout_result = $this->instantiator->run($host, $expansion->structuralOperations(), $expansion->structuralPaths(), [
            'batch' => array_diff_key($options, ['validate_only' => TRUE]),
          ]);
          array_push($warnings, ...$layout_result->getWarnings());
          if ($layout_result->isSuccess()) {
            $layout_landed = TRUE;
            $node_styles = $expansion->styles;
          }
          else {
            array_push($errors, ...$layout_result->getErrors());
          }
        }
      }
    }

    // The style map: the node styles (keyed by the absolute key minted
    // for each node) and the top-level `styles` map merge into one map, a
    // key stated in both places refused, written through the host. Its
    // one validation seam is the field handler of the host's style store
    // (the resolver's check, the map validator, the canonical condition
    // spelling), with its field-path errors re-rooted at `styles`. Every
    // entry asks style of the element its key addresses first: a template
    // element's entry on a plain host, or any entry on a non-default
    // translation (the map keys into the one layout every translation
    // shares), is refused at the door. A map over a layout that did not
    // land is not judged: its keys name elements that never came to be.
    $validated_fields = array_keys($fields);
    $styles = array_key_exists('styles', $data) ? $data['styles'] : NULL;
    if ($layout_landed && ($styles !== NULL || $node_styles !== [])) {
      $map = is_array($styles) ? $styles : [];
      foreach ($node_styles as $key => $entry) {
        if (array_key_exists($key, $map)) {
          $errors[] = new BlueprintError(
            path: "styles.$key",
            code: 'DUPLICATE_STYLE_KEY',
            message: sprintf('The styles of "%s" are stated twice: on its node in layout and in the top-level styles map.', $key),
            hint: 'State an element\'s styles once: on its node (keyed by the element-relative key) or in the top-level styles map (keyed by the absolute key), never both.',
          );
          continue;
        }
        $map[$key] = $entry;
      }
      $definition = $host->styleMapDefinition();
      if ($definition === NULL) {
        $errors[] = new BlueprintError(
          path: 'styles',
          code: 'NO_STYLE_STORE',
          message: 'This entity carries no style map.',
          hint: 'Enable styling for the bundle, or omit styles (on the nodes and at the top level).',
        );
      }
      else {
        $store_name = $definition->getName();
        $reroot = static fn (string $path): string => 'styles' . substr($path, strlen("fields.$store_name"));
        $style_refusals = [];
        if ($actor !== NULL) {
          foreach (array_keys($map) as $key) {
            $element = is_string($key) ? ElementKey::uuid($key) : NULL;
            $refused = $element === NULL ? NULL : $this->refusal($host, $actor, Capability::Style, $element, "styles.$key");
            if ($refused !== NULL) {
              $style_refusals[] = $refused;
            }
          }
        }
        $style_access_errors = $style_refusals === [] && $actor !== NULL ? $this->semanticValidator->validateFieldAccess($entity, [$store_name]) : [];
        if ($style_refusals !== []) {
          array_push($errors, ...$style_refusals);
        }
        elseif ($style_access_errors !== []) {
          array_push($errors, ...array_map(static fn (BlueprintError $error) => $error->withPath($reroot($error->getPath())), $style_access_errors));
        }
        else {
          $handler = $this->fieldHandlerManager->getHandler($definition);
          $handler_result = $handler->deserialize($map, $definition, [
            'host' => $host,
            'entity' => $entity,
            'fields' => $fields,
            'element_uuid' => $entity->uuid(),
          ] + $options);
          if ($handler_result->hasErrors()) {
            array_push($errors, ...array_map(static fn (BlueprintError $error) => $error->withPath($reroot($error->getPath())), $handler_result->getErrors()));
            $error_field_names[] = $store_name;
          }
          else {
            $value = $handler_result->getValue();
            $host->setStyleMap(is_string($value) ? (json_decode($value, TRUE) ?: []) : []);
            $validated_fields[] = $store_name;
          }
          array_push($warnings, ...array_map(static fn (BlueprintWarning $warning) => new BlueprintWarning($reroot($warning->getPath()), $warning->getCode(), $warning->getMessage()), $handler_result->getWarnings()));
        }
      }
    }

    // Run constraint validation (scoped to the submitted fields and the
    // style store when styles were written).
    $constraint_errors = $this->semanticValidator->validateConstraints(
      $entity,
      $validated_fields,
      $error_field_names,
      $is_full_create,
      $actor === NULL,
    );
    $errors = array_merge($errors, $constraint_errors);

    if (!empty($errors)) {
      $this->logger->notice('Semantic validation failed for @type:@bundle with @count error(s).', [
        '@type' => $entity_type,
        '@bundle' => $bundle,
        '@count' => count($errors),
      ]);
      return BlueprintResult::semanticFailure($errors, $warnings);
    }

    if ($validate_only) {
      return BlueprintResult::validated($warnings);
    }

    $operation = $is_full_create ? 'created' : 'updated';
    $warning_count = count($warnings);
    $this->logger->info('Successfully deserialized @type:@bundle (@operation) with @warnings warning(s).', [
      '@type' => $entity_type,
      '@bundle' => $bundle,
      '@operation' => $operation,
      '@warnings' => $warning_count,
    ]);
    return BlueprintResult::success($host, $warnings);
  }

  /**
   * The actor the document is judged for, or NULL under the system posture.
   *
   * @param array $options
   *   The deserialization options: `actor` (an account; the current user
   *   when absent) and `system` (TRUE skips the gate, as a system batch
   *   does: the seed, an importer).
   *
   * @return \Drupal\Core\Session\AccountInterface|null
   *   The actor, or NULL when nothing is judged.
   */
  protected function actor(array $options): ?AccountInterface {
    if (!empty($options['system'])) {
      return NULL;
    }
    $actor = $options['actor'] ?? NULL;
    if ($actor !== NULL && !$actor instanceof AccountInterface) {
      throw new \InvalidArgumentException('The actor option is an account (AccountInterface).');
    }
    return $actor ?? $this->currentUser;
  }

  /**
   * The resolver's refusal of a capability as a document error, or NULL.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   The actor.
   * @param \Drupal\daedalus_blueprint\Capability\Capability $capability
   *   The capability asked.
   * @param string $target
   *   The element uuid, or the host's uuid for the root.
   * @param string $path
   *   The document path the refusal is reported at.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError|null
   *   The error, or NULL when allowed.
   */
  protected function refusal(HostInterface $host, AccountInterface $actor, Capability $capability, string $target, string $path): ?BlueprintError {
    return $this->capabilityResolver->verdict($host, $actor, $capability, $target)->refusal()?->toError($path);
  }

  /**
   * Loads an existing entity or creates a new one.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param array $data
   *   The full blueprint data (checked for _root_entity).
   * @param bool $disposable
   *   TRUE to answer a clone of a loaded entity (a dry run never touches
   *   the request's shared object); a created entity is disposable as is.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|\Drupal\daedalus_blueprint\Result\BlueprintError
   *   The entity, or an error if _root_entity references a nonexistent entity.
   */
  protected function loadOrCreateEntity(string $entity_type, string $bundle, array $data, bool $disposable = FALSE): FieldableEntityInterface|BlueprintError {
    $storage = $this->entityTypeManager->getStorage($entity_type);

    if (isset($data['_root_entity'])) {
      $field = $data['_root_entity']['field'];
      $value = $data['_root_entity']['value'];

      $entities = $storage->loadByProperties([$field => $value]);
      if (empty($entities)) {
        return new BlueprintError(
          path: '_root_entity',
          code: 'ENTITY_NOT_FOUND',
          message: "No $entity_type entity found with $field = $value.",
          hint: 'Verify the entity exists and the identifier is correct.',
        );
      }

      $entity = reset($entities);
      return $disposable ? clone $entity : $entity;
    }

    // Create new entity.
    $values = [];
    $bundle_key = $this->entityTypeManager->getDefinition($entity_type)->getKey('bundle');
    if ($bundle_key) {
      $values[$bundle_key] = $bundle;
    }

    return $storage->create($values);
  }

}
