<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the condition type plugin system.
 *
 * One vocabulary powers three consumers — the printer's wrappers, the
 * validator's membership check, and the AI schema's condition table — so
 * this covers the manager's flattened table, configurable thresholds, and
 * each consumer picking the vocabulary up.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ConditionTypeTest extends KernelTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * The shipped plugins provide the condition vocabulary.
   */
  public function testShippedConditions(): void {
    $manager = $this->container->get('plugin.manager.daedalus_stylish_condition_type');
    $this->assertSame([
      'hover' => ':hover',
      'focus' => ':focus',
      'focus-within' => ':focus-within',
      'focus-visible' => ':focus-visible',
      'active' => ':active',
      'first-child' => ':first-child',
      'last-child' => ':last-child',
      'only-child' => ':only-child',
      'empty' => ':empty',
      'odd' => ':nth-child(odd)',
      'even' => ':nth-child(even)',
      'not-first-child' => ':not(:first-child)',
      'not-last-child' => ':not(:last-child)',
      'not-only-child' => ':not(:only-child)',
      'not-empty' => ':not(:empty)',
      'narrow' => '@container (max-width: 767px)',
      'medium' => '@container (min-width: 768px) and (max-width: 1023px)',
      'dark' => '@media (prefers-color-scheme: dark)',
      'reduced-motion' => '@media (prefers-reduced-motion: reduce)',
    ], array_map(static fn (array $definition) => $definition['wrapper'], $manager->getConditions()));

    // The structural conditions are FACTS (the element's place in the
    // markup, never posable); every other condition is not. The four
    // negations are facts of the same shape, identified by their :not()
    // wrapper (odd and even are each other's complement and need none).
    $this->assertSame(
      ['first-child', 'last-child', 'only-child', 'empty', 'odd', 'even', 'not-first-child', 'not-last-child', 'not-only-child', 'not-empty'],
      array_keys(array_filter($manager->getConditions(), static fn (array $definition): bool => ($definition['fact'] ?? FALSE) === TRUE)),
    );
    $this->assertTrue($manager->isFact('first-child'));
    $this->assertTrue($manager->isFact('odd'));
    $this->assertTrue($manager->isFact('not-last-child'));
    $this->assertTrue($manager->isFact('not-empty'));
    $this->assertSame(':not(:last-child)', $manager->getWrapper('not-last-child'));
    $this->assertFalse($manager->isFact('hover'));
    $this->assertFalse($manager->isFact('narrow'));
    $this->assertFalse($manager->isFact('bogus'));

    $this->assertTrue($manager->hasCondition('hover'));
    $this->assertFalse($manager->hasCondition('default'));
    $this->assertFalse($manager->hasCondition('bogus'));
    $this->assertNull($manager->getWrapper('bogus'));
    $this->assertTrue($manager->isContainerCondition('narrow'));
    $this->assertTrue($manager->isContainerCondition('medium'));
    $this->assertFalse($manager->isContainerCondition('dark'));
    $this->assertFalse($manager->isContainerCondition('hover'));
    // A scheme condition is keyed on the declared preference fact
    // (preference.feature), not the wrapper text: dark is one, a
    // width-only condition and a pseudo-class are not.
    $this->assertTrue($manager->isSchemeCondition('dark'));
    $this->assertFalse($manager->isSchemeCondition('narrow'));
    $this->assertFalse($manager->isSchemeCondition('hover'));
    $this->assertFalse($manager->isSchemeCondition('reduced-motion'));
    $this->assertFalse($manager->isSchemeCondition('bogus'));

    // Every condition carries its type's declared axis.
    $this->assertSame([
      'hover' => 'state',
      'focus' => 'state',
      'focus-within' => 'state',
      'focus-visible' => 'state',
      'active' => 'state',
      'first-child' => 'structural',
      'last-child' => 'structural',
      'only-child' => 'structural',
      'empty' => 'structural',
      'odd' => 'structural',
      'even' => 'structural',
      'not-first-child' => 'structural',
      'not-last-child' => 'structural',
      'not-only-child' => 'structural',
      'not-empty' => 'structural',
      'narrow' => 'width',
      'medium' => 'width',
      'dark' => 'scheme',
      'reduced-motion' => 'motion',
    ], array_map(static fn (array $definition) => $definition['axis'], $manager->getConditions()));
    $this->assertSame('width', $manager->getAxis('narrow'));
    $this->assertSame('structural', $manager->getAxis('first-child'));
    $this->assertNull($manager->getAxis('bogus'));
    // Axis-declaration order: the order axes first appear across the
    // (weight, id)-ordered types — the canonical compound part order. The
    // structural axis sits between state and width (weight 15) so a
    // compound reads hover+first-child+narrow.
    $this->assertSame(['state', 'structural', 'width', 'scheme', 'motion'], $manager->conditionAxes());
  }

  /**
   * Compound conditions: part grammar, canonical order, container detection.
   */
  public function testCompoundConditions(): void {
    $manager = $this->container->get('plugin.manager.daedalus_stylish_condition_type');

    $this->assertSame(['narrow', 'dark'], $manager::compoundParts('narrow+dark'));
    $this->assertSame(['hover'], $manager::compoundParts('hover'));

    // Canonicalization sorts parts into axis-declaration order and is
    // idempotent; single conditions and reserved keys pass through.
    $this->assertSame('hover+narrow+dark', $manager->canonicalizeCondition('dark+narrow+hover'));
    $this->assertSame('hover+narrow+dark', $manager->canonicalizeCondition('hover+narrow+dark'));
    $this->assertSame('narrow+dark', $manager->canonicalizeCondition('dark+narrow'));
    $this->assertSame('narrow+reduced-motion', $manager->canonicalizeCondition('reduced-motion+narrow'));
    // A structural fact takes its axis's place: after the state, before
    // the width band.
    $this->assertSame('hover+first-child+narrow', $manager->canonicalizeCondition('narrow+first-child+hover'));
    $this->assertSame('odd+dark', $manager->canonicalizeCondition('dark+odd'));
    $this->assertSame('hover', $manager->canonicalizeCondition('hover'));
    $this->assertSame('default', $manager->canonicalizeCondition('default'));
    $this->assertSame('variants', $manager->canonicalizeCondition('variants'));
    // Unknown parts sort after every placed part, keeping spelled order:
    // canonicalization never invents or drops anything.
    $this->assertSame('dark+bogus+worse', $manager->canonicalizeCondition('bogus+dark+worse'));

    // A compound uses containers when any part does.
    $this->assertTrue($manager->isContainerCondition('narrow+dark'));
    $this->assertTrue($manager->isContainerCondition('hover+medium'));
    $this->assertFalse($manager->isContainerCondition('hover+dark'));
    // A compound is a scheme condition when ANY part is.
    $this->assertTrue($manager->isSchemeCondition('narrow+dark'));
    $this->assertTrue($manager->isSchemeCondition('hover+dark'));
    $this->assertFalse($manager->isSchemeCondition('hover+medium'));
    $this->assertTrue($manager->mapUsesContainerConditions([
      'block:x' => ['narrow+dark' => ['padding' => '1rem']],
    ]));
    $this->assertFalse($manager->mapUsesContainerConditions([
      'block:x' => ['hover+dark' => ['padding' => '1rem']],
    ]));

    // Map normalization canonicalizes condition keys in place, touching
    // nothing else.
    $this->assertSame([
      'block:x' => [
        'variants' => [['variant' => 'cta_bold', 'fragment' => '']],
        'default' => ['padding' => '2rem'],
        'narrow+dark' => ['padding' => '1rem'],
        'hover+medium' => ['color' => 'red'],
      ],
      'block:y' => 'not-an-entry',
    ], $manager->normalizeMapConditions([
      'block:x' => [
        'variants' => [['variant' => 'cta_bold', 'fragment' => '']],
        'default' => ['padding' => '2rem'],
        'dark+narrow' => ['padding' => '1rem'],
        'medium+hover' => ['color' => 'red'],
      ],
      'block:y' => 'not-an-entry',
    ]));
  }

  /**
   * The validator accepts compounds and rejects their misuse correctively.
   */
  public function testCompoundValidation(): void {
    $uuid = $this->container->get('uuid')->generate();
    $validator = $this->container->get('daedalus_stylish.style_map_validator');

    // Any part order is valid — canonicalization is the write path's job,
    // never a validation demand.
    $this->assertSame([], $validator->validate([
      "entity:$uuid" => [
        'narrow+dark' => ['padding' => '1rem'],
        'dark+hover' => ['color' => 'red'],
        'hover+narrow+reduced-motion' => ['transition' => 'none'],
        'first-child' => ['margin-top' => '0'],
        'hover+last-child' => ['color' => 'blue'],
        'not-last-child' => ['margin-bottom' => '24px'],
        'hover+not-first-child+narrow' => ['color' => 'green'],
      ],
    ]));
    // Two facts share the structural axis: alternatives, never combinables;
    // a negation is one more fact on the axis, so it never combines with
    // the fact it complements.
    $errors = $validator->validate(["entity:$uuid" => ['first-child+odd' => ['padding' => '1rem']]]);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_CONDITION_AXIS_CONFLICT', $errors[0]->getCode());
    $this->assertSame('structural', $errors[0]->getContext()['axis']);
    $errors = $validator->validate(["entity:$uuid" => ['first-child+not-last-child' => ['padding' => '1rem']]]);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_CONDITION_AXIS_CONFLICT', $errors[0]->getCode());
    $this->assertSame('structural', $errors[0]->getContext()['axis']);

    // An unknown part fails the whole compound, naming the part.
    $errors = $validator->validate(["entity:$uuid" => ['narrow+bogus' => ['padding' => '1rem']]]);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_STYLE_CONDITION', $errors[0]->getCode());
    $this->assertSame("entity:$uuid.narrow+bogus", $errors[0]->getPath());
    $this->assertStringContainsString('"bogus"', $errors[0]->getMessage());
    // "default" is the absence of conditions, never a compound part.
    $errors = $validator->validate(["entity:$uuid" => ['narrow+default' => ['padding' => '1rem']]]);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_STYLE_CONDITION', $errors[0]->getCode());

    // Two parts on one axis conflict (conditions sharing an axis are
    // alternatives), duplicates included.
    $errors = $validator->validate(["entity:$uuid" => ['narrow+medium' => ['padding' => '1rem']]]);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_CONDITION_AXIS_CONFLICT', $errors[0]->getCode());
    $this->assertSame('width', $errors[0]->getContext()['axis']);
    $errors = $validator->validate(["entity:$uuid" => ['dark+dark' => ['color' => 'red']]]);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_CONDITION_AXIS_CONFLICT', $errors[0]->getCode());

    // Two spellings of one compound in the same entry would collide on
    // write-side canonicalization: rejected as the ambiguity it is.
    $errors = $validator->validate([
      "entity:$uuid" => [
        'narrow+dark' => ['padding' => '1rem'],
        'dark+narrow' => ['padding' => '2rem'],
      ],
    ]);
    $this->assertCount(1, $errors);
    $this->assertSame('DUPLICATE_STYLE_CONDITION', $errors[0]->getCode());
    $this->assertSame('narrow+dark', $errors[0]->getContext()['canonical']);

    // The scope-only seam (variant styles) speaks the same compound
    // grammar: one shared validateEntryConditions.
    $this->assertSame([], $validator->validateAt(['' => ['dark+narrow' => ['padding' => '1rem']]], Address::fromString('kind:container')));
    $errors = $validator->validateAt(['' => ['narrow+medium' => ['padding' => '1rem']]], Address::fromString('kind:container'));
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_CONDITION_AXIS_CONFLICT', $errors[0]->getCode());
  }

  /**
   * Breakpoint thresholds come from config, with code defaults.
   */
  public function testConfigurableThresholds(): void {
    $this->config('daedalus_stylish.settings')
      ->set('breakpoints', ['narrow' => 500, 'medium' => 1200])
      ->save();
    $manager = $this->container->get('plugin.manager.daedalus_stylish_condition_type');
    $manager->clearCachedDefinitions();

    $this->assertSame('@container (max-width: 500px)', $manager->getWrapper('narrow'));
    // Medium's lower edge derives from the narrow ceiling, so the two
    // configured numbers always tile the bands exactly.
    $this->assertSame('@container (min-width: 501px) and (max-width: 1200px)', $manager->getWrapper('medium'));

    // The printer emits the configured threshold.
    $uuid = $this->container->get('uuid')->generate();
    $css = $this->container->get('daedalus_stylish.css_printer')->printMap([
      "entity:$uuid" => ['narrow' => ['padding' => '1rem']],
    ]);
    $this->assertStringContainsString('@container (max-width: 500px) {', $css);
  }

  /**
   * The validator rejects unknown condition names, listing the valid set.
   */
  public function testValidatorMembership(): void {
    $uuid = $this->container->get('uuid')->generate();
    $validator = $this->container->get('daedalus_stylish.style_map_validator');

    $this->assertSame([], $validator->validate([
      "entity:$uuid" => [
        'default' => ['padding' => '2rem'],
        'narrow' => ['padding' => '1rem'],
        'reduced-motion' => [],
      ],
    ]));

    $errors = $validator->validate(["entity:$uuid" => ['compact' => ['padding' => '1rem']]]);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_STYLE_CONDITION', $errors[0]->getCode());
    $this->assertSame("entity:$uuid.compact", $errors[0]->getPath());
    $this->assertContains('default', $errors[0]->getContext()['valid_conditions']);
    $this->assertContains('narrow', $errors[0]->getContext()['valid_conditions']);
    $this->assertStringContainsString('narrow', $errors[0]->getHint());
  }

  /**
   * Container-condition detection over a style map.
   */
  public function testMapUsesContainerConditions(): void {
    $manager = $this->container->get('plugin.manager.daedalus_stylish_condition_type');

    $this->assertTrue($manager->mapUsesContainerConditions([
      'block:x' => ['default' => ['padding' => '2rem'], 'narrow' => ['padding' => '1rem']],
    ]));
    // Media/pseudo conditions, variant references: no containers needed.
    $this->assertFalse($manager->mapUsesContainerConditions([
      'block:x' => ['default' => ['padding' => '2rem'], 'dark' => ['padding' => '1rem'], 'hover' => ['color' => 'red']],
      'block:y' => ['variants' => [['variant' => 'cta_bold', 'fragment' => '']]],
    ]));
    $this->assertFalse($manager->mapUsesContainerConditions([]));

    // The container_roots kill switch.
    $container_map = ['block:x' => ['medium' => ['padding' => '4rem']]];
    $this->assertTrue($manager->demandsContainerRoots($container_map));
    $this->config('daedalus_stylish.settings')->set('container_roots', FALSE)->save();
    $this->assertFalse($manager->demandsContainerRoots($container_map));
  }

  /**
   * The AI schema exposes the condition vocabulary with live wrappers.
   */
  public function testConditionsSchema(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $definitions = $this->container->get('entity_field.manager')->getFieldDefinitions('entity_test', 'entity_test');
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($definitions['daedalus_stylish_styles']);

    $conditions = $handler->getSchemaAdditions($definitions['daedalus_stylish_styles'])['style_guide']['conditions'];
    $this->assertSame('always applies', $conditions['default']);
    $this->assertSame(['wrapper' => ':hover', 'axis' => 'state'], $conditions['hover']);
    $this->assertSame(['wrapper' => '@container (max-width: 767px)', 'axis' => 'width'], $conditions['narrow']);
    // A structural fact says so: the AI learns it is a place, not a state.
    $this->assertSame(['wrapper' => ':first-child', 'axis' => 'structural', 'fact' => TRUE], $conditions['first-child']);
    $this->assertSame(['wrapper' => ':nth-child(odd)', 'axis' => 'structural', 'fact' => TRUE], $conditions['odd']);
    $this->assertSame(['wrapper' => ':not(:last-child)', 'axis' => 'structural', 'fact' => TRUE], $conditions['not-last-child']);
  }

}
