<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Test\TestDatabase;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Drush\Commands\SyncCommand;
use Drupal\daedalus_stylish\ShippedDefinitionsImporter;
use Drupal\daedalus_stylish\ShippedSync;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\VariantImporter;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the orchestrated sync of shipped definitions and its entries.
 *
 * The daedalus_stylish_shipped_sync_test module ships one self-referencing package: an
 * animation, a swatch, a skin token, and a variant wearing all three. On a
 * strict site the variant lands only after the materials it names are
 * published, so the package proves the orchestrator's order (animations,
 * swatches, skins, variants) the way the old per-kind order failed. The
 * daedalus_stylish_design_system_test module packages the aurora design system (the same
 * four kinds as members, plus a variant on an axis only aurora's own law
 * declares) and proves the package rule: the package is deferred whole while
 * aurora is not the active system (install, the system's config landing, a
 * rebuild in between), and its activation lands it under its own law. The
 * rebuild entry is proven the way it failed on a pulled site: a per-id variants
 * file dropped beside an already installed module goes live on a cache rebuild
 * alone. That module is created under the test's own real site directory
 * (sites/simpletest/ID), never a real module directory, and removed on teardown
 * (the VariantShipperTest pattern). The activation entry is proven on the
 * recipe's own shape with the real modules, twice: a site install, where the
 * batch waits for the installer and the recipe's activation of the olivero
 * package lands every enabled module's definitions whole; and a recipe applied
 * to an existing site, where the batch syncs every provider on a site with no
 * system and no skin, the suite's gap binding refuses daedalus_stylish_layouts' Columns
 * variant there, and the activation lands it without a rebuild.
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
    'animation' => 'aurora_fade',
    'swatch' => 'aurora_accent',
    'skin' => 'aurora',
    'variant' => 'aurora_band',
  ];

  /**
   * The orchestrator's kinds, in dependency order.
   */
  protected const ORDER = ['animation', 'swatch', 'skin', 'variant'];

  /**
   * The package's shipped definition per kind: kind => machine name.
   */
  protected const PACKAGE_NAMES = [
    'animation' => 'sync_test_fade',
    'swatch' => 'sync_test_accent',
    'skin' => 'sync_test_skin',
    'variant' => 'sync_test_band',
  ];

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
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    // Saves mirror motion-* and --swatch-* references into the usage
    // tables, and the shipped package wears both.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
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
   * Captures the daedalus_stylish channel into $this->log.
   *
   * A test enabling a module of its own re-attaches after doing so:
   * enableModules() rebuilds the container and drops the sink.
   */
  protected function attachLogSink(): void {
    $this->container->get('logger.factory')->addLogger(new class ($this->log) implements LoggerInterface {
      use RfcLoggerTrait;

      /**
       * The test's log array, aliased.
       */
      protected array $sink;

      /**
       * Promotion can't take a reference: the sink must alias the test's
       * array so assertions read what the hooks logged.
       */
      public function __construct(array &$sink) {
        $this->sink = &$sink;
      }

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        if (($context['channel'] ?? NULL) === 'daedalus_stylish') {
          $placeholders = array_filter($context, static fn (string $key): bool => str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY);
          $this->sink[] = [$level, strtr((string) $message, array_map('strval', $placeholders))];
        }
      }

    });
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
   * Writes a per-id variants file beside the target module.
   */
  protected function dropVariantsFile(string $id, array $definitions): void {
    $importer = \Drupal::service('daedalus_stylish.variant_importer');
    assert($importer instanceof VariantImporter);
    file_put_contents($this->targetPath . '/' . self::TARGET . '.' . $importer->perFileSuffix($id), Yaml::encode($definitions));
  }

  /**
   * One run lands a package whose variant wears its own other kinds.
   *
   * The site is strict, so every reference must name a published
   * material: the variant importer on its own (the old per-kind order,
   * variants first) refuses the variant for all three, and the
   * orchestrator's order lands everything CREATED with no failure.
   */
  public function testKindsSyncInDependencyOrder(): void {
    // The activation precedes the skin, as a recipe's config precedes its
    // content: the site-built system names a skin the lane has not synced
    // yet, and the package's global members validate against it once it
    // lands (the module packages no system of its own). The system is
    // strict: enforcement is its own property. The providers arrive after
    // the activation (an activation syncs every enabled module, and the
    // claim below is what one kind alone refuses on the strict site).
    DesignSystem::create(['id' => 'sync_test', 'label' => 'Sync test', 'default_skin' => 'sync_test_skin'])->save();
    \Drupal::service(ActiveDesignSystem::SERVICE_ID)->activate('sync_test');
    $this->enforce('strict');
    $this->enableProviders();

    $report = \Drupal::service('daedalus_stylish.variant_importer')->sync([self::PACKAGE]);
    $this->assertSame(ShippedDefinitionsImporter::FAILED, $report->outcome(self::PACKAGE, 'sync_test_band'));
    $codes = array_map(static fn ($error) => $error->getCode(), $report->errors(self::PACKAGE, 'sync_test_band'));
    sort($codes);
    $this->assertSame(['STYLE_ANIMATION_NOT_PUBLISHED', 'STYLE_SWATCH_NOT_PUBLISHED', 'STYLE_TOKEN_NOT_MINTED'], $codes);
    $this->assertNull($this->load('variant', 'sync_test_band'));

    $report = $this->sync()->sync([self::PACKAGE]);
    $this->assertSame(self::ORDER, array_keys($report->reports()));
    $this->assertSame(self::ORDER, array_keys($this->sync()->importers()));
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
    $this->assertFalse($report->isEmpty());
    foreach (self::PACKAGE_NAMES as $kind => $name) {
      $this->assertSame($kind, $report->report($kind)->kind());
      $this->assertSame([self::PACKAGE => [$name => ShippedDefinitionsImporter::CREATED]], $report->report($kind)->outcomes(), $kind);
      $entity = $this->load($kind, $name);
      $this->assertNotNull($entity, $kind);
      $this->assertSame(self::PACKAGE, $entity->getProvider(), $kind);
    }
    $variant = $this->load('variant', 'sync_test_band');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant);
    $this->assertTrue($variant->isPublished());
    $this->assertSame('var(--space-gutter)', $variant->getStyles()['']['default']['padding']);

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
   * global variant referencing an unminted token still fails strict, ~
   * is the explicit opt-out, an explicit id joins that system, and an
   * unknown id is refused.
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
      self::DESIGN_SYSTEM_PACKAGE . ': animation sync: 1 created',
      self::DESIGN_SYSTEM_PACKAGE . ': swatch sync: 1 created',
      self::DESIGN_SYSTEM_PACKAGE . ': skin sync: 1 created',
      self::DESIGN_SYSTEM_PACKAGE . ': variant sync: 2 created',
    ], $this->logged(RfcLogLevel::INFO));
    foreach (self::DESIGN_SYSTEM_NAMES + ['rhythm' => 'aurora_rhythm'] as $kind => $name) {
      $entity = $this->load($kind === 'rhythm' ? 'variant' : $kind, $name);
      $this->assertNotNull($entity, $name);
      $this->assertSame('aurora', $entity->getDesignSystem(), $name);
      $this->assertTrue($entity->isPublished(), $name);
    }
    $roster = $builder->build();
    $this->assertTrue($roster->has(MaterialKind::Variant, 'aurora_band'));
    $this->assertTrue($roster->has(MaterialKind::Variant, 'aurora_rhythm'));
    $this->assertTrue($roster->has(MaterialKind::Swatch, 'aurora_accent'));
    $this->assertTrue($roster->has(MaterialKind::Animation, 'aurora_fade'));
    $this->assertSame('aurora', $roster->get(MaterialKind::Variant, 'aurora_band')->designSystem);
    $validator = \Drupal::service('daedalus_stylish.style_map_validator');
    $wearing = [
      'container:' . $this->container->get('uuid')->generate() => [
        'variants' => [['variant' => 'aurora_band', 'fragment' => '']],
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
    $this->assertFalse($roster->has(MaterialKind::Variant, 'aurora_band'));
    $this->assertFalse($roster->has(MaterialKind::Swatch, 'aurora_accent'));
    $this->assertFalse($roster->has(MaterialKind::Animation, 'aurora_fade'));
    $this->assertSame([], $roster->tokens());
    $members = array_map(static fn ($record) => $record->id, array_values(array_filter($builder->members('aurora'), static fn ($record) => $record->kind !== MaterialKind::Token)));
    sort($members);
    $this->assertSame(['aurora_accent', 'aurora_band', 'aurora_fade', 'aurora_rhythm'], $members);
    $errors = $validator->validate($wearing);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VARIANT_DESIGN_SYSTEM_INACTIVE', $errors[0]->getCode());
    $this->assertSame('aurora', $errors[0]->getContext()['design_system']);
    $this->assertStringContainsString('belongs to the Aurora design system, which is not active', $errors[0]->getMessage());
    $this->assertStringContainsString('offered only while it is active; wear a variant of the active system', $errors[0]->getHint());
    $this->assertSame([self::DESIGN_SYSTEM_PACKAGE => 'aurora'], $this->sync()->sync([self::DESIGN_SYSTEM_PACKAGE])->report('variant')->deferred());

    // The key's spellings, in a module packaging nothing (the target),
    // under borealis (a system carrying no skin, so the active skin is
    // none and no token is minted). Absent: global, validated in the
    // active system's universe: the unminted token still fails strict.
    // ~: the same, said explicitly. aurora: a member, validated in
    // aurora's universe, its token resolves (a module packaging nothing
    // is never deferred, whichever system its members join). nope:
    // refused.
    $this->dropVariantsFile('members', [
      'global_band' => [
        'label' => 'Global band',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => 'var(--space-gutter)']]],
      ],
      'opted_out_band' => [
        'label' => 'Opted out band',
        'scope' => 'kind:container',
        'design_system' => NULL,
        'styles' => ['' => ['default' => ['padding' => 'var(--space-gutter)']]],
      ],
      'joined_band' => [
        'label' => 'Joined band',
        'scope' => 'kind:container',
        'design_system' => 'aurora',
        'styles' => ['' => ['default' => ['padding' => 'var(--space-gutter)']]],
      ],
      'lost_band' => [
        'label' => 'Lost band',
        'scope' => 'kind:container',
        'design_system' => 'nope',
        'styles' => ['' => ['default' => ['padding' => '1rem']]],
      ],
    ]);
    $report = \Drupal::service('daedalus_stylish.variant_importer')->sync([self::TARGET]);
    $this->assertSame([
      'global_band' => ShippedDefinitionsImporter::FAILED,
      'opted_out_band' => ShippedDefinitionsImporter::FAILED,
      'joined_band' => ShippedDefinitionsImporter::CREATED,
      'lost_band' => ShippedDefinitionsImporter::FAILED,
    ], $report->outcomes()[self::TARGET]);
    $this->assertSame(['STYLE_TOKEN_NOT_MINTED'], array_map(static fn ($error) => $error->getCode(), $report->errors(self::TARGET, 'global_band')));
    $this->assertSame(['STYLE_TOKEN_NOT_MINTED'], array_map(static fn ($error) => $error->getCode(), $report->errors(self::TARGET, 'opted_out_band')));
    $this->assertSame(['SHIPPED_DESIGN_SYSTEM_UNKNOWN'], array_map(static fn ($error) => $error->getCode(), $report->errors(self::TARGET, 'lost_band')));
    $known = $report->errors(self::TARGET, 'lost_band')[0]->getContext()['design_systems'];
    sort($known);
    $this->assertSame(['aurora', 'borealis'], $known);
    $this->assertSame('aurora', $this->load('variant', 'joined_band')->getDesignSystem());
    $this->assertSame(self::TARGET, $this->load('variant', 'joined_band')->getProvider());

    // Disk is the author of membership: the joined variant opting out on
    // disk leaves aurora on the next sync (a face change, styles
    // unchanged), and rejoins when the key names it again.
    $this->dropVariantsFile('members', [
      'joined_band' => [
        'label' => 'Joined band',
        'scope' => 'kind:container',
        'design_system' => NULL,
        'styles' => ['' => ['default' => ['padding' => '1rem']]],
      ],
    ]);
    $report = \Drupal::service('daedalus_stylish.variant_importer')->sync([self::TARGET]);
    $this->assertSame(['joined_band' => ShippedDefinitionsImporter::UPDATED], $report->outcomes()[self::TARGET]);
    $this->assertNull($this->load('variant', 'joined_band')->getDesignSystem());
    $this->dropVariantsFile('members', [
      'joined_band' => [
        'label' => 'Joined band',
        'scope' => 'kind:container',
        'design_system' => 'aurora',
        'styles' => ['' => ['default' => ['padding' => '1rem']]],
      ],
    ]);
    $report = \Drupal::service('daedalus_stylish.variant_importer')->sync([self::TARGET]);
    $this->assertSame(['joined_band' => ShippedDefinitionsImporter::UNCHANGED], $report->outcomes()[self::TARGET]);
    $this->assertSame('aurora', $this->load('variant', 'joined_band')->getDesignSystem());
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
   * install-time sync defers it. Landing the system syncs nothing (no
   * entry fires on it any more), a further sync still defers, and a
   * site-built system packages nothing and syncs nothing.
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
    $this->assertNull($this->load('variant', 'aurora_band'));

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
      self::DESIGN_SYSTEM_PACKAGE . ': animation sync: 1 created',
      self::DESIGN_SYSTEM_PACKAGE . ': swatch sync: 1 created',
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
      'swatch:aurora_accent',
      'token:space/gutter',
      'variant:aurora_band',
      'variant:aurora_rhythm',
    ], $upserts);
    $this->assertSame([], $delta['remove']);

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
   * A site install: the batch waits for the installer, the activation lands.
   *
   * `drush si recipes/daedalus_recipe` installs daedalus_stylish, daedalus_stylish_layouts and
   * the olivero package in one batch (hook_modules_installed() with
   * daedalus_stylish in it would sweep every enabled module) on a site that has
   * no design system and no skin yet, and the recipe's activation action
   * gives it olivero afterwards; the installer ends with no cache
   * rebuild. While the installer runs (InstallerKernel::
   * installationAttempted() reads the install_state global) the batch
   * syncs nothing and logs nothing: the suite's gap binding would refuse
   * the Columns variant (`row`, gap var(--space-gutter)) with nothing
   * minted, a refusal the same install resolves is not a failure the
   * install should report, and the entry fires once per ingredient
   * batch, so even a notice would be a wall of them. The activation
   * syncs every
   * enabled module under olivero's law: the package lands, every global
   * lands with nothing failed, `row` among them, the roster offers it
   * with no rebuild, and a further sync is converged.
   */
  public function testSiteInstallWaitsForTheInstaller(): void {
    $this->enableModules(['daedalus_stylish_layouts', 'daedalus_stylish_olivero_skin']);
    $this->attachLogSink();
    $this->installConfig(['daedalus_blueprint']);
    $active = \Drupal::service(ActiveDesignSystem::SERVICE_ID);
    $this->assertNull($active->load());

    // The install batch, inside the installer: silent, nothing lands.
    $GLOBALS['install_state'] = ['installation_finished' => FALSE];
    try {
      $this->sync()->onInstall(['daedalus_stylish', 'daedalus_stylish_layouts', 'daedalus_stylish_olivero_skin']);
    }
    finally {
      unset($GLOBALS['install_state']);
    }
    $this->assertSame([], $this->log, 'the batch waits silently: the entry fires per ingredient batch');
    $waiting = [
      ['variant', 'columns'],
      ['variant', 'row'],
      ['variant', 'container_site'],
      ['variant', 'block_fade_in'],
      ['animation', 'fade_in'],
      ['skin', 'olivero'],
    ];
    foreach ($waiting as [$kind, $name]) {
      $this->assertNull($this->load($kind, $name), "$kind $name waits for the installer");
    }

    // The recipe's config import lands the system, then its action
    // activates it: the sweep lands the package and every global.
    $this->installConfig(['daedalus_stylish_olivero_skin']);
    $this->log = [];
    $active->activate('olivero');
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR), implode("\n", $this->logged(RfcLogLevel::ERROR)));
    $this->assertSame([], $this->logged(RfcLogLevel::NOTICE));
    $info = $this->logged(RfcLogLevel::INFO);
    $this->assertContains('daedalus_stylish_olivero_skin: skin sync: 1 created', $info);
    foreach (['daedalus_stylish: animation sync: ', 'daedalus_stylish: variant sync: ', 'daedalus_stylish_layouts: variant sync: '] as $prefix) {
      $lines = array_values(array_filter($info, static fn (string $line): bool => str_starts_with($line, $prefix)));
      $this->assertCount(1, $lines, $prefix);
      $this->assertStringEndsWith(' created', $lines[0], 'every global landed at the activation, none failed');
    }
    $this->assertNotNull($this->load('variant', 'container_site'));
    $this->assertNotNull($this->load('animation', 'fade_in'));
    $row = $this->load('variant', 'columns');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $row);
    $this->assertTrue($row->isPublished());
    $this->assertNull($row->getDesignSystem(), 'a global, offered under every system');
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder');
    $roster->reset();
    $this->assertTrue($roster->build()->has(MaterialKind::Variant, 'columns'), 'the roster offers Columns with no rebuild');
    $report = $this->sync()->sync();
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
    $this->assertSame(ShippedDefinitionsImporter::UNCHANGED, $report->report('variant')->outcome('daedalus_stylish_layouts', 'columns'));
  }

  /**
   * After the recipe's activation, a later batch syncs inside the installer.
   *
   * The recipe installs its block types after the styling ingredient
   * that activates olivero, and a block type module ships variants
   * scoped to its own type (daedalus_edit_text_block's Button). Those
   * batches fire inside the installer with the site's law already in
   * place, and the installer ends with no cache rebuild, so a batch that
   * kept waiting landed only at the site's first `drush cr` (a site
   * installed by `drush si` alone offered no Button until then, and the
   * recipe's own browser fixture never got it). With a system active the
   * batch syncs as one outside the installer does, logged, and a further
   * sync is converged.
   */
  public function testBatchAfterTheActivationSyncsInsideTheInstaller(): void {
    $this->enableModules(['daedalus_stylish_layouts', 'daedalus_stylish_olivero_skin']);
    $this->installConfig(['daedalus_blueprint', 'daedalus_stylish_olivero_skin']);
    \Drupal::service(ActiveDesignSystem::SERVICE_ID)->activate('olivero');

    // A later ingredient's module, installed after the activation, shipping
    // a variant that conforms under olivero (the suite's gutter token).
    $this->enableModules([self::TARGET]);
    $this->attachLogSink();
    $this->dropVariantsFile('later', [
      'later_band' => [
        'label' => 'Later band',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['gap' => 'var(--space-gutter)']]],
      ],
    ]);
    $GLOBALS['install_state'] = ['installation_finished' => FALSE];
    try {
      $this->sync()->onInstall([self::TARGET]);
    }
    finally {
      unset($GLOBALS['install_state']);
    }
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR), implode("\n", $this->logged(RfcLogLevel::ERROR)));
    $this->assertSame([self::TARGET . ': variant sync: 1 created'], $this->logged(RfcLogLevel::INFO));
    $variant = $this->load('variant', 'later_band');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant, 'the variant landed at install: the site has its law');
    $this->assertTrue($variant->isPublished());
    $report = $this->sync()->sync([self::TARGET]);
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
    $this->assertSame(ShippedDefinitionsImporter::UNCHANGED, $report->report('variant')->outcome(self::TARGET, 'later_band'));
  }

  /**
   * A recipe on an existing site: the activation lands what the batch refused.
   *
   * `drush recipe recipes/daedalus_recipe` on a site that already exists runs
   * the same batch outside the installer, on a site that has no design
   * system and no skin yet, and the recipe's activation action gives it
   * olivero afterwards. The batch syncs: the suite's gap binding refuses
   * the Columns variant (`row`, gap var(--space-gutter): guarded law,
   * nothing minted), the width family lands (container_site spells the
   * gutter inside max(), not as a bare reference) and the olivero package
   * is deferred whole. The activation syncs every enabled module under
   * olivero's law: the package lands, `row` lands as a global, the
   * roster offers it with no rebuild, and a further sync is converged.
   */
  public function testActivationLandsWhatTheSkinlessBatchRefused(): void {
    $this->enableModules(['daedalus_stylish_layouts', 'daedalus_stylish_olivero_skin']);
    $this->attachLogSink();
    $this->installConfig(['daedalus_blueprint']);
    $active = \Drupal::service(ActiveDesignSystem::SERVICE_ID);
    $this->assertNull($active->load());

    // The batch, on the skinless site, outside the installer.
    $this->sync()->onInstall(['daedalus_stylish', 'daedalus_stylish_layouts', 'daedalus_stylish_olivero_skin']);
    // Both arrangements carry the gutter token gap, so both wait.
    $this->assertNull($this->load('variant', 'columns'), 'the skinless batch refused the Columns variant');
    $this->assertNull($this->load('variant', 'row'), 'the skinless batch refused the Row variant');
    $this->assertNotNull($this->load('variant', 'container_site'), 'the width family landed in the batch');
    $this->assertNull($this->load('skin', 'olivero'), 'the olivero package waited for its activation');
    $errors = $this->logged(RfcLogLevel::ERROR);
    $this->assertCount(2, $errors, implode("\n", $errors));
    $this->assertStringStartsWith('daedalus_stylish_layouts: variant columns failed: ', $errors[0]);
    $this->assertStringStartsWith('daedalus_stylish_layouts: variant row failed: ', $errors[1]);
    foreach ($errors as $error) {
      $this->assertStringContainsString('[STYLE_VALUE_NOT_ON_SCALE]', $error);
      $this->assertStringContainsString('gap', $error);
    }
    $layouts = array_values(array_filter(
      $this->logged(RfcLogLevel::INFO),
      static fn (string $line): bool => str_starts_with($line, 'daedalus_stylish_layouts: variant sync: '),
    ));
    $this->assertCount(1, $layouts);
    $this->assertStringEndsWith(', 2 failed', $layouts[0]);

    // The recipe's config import lands the system, then its action
    // activates it: the sweep lands the package and the refused globals.
    $this->installConfig(['daedalus_stylish_olivero_skin']);
    $this->log = [];
    $active->activate('olivero');
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR), implode("\n", $this->logged(RfcLogLevel::ERROR)));
    $info = $this->logged(RfcLogLevel::INFO);
    $this->assertContains('daedalus_stylish_olivero_skin: skin sync: 1 created', $info);
    $this->assertContains('daedalus_stylish_layouts: variant sync: 2 created', $info);
    $this->assertNotNull($this->load('variant', 'row'), 'the Row landed beside Columns');
    $row = $this->load('variant', 'columns');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $row);
    $this->assertTrue($row->isPublished());
    $this->assertNull($row->getDesignSystem(), 'a global, offered under every system');
    $this->assertSame('daedalus_stylish_layouts', $row->getProvider());
    $this->assertSame('var(--space-gutter)', $row->getStyles()['']['default']['gap']);
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder');
    $roster->reset();
    $this->assertTrue($roster->build()->has(MaterialKind::Variant, 'columns'), 'the roster offers Columns with no rebuild');
    $this->assertArrayNotHasKey('columns', \Drupal::service('daedalus_stylish.variant_conformance')->withheld(), 'Columns conforms under olivero (the gutter is minted)');

    // Converged: the rebuild a fresh site used to need finds nothing.
    $report = $this->sync()->sync();
    $this->assertFalse($report->hasFailures(), implode("\n", $report->failureLines()));
    $this->assertSame(ShippedDefinitionsImporter::UNCHANGED, $report->report('variant')->outcome('daedalus_stylish_layouts', 'columns'));
  }

  /**
   * Install syncs the batch; daedalus_stylish in the batch sweeps every provider.
   *
   * The batch entry syncs only the installed modules (daedalus_stylish's own
   * shipped animations stay absent), and daedalus_stylish's own install syncs
   * every enabled module, so providers enabled before daedalus_stylish existed
   * arrive the moment the system that hosts them does. Both log.
   */
  public function testInstallSyncsBatchAndDaedalusStylishSweepsAll(): void {
    $this->enableProviders();
    $this->sync()->onInstall([self::PACKAGE]);
    foreach (self::PACKAGE_NAMES as $kind => $name) {
      $this->assertNotNull($this->load($kind, $name), $kind);
    }
    $this->assertNull($this->load('animation', 'fade_in'));
    $this->assertSame([
      self::PACKAGE . ': animation sync: 1 created',
      self::PACKAGE . ': swatch sync: 1 created',
      self::PACKAGE . ': skin sync: 1 created',
      self::PACKAGE . ': variant sync: 1 created',
    ], $this->logged(RfcLogLevel::INFO));
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR));

    $this->log = [];
    $this->sync()->onInstall(['daedalus_stylish']);
    $this->assertNotNull($this->load('animation', 'fade_in'));
    $this->assertNotNull($this->load('variant', 'block_fade_in'));
    // The package converged already: unchanged is silent.
    $this->assertStringStartsWith('daedalus_stylish: animation sync: ', $this->logged(RfcLogLevel::INFO)[0]);
    $this->assertNotContains(self::PACKAGE . ': variant sync: 1 created', $this->logged(RfcLogLevel::INFO));
  }

  /**
   * A rebuild activates a variants file dropped beside an installed module.
   *
   * The pulled-package state: the module was installed long ago, its
   * per-id variants file arrived with a pull, and nobody ran a command.
   * hook_rebuild(), invoked through the module handler as
   * drupal_flush_all_caches() does, lands it; pending entity definition
   * updates skip the sync with one error line instead; a definition the
   * lane refuses is logged at error while the rest of the file lands; and
   * a converged rebuild logs nothing.
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
    // definition update manager: the guard's one error line, no sync.
    $update_manager = \Drupal::entityDefinitionUpdateManager();
    $this->assertTrue($update_manager->needsUpdates());
    \Drupal::moduleHandler()->invoke('daedalus_stylish', 'rebuild');
    $this->assertNull($this->load('variant', 'card_section'));
    $this->assertSame([], $this->logged(RfcLogLevel::INFO));
    $this->assertSame(['Shipped daedalus_stylish definitions were not synced: entity definition updates are pending. Run `drush updb`; the cache rebuild at its end syncs them.'], $this->logged(RfcLogLevel::ERROR));

    $repository = \Drupal::service('entity.last_installed_schema.repository');
    foreach (array_keys(\Drupal::entityTypeManager()->getDefinitions()) as $entity_type_id) {
      if ($repository->getLastInstalledDefinition($entity_type_id) === NULL) {
        $this->installEntitySchema($entity_type_id);
      }
    }
    $this->assertFalse($update_manager->needsUpdates());
    $this->log = [];

    \Drupal::moduleHandler()->invoke('daedalus_stylish', 'rebuild');
    $variant = $this->load('variant', 'card_section');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $variant);
    $this->assertTrue($variant->isShipped());
    $this->assertSame(self::TARGET, $variant->getProvider());
    $this->assertTrue($variant->isPublished());
    // Every enabled module synced: the package and daedalus_stylish's own files too.
    $this->assertNotNull($this->load('variant', 'sync_test_band'));
    $this->assertNotNull($this->load('animation', 'fade_in'));
    $this->assertContains(self::TARGET . ': variant sync: 1 created', $this->logged(RfcLogLevel::INFO));
    $this->assertSame([], $this->logged(RfcLogLevel::ERROR));

    // Converged: a rebuild is silent.
    $this->log = [];
    \Drupal::moduleHandler()->invoke('daedalus_stylish', 'rebuild');
    $this->assertSame([], $this->log);

    // A refused definition is one error line; its file's sound neighbor
    // still lands, and the earlier variant is untouched.
    $this->dropVariantsFile('broken', [
      'broken_section' => ['label' => 'Broken section', 'scope' => 'kind:container'],
      'sound_section' => [
        'label' => 'Sound section',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => '1rem']]],
      ],
    ]);
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
   * lands; a converged module reads unchanged and exits clean.
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
    $this->assertStringContainsString('system is not enabled or ships no daedalus_stylish definitions: no system.daedalus_stylish.animations.yml, system.daedalus_stylish.swatches.yml, system.daedalus_stylish.skins.yml, system.daedalus_stylish.variants.yml or a per-id file beside one (e.g. system.daedalus_stylish.variants.*.yml).', $tester->getDisplay());

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
    $this->assertStringContainsString(self::PACKAGE . ': variant sync_test_band unchanged', $tester->getDisplay());

    // A design-system package whose system is not active is one deferred
    // line naming the system, not a file-shipping miss and not a failure.
    $this->enableModules([self::DESIGN_SYSTEM_PACKAGE]);
    $this->installConfig(['daedalus_blueprint']);
    $tester = new CommandTester(SyncCommand::create($this->container));
    $this->assertSame(Command::SUCCESS, $tester->execute(['module' => self::DESIGN_SYSTEM_PACKAGE]));
    $this->assertSame(self::DESIGN_SYSTEM_PACKAGE . ': deferred; it packages the "aurora" design system, which is not active. Its definitions land when the system is activated.', trim($tester->getDisplay()));
    $this->assertNull($this->load('variant', 'aurora_band'));
  }

}
