<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\Tests\daedalus\Traits\AiContextRefusalTrait;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the materials provider: tokens, animations, fonts, variants.
 *
 * Pins what the roster carries for each of daedalus_stylish's kinds and where it
 * comes from: tokens from the active skin on declared scales (undeclared
 * scales dropped, no skin means no tokens; a capture with no scale named
 * lands on the first declared scale whose shape fits the value), variants
 * from the published set weight-then-label
 * with spellings derived from the declarations (class for the style
 * axis, an attribute on the element's tag for a declared axis of the
 * container kind, the block kind, a block type or a block plugin, none
 * for a layout's or a base tag's axes), the root-only depth fact,
 * animations from the active skin in stored order spelled in the
 * animation slot, and the provider's cache domain; and that a material
 * write mid-request resets the memo. The AI context budget's guard on
 * the gestures: while the site is full, an offered mint of any kind and
 * an update turning `offered` on are refused with the one wording, and
 * a mint withheld from the AI, a face edit, and a flag re-stated go
 * through.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishMaterialsProviderTest extends KernelTestBase {

  use AiContextRefusalTrait;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_variant');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-*, var() and family references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'color' => ['label' => 'Color', 'shape' => 'color'],
        'gradient' => ['label' => 'Gradient', 'shape' => 'gradient'],
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
    $skin->setPackageTokens(['space' => ['gutter' => '2rem']]);
    $skin->setPackageAnimations(['aurora_fade' => ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']]]);
    $skin->save();
    $this->createVariant([
      'label' => 'Aurora section',
      'machine_name' => 'aurora_section',
      'scope' => 'kind:container',
      'design_system' => 'aurora',
    ]);
    $this->createVariant(['label' => 'Section', 'machine_name' => 'section', 'scope' => 'kind:container']);

    // No active system: the global records alone. No system means no
    // active skin, and the provider serves the ACTIVE skin's tokens and
    // animations only (their ids are not skin-qualified), so a system's
    // skin material records exist only while it is active; its other
    // members are readable through members() at any time.
    $roster = $this->roster();
    $this->assertSame([], array_keys($roster->tokens()));
    $this->assertSame(['section'], array_keys($roster->variants()));
    $this->assertSame([], array_keys($roster->animations()));
    $this->assertNull($roster->get(MaterialKind::Variant, 'section')->designSystem);
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $members = static fn () => array_map(static fn (MaterialRecord $record): string => $record->kind->value . ':' . $record->id, $builder->members('aurora'));
    $this->assertSame(['variant:aurora_section'], $members());

    // Aurora active: its members join the global ones, membership on the
    // record and its array shape; the skin's tokens and animations are
    // the system's materials now. The suite ships no global motion, so
    // nothing else joins on the activation's sync.
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('aurora');
    $roster = $this->roster();
    $this->assertSame(['space/gutter'], array_keys($roster->tokens()));
    $this->assertSame(['token:space/gutter', 'variant:aurora_section', 'animation:aurora_fade'], $members());
    $this->assertSame('aurora', $roster->get(MaterialKind::Token, 'space/gutter')->designSystem);
    $this->assertSame('aurora', $roster->get(MaterialKind::Animation, 'aurora_fade')->designSystem);
    $this->assertSame(['aurora_section', 'section'], array_keys($roster->variants()));
    $this->assertSame(['aurora_fade'], array_keys($roster->animations()));
    $this->assertSame('aurora', $roster->get(MaterialKind::Variant, 'aurora_section')->toArray()['design_system']);
    $this->assertFalse($roster->get(MaterialKind::Token, 'space/gutter')->isGlobal());
    $this->assertTrue($roster->get(MaterialKind::Variant, 'section')->isGlobal());

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
        'gutter' => ['value' => '1.5rem', 'description' => 'The section gutter.', 'offered' => FALSE],
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
    $this->assertSame('The section gutter.', $gutter->description);
    $this->assertFalse($gutter->offered);
    $this->assertSame(['space/4', 'color/accent'], array_keys($this->roster()->offered()->tokens()), 'the offered view leaves the unoffered token out');
    $accent = $tokens['color/accent'];
    $this->assertSame(['default' => '#1a1a2e', 'dark' => '#e8e8f0'], $accent->value);
    $this->assertSame('var(--color-accent)', $accent->spelling('style'));
    $this->assertSame('color/accent', $this->roster()->bySpelling('style', 'var(--color-accent)')?->id);
  }

  /**
   * A token capture lands on the first declared scale whose shape fits.
   *
   * The capture rule: a value with no `group` names its scale by shape in
   * declared order (a color on the color scale, a gradient on the
   * gradient scale, a length on the space scale), an explicit group wins,
   * and a value no declared scale admits refuses at group, naming the
   * scales. The element capture (the literal an element wears) rides the
   * same path through the batch door (MaterialCaptureTest).
   */
  public function testTokenCaptureInfersTheScale(): void {
    $this->installConfig(['daedalus_blueprint']);
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens(['space' => ['4' => '1rem']])->save();
    $this->activateSkin($skin);
    $provider = $this->container->get('daedalus_stylish.materials_provider');
    $tokens = $this->container->get('daedalus_stylish.skin_material_manager');

    $this->assertSame('color/brand-red', $provider->capture(MaterialKind::Token, ['label' => 'brand-red', 'value' => '#b91c1c'], NULL, [])->id);
    $this->assertSame('gradient/hero-fade', $provider->capture(MaterialKind::Token, ['label' => 'hero-fade', 'value' => 'linear-gradient(90deg, #b91c1c, #fbbf24)'], NULL, [])->id);
    $this->assertSame('space/8', $provider->capture(MaterialKind::Token, ['label' => '8', 'value' => '2rem'], NULL, [])->id);
    $this->assertSame('color/signal', $provider->capture(MaterialKind::Token, ['group' => 'color', 'label' => 'signal', 'value' => '#ff5500'], NULL, [])->id);
    $active = $tokens->activeSkin();
    $this->assertSame('#b91c1c', $tokens->resolve($active, 'color', 'brand-red')->record['value']);
    $this->assertSame('linear-gradient(90deg, #b91c1c, #fbbf24)', $tokens->resolve($active, 'gradient', 'hero-fade')->record['value']);
    $this->assertSame(['space/4', 'space/8', 'color/brand-red', 'color/signal', 'gradient/hero-fade'], array_keys($this->roster()->tokens()));
    $this->assertSame('var(--gradient-hero-fade)', $this->roster()->tokens()['gradient/hero-fade']->spelling('style'));

    // A compound fits none of the declared shapes (a length is one
    // component, a bare word is a named color, a gradient is a function):
    // refused at group, the declared scales in the context so the caller
    // can name one.
    try {
      $provider->capture(MaterialKind::Token, ['label' => 'nope', 'value' => '1px solid red'], NULL, []);
      $this->fail('Expected STYLE_TOKEN_SCALE_UNFIT.');
    }
    catch (MaterialGestureException $exception) {
      $error = $exception->getErrors()[0];
      $this->assertSame(['group', 'STYLE_TOKEN_SCALE_UNFIT'], [$error->getPath(), $error->getCode()]);
      $this->assertSame(['space', 'color', 'gradient'], $error->getContext()['scales']);
    }
    $this->assertFalse($tokens->has($active, 'color', 'nope'));
  }

  /**
   * Animations: the active skin's records in stored order, the motion namespace.
   */
  public function testAnimations(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageAnimations([
      'rise' => ['steps' => ['to' => ['opacity' => '1'], 'from' => ['opacity' => '0']], 'description' => 'Rise motion.'],
      'fade_in' => ['steps' => ['to' => ['opacity' => '1'], 'from' => ['opacity' => '0']], 'description' => 'Fade in motion.', 'offered' => FALSE],
    ]);
    $skin->save();
    $this->assertSame([], array_keys($this->roster()->animations()), 'An inactive skin\'s animations are not materials.');
    $this->activateSkin($skin);

    $animations = $this->roster()->animations();
    $this->assertSame(['rise', 'fade_in'], array_keys($animations), 'The skin\'s stored order, the compiled skin file\'s order.');
    $fade = $animations['fade_in'];
    $this->assertSame('fade_in', $fade->label, 'An animation has no label apart from its name.');
    $this->assertSame('Fade in motion.', $fade->description);
    $this->assertNull($fade->group);
    // The value is the canonical steps map (the record's shape the schema
    // projects as steps and the editor reads).
    $this->assertSame(['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']], $fade->value);
    // The spelling is the property-scoped animation slot, never var().
    $this->assertSame(['animation' => 'motion-fade_in'], $fade->spellings);
    $this->assertTrue($fade->published);
    $this->assertFalse($fade->offered);
    $this->assertTrue($animations['rise']->offered);
    $this->assertSame(MaterialRecord::ORIGIN_USER, $fade->origin, 'The site\'s own skin mints user materials.');
    $this->assertTrue($fade->updatable);
    $this->assertSame('fade_in', $this->roster()->bySpelling('animation', 'motion-fade_in')?->id);
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
      'daedalus_variant_list',
      'config:daedalus_stylish.settings',
      'config:system.theme',
      'daedalus_ui_page_builder',
    ];
    foreach ($domain as $tag) {
      $this->assertContains($tag, $tags);
    }
    $this->assertSame([], $before->variants());

    // A variant save mid-request: the next build sees it without an
    // explicit reset (the entity hooks reset the memo).
    $this->createVariant(['label' => 'Button', 'machine_name' => 'button', 'scope' => 'block_type:text']);
    $after = $builder->build();
    $this->assertNotSame($before, $after);
    $this->assertSame(['button'], array_keys($after->variants()));

    // Same for a skin activation and a token mint on the active skin.
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens(['space' => ['4' => '1rem']])->save();
    $this->assertSame([], $builder->build()->tokens(), 'A saved skin is not the active one yet.');
    $this->activateSkin($skin);
    $this->assertSame(['space/4'], array_keys($builder->build()->tokens()), 'Activating a skin resets the memo.');
    $this->assertSame(MaterialKind::Token, $builder->build()->get(MaterialKind::Token, 'space/4')->kind);
    $tokens = $this->container->get('daedalus_stylish.skin_material_manager');
    $tokens->mint($tokens->activeSkin(), 'color', 'lime-green', '#4dff8c');
    $this->assertSame(['space/4', 'color/lime-green'], array_keys($builder->build()->tokens()), 'A token mint resets the memo.');

    // And an animation mint on the skin (the suite ships no motion of its
    // own, so the skin's is the whole set).
    $tokens->mintAnimation($tokens->activeSkin(), 'drift', ['0%' => ['opacity' => '0']]);
    $this->assertSame(['drift'], array_keys($builder->build()->animations()), 'An animation mint resets the memo.');
  }

  /**
   * The updatable flag is the distro lock as the client reads it.
   *
   * A shipped variant record is not updatable while the lock holds (a
   * consuming site) and updatable once the developer gate lifts it
   * (`$settings['daedalus_blueprint_developer'] = TRUE`); a site-minted
   * material is updatable either way, and so is a shipped skin's token or
   * animation (a consuming site's write lands in the skin's site layer).
   * The client's locked() and the update gesture read this one flag.
   */
  public function testUpdatableFollowsTheDistroLock(): void {
    $mine = DaedalusStylishVariant::create([
      'label' => 'Mine',
      'machine_name' => 'container_mine',
      'scope' => 'kind:container',
      'styles' => ['' => ['default' => ['padding' => '2rem']]],
    ]);
    $mine->save();
    $shipped_variant = DaedalusStylishVariant::create([
      'label' => 'Airy',
      'machine_name' => 'container_airy',
      'scope' => 'kind:container',
      'styles' => ['' => ['default' => ['padding' => '1rem']]],
    ]);
    $shipped_variant->setShippedSource('daedalus_stylish', ['' => ['default' => ['padding' => '1rem']]]);
    $shipped_variant->save();
    $shipped_skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora']);
    $shipped_skin->setPackageAnimations(['fade_in' => ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']]]);
    $shipped_skin->setShippedSource('daedalus_stylish', ['tokens' => [], 'animations' => $shipped_skin->packageAnimations(), 'fonts' => [], 'defaults' => []]);
    $shipped_skin->save();
    $this->activateSkin($shipped_skin);

    $roster = $this->roster();
    $this->assertSame(MaterialRecord::ORIGIN_SHIPPED, $roster->variants()['container_airy']->origin);
    $this->assertFalse($roster->variants()['container_airy']->updatable);
    $this->assertTrue($roster->variants()['container_mine']->updatable);
    $this->assertSame(MaterialRecord::ORIGIN_SHIPPED, $roster->animations()['fade_in']->origin);
    $this->assertTrue($roster->animations()['fade_in']->updatable, 'A shipped animation takes a site record over it.');
    $this->assertFalse($roster->variants()['container_airy']->toArray()['updatable']);

    // The gate lifts the lock for every shipped kind at once; the records
    // keep their shipped origin (the fork and revert gestures still read
    // it).
    $this->setSetting('daedalus_blueprint_developer', TRUE);
    $roster = $this->roster();
    $this->assertSame(MaterialRecord::ORIGIN_SHIPPED, $roster->variants()['container_airy']->origin);
    $this->assertTrue($roster->variants()['container_airy']->updatable);
    $this->assertTrue($roster->variants()['container_mine']->updatable);
    $this->assertTrue($roster->animations()['fade_in']->updatable);
  }

  /**
   * The budget guards sharing: an offered mint, `offered` turning on.
   *
   * The guard refuses sharing, never saving: with the budget set tiny,
   * an offered mint of a token and an animation is refused with
   * the one AI_CONTEXT_FULL wording at path `offered` (the reading as
   * context, the dialog's path in the hint); the same mints withheld
   * from the AI go through; an update turning `offered` on where it was
   * off is refused (the token's stored record read like the entities'
   * flag), while a value or face edit, an update keeping the flag off,
   * and an update re-stating a flag already on (the cards re-send it)
   * pass; with room again the same update shares.
   */
  public function testBudgetGuardsSharing(): void {
    $this->installConfig(['daedalus_blueprint']);
    $provider = $this->container->get('daedalus_stylish.materials_provider');
    $budget = $this->container->get('daedalus_blueprint.materials_budget');
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setPackageTokens(['space' => ['4' => '1rem']])->save();
    $this->activateSkin($skin);
    $tokens = $this->container->get('daedalus_stylish.skin_material_manager');

    // Room: an offered mint goes through.
    $provider->capture(MaterialKind::Token, ['group' => 'space', 'label' => '2', 'value' => '0.5rem'], NULL, []);
    $this->assertTrue($tokens->resolve($tokens->activeSkin(), 'space', '2')->record['offered']);

    $this->config('daedalus_blueprint.settings')->set(MaterialsBudget::KEY_BUDGET, 1)->save();
    $reading = $budget->reading();
    $this->assertTrue($reading->over);

    // Full: an offered mint of any kind is refused, and nothing is minted.
    $fade = ['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']];
    $eight_op = ['group' => 'space', 'label' => '8', 'value' => '2rem'];
    $fade_op = ['label' => 'fade', 'steps' => $fade];
    $this->assertRefusedAsFull(fn () => $provider->capture(MaterialKind::Token, $eight_op, NULL, []), $reading);
    $this->assertRefusedAsFull(fn () => $provider->capture(MaterialKind::Animation, $fade_op, NULL, []), $reading);
    $this->assertFalse($tokens->has($tokens->activeSkin(), 'space', '8'));

    // A mint withheld from the AI shares nothing and passes.
    $provider->capture(MaterialKind::Token, $eight_op + ['offered' => FALSE], NULL, []);
    $this->assertFalse($tokens->resolve($tokens->activeSkin(), 'space', '8')->record['offered']);
    $provider->capture(MaterialKind::Animation, $fade_op + ['offered' => FALSE], NULL, []);

    // Full: turning offered on is refused at `offered`; everything else
    // updates, a flag already on included.
    $share = ['offered' => TRUE];
    $this->assertRefusedAsFull(fn () => $provider->update(MaterialKind::Token, 'space/8', $share + ['value' => '3rem'], NULL, []), $reading);
    $this->assertRefusedAsFull(fn () => $provider->update(MaterialKind::Animation, 'fade', $share, NULL, []), $reading);
    $this->assertSame('2rem', $tokens->resolve($tokens->activeSkin(), 'space', '8')->record['value'], 'A refused update writes nothing.');
    $provider->update(MaterialKind::Token, 'space/8', ['value' => '2.5rem'], NULL, []);
    $this->assertSame('2.5rem', $tokens->resolve($tokens->activeSkin(), 'space', '8')->record['value']);
    $provider->update(MaterialKind::Token, 'space/4', ['offered' => TRUE, 'description' => 'The unit.'], NULL, []);
    $this->assertSame('The unit.', $tokens->resolve($tokens->activeSkin(), 'space', '4')->record['description']);

    // Room again: the same updates share.
    $this->config('daedalus_blueprint.settings')->set(MaterialsBudget::KEY_BUDGET, MaterialsBudget::INSTALL_DEFAULTS[MaterialsBudget::KEY_BUDGET])->save();
    $this->assertFalse($budget->reading()->over);
    $provider->update(MaterialKind::Token, 'space/8', ['offered' => TRUE], NULL, []);
    $this->assertTrue($tokens->resolve($tokens->activeSkin(), 'space', '8')->record['offered']);
  }

}
