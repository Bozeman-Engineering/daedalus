<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * One font of a skin, resolved: the usage lane's handle on it.
 *
 * SkinToken's and SkinAnimation's twin for the skin's type section. A font
 * is not an entity (its record is a fact of the skin's fonts map), so the
 * delete batch addresses it through this handle: the skin it lives on, its
 * name, its record as worn (the merge of both layers) and the quoted
 * family a font-family stack names it by (StyleGuide::fontFamily(), the
 * one derivation rule, the usage table's reference form), resolved once
 * through the skin material manager (SkinMaterialManager::resolveFont()).
 *
 * The batch id (batchId()) is what the Batch API serializes between
 * requests: the skin's entity id (pinned, so a design system switch
 * mid-batch cannot redirect the job), the section and the name. A font's
 * rename never stages a batch (the name is the roster id, never a
 * reference's tail: stacks name the FAMILY), so the handle carries no
 * rename target.
 */
final class SkinFont {

  public function __construct(
    public readonly DaedalusStylishSkinInterface $skin,
    public readonly string $name,
    public readonly array $record,
    public readonly string $spelling,
  ) {}

  /**
   * The font's roster id: its name.
   */
  public function id(): string {
    return $this->name;
  }

  /**
   * The family a stack names, bare.
   */
  public function family(): string {
    return (string) ($this->record['family'] ?? '');
  }

  /**
   * The Batch API's serialized address of this font.
   */
  public function batchId(): string {
    return $this->skin->id() . ':' . SkinMaterialManager::FONTS . ':' . $this->name;
  }

}
