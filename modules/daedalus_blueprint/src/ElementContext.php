<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Value object returned by ElementLocator.
 *
 * Contains the located element target (SectionComponent, section, or
 * paragraph entity), the host it was located in, the entity owning the
 * field the target sits in (when one does), and the path from the root.
 */
class ElementContext {

  /**
   * Element type: Layout Builder component.
   */
  public const TYPE_LB_COMPONENT = 'lb_component';

  /**
   * Element type: paragraph (inline entity).
   */
  public const TYPE_PARAGRAPH = 'paragraph';

  /**
   * Element type: Layout Builder section.
   */
  public const TYPE_SECTION = 'section';

  /**
   * Constructs a ElementContext.
   *
   * @param string $type
   *   The element type (TYPE_LB_COMPONENT, TYPE_PARAGRAPH, or TYPE_SECTION).
   * @param object $target
   *   The located target: SectionComponent (LB) or paragraph entity.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the element was located in.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $parentEntity
   *   The entity owning the field the target lives in: the host's fields
   *   entity for a layout element or a root-level paragraph, the parent
   *   block_content or paragraph for a nested paragraph. NULL for a layout
   *   element on a host with no fields of its own.
   * @param string $path
   *   The full path from the root to this element.
   * @param object|null $section
   *   The Section containing this element (LB only).
   * @param int|null $sectionIndex
   *   The section index in the layout (LB only).
   * @param string|null $fieldName
   *   The ERR field name on the parent entity (paragraph only).
   * @param int|null $delta
   *   The field item delta (paragraph only).
   * @param object|null $sectionStorage
   *   The NestedAwareSectionStorage wrapper used for nested lookups (LB
   *   only). Kept alive so bubbleChangesToRoot() can propagate nested
   *   changes to the root after modifications.
   */
  public function __construct(
    public readonly string $type,
    public readonly object $target,
    public readonly HostInterface $host,
    public readonly ?FieldableEntityInterface $parentEntity,
    public readonly string $path,
    public readonly ?object $section = NULL,
    public readonly ?int $sectionIndex = NULL,
    public readonly ?string $fieldName = NULL,
    public readonly ?int $delta = NULL,
    public readonly ?object $sectionStorage = NULL,
  ) {}

  /**
   * Whether this is a Layout Builder component.
   *
   * @return bool
   *   TRUE if this is an LB component.
   */
  public function isLbComponent(): bool {
    return $this->type === static::TYPE_LB_COMPONENT;
  }

  /**
   * Whether this is a paragraph (inline entity).
   *
   * @return bool
   *   TRUE if this is a paragraph.
   */
  public function isParagraph(): bool {
    return $this->type === static::TYPE_PARAGRAPH;
  }

  /**
   * Whether this is a Layout Builder section.
   *
   * @return bool
   *   TRUE if this is a section.
   */
  public function isSection(): bool {
    return $this->type === static::TYPE_SECTION;
  }

}
