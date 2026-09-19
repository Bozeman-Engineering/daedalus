<?php

namespace Drupal\daedalus_layout_transform_test;

use Drupal\Core\Entity\EntityInterface;
use Drupal\layout_builder\Section;
use Drupal\daedalus_layout\ComponentTransform\SectionTransformInterface;

/**
 * Rewrites layout_twocol_section sections flagged 'convert' to layout_onecol.
 *
 * The replacement pattern under test: core sections cannot change layout id
 * in place, so the transform builds a new Section, keeps the daedalus_layout uuid,
 * and moves every component into layout_onecol's content region in region
 * order. The 'convert' third-party flag keeps the transform surgical so
 * other spine tests' twocol fixtures are untouched.
 */
class LegacySectionTransform implements SectionTransformInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(Section $section, EntityInterface $host): bool {
    return $section->getLayoutId() === 'layout_twocol_section'
      && (bool) $section->getThirdPartySetting('daedalus_layout_transform_test', 'convert');
  }

  /**
   * {@inheritdoc}
   */
  public function transform(Section $section, EntityInterface $host): Section {
    $third_party = [];
    foreach ($section->getThirdPartyProviders() as $provider) {
      $third_party[$provider] = $section->getThirdPartySettings($provider);
    }
    unset($third_party['daedalus_layout_transform_test']['convert']);
    $third_party['daedalus_layout_transform_test']['converted_on'] = $host->label();

    $replacement = new Section('layout_onecol', $section->getLayoutSettings(), [], $third_party);
    $weight = 0;
    foreach (['first', 'second'] as $region) {
      foreach ($section->getComponentsByRegion($region) as $component) {
        $component->set('region', 'content');
        $component->setWeight($weight++);
        $replacement->appendComponent($component);
      }
    }
    TransformLog::$calls[] = 'section:' . $section->getThirdPartySetting('daedalus_layout', 'uuid');
    return $replacement;
  }

}
