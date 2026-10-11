<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\BundlePermissionHandlerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The per-type blueprint permissions, and their one spelling.
 *
 * Three permissions per blueprint type, core's bundle grammar (`create
 * {type} blueprint`, `edit any {type} blueprint`, `delete any {type}
 * blueprint`), derived from the blueprint_type config entities so a type
 * added by config is grantable at once. The spelling has ONE owner,
 * permission(): the access handler judges by it, the "any type" route
 * gate (BlueprintTypeAccess) asks by it, and the recipe's roles
 * ingredient grants the strings it prints.
 *
 * Create governs saving a blueprint of the type: the Save as blueprint
 * verb and the save_material op (the capture names its type, `section`
 * unless told otherwise), and the add page's door into composing one from
 * scratch. Edit governs everything that changes a saved blueprint of the
 * type: update_material and the Update blueprint verb, the details
 * dialog and form, composing on its canvas (with core's override
 * permission on the type's display), adopting, releasing and transferring
 * its owned variants, and seeing it while unpublished. Delete governs
 * removing it, from the listing or by remove_material. Placing a
 * blueprint needs none of these: it is an edit of the host the editor can
 * already edit, and a published blueprint is viewable by everyone, as a
 * palette entry must be.
 */
final class BlueprintPermissions implements ContainerInjectionInterface {

  use BundlePermissionHandlerTrait;
  use StringTranslationTrait;

  /**
   * The operations a type's permissions govern, in the access handler's words.
   */
  public const OPERATIONS = ['create', 'update', 'delete'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * The permission governing an operation on blueprints of a type.
   *
   * @param string $type_id
   *   The blueprint type id.
   * @param string $operation
   *   One of OPERATIONS: create, update or delete.
   *
   * @return string
   *   The permission name.
   */
  public static function permission(string $type_id, string $operation): string {
    return match ($operation) {
      'create' => sprintf('create %s blueprint', $type_id),
      'update' => sprintf('edit any %s blueprint', $type_id),
      'delete' => sprintf('delete any %s blueprint', $type_id),
      default => throw new \InvalidArgumentException(sprintf('"%s" is not a blueprint operation; one of %s.', $operation, implode(', ', self::OPERATIONS))),
    };
  }

  /**
   * The permission callback: three permissions per blueprint type.
   *
   * @return array
   *   Permissions in the permission handler's shape, each depending on its
   *   type's config.
   */
  public function permissions(): array {
    return $this->generatePermissions($this->entityTypeManager->getStorage(BlueprintTypeInterface::ENTITY_TYPE_ID)->loadMultiple(), [$this, 'buildPermissions']);
  }

  /**
   * The three permissions of one type.
   *
   * @return array
   *   The permissions, keyed by name.
   */
  protected function buildPermissions(BlueprintTypeInterface $type): array {
    $arguments = ['%type' => $type->label()];
    return [
      self::permission($type->id(), 'create') => [
        'title' => $this->t('%type: Save new blueprints', $arguments),
        'description' => $this->t('Save a blueprint of this type from the canvas (Save as blueprint) or compose one from scratch from the listing.'),
      ],
      self::permission($type->id(), 'update') => [
        'title' => $this->t('%type: Edit any blueprint', $arguments),
        'description' => $this->t('Update a saved blueprint of this type from a placement, compose it on its own canvas, edit its details, curate its owned style variants and see it while unpublished.'),
      ],
      self::permission($type->id(), 'delete') => [
        'title' => $this->t('%type: Delete any blueprint', $arguments),
        'description' => $this->t('Delete a saved blueprint of this type. Placements are copies and survive.'),
      ],
    ];
  }

}
