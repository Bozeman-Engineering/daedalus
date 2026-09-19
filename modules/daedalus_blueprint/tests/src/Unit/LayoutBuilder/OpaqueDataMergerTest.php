<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Unit\LayoutBuilder;

use Drupal\daedalus_blueprint\LayoutBuilder\OpaqueDataMerger;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the OpaqueDataMerger.
 */
#[CoversClass(OpaqueDataMerger::class)]
#[Group('daedalus_blueprint')]
class OpaqueDataMergerTest extends UnitTestCase {

  /**
   * The merger under test.
   *
   * @var \Drupal\daedalus_blueprint\LayoutBuilder\OpaqueDataMerger
   */
  protected OpaqueDataMerger $merger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->merger = new OpaqueDataMerger();
  }

  /**
   * Tests that non-managed keys are preserved from previous state.
   */
  public function testPreservesNonManagedKeys(): void {
    $previous = [
      'layout_builder_lock' => ['lock_settings' => TRUE],
      'some_contrib_module' => ['secret' => 'value'],
    ];
    $incoming = [];
    $managed_keys = [];

    $result = $this->merger->merge($previous, $incoming, $managed_keys);

    $this->assertSame($previous, $result);
  }

  /**
   * Tests that managed keys from incoming are used.
   */
  public function testManagedKeysFromIncomingUsed(): void {
    $previous = [
      'non_managed' => 'preserve me',
    ];
    $incoming = [
      'column_widths' => '33-67',
    ];
    $managed_keys = [
      'column_widths' => [
        'label' => 'Column widths',
        'schema' => ['type' => 'string'],
      ],
    ];

    $result = $this->merger->merge($previous, $incoming, $managed_keys);

    $this->assertSame('preserve me', $result['non_managed']);
    $this->assertSame('33-67', $result['column_widths']);
  }

  /**
   * Tests that managed keys omitted from incoming are preserved.
   *
   * Same PATCH contract as fields: not mentioning a key leaves it alone.
   */
  public function testManagedKeysOmittedArePreserved(): void {
    $previous = [
      'non_managed' => 'keep',
      'column_widths' => '50-50',
    ];
    $incoming = [];
    $managed_keys = [
      'column_widths' => [
        'label' => 'Column widths',
        'schema' => ['type' => 'string'],
      ],
    ];

    $result = $this->merger->merge($previous, $incoming, $managed_keys);

    $this->assertSame('keep', $result['non_managed']);
    $this->assertSame('50-50', $result['column_widths']);
  }

  /**
   * Tests update behavior: non-managed preserved, managed overridden.
   */
  public function testUpdateMixedKeys(): void {
    $previous = [
      'layout_builder_lock' => ['locked' => TRUE],
      'daedalus_layout' => ['uuid' => '6729b781-b4f2-f3fb-2c1a-172076b158c2', 'custom_classes' => 'old-class'],
      'layout_builder_styles_style' => ['hero' => 'large'],
    ];
    $incoming = [
      'daedalus_layout' => ['uuid' => '8833d491-1d16-9c36-5242-e2c4e604c946', 'custom_classes' => 'new-class'],
      'layout_builder_styles_style' => ['hero' => 'small'],
    ];
    $managed_keys = [
      'daedalus_layout' => [
        'label' => 'Daedalus Layout',
        'schema' => ['type' => 'object'],
      ],
      'layout_builder_styles_style' => [
        'label' => 'Styles',
        'schema' => ['type' => 'object'],
      ],
    ];

    $result = $this->merger->merge($previous, $incoming, $managed_keys);

    // Non-managed preserved.
    $this->assertSame(['locked' => TRUE], $result['layout_builder_lock']);
    // Managed overridden with incoming values.
    $this->assertSame(['uuid' => '8833d491-1d16-9c36-5242-e2c4e604c946', 'custom_classes' => 'new-class'], $result['daedalus_layout']);
    $this->assertSame(['hero' => 'small'], $result['layout_builder_styles_style']);
  }

  /**
   * Tests create behavior: no previous state.
   */
  public function testCreateNoPreviousState(): void {
    $previous = [];
    $incoming = [
      'column_widths' => '25-75',
    ];
    $managed_keys = [
      'column_widths' => [
        'label' => 'Column widths',
        'schema' => ['type' => 'string'],
      ],
    ];

    $result = $this->merger->merge($previous, $incoming, $managed_keys);

    $this->assertSame(['column_widths' => '25-75'], $result);
  }

  /**
   * Tests create with managed key absent from incoming.
   */
  public function testCreateManagedKeyAbsent(): void {
    $previous = [];
    $incoming = [];
    $managed_keys = [
      'column_widths' => [
        'label' => 'Column widths',
        'schema' => ['type' => 'string'],
      ],
    ];

    $result = $this->merger->merge($previous, $incoming, $managed_keys);

    $this->assertEmpty($result);
  }

  /**
   * Tests that non-managed keys in incoming are ignored.
   *
   * Only managed keys from incoming are applied; any extra keys the AI
   * sends that are not managed are silently dropped.
   */
  public function testIncomingNonManagedKeysIgnored(): void {
    $previous = [
      'existing_non_managed' => 'preserve',
    ];
    $incoming = [
      'column_widths' => '50-50',
      'unknown_key' => 'should be ignored',
    ];
    $managed_keys = [
      'column_widths' => [
        'label' => 'Column widths',
        'schema' => ['type' => 'string'],
      ],
    ];

    $result = $this->merger->merge($previous, $incoming, $managed_keys);

    $this->assertSame('preserve', $result['existing_non_managed']);
    $this->assertSame('50-50', $result['column_widths']);
    $this->assertArrayNotHasKey('unknown_key', $result);
  }

  /**
   * Tests merge with empty managed keys acts as full preserve.
   */
  public function testEmptyManagedKeysPreservesAll(): void {
    $previous = [
      'key_a' => 'value_a',
      'key_b' => 'value_b',
    ];
    $incoming = [
      'key_a' => 'new_a',
      'key_c' => 'new_c',
    ];
    $managed_keys = [];

    $result = $this->merger->merge($previous, $incoming, $managed_keys);

    // All previous preserved, nothing from incoming applied.
    $this->assertSame($previous, $result);
  }

  /**
   * Tests merge with all keys managed.
   */
  public function testAllKeysManaged(): void {
    $previous = [
      'key_a' => 'old_a',
      'key_b' => 'old_b',
    ];
    $incoming = [
      'key_a' => 'new_a',
    ];
    $managed_keys = [
      'key_a' => ['label' => 'A'],
      'key_b' => ['label' => 'B'],
    ];

    $result = $this->merger->merge($previous, $incoming, $managed_keys);

    // key_a overridden, key_b preserved (omitted by AI).
    $this->assertSame(['key_a' => 'new_a', 'key_b' => 'old_b'], $result);
  }

  /**
   * Tests that explicit empty values clear a managed key.
   *
   * Mirrors the field empty predicate (FieldHandlerBase::deserialize()):
   * exactly NULL, '', and [] mean "clear this key".
   */
  #[DataProvider('emptyValueProvider')]
  public function testManagedKeyExplicitEmptyClears(mixed $empty_value): void {
    $previous = [
      'non_managed' => 'keep',
      'column_widths' => '50-50',
    ];
    $incoming = [
      'column_widths' => $empty_value,
    ];
    $managed_keys = [
      'column_widths' => ['label' => 'Column widths'],
    ];

    $result = $this->merger->merge($previous, $incoming, $managed_keys);

    $this->assertSame('keep', $result['non_managed']);
    $this->assertArrayNotHasKey('column_widths', $result);
  }

  /**
   * Data provider for testManagedKeyExplicitEmptyClears.
   */
  public static function emptyValueProvider(): array {
    return [
      'null' => [NULL],
      'empty string' => [''],
      'empty array' => [[]],
    ];
  }

  /**
   * Tests that falsy-but-not-empty values are stored, not cleared.
   *
   * The empty predicate is strict: 0, '0', and FALSE are real values.
   */
  public function testManagedKeyFalsyValuesAreStored(): void {
    $previous = [];
    $incoming = [
      'count' => 0,
      'flag' => FALSE,
      'label' => '0',
    ];
    $managed_keys = [
      'count' => ['label' => 'Count'],
      'flag' => ['label' => 'Flag'],
      'label' => ['label' => 'Label'],
    ];

    $result = $this->merger->merge($previous, $incoming, $managed_keys);

    $this->assertSame(['count' => 0, 'flag' => FALSE, 'label' => '0'], $result);
  }

  /**
   * Tests complex nested values are preserved/merged correctly.
   */
  public function testComplexNestedValues(): void {
    $previous = [
      'contrib_settings' => [
        'deeply' => ['nested' => ['structure' => TRUE]],
      ],
      'managed_complex' => [
        'old_config' => ['a' => 1, 'b' => 2],
      ],
    ];
    $incoming = [
      'managed_complex' => [
        'new_config' => ['x' => 10],
      ],
    ];
    $managed_keys = [
      'managed_complex' => ['label' => 'Complex managed'],
    ];

    $result = $this->merger->merge($previous, $incoming, $managed_keys);

    // Non-managed deep structure preserved exactly.
    $this->assertSame(
      ['deeply' => ['nested' => ['structure' => TRUE]]],
      $result['contrib_settings'],
    );
    // Managed key replaced entirely (not deep-merged).
    $this->assertSame(
      ['new_config' => ['x' => 10]],
      $result['managed_complex'],
    );
  }

  /**
   * Tests patch() applies values onto a live configuration array.
   *
   * Non-empty values are set, explicit empty values remove the key, and keys
   * not mentioned in incoming are untouched.
   */
  public function testPatch(): void {
    $config = [
      'id' => 'inline_block:text_section',
      'label' => 'Block',
      'style_variant' => 'hero',
      'background' => 'bg-primary',
    ];
    $incoming = [
      'style_variant' => NULL,
      'background' => 'bg-secondary',
    ];

    $result = $this->merger->patch($config, $incoming);

    // Explicit NULL removes the key.
    $this->assertArrayNotHasKey('style_variant', $result);
    // Declared value updates.
    $this->assertSame('bg-secondary', $result['background']);
    // Unmentioned keys untouched.
    $this->assertSame('inline_block:text_section', $result['id']);
    $this->assertSame('Block', $result['label']);
  }

  /**
   * Tests an omitted scope (NULL incoming) preserves previous managed data.
   */
  public function testMergeOptionalScopeOmittedPreservesManaged(): void {
    $previous = [
      'non_managed' => 'keep',
      'background' => 'bg-primary',
      'width' => 'max-w-screen-lg',
    ];
    $managed_keys = [
      'background' => ['label' => 'Background'],
      'width' => ['label' => 'Width'],
    ];

    $result = $this->merger->mergeOptionalScope($previous, NULL, $managed_keys);

    // Whole scope omitted: previous preserved verbatim, managed keys included.
    $this->assertSame($previous, $result);
  }

  /**
   * Tests a present empty scope also preserves managed keys.
   *
   * Under the PATCH contract an empty scope declares nothing, so it is
   * equivalent to omitting the scope entirely.
   */
  public function testMergeOptionalScopePresentEmptyPreservesManaged(): void {
    $previous = [
      'non_managed' => 'keep',
      'background' => 'bg-primary',
    ];
    $managed_keys = [
      'background' => ['label' => 'Background'],
    ];

    $result = $this->merger->mergeOptionalScope($previous, [], $managed_keys);

    $this->assertSame($previous, $result);
  }

  /**
   * Tests declaring a subset of managed keys preserves the rest.
   *
   * The safety win of the PATCH contract: updating one setting cannot
   * silently wipe the others.
   */
  public function testMergeOptionalScopeSubsetPreservesRest(): void {
    $previous = [
      'non_managed' => 'keep',
      'background' => 'bg-primary',
      'width' => 'max-w-screen-lg',
    ];
    $incoming = [
      'background' => 'bg-secondary',
    ];
    $managed_keys = [
      'background' => ['label' => 'Background'],
      'width' => ['label' => 'Width'],
    ];

    $result = $this->merger->mergeOptionalScope($previous, $incoming, $managed_keys);

    // Non-managed preserved, declared managed key updated, omitted managed
    // key (width) preserved.
    $this->assertSame('keep', $result['non_managed']);
    $this->assertSame('bg-secondary', $result['background']);
    $this->assertSame('max-w-screen-lg', $result['width']);
  }

}
