<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\VariantValidationException;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the variant gestures behind the inspector's variant area.
 *
 * Save-as-variant captures scope server-side and validates against the
 * style guide (daedalus_stylish_guide_test: section declares spacing with padding
 * max 4rem); push-update creates a NEW REVISION (the reversibility net)
 * and reports the loud use count; usage counting reads the
 * daedalus_variant_usage table, which the host entity save chokepoint
 * maintains for both reference forms (machine name and uuid).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantManagerTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
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
    // The unbound site: the class writes literal paddings, gaps and sizes
    // on a site that mints nothing, and the suite's bindings would refuse
    // every one for a reason that is the scale's, not the claim's.
    'daedalus_stylish_unbound_test',
  ];

  /**
   * The variant manager.
   *
   * @var \Drupal\daedalus_stylish\VariantManager
   */
  protected $manager;

  /**
   * A valid uuid to build keys with.
   */
  protected string $uuid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('daedalus_variant');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser();
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->manager = $this->container->get('daedalus_stylish.variant_manager');
    $this->uuid = $this->container->get('uuid')->generate();
  }

  /**
   * Collects error codes keyed by path from a validation exception.
   */
  protected function catchErrors(callable $gesture): array {
    try {
      $gesture();
    }
    catch (VariantValidationException $exception) {
      $codes = [];
      foreach ($exception->getErrors() as $error) {
        $codes[$error->getPath()][] = $error->getCode();
      }
      return $codes;
    }
    $this->fail('Expected a VariantValidationException.');
  }

  /**
   * Save-as-variant: server-side scope capture, guide validation, creation.
   */
  public function testSaveAsVariant(): void {
    $variant = $this->manager->saveAsVariant('Wide section', "container:{$this->uuid}", [
      '' => ['default' => ['padding' => '2rem'], 'hover' => ['margin' => '1rem']],
      '::.inner' => ['default' => ['margin' => '0']],
    ]);

    $this->assertSame('wide_section', $variant->getMachineName());
    $this->assertSame('kind:container', $variant->getScope());
    $this->assertSame([
      '' => ['default' => ['padding' => '2rem'], 'hover' => ['margin' => '1rem']],
      '::.inner' => ['default' => ['margin' => '0']],
    ], $variant->getStyles());
    $this->assertTrue($variant->isPublished());
    // No axis picked at save lands on the built-in "style" axis.
    $this->assertSame('style', $variant->getAxis());

    // The axis picked at save is validated against the captured scope's
    // vocabulary and stored; an axis outside it is a corrective rejection
    // naming the valid set (kind:container declares no extra axes).
    $styled = $this->manager->saveAsVariant('Styled', "container:{$this->uuid}", ['' => ['default' => ['padding' => '1rem']]], NULL, 'style');
    $this->assertSame('style', $styled->getAxis());
    try {
      $this->manager->saveAsVariant('Off axis', "container:{$this->uuid}", ['' => ['default' => ['padding' => '1rem']]], NULL, 'proportions');
      $this->fail('Expected a VariantValidationException.');
    }
    catch (VariantValidationException $exception) {
      $error = $exception->getErrors()[0];
      $this->assertSame('axis', $error->getPath());
      $this->assertSame('STYLE_VARIANT_AXIS_INVALID', $error->getCode());
      $this->assertSame(['style'], $error->getContext()['valid_axes']);
    }

    // Style guide violations reject the save, re-rooted onto the styles
    // parameter (the section declaration caps padding at 4rem).
    $codes = $this->catchErrors(fn () => $this->manager->saveAsVariant('Too wide', "container:{$this->uuid}", [
      '' => ['default' => ['padding' => '6rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['styles.default.padding']);

    // Entry-shaped fragments: an entry may carry a "variants" reference
    // list to an EXISTING variant (a captured child wearing); it saves as
    // a ref. The saved variant's OWN axis is reserved at its root entry —
    // apply places the variant's own reference there on every wearer — so
    // a root reference on that axis rejects at save, while a slot entry's
    // same-axis reference (the flagship composition case: it lands on the
    // resolved child beside the fragment reference) rides through.
    $ref = $this->manager->saveAsVariant('Ref', "container:{$this->uuid}", [
      '' => ['default' => ['padding' => '1rem']],
      'container(1)' => ['variants' => [['variant' => 'wide_section', 'fragment' => '']]],
    ]);
    $this->assertSame([
      '' => ['default' => ['padding' => '1rem']],
      'container(1)' => ['variants' => [['variant' => 'wide_section', 'fragment' => '']]],
    ], $ref->getStyles());
    $codes = $this->catchErrors(fn () => $this->manager->saveAsVariant('Self axis', "container:{$this->uuid}", [
      '' => ['variants' => [['variant' => 'wide_section', 'fragment' => '']], 'default' => ['padding' => '1rem']],
    ]));
    $this->assertSame(['STYLE_VARIANT_AXIS_CONFLICT'], $codes['styles.variants.0']);

    // Malformed gestures: no label, no styles, a sub-element source key, a
    // malformed reference item (the bare-string shorthand is retired), a
    // bad relative key.
    $codes = $this->catchErrors(fn () => $this->manager->saveAsVariant('  ', "container:{$this->uuid}", ['' => ['default' => ['padding' => '1rem']]]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['label']);
    $codes = $this->catchErrors(fn () => $this->manager->saveAsVariant('Empty', "container:{$this->uuid}", []));
    $this->assertSame(['MISSING_PARAMETER'], $codes['styles']);
    $codes = $this->catchErrors(fn () => $this->manager->saveAsVariant('Sub', "container:{$this->uuid}::.inner", ['' => ['default' => ['padding' => '1rem']]]));
    $this->assertSame(['INVALID_STYLE_KEY'], $codes['key']);
    $codes = $this->catchErrors(fn () => $this->manager->saveAsVariant('Bad ref', "container:{$this->uuid}", ['' => ['variants' => ['wide_section']]]));
    $this->assertSame(['INVALID_STYLE_ENTRY'], $codes['styles.variants.0']);
    $codes = $this->catchErrors(fn () => $this->manager->saveAsVariant('Bad key', "container:{$this->uuid}", ['.loose' => ['default' => ['padding' => '1rem']]]));
    $this->assertSame(['INVALID_STYLE_KEY'], $codes['styles..loose']);
  }

  /**
   * Composition capture: slot keys ride the styles array through the seam.
   *
   * The client walks the staged subtree and translates child map entries
   * into slot keys (variant-scope.js captureEntries); server-side they are
   * ordinary relative keys — sourceScope() only ever sees the root
   * element's key, and validateAt() speaks the slot grammar, so save and
   * push accept them with no dedicated path.
   */
  public function testSlotKeyCapture(): void {
    $styles = [
      '' => ['default' => ['padding' => '2rem']],
      'block(text,2)' => ['default' => ['padding' => '1rem']],
      'container(1)/block(image,1)' => ['default' => ['padding' => '1rem']],
    ];
    $variant = $this->manager->saveAsVariant('Hero treatment', "container:{$this->uuid}", $styles);
    $this->assertSame('kind:container', $variant->getScope());
    $this->assertSame($styles, $variant->getStyles());

    // Pushes carry slot keys the same way.
    $pushed = ['block(text,2)' => ['default' => ['padding' => '3rem']]];
    $this->manager->push($variant, "container:{$this->uuid}", $pushed);
    $this->assertSame($pushed, DaedalusStylishVariant::load($variant->id())->getStyles());

    // A path the root kind does not structurally contain rejects through
    // the same seam, re-rooted onto the styles parameter.
    $codes = $this->catchErrors(fn () => $this->manager->saveAsVariant('Bad path', "container:{$this->uuid}", [
      'field(field_x)' => ['default' => ['padding' => '1rem']],
    ]));
    $this->assertSame(['INVALID_STYLE_KEY'], $codes['styles.field(field_x)']);
  }

  /**
   * Push-update: revisioned, scope-guarded, validated, counted.
   */
  public function testPush(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Wide section',
      'machine_name' => 'wide_section',
      'scope' => 'kind:container',
    ]);
    $variant->setStyles(['' => ['default' => ['padding' => '1rem']]]);
    $variant->save();
    $first_revision = (int) $variant->getRevisionId();

    // Two saved uses to count: one by machine name, one by uuid.
    EntityTest::create([
      'name' => 'a',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        "container:{$this->uuid}" => ['variants' => [['variant' => 'wide_section', 'fragment' => '']]],
      ]),
    ])->save();
    $other = $this->container->get('uuid')->generate();
    EntityTest::create([
      'name' => 'b',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        "container:$other" => ['variants' => [['variant' => $variant->uuid(), 'fragment' => '']]],
        "entity:$other" => ['default' => ['padding' => '2rem']],
      ]),
    ])->save();

    $usages = $this->manager->push($variant, "container:{$this->uuid}", [
      '' => ['default' => ['padding' => '3rem'], 'hover' => ['margin' => '1rem']],
    ]);

    $this->assertSame(2, $usages);
    $reloaded = DaedalusStylishVariant::load($variant->id());
    // A NEW default revision (a bad push is one revert away) with
    // identity fields untouched and the pushed styles in place.
    $this->assertGreaterThan($first_revision, (int) $reloaded->getRevisionId());
    $this->assertSame('wide_section', $reloaded->getMachineName());
    $this->assertSame('kind:container', $reloaded->getScope());
    $this->assertSame(['' => ['default' => ['padding' => '3rem'], 'hover' => ['margin' => '1rem']]], $reloaded->getStyles());
    $this->assertStringContainsString("container:{$this->uuid}", (string) $reloaded->getRevisionLogMessage());

    // Cross-scope pushes are rejected: a block's styles cannot update a
    // section variant.
    $codes = $this->catchErrors(fn () => $this->manager->push($variant, "entity:{$this->uuid}", [
      '' => ['default' => ['padding' => '1rem']],
    ]));
    $this->assertSame(['STYLE_VARIANT_SCOPE_MISMATCH'], $codes['variant']);

    // Guide validation applies to pushes too.
    $codes = $this->catchErrors(fn () => $this->manager->push($variant, "container:{$this->uuid}", [
      '' => ['default' => ['padding' => '6rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['styles.default.padding']);

    // The MASTER push (the variant master surface: the variant edited in
    // isolation): an empty key has no source element to scope-match, so
    // the source checks skip and the styles validate against the
    // variant's own scope — still revisioned, still counted.
    $master_revision = (int) DaedalusStylishVariant::load($variant->id())->getRevisionId();
    $usages = $this->manager->push($variant, '', [
      '' => ['default' => ['padding' => '2rem']],
    ]);
    $this->assertSame(2, $usages);
    $reloaded = DaedalusStylishVariant::load($variant->id());
    $this->assertGreaterThan($master_revision, (int) $reloaded->getRevisionId());
    $this->assertSame(['' => ['default' => ['padding' => '2rem']]], $reloaded->getStyles());
    $this->assertStringContainsString('variant code view', (string) $reloaded->getRevisionLogMessage());

    // The variant's own scope still gates a master push's values.
    $codes = $this->catchErrors(fn () => $this->manager->push($variant, '', [
      '' => ['default' => ['padding' => '6rem']],
    ]));
    $this->assertSame(['STYLE_VALUE_ABOVE_MAX'], $codes['styles.default.padding']);
  }

  /**
   * Usage counting: wearers from the usage table; both forms; no ghosts.
   */
  public function testUsages(): void {
    $variant = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'kind:block']);
    $variant->setStyles(['' => ['default' => ['padding' => '1rem']]]);
    $variant->save();

    $uuids = [];
    for ($i = 0; $i < 4; $i++) {
      $uuids[] = $this->container->get('uuid')->generate();
    }
    // Two entries on one entity: WEARERS count, one per host entity.
    EntityTest::create([
      'name' => 'two-entries',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        "block:$uuids[0]" => ['variants' => [['variant' => 'card', 'fragment' => '']]],
        "block:$uuids[1]" => ['variants' => [['variant' => 'card', 'fragment' => '']]],
      ]),
    ])->save();
    // …one by uuid on another…
    EntityTest::create([
      'name' => 'uuid-use',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        "block:$uuids[2]" => ['variants' => [['variant' => $variant->uuid(), 'fragment' => '']]],
      ]),
    ])->save();
    // …and a lookalike that mentions the name without referencing it.
    EntityTest::create([
      'name' => 'no-use',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode(["block:$uuids[3]" => ['default' => ['background-image' => 'url("/card.png")']]]),
    ])->save();

    $this->assertSame(2, $this->manager->usages($variant));
  }

  /**
   * The resolveHost loads by id or uuid and tolerates junk.
   */
  public function testResolveHost(): void {
    $entity = EntityTest::create(['name' => 'host']);
    $entity->save();

    $this->assertSame($entity->id(), $this->manager->resolveHost('entity_test', (string) $entity->id())?->id());
    $this->assertSame($entity->id(), $this->manager->resolveHost('entity_test', $entity->uuid())?->id());
    $this->assertNull($this->manager->resolveHost('entity_test', '999999'));
    $this->assertNull($this->manager->resolveHost('no_such_type', '1'));
    $this->assertNull($this->manager->resolveHost(NULL, NULL));
  }

}
