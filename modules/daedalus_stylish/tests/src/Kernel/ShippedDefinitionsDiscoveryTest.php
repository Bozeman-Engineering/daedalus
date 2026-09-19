<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Drush\Commands\SyncCommand;
use Drupal\daedalus_stylish\Exception\ShippedVariantException;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\VariantImporter;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the disk lane's per-file shipped-definition discovery.
 *
 * ShippedDefinitionsImporter merges a module's hand-authored single file
 * (MODULE.daedalus_stylish.variants.yml) with every per-id file beside it
 * (MODULE.daedalus_stylish.variants.ID.yml), the machine-written form a stencil's
 * variants ship in. daedalus_stylish_variants_files_test ships one variant in each
 * form; daedalus_stylish_variants_files_test_invalid ships the same machine name in
 * both, which discovery must refuse before anything imports. Exercised
 * through the variants importer, the lane's founding member: the skins,
 * swatches, and animations importers share the discovery by construction.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ShippedDefinitionsDiscoveryTest extends KernelTestBase {

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
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * Loads a variant by machine name, bypassing access.
   */
  protected function loadVariant(string $machine_name): ?DaedalusStylishVariantInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_variant');
    $storage->resetCache();
    $variants = $storage->loadByProperties(['machine_name' => $machine_name]);
    $variant = reset($variants) ?: NULL;
    assert($variant === NULL || $variant instanceof DaedalusStylishVariantInterface);
    return $variant;
  }

  /**
   * The importer service, resolved late (installs rebuild the container).
   */
  protected function importer(): VariantImporter {
    return \Drupal::service('daedalus_stylish.variant_importer');
  }

  /**
   * Both file forms ship: the single file and the per-id file sync as one.
   */
  public function testBothFileFormsSync(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_variants_files_test']);

    // Discovery merges the two files into one map, single file first.
    $definitions = $this->importer()->getShippedDefinitions('daedalus_stylish_variants_files_test');
    $this->assertSame(['narrow_section', 'card_section'], array_keys($definitions));

    foreach (['narrow_section' => '1rem', 'card_section' => '3rem'] as $name => $padding) {
      $variant = $this->loadVariant($name);
      $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant, $name);
      $this->assertSame('daedalus_stylish_variants_files_test', $variant->getProvider());
      $this->assertTrue($variant->isPublished());
      $this->assertFalse($variant->isModified());
      $this->assertSame(['' => ['default' => ['padding' => $padding]]], $variant->getStyles());
      // The revert source reads through the same discovery, so the per-id
      // variant has a shipped state like any single-file one.
      $this->assertSame(['' => ['default' => ['padding' => $padding]]], $this->importer()->getShippedStyles($variant));
    }

    $results = $this->importer()->sync(['daedalus_stylish_variants_files_test'])->outcomes();
    $this->assertSame(VariantImporter::UNCHANGED, $results['daedalus_stylish_variants_files_test']['narrow_section']);
    $this->assertSame(VariantImporter::UNCHANGED, $results['daedalus_stylish_variants_files_test']['card_section']);
  }

  /**
   * One machine name in two of a module's files is FAILED under the file.
   */
  public function testDuplicateAcrossFilesFailsLoudly(): void {
    $this->enableModules(['daedalus_stylish_variants_files_test_invalid']);
    $module = 'daedalus_stylish_variants_files_test_invalid';
    $file = "$module.daedalus_stylish.variants.doubled.yml";
    $report = $this->importer()->sync([$module]);
    // The failure is the FILE's (the second one the name was met in), not a
    // definition's: which copy wins is not a question the sync answers.
    $this->assertSame([$module => [$file => VariantImporter::FAILED]], $report->outcomes());
    $error = $report->errors($module, $file)[0];
    $this->assertSame('doubled_section', $error->getPath());
    $this->assertSame('SHIPPED_DEFINITION_DUPLICATE', $error->getCode());
    $this->assertStringContainsString("$module.daedalus_stylish.variants.yml", $error->getMessage());
    $this->assertStringContainsString($file, $error->getMessage());
    // Neither file's copy landed.
    $this->assertNull($this->loadVariant('doubled_section'));

    // The loud reader (the revert source, the shipper's roster) throws the
    // same error instead of choosing.
    try {
      $this->importer()->getShippedDefinitions($module);
      $this->fail('A machine name shipped in two files of one module must not read.');
    }
    catch (ShippedVariantException $e) {
      $this->assertSame($module, $e->getProvider());
      $this->assertSame('SHIPPED_DEFINITION_DUPLICATE', $e->getErrors()[0]->getCode());
    }
  }

  /**
   * A file that does not parse is FAILED under its name; the rest lands.
   */
  public function testMalformedFileFailsUnderItsName(): void {
    $this->enableModules(['daedalus_stylish_variants_files_test_malformed']);
    $module = 'daedalus_stylish_variants_files_test_malformed';
    $file = "$module.daedalus_stylish.variants.yml";
    $report = $this->importer()->sync([$module]);
    $this->assertSame([
      $module => [
        $file => VariantImporter::FAILED,
        'sound_section' => VariantImporter::CREATED,
      ],
    ], $report->outcomes());
    $error = $report->errors($module, $file)[0];
    $this->assertSame($file, $error->getPath());
    $this->assertSame('SHIPPED_FILE_MALFORMED', $error->getCode());
    $this->assertStringContainsString($file, $error->getMessage());
    $this->assertNull($this->loadVariant('broken_section'));
    // The sound per-id file beside the broken single file landed.
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $this->loadVariant('sound_section'));
    $this->assertSame($module, $this->loadVariant('sound_section')->getProvider());

    // The command prints the failure under the file's name and fails.
    $command = SyncCommand::create($this->container);
    $tester = new CommandTester($command);
    $this->assertSame(Command::FAILURE, $tester->execute(['module' => $module]));
    $this->assertStringContainsString("$module: variant $file failed", $tester->getDisplay());
    $this->assertStringContainsString('SHIPPED_FILE_MALFORMED', $tester->getDisplay());
    $this->assertStringContainsString("$module: variant sound_section unchanged", $tester->getDisplay());
  }

  /**
   * The sync command names both file forms and surfaces the duplicate.
   */
  public function testSyncCommandNamesBothForms(): void {
    $this->enableModules(['daedalus_stylish_variants_files_test']);
    $command = SyncCommand::create($this->container);
    $tester = new CommandTester($command);

    $this->assertSame(Command::SUCCESS, $tester->execute(['module' => 'daedalus_stylish_variants_files_test']));
    $this->assertStringContainsString('daedalus_stylish_variants_files_test: variant narrow_section created', $tester->getDisplay());
    $this->assertStringContainsString('daedalus_stylish_variants_files_test: variant card_section created', $tester->getDisplay());

    // A module shipping nothing is told which files it could ship.
    $this->assertSame(Command::SUCCESS, $tester->execute(['module' => 'system']));
    $this->assertStringContainsString('system is not enabled or ships no daedalus_stylish definitions: no system.daedalus_stylish.animations.yml, system.daedalus_stylish.swatches.yml, system.daedalus_stylish.skins.yml, system.daedalus_stylish.variants.yml or a per-id file beside one (e.g. system.daedalus_stylish.variants.*.yml).', $tester->getDisplay());

    $this->enableModules(['daedalus_stylish_variants_files_test_invalid']);
    $command = SyncCommand::create($this->container);
    $tester = new CommandTester($command);
    $this->assertSame(Command::FAILURE, $tester->execute(['module' => 'daedalus_stylish_variants_files_test_invalid']));
    $this->assertStringContainsString('SHIPPED_DEFINITION_DUPLICATE', $tester->getDisplay());
  }

}
