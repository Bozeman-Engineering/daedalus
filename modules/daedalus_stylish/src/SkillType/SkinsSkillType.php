<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\SkillType;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\SkillType\SkillTypeInterface;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\SkinManager;
use Drupal\daedalus_stylish\StyleGuide;

/**
 * Skill type for the skins skill: live skin state + the skin operations.
 *
 * On the CreateEntitySkillType pattern: compose() runs fresh per
 * invocation, so the response opens with the live catalog (the declared
 * scales, every skin with its entity id and machine name, which one the
 * site wears, and whether a shipped skin carries a site layer) before the
 * skill file's static teaching of the operations (the token gestures with
 * kind token, update_default, the create door and the gestures that are a
 * person's). The catalog is what the static instructions cannot carry:
 * which skin the operations write, and the entity ids the generic read
 * and create doors take. The skill definition's permission key
 * ('administer skins') gates who sees and may invoke the skill; skins are
 * the design system, and changing it is access-gated even though the
 * token vocabulary itself is not.
 */
class SkinsSkillType implements SkillTypeInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SkinManager $skinManager,
    protected StyleGuide $styleGuide,
    protected PageBuilderDeclarations $declarations,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function compose(array $skill_definition, array $parameters): string {
    $parts = ["## Skins on this site\n\n" . $this->catalog()];

    if (!empty($skill_definition['instructions'])) {
      $parts[] = $skill_definition['instructions'];
    }

    return implode("\n\n", $parts);
  }

  /**
   * The live catalog: declared scales and existing skins.
   *
   * @return string
   *   Markdown lines describing scales, skins, and the active pointer.
   */
  protected function catalog(): string {
    $lines = [];

    $theme = $this->declarations->siteTheme();
    $scales = $this->styleGuide->getTokenScales($theme);
    if ($scales === []) {
      $lines[] = 'Declared token scales: NONE. This site declares no token vocabulary, so skins cannot mint tokens (defaults still work).';
    }
    else {
      $scale_lines = [];
      foreach ($scales as $scale => $declaration) {
        $entry = "{$scale} ({$declaration['shape']}, --" . StyleGuide::tokenPrefix($scale, $declaration) . '-{name})';
        $scale_lines[] = $entry;
      }
      $lines[] = 'Declared token scales: ' . implode(', ', $scale_lines) . '.';
    }

    $active = $this->skinManager->activeSkinName();
    $skins = $this->entityTypeManager->getStorage('daedalus_skin')->loadMultiple();
    if ($skins === []) {
      $lines[] = 'No skins exist yet.';
    }
    else {
      $lines[] = 'Skins (entity_type daedalus_skin; a skin is a member of the design system its design_system field names, and ACTIVE marks the skin the site wears: its choice for the active design system while that names a published, conforming member, else the system\'s default_skin):';
      foreach ($skins as $skin) {
        /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin */
        $line = sprintf('- id %s, machine_name %s, "%s"', $skin->id(), $skin->getMachineName(), $skin->label());
        $flags = [];
        if ($skin->getMachineName() === $active) {
          $flags[] = 'ACTIVE';
        }
        $flags[] = $skin->getDesignSystem() === NULL ? 'global' : 'member of ' . $skin->getDesignSystem();
        $flags[] = $skin->isPublished() ? 'published' : 'unpublished';
        if ($skin->isShipped()) {
          $flags[] = $skin->isModified() ? 'shipped, package layer locally modified' : 'shipped';
          $flags[] = $this->skinManager->hasSiteLayer($skin) ? 'with a site layer' : 'no site layer yet';
        }
        $lines[] = $line . ': ' . implode(', ', $flags);
      }
      if ($active === NULL) {
        $lines[] = 'No skin is worn: the active design system names no default_skin and the site has chosen none (or no system is active), so no tokens or defaults deliver and the operations have no skin to write until a person chooses one.';
      }
    }

    // Generated, not authored: this reference rule holds only where the
    // validator actually checks it (closed-world sites), so the static
    // instructions must not state it. Fact-shaped, never names a mode.
    if ($this->styleGuide->propertiesClosed()) {
      $lines[] = 'A var() reference inside a token or default value must name a minted token or a PUBLISHED swatch; unminted references are rejected.';
    }

    return implode("\n", $lines);
  }

}
