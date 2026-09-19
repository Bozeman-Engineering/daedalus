<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutDeserializer;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
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
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\LayoutDeserializer $layoutDeserializer
   *   The layout deserializer.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(
    protected StructuralValidator $structuralValidator,
    protected SemanticValidator $semanticValidator,
    protected FieldHandlerManager $fieldHandlerManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LayoutDeserializer $layoutDeserializer,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function deserialize(array $data, array $options = []): BlueprintResult {
    $validate_only = !empty($options['validate_only']);
    $view_mode = $options['view_mode'] ?? 'full';

    // Phase A: Structural validation.
    $errors = $this->structuralValidator->validate($data, $view_mode);
    if (!empty($errors)) {
      $this->logger->notice('Structural validation failed for @type:@bundle with @count error(s).', [
        '@type' => $data['entity_type'] ?? 'unknown',
        '@bundle' => $data['bundle'] ?? 'unknown',
        '@count' => count($errors),
      ]);
      return BlueprintResult::structuralFailure($errors);
    }

    if ($validate_only) {
      return BlueprintResult::validated();
    }

    // Phase B: Semantic validation + hydration.
    $entity_type = $data['entity_type'];
    $bundle = $data['bundle'];
    $langcode = $data['langcode'] ?? NULL;
    $fields = $data['fields'] ?? [];
    $is_full_create = !isset($data['_root_entity']);

    // Form-only stubs (FULL-mode structure completeness) are identity, not
    // data: drop them from the submitted set so a FULL blueprint echoed
    // back neither writes stubs into fields nor scopes constraint
    // validation to fields it never touched, the same PATCH semantics as
    // an omitted key.
    $fields = array_filter($fields, fn($value) => !FormOnlyStub::isStub($value));

    // Load or create entity.
    $entity_or_error = $this->loadOrCreateEntity($entity_type, $bundle, $data);
    if ($entity_or_error instanceof BlueprintError) {
      return BlueprintResult::semanticFailure([$entity_or_error]);
    }
    $entity = $entity_or_error;

    // Entity-level access check.
    $access_errors = $this->semanticValidator->validateEntityAccess($entity);
    if (!empty($access_errors)) {
      return BlueprintResult::semanticFailure($access_errors);
    }

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

    // Field-level access check. layout_builder__layout is excluded: core
    // hardcodes its edit access to forbidden for everyone
    // (LayoutSectionItemList::defaultAccess), so it carries no per-user signal
    // to honor — the field is meant to be driven through the layout machinery,
    // not generic field access. Per-entity layout-override access is gated by
    // the entity 'update' check above (mirroring core's
    // OverridesSectionStorage), and the layout itself is built by
    // LayoutDeserializer below.
    $access_check_fields = array_diff(
      array_keys($fields),
      ['layout_builder__layout'],
    );
    $field_access_errors = $this->semanticValidator->validateFieldAccess(
      $entity,
      $access_check_fields,
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

    // Process layout_builder__layout if present.
    $layout_data = $data['layout_builder__layout'] ?? $fields['layout_builder__layout'] ?? NULL;
    if ($layout_data !== NULL && is_array($layout_data) && $entity->hasField('layout_builder__layout')) {
      // Translation guard: the layout is ONE structure shared by every
      // translation (core creates the field untranslatable, empties it on
      // translation-create, and denies layout editing on non-default
      // translations). A langcode-scoped layout write would silently mutate
      // the shared layout for all languages, so it is refused, mirroring
      // core's access rule.
      if ($is_non_default_translation) {
        $errors[] = new BlueprintError(
          path: 'layout_builder__layout',
          code: 'LAYOUT_NOT_TRANSLATABLE',
          message: 'The layout is shared across all translations and cannot be written through a non-default translation.',
          hint: 'Write translatable field values with langcode; write the layout without a langcode (it applies to every language).',
        );
      }
      else {
        $layout_result = $this->layoutDeserializer->deserialize($layout_data, $entity, $view_mode, $options);
        $errors = array_merge($errors, $layout_result->getErrors());
        array_push($warnings, ...$layout_result->getWarnings());
      }
    }

    // Run constraint validation (scoped to submitted fields).
    $constraint_errors = $this->semanticValidator->validateConstraints(
      $entity,
      array_keys($fields),
      $error_field_names,
      $is_full_create,
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

    $operation = $is_full_create ? 'created' : 'updated';
    $warning_count = count($warnings);
    $this->logger->info('Successfully deserialized @type:@bundle (@operation) with @warnings warning(s).', [
      '@type' => $entity_type,
      '@bundle' => $bundle,
      '@operation' => $operation,
      '@warnings' => $warning_count,
    ]);
    return BlueprintResult::success($entity, $warnings);
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
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|\Drupal\daedalus_blueprint\Result\BlueprintError
   *   The entity, or an error if _root_entity references a nonexistent entity.
   */
  protected function loadOrCreateEntity(string $entity_type, string $bundle, array $data): FieldableEntityInterface|BlueprintError {
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

      return reset($entities);
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
