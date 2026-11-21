<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use ColinODell\PsrTestLogger\TestLogger;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\Drush\Commands\ConvergeKeysCommand;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests the key grammar convergence: a key the grammar rejects is dropped.
 *
 * A stored style key outside its store's key grammar prints nothing and
 * the stored styles validation refuses it, so its host cannot be saved.
 * The convergence deletes exactly those keys and logs each one; nothing
 * is rewritten and every key inside the grammar stays as it is. Covered
 * stores: host style maps (the KeyGrammarSweep, riding daedalus_layout's
 * transform spine last), daedalus_variant styles and daedalus_skin
 * defaults across revisions, and a staged copy shedding such a key when
 * it loads. Shipped-unmodified entities are left to the sync lane; a
 * second pass changes nothing. The key_grammar post_update sequence and
 * the drush command run the same passes and close with the report of
 * what the stored styles validation still refuses on a host, which
 * writes nothing.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class KeyGrammarConvergenceTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    'daedalus_stylish_layouts',
    'file',
    'image',
    'media',
  ];

  /**
   * Collects what the convergence logs.
   */
  protected TestLogger $log;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->log = new TestLogger();
    $this->container->get('logger.factory')->addLogger($this->log);
  }

  /**
   * The messages logged to the daedalus_stylish channel since the last read.
   *
   * @return string[]
   *   The messages, in order.
   */
  protected function logged(): array {
    $messages = [];
    foreach ($this->log->records as $record) {
      if (($record['context']['channel'] ?? NULL) === 'daedalus_stylish') {
        $messages[] = (string) $record['message'];
      }
    }
    $this->log->reset();
    return $messages;
  }

  /**
   * The five key shapes an installed site was found to store.
   *
   * Spelled with the element type prefix the grammar no longer knows:
   * three rules under one container and two under another.
   *
   * @return string[]
   *   The keys.
   */
  protected function retiredKeys(string $uuid, string $other): array {
    return [
      "container:$uuid::> pb-container",
      "container:$uuid::> pb-container > pb-text",
      "container:$uuid::> pb-container > pb-text + pb-text",
      "container:$other::> pb-image",
      "container:$other::> pb-image .image-block-image",
    ];
  }

  /**
   * Persists an entity carrying pre-convergence data.
   *
   * A syncing save bypasses the presave safety net, which is how such
   * data sits on a site the update hooks have not run on yet.
   */
  protected function createLegacyEntity(array $sections, array $map): EntityTest {
    $entity = EntityTest::create([
      OverridesSectionStorage::FIELD_NAME => $sections,
      DaedalusStylishFieldManager::FIELD_NAME => json_encode($map, JSON_UNESCAPED_SLASHES),
    ]);
    $entity->setSyncing(TRUE);
    $entity->save();
    return $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
  }

  /**
   * Runs the host entity walker to completion.
   */
  protected function walkHosts(): void {
    $walker = $this->container->get('daedalus_layout.host_entity_walker');
    $sandbox = [];
    do {
      $walker->walk($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
    $this->entityTypeManager->getStorage('entity_test')->resetCache();
  }

  /**
   * Reads an entity's style map.
   */
  protected function styleMap(EntityTest $entity): array {
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    if ($item === NULL) {
      return [];
    }
    assert($item instanceof StyleMapItem);
    return $item->getStyleMap();
  }

  /**
   * Validates an entity's style map field.
   */
  protected function styleMapViolations(EntityTest $entity): ConstraintViolationListInterface {
    return $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->validate();
  }

  /**
   * Host maps: the five shapes leave and are logged, valid keys stay.
   */
  public function testHostMapSheds(): void {
    $section = $this->createSection('daedalus_stylish_container');
    $other_section = $this->createSection('daedalus_stylish_container');
    $uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $other = $other_section->getThirdPartySetting('daedalus_layout', 'uuid');
    $retired = $this->retiredKeys($uuid, $other);
    $valid = [
      "container:$uuid" => ['default' => ['padding' => '1rem']],
      "container:$uuid::> d-text" => ['default' => ['color' => 'gray'], 'hover' => ['color' => 'black']],
      "container:$uuid::> d-container > d-text + d-text" => ['default' => ['margin-top' => '1rem']],
      "container:$other::> d-image .image-block-image" => ['default' => ['border-radius' => '4px']],
      "container:$other::.section" => ['default' => ['gap' => '2rem']],
    ];
    // Interleaved, so the survivors keep their order around the gaps.
    $stored = [];
    foreach (array_keys($valid) as $index => $key) {
      $stored[$retired[$index]] = ['default' => ['margin' => $index . 'px']];
      $stored[$key] = $valid[$key];
    }
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    foreach ($retired as $key) {
      $this->assertNull($validator->parseKey($key), "$key is outside the grammar.");
    }
    foreach (array_keys($valid) as $key) {
      $this->assertNotNull($validator->parseKey($key), "$key is inside the grammar.");
    }
    $entity = $this->createLegacyEntity([$section, $other_section], $stored);
    $this->assertSame($stored, $this->styleMap($entity));
    $this->logged();

    $this->walkHosts();
    $this->assertSame($valid, $this->styleMap($this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id())));
    $dropped = array_values(array_filter($this->logged(), static fn (string $line): bool => str_contains($line, 'dropped the "')));
    $this->assertCount(5, $dropped, implode("\n", $dropped));
    foreach ($retired as $index => $key) {
      $this->assertStringContainsString("entity_test:{$entity->id()}: dropped the \"$key\" entry", $dropped[$index]);
      $this->assertStringContainsString('{"default":{"margin":"' . $index . 'px"}}', $dropped[$index], 'The line carries what the entry held.');
    }

    // A converged map is a no-op: nothing written, nothing said.
    $this->walkHosts();
    $this->assertSame($valid, $this->styleMap($this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id())));
    $this->assertSame([], $this->logged());
    $lines = [];
    $this->assertNull($this->container->get('daedalus_stylish.key_grammar_convergence')->convergeStyleMap($valid, 'test', $lines));
    $this->assertSame([], $lines);
  }

  /**
   * The host rule is the map key grammar: the root form counts too.
   *
   * A root that is no element key is outside the grammar whatever its
   * selector, and a map holding nothing else converges to the empty map.
   * An entry that is no style entry sits on a key that parses, so it is
   * another refusal and is left alone.
   */
  public function testHostRuleIsTheMapKeyGrammar(): void {
    $convergence = $this->container->get('daedalus_stylish.key_grammar_convergence');
    $uuid = $this->uuid->generate();
    $lines = [];
    $this->assertSame([
      "block:$uuid" => 'not an entry',
      "container:$uuid" => ['default' => ['pading' => '1rem']],
    ], $convergence->convergeStyleMap([
      'nonsense' => ['default' => ['padding' => '1rem']],
      "block:$uuid" => 'not an entry',
      "section:$uuid" => ['default' => ['padding' => '1rem']],
      "container:$uuid" => ['default' => ['pading' => '1rem']],
      "container:$uuid::>" => ['default' => ['margin' => '0']],
      "container:$uuid::not a selector!" => ['default' => ['margin' => '0']],
    ], 'test', $lines));
    $this->assertCount(4, $lines, implode("\n", $lines));
    $this->assertStringContainsString('test: dropped the "nonsense" entry; the key is outside the style map key grammar', $lines[0]);
    $this->assertSame($lines, $this->logged(), 'Every line is logged as it is collected.');

    $lines = [];
    $this->assertSame([], $convergence->convergeStyleMap(['nonsense' => []], 'test', $lines));
    $this->assertCount(1, $lines);
  }

  /**
   * Variants: a suffix that is no canonical selector goes, in ALL revisions.
   *
   * The variant rule is the key's own: the "::" suffix. A slot path the
   * scope does not contain is refused by the scope, not by the key
   * alone, and stays. Shipped-unmodified variants are left to the sync
   * lane.
   */
  public function testVariantsShed(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'My section',
      'machine_name' => 'my_section',
      'scope' => 'kind:container',
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $variant->setStyles([
      '' => ['default' => ['background-color' => 'aliceblue'], 'variants' => [['variant' => 'pull', 'fragment' => '']]],
      '::> pb-container' => ['default' => ['margin' => '0']],
      '::> d-text' => ['default' => ['color' => 'gray']],
      'container(1)' => ['default' => ['padding' => '1rem']],
      'container(1)::> pb-container > pb-text' => ['default' => ['color' => 'red']],
      'container(1)::.section' => ['default' => ['gap' => '1rem']],
      'block(text,1)::> pb-image .image-block-image' => ['variants' => [['variant' => 'pull', 'fragment' => '']]],
      // A path a container does not contain: the scope's refusal.
      'field(body)' => ['default' => ['color' => 'navy']],
    ]);
    $variant->save();
    $first_revision_id = $variant->getRevisionId();
    $variant->setStyles([
      '::.section' => ['default' => ['padding-top' => '6rem']],
      '::> pb-text + pb-text' => ['default' => ['margin-top' => '1rem']],
    ]);
    $variant->setNewRevision(TRUE);
    $variant->save();

    $shipped_styles = ['::> pb-text' => ['default' => ['max-width' => '80rem']]];
    $shipped = DaedalusStylishVariant::create([
      'label' => 'Columns',
      'machine_name' => 'columns',
      'scope' => 'kind:container',
      'axis' => 'container_layout',
      'status' => TRUE,
    ]);
    $shipped->setStyles($shipped_styles);
    $shipped->setShippedSource('daedalus_stylish_layouts', $shipped_styles);
    $shipped->save();
    $this->logged();

    $convergence = $this->container->get('daedalus_stylish.key_grammar_convergence');
    $lines = $convergence->convergeVariants();
    $this->assertCount(4, $lines, implode("\n", $lines));
    $this->assertSame($lines, $this->logged());
    $this->assertStringContainsString('Variant "my_section" (revision ' . $first_revision_id . '): dropped the "::> pb-container" entry; the key is outside the variant style key grammar', $lines[0]);
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();

    $current = $storage->loadUnchanged($variant->id());
    assert($current instanceof DaedalusStylishVariant);
    $this->assertSame(['::.section' => ['default' => ['padding-top' => '6rem']]], $current->getStyles());
    assert($storage instanceof RevisionableStorageInterface);
    $first = $storage->loadRevision($first_revision_id);
    assert($first instanceof DaedalusStylishVariant);
    $this->assertSame([
      '' => ['default' => ['background-color' => 'aliceblue'], 'variants' => [['variant' => 'pull', 'fragment' => '']]],
      '::> d-text' => ['default' => ['color' => 'gray']],
      'container(1)' => ['default' => ['padding' => '1rem']],
      'container(1)::.section' => ['default' => ['gap' => '1rem']],
      'field(body)' => ['default' => ['color' => 'navy']],
    ], $first->getStyles());

    $shipped = $storage->loadUnchanged($shipped->id());
    assert($shipped instanceof DaedalusStylishVariant);
    $this->assertSame($shipped_styles, $shipped->getStyles(), 'A shipped unmodified variant is left to the sync lane.');
    $this->assertFalse($shipped->isModified());

    // Idempotence.
    $this->assertSame([], $convergence->convergeVariants());
    $this->assertSame([], $this->logged());
  }

  /**
   * Skin defaults: a default's key is '' or a selector under its scope.
   *
   * A suffix that is no canonical selector goes, and so does a path: a
   * default has no root but its scope. Both layers converge;
   * a shipped-unmodified package layer is left to the sync lane.
   */
  public function testSkinsShed(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $skin->setPackageDefaults([
      'kind:container' => [
        '' => ['default' => ['background-color' => 'white']],
        '::> pb-container' => ['default' => ['margin' => '0']],
        '::> *' => ['default' => ['min-width' => '0']],
        'container(1)' => ['default' => ['padding' => '1rem']],
        'container(1)::.section' => ['default' => ['gap' => '1rem']],
      ],
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
    ]);
    $skin->setSiteDefaults([
      'kind:container' => [
        '::> pb-image .image-block-image' => ['default' => ['border-radius' => '4px']],
        '::.section' => ['default' => ['gap' => '2rem']],
      ],
    ]);
    $skin->save();

    $shipped_data = [
      'tokens' => [],
      'animations' => [],
      'fonts' => [],
      'defaults' => [
        'kind:container' => ['::> pb-text' => ['default' => ['max-width' => '90rem']]],
      ],
    ];
    $shipped = DaedalusStylishSkin::create(['label' => 'Shipped', 'machine_name' => 'shipped_skin', 'status' => TRUE]);
    $shipped->setPackageDefaults($shipped_data['defaults']);
    $shipped->setShippedSource('daedalus_stylish_test', $shipped_data);
    $shipped->setSiteDefaults([
      'base:h2' => ['' => ['default' => ['color' => 'navy']], '::> pb-text' => ['default' => ['color' => 'red']]],
    ]);
    $shipped->save();
    $this->logged();

    $convergence = $this->container->get('daedalus_stylish.key_grammar_convergence');
    $lines = $convergence->convergeSkins();
    $this->assertCount(5, $lines, implode("\n", $lines));
    $this->assertSame($lines, $this->logged());
    $all = implode("\n", $lines);
    $this->assertStringContainsString('site defaults kind:container: dropped the "::> pb-image .image-block-image" entry; the key is outside the skin default key grammar', $all);
    $this->assertStringContainsString('package defaults kind:container: dropped the "container(1)" entry', $all);
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $storage->resetCache();

    /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $aurora */
    $aurora = $storage->loadUnchanged($skin->id());
    $this->assertSame([
      'kind:container' => [
        '' => ['default' => ['background-color' => 'white']],
        '::> *' => ['default' => ['min-width' => '0']],
      ],
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
    ], $aurora->packageDefaults());
    $this->assertSame([
      'kind:container' => ['::.section' => ['default' => ['gap' => '2rem']]],
    ], $aurora->siteDefaults());

    /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $shipped */
    $shipped = $storage->loadUnchanged($shipped->id());
    $this->assertSame($shipped_data['defaults'], $shipped->packageDefaults(), 'A shipped unmodified package layer is left to the sync lane.');
    $this->assertFalse($shipped->isModified());
    $this->assertSame([
      'base:h2' => ['' => ['default' => ['color' => 'navy']]],
    ], $shipped->siteDefaults(), 'The site layer over a shipped skin is the site\'s and converges.');

    // Idempotence.
    $this->assertSame([], $convergence->convergeSkins());
    $this->assertSame([], $this->logged());
  }

  /**
   * A staged copy carrying such a key loads without it.
   */
  public function testStagedCopyShedsAtLoad(): void {
    $section = $this->createSection('daedalus_stylish_container');
    $uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $entity = $this->createTestEntity([$this->createSection('daedalus_stylish_container')]);

    $helper = $this->container->get('daedalus_tempstore.section_storage_helper');
    $storage = $helper->getEditableSectionStorage($entity);
    if ($storage instanceof NestedSectionStorageInterface) {
      $storage = $storage->getRootStorage();
    }
    $staged_entity = $storage->getContextValue('entity');
    $staged_entity->set(OverridesSectionStorage::FIELD_NAME, [$section]);
    $staged_entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "container:$uuid::> pb-container > pb-text" => ['default' => ['color' => 'red']],
      "container:$uuid::> d-text" => ['default' => ['color' => 'gray']],
    ], JSON_UNESCAPED_SLASHES));
    $strategy = $this->container->get('daedalus_tempstore.strategy.layout');
    $key = $strategy->getKey($storage);
    $this->container->get('tempstore.shared')
      ->get('layout_builder.section_storage.overrides')
      ->set($key, ['section_storage' => $storage]);
    $this->logged();

    $fresh = $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
    $staged = $this->container->get('daedalus_tempstore.entity_tempstore_repository')->get($fresh);
    $this->assertNotSame($fresh, $staged, 'The staged session is served.');

    assert($staged instanceof EntityTest);
    $this->assertSame(["container:$uuid::> d-text" => ['default' => ['color' => 'gray']]], $this->styleMap($staged));
    $dropped = array_values(array_filter($this->logged(), static fn (string $line): bool => str_contains($line, 'dropped the "')));
    $this->assertCount(1, $dropped, implode("\n", $dropped));
    $this->assertStringContainsString("dropped the \"container:$uuid::> pb-container > pb-text\" entry", $dropped[0]);
  }

  /**
   * Stores the data an installed site carries into the update.
   *
   * One host with the five retired keys beside a valid one, one host
   * whose map holds a refusal on a key that parses (so it may print and
   * is nobody's to drop), one host with nothing wrong, a variant and a
   * skin each holding one retired key.
   *
   * @return array
   *   The three hosts keyed "retired", "refused" and "clean", the valid
   *   map of the first under "valid" and the stored map of the second
   *   under "refused_map".
   */
  protected function createInstalledSiteData(): array {
    $section = $this->createSection('daedalus_stylish_container');
    $other_section = $this->createSection('daedalus_stylish_container');
    $uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $other = $other_section->getThirdPartySetting('daedalus_layout', 'uuid');
    $valid = ["container:$uuid::> d-text" => ['default' => ['color' => 'gray']]];
    $stored = $valid;
    foreach ($this->retiredKeys($uuid, $other) as $key) {
      $stored[$key] = ['default' => ['margin' => '0']];
    }
    $retired = $this->createLegacyEntity([$section, $other_section], $stored);

    $refused_section = $this->createSection('daedalus_stylish_container');
    $refused_uuid = $refused_section->getThirdPartySetting('daedalus_layout', 'uuid');
    $refused_map = [
      "container:$refused_uuid" => ['default' => ['padding' => '1rem']],
      "container:$refused_uuid::> d-text" => 'not an entry',
    ];
    $refused = $this->createLegacyEntity([$refused_section], $refused_map);

    $clean_section = $this->createSection('daedalus_stylish_container');
    $clean = $this->createLegacyEntity([$clean_section], [
      'container:' . $clean_section->getThirdPartySetting('daedalus_layout', 'uuid') => ['default' => ['padding' => '1rem']],
    ]);

    $variant = DaedalusStylishVariant::create([
      'label' => 'My section',
      'machine_name' => 'my_section',
      'scope' => 'kind:container',
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $variant->setStyles([
      '::> pb-container' => ['default' => ['margin' => '0']],
      '::> d-text' => ['default' => ['color' => 'gray']],
    ]);
    $variant->save();

    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $skin->setSiteDefaults([
      'kind:container' => [
        '::> pb-image .image-block-image' => ['default' => ['border-radius' => '4px']],
        '::.section' => ['default' => ['gap' => '2rem']],
      ],
    ]);
    $skin->save();
    $this->logged();

    return [
      'retired' => $retired,
      'refused' => $refused,
      'clean' => $clean,
      'valid' => $valid,
      'refused_map' => $refused_map,
    ];
  }

  /**
   * Asserts the three stores hold what the convergence leaves.
   */
  protected function assertInstalledSiteDataConverged(array $data): void {
    $hosts = $this->entityTypeManager->getStorage('entity_test');
    $this->assertSame($data['valid'], $this->styleMap($hosts->loadUnchanged($data['retired']->id())));
    $this->assertSame($data['refused_map'], $this->styleMap($hosts->loadUnchanged($data['refused']->id())), 'A refusal on a key that parses is left as it is.');

    $variants = $this->entityTypeManager->getStorage('daedalus_variant');
    $variants->resetCache();
    $variant = current($variants->loadByProperties(['machine_name' => 'my_section']));
    assert($variant instanceof DaedalusStylishVariant);
    $this->assertSame(['::> d-text' => ['default' => ['color' => 'gray']]], $variant->getStyles());

    $skins = $this->entityTypeManager->getStorage('daedalus_skin');
    $skins->resetCache();
    /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin */
    $skin = current($skins->loadByProperties(['machine_name' => 'aurora']));
    $this->assertSame(['kind:container' => ['::.section' => ['default' => ['gap' => '2rem']]]], $skin->siteDefaults());
  }

  /**
   * The post_update sequence: three passes that drop, one that reports.
   *
   * The hosts pass is the walker, so it drops and logs the five keys;
   * the variant and skin passes carry their count in the message. The
   * report pass names each refusal left with its host and path, counts
   * them in its message, and writes nothing. A second run of the whole
   * sequence drops nothing and reports the same refusal.
   */
  public function testPostUpdates(): void {
    $data = $this->createInstalledSiteData();
    require_once $this->container->get('extension.list.module')->getPath('daedalus_stylish') . '/daedalus_stylish.post_update.php';

    $sandbox = [];
    do {
      $message = (string) daedalus_stylish_post_update_key_grammar_0001_hosts($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
    $this->assertStringContainsString('Dropped the keys outside the style map key grammar from host style maps', $message);
    $this->entityTypeManager->getStorage('entity_test')->resetCache();
    $dropped = array_values(array_filter($this->logged(), static fn (string $line): bool => str_contains($line, 'dropped the "')));
    $this->assertCount(5, $dropped, implode("\n", $dropped));
    $this->assertStringStartsWith("entity_test:{$data['retired']->id()}: dropped the \"", $dropped[0]);

    $this->assertStringContainsString('Dropped 1 keys outside the variant style key grammar', (string) daedalus_stylish_post_update_key_grammar_0002_variants());
    $this->assertCount(1, $this->logged());
    $this->assertStringContainsString('Dropped 1 keys outside the skin default key grammar', (string) daedalus_stylish_post_update_key_grammar_0003_skins());
    $this->assertCount(1, $this->logged());
    $this->assertInstalledSiteDataConverged($data);

    // The report: one refusal, on the host whose entry is no entry.
    $hosts = $this->entityTypeManager->getStorage('entity_test');
    $before = [];
    foreach (['retired', 'refused', 'clean'] as $name) {
      $before[$name] = $hosts->loadUnchanged($data[$name]->id())->toArray();
    }
    $sandbox = [];
    do {
      $message = daedalus_stylish_post_update_key_grammar_0004_report($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
    $this->assertSame('The stored styles validation refuses 1 style map entries on 1 of 3 hosts. Those hosts cannot be saved until the entries are repaired; each refusal is logged to the daedalus_stylish channel with its host and path. Nothing was changed.', (string) $message);
    $refused_key = array_key_last($data['refused_map']);
    $logged = $this->logged();
    $this->assertCount(1, $logged, implode("\n", $logged));
    $this->assertStringContainsString('The stored styles validation refuses entity_test:' . $data['refused']->id() . ' (', $logged[0]);
    $this->assertStringContainsString(DaedalusStylishFieldManager::FIELD_NAME . ': ' . $refused_key . ': ', $logged[0], 'The line carries the host and the path inside its map.');
    foreach (['retired', 'refused', 'clean'] as $name) {
      // Equal, not identical: each load builds its own section objects.
      $this->assertEquals($before[$name], $hosts->loadUnchanged($data[$name]->id())->toArray(), "The report writes nothing on the $name host.");
    }

    // The report is the stored styles constraint's own judgement: the
    // refusal is the one validating the host meets, and it is the only
    // one of that constraint's on any host.
    $violations = $this->styleMapViolations($hosts->loadUnchanged($data['refused']->id()));
    $this->assertCount(1, $violations);
    $this->assertStringContainsString(PlainTextOutput::renderFromHtml($violations->get(0)->getMessage()), $logged[0]);
    $this->assertCount(0, $this->styleMapViolations($hosts->loadUnchanged($data['retired']->id())), 'The host that shed its keys validates.');

    // A second run drops nothing and reports the same refusal.
    $sandbox = [];
    do {
      daedalus_stylish_post_update_key_grammar_0001_hosts($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
    $this->assertStringContainsString('Dropped 0 keys', (string) daedalus_stylish_post_update_key_grammar_0002_variants());
    $this->assertStringContainsString('Dropped 0 keys', (string) daedalus_stylish_post_update_key_grammar_0003_skins());
    $this->assertSame([], $this->logged());
    $sandbox = [];
    do {
      $message = daedalus_stylish_post_update_key_grammar_0004_report($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
    $this->assertStringContainsString('refuses 1 style map entries on 1 of 3 hosts', (string) $message);
    $this->assertCount(1, $this->logged());
  }

  /**
   * The report reads in batches and says so when nothing is refused.
   *
   * Before the hosts pass the five retired keys are refusals too (that
   * is why their host could not be saved); a batch of one host at a
   * time reaches every host once and keeps the totals across calls.
   */
  public function testReportBatchesAndAcceptance(): void {
    $data = $this->createInstalledSiteData();
    $report = $this->container->get('daedalus_stylish.stored_styles_report');

    $sandbox = [];
    $lines = [];
    $calls = 0;
    do {
      array_push($lines, ...$report->report($sandbox, 1));
      $calls++;
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
    $this->assertGreaterThanOrEqual(3, $calls);
    $this->assertSame(['hosts' => 3, 'refused_hosts' => 2, 'refusals' => 6], $report->totals($sandbox), implode("\n", $lines));
    $this->assertCount(6, $lines);
    $this->assertCount(6, $this->logged());
    $retired_lines = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, "entity_test:{$data['retired']->id()} (")));
    $this->assertCount(5, $retired_lines);
    $this->assertStringContainsString('::> pb-container', $retired_lines[0]);

    // With the refused host gone and the keys dropped, every map passes.
    $data['refused']->delete();
    $this->walkHosts();
    $this->logged();
    require_once $this->container->get('extension.list.module')->getPath('daedalus_stylish') . '/daedalus_stylish.post_update.php';
    $sandbox = [];
    $this->assertSame('The stored styles validation accepts the style map of every host (2 read); nothing was changed.', (string) daedalus_stylish_post_update_key_grammar_0004_report($sandbox));
    $this->assertSame(1, $sandbox['#finished']);
    $this->assertSame([], $this->logged());
  }

  /**
   * The drush command runs the same sequence and prints the report.
   */
  public function testConvergeKeysCommand(): void {
    // The command opens with the shipped definitions sync, which lands
    // the shipped variants and records what they use.
    $this->installSchema('daedalus_stylish', ['daedalus_skin_material_usage']);
    $data = $this->createInstalledSiteData();
    $tester = new CommandTester(ConvergeKeysCommand::create($this->container));
    $this->assertSame(Command::SUCCESS, $tester->execute([]));
    $this->entityTypeManager->getStorage('entity_test')->resetCache();
    $display = $tester->getDisplay();
    $this->assertStringContainsString('1 host revision(s) rewritten.', $display);
    $this->assertStringContainsString('Variant "my_section"', $display);
    $this->assertStringContainsString('dropped the "::> pb-image .image-block-image" entry', $display);
    $this->assertStringContainsString('Key grammar convergence complete.', $display);
    $this->assertStringContainsString('  entity_test:' . $data['refused']->id() . ' (', $display);
    $this->assertStringContainsString('The stored styles validation refuses 1 style map entries on 1 of 3 hosts.', $display);
    $this->assertInstalledSiteDataConverged($data);
  }

}
