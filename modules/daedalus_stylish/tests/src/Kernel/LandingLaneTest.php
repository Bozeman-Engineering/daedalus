<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

// cspell:ignore pading zzzx
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the validator's landing lane: the open posture, structure only.
 *
 * The disk lane lands a module's shipped variant at the landing posture
 * (StyleMapValidator::withLandingLane()): a module's literal global is
 * not the site's act, and the roster's verdict (VariantConformance)
 * already judges offering under the law of the day, so what the lane
 * refuses is structure alone. On a strict site with a bound scale the
 * save gate refuses a literal and an unminted token; on the lane both
 * land, an existing published referent the verdict withholds is admitted,
 * and everything structural (an unrecognized property, a selector word
 * the browser does not know, a dangling, mis-scoped or unpublished
 * reference, a cycle) still fails. The lane is a posture for ONE call
 * and never leaks, not even past an exception.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class LandingLaneTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * Any theme name works: an unknown theme simply has no theme layers.
   */
  protected const THEME = 'stark';

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
    'daedalus_stylish_control_test',
  ];

  /**
   * The validator under test.
   */
  protected StyleMapValidator $validator;

  /**
   * The container kind's address, the scope every fixture variant wears.
   */
  protected Address $scope;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_skin');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all three storages on every build.
    $this->installEntitySchema('daedalus_variant');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and var() references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    $this->setUpCurrentUser([], [], TRUE);
    // The site under test owns one scale, and the container kind binds
    // padding to it: the one bound property every case below writes.
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
      ],
    ]);
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => [
        'property_groups' => [
          'spacing' => [
            'constraints' => [
              'padding' => ['scale' => 'space'],
            ],
          ],
        ],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    // One minted token, so the scale is honestly bound (a literal is off
    // it, an unminted name is not on it) and a minted reference passes as
    // the control.
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens(['space' => ['4' => '1rem']])->save();
    $this->activateSkin($skin);
    $this->enforce('strict');
    $this->validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->scope = Address::fromString('kind:container');
  }

  /**
   * Validates container-scoped variant styles on the save gate.
   *
   * @return array<string, string[]>
   *   Error codes keyed by path.
   */
  protected function gate(array $styles, array $context = []): array {
    return $this->codes($this->validator->validateAt($styles, $this->scope, ['theme' => self::THEME] + $context));
  }

  /**
   * Validates container-scoped variant styles on the landing lane.
   *
   * @return array<string, string[]>
   *   Error codes keyed by path.
   */
  protected function lane(array $styles, array $context = []): array {
    return $this->validator->withLandingLane(fn (): array => $this->gate($styles, $context));
  }

  /**
   * Collects error codes keyed by path.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   Errors from the validator.
   */
  protected function codes(array $errors): array {
    $codes = [];
    foreach ($errors as $error) {
      $codes[$error->getPath()][] = $error->getCode();
    }
    return $codes;
  }

  /**
   * Variant styles nesting one root reference on the first child container.
   */
  protected function nesting(string $reference): array {
    return [
      'container(1)' => [
        'variants' => [['variant' => $reference, 'fragment' => '']],
      ],
    ];
  }

  /**
   * Creates and saves a published container variant with the given styles.
   *
   * The entity save does not validate, so a variant the site's law
   * refuses lands here the way a shipped one will: published, and
   * withheld by the verdict.
   */
  protected function createVariant(string $machine_name, array $styles, string $scope = 'kind:container', bool $published = TRUE): DaedalusStylishVariantInterface {
    $variant = \Drupal::entityTypeManager()->getStorage('daedalus_variant')->create([
      'label' => $machine_name,
      'machine_name' => $machine_name,
      'scope' => $scope,
      'status' => $published,
    ]);
    assert($variant instanceof DaedalusStylishVariantInterface);
    $variant->setStyles($styles)->save();
    return $variant;
  }

  /**
   * The law refuses a literal and an unminted token; the lane lands both.
   *
   * Strict with padding bound to the space scale: a literal is off the
   * scale (STYLE_VALUE_NOT_ON_SCALE) and an unminted name in the scale's
   * namespace is not on it either, while the minted token passes on both
   * postures. On the lane constraints are offers and token references
   * unchecked, so the literal and the unminted token land, exactly what
   * the open save gate would accept; whether the site OFFERS them is the
   * verdict's.
   */
  public function testLawRefusalsLandOnTheLane(): void {
    $literal = ['' => ['default' => ['padding' => '0.75em']]];
    $unminted = ['' => ['default' => ['padding' => 'var(--space-9)']]];
    $minted = ['' => ['default' => ['padding' => 'var(--space-4)']]];

    $this->assertSame(['STYLE_VALUE_NOT_ON_SCALE'], $this->gate($literal)['default.padding']);
    $this->assertContains('STYLE_VALUE_NOT_ON_SCALE', $this->gate($unminted)['default.padding']);
    $this->assertSame([], $this->gate($minted));

    $this->assertSame([], $this->lane($literal));
    $this->assertSame([], $this->lane($unminted));
    $this->assertSame([], $this->lane($minted));
  }

  /**
   * Structure stays law on the lane.
   *
   * An unrecognized property (INVALID_STYLE_PROPERTY: recognition is the
   * universal vocabulary's, never a grant), a selector word the browser
   * does not know (STYLE_SELECTOR_UNKNOWN), a reference to no saved
   * variant (STYLE_VARIANT_NOT_FOUND), to an unpublished one (no
   * material, so the same not-found) and to one scoped to another kind
   * (STYLE_VARIANT_SCOPE_MISMATCH) fail on the lane exactly as on the
   * gate. An undeclared PART on a declared element is the grant posture's
   * question, not structure's: strict refuses it on the gate
   * (STYLE_ELEMENT_NOT_DECLARED) and the lane, the open posture, accepts
   * it grammar-checked, leaving the verdict to withhold it under strict.
   */
  public function testStructureStaysLaw(): void {
    $this->createVariant('block_look', ['' => ['default' => ['padding' => 'var(--space-4)']]], 'kind:block');
    $this->createVariant('drafted', ['' => ['default' => ['padding' => 'var(--space-4)']]], published: FALSE);

    $this->assertSame(['INVALID_STYLE_PROPERTY'], $this->lane(['' => ['default' => ['pading' => '1rem']]])['default.pading']);
    $this->assertSame(['STYLE_SELECTOR_UNKNOWN'], $this->lane(['::zzzx' => ['default' => ['padding' => 'var(--space-4)']]])['::zzzx']);

    $this->assertSame(['STYLE_VARIANT_NOT_FOUND'], $this->lane($this->nesting('no_such_variant'))['container(1).variants.0']);
    $this->assertSame(['STYLE_VARIANT_NOT_FOUND'], $this->lane($this->nesting('drafted'))['container(1).variants.0']);
    $this->assertSame(['STYLE_VARIANT_SCOPE_MISMATCH'], $this->lane($this->nesting('block_look'))['container(1).variants.0']);

    $part = ['::.mystery' => ['default' => ['padding' => 'var(--space-4)']]];
    $this->assertSame(['STYLE_ELEMENT_NOT_DECLARED'], $this->gate($part)['::.mystery']);
    $this->assertSame([], $this->lane($part));
  }

  /**
   * A nested reference admits a withheld referent on the lane; cycles stay.
   *
   * "raw" is published with a literal padding, so the verdict withholds
   * it under strict: the gate refuses a variant nesting it by name
   * (STYLE_VARIANT_NONCONFORMING), the lane resolves it through the
   * repository and lands the pair, the verdict's to withhold. The cycle
   * walk follows the same referents: "loop_b" (withheld, a literal too)
   * nests "loop_a", so saving a reference to loop_b INTO loop_a closes a
   * cycle the lane reports, where the gate had already stopped at the
   * nonconforming referent.
   */
  public function testWithheldReferentAdmitted(): void {
    $this->createVariant('raw', ['' => ['default' => ['padding' => '0.75em']]]);
    $this->createVariant('loop_a', []);
    $this->createVariant('loop_b', [
      '' => ['default' => ['padding' => '0.75em']],
      'container(1)' => ['variants' => [['variant' => 'loop_a', 'fragment' => '']]],
    ]);
    $withheld = $this->container->get('daedalus_stylish.variant_conformance')->withheld();
    $this->assertArrayHasKey('raw', $withheld, 'the verdict withholds the literal variant');
    $this->assertArrayHasKey('loop_b', $withheld, 'the verdict withholds the literal nesting variant');
    $this->assertArrayNotHasKey('loop_a', $withheld, 'the empty variant conforms');

    $this->assertSame(['STYLE_VARIANT_NONCONFORMING'], $this->gate($this->nesting('raw'))['container(1).variants.0']);
    $this->assertSame([], $this->lane($this->nesting('raw')));

    $this->assertSame(['STYLE_VARIANT_NONCONFORMING'], $this->gate($this->nesting('loop_b'), ['variant_identity' => ['loop_a']])['container(1).variants.0']);
    $this->assertSame(['STYLE_VARIANT_REFERENCE_CYCLE'], $this->lane($this->nesting('loop_b'), ['variant_identity' => ['loop_a']])['container(1).variants.0']);
  }

  /**
   * The lane is one call's posture and never leaks.
   *
   * After withLandingLane() returns, the next validation refuses the
   * literal again; an exception thrown inside restores the posture too,
   * and the callable's return value comes back through.
   */
  public function testTheLaneDoesNotLeak(): void {
    $literal = ['' => ['default' => ['padding' => '0.75em']]];

    $this->assertSame([], $this->lane($literal));
    $this->assertSame(['STYLE_VALUE_NOT_ON_SCALE'], $this->gate($literal)['default.padding']);

    $this->assertSame('through', $this->validator->withLandingLane(static fn (): string => 'through'));

    try {
      $this->validator->withLandingLane(static function (): never {
        throw new \RuntimeException('mid-lane');
      });
      $this->fail('the exception travels through the lane');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('mid-lane', $e->getMessage());
    }
    $this->assertSame(['STYLE_VALUE_NOT_ON_SCALE'], $this->gate($literal)['default.padding']);
  }

}
