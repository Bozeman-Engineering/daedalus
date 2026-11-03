<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * One animation of a skin, resolved: the usage lane's handle on it.
 *
 * SkinToken's twin for the skin's motion section. An animation is not an
 * entity (its record is a fact of the skin's animations map), so the
 * delete and rename batches address it through this handle: the skin it
 * lives on, its name, its record as worn (the merge of both layers) and
 * the keyframes name it delivers as (StyleGuide::animationName(), the one
 * derivation rule), resolved once through the skin material manager
 * (SkinMaterialManager::resolveAnimation()). A rename carries its target
 * name too, so one batch shape serves the rewrite-then-delete and the
 * rewrite-then-rename jobs.
 *
 * The batch id (batchId()) is what the Batch API serializes between
 * requests: the skin's entity id (pinned, so a design system switch
 * mid-batch cannot redirect the job), the section, the name and, for a
 * rename, the new name.
 */
final class SkinAnimation {

  public function __construct(
    public readonly DaedalusStylishSkinInterface $skin,
    public readonly string $name,
    public readonly array $record,
    public readonly string $keyframes,
    public readonly ?string $renameTo = NULL,
  ) {}

  /**
   * The animation's roster id: its name.
   */
  public function id(): string {
    return $this->name;
  }

  /**
   * The Batch API's serialized address of this animation.
   */
  public function batchId(): string {
    $id = $this->skin->id() . ':' . SkinMaterialManager::ANIMATIONS . ':' . $this->name;
    return $this->renameTo === NULL ? $id : $id . ':' . $this->renameTo;
  }

}
