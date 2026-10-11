<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Dialect;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DomOperationHandler;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRoster;
use Drupal\daedalus_stylish_value_handler_test\TestFileStyleValueHandler;
use Drupal\daedalus_stylish_value_handler_test\TestGenerateStyleValueHandler;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The DOM style op and set's style half, through daedalus_stylish's registered ops.
 *
 * The `style` op's CSS parses under the <style>-block grammar (the same
 * DialectCssParser instance grammar the document parser uses) and lands
 * as update_style operations, so daedalus_stylish's one validation engine judges
 * every value; the explicit `remove` list is the deletion syntax (unset
 * is a real CSS value, not a delete). set's style attribute is
 * whole-list replacement of the element's own default-condition styles.
 *
 * The adversarial CSS surface (the reject list and the fluency spills:
 * descendant selectors into rich text, compound #id.class) is pinned
 * here against a real entity; the entity-free corpus lives in
 * DialectSpecTest.
 */
#[CoversClass(DomOperationHandler::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DomStyleOperationsTest extends BlueprintKernelTestBase {

  use DesignSystemActivationTrait;

  /**
   * The blueprint operations service (the op spine).
   */
  protected object $operations;

  /**
   * The host entity.
   */
  protected object $entity;

  /**
   * The root section's uuid (a container in the tree).
   */
  protected string $rootUuid;

  /**
   * The request options every DOM op batch rides.
   */
  protected array $options;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'navigation',
      'twig_events',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'file',
      'daedalus_ui',
      // Section identity (the uuid the tree addresses containers by) is
      // daedalus_layout's third-party setting; without it no container is
      // addressable.
      'daedalus_layout',
      'daedalus_stylish',
      'daedalus_stylish_guide_test',
      // Registers the `_generate` and `_file` marker stubs (daedalus_ai's
      // production handlers, minus the minting), so the dialect's generate
      // and file url translations are testable end to end.
      'daedalus_stylish_value_handler_test',
      // State-layered spine declarations: the rich-text tests declare the
      // '.s-*' instance namespace on the spine through its alter hook.
      'daedalus_stylish_nested_test',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->installEntitySchema('daedalus_variant');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->enableLayoutBuilder();

    $this->rootUuid = (new PhpUuid())->generate();
    $section = $this->createSection('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->rootUuid);
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Styled host',
      'layout_builder__layout' => [$section],
    ]);
    $this->entity->save();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->options = [
      'dialect_catalog' => $this->catalog(),
      'conversation' => 'conv-1',
    ];
  }

  /**
   * The test vocabulary: conditions matching daedalus_stylish's names, no tokens.
   */
  protected function catalog(): DialectCatalog {
    return new DialectCatalog(
      containerLayoutId: 'layout_onecol',
      // 0.6: the testimonial block type's emphasis axis (daedalus_stylish_guide_test
      // declares it), an attribute on the block's own tag.
      axes: ['block_type:testimonial' => ['emphasis' => []]],
      blockTypes: [],
      conditions: [
        'narrow' => '@container (max-width: 767px)',
        'dark' => '@media (prefers-color-scheme: dark)',
        'hover' => ':hover',
      ],
      // v0.3: the container's own style-axis variant (see mintBandVariant)
      // and 0.6: the block axis variant (see mintEmphaticVariant), as the
      // daedalus_stylish provider would serve them.
      roster: new MaterialsRoster([
        new MaterialRecord(kind: MaterialKind::Variant, id: 'band', label: 'Band', group: 'style', spellings: [MaterialRecord::SLOT_CLASS => 'band'], scope: Address::fromString('kind:container')),
        new MaterialRecord(kind: MaterialKind::Variant, id: 'testimonial_emphatic', label: 'Emphatic', group: 'emphasis', spellings: [MaterialRecord::attrSlot('block_type:testimonial', 'emphasis') => 'emphatic'], scope: Address::fromString('block_type:testimonial')),
      ], new CacheableMetadata()),
    );
  }

  /**
   * The catalog for the rich-text lane.
   *
   * A rich-body block type and the '.s-*' instance namespace with its
   * declared tags.
   */
  protected function instanceCatalog(): DialectCatalog {
    return new DialectCatalog(
      containerLayoutId: 'layout_onecol',
      axes: [],
      blockTypes: [
        // The dialect's rawtext capture is tag-scoped (pb-formatted-text
        // bodies are opaque-verbatim), so the rich-body fixture is the
        // formatted_text bundle.
        'formatted_text' => [
          'attributes' => [],
          'body' => ['field' => 'body', 'kind' => 'rich'],
        ],
      ],
      conditions: [
        'narrow' => '@container (max-width: 767px)',
        'dark' => '@media (prefers-color-scheme: dark)',
        'hover' => ':hover',
      ],
      instances: [
        '.s-*' => [
          'label' => 'Text element',
          'tags' => ['p' => 'Paragraph', 'h2' => 'Heading 2', 'ul' => 'Bulleted list', 'ol' => 'Numbered list'],
        ],
      ],
      // The tag tier's vocabulary on every owner is the styling layer's
      // base section (daedalus_stylish's shipped one), not the identity
      // tags above.
      selectors: array_fill_keys(['pb-container', 'pb-text', 'pb-formatted-text', 'pb-block'], ['parts' => [], 'tags' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'img']]),
    );
  }

  /**
   * Declares the '.s-*' namespace on the SPINE and builds the note type.
   *
   * The dialect maps selectors to storage keys; the update_style writes
   * they land in are judged by daedalus_stylish against the spine's declarations,
   * so the namespace must exist there too (one vocabulary truth, two
   * readers). Returns the request options for the rich-text lane.
   */
  protected function setUpRichTextLane(): array {
    $this->createBlockContentType('formatted_text', 'Formatted text');
    $this->createBlockField('body', 'text_with_summary', 'formatted_text');
    $this->container->get('state')->set('daedalus_stylish_nested_test.instances', [
      '.s-*' => [
        'label' => 'Text element',
        'tags' => ['p' => 'Paragraph', 'h2' => 'Heading 2', 'ul' => 'Bulleted list', 'ol' => 'Numbered list'],
        'property_groups' => ['typography' => [], 'spacing' => []],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    return ['dialect_catalog' => $this->instanceCatalog(), 'conversation' => 'conv-1'];
  }

  /**
   * V0.5 create-and-style: one batch writes a body and styles into it.
   *
   * The body write authors its own identities (the AI's create-and-style
   * gesture); the style op's existence check reads the STAGED post-op
   * content, so `#copy .s-lead` validates against a body the same batch
   * just wrote. All three v0.5 spellings land as storage keys: the
   * instance part, the element-scoped tag tier, and the part+children
   * composition addressing ONE list's items.
   */
  public function testCreateAndStyleRichTextInOneBatch(): void {
    $options = $this->setUpRichTextLane();
    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
        'html' => '<pb-formatted-text id="copy"><p class="s-lead">Lead copy</p><p>Plain copy</p><ul class="s-list"><li>One</li><li>Two</li></ul></pb-formatted-text>',
      ],
      [
        'op' => 'style',
        'css' => '#copy .s-lead { color: red; } #copy p { font-size: 1.5rem; } #copy .s-list > * { padding-top: 0.5rem; }',
      ],
    ], $options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $uuid = $result->getAliases()['copy'];
    $map = $this->styleMap();
    $this->assertSame(['color' => 'red'], $map["block:$uuid::.s-lead"]['default']);
    $this->assertSame(['font-size' => '1.5rem'], $map["block:$uuid::p"]['default']);
    $this->assertSame(['padding-top' => '0.5rem'], $map["block:$uuid::.s-list > *"]['default']);
  }

  /**
   * Child selectors land as the container's selector keys, one grammar.
   *
   * `#band > pb-formatted-text { color }` styles every formatted-text
   * block among the band's children through ONE entry on the container's
   * key, nesting flattens on the way in, and the document print reads the
   * flat rules back.
   */
  public function testStyleOpWritesChildSelectors(): void {
    $options = $this->setUpRichTextLane();
    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
        'html' => '<pb-formatted-text id="one"><p>One</p></pb-formatted-text><pb-formatted-text id="two"><p>Two</p></pb-formatted-text>',
      ],
      [
        'op' => 'style',
        'css' => "#{$this->rootUuid} { > pb-formatted-text { color: red; &:hover { color: blue; } } > * { margin: 0; } p { font-size: 1.5rem; } > pb-formatted-text + pb-formatted-text { margin-top: 1rem; } > pb-formatted-text:has(a):hover { color: green; } > *:not(pb-formatted-text) { margin-bottom: 0; } }",
      ],
    ], $options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $map = $this->styleMap();
    $this->assertSame(['color' => 'red'], $map["container:{$this->rootUuid}::> pb-formatted-text"]['default']);
    $this->assertSame(['color' => 'blue'], $map["container:{$this->rootUuid}::> pb-formatted-text"]['hover']);
    $this->assertSame(['margin' => '0'], $map["container:{$this->rootUuid}::> *"]['default']);
    $this->assertSame(['font-size' => '1.5rem'], $map["container:{$this->rootUuid}::p"]['default']);
    // The relations land the same way: the qualifiers in the key, the
    // state as the condition.
    $this->assertSame(['margin-top' => '1rem'], $map["container:{$this->rootUuid}::> pb-formatted-text + pb-formatted-text"]['default']);
    $this->assertSame(['color' => 'green'], $map["container:{$this->rootUuid}::> pb-formatted-text:has(a)"]['hover']);
    $this->assertSame(['margin-bottom' => '0'], $map["container:{$this->rootUuid}::> *:not(pb-formatted-text)"]['default']);
    // The print reads the flat rules back from the staged host itself
    // (the corpus round-trips in DialectSpecTest lock the whole document):
    // the host's section is core's layout_onecol, which prints as a
    // section with a layout fact rather than refusing the page.
    $print = $this->container->get('daedalus_blueprint.dialect_printer')->print(
      $this->container->get('daedalus_blueprint.node_tree_builder')->build($this->host($this->entity)),
      $options['dialect_catalog'],
    )->text;
    $this->assertStringContainsString("<pb-container id=\"{$this->rootUuid}\" data-layout=\"layout_onecol\"", $print);
    $this->assertStringContainsString("#{$this->rootUuid} > pb-formatted-text { color: red; }", $print);
    $this->assertStringContainsString("#{$this->rootUuid} > pb-formatted-text:hover { color: blue; }", $print);
    $this->assertStringContainsString("#{$this->rootUuid} > * { margin: 0; }", $print);
    $this->assertStringContainsString("#{$this->rootUuid} p { font-size: 1.5rem; }", $print);
    $this->assertStringContainsString("#{$this->rootUuid} > pb-formatted-text + pb-formatted-text { margin-top: 1rem; }", $print);
    $this->assertStringContainsString("#{$this->rootUuid} > pb-formatted-text:has(a):hover { color: green; }", $print);
    $this->assertStringContainsString("#{$this->rootUuid} > *:not(pb-formatted-text) { margin-bottom: 0; }", $print);
  }

  /**
   * V0.5: the existence miss names the element's REAL instances.
   *
   * Styling an identity the staged body does not carry fails the batch
   * with a corrective enumerating the body's actual instances with
   * excerpts, so the writer's repair round has the real vocabulary.
   */
  public function testInstanceExistenceCheckedAgainstStagedContent(): void {
    $options = $this->setUpRichTextLane();
    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
        'html' => '<pb-formatted-text id="copy"><p class="s-lead">Lead copy</p></pb-formatted-text>',
      ],
      [
        'op' => 'style',
        'css' => '#copy .s-ghost { color: red; }',
      ],
    ], $options);

    $this->assertFalse($result->isSuccess());
    $codes = array_map(static fn ($error) => $error->getCode(), $result->getErrors());
    $this->assertContains('UNKNOWN_INSTANCE', $codes, 'got ' . implode(', ', $codes));
    $messages = implode(' ', array_map(static fn ($error) => $error->getMessage(), $result->getErrors()));
    $this->assertStringContainsString('.s-lead <p> "Lead copy"', $messages);
    $this->assertSame([], $this->styleMap(), 'Rollback stayed total.');
  }

  /**
   * V0.5: a within-batch identity collision is refused loudly.
   *
   * One authored class on two mintable elements would be silently
   * re-minted at stage time, orphaning the batch's own styles; both the
   * fragment door (insert) and the patch door (set body) refuse it with
   * IDENTITY_COLLISION. Minting is universal, so the law reaches inside
   * list items too.
   */
  public function testAuthoredIdentityCollisionIsRefused(): void {
    $options = $this->setUpRichTextLane();

    $insert = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
        'html' => '<pb-formatted-text id="copy"><p class="s-dup">A</p><p class="s-dup">B</p></pb-formatted-text>',
      ],
    ], $options);
    $this->assertFalse($insert->isSuccess());
    $this->assertContains('IDENTITY_COLLISION', array_map(static fn ($error) => $error->getCode(), $insert->getErrors()));

    $seeded = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
        'html' => '<pb-formatted-text id="copy"><p class="s-lead">Lead</p></pb-formatted-text>',
      ],
    ], $options);
    $this->assertTrue($seeded->isSuccess(), print_r($seeded->toArray()['errors'] ?? [], TRUE));

    $set = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => 'copy', 'attrs' => ['body' => '<p class="s-dup">A</p><p class="s-dup">B</p>']],
    ], $options);
    $this->assertFalse($set->isSuccess());
    $this->assertContains('IDENTITY_COLLISION', array_map(static fn ($error) => $error->getCode(), $set->getErrors()));

    // Minting is universal, so the same law holds inside list items: a
    // duplicated class on two item paragraphs is the collision the minter
    // would silently re-mint, and the dialect refuses it loudly.
    $inside = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'set',
        'ref' => 'copy',
        'attrs' => ['body' => '<ul class="s-list"><li><p class="s-item">One</p></li><li><p class="s-item">Two</p></li></ul>'],
      ],
    ], $options);
    $this->assertFalse($inside->isSuccess());
    $this->assertContains('IDENTITY_COLLISION', array_map(static fn ($error) => $error->getCode(), $inside->getErrors()));

    // Unique identities inside list items pass.
    $legal = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'set',
        'ref' => 'copy',
        'attrs' => ['body' => '<ul class="s-list"><li><p class="s-one">One</p></li><li><p class="s-two">Two</p></li></ul>'],
      ],
    ], $options);
    $this->assertTrue($legal->isSuccess(), print_r($legal->toArray()['errors'] ?? [], TRUE));
  }

  /**
   * Mints the published kind:container style-axis variant the catalog names.
   */
  protected function mintBandVariant(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Band',
      'machine_name' => 'band',
      'scope' => 'kind:container',
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $variant->setStyles(['' => ['default' => ['padding-top' => '4rem', 'gap' => '2rem']]]);
    $variant->save();
  }

  /**
   * V0.3: a container wears a style variant as class and detaches it.
   *
   * Class="" is the element's whole style-axis wear (a class diff), and
   * set's detach materializes the worn variant's styles into the
   * element's own defaults and drops the reference (the spine's
   * detach_variant), so the look stays but stops following the variant.
   */
  public function testContainerWearsClassVariantAndDetaches(): void {
    $this->mintBandVariant();

    $worn = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $this->rootUuid, 'attrs' => ['class' => 'band', 'style' => 'gap: 1rem']],
    ], $this->options);
    $this->assertTrue($worn->isSuccess(), print_r($worn->toArray()['errors'] ?? [], TRUE));
    $entry = $this->styleMap()["container:{$this->rootUuid}"];
    $this->assertSame([['variant' => 'band', 'fragment' => '']], $entry['variants']);
    $this->assertSame(['gap' => '1rem'], $entry['default']);

    // The wear prints back on the host's own section (a layout_onecol
    // section prints with its layout fact).
    $print = $this->container->get('daedalus_blueprint.dialect_printer')->print(
      $this->container->get('daedalus_blueprint.node_tree_builder')->build($this->host($this->entity)),
      $this->options['dialect_catalog'],
    )->text;
    $this->assertStringContainsString("<pb-container id=\"{$this->rootUuid}\" data-layout=\"layout_onecol\" class=\"band\" style=\"gap: 1rem\">", $print);
    $detached = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $this->rootUuid, 'detach' => ['band']],
    ], $this->options);
    $this->assertTrue($detached->isSuccess(), print_r($detached->toArray()['errors'] ?? [], TRUE));
    $entry = $this->styleMap()["container:{$this->rootUuid}"];
    $this->assertArrayNotHasKey('variants', $entry);
    // Materialized: the variant's values land where the element had none;
    // the element's own gap already beat the variant and stays.
    $this->assertSame('4rem', $entry['default']['padding-top']);
    $this->assertSame('1rem', $entry['default']['gap']);

    // An unpublished spelling is refused with the roster: the structured
    // corrective the document parser gives (did-you-mean in the hint, the
    // element's declared roster and the ranked candidates in context).
    $unknown = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $this->rootUuid, 'attrs' => ['class' => 'hero-band']],
    ], $this->options);
    $this->assertFalse($unknown->isSuccess());
    $error = $unknown->getErrors()[0];
    $this->assertSame('UNKNOWN_CLASS', $error->getCode());
    $this->assertSame('attrs.class', $error->getPath());
    $this->assertSame(['band'], $error->getContext()['declared']);
    $this->assertSame('band', $error->getContext()['candidates'][0]['id']);
    $this->assertStringContainsString('Did you mean this variant: band "Band" (class="band")', $error->getHint());
  }

  /**
   * Saves the testimonial type and its published emphasis-axis variant.
   */
  protected function mintEmphaticVariant(): void {
    $this->createBlockContentType('testimonial', 'Testimonial');
    $variant = DaedalusStylishVariant::create([
      'label' => 'Emphatic',
      'machine_name' => 'testimonial_emphatic',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
      'status' => TRUE,
    ]);
    $variant->setStyles(['' => ['default' => ['font-size' => '1.5rem', 'color' => 'red']]]);
    $variant->save();
  }

  /**
   * Spec 0.6: a block wears its type's axis variant as an attribute.
   *
   * The container rule on the block's own tag: emphasis="emphatic" on the
   * inserted <pb-block type="testimonial"> lands as the variant reference
   * (the spine's apply_variant, daedalus_stylish judging the wear), a value the
   * axis does not offer is refused with the axis's allowed values on the
   * block's tag, null on the axis takes the variant off, and detach by the
   * axis name materializes the variant's styles into the block's own.
   */
  public function testBlockWearsAxisVariantAndDetaches(): void {
    $this->mintEmphaticVariant();

    $inserted = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'html' => '<pb-block id="quote" type="testimonial" emphasis="emphatic" />',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);
    $this->assertTrue($inserted->isSuccess(), print_r($inserted->toArray()['errors'] ?? [], TRUE));
    $uuid = $inserted->getAliases()['quote'];
    $this->assertSame([['variant' => 'testimonial_emphatic', 'fragment' => '']], $this->styleMap()["block:$uuid"]['variants']);

    $bad = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $uuid, 'attrs' => ['emphasis' => 'loud']],
    ], $this->options);
    $this->assertFalse($bad->isSuccess());
    $error = $bad->getErrors()[0];
    $this->assertSame('BAD_ATTR_VALUE', $error->getCode());
    $this->assertSame('attrs.emphasis', $error->getPath());
    $this->assertStringContainsString('emphasis="loud" on <pb-block>', $error->getMessage());
    $this->assertSame(['emphatic'], $error->getContext()['allowed']);

    $detached = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $uuid, 'detach' => ['emphasis'], 'attrs' => ['style' => 'color: blue']],
    ], $this->options);
    $this->assertTrue($detached->isSuccess(), print_r($detached->toArray()['errors'] ?? [], TRUE));
    $entry = $this->styleMap()["block:$uuid"];
    $this->assertArrayNotHasKey('variants', $entry);
    $this->assertSame('1.5rem', $entry['default']['font-size']);
    $this->assertSame('blue', $entry['default']['color']);

    // The axis patch wears it again; null on the axis takes it off.
    $worn = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $uuid, 'attrs' => ['emphasis' => 'emphatic']],
    ], $this->options);
    $this->assertTrue($worn->isSuccess(), print_r($worn->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([['variant' => 'testimonial_emphatic', 'fragment' => '']], $this->styleMap()["block:$uuid"]['variants']);
    $cleared = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $uuid, 'attrs' => ['emphasis' => NULL]],
    ], $this->options);
    $this->assertTrue($cleared->isSuccess(), print_r($cleared->toArray()['errors'] ?? [], TRUE));
    $this->assertArrayNotHasKey('variants', $this->styleMap()["block:$uuid"]);

    // A detach naming no worn axis or class says which axes the tag has.
    $nothing = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $uuid, 'detach' => ['emphasis']],
    ], $this->options);
    $this->assertFalse($nothing->isSuccess());
    $this->assertSame('BAD_OP', $nothing->getErrors()[0]->getCode());
    $this->assertStringContainsString('wears no emphasis variant', $nothing->getErrors()[0]->getMessage());
  }

  /**
   * Style: a rule wears a variant as a declaration; remove takes it off.
   *
   * `class: band` inside `#root > pb-container` lands as apply_variant at
   * the rule key beside the rule's own gap (daedalus_stylish judging the
   * wear by the rule's landing), the print reads the rule back with its
   * declaration, a wear on the element itself is refused (its attributes
   * and the set op spell that), a remove entry naming "class" takes every
   * class the rule wears off (remove_variant) and leaves its properties,
   * and a remove naming a wear the rule does not carry says so.
   */
  public function testStyleOpWearsAtARule(): void {
    $this->mintBandVariant();
    $rule = "container:{$this->rootUuid}::> pb-container";

    $worn = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'style', 'css' => "#{$this->rootUuid} > pb-container { class: band; gap: 1rem; }"],
    ], $this->options);
    $this->assertTrue($worn->isSuccess(), print_r($worn->toArray()['errors'] ?? [], TRUE));
    $entry = $this->styleMap()[$rule];
    $this->assertSame([['variant' => 'band', 'fragment' => '']], $entry['variants']);
    $this->assertSame(['gap' => '1rem'], $entry['default']);
    $this->assertArrayNotHasKey('variants', $this->styleMap()["container:{$this->rootUuid}"] ?? [], 'The wear is the rule\'s, never the owner\'s.');

    $print = $this->container->get('daedalus_blueprint.dialect_printer')->print([
      [
        'kind' => 'container',
        'uuid' => $this->rootUuid,
        'layout_id' => 'daedalus_stylish_container',
        'children' => [],
        'styles' => ['::> pb-container' => $entry],
      ],
    ], $this->options['dialect_catalog'])->text;
    $this->assertStringContainsString("#{$this->rootUuid} > pb-container { class: band; gap: 1rem; }", $print);

    $element = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'style', 'css' => "#{$this->rootUuid} { class: band; }"],
    ], $this->options);
    $this->assertFalse($element->isSuccess());
    $this->assertSame('WEAR_ON_ELEMENT', $element->getErrors()[0]->getCode());
    $this->assertSame('css', $element->getErrors()[0]->getPath());

    $off = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'style', 'remove' => [['selector' => "#{$this->rootUuid} > pb-container", 'properties' => ['class']]]],
    ], $this->options);
    $this->assertTrue($off->isSuccess(), print_r($off->toArray()['errors'] ?? [], TRUE));
    $entry = $this->styleMap()[$rule];
    $this->assertArrayNotHasKey('variants', $entry);
    $this->assertSame(['gap' => '1rem'], $entry['default']);

    $nothing = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'style', 'remove' => [['selector' => "#{$this->rootUuid} > pb-container", 'properties' => ['class']]]],
    ], $this->options);
    $this->assertFalse($nothing->isSuccess());
    $this->assertSame('BAD_OP', $nothing->getErrors()[0]->getCode());
    $this->assertSame('remove.0', $nothing->getErrors()[0]->getPath());
    $this->assertStringContainsString('wears no class variant', $nothing->getErrors()[0]->getMessage());
  }

  /**
   * Reads the entity's style map back.
   */
  protected function styleMap(): array {
    $item = $this->entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item ? $item->getStyleMap() : [];
  }

  /**
   * Style: real CSS lands as update_style writes, conditions by wrapper.
   */
  public function testStyleOpWritesConditionsFromWrappers(): void {
    $css = <<<CSS
#{$this->rootUuid} { padding: 2rem; }
@container (max-width: 767px) { #{$this->rootUuid} { padding: 1rem; } }
#{$this->rootUuid}:hover { margin: 1rem; }
CSS;
    $result = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'style', 'css' => $css],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $entry = $this->styleMap()["container:{$this->rootUuid}"];
    $this->assertSame(['padding' => '2rem'], $entry['default']);
    $this->assertSame(['padding' => '1rem'], $entry['narrow']);
    $this->assertSame(['margin' => '1rem'], $entry['hover']);
  }

  /**
   * A gradient over a generation request lands in ONE style op.
   *
   * The CSS value splits into the spine's layer list (the gradient as a
   * literal fragment, the generate url as the {_generate} handled value),
   * StyleValueResolver validates the stand-in and commits the resolved
   * layers joined with commas, so the stored value is final CSS holding
   * the minted media token. The stub handler stands in for daedalus_ai's
   * minter with a fixed uuid.
   */
  public function testStyleOpComposesGradientOverGenerate(): void {
    $gradient = 'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5))';
    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'style',
        'css' => "#{$this->rootUuid} { background-image: $gradient, url('generate:sunrise over the bay'); }",
      ],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(
      sprintf("%s, url('media:%s')", $gradient, TestGenerateStyleValueHandler::UUID),
      $this->styleMap()["container:{$this->rootUuid}"]['default']['background-image'],
      'The stored value is one comma-joined CSS value: the literal gradient over the resolved media token.',
    );
  }

  /**
   * A file handle in a style op lands as the {_file} handled value.
   *
   * The file url translates exactly as the generate url: alone it is the
   * bare handled value, over a gradient it is one layer,
   * and the resolver commits the stub's token in its place; the batch's
   * conversation reaches the handler through the seam's context (the
   * production handler resolves the name for that conversation).
   */
  public function testStyleOpPlacesFileHandle(): void {
    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'style',
        'css' => "#{$this->rootUuid} { background-image: url('file:hero.svg'); }",
      ],
    ], ['conversation' => 'thread-style'] + $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(
      sprintf("url('media:%s')", TestFileStyleValueHandler::UUID),
      $this->styleMap()["container:{$this->rootUuid}"]['default']['background-image'],
    );
    $this->assertSame('thread-style', TestFileStyleValueHandler::$conversation, 'The batch conversation reaches the style value seam.');

    $gradient = 'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5))';
    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'style',
        'css' => "#{$this->rootUuid} { background-image: $gradient, url('file:hero.svg'); }",
      ],
    ], array_diff_key($this->options, ['conversation' => '']));
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(
      sprintf("%s, url('media:%s')", $gradient, TestFileStyleValueHandler::UUID),
      $this->styleMap()["container:{$this->rootUuid}"]['default']['background-image'],
    );
    $this->assertSame('', TestFileStyleValueHandler::$conversation, 'A batch without a conversation hands the seam the empty one.');
  }

  /**
   * Style: the remove list is the deletion syntax, per selector states.
   */
  public function testStyleRemoveListDeletesProperties(): void {
    $seed = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'style',
        'css' => "#{$this->rootUuid} { padding: 2rem; margin: 1rem; }\n#{$this->rootUuid}:hover { gap: 1rem; }",
      ],
    ], $this->options);
    $this->assertTrue($seed->isSuccess(), print_r($seed->toArray()['errors'] ?? [], TRUE));

    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'style',
        'remove' => [
          ['selector' => "#{$this->rootUuid}", 'properties' => ['padding']],
          ['selector' => "#{$this->rootUuid}:hover", 'properties' => ['gap']],
        ],
      ],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $entry = $this->styleMap()["container:{$this->rootUuid}"];
    $this->assertSame(['margin' => '1rem'], $entry['default']);
    $this->assertArrayNotHasKey('hover', $entry);
  }

  /**
   * Style: aliases address elements in css exactly like uuids.
   */
  public function testStyleOpResolvesAliases(): void {
    $this->container->get('daedalus_blueprint.dialect_alias_map')->record($this->host($this->entity), 'conv-1', 'hero', $this->rootUuid);
    $this->container->get('daedalus_blueprint.dialect_alias_map')->persist();

    $result = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'style', 'css' => '#hero { padding: 3rem; }'],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('3rem', $this->styleMap()["container:{$this->rootUuid}"]['default']['padding']);
  }

  /**
   * The adversarial CSS surface rejects with correctives, changing nothing.
   */
  public function testStyleOpRejectsAdversarialCss(): void {
    $cases = [
      '!important' => ["#{$this->rootUuid} { padding: 2rem !important; }", 'IMPORTANT'],
      'custom property definition' => ["#{$this->rootUuid} { --brand: red; }", 'CUSTOM_PROP_DEF'],
      'url()' => ["#{$this->rootUuid} { background: url(/x.png); }", 'URL_FUNCTION'],
      '@media width misuse' => [
        "@media (max-width: 767px) { #{$this->rootUuid} { padding: 1rem; } }",
        'UNKNOWN_WRAPPER',
      ],
      'clamp() value' => ["#{$this->rootUuid} { font-size: clamp(1rem, 2vw, 3rem); }", 'UNSUPPORTED_VALUE'],
      'unknown id' => ['#ghost { padding: 1rem; }', 'UNKNOWN_ID_SELECTOR'],
      'fluency spill: descendant into rich text' => ["#{$this->rootUuid} h2 { font-size: 2rem; }", 'BAD_SELECTOR'],
      'fluency spill: compound #id.class' => ["#{$this->rootUuid}.button { padding: 1rem; }", 'BAD_SELECTOR'],
      ':root' => [':root { --x: 1; }', 'BAD_SELECTOR'],
      'selector list' => ["#{$this->rootUuid}, #other { padding: 1rem; }", 'SELECTOR_LIST'],
    ];
    foreach ($cases as $label => [$css, $code]) {
      $result = $this->operations->batchOperations($this->host($this->entity), [
        ['op' => 'style', 'css' => $css],
      ], $this->options);
      $this->assertFalse($result->isSuccess(), $label);
      $codes = array_map(static fn ($error) => $error->getCode(), $result->getErrors());
      $this->assertContains($code, $codes, $label . ': got ' . implode(', ', $codes));
    }
    // The fluency-spill correctives name the dialect gesture.
    $descendant = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'style', 'css' => "#{$this->rootUuid} h2 { font-size: 2rem; }"],
    ], $this->options);
    $this->assertStringContainsString('descendant elements are not addressable', $descendant->getErrors()[0]->getMessage());
    $compound = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'style', 'css' => "#{$this->rootUuid}.button { padding: 1rem; }"],
    ], $this->options);
    $this->assertStringContainsString('class=""', $compound->getErrors()[0]->getMessage());

    $this->assertSame([], $this->styleMap());
  }

  /**
   * Set style="": whole-list replacement of the own default styles.
   */
  public function testSetStyleReplacesOwnDefaults(): void {
    $first = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $this->rootUuid, 'attrs' => ['style' => 'padding: 2rem; margin: 1rem']],
    ], $this->options);
    $this->assertTrue($first->isSuccess(), print_r($first->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(['padding' => '2rem', 'margin' => '1rem'], $this->styleMap()["container:{$this->rootUuid}"]['default']);

    // Writing the attribute again replaces the list: padding is gone, not
    // merged (the HTML semantics of style="").
    $second = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $this->rootUuid, 'attrs' => ['style' => 'margin: 3rem']],
    ], $this->options);
    $this->assertTrue($second->isSuccess(), print_r($second->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(['margin' => '3rem'], $this->styleMap()["container:{$this->rootUuid}"]['default']);

    // Null clears the element's own default styles entirely.
    $cleared = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $this->rootUuid, 'attrs' => ['style' => NULL]],
    ], $this->options);
    $this->assertTrue($cleared->isSuccess(), print_r($cleared->toArray()['errors'] ?? [], TRUE));
    $this->assertArrayNotHasKey("container:{$this->rootUuid}", $this->styleMap());
  }

  /**
   * A failed insert's own errors survive a downstream failure.
   *
   * Op 0 inserts a container whose style="" carries an undeclared property
   * (semantic STYLE_PROPERTY_NOT_DECLARED from the nested batch), and op 1
   * styles the alias the rolled-back insert never minted (structural
   * UNKNOWN_ID_SELECTOR). A phase-priority rule withholding the semantic
   * error would let only the downstream shadow reach the writer and point
   * the repair at the wrong op. Both must ride the one round, each on its
   * own operation index.
   */
  public function testFailedInsertErrorsSurviveDownstreamFailure(): void {
    // The closed world (undeclared properties rejected) is strict mode;
    // guarded, the default, only makes authored constraints law.
    $this->enforce('strict');

    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'at' => ['ref' => 'root', 'position' => 'beforeend'],
        'html' => '<pb-container id="sec" style="text-decoration: none"></pb-container>',
      ],
      [
        'op' => 'style',
        'css' => '#sec:hover { padding: 1rem; }',
      ],
    ], $this->options);

    $this->assertFalse($result->isSuccess());
    $codes = [];
    $elements = [];
    foreach ($result->getErrors() as $error) {
      $codes[$error->getCode()] = $error->getOperationIndex();
      $elements[$error->getCode()] = $error->getElement();
    }
    $this->assertArrayHasKey('STYLE_PROPERTY_NOT_DECLARED', $codes, 'got ' . implode(', ', array_keys($codes)));
    $this->assertSame(0, $codes['STYLE_PROPERTY_NOT_DECLARED']);
    $this->assertArrayHasKey('UNKNOWN_ID_SELECTOR', $codes, 'got ' . implode(', ', array_keys($codes)));
    $this->assertSame(1, $codes['UNKNOWN_ID_SELECTOR']);
    // The translated op's error names the AUTHOR's alias (the nested
    // batch's own numbering never reaches them) while the enclosing batch
    // of their ops still carries their op index.
    $this->assertSame('sec', $elements['STYLE_PROPERTY_NOT_DECLARED']);
    $this->assertNull($elements['UNKNOWN_ID_SELECTOR'], 'A structural error on the author\'s own op is not element-addressed.');
    // The mixed batch is labeled by its earliest failing phase; the label
    // filters nothing.
    $this->assertSame('structural', $result->getPhase());
    // Rollback stayed total.
    $this->assertSame([], $this->styleMap());
  }

  /**
   * Document errors are addressed to the author's elements, never op indexes.
   *
   * Reporting STYLE_PROPERTY_NOT_DECLARED at operation_index 70/74/78,
   * the translated batch's numbering of a six-section document, names
   * nothing the author wrote: they wrote no op 70, they wrote
   * <pb-container id="stat-1" style="padding: ...">. A document error must
   * name that element (the alias when they chose one, the uuid otherwise,
   * the part suffix on a part) and restate the path in the style=""
   * grammar, and carry no operation index at all: there is no enclosing
   * batch of the author's ops on the create path.
   */
  public function testDocumentErrorsAddressElements(): void {
    $this->enforce('strict');

    $result = $this->container->get('daedalus_blueprint.dom_operation_handler')->applyDocument($this->host($this->entity), <<<'HTML'
      <pb-container id="band" style="margin: 1rem">
        <pb-container id="card" style="text-decoration: none"></pb-container>
        <pb-container style="transition: opacity 1s"></pb-container>
      </pb-container>
      <style>
        #band:hover { transition: opacity 1s; }
      </style>
      HTML, $this->options);

    $this->assertFalse($result->isSuccess());
    $by_element = [];
    foreach ($result->getErrors() as $error) {
      $this->assertSame('STYLE_PROPERTY_NOT_DECLARED', $error->getCode());
      $this->assertArrayNotHasKey('operation_index', $error->toArray(), 'A document error carries no translated-op numbering.');
      $this->assertArrayHasKey('element', $error->toArray());
      $by_element[$error->getElement()] = $error->getPath();
    }
    $this->assertSame('style.text-decoration', $by_element['card'] ?? NULL, print_r($by_element, TRUE));
    $this->assertSame('hover.transition', $by_element['band'] ?? NULL, print_r($by_element, TRUE));
    unset($by_element['card'], $by_element['band']);
    // The unnamed sibling is addressed by the uuid the print would show.
    $this->assertCount(1, $by_element);
    $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', array_key_first($by_element));
    $this->assertSame('style.transition', reset($by_element));
    $this->assertSame([], $this->styleMap(), 'Rollback stayed total.');
  }

  /**
   * The style op needs css or remove; empty gestures are refused.
   */
  public function testStyleOpNeedsPayload(): void {
    $result = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'style'],
    ], $this->options);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('MISSING_PARAMETER', $result->getErrors()[0]->getCode());
  }

  /**
   * The stylable roster is spelled once per batch; later misses reference it.
   *
   * STYLE_PROPERTY_NOT_DECLARED's corrective names the element's whole
   * permitted roster (~80 properties live); every miss in a batch
   * repeating it would cost ~4k corrective tokens for one fact. Two
   * undeclared properties on ONE element must yield the roster exactly
   * once: the first error carries declared_properties in context, the
   * second a reference to the first with the duplicated payload dropped.
   * The shared-hint marker is aggregator bookkeeping and never
   * serializes. The roster rides the context only; each hint is the
   * miss's own did-you-mean.
   */
  public function testStylableRosterSpelledOncePerBatch(): void {
    // The closed world (undeclared properties rejected) is strict mode;
    // guarded, the default, only makes authored constraints law.
    $this->enforce('strict');

    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'at' => ['ref' => 'root', 'position' => 'beforeend'],
        'html' => '<pb-container id="sec" style="text-decoration: none; transition: opacity 1s"></pb-container>',
      ],
    ], $this->options);

    $this->assertFalse($result->isSuccess());
    $not_declared = array_values(array_filter(
      $result->getErrors(),
      static fn ($error) => $error->getCode() === 'STYLE_PROPERTY_NOT_DECLARED',
    ));
    $this->assertCount(2, $not_declared, 'Both undeclared properties error.');

    [$first, $second] = $not_declared;
    // The hint is the catalog's one did-you-mean formatter (the roster
    // lives in context, and in the catalog).
    $granted = new VocabularyRoster(Address::kind('block'), 'spec', ['granted' => $first->getContext()['declared_properties']]);
    $this->assertSame($granted->corrective('text-decoration'), $first->getHint(), 'The first miss carries its own corrective.');
    $this->assertNotEmpty($first->getContext()['declared_properties']);
    $this->assertStringStartsWith($granted->corrective('transition'), $second->getHint(), 'The second miss keeps its own corrective.');
    $this->assertStringContainsString('Same as the first STYLE_PROPERTY_NOT_DECLARED error above', $second->getHint(), 'The second miss references the first instead of repeating the roster.');
    // Both misses are addressed to the author's alias in the author's
    // grammar (style="" declarations), never to the translated op.
    $this->assertSame('sec', $first->getElement());
    $this->assertSame('sec', $second->getElement());
    $this->assertSame('style.text-decoration', $first->getPath());
    $this->assertSame('style.transition', $second->getPath());
    $this->assertArrayNotHasKey('declared_properties', $second->getContext(), 'The duplicated payload is dropped from the referencing error.');
    $roster_spellings = array_filter(
      $result->getErrors(),
      static fn ($error) => isset($error->getContext()['declared_properties']),
    );
    $this->assertCount(1, $roster_spellings, 'The roster is spelled exactly once in the batch.');
    // The marker never reaches the wire, deduplicated or not.
    foreach ($result->getErrors() as $error) {
      $this->assertArrayNotHasKey('_shared_hint', $error->toArray()['context'] ?? [], 'The shared-hint marker is bookkeeping, not wire format.');
    }
  }

}
