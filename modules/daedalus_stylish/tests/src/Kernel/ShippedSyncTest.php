<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Test\TestDatabase;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Drush\Commands\SyncCommand;
use Drupal\daedalus_stylish\ShippedDefinitionsImporter;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\ShippedSync;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\VariantImporter;
use Drupal\daedalus_stylish\VariantStylesheet;
use Drupal\daedalus_stylish_log_sink_test\LogSink;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the orchestrated sync of shipped definitions and its entries.
 *
 * The daedalus_stylish_shipped_sync_test module ships one self-referencing package: a
 * font, a skin (a token the variant wears, a color token it wears too, a
 * font token naming the font's family, and the animation the variant
 * wears), and a variant wearing the other two. On a strict
 * site the variant lands only after the materials it names are published,
 * so the package proves the orchestrator's order (fonts, skins,
 * variants) the way the old per-kind order failed. The
 * daedalus_stylish_design_system_test module packages the aurora design system (the same
 * kinds as members, plus a variant on an axis only aurora's own law
 * declares) and proves the package rule: the package is deferred whole while
 * aurora is not the active system (install, the system's config landing, a
 * rebuild in between), and its activation lands it under its own law; when
 * the pointer named aurora before its config landed (config import's
 * order), the landing itself is the activation and lands it. The
 * rebuild entry is proven the way it failed on a pulled site: a per-id variants
 * file dropped beside an already installed module goes live on a cache rebuild
 * alone. That module is created under the test's own real site directory
 * (sites/simpletest/ID), never a real module directory, and removed on teardown
 * (the VariantShipperTest pattern). The install and activation entries are
 * proven on the recipe's own shape with the real modules, twice: a site
 * install, where the batch syncs inside the installer on a site with no
 * system and no skin (daedalus_stylish_layouts' gutter globals land withheld,
 * the olivero package deferred); and a recipe applied to an existing site,
 * where the same skinless batch lands the Columns variant withheld under
 * the suite's gap binding and the activation of the olivero package mints
 * the gutter, so the roster's verdict offers it with no re-sync and no
 * rebuild. A sync never fails a definition for law: structure alone is
 * the sync's refusal, and the verdict (VariantConformance) withholds what
 * the law of the day refuses. Skins wait for one more thing than
 * variants: the default theme, whose chain is the guide their defaults
 * validate against, so while system.theme names a theme that is not
 * installed (the installer's window) the skins kind defers every shipping
 * module and the theme landing (its install, or the default's save) syncs
 * it.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ShippedSyncTest extends KernelTestBase {

  use UserCreationTrait;
  use DesignSystemActivationTrait;

  /**
   * The module shipping the self-referencing package.
   */
  protected const PACKAGE = 'daedalus_stylish_shipped_sync_test';

  /**
   * The installed module a variants file is dropped beside.
   */
  protected const TARGET = 'daedalus_stylish_shipped_sync_target';

  /**
   * The module packaging the aurora design system with its members.
   */
  protected const DESIGN_SYSTEM_PACKAGE = 'daedalus_stylish_design_system_test';

  /**
   * The design-system package's shipped definition per kind.
   */
  protected const DESIGN_SYSTEM_NAMES = [
    'skin' => 'aurora',
    'variant' => 'aurora_section',
  ];

  /**
   * The orchestrator's kinds, in dependency order.
   */
  protected const ORDER = ['skin', 'variant'];

  /**
   * The package's shipped definition per kind: kind => machine name.
   */
  protected const PACKAGE_NAMES = [
    'skin' => 'sync_test_skin',
    'variant' => 'sync_test_section',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'filter',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_log_sink_test',
  ];

  /**
   * The target module's directory, absolute.
   */
  protected string $targetPath;

  /**
   * What the daedalus_stylish channel logged: [RfcLogLevel, rendered message] pairs.
   */
  protected array $log = [];

  /**
   * {@inheritdoc}
   *
   * The target module is written into the test's own REAL site directory
   * before the kernel boots (ExtensionDiscovery caches each scanned
   * directory statically, and the per-id discovery globs, which no stream
   * wrapper serves); test_parent_site keeps the real path a scan root
   * after boot. See VariantShipperTest for the full reasoning.
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $site = (new TestDatabase($this->databasePrefix))->getTestSitePath();
    $this->targetPath = $this->root . '/' . $site . '/modules/' . self::TARGET;
    mkdir($this->targetPath, 0777, TRUE);
    file_put_contents($this->targetPath . '/' . self::TARGET . '.info.yml', Yaml::encode([
      'name' => 'Daedalus Stylish shipped sync target',
      'type' => 'module',
      'core_version_requirement' => '*',
      'hidden' => TRUE,
      'dependencies' => ['daedalus_stylish:daedalus_stylish'],
    ]));
    $this->setSetting('test_parent_site', $site);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    // A shipped skin's font lands its face's file through the font store.
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    // Saves mirror motion-*, var() and "family" references into the
    // usage tables, and the shipped package wears all three.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    // The importers switch to an administrative account (shipped
    // definitions are code, not user input); give the switcher one.
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * Enables the package and the target with nothing imported.
   *
   * The enableModules() route skips install hooks: both providers are
   * enabled with nothing imported, the state of a site whose providers
   * predate daedalus_stylish (the sweep) or whose files arrived by pull (the
   * rebuild). Each test calls this where its story places the providers'
   * arrival: before any activation for most, after it for the one that
   * needs the package still on disk when the site is already strict (an
   * activation syncs every enabled module, so a provider present at the
   * activation lands there).
   */
  protected function enableProviders(): void {
    $this->enableModules([self::PACKAGE, self::TARGET]);
    $this->attachLogSink();
  }

  /**
   * Aliases $this->log to the daedalus_stylish channel's captured entries.
   *
   * The sink is daedalus_stylish_log_sink_test's LogSink service (a tagged
   * logger over a static store), so it rides every container the test's
   * installs rebuild (a module install, a theme install) and the hooks
   * that log in the next container are still read here. Attaching resets
   * the entries; a test resets $this->log again where its story starts
   * reading.
   */
  protected function attachLogSink(): void {
    $this->log = &LogSink::$sink;
    $this->log = [];
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Leave nothing behind: the real site directory exists only for the
    // target module.
    if (isset($this->targetPath) && is_dir($site = dirname($this->targetPath, 2))) {
      foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($site, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
      }
      rmdir($site);
    }
    parent::tearDown();
  }

  /**
   * The orchestrator, resolved late (installs rebuild the container).
   */
  protected function sync(): ShippedSync {
    return \Drupal::service('daedalus_stylish.shipped_sync');
  }

  /**
   * Loads a definition entity of one kind by machine name, bypassing access.
   */
  protected function load(string $kind, string $machine_name): mixed {
    $storage = \Drupal::entityTypeManager()->getStorage("daedalus_$kind");
    $storage->resetCache();
    $entities = $storage->loadByProperties(['machine_name' => $machine_name]);
    return reset($entities) ?: NULL;
  }

  /**
   * The logged messages at one level, in order.
   *
   * @param int $level
   *   An RfcLogLevel constant (the channel converts PSR levels before any
   *   logger sees them).
   *
   * @return string[]
   *   The rendered messages.
   */
  protected function logged(int $level): array {
    $messages = [];
    foreach ($this->log as [$logged_level, $message]) {
      if ($logged_level === $level) {
        $messages[] = $message;
      }
    }
    return $messages;
  }

  /**
   * The compiled variants file the site delivers, or '' while there is none.
   *
   * Read through the current container (a theme install rebuilds it): the
   * state value names the file, and no file (no offered variant) reads as
   * an empty sheet so a "does not contain" assertion means the same thing
   * either way.
   */
  protected function variantsCss(): string {
    $uri = \Drupal::state()->get(VariantStylesheet::STATE_KEY);
    return $uri === NULL ? '' : file_get_contents($uri);
  }

  /**
   * Puts the site on the fresh install's law before the providers arrive.
   *
   * A strict system naming the package's skin before anything syncs (a
   * recipe's config lands before its content): the skin's tokens are
   * unminted until it lands, so the variant wearing them lands withheld,
   * and only the skin's landing offers it. Guarded law (no system) never
   * withholds for an unminted token, so without this the landing changes
   * nothing a delivery test can see.
   */
  protected function activateStrictPackageSystem(): void {
    DesignSystem::create(['id' => 'sync_test', 'label' => 'Sync test', 'default_skin' => 'sync_test_skin'])->save();
    \Drupal::service(ActiveDesignSystem::SERVICE_ID)->activate('sync_test');
    $this->enforce('strict');
  }

  /**
   * Asserts the variant is withheld and absent from the compiled file.
   *
   * The state before the skin lands: the verdict names the variant (its
   * tokens unminted, its animation unpublished) and the file, the
   * verdict's projection on disk, does not carry it.
   */
  protected function assertVariantWithheldFromDelivery(string $machine_name): void {
    $this->assertArrayHasKey($machine_name, \Drupal::service('daedalus_stylish.variant_conformance')->withheld(), "$machine_name is withheld before the skin lands");
    $this->assertStringNotContainsString($machine_name, $this->variantsCss(), "$machine_name is not in the compiled file before the skin lands");
  }

  /**
   * Asserts the landing reached delivery in the same request, no variant write.
   *
   * The skin's landing is a law change, and the compiled variants file is
   * a projection of the verdict: in the request that landed the skin, with
   * no variant saved, the verdict no longer names the variant, the roster
   * carries it, and the file carries its block with the token reference
   * printed verbatim (the skin file supplies the value). Before this
   * held, the verdict's request memo outlived the skin's write and only a
   * variant save recompiled the file, so a fresh install delivered an
   * unstyled site until some unrelated variant save.
   */
  protected function assertVariantLandedInDelivery(string $machine_name): void {
    $this->assertArrayNotHasKey($machine_name, \Drupal::service('daedalus_stylish.variant_conformance')->withheld(), "the skin's landing offers $machine_name");
    $this->assertTrue(\Drupal::service('daedalus_blueprint.materials_roster_builder')->build()->has(MaterialKind::Variant, $machine_name), "the roster carries $machine_name after the skin lands");
    $css = $this->variantsCss();
    $this->assertStringContainsString(".daedalus-stylish-variant--$machine_name", $css, "the compiled file delivers $machine_name after the skin lands");
    $this->assertStringContainsString('padding: var(--space-gutter);', $css);
  }

  /**
   * Writes a per-id variants file beside the target module.
   */
  protected function dropVariantsFile(string $id, array $definitions): void {
    $importer = \Drupal::service('daedalus_stylish.variant_importer');
    assert($importer instanceof VariantImporter);
    file_put_contents($this->targetPath . '/' . self::TARGET . '.' . $importer->perFileSuffix($id), Yaml::encode($definitions));
  }

  /**
   * One kind alone lands the variant withheld; the orchestrator's order offers it.
   *
   * The site is strict, so every reference must name a published material
   * for the roster to offer the variant: the variant importer on its own
   * (the old per-kind order, variants first) lands the variant, since its
   * structure is sound, and the verdict withholds it for the animation and
   * the two tokens its own module's skin has not published; the
   * orchestrator's order lands the skin first, and the variant, unchanged,
   * is offered with no failure and no re-sync.
   */
  public function testKindsSyncInDependencyOrder(): void {
    // The activation precedes the skin, as a recipe's config precedes its
    // content: the site-built system names a skin the lane has not synced
    // yet, and the package's global members are judged against it once it
    // lands (the module packages no system of its own). The system is
    // strict: enforcement is its own property. The providers arrive after
    // the activation (an activation syncs every enabled module, and the
    // claim below is what one kind alone lands on the strict site).
    DesignSystem::create(['id' => 'sync_test', 'label' => 'Sync test', 'default_skin' => 'sync_test_skin'])->save();
    \Drupal::service(ActiveDesignSystem::SERVICE_ID)->activate('sync_test');
    $this->enforce('strict');
    $this->enableProviders();

    // One kind alone: the variant lands CREATED, nothing failed, and the
    // verdict withholds it under the law for the three materials nothing
    // has published yet, so the roster does not offer it.
    $report = \Drupal::service('daedalus_stylish.variant_importer')->sync([self::PACKAGE]);
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
    $this->assertSame(ShippedDefinitionsImporter::CREATED, $report->outcome(self::PACKAGE, 'sync_test_section'));
    $variant = $this->load('variant', 'sync_test_section');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant);
    $this->assertTrue($variant->isPublished());
    $revision = $variant->getRevisionId();
    $conformance = \Drupal::service('daedalus_stylish.variant_conformance');
    $codes = array_map(static fn ($error) => $error->getCode(), $conformance->withheld()['sync_test_section'] ?? []);
    sort($codes);
    $this->assertSame(['STYLE_ANIMATION_NOT_PUBLISHED', 'STYLE_TOKEN_NOT_MINTED', 'STYLE_TOKEN_NOT_MINTED'], $codes);
    $builder = \Drupal::service('daedalus_blueprint.materials_roster_builder');
    $builder->reset();
    $this->assertFalse($builder->build()->has(MaterialKind::Variant, 'sync_test_section'), 'withheld: the roster does not offer the variant before its materials land');

    // The orchestrator's order: the skin lands first (CREATED), the
    // variant is converged (UNCHANGED, the same revision), and the verdict
    // offers it once the skin has published what it names.
    $report = $this->sync()->sync([self::PACKAGE]);
    $this->assertSame(self::ORDER, array_keys($report->reports()));
    $this->assertSame(self::ORDER, array_keys($this->sync()->importers()));
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
    $this->assertFalse($report->isEmpty());
    foreach (self::PACKAGE_NAMES as $kind => $name) {
      $this->assertSame($kind, $report->report($kind)->kind());
      $outcome = $kind === 'skin' ? ShippedDefinitionsImporter::CREATED : ShippedDefinitionsImporter::UNCHANGED;
      $this->assertSame([self::PACKAGE => [$name => $outcome]], $report->report($kind)->outcomes(), $kind);
      $entity = $this->load($kind, $name);
      $this->assertNotNull($entity, $kind);
      $this->assertSame(self::PACKAGE, $entity->getProvider(), $kind);
    }
    $variant = $this->load('variant', 'sync_test_section');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant);
    $this->assertTrue($variant->isPublished());
    $this->assertSame($revision, $variant->getRevisionId(), 'the variant was not re-synced');
    $this->assertSame('var(--space-gutter)', $variant->getStyles()['']['default']['padding']);
    // The skin's save invalidated the persisted verdict by tag and reset the
    // roster, whose reset cascades to the verdict's request memo: nothing is
    // reset by hand here, the same request reads the fresh verdict.
    $this->assertArrayNotHasKey('sync_test_section', $conformance->withheld(), 'the variant conforms once its materials are published');
    $this->assertTrue($builder->build()->has(MaterialKind::Variant, 'sync_test_section'), 'the orchestrator\'s order offers the variant');
    // The skin's font landed with it, its face's bytes as a managed file
    // the skin's font token names by family (the usage table saw the
    // skin's save: the skin references its own font).
    $font = $this->load('skin', 'sync_test_skin')->fonts()['sync_test_font'];
    $this->assertSame('Sync Test Sans', $font['family']);
    $this->assertNotNull(\Drupal::service('daedalus_stylish.font_files')->load($font['faces'][0]['source']['file']), 'The face names a managed file.');
    $this->assertSame(1, \Drupal::service('daedalus_stylish.skin_material_usage')->countReferencesTo(StyleGuide::fontFamily('Sync Test Sans')));

    // A second run converges: every kind unchanged, nothing failed.
    $report = $this->sync()->sync([self::PACKAGE]);
    $this->assertFalse($report->hasFailures());
    foreach (self::PACKAGE_NAMES as $kind => $name) {
      $this->assertSame([self::PACKAGE => [$name => ShippedDefinitionsImporter::UNCHANGED]], $report->report($kind)->outcomes(), $kind);
    }
  }

  /**
   * A package waits whole while another system is active, and lands on its own.
   *
   * The sibling of testKindsSyncInDependencyOrder() for the unit: the
   * package's materials are members of its own design system (the module
   * default, no key in any file), and a package syncs only under its own
   * law. Landing aurora's config while borealis is active reads nothing
   * of the package (no entity, no log line); a sync of it by hand answers
   * deferred, naming aurora. Activating aurora lands every kind CREATED,
   * the variant on aurora's own axis included, each a member of aurora
   * and on the roster. Back on borealis the members leave the roster and
   * a style map wearing one is refused by name of its system. Beside the
   * package, a dropped file in a module that packages nothing proves the
   * key's three spellings: absent means global for such a module, a
   * global variant referencing an unminted token lands and strict
   * withholds it, ~ is the explicit opt-out, an explicit id joins that
   * system, and an unknown id is refused (structure: the sync's failure).
   */
  public function testPackageWaitsForItsOwnActivation(): void {
    $this->enableProviders();
    $this->enableModules([self::DESIGN_SYSTEM_PACKAGE]);
    $this->attachLogSink();
    $this->installConfig(['daedalus_blueprint']);
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $active = \Drupal::service(ActiveDesignSystem::SERVICE_ID);
    // The first activation syncs every enabled module: daedalus_stylish's own
    // globals and the packageless package land here and are converged
    // (silent) at every activation below.
    $active->activate('borealis');
    $this->enforce('strict');

    // The system landing (the recipe's config import) reads nothing of
    // its package: the package is deferred until aurora is active.
    $this->log = [];
    $this->installConfig([self::DESIGN_SYSTEM_PACKAGE]);
    $this->assertSame([], $this->log);
    foreach (self::DESIGN_SYSTEM_NAMES as $kind => $name) {
      $this->assertNull($this->load($kind, $name), $kind);
    }
    $this->assertNull($this->load('variant', 'aurora_rhythm'));
    $report = $this->sync()->sync([self::DESIGN_SYSTEM_PACKAGE]);
    $this->assertFalse($report->isEmpty());
    $this->assertFalse($report->hasFailures());
    foreach (self::ORDER as $kind) {
      $this->assertSame([], $report->report($kind)->outcomes(), $kind);
      $this->assertSame([self::DESIGN_SYSTEM_PACKAGE => 'aurora'], $report->report($kind)->deferred(), $kind);
    }
    $builder = \Drupal::service('daedalus_blueprint.materials_roster_builder');
    $builder->reset();
    $this->assertSame([], $builder->members('aurora'));

    // Activating aurora lands the whole package under its own law: every
    // kind CREATED, the variant on aurora's own axis included, members
    // of aurora, published, on the roster.
    $this->log = [];
    $active->activate('aurora');
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR));
    $this->assertSame([
      self::DESIGN_SYSTEM_PACKAGE . ': skin sync: 1 created',
      self::DESIGN_SYSTEM_PACKAGE . ': variant sync: 2 created',
    ], $this->logged(RfcLogLevel::INFO));
    $this->assertArrayHasKey('aurora_fade', $this->load('skin', 'aurora')->animations(), 'The skin carries the package\'s animation.');
    foreach (self::DESIGN_SYSTEM_NAMES + ['rhythm' => 'aurora_rhythm'] as $kind => $name) {
      $entity = $this->load($kind === 'rhythm' ? 'variant' : $kind, $name);
      $this->assertNotNull($entity, $name);
      $this->assertSame('aurora', $entity->getDesignSystem(), $name);
      $this->assertTrue($entity->isPublished(), $name);
    }
    $roster = $builder->build();
    $this->assertTrue($roster->has(MaterialKind::Variant, 'aurora_section'));
    $this->assertTrue($roster->has(MaterialKind::Variant, 'aurora_rhythm'));
    $this->assertTrue($roster->has(MaterialKind::Token, 'color/accent'));
    $this->assertTrue($roster->has(MaterialKind::Animation, 'aurora_fade'));
    $this->assertSame('aurora', $roster->get(MaterialKind::Variant, 'aurora_section')->designSystem);
    $validator = \Drupal::service('daedalus_stylish.style_map_validator');
    $wearing = [
      'container:' . $this->container->get('uuid')->generate() => [
        'variants' => [['variant' => 'aurora_section', 'fragment' => '']],
      ],
    ];
    $this->assertSame([], $validator->validate($wearing));

    // A second run converges, membership included.
    $report = $this->sync()->sync([self::DESIGN_SYSTEM_PACKAGE]);
    $this->assertFalse($report->hasFailures());
    foreach (self::DESIGN_SYSTEM_NAMES as $kind => $name) {
      $expected = [$name => ShippedDefinitionsImporter::UNCHANGED];
      if ($kind === 'variant') {
        $expected['aurora_rhythm'] = ShippedDefinitionsImporter::UNCHANGED;
      }
      $this->assertSame([self::DESIGN_SYSTEM_PACKAGE => $expected], $report->report($kind)->outcomes(), $kind);
      $this->assertSame([], $report->report($kind)->deferred(), $kind);
    }

    // Members of an inactive system are absent from the roster (their
    // entities stay), a style map wearing the member variant is refused
    // by name of it, and the package is deferred again.
    $active->activate('borealis');
    $roster = $builder->build();
    $this->assertFalse($roster->has(MaterialKind::Variant, 'aurora_section'));
    $this->assertFalse($roster->has(MaterialKind::Token, 'color/accent'));
    $this->assertFalse($roster->has(MaterialKind::Animation, 'aurora_fade'));
    $this->assertSame([], $roster->tokens());
    // The skin's materials (its tokens and animations) leave with the
    // skin: an inactive system's skin is a held look, not materials, so
    // members() lists its entity members alone.
    $members = array_map(static fn ($record) => $record->id, array_values(array_filter($builder->members('aurora'), static fn ($record) => $record->kind !== MaterialKind::Token)));
    sort($members);
    $this->assertSame(['aurora_rhythm', 'aurora_section'], $members);
    $errors = $validator->validate($wearing);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_DESIGN_SYSTEM_INACTIVE', $errors[0]->getCode());
    $this->assertSame('aurora', $errors[0]->getContext()['design_system']);
    $this->assertStringContainsString('belongs to the Aurora design system, which is not active', $errors[0]->getMessage());
    $this->assertStringContainsString('offered only while it is active; wear a variant of the active system', $errors[0]->getHint());
    $this->assertSame([self::DESIGN_SYSTEM_PACKAGE => 'aurora'], $this->sync()->sync([self::DESIGN_SYSTEM_PACKAGE])->report('variant')->deferred());

    // The key's spellings, in a module packaging nothing (the target),
    // under borealis (a system carrying no skin, so the active skin is
    // none and no token is minted). Absent: global, landed, and judged in
    // the active system's universe, where the unminted token is withheld
    // under strict. ~: the same, said explicitly. aurora: a member,
    // validated in aurora's universe, its token resolves (a module
    // packaging nothing is never deferred, whichever system its members
    // join). nope: refused, since an unknown system is structure.
    $this->dropVariantsFile('members', [
      'global_section' => [
        'label' => 'Global section',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => 'var(--space-gutter)']]],
      ],
      'opted_out_section' => [
        'label' => 'Opted out section',
        'scope' => 'kind:container',
        'design_system' => NULL,
        'styles' => ['' => ['default' => ['padding' => 'var(--space-gutter)']]],
      ],
      'joined_section' => [
        'label' => 'Joined section',
        'scope' => 'kind:container',
        'design_system' => 'aurora',
        'styles' => ['' => ['default' => ['padding' => 'var(--space-gutter)']]],
      ],
      'lost_section' => [
        'label' => 'Lost section',
        'scope' => 'kind:container',
        'design_system' => 'nope',
        'styles' => ['' => ['default' => ['padding' => '1rem']]],
      ],
    ]);
    $report = \Drupal::service('daedalus_stylish.variant_importer')->sync([self::TARGET]);
    $this->assertSame([
      'global_section' => ShippedDefinitionsImporter::CREATED,
      'opted_out_section' => ShippedDefinitionsImporter::CREATED,
      'joined_section' => ShippedDefinitionsImporter::CREATED,
      'lost_section' => ShippedDefinitionsImporter::FAILED,
    ], $report->outcomes()[self::TARGET]);
    $withheld = \Drupal::service('daedalus_stylish.variant_conformance')->withheld();
    foreach (['global_section', 'opted_out_section'] as $name) {
      $this->assertNull($this->load('variant', $name)->getDesignSystem(), "$name is a global");
      $this->assertSame(['STYLE_TOKEN_NOT_MINTED'], array_map(static fn ($error) => $error->getCode(), $withheld[$name] ?? []), $name);
    }
    $builder->reset();
    $this->assertFalse($builder->build()->has(MaterialKind::Variant, 'global_section'), 'withheld under strict: not offered');
    $this->assertSame(['SHIPPED_DESIGN_SYSTEM_UNKNOWN'], array_map(static fn ($error) => $error->getCode(), $report->errors(self::TARGET, 'lost_section')));
    $this->assertNull($this->load('variant', 'lost_section'));
    $known = $report->errors(self::TARGET, 'lost_section')[0]->getContext()['design_systems'];
    sort($known);
    $this->assertSame(['aurora', 'borealis'], $known);
    $this->assertSame('aurora', $this->load('variant', 'joined_section')->getDesignSystem());
    $this->assertSame(self::TARGET, $this->load('variant', 'joined_section')->getProvider());

    // Disk is the author of membership: the joined variant opting out on
    // disk leaves aurora on the next sync (a face change, styles
    // unchanged), and rejoins when the key names it again.
    $this->dropVariantsFile('members', [
      'joined_section' => [
        'label' => 'Joined section',
        'scope' => 'kind:container',
        'design_system' => NULL,
        'styles' => ['' => ['default' => ['padding' => '1rem']]],
      ],
    ]);
    $report = \Drupal::service('daedalus_stylish.variant_importer')->sync([self::TARGET]);
    $this->assertSame(['joined_section' => ShippedDefinitionsImporter::UPDATED], $report->outcomes()[self::TARGET]);
    $this->assertNull($this->load('variant', 'joined_section')->getDesignSystem());
    $this->dropVariantsFile('members', [
      'joined_section' => [
        'label' => 'Joined section',
        'scope' => 'kind:container',
        'design_system' => 'aurora',
        'styles' => ['' => ['default' => ['padding' => '1rem']]],
      ],
    ]);
    $report = \Drupal::service('daedalus_stylish.variant_importer')->sync([self::TARGET]);
    $this->assertSame(['joined_section' => ShippedDefinitionsImporter::UNCHANGED], $report->outcomes()[self::TARGET]);
    $this->assertSame('aurora', $this->load('variant', 'joined_section')->getDesignSystem());
  }

  /**
   * A global the site's law refuses lands withheld; structure still fails.
   *
   * The disk lane validates on the validator's landing lane
   * (VariantStylesFieldHandler for a shipped_sync write): on a strict site
   * with padding bound to the space scale, a module's global with a
   * literal padding, one naming an unminted token and one styling an
   * undeclared part all land CREATED with no failure, published and as
   * authored, while the roster's verdict withholds the three with the
   * law's own codes and the roster offers none of them. A definition
   * misspelling a property is structure, so it still FAILS with its error
   * and lands nothing, and the landed three are converged beside it.
   * Activating an open system afterwards offers all three with no
   * re-sync: the activation's sweep finds them unchanged (the same
   * revisions), the verdict withholds none of them, the roster offers
   * them, and the one error the sweep still logs is the structural one.
   * The old lane refused the three at the write and re-logged the refusal
   * on every rebuild forever.
   */
  public function testLawRefusedGlobalLandsWithheld(): void {
    $this->enableModules([self::TARGET, 'daedalus_stylish_control_test']);
    $this->attachLogSink();
    // The site under test owns one scale and the container kind binds
    // padding to it (the state REPLACES the suite's site declaration, so
    // this is the one binding); one minted token makes the scale honestly
    // bound. Enforcement is the active system's, so it is set after the
    // activation it governs.
    $state = $this->container->get('state');
    $state->set('daedalus_stylish_control_test.site', [
      'token_scales' => ['space' => ['label' => 'Space', 'shape' => 'dimension']],
    ]);
    $state->set('daedalus_stylish_control_test.kinds', [
      'container' => ['property_groups' => ['spacing' => ['constraints' => ['padding' => ['scale' => 'space']]]]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $skin = DaedalusStylishSkin::create(['label' => 'Strict skin']);
    $skin->setPackageTokens(['space' => ['4' => '1rem']])->save();
    $this->activateSkin($skin, 'strict_system');
    $this->enforce('strict');

    $this->dropVariantsFile('withheld', [
      'literal_section' => [
        'label' => 'Literal section',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => '0.75em']]],
      ],
      'unminted_section' => [
        'label' => 'Unminted section',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => 'var(--space-9)']]],
      ],
      'undeclared_part_section' => [
        'label' => 'Undeclared part section',
        'scope' => 'kind:container',
        'styles' => ['::.mystery' => ['default' => ['padding' => 'var(--space-4)']]],
      ],
    ]);
    $importer = \Drupal::service('daedalus_stylish.variant_importer');
    $report = $importer->sync([self::TARGET]);
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
    $this->assertSame([
      'literal_section' => ShippedDefinitionsImporter::CREATED,
      'unminted_section' => ShippedDefinitionsImporter::CREATED,
      'undeclared_part_section' => ShippedDefinitionsImporter::CREATED,
    ], $report->outcomes()[self::TARGET]);
    $revisions = [];
    foreach (['literal_section', 'unminted_section', 'undeclared_part_section'] as $name) {
      $variant = $this->load('variant', $name);
      $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant, $name);
      $this->assertTrue($variant->isPublished(), $name);
      $this->assertSame(self::TARGET, $variant->getProvider(), $name);
      $this->assertNull($variant->getDesignSystem(), "$name is a global");
      $revisions[$name] = $variant->getRevisionId();
    }
    $this->assertSame('0.75em', $this->load('variant', 'literal_section')->getStyles()['']['default']['padding'], 'the literal lands as authored');

    // The verdict withholds the three with the law's own refusals; the
    // roster offers none of them.
    $conformance = \Drupal::service('daedalus_stylish.variant_conformance');
    $codes = static fn (array $errors): array => array_map(static fn ($error) => $error->getCode(), $errors);
    $withheld = $conformance->withheld();
    $this->assertSame(['STYLE_VALUE_NOT_ON_SCALE'], $codes($withheld['literal_section'] ?? []));
    $this->assertContains('STYLE_VALUE_NOT_ON_SCALE', $codes($withheld['unminted_section'] ?? []));
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $codes($withheld['undeclared_part_section'] ?? []));
    $builder = \Drupal::service('daedalus_blueprint.materials_roster_builder');
    $builder->reset();
    $roster = $builder->build();
    foreach (array_keys($revisions) as $name) {
      $this->assertFalse($roster->has(MaterialKind::Variant, $name), "$name is not offered under strict");
    }

    // Structure stays the sync's refusal: a misspelled property FAILS with
    // its error and lands nothing, while the landed three are converged.
    $this->dropVariantsFile('broken', [
      'misspelled_section' => [
        'label' => 'Misspelled section',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['pading' => '1rem']]],
      ],
    ]);
    $report = $importer->sync([self::TARGET]);
    $this->assertTrue($report->hasFailures());
    $this->assertSame(ShippedDefinitionsImporter::FAILED, $report->outcome(self::TARGET, 'misspelled_section'));
    $this->assertSame(['INVALID_STYLE_PROPERTY'], $codes($report->errors(self::TARGET, 'misspelled_section')));
    $this->assertNull($this->load('variant', 'misspelled_section'));
    foreach (array_keys($revisions) as $name) {
      $this->assertSame(ShippedDefinitionsImporter::UNCHANGED, $report->outcome(self::TARGET, $name), $name);
    }

    // The law changes: an open system whose skin mints the same token (the
    // universe stands, only the law moves). Its activation sweeps every
    // enabled module and finds the three unchanged, the same revisions, so
    // nothing re-synced; the one error it logs is the structural one. The
    // verdict withholds none of the three and the roster offers them.
    $open = DaedalusStylishSkin::create(['label' => 'Open skin']);
    $open->setPackageTokens(['space' => ['4' => '1rem']])->save();
    $this->log = [];
    $this->activateSkin($open, 'open_system');
    $this->enforce('open');
    $errors = $this->logged(RfcLogLevel::ERROR);
    $this->assertCount(1, $errors, implode("\n", $errors));
    $this->assertStringStartsWith(self::TARGET . ': variant misspelled_section failed: ', $errors[0]);
    $withheld = $conformance->withheld();
    foreach ($revisions as $name => $revision) {
      $this->assertArrayNotHasKey($name, $withheld, "$name conforms under open law");
      $this->assertSame($revision, $this->load('variant', $name)->getRevisionId(), "$name was not re-synced");
    }
    $builder->reset();
    $roster = $builder->build();
    foreach (array_keys($revisions) as $name) {
      $this->assertTrue($roster->has(MaterialKind::Variant, $name), "$name is offered under open");
    }
  }

  /**
   * A package is a package from install, before its system's config lands.
   *
   * The recipe order: a module installs (hook_modules_installed() syncs
   * the batch) and the recipe imports its install config, the system
   * among it, only afterwards. In that window the landed set knows no
   * system for the module, and reading its files then would sync them as
   * global under a law that is not theirs (the state the old landing
   * re-sync existed to repair); the shipped set (the design_system file
   * under the module's config/install) names the package already, so the
   * install-time sync defers it. Landing a system the pointer does not
   * name syncs nothing (the landing that is a switch is the pointer's
   * own system's, testPointerAheadOfItsSystemLandsThePackageAtTheLanding),
   * a further sync still defers, and a site-built system packages
   * nothing and syncs nothing.
   */
  public function testPackageIsDeferredBeforeItsSystemLands(): void {
    $this->enableProviders();
    $this->enableModules([self::DESIGN_SYSTEM_PACKAGE]);
    $this->attachLogSink();
    $this->installConfig(['daedalus_blueprint']);
    $this->assertNull(\Drupal::service(ActiveDesignSystem::SERVICE_ID)->packageOf(self::DESIGN_SYSTEM_PACKAGE));
    $this->assertSame('aurora', \Drupal::service(ActiveDesignSystem::SERVICE_ID)->packagedSystemOf(self::DESIGN_SYSTEM_PACKAGE));

    $this->log = [];
    $this->sync()->onInstall([self::DESIGN_SYSTEM_PACKAGE]);
    $this->assertSame([], $this->log);
    foreach (self::DESIGN_SYSTEM_NAMES as $kind => $name) {
      $this->assertNull($this->load($kind, $name), "$kind deferred: its system is not active.");
    }

    $this->installConfig([self::DESIGN_SYSTEM_PACKAGE]);
    $this->assertSame([], $this->log);
    $this->assertSame('aurora', \Drupal::service(ActiveDesignSystem::SERVICE_ID)->packageOf(self::DESIGN_SYSTEM_PACKAGE));
    $report = $this->sync()->sync([self::DESIGN_SYSTEM_PACKAGE]);
    foreach (self::ORDER as $kind) {
      $this->assertSame([self::DESIGN_SYSTEM_PACKAGE => 'aurora'], $report->report($kind)->deferred(), $kind);
    }

    // A site-built system packages nothing: landing it syncs nothing.
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $this->assertSame([], $this->log);
  }

  /**
   * A pointer ahead of its system: the package lands when the system does.
   *
   * Config import's order (an install from exported config, `drush cim`
   * on a running site): the pointer is simple config, written as its
   * module installs, and the design_system entity imports only after
   * every module is in. In that window the pointer names a system that
   * does not exist, which the suite reads as no system active
   * (ActiveDesignSystem::load()), so the install batch defers the
   * package like any inactive one (nothing read, nothing thrown: the
   * skin's design-system check would refuse a save) and logs nothing.
   * The entity's landing is the activation then: the switch subscriber
   * runs the same sweep it runs for the pointer's save, so the package
   * lands in that save, under its own law (the variant on aurora's own
   * axis included), on the roster and on the request's materials delta,
   * with no cache rebuild (neither core's import nor drush's ends with
   * one). A later sync finds it converged, and a system the pointer does
   * not name landing afterwards fires nothing.
   */
  public function testPointerAheadOfItsSystemLandsThePackageAtTheLanding(): void {
    $this->enableProviders();
    $this->enableModules([self::DESIGN_SYSTEM_PACKAGE]);
    $this->attachLogSink();
    $this->installConfig(['daedalus_blueprint']);
    $active = \Drupal::service(ActiveDesignSystem::SERVICE_ID);
    $change_set = \Drupal::service('daedalus_blueprint.materials_change_set');
    // The earlier install batches landed their globals (nothing active, so
    // the aurora package is deferred here too), the way a real site's did
    // before the package's module installed.
    $this->sync()->onInstall(['daedalus_stylish']);
    $this->assertNull($this->load('variant', 'aurora_section'));

    // The pointer lands first, as a config import writes it.
    $this->log = [];
    $this->config(ActiveDesignSystem::CONFIG_NAME)->set(ActiveDesignSystem::KEY, 'aurora')->save();
    $this->assertSame('aurora', $active->activeId());
    $this->assertNull($active->load(), 'A pointer at an unlanded system is no active system.');
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR));

    // The module's install batch: the package waits for its system.
    $this->log = [];
    $this->sync()->onInstall([self::DESIGN_SYSTEM_PACKAGE]);
    $this->assertSame([], $this->log);
    foreach (self::DESIGN_SYSTEM_NAMES as $kind => $name) {
      $this->assertNull($this->load($kind, $name), "$kind deferred: its system has not landed.");
    }
    $report = $this->sync()->sync([self::DESIGN_SYSTEM_PACKAGE]);
    foreach (self::ORDER as $kind) {
      $this->assertSame([self::DESIGN_SYSTEM_PACKAGE => 'aurora'], $report->report($kind)->deferred(), $kind);
    }

    // The system's config lands: that save is the switch, and lands the
    // package.
    $this->log = [];
    $change_set->flush();
    $this->installConfig([self::DESIGN_SYSTEM_PACKAGE]);
    $this->assertSame('aurora', $active->load()?->id());
    $variant = $this->load('variant', 'aurora_rhythm');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant);
    $this->assertSame('aurora', $variant->getDesignSystem());
    $this->assertSame('rhythm', $variant->getAxis());
    $this->assertTrue($variant->isPublished());
    $this->assertNotNull($this->load('skin', 'aurora'));
    $this->assertNotNull($this->load('variant', 'aurora_section'));
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR));
    $info = $this->logged(RfcLogLevel::INFO);
    $this->assertContains(self::DESIGN_SYSTEM_PACKAGE . ': skin sync: 1 created', $info);
    $this->assertContains(self::DESIGN_SYSTEM_PACKAGE . ': variant sync: 2 created', $info);
    $builder = \Drupal::service('daedalus_blueprint.materials_roster_builder');
    $this->assertTrue($builder->build()->has(MaterialKind::Variant, 'aurora_rhythm'));
    $delta = $change_set->flush();
    $upserts = array_map(static fn (array $entry): string => $entry['kind'] . ':' . $entry['id'], $delta['upsert']);
    sort($upserts);
    $this->assertSame([
      'animation:aurora_fade',
      'token:color/accent',
      'token:space/gutter',
      'variant:aurora_rhythm',
      'variant:aurora_section',
    ], $upserts);
    // The sweep is a law change, so every published variant rides it: the
    // global the earlier batches landed (sync_test_section, wearing an
    // animation no worn skin carries) stays withheld under aurora's law
    // and resolves to a remove.
    $this->assertSame([['kind' => 'variant', 'id' => 'sync_test_section']], $delta['remove']);

    // Converged: a later sync defers nothing and is silent.
    $this->log = [];
    $revision = $variant->getRevisionId();
    $report = $this->sync()->sync([self::DESIGN_SYSTEM_PACKAGE]);
    foreach (self::ORDER as $kind) {
      $this->assertSame([], $report->report($kind)->deferred(), $kind);
    }
    $this->assertSame([], $this->log);
    $this->assertSame($revision, $this->load('variant', 'aurora_rhythm')->getRevisionId());

    // A system the pointer does not name lands: not a switch, nothing fires.
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $this->assertSame([], $this->log);
    $this->assertSame('aurora', $active->load()?->id());
  }

  /**
   * Skins wait for the default theme; the named theme's install lands them.
   *
   * The installer's window: system.theme names the site's default theme
   * (core's stark until the config phase imports it; a recipe sets it
   * before installing it) while the theme is not installed, so its chain
   * is not in the guide a skin's defaults validate against. The skins
   * kind defers every shipping module (nothing read, no error line) and
   * records the theme it waits for; a module shipping no skins is silent;
   * variants land (the lane admits a part only the theme declares). The
   * command names the wait. Installing the named theme is the landing:
   * hook_themes_installed() syncs every module, the skin lands and the
   * variant is untouched; a later sync is converged and silent. Delivery
   * follows the landing: on the strict site the variant lands withheld
   * (the skin's tokens unminted) and out of the compiled file, and the
   * theme install that lands the skin offers it, puts it on the roster
   * and in the file, in that request, with no variant write.
   */
  public function testSkinsWaitForTheDefaultThemeToInstall(): void {
    // The default is named before the providers arrive, as the installer
    // writes system.theme before any module's batch.
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->activateStrictPackageSystem();
    $this->enableProviders();
    $skins = \Drupal::service('daedalus_stylish.skin_importer');
    $this->assertSame('stark', $skins->themeAwaited());
    $this->assertNull(\Drupal::service('daedalus_stylish.variant_importer')->themeAwaited());

    $this->log = [];
    $this->sync()->onInstall([self::PACKAGE]);
    $this->assertNull($this->load('skin', 'sync_test_skin'));
    $variant = $this->load('variant', 'sync_test_section');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant);
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR));
    $this->assertSame([self::PACKAGE . ': variant sync: 1 created'], $this->logged(RfcLogLevel::INFO));
    $report = $this->sync()->sync([self::PACKAGE, self::TARGET]);
    $this->assertSame([self::PACKAGE => 'stark'], $report->report('skin')->deferredForTheme(), 'The skins kind waits; the target ships no skins and is silent.');
    $this->assertSame([], $report->report('skin')->deferred());
    $this->assertSame([], $report->report('variant')->deferredForTheme());
    $this->assertFalse($report->hasFailures());
    $this->assertVariantWithheldFromDelivery('sync_test_section');

    $tester = new CommandTester(SyncCommand::create($this->container));
    $this->assertSame(Command::SUCCESS, $tester->execute(['module' => self::PACKAGE]));
    $display = $tester->getDisplay();
    $this->assertStringContainsString(self::PACKAGE . ': variant sync_test_section unchanged', $display);
    $this->assertStringContainsString(self::PACKAGE . ': skins deferred; the default theme "stark" is not installed. They land when it is.', $display);

    // The named theme installs: that is the landing, and the skin lands.
    $this->log = [];
    $this->container->get('theme_installer')->install(['stark']);
    $this->assertNull($skins->themeAwaited());
    $skin = $this->load('skin', 'sync_test_skin');
    $this->assertInstanceOf(DaedalusStylishSkinInterface::class, $skin);
    $this->assertTrue($skin->isPublished());
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR));
    $this->assertContains(self::PACKAGE . ': skin sync: 1 created', $this->logged(RfcLogLevel::INFO));
    $this->assertSame($variant->getRevisionId(), $this->load('variant', 'sync_test_section')->getRevisionId());
    // The landing reached delivery: verdict, roster and file, no variant
    // write (the revision above is the proof of that).
    $this->assertVariantLandedInDelivery('sync_test_section');

    // Converged: a later sync waits for nothing and is silent.
    $this->log = [];
    $report = $this->sync()->sync([self::PACKAGE]);
    $this->assertSame([], $report->report('skin')->deferredForTheme());
    $this->assertSame([], $this->log);
  }

  /**
   * The default changing to an installed theme lands the waiting skins.
   *
   * Config import's order: the theme is installed in the extension phase
   * while system.theme still names the installer's default, and the config
   * phase then imports system.theme (after the package's system landed and
   * its skin was deferred). The save that names an installed default is
   * the landing: the switch subscriber runs its sweep and the skin lands.
   * Installing a theme the default does not name syncs nothing, and a
   * system.theme save that leaves the default alone (the admin theme) is
   * not a landing. Delivery follows this landing too: the variant lands
   * withheld and out of the compiled file, and the save that lands the
   * skin (the sweep) offers it, puts it on the roster and in the file,
   * in that request, with no variant write.
   */
  public function testSkinsLandWhenTheDefaultThemeChangesToAnInstalledOne(): void {
    $this->config('system.theme')->set('default', 'claro')->save();
    $this->activateStrictPackageSystem();
    $this->enableProviders();
    $this->log = [];
    $this->sync()->onInstall([self::PACKAGE]);
    $this->assertNull($this->load('skin', 'sync_test_skin'));
    $variant = $this->load('variant', 'sync_test_section');
    $this->assertNotNull($variant);
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR));

    // A theme the default does not name installs: not a landing.
    $this->log = [];
    $this->container->get('theme_installer')->install(['stark']);
    $this->assertNull($this->load('skin', 'sync_test_skin'));
    $this->assertSame([], $this->log);
    $this->assertSame('claro', \Drupal::service('daedalus_stylish.skin_importer')->themeAwaited());
    // Read after the install (which rebuilt the container): this is the
    // request that will save system.theme, and on an install the sweep
    // that landed the package's members already read the verdict in it,
    // so the save's sweep finds the verdict memoized under the pre-skin
    // law, exactly as the installer's does.
    $this->assertVariantWithheldFromDelivery('sync_test_section');

    // The default changes to the installed theme: the save lands the skin.
    $this->log = [];
    $this->config('system.theme')->set('default', 'stark')->save();
    $skin = $this->load('skin', 'sync_test_skin');
    $this->assertInstanceOf(DaedalusStylishSkinInterface::class, $skin);
    $this->assertTrue($skin->isPublished());
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR));
    $this->assertContains(self::PACKAGE . ': skin sync: 1 created', $this->logged(RfcLogLevel::INFO));
    // The sweep's landing reached delivery in this same request, the
    // variant untouched (same revision): verdict, roster and file.
    $this->assertSame($variant->getRevisionId(), $this->load('variant', 'sync_test_section')->getRevisionId());
    $this->assertVariantLandedInDelivery('sync_test_section');

    // The admin theme is not the law's input: nothing fires.
    $this->log = [];
    $this->config('system.theme')->set('admin', 'stark')->save();
    $this->assertSame([], $this->log);
  }

  /**
   * Activating a packaged system lands what only its own law admits.
   *
   * A member validates against its system's tokens and materials but
   * against the SITE's law, and the site layers a package's law (its
   * axes, scales, bindings) only while its system is active. So the
   * package is deferred whole (the container scope has no rhythm axis
   * for its variant, nothing is read) while it lands under borealis,
   * and activating aurora syncs it under its own law: every kind lands
   * in the activation itself (no rebuild), the variant on aurora's axis
   * included, members, published, on the roster, and on the request's
   * materials delta (the sync runs before the switch touches the
   * incoming system's ids). Every activation syncs every enabled module,
   * so the globals land at the first one and every later activation
   * (a site-built system, aurora again) finds them converged and silent.
   */
  public function testActivationSyncsTheIncomingPackage(): void {
    $this->enableProviders();
    $this->enableModules([self::DESIGN_SYSTEM_PACKAGE]);
    $this->attachLogSink();
    $this->installConfig(['daedalus_blueprint']);
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $active = \Drupal::service(ActiveDesignSystem::SERVICE_ID);
    // The first activation lands daedalus_stylish's globals and the packageless
    // package; the switches below find them converged.
    $active->activate('borealis');
    $this->enforce('strict');
    $validator = \Drupal::service('daedalus_stylish.style_map_validator');

    // Landing while borealis is active: the container scope has no rhythm
    // axis (aurora's declaration is not layered) and the package waits.
    $this->log = [];
    $this->installConfig([self::DESIGN_SYSTEM_PACKAGE]);
    $this->assertSame(['style'], $validator->scopeAxes('kind:container'));
    $this->assertSame([], $this->log);
    $this->assertNull($this->load('variant', 'aurora_rhythm'));
    $this->assertNull($this->load('variant', 'aurora_section'));

    // Activation: aurora's law is the site's, and the switch's sync lands
    // the variant before anything reads the roster.
    $this->log = [];
    $change_set = \Drupal::service('daedalus_blueprint.materials_change_set');
    $change_set->flush();
    $active->activate('aurora');
    $this->assertSame(['style', 'rhythm'], $validator->scopeAxes('kind:container'));
    $variant = $this->load('variant', 'aurora_rhythm');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant);
    $this->assertSame('aurora', $variant->getDesignSystem());
    $this->assertSame('rhythm', $variant->getAxis());
    $this->assertSame(self::DESIGN_SYSTEM_PACKAGE, $variant->getProvider());
    $this->assertTrue($variant->isPublished());
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR));
    $this->assertSame([
      self::DESIGN_SYSTEM_PACKAGE . ': skin sync: 1 created',
      self::DESIGN_SYSTEM_PACKAGE . ': variant sync: 2 created',
    ], $this->logged(RfcLogLevel::INFO));
    $builder = \Drupal::service('daedalus_blueprint.materials_roster_builder');
    $this->assertTrue($builder->build()->has(MaterialKind::Variant, 'aurora_rhythm'));
    $delta = $change_set->flush();
    $upserts = array_map(static fn (array $entry): string => $entry['kind'] . ':' . $entry['id'], $delta['upsert']);
    sort($upserts);
    $this->assertSame([
      'animation:aurora_fade',
      'token:color/accent',
      'token:space/gutter',
      'variant:aurora_rhythm',
      'variant:aurora_section',
    ], $upserts);
    // The sweep is a law change, so every published variant rides it: the
    // global the earlier batches landed (sync_test_section, wearing an
    // animation no worn skin carries) stays withheld under aurora's law
    // and resolves to a remove.
    $this->assertSame([['kind' => 'variant', 'id' => 'sync_test_section']], $delta['remove']);

    // A site-built system packages nothing: activating it finds every
    // global converged (silent), and the package's members leave the
    // roster (their entities stay).
    $this->log = [];
    $active->activate('borealis');
    $this->assertSame([], $this->log);
    $this->assertFalse($builder->build()->has(MaterialKind::Variant, 'aurora_rhythm'));
    $this->assertNotNull($this->load('variant', 'aurora_rhythm'));

    // Back to aurora: converged, so the sync is silent.
    $active->activate('aurora');
    $this->assertSame([], $this->log);
    $this->assertTrue($builder->build()->has(MaterialKind::Variant, 'aurora_rhythm'));
  }

  /**
   * An install batch syncs inside the installer with no system active.
   *
   * `drush si recipes/daedalus_recipe` installs daedalus_stylish, daedalus_stylish_layouts and
   * the olivero package in one batch (hook_modules_installed() with
   * daedalus_stylish in it sweeps every enabled module) on a site that has
   * no design system and no skin yet; the recipe's activation action
   * gives it olivero afterwards, and the installer ends with no cache
   * rebuild. The batch used to wait for the installer, because the
   * suite's gap binding refused the Columns variant (gap
   * var(--space-gutter)) with nothing minted and a refusal the same
   * install resolves was not a failure `drush si` should report. Law is
   * the verdict's now, so the batch syncs inside the installer
   * (install_state set) like any other: the layouts' globals land with
   * nothing failed and no error line, the two carrying the gutter gap
   * withheld (STYLE_VALUE_NOT_ON_SCALE on gap, nothing minted) and the
   * width family offered, the olivero package is deferred whole (its
   * system is not active), and the batch logs its info lines.
   */
  public function testInstallBatchSyncsInsideTheInstaller(): void {
    $this->enableModules(['daedalus_stylish_layouts', 'daedalus_stylish_olivero_skin']);
    $this->attachLogSink();
    $this->installConfig(['daedalus_blueprint']);
    $this->assertNull(\Drupal::service(ActiveDesignSystem::SERVICE_ID)->load());

    $GLOBALS['install_state'] = ['installation_finished' => FALSE];
    try {
      $this->sync()->onInstall(['daedalus_stylish', 'daedalus_stylish_layouts', 'daedalus_stylish_olivero_skin']);
    }
    finally {
      unset($GLOBALS['install_state']);
    }
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR), implode("\n", $this->logged(RfcLogLevel::ERROR)));
    $layouts = array_values(array_filter(
      $this->logged(RfcLogLevel::INFO),
      static fn (string $line): bool => str_starts_with($line, 'daedalus_stylish_layouts: variant sync: '),
    ));
    $this->assertCount(1, $layouts, implode("\n", $this->logged(RfcLogLevel::INFO)));
    $this->assertStringEndsWith(' created', $layouts[0], 'every layouts global landed inside the installer, none failed');
    $this->assertStringNotContainsString('failed', $layouts[0]);
    foreach (['columns', 'row', 'container_site'] as $name) {
      $variant = $this->load('variant', $name);
      $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant, "$name landed in the install batch");
      $this->assertTrue($variant->isPublished(), $name);
      $this->assertNull($variant->getDesignSystem(), "$name is a global");
    }
    $this->assertNull($this->load('skin', 'olivero'), 'the olivero package is deferred: its system is not active');
    $this->assertNull($this->load('variant', 'olivero_fade_in'), 'the package\'s variants are deferred with its skin');
    $withheld = \Drupal::service('daedalus_stylish.variant_conformance')->withheld();
    foreach (['columns', 'row'] as $name) {
      $this->assertArrayHasKey($name, $withheld, "$name is withheld with nothing minted");
      $this->assertSame(['STYLE_VALUE_NOT_ON_SCALE'], array_map(static fn ($error) => $error->getCode(), $withheld[$name]), $name);
      $this->assertStringContainsString('gap', $withheld[$name][0]->getPath(), $name);
    }
    $this->assertArrayNotHasKey('container_site', $withheld, 'the width family spells the gutter inside max(), not as a bare reference');
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder');
    $roster->reset();
    $this->assertFalse($roster->build()->has(MaterialKind::Variant, 'columns'), 'withheld: not offered');
    $this->assertTrue($roster->build()->has(MaterialKind::Variant, 'container_site'), 'the width family is offered');
  }

  /**
   * The skinless batch lands its globals withheld; the activation offers them.
   *
   * `drush recipe recipes/daedalus_recipe` on a site that already exists runs
   * the batch outside the installer, on a site that has no design system
   * and no skin yet, and the recipe's activation action gives it olivero
   * afterwards. The batch lands every layouts global with nothing failed:
   * the suite's gap binding is law the verdict judges, so the Columns and
   * Row variants (gap var(--space-gutter), nothing minted) land withheld
   * with STYLE_VALUE_NOT_ON_SCALE on gap while the width family
   * (container_site spells the gutter inside max(), not as a bare
   * reference) is offered, and the olivero package is deferred whole. The
   * activation syncs every enabled module under olivero's law: the package
   * lands, the layouts are converged (the same revisions, silent in the
   * log), the verdict withholds neither Columns nor Row now that the
   * gutter is minted, the roster offers them with no re-sync and no
   * rebuild, and a further sync is converged.
   */
  public function testSkinlessBatchLandsWithheldAndTheActivationOffers(): void {
    $this->enableModules(['daedalus_stylish_layouts', 'daedalus_stylish_olivero_skin']);
    $this->attachLogSink();
    $this->installConfig(['daedalus_blueprint']);
    $active = \Drupal::service(ActiveDesignSystem::SERVICE_ID);
    $this->assertNull($active->load());

    // The batch, on the skinless site, outside the installer: every
    // layouts global lands, the two gutter gaps withheld.
    $this->sync()->onInstall(['daedalus_stylish', 'daedalus_stylish_layouts', 'daedalus_stylish_olivero_skin']);
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR), implode("\n", $this->logged(RfcLogLevel::ERROR)));
    $layouts = array_values(array_filter(
      $this->logged(RfcLogLevel::INFO),
      static fn (string $line): bool => str_starts_with($line, 'daedalus_stylish_layouts: variant sync: '),
    ));
    $this->assertCount(1, $layouts, implode("\n", $this->logged(RfcLogLevel::INFO)));
    $this->assertStringEndsWith(' created', $layouts[0], 'every layouts global landed in the batch, none failed');
    $this->assertStringNotContainsString('failed', $layouts[0]);
    $revisions = [];
    foreach (['columns', 'row', 'container_site'] as $name) {
      $variant = $this->load('variant', $name);
      $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant, "$name landed in the skinless batch");
      $this->assertTrue($variant->isPublished(), $name);
      $this->assertNull($variant->getDesignSystem(), "$name is a global, offered under every system");
      $this->assertSame('daedalus_stylish_layouts', $variant->getProvider(), $name);
      $revisions[$name] = $variant->getRevisionId();
    }
    $this->assertSame('var(--space-gutter)', $this->load('variant', 'columns')->getStyles()['']['default']['gap'], 'the gap lands as authored');
    $this->assertNull($this->load('skin', 'olivero'), 'the olivero package waited for its activation');
    $conformance = \Drupal::service('daedalus_stylish.variant_conformance');
    $withheld = $conformance->withheld();
    foreach (['columns', 'row'] as $name) {
      $this->assertArrayHasKey($name, $withheld, "$name is withheld with nothing minted");
      $this->assertSame(['STYLE_VALUE_NOT_ON_SCALE'], array_map(static fn ($error) => $error->getCode(), $withheld[$name]), $name);
      $this->assertStringContainsString('gap', $withheld[$name][0]->getPath(), $name);
    }
    $this->assertArrayNotHasKey('container_site', $withheld, 'the width family conforms with nothing minted');
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder');
    $roster->reset();
    $this->assertFalse($roster->build()->has(MaterialKind::Variant, 'columns'), 'withheld: not offered on the skinless site');
    $this->assertTrue($roster->build()->has(MaterialKind::Variant, 'container_site'));

    // The recipe's config import lands the system, then its action
    // activates it: the sweep lands the package and finds the layouts
    // converged; the verdict under olivero's law offers the gutter gaps.
    $this->installConfig(['daedalus_stylish_olivero_skin']);
    $this->log = [];
    $active->activate('olivero');
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR), implode("\n", $this->logged(RfcLogLevel::ERROR)));
    $info = $this->logged(RfcLogLevel::INFO);
    $this->assertContains('daedalus_stylish_olivero_skin: skin sync: 1 created', $info);
    $this->assertSame([], array_values(array_filter(
      $info,
      static fn (string $line): bool => str_starts_with($line, 'daedalus_stylish_layouts: '),
    )), 'the layouts are converged: unchanged is silent');
    foreach ($revisions as $name => $revision) {
      $this->assertSame($revision, $this->load('variant', $name)->getRevisionId(), "$name was not re-synced by the activation");
    }
    $withheld = $conformance->withheld();
    $this->assertArrayNotHasKey('columns', $withheld, 'Columns conforms under olivero (the gutter is minted)');
    $this->assertArrayNotHasKey('row', $withheld, 'Row conforms under olivero (the gutter is minted)');
    $roster->reset();
    $this->assertTrue($roster->build()->has(MaterialKind::Variant, 'columns'), 'the roster offers Columns with no rebuild');
    $this->assertTrue($roster->build()->has(MaterialKind::Variant, 'row'), 'the roster offers Row with no rebuild');

    // Converged: the rebuild a fresh site used to need finds nothing.
    $report = $this->sync()->sync();
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
    $this->assertSame(ShippedDefinitionsImporter::UNCHANGED, $report->report('variant')->outcome('daedalus_stylish_layouts', 'columns'));
  }

  /**
   * Install syncs the batch; daedalus_stylish in the batch sweeps every provider.
   *
   * The batch entry syncs only the installed modules (the target's dropped
   * variants file stays absent), and daedalus_stylish's own install syncs
   * every enabled module, so providers enabled before daedalus_stylish existed
   * arrive the moment the system that hosts them does. Both log.
   */
  public function testInstallSyncsBatchAndDaedalusStylishSweepsAll(): void {
    $this->enableProviders();
    $this->dropVariantsFile('card', [
      'card_section' => [
        'label' => 'Card section',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => '3rem']]],
      ],
    ]);
    $this->sync()->onInstall([self::PACKAGE]);
    foreach (self::PACKAGE_NAMES as $kind => $name) {
      $this->assertNotNull($this->load($kind, $name), $kind);
    }
    $this->assertArrayHasKey('sync_test_fade', $this->load('skin', 'sync_test_skin')->animations(), 'The skin carries the package\'s animation.');
    $this->assertNull($this->load('variant', 'card_section'));
    $this->assertSame([
      self::PACKAGE . ': skin sync: 1 created',
      self::PACKAGE . ': variant sync: 1 created',
    ], $this->logged(RfcLogLevel::INFO));
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR));

    $this->log = [];
    $this->sync()->onInstall(['daedalus_stylish']);
    $this->assertNotNull($this->load('variant', 'card_section'));
    // The package converged already: unchanged is silent.
    $this->assertSame([self::TARGET . ': variant sync: 1 created'], $this->logged(RfcLogLevel::INFO));
  }

  /**
   * A rebuild activates a variants file dropped beside an installed module.
   *
   * The pulled-package state: the module was installed long ago, its
   * per-id variants file arrived with a pull, and nobody ran a command.
   * hook_rebuild(), invoked through the module handler as
   * drupal_flush_all_caches() does, lands it; pending entity definition
   * updates on a kind's own entity type skip the sync with one error line
   * naming the type, while pending updates on any other type are not this
   * lane's concern; a definition the lane refuses is logged at error while
   * the rest of the file lands; and a converged rebuild logs nothing.
   */
  public function testRebuildActivatesDroppedVariantsFile(): void {
    $this->enableProviders();
    $this->dropVariantsFile('card', [
      'card_section' => [
        'label' => 'Card section',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => '3rem']]],
      ],
    ]);

    // Entity types the test never installed are pending updates to the
    // definition update manager, none of them a kind's own: not this
    // lane's concern, so the rebuild syncs and logs no guard line.
    $update_manager = \Drupal::entityDefinitionUpdateManager();
    $this->assertTrue($update_manager->needsUpdates());
    $this->assertArrayNotHasKey('daedalus_variant', $update_manager->getChangeList());
    $this->assertArrayNotHasKey('daedalus_skin', $update_manager->getChangeList());
    $this->log = [];
    \Drupal::moduleHandler()->invoke('daedalus_stylish', 'rebuild');
    $variant = $this->load('variant', 'card_section');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant);
    $this->assertTrue($variant->isShipped());
    $this->assertSame(self::TARGET, $variant->getProvider());
    $this->assertTrue($variant->isPublished());
    // Every enabled module synced: the package too.
    $this->assertNotNull($this->load('variant', 'sync_test_section'));
    $this->assertContains(self::TARGET . ': variant sync: 1 created', $this->logged(RfcLogLevel::INFO));
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR));

    // Converged: a rebuild is silent.
    $this->log = [];
    \Drupal::moduleHandler()->invoke('daedalus_stylish', 'rebuild');
    $this->assertSame([], $this->log);

    // A pending update on a kind's own entity type is the guard's: with
    // the variant storage's weight field forgotten by the last-installed
    // repository (a field to create, the shape of pulled code whose update
    // hook has not run), the rebuild skips the sync with one error line
    // naming the type and lands nothing; the definition restored, the
    // next rebuild lands what waited.
    $this->dropVariantsFile('broken', [
      'broken_section' => ['label' => 'Broken section', 'scope' => 'kind:container'],
      'sound_section' => [
        'label' => 'Sound section',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => '1rem']]],
      ],
    ]);
    $repository = \Drupal::service('entity.last_installed_schema.repository');
    $weight = $repository->getLastInstalledFieldStorageDefinitions('daedalus_variant')['weight'];
    $repository->deleteLastInstalledFieldStorageDefinition($weight);
    $this->assertArrayHasKey('daedalus_variant', $update_manager->getChangeList());
    $this->log = [];
    \Drupal::moduleHandler()->invoke('daedalus_stylish', 'rebuild');
    $this->assertNull($this->load('variant', 'sound_section'));
    $this->assertSame([], $this->logged(RfcLogLevel::INFO));
    $this->assertSame(['Shipped daedalus_stylish definitions were not synced: entity definition updates are pending for daedalus_variant. Run `drush updb` (daedalus_stylish ships the update hooks that install them); the cache rebuild at its end syncs them.'], $this->logged(RfcLogLevel::ERROR));
    $repository->setLastInstalledFieldStorageDefinition($weight);
    $this->assertArrayNotHasKey('daedalus_variant', $update_manager->getChangeList());

    // A refused definition is one error line; its file's sound neighbor
    // still lands, and the earlier variant is untouched.
    $this->log = [];
    \Drupal::moduleHandler()->invoke('daedalus_stylish', 'rebuild');
    $this->assertNotNull($this->load('variant', 'sound_section'));
    $this->assertNull($this->load('variant', 'broken_section'));
    $this->assertSame([self::TARGET . ': variant sync: 1 created, 1 failed'], $this->logged(RfcLogLevel::INFO));
    $errors = $this->logged(RfcLogLevel::ERROR);
    $this->assertCount(1, $errors);
    $this->assertStringStartsWith(self::TARGET . ': variant broken_section failed: ', $errors[0]);
    $this->assertStringContainsString('[INVALID_SHIPPED_VARIANT]', $errors[0]);
  }

  /**
   * The command runs the orchestrated sync and prints every outcome.
   *
   * One line per definition in sync order (`MODULE: KIND NAME outcome`);
   * a module shipping nothing is told which files it could ship; a
   * definition the lane refuses prints its path, code, message, and hint
   * after the outcomes and fails the run while the rest of its file
   * lands; a converged module reads unchanged and exits clean; a
   * variant the site's law refuses lands and is named after the
   * outcomes, before the failures, with the verdict's refusals and the
   * enforcement, while a landed variant the law accepts and an unchanged
   * withheld one are not.
   */
  public function testSyncCommand(): void {
    $this->enableProviders();
    $tester = new CommandTester(SyncCommand::create($this->container));

    $this->assertSame(Command::SUCCESS, $tester->execute(['module' => self::PACKAGE]));
    $display = $tester->getDisplay();
    $positions = [];
    foreach (self::PACKAGE_NAMES as $kind => $name) {
      $positions[$kind] = strpos($display, self::PACKAGE . ": $kind $name created");
      $this->assertNotFalse($positions[$kind], $display);
      $this->assertNotNull($this->load($kind, $name), $kind);
    }
    $this->assertSame(self::ORDER, array_keys($positions));
    $ordered = array_values($positions);
    sort($ordered);
    $this->assertSame($ordered, array_values($positions), 'The lines print in sync order.');

    // A module shipping nothing is told which files it could ship.
    $this->assertSame(Command::SUCCESS, $tester->execute(['module' => 'system']));
    $this->assertStringContainsString('system is not enabled or ships no daedalus_stylish definitions: no system.daedalus_stylish.skins.yml, system.daedalus_stylish.variants.yml or a per-id file beside one (e.g. system.daedalus_stylish.variants.*.yml).', $tester->getDisplay());

    // A refused definition fails the run after the rest of its file lands,
    // printing its path, code, message, and hint.
    $this->dropVariantsFile('cards', [
      'broken_card' => [
        'label' => 'Broken card',
        'scope' => 'kind:container',
      ],
      'sound_card' => [
        'label' => 'Sound card',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => '3rem']]],
      ],
    ]);
    $this->assertSame(Command::FAILURE, $tester->execute(['module' => self::TARGET]));
    $display = $tester->getDisplay();
    $this->assertStringContainsString(self::TARGET . ': variant sound_card created', $display);
    $this->assertStringContainsString(self::TARGET . ': variant broken_card failed', $display);
    $this->assertStringContainsString('  broken_card [INVALID_SHIPPED_VARIANT]: The "broken_card" entry is missing the "styles" key(s).', $display);
    $this->assertStringContainsString('    Hint: A shipped variant definition consists of label, scope, and styles, plus an optional axis.', $display);
    $this->assertNull($this->load('variant', 'broken_card'));
    $this->assertNotNull($this->load('variant', 'sound_card'));

    // A converged module reads unchanged.
    $this->assertSame(Command::SUCCESS, $tester->execute(['module' => self::PACKAGE]));
    $this->assertStringContainsString(self::PACKAGE . ': variant sync_test_section unchanged', $tester->getDisplay());

    // A design-system package whose system is not active is one deferred
    // line naming the system, not a file-shipping miss and not a failure.
    $this->enableModules([self::DESIGN_SYSTEM_PACKAGE]);
    $this->installConfig(['daedalus_blueprint']);
    $tester = new CommandTester(SyncCommand::create($this->container));
    $this->assertSame(Command::SUCCESS, $tester->execute(['module' => self::DESIGN_SYSTEM_PACKAGE]));
    $this->assertSame(self::DESIGN_SYSTEM_PACKAGE . ': deferred; it packages the "aurora" design system, which is not the active one. Its definitions land when the system becomes active.', trim($tester->getDisplay()));
    $this->assertNull($this->load('variant', 'aurora_section'));

    // A variant the site's law refuses lands (structure alone fails the
    // sync) and is named after the outcomes and before the failures with
    // the verdict's refusals; a landed variant the law accepts and an
    // UNCHANGED one the law withholds (announced when it landed) are not
    // named. The strict fixture is testLawRefusedGlobalLandsWithheld's:
    // padding bound to the one scale, one minted token, strict law.
    $this->enableModules(['daedalus_stylish_control_test']);
    $state = $this->container->get('state');
    $state->set('daedalus_stylish_control_test.site', [
      'token_scales' => ['space' => ['label' => 'Space', 'shape' => 'dimension']],
    ]);
    $state->set('daedalus_stylish_control_test.kinds', [
      'container' => ['property_groups' => ['spacing' => ['constraints' => ['padding' => ['scale' => 'space']]]]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $skin = DaedalusStylishSkin::create(['label' => 'Strict skin']);
    $skin->setPackageTokens(['space' => ['4' => '1rem']])->save();
    $this->activateSkin($skin, 'strict_system');
    $this->enforce('strict');
    $this->dropVariantsFile('withheld', [
      'literal_section' => [
        'label' => 'Literal section',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => '0.75em']]],
      ],
      'bound_section' => [
        'label' => 'Bound section',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => 'var(--space-4)']]],
      ],
    ]);
    $tester = new CommandTester(SyncCommand::create($this->container));
    // broken_card is still on disk, so the run fails; the withheld line
    // sits between the outcomes and the failure.
    $this->assertSame(Command::FAILURE, $tester->execute(['module' => self::TARGET]));
    $display = $tester->getDisplay();
    $created = strpos($display, self::TARGET . ': variant literal_section created');
    $withheld = strpos($display, self::TARGET . ': variant literal_section landed, withheld under the current law (strict): ');
    $failed = strpos($display, self::TARGET . ': variant broken_card failed');
    $this->assertNotFalse($created, $display);
    $this->assertNotFalse($withheld, $display);
    $this->assertNotFalse($failed, $display);
    $this->assertTrue($created < $withheld && $withheld < $failed, $display);
    $withheld_line = substr($display, $withheld, strpos($display, "\n", $withheld) - $withheld);
    $this->assertStringContainsString('[STYLE_VALUE_NOT_ON_SCALE]', $withheld_line);
    $this->assertStringContainsString(self::TARGET . ': variant bound_section created', $display);
    $this->assertStringNotContainsString('bound_section landed', $display, 'a landed variant the law accepts is not named');
    $this->assertStringContainsString(self::TARGET . ': variant sound_card unchanged', $display);
    $this->assertStringNotContainsString('sound_card landed', $display, 'an unchanged variant is not re-announced');
    // The command reset the roster (its cascade forgets the verdict) before
    // it read the verdict; the same request reads the same fresh answer.
    $verdict = \Drupal::service('daedalus_stylish.variant_conformance')->withheld();
    $this->assertArrayHasKey('sound_card', $verdict, 'the unchanged literal is withheld all the same');
    $this->assertArrayNotHasKey('bound_section', $verdict);
    $this->assertTrue($this->load('variant', 'literal_section')->isPublished());
  }

}
