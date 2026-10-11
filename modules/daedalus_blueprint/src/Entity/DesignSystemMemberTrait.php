<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Entity;

use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The design_system identity field every member entity shares.
 *
 * One definition, one read, one write, one save-time check, so the member
 * entity types (daedalus_stylish's four materials, the blueprint) cannot drift on
 * what membership means (see DesignSystemMemberInterface).
 * Non-revisionable beside the entity's other identity fields: the system
 * relationship belongs to the member, not to any one revision.
 */
trait DesignSystemMemberTrait {

  /**
   * The design_system base field, for baseFieldDefinitions().
   */
  protected static function designSystemBaseFieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Design system'))
      ->setDescription(new TranslatableMarkup('The design system this is a member of: offered only under that system. Empty for a global one, offered under every design system.'))
      ->setSetting('max_length', 255);
  }

  /**
   * {@inheritdoc}
   */
  public function getDesignSystem(): ?string {
    $value = $this->get('design_system')->value;
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setDesignSystem(?string $design_system): static {
    $this->set('design_system', $design_system);
    return $this;
  }

  /**
   * Refuses a membership naming no design system (the preSave() backstop).
   *
   * The lanes resolve membership from things that exist (the active
   * system, a module's package, a shipped key the lane checked), so a
   * dangling id here is always a caller bug; the blueprint lane's
   * corrective (daedalus_stylish's DESIGN_SYSTEM_UNKNOWN) fires first for
   * AI-authored payloads.
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  protected function checkDesignSystem(): void {
    $design_system = $this->getDesignSystem();
    if ($design_system !== NULL && $this->entityTypeManager()->getStorage(DesignSystem::ENTITY_TYPE_ID)->load($design_system) === NULL) {
      throw new EntityMalformedException(sprintf('"%s" is not a design system on this site; a member belongs to an existing design system or is global (empty).', $design_system));
    }
  }

}
