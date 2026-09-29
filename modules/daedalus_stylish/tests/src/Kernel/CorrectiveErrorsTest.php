<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

// cspell:ignore pading zzzzqx
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRoster;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that validator errors carry the legal alternatives.
 *
 * The corrective-error contract: wherever the legal alternatives are
 * enumerable, the error names them — closest recognized properties on an
 * unknown property, the element's stylable properties on an undeclared one,
 * declared parts on an unknown part key, scope-matching variants on a
 * missing variant reference — so an AI writer self-corrects from the error
 * alone, without a second discovery round-trip. The style guide in play
 * comes from daedalus_stylish_guide_test: section declares spacing (padding max
 * 4rem), and the testimonial block type declares typography plus a
 * .testimonial__quote sub-element.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class CorrectiveErrorsTest extends KernelTestBase {

  use DesignSystemActivationTrait;
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
  ];

  /**
   * The validator under test.
   */
  protected StyleMapValidator $validator;

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
    $this->installEntitySchema('daedalus_variant');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->uuid = $this->container->get('uuid')->generate();
  }

  /**
   * Finds the one error with a code, asserting it exists.
   */
  protected function findError(array $errors, string $code): object {
    foreach ($errors as $error) {
      if ($error->getCode() === $code) {
        return $error;
      }
    }
    $this->fail(sprintf('No %s error found in: %s', $code, implode(', ', array_map(static fn ($error) => $error->getCode(), $errors))));
  }

  /**
   * The map lane accepts '::> *' keys and rejects them strictly like parts.
   *
   * The children pseudo-part on style MAP keys: a container key's '::> *'
   * entry validates against the container kind's children declaration
   * (daedalus_stylish_guide_test declares layout on it); strict mode rejects
   * out-of-declaration properties on it, and the '::> *' key itself on
   * elements declaring no children; malformed suffixes fail the key
   * grammar with the corrective naming the suffix.
   */
  public function testChildrenPseudoPartMapKeys(): void {
    $this->assertSame([], $this->validator->validate([
      "container:{$this->uuid}::> *" => [
        'default' => ['flex-grow' => '1'],
        'narrow' => ['flex-grow' => '0'],
      ],
      "container:{$this->uuid}::.band-content > *" => ['default' => ['flex-grow' => '1']],
    ]));

    $errors = $this->validator->validate([
      "container:{$this->uuid}::>::>" => ['default' => ['flex-grow' => '1']],
    ]);
    $error = $this->findError($errors, 'INVALID_STYLE_KEY');
    $this->assertStringContainsString('"> *"', $error->getHint());
    $errors = $this->validator->validate([
      "container:{$this->uuid}::>" => ['default' => ['flex-grow' => '1']],
    ]);
    $this->assertNotNull($this->findError($errors, 'INVALID_STYLE_KEY'), 'The retired pseudo-part spelling is not a key.');

    $this->enforce('strict');
    $errors = $this->validator->validate([
      "container:{$this->uuid}::> *" => ['default' => ['font-size' => '2rem']],
    ]);
    $this->assertSame('STYLE_PROPERTY_NOT_DECLARED', $errors[0]->getCode());

    $errors = $this->validator->validate([
      "block:{$this->uuid}::> *" => ['default' => ['flex-grow' => '1']],
    ]);
    $error = $this->findError($errors, 'STYLE_CHILDREN_NOT_DECLARED');
    $this->assertStringContainsString('children', $error->getHint());
  }

  /**
   * An unrecognized property comes back with its closest legal names.
   */
  public function testUnknownPropertySuggestions(): void {
    // A typo resolves by edit distance.
    $errors = $this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['pading' => '2rem']],
    ]);
    $error = $this->findError($errors, 'INVALID_STYLE_PROPERTY');
    $this->assertSame('padding', $error->getContext()['similar'][0]);
    $this->assertStringContainsString('Did you mean padding', $error->getHint());

    // An invented compound resolves by containment.
    $errors = $this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['font-color' => 'red']],
    ]);
    $error = $this->findError($errors, 'INVALID_STYLE_PROPERTY');
    $this->assertContains('color', $error->getContext()['similar']);

    // Nothing close: no suggestion payload, the base hint stands alone.
    $errors = $this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['zzzzqx' => '1']],
    ]);
    $error = $this->findError($errors, 'INVALID_STYLE_PROPERTY');
    $this->assertArrayNotHasKey('similar', $error->getContext());
    $this->assertStringNotContainsString('Did you mean', $error->getHint());
  }

  /**
   * Strict mode's undeclared-property error lists what IS stylable.
   */
  public function testUndeclaredPropertyListsLegalProperties(): void {
    $this->enforce('strict');

    // The section declaration lists only the spacing group; a typography
    // property is refused with spacing's property names as the legal set.
    $errors = $this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['font-size' => '1.5rem']],
    ]);
    $error = $this->findError($errors, 'STYLE_PROPERTY_NOT_DECLARED');
    $this->assertContains('padding', $error->getContext()['declared_properties']);
    $this->assertNotContains('font-size', $error->getContext()['declared_properties']);
    $this->assertSame(['spacing'], $error->getContext()['declared_groups']);
    // The roster rides the context; the hint is the dialect catalog's one
    // formatter (shared with the parser), the nearest granted names and
    // the roster's home, never the list.
    $this->assertSame([], $error->getContext()['similar'], 'Nothing in the spacing group is close to font-size.');
    $this->assertSame((new VocabularyRoster(Address::kind('container'), 'spec', ['spacing' => $error->getContext()['declared_properties']]))->corrective('font-size'), $error->getHint());
    $this->assertStringStartsWith('Nothing granted here is close to "font-size".', $error->getHint());
    $this->assertStringNotContainsString('padding', $error->getHint());

    // A miss with a granted family names it.
    $errors = $this->validator->validate([
      "container:{$this->uuid}" => ['default' => ['margin-inline' => 'auto']],
    ]);
    $error = $this->findError($errors, 'STYLE_PROPERTY_NOT_DECLARED');
    $this->assertSame(['margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left'], $error->getContext()['similar']);
    $this->assertStringStartsWith('Did you mean margin, margin-top, margin-right, margin-bottom or margin-left?', $error->getHint());
  }

  /**
   * Strict mode's unknown-part error names the declared parts.
   */
  public function testUnknownPartListsDeclaredParts(): void {
    $this->enforce('strict');

    $errors = $this->validator->validateAt([
      '::.mystery' => ['default' => ['font-size' => '1rem']],
    ], Address::fromString('block_type:testimonial'));
    $error = $this->findError($errors, 'STYLE_ELEMENT_NOT_DECLARED');
    $this->assertSame(['.testimonial__quote'], $error->getContext()['declared_elements']);
    $this->assertStringContainsString('.testimonial__quote', $error->getHint());
  }

  /**
   * A missing variant reference lists the variants legal for the element.
   */
  public function testVariantNotFoundListsScopeLegalVariants(): void {
    foreach ([
      ['label' => 'Hero block', 'machine_name' => 'block_hero', 'scope' => 'kind:block'],
      ['label' => 'Quiet testimonial', 'machine_name' => 'testimonial_quiet', 'scope' => 'block_type:testimonial'],
      ['label' => 'Airy section', 'machine_name' => 'section_airy', 'scope' => 'kind:container'],
    ] as $values) {
      DaedalusStylishVariant::create($values)->save();
    }

    // A block key with no host entity captures the degraded "kind:block"
    // scope, which pairs with the whole block family — and only the block
    // family: blocks never hold layouts, so section-family variants never
    // apply to a block key.
    $errors = $this->validator->validate([
      "block:{$this->uuid}" => ['variants' => [['variant' => 'no_such_variant', 'fragment' => '']]],
    ]);
    $error = $this->findError($errors, 'STYLE_VARIANT_NOT_FOUND');
    // The context is the dialect door's shape: the reference as written and
    // {kind, id, spelling} candidates, the spelling being the id here.
    $this->assertSame('no_such_variant', $error->getContext()['reference']);
    $this->assertSame([
      ['kind' => 'variant', 'id' => 'block_hero', 'spelling' => 'block_hero'],
      ['kind' => 'variant', 'id' => 'testimonial_quiet', 'spelling' => 'testimonial_quiet'],
    ], $error->getContext()['candidates']);
    $this->assertStringContainsString('block_hero "Hero block" (class="block_hero")', $error->getHint());
    // The enumeration speaks the guide's vocabulary and the creation
    // pointer is framed how-to-when-asked, never an open invitation.
    $this->assertStringContainsString("Variants among the site's materials", $error->getHint());
    $this->assertStringContainsString('only when the user asks', $error->getHint());

    // A section key sees only section-scoped variants.
    $errors = $this->validator->validate([
      "container:{$this->uuid}" => ['variants' => [['variant' => 'no_such_variant', 'fragment' => '']]],
    ]);
    $error = $this->findError($errors, 'STYLE_VARIANT_NOT_FOUND');
    $this->assertSame(['section_airy'], array_column($error->getContext()['candidates'], 'id'));

    // No variant applies: an empty candidate list (the key stays, both
    // doors carry it), and the hint says so before pointing at creation.
    $errors = $this->validator->validate([
      "entity:{$this->uuid}" => ['variants' => [['variant' => 'no_such_variant', 'fragment' => '']]],
    ]);
    $error = $this->findError($errors, 'STYLE_VARIANT_NOT_FOUND');
    $this->assertSame([], $error->getContext()['candidates']);
    $this->assertStringContainsString('No saved variants apply', $error->getHint());
  }

  /**
   * Variant candidates in correctives carry the roster description.
   *
   * The rejection is the small-model teaching channel: a variant's
   * description is the "when to reach for this look" text the materials
   * roster advertises, so a corrective naming candidates names them by
   * that description too, through the roster's one formatter (id "Label"
   * (spelling): description), so the writer learns the design language at
   * exactly the moment it reached outside it. Variants without a
   * description stop after the spelling. The description rides the hint
   * only: the structured context is the two doors' shared {kind, id,
   * spelling} triple.
   */
  public function testVariantCandidatesCarryDescriptions(): void {
    DaedalusStylishVariant::create([
      'label' => 'Airy section',
      'machine_name' => 'section_airy',
      'scope' => 'kind:container',
      'description' => 'Generous vertical breathing room for calm, editorial pacing.',
    ])->save();
    DaedalusStylishVariant::create([
      'label' => 'Tight section',
      'machine_name' => 'section_tight',
      'scope' => 'kind:container',
    ])->save();

    $errors = $this->validator->validate([
      "container:{$this->uuid}" => ['variants' => [['variant' => 'no_such_variant', 'fragment' => '']]],
    ]);
    $error = $this->findError($errors, 'STYLE_VARIANT_NOT_FOUND');
    $this->assertStringContainsString('section_airy "Airy section" (class="section_airy"): Generous vertical breathing room for calm, editorial pacing.', $error->getHint());
    $this->assertStringContainsString('section_tight "Tight section" (class="section_tight")', $error->getHint());
    $this->assertSame(['section_airy', 'section_tight'], array_column($error->getContext()['candidates'], 'id'));
    $this->assertSame([['kind', 'id', 'spelling']], array_unique(array_map('array_keys', $error->getContext()['candidates']), SORT_REGULAR));
  }

  /**
   * A mis-scoped variant reference names the variants that DO apply.
   *
   * The nearest-valid move on the scope-mismatch path, both lanes: the
   * writer reached for a real variant of the wrong scope, so the corrective
   * lists what the element (map lane) or the slot's scope (nested lane)
   * could actually wear, descriptions included — same seam as the
   * not-found corrective, never a second mechanism.
   */
  public function testScopeMismatchSuggestsScopeLegalVariants(): void {
    DaedalusStylishVariant::create([
      'label' => 'Quiet testimonial',
      'machine_name' => 'testimonial_quiet',
      'scope' => 'block_type:testimonial',
    ])->save();
    DaedalusStylishVariant::create([
      'label' => 'Airy section',
      'machine_name' => 'section_airy',
      'scope' => 'kind:container',
      'description' => 'Generous vertical breathing room.',
    ])->save();

    // Map lane: a container key wearing a block-scoped variant.
    $errors = $this->validator->validate([
      "container:{$this->uuid}" => ['variants' => [['variant' => 'testimonial_quiet', 'fragment' => '']]],
    ]);
    $error = $this->findError($errors, 'STYLE_VARIANT_SCOPE_MISMATCH');
    $this->assertStringContainsString('Variants among the site\'s materials that DO apply to this element: section_airy "Airy section" (class="section_airy"): Generous vertical breathing room.', $error->getHint());
    $this->assertSame('testimonial_quiet', $error->getContext()['reference']);
    $this->assertSame(['section_airy'], array_column($error->getContext()['candidates'], 'id'));

    // Nested lane: a container-scoped save whose root references a
    // block-scoped variant.
    $errors = $this->validator->validateAt([
      '' => ['variants' => [['variant' => 'testimonial_quiet', 'fragment' => '']]],
    ], Address::fromString('kind:container'));
    $error = $this->findError($errors, 'STYLE_VARIANT_SCOPE_MISMATCH');
    $this->assertStringContainsString('section_airy "Airy section" (class="section_airy"): Generous vertical breathing room.', $error->getHint());
    $this->assertSame(['section_airy'], array_column($error->getContext()['candidates'], 'id'));

    // No scope-legal variant exists: the hint stays honest, no candidates.
    $errors = $this->validator->validate([
      "entity:{$this->uuid}" => ['variants' => [['variant' => 'testimonial_quiet', 'fragment' => '']]],
    ]);
    $error = $this->findError($errors, 'STYLE_VARIANT_SCOPE_MISMATCH');
    $this->assertSame([], $error->getContext()['candidates']);
    $this->assertStringNotContainsString('DO apply', $error->getHint());
  }

}
