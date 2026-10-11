<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishAnimation;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the materials provider: tokens, swatches, variants, animations.
 *
 * Pins what the roster carries for each of daedalus_stylish's kinds and where it
 * comes from: tokens from the active skin on declared scales (undeclared
 * scales dropped, no skin means no tokens), swatches from the published
 * set in label order, variants from the published set weight-then-label
 * with spellings derived from the declarations (class for the style
 * axis, an attribute on the element's tag for a declared axis of the
 * container kind, the block kind, a block type or a block plugin, none
 * for a layout's or a base tag's axes), the root-only depth fact,
 * animations from the published set in label order spelled in the
 * animation slot, and the provider's cache domain; and that a material
 * write mid-request resets the memo.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishMaterialsProviderTest extends KernelTestBase {

  use DesignSystemActivationTrait;
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
    'daedalus_stylish_control_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_variant');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'color' => ['label' => 'Color', 'shape' => 'color'],
      ],
    ]);
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['variant_axes' => [['width' => ['depth' => 'root']], 'container_layout']],
    ]);
    // A block-type axis spells as an attribute on the block's own tag,
    // the container rule on every tagged scope.
    $this->container->get('state')->set('daedalus_stylish_control_test.block_types', [
      'testimonial' => ['variant_axes' => ['emphasis']],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
  }

  /**
   * Builds the roster fresh.
   */
  protected function roster(): MaterialsRoster {
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $builder->reset();
    return $builder->build();
  }

  /**
   * Creates a published variant.
   */
  protected function createVariant(array $values): DaedalusStylishVariant {
    $variant = DaedalusStylishVariant::create($values + ['styles' => ['' => ['default' => ['padding' => '1rem']]]]);
    $variant->save();
    return $variant;
  }

  /**
   * Every record carries its material's membership; the roster partitions.
   *
   * The provider serves every record it has (members of an inactive
   * system included: MaterialsRosterBuilder::members() reads them) and
   * the builder keeps the global ones and the active system's, so a
   * member of an inactive system is absent from every surface that reads
   * the roster. Tokens are their skin's: a member skin's tokens leave
   * with it.
   */
  public function testMembershipPartitionsTheRoster(): void {
    $this->installConfig(['daedalus_blueprint']);
    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora', 'default_skin' => 'aurora'])->save();
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora', 'design_system' => 'aurora']);
    $skin->setPackageTokens(['space' => ['gutter' => '2rem']])->save();
    $swatch = DaedalusStylishSwatch::create(['label' => 'Aurora accent', 'kind' => 'color', 'design_system' => 'aurora']);
    $swatch->setSwatchValue('#6941c6');
    $swatch->save();
    $global_swatch = DaedalusStylishSwatch::create(['label' => 'Ink', 'kind' => 'color']);
    $global_swatch->setSwatchValue('#111111');
    $global_swatch->save();
    $this->createVariant([
      'label' => 'Aurora band',
      'machine_name' => 'aurora_band',
      'scope' => 'kind:container',
      'design_system' => 'aurora',
    ]);
    $this->createVariant(['label' => 'Band', 'machine_name' => 'band', 'scope' => 'kind:container']);
    $animation = DaedalusStylishAnimation::create([
      'label' => 'Aurora fade',
      'machine_name' => 'aurora_fade',
      'design_system' => 'aurora',
    ]);
    $animation->setSteps(['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']]);
    $animation->save();

    // No active system: the global records alone. No system means no
    // active skin, and the provider serves the ACTIVE skin's tokens only
    // (token ids are not skin-qualified), so a system's token records
    // exist only while it is active; its other members are readable
    // through members() at any time.
    $roster = $this->roster();
    $this->assertSame([], array_keys($roster->tokens()));
    $this->assertSame(['ink'], array_keys($roster->swatches()));
    $this->assertSame(['band'], array_keys($roster->variants()));
    $this->assertSame([], array_keys($roster->animations()));
    $this->assertNull($roster->get(MaterialKind::Variant, 'band')->designSystem);
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $members = static fn () => array_map(static fn (MaterialRecord $record): string => $record->kind->value . ':' . $record->id, $builder->members('aurora'));
    $this->assertSame(['swatch:aurora_accent', 'variant:aurora_band', 'animation:aurora_fade'], $members());

    // Aurora active: its members join the global ones, membership on the
    // record and its array shape. The activation syncs every enabled
    // module, so the suite's shipped block variants and animations (global
    // materials) join the roster here too.
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('aurora');
    $roster = $this->roster();
    $this->assertSame(['space/gutter'], array_keys($roster->tokens()));
    $this->assertSame(['token:space/gutter', 'swatch:aurora_accent', 'variant:aurora_band', 'animation:aurora_fade'], $members());
    $this->assertSame('aurora', $roster->get(MaterialKind::Token, 'space/gutter')->designSystem);
    $this->assertSame(['aurora_accent', 'ink'], array_keys($roster->swatches()));
    $this->assertSame(['aurora_band', 'band', 'block_fade_in', 'block_lift', 'block_rise', 'block_scale_in'], array_keys($roster->variants()));
    $this->assertSame(['aurora_fade', 'fade_in', 'pulse', 'rise', 'scale_in', 'spin'], array_keys($roster->animations()));
    $this->assertSame('aurora', $roster->get(MaterialKind::Variant, 'aurora_band')->toArray()['design_system']);
    $this->assertFalse($roster->get(MaterialKind::Swatch, 'aurora_accent')->isGlobal());
    $this->assertTrue($roster->get(MaterialKind::Swatch, 'ink')->isGlobal());

    // A membership naming no design system never saves.
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('"nope" is not a design system on this site');
    $this->createVariant([
      'label' => 'Lost',
      'machine_name' => 'lost',
      'scope' => 'kind:container',
      'design_system' => 'nope',
    ]);
  }

  /**
   * Tokens: the active skin on declared scales, spelled through var().
   *
   * The record carries the stored token's facts: a bare-minted token has
   * no description and is offered; a token minted as a record carries
   * what its record says.
   */
  public function testTokens(): void {
    $this->assertSame([], $this->roster()->tokens(), 'No active skin, no tokens.');

    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens([
      'space' => [
        '4' => '1rem',
        'gutter' => ['value' => '1.5rem', 'description' => 'The band gutter.', 'offered' => FALSE],
      ],
      'color' => ['accent' => ['default' => '#1a1a2e', 'dark' => '#e8e8f0']],
      // Not a declared scale: never a material.
      'radius' => ['sm' => '2px'],
    ])->save();
    $this->activateSkin($skin);

    $tokens = $this->roster()->tokens();
    $this->assertSame(['space/4', 'space/gutter', 'color/accent'], array_keys($tokens));
    $four = $tokens['space/4'];
    $this->assertSame('4', $four->label);
    $this->assertSame('space', $four->group);
    $this->assertSame('1rem', $four->value);
    $this->assertSame('', $four->description);
    $this->assertTrue($four->offered);
    $this->assertSame(['style' => 'var(--space-4)'], $four->spellings);
    $this->assertSame(MaterialRecord::ORIGIN_USER, $four->origin);
    $gutter = $tokens['space/gutter'];
    $this->assertSame('1.5rem', $gutter->value);
    $this->assertSame('The band gutter.', $gutter->description);
    $this->assertFalse($gutter->offered);
    $this->assertSame(['space/4', 'color/accent'], array_keys($this->roster()->offered()->tokens()), 'the offered view leaves the unoffered token out');
    $accent = $tokens['color/accent'];
    $this->assertSame(['default' => '#1a1a2e', 'dark' => '#e8e8f0'], $accent->value);
    $this->assertSame('var(--color-accent)', $accent->spelling('style'));
    $this->assertSame('color/accent', $this->roster()->bySpelling('style', 'var(--color-accent)')?->id);
  }

  /**
   * Swatches: the published set in label order, the reserved namespace.
   */
  public function testSwatches(): void {
    foreach ([['Lime green', '#4dff8c'], ['Brand red', '#e94560']] as [$label, $value]) {
      $swatch = DaedalusStylishSwatch::create(['label' => $label, 'kind' => 'color']);
      $swatch->setSwatchValue($value);
      $swatch->save();
    }
    $draft = DaedalusStylishSwatch::create(['label' => 'Unreleased', 'kind' => 'color']);
    $draft->setSwatchValue('#000000');
    $draft->setUnpublished();
    $draft->save();

    $swatches = $this->roster()->swatches();
    $this->assertSame(['brand_red', 'lime_green'], array_keys($swatches));
    $lime = $swatches['lime_green'];
    $this->assertSame('Lime green', $lime->label);
    $this->assertSame('color', $lime->group);
    $this->assertSame('#4dff8c', $lime->value);
    $this->assertSame(['style' => 'var(--swatch-lime_green)'], $lime->spellings);
    $this->assertTrue($lime->published);
    $this->assertSame(MaterialRecord::ORIGIN_USER, $lime->origin);
    $this->assertSame('lime_green', $this->roster()->bySpelling('style', 'var(--swatch-lime_green)')?->id);
  }

  /**
   * Animations: the published set in label order, the motion namespace.
   */
  public function testAnimations(): void {
    foreach ([['Rise', 'rise'], ['Fade in', 'fade_in']] as [$label, $name]) {
      $animation = DaedalusStylishAnimation::create([
        'label' => $label,
        'machine_name' => $name,
        'description' => "$label motion.",
      ]);
      $animation->setSteps(['to' => ['opacity' => '1'], 'from' => ['opacity' => '0']]);
      $animation->save();
    }
    $draft = DaedalusStylishAnimation::create(['label' => 'Unreleased']);
    $draft->setSteps(['0%' => ['opacity' => '0']]);
    $draft->setUnpublished();
    $draft->save();

    $animations = $this->roster()->animations();
    $this->assertSame(['fade_in', 'rise'], array_keys($animations));
    $fade = $animations['fade_in'];
    $this->assertSame('Fade in', $fade->label);
    $this->assertSame('Fade in motion.', $fade->description);
    $this->assertNull($fade->group);
    // The value is the canonical steps map (the record's shape the schema
    // projects as steps and the editor reads).
    $this->assertSame(['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']], $fade->value);
    // The spelling is the property-scoped animation slot, never var().
    $this->assertSame(['animation' => 'motion-fade_in'], $fade->spellings);
    $this->assertTrue($fade->published);
    $this->assertSame(MaterialRecord::ORIGIN_USER, $fade->origin);
    $this->assertSame('fade_in', $this->roster()->bySpelling('animation', 'motion-fade_in')?->id);
    // The roster order is the compiled keyframes file's order.
    $this->assertSame(['fade_in', 'rise'], array_map(static fn ($animation) => $animation->getMachineName(), $this->container->get('daedalus_stylish.animation_repository')->publishedAnimations()));
  }

  /**
   * Variants: published, weight-then-label, spellings from declarations.
   */
  public function testVariants(): void {
    $this->createVariant([
      'label' => 'Button',
      'machine_name' => 'button',
      'scope' => 'block_type:text',
      'description' => 'Dress the link as a solid button.',
    ]);
    $this->createVariant([
      'label' => 'Full width',
      'machine_name' => 'container_full',
      'scope' => 'kind:container',
      'axis' => 'width',
      'weight' => -10,
    ]);
    $this->createVariant([
      'label' => 'Columns',
      'machine_name' => 'row',
      'scope' => 'kind:container',
      'axis' => 'container_layout',
    ]);
    $this->createVariant([
      'label' => 'Emphatic',
      'machine_name' => 'testimonial_emphatic',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
    ]);
    $this->createVariant([
      'label' => 'Airy',
      'machine_name' => 'container_airy',
      'scope' => 'kind:container',
      'ai_offered' => FALSE,
    ]);
    $draft = DaedalusStylishVariant::create([
      'label' => 'Unreleased look',
      'machine_name' => 'unreleased_look',
      'scope' => 'kind:container',
      'styles' => ['' => ['default' => ['padding' => '1rem']]],
    ]);
    $draft->setUnpublished();
    $draft->save();

    $roster = $this->roster();
    $variants = $roster->variants();
    // Weight first (container_full at -10), then label.
    $this->assertSame(['container_full', 'container_airy', 'button', 'row', 'testimonial_emphatic'], array_keys($variants));
    // The AI opt-out rides the record: the full roster (legality) keeps the
    // withheld variant, the offered view (the brief and the schema) omits
    // it, and every other record is offered, the default.
    $this->assertFalse($variants['container_airy']->offered);
    $this->assertTrue($variants['button']->offered);
    $this->assertSame(['container_full', 'button', 'row', 'testimonial_emphatic'], array_keys($roster->offered()->variants()));

    $button = $variants['button'];
    $this->assertSame('Button', $button->label);
    $this->assertSame('Dress the link as a solid button.', $button->description);
    $this->assertSame('style', $button->group);
    $this->assertSame('block_type:text', (string) $button->scope);
    $this->assertSame(['class' => 'button'], $button->spellings);
    $this->assertSame([], $button->applies);
    $this->assertNull($button->ownedBy);
    $this->assertSame(0, $button->weight);
    $this->assertSame(MaterialRecord::ORIGIN_USER, $button->origin);

    // A container axis the container kind declares: attribute spelling,
    // machine name minus container_, root depth from the declaration.
    $full = $variants['container_full'];
    $this->assertSame('width', $full->group);
    $this->assertSame(['attr:kind:container:width' => 'full'], $full->spellings);
    $this->assertSame(['depth' => 'root'], $full->applies);
    $this->assertTrue($full->rootOnly());
    $this->assertSame(-10, $full->weight);

    // An unprefixed axis variant spells as its label; container_layout
    // spells as the layout attribute; the axis is not root-only.
    $row = $variants['row'];
    $this->assertSame(['attr:kind:container:layout' => 'columns'], $row->spellings);
    $this->assertSame([], $row->applies);

    // A style-axis container variant is a class like any other.
    $this->assertSame(['class' => 'container_airy'], $variants['container_airy']->spellings);

    // A declared non-style axis on a block type spells as that axis's
    // attribute on the block's tag, the value the machine name minus the
    // type's prefix (block axes are attributes like the container's).
    $this->assertSame(['attr:block_type:testimonial:emphasis' => 'emphatic'], $variants['testimonial_emphatic']->spellings);
    $this->assertSame('emphasis', $variants['testimonial_emphatic']->group);

    // The index and the predicate read the records.
    $this->assertSame('container_full', $roster->bySpelling('attr:kind:container:width', 'full')?->id);
    $this->assertSame('testimonial_emphatic', $roster->bySpelling('attr:block_type:testimonial:emphasis', 'emphatic')?->id);
    $this->assertNull($roster->bySpelling('attr:width', 'full'), 'An attribute value has no site-wide slot.');
    $this->assertSame('button', $roster->bySpelling('class', 'button')?->id);
    $this->assertTrue($roster->applicable($full, Address::fromString('layout:layout_onecol')));
    $this->assertFalse($roster->applicable($full, Address::fromString('layout:layout_onecol'), top_level: FALSE));
    $this->assertFalse($roster->applicable($button, Address::fromString('kind:container')));
  }

  /**
   * The one spelling rule, and its validation against the scope's axes.
   *
   * Attr vs class has ONE declaration: the scope's variant_axes. The
   * style axis (every scope's built-in) spells as a class; a declared
   * axis of a scope whose elements have a dialect tag (the two kinds,
   * block types, block plugins) spells as its attribute on that tag (the
   * scope's own prefix stripped from the axis and the machine name, the
   * label lowercased as a fallback value); a declared axis on a scope
   * with no tag (a layout, a base tag treatment) has no page-language
   * spelling; neither has an axis the scope no longer declares: the entity
   * refuses to save one, so such a row is legacy data a post_update
   * migration moves, and the roster must keep building (every save in the
   * migration compiles it) while the row's siblings still sit on the
   * retired axis.
   */
  public function testSpellingsFollowTheScopeAxes(): void {
    $container = ['style', 'width', 'container_layout'];
    $this->assertSame(['class' => 'airy'], DaedalusStylishMaterialsProvider::spellings(Address::fromString('kind:container'), 'style', 'airy', 'Airy', $container));
    $this->assertSame(['attr:kind:container:width' => 'full'], DaedalusStylishMaterialsProvider::spellings(Address::fromString('kind:container'), 'width', 'container_full', 'Full width', $container));
    $this->assertSame(['attr:kind:container:layout' => 'two-columns'], DaedalusStylishMaterialsProvider::spellings(Address::fromString('kind:container'), 'container_layout', 'row', ' Two  Columns ', $container));
    $this->assertSame(['class' => 'button'], DaedalusStylishMaterialsProvider::spellings(Address::fromString('block_type:text'), 'style', 'button', 'Button', ['style']));
    // Block axes are attributes on the block's tag, prefix rule per
    // scope (testimonial_ on block_type:testimonial, block_ on kind:block,
    // the base plugin id on block_plugin:*), label slug when unprefixed.
    $this->assertSame([
      'attr:block_type:testimonial:emphasis' => 'emphatic',
    ], DaedalusStylishMaterialsProvider::spellings(Address::fromString('block_type:testimonial'), 'emphasis', 'testimonial_emphatic', 'Emphatic', [
      'style',
      'emphasis',
    ]));
    $this->assertSame([
      'attr:block_type:testimonial:emphasis' => 'very-loud',
    ], DaedalusStylishMaterialsProvider::spellings(Address::fromString('block_type:testimonial'), 'testimonial_emphasis', 'loud', 'Very loud', [
      'style',
      'testimonial_emphasis',
    ]));
    $this->assertSame([
      'attr:kind:block:shadow' => 'soft',
    ], DaedalusStylishMaterialsProvider::spellings(Address::fromString('kind:block'), 'shadow', 'block_soft', 'Soft shadow', [
      'style',
      'shadow',
    ]));
    // The partition: the same look name on two scopes is two slots.
    $this->assertSame([
      'attr:kind:container:animation' => 'fade_in',
    ], DaedalusStylishMaterialsProvider::spellings(Address::fromString('kind:container'), 'animation', 'container_fade_in', 'Fade in', [
      'style',
      'animation',
    ]));
    $this->assertSame([
      'attr:kind:block:animation' => 'fade_in',
    ], DaedalusStylishMaterialsProvider::spellings(Address::fromString('kind:block'), 'animation', 'block_fade_in', 'Fade in', [
      'style',
      'animation',
    ]));
    $this->assertSame([
      'attr:block_plugin:system_menu_block:density' => 'compact',
    ], DaedalusStylishMaterialsProvider::spellings(Address::fromString('block_plugin:system_menu_block'), 'density', 'system_menu_block_compact', 'Compact', [
      'style',
      'density',
    ]));
    $this->assertSame([], DaedalusStylishMaterialsProvider::spellings(Address::fromString('layout:layout_onecol'), 'tone', 'loud', 'Loud', [
      'style',
      'tone',
    ]));
    $this->assertSame([], DaedalusStylishMaterialsProvider::spellings(Address::fromString('base:h2'), 'tone', 'loud', 'Loud', [
      'style',
      'tone',
    ]));
    // A legacy row on a retired axis: rostered, spelled nowhere.
    $this->assertSame([], DaedalusStylishMaterialsProvider::spellings(Address::fromString('block_type:testimonial'), 'emphasis', 'testimonial_emphatic', 'Emphatic', ['style']));
    $this->assertSame([], DaedalusStylishMaterialsProvider::spellings(Address::fromString('kind:container'), 'proportions', 'split_67_33', 'Split 67/33', [
      'style',
      'width',
      'container_layout',
    ]));
  }

  /**
   * The provider names its cache domain; a write resets the memo.
   */
  public function testCacheMetadataAndReset(): void {
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $before = $builder->build();
    $tags = $before->getCacheableMetadata()->getCacheTags();
    $domain = [
      'daedalus_skin_list',
      'daedalus_swatch_list',
      'daedalus_variant_list',
      'daedalus_animation_list',
      'config:daedalus_stylish.settings',
      'config:system.theme',
      'daedalus_ui_page_builder',
    ];
    foreach ($domain as $tag) {
      $this->assertContains($tag, $tags);
    }
    $this->assertSame([], $before->swatches());

    // A swatch mint mid-request: the next build sees it without an
    // explicit reset (the entity hooks reset the memo).
    $swatch = DaedalusStylishSwatch::create(['label' => 'Lime green', 'kind' => 'color']);
    $swatch->setSwatchValue('#4dff8c');
    $swatch->save();
    $after = $builder->build();
    $this->assertNotSame($before, $after);
    $this->assertSame(['lime_green'], array_keys($after->swatches()));

    // Same for a variant save and a skin activation.
    $this->createVariant(['label' => 'Button', 'machine_name' => 'button', 'scope' => 'block_type:text']);
    $this->assertSame(['button'], array_keys($builder->build()->variants()));
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens(['space' => ['4' => '1rem']])->save();
    $this->assertSame([], $builder->build()->tokens(), 'A saved skin is not the active one yet.');
    $this->activateSkin($skin);
    $this->assertSame(['space/4'], array_keys($builder->build()->tokens()), 'Activating a skin resets the memo.');
    $this->assertSame(MaterialKind::Token, $builder->build()->get(MaterialKind::Token, 'space/4')->kind);

    // And an animation save (beside the suite's shipped animations, which
    // the activation above landed).
    $animation = DaedalusStylishAnimation::create(['label' => 'Drift']);
    $animation->setSteps(['0%' => ['opacity' => '0']]);
    $animation->save();
    $this->assertSame(['drift', 'fade_in', 'pulse', 'rise', 'scale_in', 'spin'], array_keys($builder->build()->animations()));
  }

  /**
   * The updatable flag is the distro lock as the client reads it.
   *
   * A shipped swatch, variant or animation record is not updatable while
   * the lock holds (a consuming site) and updatable once the developer
   * gate lifts it (`$settings['daedalus_blueprint_developer'] = TRUE`); a
   * site-minted material is updatable either way. The client's locked()
   * and the update gesture read this one flag.
   */
  public function testUpdatableFollowsTheDistroLock(): void {
    $shipped_swatch = DaedalusStylishSwatch::create(['label' => 'Brand red', 'kind' => 'color']);
    $shipped_swatch->setSwatchValue('#e94560');
    $shipped_swatch->setShippedSource('daedalus_stylish', ['kind' => 'color', 'value' => '#e94560']);
    $shipped_swatch->save();
    $mine = DaedalusStylishSwatch::create(['label' => 'Lime green', 'kind' => 'color']);
    $mine->setSwatchValue('#4dff8c');
    $mine->save();
    $shipped_variant = DaedalusStylishVariant::create([
      'label' => 'Airy',
      'machine_name' => 'container_airy',
      'scope' => 'kind:container',
      'styles' => ['' => ['default' => ['padding' => '1rem']]],
    ]);
    $shipped_variant->setShippedSource('daedalus_stylish', ['' => ['default' => ['padding' => '1rem']]]);
    $shipped_variant->save();
    $shipped_animation = DaedalusStylishAnimation::create(['label' => 'Fade in']);
    $shipped_animation->setSteps(['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']]);
    $shipped_animation->setShippedSource('daedalus_stylish', [
      'steps' => ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']],
    ]);
    $shipped_animation->save();

    $roster = $this->roster();
    $this->assertSame(MaterialRecord::ORIGIN_SHIPPED, $roster->swatches()['brand_red']->origin);
    $this->assertFalse($roster->swatches()['brand_red']->updatable);
    $this->assertTrue($roster->swatches()['lime_green']->updatable);
    $this->assertFalse($roster->variants()['container_airy']->updatable);
    $this->assertFalse($roster->animations()['fade_in']->updatable);
    $this->assertFalse($roster->swatches()['brand_red']->toArray()['updatable']);

    // The gate lifts the lock for every shipped kind at once; the records
    // keep their shipped origin (the fork and revert gestures still read
    // it).
    $this->setSetting('daedalus_blueprint_developer', TRUE);
    $roster = $this->roster();
    $this->assertSame(MaterialRecord::ORIGIN_SHIPPED, $roster->swatches()['brand_red']->origin);
    $this->assertTrue($roster->swatches()['brand_red']->updatable);
    $this->assertTrue($roster->swatches()['lime_green']->updatable);
    $this->assertTrue($roster->variants()['container_airy']->updatable);
    $this->assertTrue($roster->animations()['fade_in']->updatable);
  }

}
