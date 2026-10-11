<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\VariantImporter;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The disk lane's blueprint: key without daedalus_blueprint_library is loud.
 *
 * Ownership lives in the `blueprint` reference field daedalus_blueprint_library
 * provides on daedalus_variant; a shipped file declaring an owner on a site
 * without that field is a developer error, never silently dropped ownership
 * (the resolving half, content-blueprint resolution and orphan posture, is
 * covered where both modules live, in daedalus_blueprint_library's
 * BlueprintOwnedVariantTest).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class OwnedVariantImportTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
    'daedalus_stylish_owned_variants_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * A blueprint: key without the ownership field fails the sync loudly.
   */
  public function testOwnerWithoutLibraryModuleIsLoud(): void {
    $importer = \Drupal::service('daedalus_stylish.variant_importer');
    assert($importer instanceof VariantImporter);
    $report = $importer->sync(['daedalus_stylish_owned_variants_test']);
    $this->assertSame(VariantImporter::FAILED, $report->outcome('daedalus_stylish_owned_variants_test', 'owned_wide_section'));
    $this->assertSame('SHIPPED_VARIANT_OWNER_UNAVAILABLE', $report->errors('daedalus_stylish_owned_variants_test', 'owned_wide_section')[0]->getCode());
  }

}
