<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A design system: the unit a skin and its materials form.
 *
 * The site holds several; ONE is active (daedalus_blueprint.settings
 * active_design_system, read through ActiveDesignSystem). Members (skins,
 * variants, swatches, animations, stencils, skills) reference it by id
 * or carry none and are global; the roster partitions on it.
 */
interface DesignSystemInterface extends ConfigEntityInterface {

  /**
   * The open enforcement mode: the law shapes offers, never writes.
   */
  public const ENFORCEMENT_OPEN = 'open';

  /**
   * The guarded enforcement mode: authored constraints are law.
   */
  public const ENFORCEMENT_GUARDED = 'guarded';

  /**
   * The strict enforcement mode: a closed world of declared properties.
   */
  public const ENFORCEMENT_STRICT = 'strict';

  /**
   * The enforcement modes, loosest first.
   */
  public const ENFORCEMENT_MODES = [
    self::ENFORCEMENT_OPEN,
    self::ENFORCEMENT_GUARDED,
    self::ENFORCEMENT_STRICT,
  ];

  /**
   * The when-to-reach-for-this text, '' when the system has none.
   */
  public function getDescription(): string;

  /**
   * How this system's law is enforced while it is active.
   *
   * A shipped system owns the meaning of its law, so the mode is the
   * system's, not the site's: open (the law shapes offers only; any
   * recognized property with a grammatical value is accepted), guarded
   * (authored constraints are law; everything undeclared stays open with
   * recognition and grammar checks), or strict (a closed world: only
   * declared properties may be styled, and declared-namespace var()
   * references must name minted tokens). A site with no active system is
   * guarded; its readers (daedalus_stylish's StyleGuide) answer that default.
   *
   * @return string
   *   One of the ENFORCEMENT_* constants.
   */
  public function getEnforcement(): string;

  /**
   * The icon value (IconResolver's grammar), or NULL for the default.
   */
  public function getIcon(): ?string;

  /**
   * The skin that carries this system's vocabulary, or NULL.
   *
   * The machine name of the skin the site activates with the system; a
   * system without one (stencils and doctrine only) has NULL.
   */
  public function getDefaultSkin(): ?string;

  /**
   * The module whose shipped members belong to this system, or NULL.
   *
   * A file-shipped member of the package module belongs here by default;
   * a UI-created system has no package.
   */
  public function getPackage(): ?string;

}
