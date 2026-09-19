<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests how compose and markup shells reach the AI schema.
 *
 * A composed inline block (composed: true in the bundle's
 * PROVIDER.page_builder.block_type.BUNDLE.yml declaration) renders to a
 * single root, so no markup shells apply — not the block kind's, not its
 * fields'. Both facts are ANATOMY, so they reach the AI through the bundle
 * schema's anatomy fragment (daedalus_ui's daedalus_blueprint context
 * feed, whose fragment spells the fact as `composed`, the grammar's own
 * key), while daedalus_stylish's style_guide additions stay styling-only: what each
 * selector permits, never what the markup is. Compose declarations come
 * state-injected through daedalus_stylish_nested_test's
 * hook_page_builder_declarations_alter().
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ComposedBlockSchemaTest extends KernelTestBase {

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
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_nested_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The client payload serves the published swatches, so every build()
    // consumer needs the swatch entity schema.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * Layers state-injected declarations over the YAML ones for a dimension.
   *
   * The compile that installConfig(['daedalus_stylish']) fires in setUp() builds the
   * style guide, which warms the declarations memo and persistent cache
   * before any test injects its fixtures; state is invisible to both, so
   * the guide's cache is reset here for the injection to be read.
   */
  protected function declare(string $dimension, array $declarations): void {
    $this->container->get('state')->set("daedalus_stylish_nested_test.$dimension", $declarations);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
  }

  /**
   * Builds the style map field schema's style_guide additions.
   */
  protected function styleGuideSchema(): array {
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $definition = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('entity_test', 'entity_test')[DaedalusStylishFieldManager::FIELD_NAME];
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($definition);
    return $handler->getSchemaAdditions($definition)['style_guide'];
  }

  /**
   * Compose and shells ride the schema's anatomy fragment.
   *
   * The daedalus_ui feeds the element model's anatomy into
   * hook_daedalus_blueprint_context_alter(); the schema builder inlines it
   * into available_block_types / available_layouts. The AI has no
   * per-instance `_element` channel at schema time, so the fragment carries
   * the composed compose fact alongside the shells it voids.
   */
  public function testAnatomyFragmentCarriesCompose(): void {
    $this->declare('block_types', [
      // A composed bundle with root properties, no shells: the
      // daedalus_edit_text_block shape.
      'plain' => ['composed' => TRUE, 'property_groups' => ['spacing' => []]],
      // A composed bundle declaring nothing else at all still gets an
      // anatomy fragment: the flag IS the anatomy.
      'bare' => ['composed' => TRUE],
    ]);
    $context = $this->container->get('daedalus_blueprint.context_resolver')
      ->getContext('entity_test', 'entity_test');

    $this->assertTrue($context['block_types']['plain']['anatomy']['composed']);
    // A composed bundle with no block_content bundle config gets no
    // example (nothing to sample), so the flag IS the whole fragment.
    $this->assertSame(['composed' => TRUE], $context['block_types']['bare']['anatomy']);

    // A bundle whose declaration never composes carries its shells only.
    $card = $context['block_types']['card']['anatomy'];
    $this->assertArrayNotHasKey('composed', $card);
    $this->assertSame('fields', $card['elements']['.block__content']['contains']);
    $this->assertSame('Content', $card['elements']['.block__content']['label']);

    // Kind anatomy rides at the context root; layouts alongside the block
    // types. Styling vocabulary never leaks into the fragment.
    $this->assertSame('value', $context['anatomy']['kinds']['field']['elements']['.field__items']['elements']['.field__item']['contains']);
    $this->assertArrayHasKey('layout_onecol', $context['layouts']);
    $this->assertArrayNotHasKey('property_groups', $card['elements']['.block__content']);

    // The fragment derives from the layered declarations, so the context
    // and schema caches carry the spine's tag.
    $this->assertContains('daedalus_ui_page_builder', $context['cache_tags']);
  }

  /**
   * The style guide is styling-only: no collapse, no shells, flat selectors.
   */
  public function testStyleGuideIsStylingOnly(): void {
    $this->declare('block_types', [
      'plain' => ['composed' => TRUE, 'property_groups' => ['spacing' => [], 'typography' => []]],
      'bare' => ['composed' => TRUE],
      'chromed' => ['elements' => ['.chrome' => ['label' => 'Chrome', 'property_groups' => ['border' => []]]]],
    ]);
    // The stylable entries are the vocabulary roster's answers by
    // property name, in every posture; the compose fact never rides.
    $guide = $this->styleGuideSchema();
    $entries = $guide['stylable']['entries'];
    $this->assertStringContainsString('font-size', $entries['block_types.plain']);
    $this->assertStringNotContainsString('composed', json_encode($guide['stylable']));

    // A bundle whose effective declaration adds nothing to the universal
    // block kind (collapse is anatomy, not styling) says nothing the kind
    // entry does not: it is omitted rather than restated.
    $this->assertStringContainsString('padding', $entries['kinds.block']);
    $this->assertArrayNotHasKey('block_types.bare', $entries);

    // A bundle granting exactly its kind's roster at the root inherits it
    // silently: only its own part is an entry, at the map's key grammar.
    $this->assertArrayNotHasKey('block_types.chromed', $entries);
    $this->assertStringContainsString('border', $entries['block_types.chromed::.chrome']);

    // Sub-elements flatten to "{root}::.selector" entries at any authored
    // depth, as prose (no anatomy vocabulary, no constraint dump); the
    // header's font-size cap is the header's own value space, a
    // deviations line at its address (the fixture authors no bindings of
    // its own; the one binding line is daedalus_stylish's shipped font-family ->
    // font scale, the site lane every fixture layers over).
    $this->assertArrayHasKey('block_types.card::.card__kicker', $entries);
    $this->assertStringContainsString('font-size', $entries['block_types.card::.card__header']);
    $this->assertStringNotContainsString('"constraints"', json_encode($guide['stylable']));
    $this->assertStringNotContainsString('"label"', json_encode($guide['stylable']));
    $this->assertSame(['font-family' => 'font values'], $guide['bindings']);
    $this->assertSame('font-size at most 2rem', $guide['deviations']['block_types.card::.card__header']);

    // The closed world teaches the same entries under its own sentence.
    $this->enforce('strict');
    $closed = $this->styleGuideSchema()['stylable'];
    $this->assertSame($entries, $closed['entries']);
    $this->assertStringStartsWith('Each element styles ONLY the properties its kind permits', $closed['guidance']);
  }

  /**
   * The fragment carries the rich-text instance vocabulary, by data.
   *
   * With an `instances:` namespace declared on the spine the style_guide
   * fragment gains ONE lean rich_text paragraph: the namespace, the
   * addressable tag vocabulary (base's declared tags, the styleability
   * read the tag tier resolves through; the namespace's own `tags` map is
   * identity only), the three key spellings, the identity rules an AI
   * rewrite must keep, and the honesty line that every other tag is the
   * text format's business. Without a declaration the key is ABSENT, not
   * empty: the vocabulary grows by data, never by promise. Per-format
   * allowed-tag rosters deliberately do not serialize (payload cost).
   */
  public function testStyleGuideCarriesRichTextVocabulary(): void {
    $this->assertArrayNotHasKey('rich_text', $this->styleGuideSchema(), 'No declared namespace, no rich_text fragment.');

    $this->declare('instances', [
      '.s-*' => [
        'label' => 'Text element',
        'tags' => ['p' => 'Paragraph', 'h2' => 'Heading 2', 'ul' => 'Bulleted list'],
        'property_groups' => ['typography' => []],
      ],
    ]);

    $rich_text = $this->styleGuideSchema()['rich_text'];
    $this->assertStringContainsString('.s-* namespace', $rich_text);
    $this->assertStringContainsString('Addressable tags: h1, h2, h3, h4, h5, h6, p, a, ul, ol, li, blockquote, pre, code, img.', $rich_text);
    $this->assertStringContainsString('{key}::.s-x', $rich_text);
    $this->assertStringContainsString('{key}::tag', $rich_text);
    $this->assertStringContainsString('{key}::.s-x::>', $rich_text);
    $this->assertStringContainsString('keep them on elements you are keeping', $rich_text);
    $this->assertStringContainsString('author your own classes', $rich_text);
    $this->assertStringContainsString('strips at render', $rich_text);
  }

  /**
   * The client payload carries the same compose fact as the schema.
   *
   * Both are projections of the ONE effective declaration, so the panel's
   * consumers and the AI schema agree by construction.
   */
  public function testClientPayloadCarriesComposedFlag(): void {
    $this->declare('block_types', [
      'plain' => ['composed' => TRUE, 'property_groups' => ['spacing' => []]],
      'bare' => ['composed' => TRUE],
    ]);
    $payload = $this->container->get('daedalus_stylish.client_style_guide')->build();

    // The entries stay the served roster nodes (styling served as the
    // roster's grants), the flag riding as authored.
    $this->assertTrue($payload['blockTypes']['plain']['composed']);
    $this->assertArrayHasKey('spacing', $payload['blockTypes']['plain']['grants']);
    $this->assertTrue($payload['blockTypes']['bare']['composed']);
    $this->assertArrayNotHasKey('composed', $payload['blockTypes']['card']);
    $this->assertSame('fields', $payload['blockTypes']['card']['elements']['.block__content']['contains']);
  }

}
