<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish_discover\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Component\Serialization\Yaml;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\Drush\Commands\DiscoverCommand;
use Drupal\daedalus_stylish_discover\ElementDiscovery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests sub-element discovery, guide integration, and the drush scaffold.
 *
 * The test theme's field--block-content--field-quote.html.twig emits a
 * nested markup shell exercising every structural rule — nesting, classless
 * skip-through, shallowest-first duplicate collapse, same-element
 * multi-class, a field-wrapper chain that must become a contains slot —
 * plus a "js-" behavior hook and a charset violation that discovery must
 * filter. The tree it must discover:
 *
 *   .discover-card                (contains: field:field_author)
 *   ├── .discover-quote
 *   │   └── .discover-cite
 *   └── .discover-attribution
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ElementDiscoveryTest extends KernelTestBase {

  protected const THEME = 'daedalus_stylish_discover_test_theme';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'block',
    'block_content',
    'daedalus_blueprint',
    // Rendering entities with daedalus_stylish enabled consults daedalus_ui's
    // mode state (the live-attribution seams), which needs the full chain.
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'field_sample_value',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
    'daedalus_stylish_discover',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('theme_installer')->install([self::THEME]);

    FieldStorageConfig::create([
      'field_name' => 'field_quote',
      'entity_type' => 'block_content',
      'type' => 'string',
    ])->save();
    // 'testimonial' is the authored-curation control: daedalus_stylish_guide_test
    // ships a block-type YAML declaring its elements, so discovery must
    // leave it alone even though it renders the same discoverable classes.
    foreach (['discover_card', 'testimonial'] as $bundle) {
      BlockContentType::create(['id' => $bundle, 'label' => $bundle, 'revision' => TRUE])->save();
      FieldConfig::create([
        'field_name' => 'field_quote',
        'entity_type' => 'block_content',
        'bundle' => $bundle,
      ])->save();
      EntityViewDisplay::create([
        'targetEntityType' => 'block_content',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ])->setComponent('field_quote', ['type' => 'string'])->save();
    }
  }

  /**
   * Discovery retains the markup structure as a nested shell tree.
   *
   * One assertion per structural rule, all on the exact tree: nesting,
   * classless skip-through (.discover-cite's span sits inside an unclassed
   * div, yet lands under the quote), shallowest-first duplicate collapse
   * (.discover-attribution occurs at depth 4 inside the quote and at depth
   * 2 under the card; the part lands under the card), the field-wrapper
   * chain becoming a contains claim on the nearest classed ancestor with
   * none of the wrapper chain's own classes (field, field--name-*,
   * field__item) surfacing, and the filters ('js-' behavior hooks, charset
   * violations).
   */
  public function testDiscoveryTree(): void {
    $discovery = $this->container->get('daedalus_stylish_discover.element_discovery');
    $this->assertSame(
      [
        '.discover-card' => [
          'contains' => 'field:field_author',
          'elements' => [
            '.discover-quote' => [
              'elements' => [
                '.discover-cite' => [],
              ],
            ],
            '.discover-attribution' => [],
          ],
        ],
      ],
      $discovery->discover('discover_card', self::THEME),
    );
  }

  /**
   * The discovered tree merges into the guide; authored curation wins.
   *
   * The merged declaration also passes the guide's build validation
   * (uniqueness, contains grammar) by construction — a build error would
   * throw right here.
   */
  public function testStyleGuideIntegration(): void {
    $guide = $this->container->get('daedalus_stylish.style_guide');
    $declaration = $guide->getBlockType('discover_card', self::THEME);
    // The tree nests as the markup does, contains slots included.
    $card = $declaration['elements']['.discover-card'];
    $this->assertSame('field:field_author', $card['contains']);
    $this->assertSame(['.discover-quote', '.discover-attribution'], array_keys($card['elements']));
    // Unlabeled-but-functional: every node carries its class-name label and
    // every installed property group a real element may name
    // (unconstrained), at any depth.
    $groups = array_keys($this->container->get('plugin.manager.daedalus_stylish_property_group')->getElementGroups());
    $this->assertNotContains('generated_content', $groups);
    $quote = $card['elements']['.discover-quote'];
    $this->assertSame('discover-quote', $quote['label']);
    $this->assertSame($groups, array_keys($quote['property_groups']));
    $this->assertSame('discover-cite', $quote['elements']['.discover-cite']['label']);
    $this->assertSame($groups, array_keys($quote['elements']['.discover-cite']['property_groups']));
    // Nested parts resolve through the shared tree lookup, so flat style
    // keys reach them.
    $this->assertSame('discover-cite', PageBuilderDeclarations::findElement($declaration, '.discover-cite')['label']);

    // The testimonial type renders the same classes but authored an
    // elements declaration — discovery adds nothing to it.
    $testimonial = $guide->getBlockType('testimonial', self::THEME);
    $this->assertSame('Quote text', $testimonial['elements']['.testimonial__quote']['label']);
    $this->assertNull(PageBuilderDeclarations::findElement($testimonial, '.discover-quote'));
  }

  /**
   * A guide cached during a discovery render must not survive.
   *
   * Burned live: anything on the sample render path may consult the style
   * guide; that build runs the alter while the recursion guard is up and
   * caches a guide with no discovered elements. A fresh discovery resets
   * the guide cache so the poisoned build cannot stick.
   */
  public function testMidDiscoveryGuideBuildDoesNotStick(): void {
    $guard = new \ReflectionProperty(ElementDiscovery::class, 'discovering');
    $guard->setValue(NULL, TRUE);
    try {
      // With the guard up the alter skips: a partial guide gets cached.
      $poisoned = $this->container->get('daedalus_stylish.style_guide')->getBlockType('discover_card', self::THEME);
      $this->assertArrayNotHasKey('elements', $poisoned);
    }
    finally {
      $guard->setValue(NULL, FALSE);
    }
    // Any fresh discovery computation resets the guide, so the next read
    // rebuilds and merges (from the discovery cache, no re-render).
    $this->container->get('daedalus_stylish_discover.element_discovery')->discover('discover_card', self::THEME);
    $declaration = $this->container->get('daedalus_stylish.style_guide')->getBlockType('discover_card', self::THEME);
    $this->assertNotNull(PageBuilderDeclarations::findElement($declaration, '.discover-quote'));
  }

  /**
   * Results cache per (theme, bundle); display changes invalidate.
   */
  public function testCacheInvalidation(): void {
    $discovery = $this->container->get('daedalus_stylish_discover.element_discovery');
    $discovery->discover('discover_card', self::THEME);
    $cid = 'daedalus_stylish_discover:2:' . self::THEME . ':discover_card';
    $cache = $this->container->get('cache.default');
    $this->assertNotFalse($cache->get($cid));

    // Saving a block_content view display drops the discovery cache (and
    // resets the style guide, which bakes the merged result in).
    EntityViewDisplay::load('block_content.discover_card.default')->save();
    $this->assertFalse($cache->get($cid));
  }

  /**
   * The drush scaffold prints adoptable, labeled-TODO YAML, nested.
   */
  public function testDrushScaffold(): void {
    $command = DiscoverCommand::create($this->container);
    $tester = new CommandTester($command);
    $this->assertSame(Command::SUCCESS, $tester->execute([
      'block_type' => 'discover_card',
      '--theme' => self::THEME,
    ]));
    $display = $tester->getDisplay();
    $this->assertStringContainsString('MYMODULE.page_builder.block_type.discover_card.yml', $display);
    // The scaffold is real style guide YAML under its comments, nested as
    // the markup is, contains slots included.
    $parsed = Yaml::decode($display);
    // The scaffold offers the curated vocabulary only: the generated wide
    // groups are a validation tier, not something to author into YAML. The
    // pseudo-only groups stay out, since every line names a real element.
    $manager = $this->container->get('plugin.manager.daedalus_stylish_property_group');
    $groups = array_keys(array_intersect_key($manager->getCuratedGroups(), $manager->getElementGroups()));
    $this->assertNotContains('generated_content', $groups);
    $this->assertSame($groups, array_keys($parsed['property_groups']));
    $card = $parsed['elements']['.discover-card'];
    $this->assertSame('discover-card', $card['label']);
    $this->assertSame('field:field_author', $card['contains']);
    $this->assertSame(['.discover-quote', '.discover-attribution'], array_keys($card['elements']));
    $quote = $card['elements']['.discover-quote'];
    $this->assertSame('discover-quote', $quote['label']);
    $this->assertSame($groups, array_keys($quote['property_groups']));
    $this->assertSame('discover-cite', $quote['elements']['.discover-cite']['label']);
    $this->assertSame($groups, array_keys($quote['elements']['.discover-cite']['property_groups']));

    // Unknown bundles error politely, listing what exists.
    $this->assertSame(Command::FAILURE, $tester->execute(['block_type' => 'nope']));
    $this->assertStringContainsString("Unknown block type 'nope'", $tester->getDisplay());
    $this->assertStringContainsString('discover_card', $tester->getDisplay());
  }

}
