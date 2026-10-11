<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\Capability\Policy\OwnershipPolicy;
use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Frame\RouteOutput;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\InnerRender;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_blueprint_library\BlueprintLayout;
use Drupal\daedalus_blueprint_library\BlueprintOperationHandler;
use Drupal\daedalus_blueprint_library\BlueprintInterface;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\layout_builder\SectionComponent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Linked placement: a blueprint shown by reference inside a page.
 *
 * `place_blueprint` with `mode: linked` lands one node
 * (daedalus_blueprint_linked:{machine_name}, the lean tree's
 * `{kind: linked, blueprint}`, the dialect's <pb-linked>) that renders the
 * blueprint's document live under the page's contexts: a field block in
 * the blueprint shows the page's own name, an edit of the blueprint
 * reaches the page without a re-place, the node's content is foreign
 * (FOREIGN_DOCUMENT with the portal to the blueprint canvas for an actor
 * who may update it) while its placement stays the page's, styles follow
 * the blueprint host (its map delivered inside the render, its inline
 * blocks rooted on it), and the op door judges the fit and the linkable
 * set for every road into the document. Detach is the road back to a
 * copy, in place for a linked node and, as a CLAIM (`detach_blueprint`
 * with `slot`), for the default a slot door around the page shows: the
 * copy lands as the page's own slot section, and releasing (removing the
 * section) shows the default again.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(LinkedBlueprintBlock::class)]
#[CoversClass(BlueprintOperationHandler::class)]
#[CoversClass(OwnershipPolicy::class)]
#[CoversClass(InnerRender::class)]
#[Group('daedalus_blueprint')]
class LinkedPlacementTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish', 'daedalus_blueprint_library_test'];

  /**
   * The testimonial blueprint: a name field block beside a plugin block.
   */
  protected Blueprint $blueprint;

  /**
   * The testimonial's one section uuid.
   */
  protected string $blueprintSection;

  /**
   * The testimonial's plugin block uuid.
   */
  protected string $poweredBy;

  /**
   * The testimonial's inline block (a basic block_content).
   */
  protected BlockContent $inner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The materials roster (the dialect catalog's) reads every stylish
    // storage on a build.
    foreach (['daedalus_variant', 'daedalus_skin', 'daedalus_swatch', 'daedalus_animation', 'daedalus_font'] as $entity_type) {
      $this->installEntitySchema($entity_type);
    }
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $uuid = $this->container->get('uuid');
    $this->blueprintSection = $uuid->generate();
    $this->poweredBy = $uuid->generate();
    $this->inner = BlockContent::create(['type' => 'basic', 'info' => 'Inner', 'reusable' => FALSE]);
    $this->inner->save();
    $section = $this->section('layout_onecol', [
      new SectionComponent($uuid->generate(), 'content', [
        'id' => 'field_block:entity_test:entity_test:name',
        'label_display' => '0',
        'formatter' => [
          'label' => 'hidden',
          'type' => 'string',
          'settings' => ['link_to_entity' => FALSE],
          'third_party_settings' => [],
        ],
        'context_mapping' => ['entity' => 'layout_builder.entity'],
      ]),
      new SectionComponent($this->poweredBy, 'content', [
        'id' => 'system_powered_by_block',
        'label' => 'Powered',
        'label_display' => '0',
      ]),
      new SectionComponent($uuid->generate(), 'content', [
        'id' => 'inline_block:basic',
        'provider' => 'layout_builder',
        'label' => 'Inner',
        'label_display' => '0',
        'view_mode' => 'full',
        'block_revision_id' => $this->inner->getRevisionId(),
        'block_serialized' => NULL,
      ]),
    ], $this->blueprintSection);
    $this->blueprint = $this->createBlueprint([
      'label' => 'Testimonial',
      'machine_name' => 'testimonial',
    ], [$section]);
    // The blueprint's own styles: its section and its document root.
    $this->blueprint->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      'container:' . $this->blueprintSection => ['default' => ['background-color' => 'rgb(1, 2, 3)']],
      'entity:' . $this->blueprint->uuid() => ['default' => ['padding' => '7px']],
    ]));
    $this->blueprint->save();
  }

  /**
   * Places the testimonial linked into a page's root section.
   *
   * @return array{0: \Drupal\daedalus_blueprint\Host\HostInterface, 1: string, 2: \Drupal\Core\Entity\FieldableEntityInterface}
   *   The page host, the linked node's uuid and the page entity.
   */
  protected function placeLinked(string $name = 'Page sentinel'): array {
    ['entity' => $page, 'root_section' => $root] = $this->createHost();
    $page->set('name', $name)->save();
    $host = $this->host($page);
    $linked = $this->container->get('uuid')->generate();
    $result = $this->operations()->batchOperations($host, [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'testimonial',
        'mode' => BlueprintOperationHandler::MODE_LINKED,
        'uuid' => $linked,
        'location' => ['anchor' => $root, 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);
    // The batch writes the host's entity in memory; the page saves it.
    $page->save();
    return [$this->host($this->reload($page)), $linked, $this->reload($page)];
  }

  /**
   * Reloads an entity uncached.
   */
  protected function reload(object $entity): object {
    return $this->container->get('entity_type.manager')->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
  }

  /**
   * Renders a page's full view to markup.
   */
  protected function renderPage(object $page): string {
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($page, 'full');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * An XPath over markup.
   */
  protected function domXpath(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    return new \DOMXPath($dom);
  }

  /**
   * The linked block's root element in a render.
   */
  protected function linkedElement(\DOMXPath $xpath, string $linked, string $html): \DOMElement {
    $nodes = $xpath->query('//*[@data-layout-builder-block-uuid="' . $linked . '"]');
    $this->assertSame(1, $nodes->length, "Exactly one linked block in: $html");
    return $nodes->item(0);
  }

  /**
   * The lean node of the linked placement in a host's tree.
   */
  protected function linkedNode(HostInterface $host, string $linked): array {
    $tree = $this->container->get('daedalus_blueprint.node_tree_builder')->build($host);
    foreach ($this->rootChildren($tree) as $child) {
      if (($child['uuid'] ?? NULL) === $linked) {
        return $child;
      }
    }
    $this->fail('The linked node is in the root section: ' . json_encode($tree));
  }

  /**
   * The elements of a tree's first section, regions unwrapped.
   */
  protected function rootChildren(array $tree): array {
    $children = [];
    foreach ($tree[0]['children'] ?? [] as $child) {
      if (($child['kind'] ?? NULL) === 'region') {
        array_push($children, ...$child['children']);
      }
      else {
        $children[] = $child;
      }
    }
    return $children;
  }

  /**
   * The node renders the blueprint live under the page's own fields.
   */
  public function testLinkedPlacementRendersTheBlueprintLive(): void {
    [$host, $linked, $page] = $this->placeLinked();

    // The tree: one linked node, its one fact the blueprint it shows.
    $this->assertSame(['kind' => 'linked', 'uuid' => $linked, 'blueprint' => 'testimonial'], $this->linkedNode($host, $linked));
    $component = $host->sectionStorage()->getSection(0)->getComponent($linked);
    $configuration = $component->get('configuration');
    $this->assertSame(LinkedBlueprintBlock::pluginId('testimonial'), $configuration['id']);
    $this->assertSame(LinkedBlueprintBlock::CONTEXT_MAPPING, $configuration['context_mapping'], 'The subject and the view mode, mapped by default.');
    $this->assertNull($component->get('daedalus_blueprint_library'), 'No provenance stamp: nothing was copied.');

    // The render: the blueprint's document inside the node, the page's
    // own name in the blueprint's field block, the blueprint's identity
    // on its section.
    $html = $this->renderPage($page);
    $xpath = $this->domXpath($html);
    $element = $this->linkedElement($xpath, $linked, $html);
    $this->assertStringContainsString('Page sentinel', $element->textContent, "The blueprint's field block shows the page's own name: $html");
    $this->assertStringContainsString('Powered by', $element->textContent);
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . $this->blueprintSection . '"]', $element)->length, "The blueprint's section keeps its identity: $html");
    $this->assertSame(1, $xpath->query('.//*[contains(@class, "' . LinkedBlueprintBlock::WRAPPER_CLASS . '")]', $element)->length, 'The document wrapper.');
    $this->assertSame('block:' . $linked, $element->getAttribute('data-selectable'), 'The placement keeps its own key on its root.');
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($page, 'full');
    $this->container->get('renderer')->renderInIsolation($build);
    $this->assertContains('blueprint:' . $this->blueprint->id(), $build['#cache']['tags'], 'The page depends on the blueprint.');

    // Forward propagation: editing the blueprint changes the page, with no
    // re-place.
    $blueprint = $this->reload($this->blueprint);
    $sections = $blueprint->get('layout_builder__layout')->getSections();
    $sections[0]->removeComponent($this->poweredBy);
    $blueprint->set('layout_builder__layout', $sections)->save();
    $html = $this->renderPage($this->reload($page));
    $this->assertStringNotContainsString('Powered by', $html, 'The blueprint\'s edit reached the page.');
    $this->assertStringContainsString('Page sentinel', $this->linkedElement($this->domXpath($html), $linked, $html)->textContent);

    // A second page: the first page's read instantiates as it is, the
    // linked node landing linked (a copy of the page, not of the
    // blueprint), and the capture lane keeps the node's blueprint.
    ['entity' => $other, 'root_section' => $root] = $this->createHost();
    $result = $this->container->get('daedalus_blueprint.document_instantiator')->instantiate(
      $this->host($other),
      [$this->linkedNode($host, $linked)],
      ['anchor' => $root, 'placement' => 'inside'],
    );
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $other->save();
    $other_host = $this->host($this->reload($other));
    $nodes = array_values(array_filter($this->rootChildren($this->container->get('daedalus_blueprint.node_tree_builder')->build($other_host)), static fn (array $node): bool => $node['kind'] === 'linked'));
    $this->assertCount(1, $nodes);
    $this->assertSame('testimonial', $nodes[0]['blueprint']);
    $this->assertNotSame($linked, $nodes[0]['uuid'], 'Fresh identity.');
    $this->assertSame(
      [['kind' => 'linked', 'blueprint' => 'testimonial'], ['kind' => 'container', 'children' => []]],
      BlueprintLayout::withoutProvenance([
        ['kind' => 'linked', 'blueprint' => 'testimonial'],
        ['kind' => 'container', 'blueprint' => 'stamp', 'children' => []],
      ]),
      'A capture strips a provenance stamp and keeps a linked node\'s blueprint.',
    );
  }

  /**
   * The node's content is foreign, with a portal for an actor who may update.
   */
  public function testTheLinkedNodeIsForeignContentWithPortal(): void {
    [$host, $linked] = $this->placeLinked();
    $stamper = $this->container->get('daedalus_blueprint.verdict_stamper');

    // The admin: content refused, the portal to the blueprint; the
    // placement's own gestures stay the page's.
    $verdict = $stamper->verdicts($host, $linked);
    $this->assertSame(
      ['content' => FALSE, 'configure' => TRUE, 'style' => TRUE, 'arrange' => TRUE],
      array_intersect_key($verdict['can'], array_flip(['content', 'configure', 'style', 'arrange'])),
      'The content is foreign; the placement is the page\'s.',
    );
    $foreign = array_values(array_filter($verdict['refused'], static fn (array $refusal): bool => $refusal['code'] === 'FOREIGN_DOCUMENT'));
    $this->assertCount(1, $foreign, print_r($verdict['refused'], TRUE));
    $this->assertSame(['content'], $foreign[0]['capabilities']);
    $this->assertSame('testimonial', $foreign[0]['context']['blueprint']);
    $this->assertSame([
      'entity_type' => 'blueprint',
      'entity_id' => (string) $this->blueprint->id(),
    ], $foreign[0]['context']['owner']);
    $this->assertSame('blueprint', $foreign[0]['context']['portal']['entity_type']);
    $this->assertSame((string) $this->blueprint->id(), $foreign[0]['context']['portal']['entity_id']);
    $this->assertStringContainsString('blueprint ' . $this->blueprint->id(), $foreign[0]['hint']);
    // The page root keeps its content: the page's fields edit in place,
    // the blueprint's field block showing one of them included.
    $this->assertTrue($stamper->verdicts($host, $host->uuid())['can']['content']);

    // An editor who may not update the blueprint: the same refusal, no door.
    $editor = $this->createUser(['administer entity_test content', 'configure any layout', 'use daedalus ui edit mode']);
    $verdict = $stamper->verdicts($host, $linked, $editor);
    $this->assertFalse($verdict['can']['content']);
    $foreign = array_values(array_filter($verdict['refused'], static fn (array $refusal): bool => $refusal['code'] === 'FOREIGN_DOCUMENT'));
    $this->assertCount(1, $foreign);
    $this->assertArrayNotHasKey('portal', $foreign[0]['context']);
    $this->assertStringContainsString('may not edit', $foreign[0]['hint']);

    // The annotation: the blueprint's words, the verdict, the showing
    // with the portal's canvas url for the admin and no portal for the
    // editor.
    $annotator = $this->container->get('daedalus_ui.element_annotator');
    $tree = $this->container->get('daedalus_blueprint.node_tree_builder')->build($host);
    $annotated = $annotator->annotateTree($stamper->stampTree($tree, $host), $host);
    $node = array_values(array_filter($this->rootChildren($annotated), static fn (array $node): bool => ($node['uuid'] ?? NULL) === $linked))[0];
    $this->assertSame('Testimonial', $node['_element']['name']);
    $this->assertSame('Linked blueprint', $node['_element']['type']);
    $this->assertFalse($node['_element']['can']['content']);
    $this->assertSame([
      'shows' => 'default',
      'document' => ['entity_type' => 'blueprint', 'id' => (string) $this->blueprint->id(), 'label' => 'Testimonial'],
      'blueprint' => 'testimonial',
      'portal' => [
        'entity_type' => 'blueprint',
        'entity_id' => (string) $this->blueprint->id(),
        'label' => 'Testimonial',
        'url' => Url::fromRoute('entity.blueprint.canonical', ['blueprint' => $this->blueprint->id()])->toString(),
      ],
      'claimable' => FALSE,
    ], $node['_element']['showing'], 'The linked node shows the blueprint by reference, with the portal to its canvas for an actor who may update it.');
    $this->setCurrentUser($editor);
    $annotated = $annotator->annotateTree($stamper->stampTree($tree, $host, $editor), $host);
    $node = array_values(array_filter($this->rootChildren($annotated), static fn (array $node): bool => ($node['uuid'] ?? NULL) === $linked))[0];
    $this->assertSame('default', $node['_element']['showing']['shows']);
    $this->assertNull($node['_element']['showing']['portal'], 'No door for an actor who may not update the blueprint.');

    // The dialect: the node prints as <pb-linked blueprint="">, parses back
    // to the linked node, and refuses what cannot be linked.
    $catalog = $this->dialectOptions(TRUE)['dialect_catalog'];
    $print = $this->container->get('daedalus_blueprint.dialect_printer')->print($tree, $catalog)->text;
    $this->assertMatchesRegularExpression('/<pb-linked id="' . preg_quote($linked, '/') . '"[^>]*blueprint="testimonial"[^>]*\/>/', $print, $print);
    $parser = $this->container->get('daedalus_blueprint.dialect_parser');
    $parsed = $parser->parseFragment('<pb-container id="c"><pb-linked blueprint="testimonial"/></pb-container>', $catalog);
    $this->assertSame([], $parsed->errors);
    $this->assertSame(['kind' => 'linked', 'blueprint' => 'testimonial'], $parsed->tree[0]['children'][0]);
    $codes = static fn ($result): array => array_map(static fn ($error) => $error->code, $result->errors);
    $this->assertSame(['BLUEPRINT_NOT_LINKABLE'], $codes($parser->parseFragment('<pb-container id="c"><pb-linked blueprint="call_to_action"/></pb-container>', $catalog)), 'A shipped file has no live document.');
    $this->assertSame(['UNKNOWN_BLUEPRINT'], $codes($parser->parseFragment('<pb-container id="c"><pb-linked blueprint="nope"/></pb-container>', $catalog)));
    $this->assertSame(['ILLEGAL_ATTR'], $codes($parser->parseFragment('<pb-container id="c"><pb-linked /></pb-container>', $catalog)));
    $this->createBlueprint(['label' => 'Whole page', 'machine_name' => 'whole_page', 'scope' => 'page']);
    $catalog = $this->dialectOptions(TRUE)['dialect_catalog'];
    $this->assertSame(['BLUEPRINT_WRONG_SCOPE'], $codes($parser->parseFragment('<pb-container id="c"><pb-linked blueprint="whole_page"/></pb-container>', $catalog)));
  }

  /**
   * Styles follow the blueprint host: delivered inside, blocks rooted on it.
   */
  public function testStylesFollowTheBlueprint(): void {
    [, $linked, $page] = $this->placeLinked();
    $html = $this->renderPage($page);
    $xpath = $this->domXpath($html);
    $element = $this->linkedElement($xpath, $linked, $html);
    $this->assertStringContainsString('rgb(1, 2, 3)', $html, "The blueprint's map is delivered on the page: $html");
    $this->assertSame(1, $xpath->query('.//style', $element)->length, 'Inside the linked placement.');
    $wrapper = $xpath->query('.//*[contains(@class, "' . LinkedBlueprintBlock::WRAPPER_CLASS . '")]', $element)->item(0);
    $this->assertSame('entity:' . $this->blueprint->uuid(), $wrapper->getAttribute('data-selectable'), "The blueprint document's root key rides the wrapper, never the placement's root: $html");
    $this->assertSame('block:' . $linked, $element->getAttribute('data-selectable'));

    // The blueprint's inline block is the blueprint's, not the page's:
    // rooted on the blueprint, its styles answered by the blueprint host.
    $inner = $this->reload($this->inner);
    $root = $this->container->get('daedalus_layout.root_entity_registry')->getRoot($inner);
    $this->assertNotNull($root);
    $this->assertSame($this->blueprint->uuid(), $root->uuid(), 'The inner block roots on the blueprint.');
    $hosts = $this->container->get('daedalus_stylish.render_styles')->hosts($inner);
    $this->assertSame('blueprint', $hosts['own']->entityTypeId());
    $this->assertSame((string) $this->blueprint->id(), (string) $hosts['own']->id());

    // The page's own map says nothing of the blueprint's keys.
    $this->assertNotContains('container:' . $this->blueprintSection, $this->container->get('daedalus_stylish.render_styles')->keys($this->reload($page)));
  }

  /**
   * The op door judges every road: the mode, the fit, the linkable set.
   */
  public function testTheOpDoorJudgesEveryRoad(): void {
    ['entity' => $page, 'root_section' => $root] = $this->createHost();
    $host = $this->host($page);
    $inside = ['anchor' => $root, 'placement' => 'inside'];
    $codes = static fn ($result): array => array_map(static fn ($error) => $error->getCode(), $result->getErrors());

    $result = $this->operations()->batchOperations($host, [
      ['op' => 'place_blueprint', 'blueprint' => 'testimonial', 'mode' => 'bogus', 'location' => $inside],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['INVALID_PARAMETER'], $codes($result));
    $this->assertSame('mode', $result->getErrors()[0]->getPath());

    // A shipped file cannot be linked; the same file places detached.
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'place_blueprint', 'blueprint' => 'call_to_action', 'mode' => 'linked', 'location' => $inside],
    ]);
    $this->assertSame(['BLUEPRINT_NOT_LINKABLE'], $codes($result));
    $this->assertFalse($this->container->get('plugin.manager.block')->hasDefinition(LinkedBlueprintBlock::pluginId('call_to_action')), 'No derivative for a file.');

    // A blueprint needing a field the page lacks does not fit, through
    // place_blueprint and through a raw add_element alike.
    $this->createBlueprint(['label' => 'Needs a field', 'machine_name' => 'needs_field'], [
      $this->section('layout_onecol', [$this->pluginComponent('field_block:entity_test:entity_test:field_missing')]),
    ]);
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'place_blueprint', 'blueprint' => 'needs_field', 'mode' => 'linked', 'location' => $inside],
    ]);
    $this->assertSame(['BLUEPRINT_DOES_NOT_FIT'], $codes($result));
    $this->assertSame(['field_missing'], $result->getErrors()[0]->getContext()['missing']['fields']);
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'add_element', 'plugin_id' => LinkedBlueprintBlock::pluginId('needs_field'), 'location' => $inside],
    ]);
    $this->assertSame(['BLUEPRINT_DOES_NOT_FIT'], $codes($result), 'The fit is the op door\'s, for every road.');

    // An unpublished blueprint derives no block and links nothing.
    $this->createBlueprint(['label' => 'Draft', 'machine_name' => 'draft', 'status' => 0]);
    $this->assertFalse($this->container->get('plugin.manager.block')->hasDefinition(LinkedBlueprintBlock::pluginId('draft')));
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'place_blueprint', 'blueprint' => 'draft', 'mode' => 'linked', 'location' => $inside],
    ]);
    $this->assertSame(['BLUEPRINT_NOT_FOUND'], $codes($result));
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'add_element', 'plugin_id' => LinkedBlueprintBlock::pluginId('draft'), 'location' => $inside],
    ]);
    $this->assertSame(['INVALID_PLUGIN_ID'], $codes($result));

    // Publishing rebuilds the derivatives; the detached default is unchanged.
    $draft = $this->loadBlueprint('draft');
    $draft->setPublished()->save();
    $this->assertTrue($this->container->get('plugin.manager.block')->hasDefinition(LinkedBlueprintBlock::pluginId('draft')));
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'place_blueprint', 'blueprint' => 'testimonial', 'location' => $inside],
    ]);
    $this->assertBatchSuccess($result);
    $page->save();
    $tree = $this->container->get('daedalus_blueprint.node_tree_builder')->build($this->host($this->reload($page)));
    $placed = array_values(array_filter($this->rootChildren($tree), static fn (array $node): bool => $node['kind'] === 'container'));
    $this->assertSame('testimonial', $placed[0]['blueprint'], 'A detached placement is a copy with provenance.');
    $this->assertSame(1, count(array_filter($this->rootChildren([$placed[0]]), static fn (array $node): bool => ($node['field'] ?? NULL) === 'name')), 'The copy holds the blueprint\'s elements.');
  }

  /**
   * Detach: the node becomes, in its place, the copy it showed.
   *
   * `detach_blueprint {element}` on a linked node expands the blueprint's
   * tree after the node in its own flow with fresh identity and the
   * provenance stamp and removes the node, so the page owns the
   * composition from then on: the blueprint's later edits no longer reach
   * it, and the capture and push-back gestures work on the copy exactly as
   * on a detached placement. The blueprint's node styles are copied; the
   * blueprint's root entry stays the blueprint's.
   */
  public function testDetachMakesTheNodeTheCopyItShowed(): void {
    [$host, $linked] = $this->placeLinked();
    $marker = $this->container->get('uuid')->generate();
    $this->assertBatchSuccess($this->operations()->batchOperations($host, [
      [
        'op' => 'add_element',
        'uuid' => $marker,
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => $linked, 'placement' => 'before'],
      ],
    ]));
    $host->entity()->save();
    $page = $this->reload($host->entity());
    $host = $this->host($page);

    // The roll-call: a detach is a page-language op (page_edit admits it
    // with a ref); a placement stays the page language's element, so the
    // op is page-only and never a page-language op.
    $registry = $this->container->get('daedalus_blueprint.operation_handler_registry');
    $this->assertContains(BlueprintOperationHandler::DETACH, $registry->getPageLanguageOperations());
    $this->assertNotContains(BlueprintOperationHandler::PLACE, $registry->getPageLanguageOperations());
    $this->assertContains(BlueprintOperationHandler::PLACE, $registry->getPageOnlyOperations());

    $result = $this->operations()->batchOperations($host, [
      ['op' => BlueprintOperationHandler::DETACH, 'element' => $linked],
    ]);
    $this->assertBatchSuccess($result);
    $host->entity()->save();
    $page = $this->reload($page);
    $host = $this->host($page);

    $children = $this->rootChildren($this->container->get('daedalus_blueprint.node_tree_builder')->build($host));
    $this->assertSame(['block', 'container'], array_column($children, 'kind'), 'The copy stands where the node stood: ' . json_encode($children));
    $this->assertSame($marker, $children[0]['uuid']);
    $copy = $children[1];
    $this->assertSame('testimonial', $copy['blueprint'], 'A detached placement: a copy with provenance.');
    $this->assertNotSame($this->blueprintSection, $copy['uuid'], 'Fresh identity.');
    $inner = $this->rootChildren([$copy]);
    $this->assertSame(['name', NULL, NULL], array_map(static fn (array $node): ?string => $node['field'] ?? NULL, $inner), 'The blueprint\'s field block, spelled for the page.');
    $this->assertSame([NULL, 'system_powered_by_block', NULL], array_map(static fn (array $node): ?string => $node['plugin_id'] ?? NULL, $inner));
    $this->assertSame('basic', $inner[2]['block_type']);
    $this->assertNotContains($this->poweredBy, array_column($inner, 'uuid'), 'The blueprint\'s identity stays its own.');
    $this->assertArrayNotHasKey($linked, $host->sectionStorage()->getSection(0)->getComponents(), 'The node is gone.');
    $this->assertContains($linked, array_column(array_filter($result->getTargets(), static fn (array $target): bool => $target['field_name'] === TargetType::REMOVED), 'uuid'), 'The response reports the removal.');

    // Styles: the blueprint's node entries copied onto the fresh identity;
    // its root entry (entity:) is the blueprint document's own and stays
    // there, as a detached placement never carries it.
    $map = $host->styleMap();
    $this->assertSame(['default' => ['background-color' => 'rgb(1, 2, 3)']], $map['container:' . $copy['uuid']] ?? NULL, print_r($map, TRUE));
    $this->assertArrayNotHasKey('entity:' . $page->uuid(), $map);

    // The render: the copy is the page's own document, no linked wrapper,
    // the page's name in the copied field block.
    $html = $this->renderPage($page);
    $this->assertStringContainsString('Page sentinel', $html);
    $this->assertStringContainsString('Powered by', $html);
    $this->assertStringNotContainsString(LinkedBlueprintBlock::WRAPPER_CLASS, $html);
    $this->assertSame(1, $this->domXpath($html)->query('//*[@data-selectable="container:' . $copy['uuid'] . '"]')->length, $html);

    // Independence: an edit of the blueprint no longer reaches the page.
    $blueprint = $this->reload($this->blueprint);
    $sections = $blueprint->get('layout_builder__layout')->getSections();
    $sections[0]->removeComponent($this->poweredBy);
    $blueprint->set('layout_builder__layout', $sections)->save();
    $this->assertStringNotContainsString('Powered by', $this->renderPage($this->placeLinked('Still linked')[2]), 'A linked page follows the edit.');
    $this->assertStringContainsString('Powered by', $this->renderPage($this->reload($page)), 'The detached copy is the page\'s own.');

    // Push back: the copy updates the blueprint as any detached placement
    // does (its provenance names the blueprint), and the linked page
    // follows the pushed document.
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => 'testimonial', 'element' => $copy['uuid']],
    ]);
    $this->assertBatchSuccess($result);
    $layout = $this->container->get('daedalus_blueprint_library.discovery')->getBlueprint('testimonial')['layout'];
    $this->assertContains('system_powered_by_block', array_map(static fn (array $node): ?string => $node['plugin_id'] ?? NULL, $this->rootChildren($layout)), 'The copy\'s document is the blueprint\'s again.');
    $this->assertArrayNotHasKey('blueprint', $layout[0], 'A blueprint is not a placement: the stamp did not travel.');
    $this->assertStringContainsString('Powered by', $this->renderPage($this->placeLinked('Linked after push')[2]));

    // Capture: the copy saves as a new blueprint, the stamp stripped.
    $result = $this->operations()->batchOperations($host, [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $copy['uuid'],
        'label' => 'Testimonial copy',
        'description' => 'Captured from a detached copy.',
      ],
    ]);
    $this->assertBatchSuccess($result);
    $saved = $this->container->get('entity_type.manager')->getStorage('blueprint')->loadByProperties(['label' => 'Testimonial copy']);
    $this->assertCount(1, $saved);
    $saved = reset($saved);
    $this->assertSame(BlueprintInterface::SCOPE_BLOCK, $saved->getScope());
    $definition = $this->container->get('daedalus_blueprint_library.discovery')->getBlueprint($saved->getMachineName());
    $this->assertArrayNotHasKey('blueprint', $definition['layout'][0]);
    $this->assertSame('name', $this->rootChildren($definition['layout'])[0]['field']);
  }

  /**
   * Detach refuses what is not a linked node showing a served blueprint.
   */
  public function testDetachRefusals(): void {
    [$host, $linked] = $this->placeLinked();
    $marker = $this->container->get('uuid')->generate();
    $this->assertBatchSuccess($this->operations()->batchOperations($host, [
      [
        'op' => 'add_element',
        'uuid' => $marker,
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => $linked, 'placement' => 'after'],
      ],
    ]));
    $detach = fn (array $operation): array => array_map(
      static fn ($error): array => [$error->getPath(), $error->getCode()],
      $this->operations()->batchOperations($host, [['op' => BlueprintOperationHandler::DETACH] + $operation])->getErrors(),
    );
    $this->assertSame([['element', 'MISSING_PARAMETER']], $detach([]));
    $this->assertSame([['element', 'BLUEPRINT_NOT_LINKED']], $detach(['element' => $marker]), 'A plugin block is nobody\'s linked placement.');
    $this->assertSame([['element', 'UNKNOWN_REF']], $detach(['element' => 'hero']), 'A ref that is no uuid is an alias, resolved or refused.');
    $missing = $detach(['element' => $this->container->get('uuid')->generate()]);
    $this->assertCount(1, $missing);
    $this->assertSame('element', $missing[0][0]);
    $this->assertContains($missing[0][1], ['ELEMENT_NOT_FOUND', 'ANCHOR_NOT_FOUND'], 'An element the document lacks, refused at the door or by the handler.');

    // An unpublished blueprint derives no block and serves no document:
    // the node shows nothing, and there is nothing to copy.
    $this->loadBlueprint('testimonial')->setUnpublished()->save();
    $this->assertSame([['element', 'BLUEPRINT_NOT_FOUND']], $detach(['element' => $linked]));
    $this->assertArrayHasKey($linked, $host->sectionStorage()->getSection(0)->getComponents(), 'The node stays.');
  }

  /**
   * The site chrome is the frame's material: placed there, claimed below.
   *
   * A blueprint of a frame-only type (`site_chrome`) is refused
   * BLUEPRINT_DOES_NOT_FIT naming the site frame on a page, linked
   * (place_blueprint's linked mode and a raw add_element of the linked
   * plugin, the op door's one gate) and detached alike, with nothing
   * placed; on the frame host both placements land. The one exemption is
   * the claim: a frame slot whose default is the chrome is claimed by a
   * page as any default is, the copy landing as the page's own slot
   * section, because the claim is the frame's default reaching down into
   * the page and the only road by which the chrome enters a page's
   * document.
   */
  public function testTheSiteChromeIsTheFramesMaterial(): void {
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName('stark'));
    $this->config(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'main' => ['label' => 'Main', 'tier' => SlotRoster::TIER_FRAME],
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_FRAME],
      ])
      ->save();
    $this->createBlueprint([
      'label' => 'Header',
      'machine_name' => 'header',
      'type' => BlueprintInterface::TYPE_SITE_CHROME,
    ], [$this->section('layout_onecol', [$this->pluginComponent('system_powered_by_block')])]);
    $this->assertTrue($this->container->get('daedalus_blueprint_library.discovery')->getBlueprint('header')['frame_only']);
    $uuid = $this->container->get('uuid');
    $hero_door = $uuid->generate();
    $frame_root = $uuid->generate();
    $frame = Frame::create(['id' => 'stark']);
    $frame->appendSection($this->section('layout_onecol', [
      new SectionComponent($hero_door, 'content', [
        'id' => SlotBlock::PLUGIN_ID . ':hero',
        'label_display' => '0',
        'context_mapping' => SlotBlock::CONTEXT_MAPPING,
        SlotBlock::DEFAULT_KEY => 'header',
      ]),
    ], $frame_root));
    $frame->save();
    $frame_host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($frame);
    ['entity' => $page, 'root_section' => $root] = $this->createHost();
    $host = $this->host($page);
    $inside = ['anchor' => $root, 'placement' => 'inside'];
    $errors = static fn ($result): array => array_map(
      static fn ($error): array => [$error->getPath(), $error->getCode()],
      $result->getErrors(),
    );

    // Off the frame: refused on every road, naming the site frame.
    $roads = [
      'linked' => [
        'op' => 'place_blueprint',
        'blueprint' => 'header',
        'mode' => BlueprintOperationHandler::MODE_LINKED,
        'location' => $inside,
      ],
      'raw add_element' => [
        'op' => 'add_element',
        'plugin_id' => LinkedBlueprintBlock::pluginId('header'),
        'location' => $inside,
      ],
      'detached' => ['op' => 'place_blueprint', 'blueprint' => 'header', 'location' => $inside],
    ];
    foreach ($roads as $road => $operation) {
      $result = $this->operations()->batchOperations($host, [$operation]);
      $this->assertFalse($result->isSuccess(), $road);
      $error = $result->getErrors()[0];
      $this->assertSame('BLUEPRINT_DOES_NOT_FIT', $error->getCode(), $road);
      $this->assertSame('Blueprint "header" does not fit this entity_test:entity_test: it needs the site frame.', $error->getMessage(), $road);
      $this->assertSame(['frame' => [FrameInterface::ENTITY_TYPE_ID]], $error->getContext()['missing'], $road);
      $this->assertStringContainsString('claim', $error->getHint(), $road);
    }
    $this->assertCount(1, $host->ownSections(), 'Nothing was placed.');
    $this->assertSame([], $host->ownSections()[0]->getComponents());

    // On the frame: linked and detached placements land.
    $linked = $uuid->generate();
    $inside_frame = ['anchor' => $frame_root, 'placement' => 'inside'];
    $this->assertBatchSuccess($this->operations()->batchOperations($frame_host, [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'header',
        'mode' => BlueprintOperationHandler::MODE_LINKED,
        'uuid' => $linked,
        'location' => $inside_frame,
      ],
      ['op' => 'place_blueprint', 'blueprint' => 'header', 'location' => $inside_frame],
    ]));
    $tree = $this->container->get('daedalus_blueprint.node_tree_builder')->build($frame_host);
    $kinds = array_map(static fn (array $node): array => [$node['kind'], $node['blueprint'] ?? NULL], $this->rootChildren($tree));
    $this->assertContains([LinkedBlueprintBlock::KIND, 'header'], $kinds, 'Linked on the frame: ' . json_encode($tree));
    $this->assertContains(['container', 'header'], $kinds, 'A detached copy with provenance on the frame.');

    // The claim: the chrome reaches the page as the frame's default.
    $result = $this->operations()->batchOperations($host, [
      ['op' => BlueprintOperationHandler::DETACH, 'slot' => 'hero'],
    ]);
    $this->assertBatchSuccess($result);
    $host->entity()->save();
    $tree = $this->container->get('daedalus_blueprint.node_tree_builder')->build($this->host($this->reload($page)));
    $this->assertCount(2, $tree, 'The root section and the fill: ' . json_encode($tree));
    $this->assertSame(['hero', 'header'], [$tree[1]['slot'], $tree[1]['blueprint']], 'The chrome is the page\'s own slot section now.');
    $this->assertSame(['system_powered_by_block'], array_column($this->rootChildren([$tree[1]]), 'plugin_id'));
    // Still never a placement, claimed or not.
    $this->assertSame([['blueprint', 'BLUEPRINT_DOES_NOT_FIT']], $errors($this->operations()->batchOperations($this->host($this->reload($page)), [
      ['op' => 'place_blueprint', 'blueprint' => 'header', 'location' => $inside],
    ])));
  }

  /**
   * Claiming a slot: the default a frame slot shows becomes the page's own.
   *
   * The site frame around the page places a hero slot whose default is
   * the testimonial. From the page's canvas the door is the frame's node
   * (foreign: FOREIGN_DOCUMENT, the portal to the blueprint whose document
   * it shows) with one capability the page's own, insert, so the page may
   * fill it. `detach_blueprint {slot: hero}` claims it: the testimonial's
   * tree lands as the page's own top-level section tagged hero, with
   * provenance, so the frame's slot shows the page's copy on this page and
   * the default on every other; the claimed door's portal is the frame's
   * (the content is the page's now), and claiming again is refused. The
   * refusals: an unknown slot, a door without a default, a malformed id,
   * a host that fills no slots. Releasing is remove_element on the slot
   * section: the default shows again.
   */
  public function testClaimAndRelease(): void {
    $admin = $this->container->get('current_user')->getAccount();
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName('stark'));
    $this->config(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'main' => ['label' => 'Main', 'tier' => SlotRoster::TIER_FRAME],
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_FRAME],
        'aside' => ['label' => 'Aside', 'tier' => SlotRoster::TIER_FRAME],
      ])
      ->save();
    $uuid = $this->container->get('uuid');
    $hero_door = $uuid->generate();
    $aside_door = $uuid->generate();
    $frame = Frame::create(['id' => 'stark']);
    $frame->appendSection($this->section('layout_onecol', [
      new SectionComponent($hero_door, 'content', [
        'id' => SlotBlock::PLUGIN_ID . ':hero',
        'label_display' => '0',
        'context_mapping' => SlotBlock::CONTEXT_MAPPING,
        SlotBlock::DEFAULT_KEY => 'testimonial',
      ]),
      new SectionComponent($aside_door, 'content', [
        'id' => SlotBlock::PLUGIN_ID . ':aside',
        'label_display' => '0',
        'context_mapping' => SlotBlock::CONTEXT_MAPPING,
      ]),
    ]));
    $frame->save();
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    $frame_host = $resolver->fromEntity($frame);
    $inner_render = $this->container->get('daedalus_blueprint.inner_render');
    $stamper = $this->container->get('daedalus_blueprint.verdict_stamper');
    ['entity' => $page, 'root_section' => $root] = $this->createHost();
    $page->set('name', 'Claimant sentinel')->save();
    $host = $this->host($page);
    $render = function () use ($frame_host, $inner_render, $page): string {
      $build = $inner_render->renderFrame($frame_host, new RouteOutput(['#markup' => 'Route output'], 'Title'), $this->reload($page));
      return (string) $this->container->get('renderer')->renderInIsolation($build);
    };
    $codes = static fn ($result): array => array_map(static fn ($error): array => [$error->getPath(), $error->getCode()], $result->getErrors());
    $foreign = static fn (array $verdict): array => array_values(array_filter($verdict['refused'], static fn (array $refusal): bool => $refusal['code'] === 'FOREIGN_DOCUMENT'))[0];

    // The door, seen from the page: insert is the page's, the rest the
    // blueprint's (the default it shows), with the portal to the blueprint
    // for an actor who may update it. The default renders in the door.
    $this->assertSame('testimonial', $inner_render->slotDoor('hero', $host)->default());
    $this->assertNull($inner_render->slotFiller('hero', $frame_host, $page), 'Nothing fills yet.');
    $verdict = $stamper->verdictsOn($host, $frame_host, $hero_door);
    $this->assertSame(['content' => FALSE, 'configure' => FALSE, 'style' => FALSE, 'arrange' => FALSE, 'insert' => TRUE], $verdict['can'], 'The frame slot is the page\'s door.');
    $this->assertSame(['content', 'configure', 'style', 'arrange'], $foreign($verdict)['capabilities']);
    $this->assertSame('blueprint', $foreign($verdict)['context']['portal']['entity_type'], 'The door shows the blueprint: its portal.');
    $this->assertSame('testimonial', $foreign($verdict)['context']['blueprint']);
    $html = $render();
    $xpath = $this->domXpath($html);
    $this->assertSame(1, $xpath->query('//*[@data-layout-builder-block-uuid="' . $hero_door . '"]//*[@data-selectable="container:' . $this->blueprintSection . '"]')->length, "The default in the frame's slot: $html");
    // An editor who may not update the blueprint nor configure the frame
    // may still fill the slot: insert, no portal. (The claim copies the
    // testimonial's styles too, so the editor styles the page's elements.)
    $editor = $this->createUser([
      'administer entity_test content',
      'configure any layout',
      'style entity_test entity_test elements',
      'use daedalus ui edit mode',
    ]);
    $verdict = $stamper->verdictsOn($host, $frame_host, $hero_door, $editor);
    $this->assertTrue($verdict['can']['insert']);
    $this->assertArrayNotHasKey('portal', $foreign($verdict)['context']);
    // The aside door shows no default: the frame's portal.
    $this->assertSame(FrameInterface::ENTITY_TYPE_ID, $foreign($stamper->verdictsOn($host, $frame_host, $aside_door))['context']['portal']['entity_type']);

    // The refusals, before anything is claimed.
    $claim = fn (HostInterface $on, mixed $slot): array => $codes($this->operations()->batchOperations($on, [
      ['op' => BlueprintOperationHandler::DETACH, 'slot' => $slot],
    ]));
    $this->assertSame([['slot', 'INVALID_SLOT']], $claim($host, 'Hero Slot'));
    $this->assertSame([['slot', 'UNKNOWN_SLOT']], $claim($host, 'nowhere'));
    $this->assertSame([['slot', 'UNKNOWN_SLOT']], $claim($host, 'main'), 'The main slot is nobody\'s to claim.');
    $this->assertSame([['slot', 'SLOT_NO_DEFAULT']], $claim($host, 'aside'));
    $this->assertSame([['slot', 'UNKNOWN_SLOT']], $claim($frame_host, 'hero'), 'The frame fills no slots.');
    $this->assertSame([['slot', 'UNKNOWN_SLOT']], $claim($this->host($this->blueprint), 'hero'), 'Nor does a blueprint.');

    // The claim, as the editor: the page's own slot section, the
    // testimonial's tree with provenance, the page's name in its field.
    $this->setCurrentUser($editor);
    $result = $this->operations()->batchOperations($host, [
      ['op' => BlueprintOperationHandler::DETACH, 'slot' => 'hero'],
    ]);
    $this->assertBatchSuccess($result);
    $this->setCurrentUser($admin);
    $host->entity()->save();
    $page = $this->reload($page);
    $host = $this->host($page);
    $tree = $this->container->get('daedalus_blueprint.node_tree_builder')->build($host);
    $this->assertCount(2, $tree, 'The root section and the fill: ' . json_encode($tree));
    $this->assertSame($root, $tree[0]['uuid']);
    $fill = $tree[1];
    $this->assertSame(['hero', 'testimonial'], [$fill['slot'], $fill['blueprint']], 'A slot section with provenance.');
    $this->assertNotSame($this->blueprintSection, $fill['uuid'], 'Fresh identity.');
    $inner = $this->rootChildren([$fill]);
    $this->assertSame(['name', NULL, NULL], array_map(static fn (array $node): ?string => $node['field'] ?? NULL, $inner));
    $this->assertSame('basic', $inner[2]['block_type']);
    $this->assertContains($fill['uuid'], array_column(array_filter($result->getTargets(), static fn (array $target): bool => $target['field_name'] === TargetType::ADDED), 'uuid'), 'The response reports the section added.');
    $this->assertContains($page->uuid(), array_column(array_filter($result->getTargets(), static fn (array $target): bool => $target['field_name'] === TargetType::RESTRUCTURED), 'uuid'), 'And the root restructured.');
    $sections = $host->ownSections();
    $this->assertSame('hero', LayoutSerializer::slotOf(end($sections)));
    $filler = $inner_render->slotFiller('hero', $frame_host, $page);
    $this->assertSame([$page->getEntityTypeId(), $page->id()], [$filler->host->entityTypeId(), $filler->host->id()], 'The page fills the frame slot now.');
    $html = $render();
    $xpath = $this->domXpath($html);
    $door = $xpath->query('//*[@data-layout-builder-block-uuid="' . $hero_door . '"]')->item(0);
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . $fill['uuid'] . '"]', $door)->length, "The page's copy in the frame's slot: $html");
    $this->assertSame(0, $xpath->query('.//*[@data-selectable="container:' . $this->blueprintSection . '"]', $door)->length, 'The default no longer.');
    $this->assertStringContainsString('Claimant sentinel', $door->textContent, "The copy's field block shows the page's name.");
    // Another page of the site keeps the default.
    ['entity' => $other] = $this->createHost();
    $this->assertNull($inner_render->slotFiller('hero', $frame_host, $other));
    // The claimed door's portal is the frame's (the content shown is the
    // page's own now), and claiming again is refused.
    $verdict = $stamper->verdictsOn($host, $frame_host, $hero_door);
    $this->assertTrue($verdict['can']['insert']);
    $this->assertSame(FrameInterface::ENTITY_TYPE_ID, $foreign($verdict)['context']['portal']['entity_type']);
    $this->assertSame([['slot', 'BLUEPRINT_SLOT_DUPLICATE']], $claim($host, 'hero'));
    // The copy is a detached placement: push back reaches the blueprint.
    $this->assertBatchSuccess($this->operations()->batchOperations($host, [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => 'testimonial', 'element' => $fill['uuid']],
    ]));

    // Release: the slot section goes, the default shows again.
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'remove_element', 'element' => $fill['uuid']],
    ]);
    $this->assertBatchSuccess($result);
    $host->entity()->save();
    $page = $this->reload($page);
    $this->assertCount(1, $this->container->get('daedalus_blueprint.node_tree_builder')->build($this->host($page)), 'The fill is gone.');
    $this->assertNull($inner_render->slotFiller('hero', $frame_host, $page));
    // The default again: the blueprint's document as it now stands (the
    // push back above gave it the copy's identity).
    $current = $this->reload($this->blueprint)->get('layout_builder__layout')->getSections()[0]->getThirdPartySetting('daedalus_layout', 'uuid');
    $html = $render();
    $xpath = $this->domXpath($html);
    $door = $xpath->query('//*[@data-layout-builder-block-uuid="' . $hero_door . '"]')->item(0);
    $this->assertSame(1, $xpath->query('.//*[@data-selectable="container:' . $current . '"]', $door)->length, "The default again: $html");
    $this->assertSame(0, $xpath->query('.//*[@data-selectable="container:' . $fill['uuid'] . '"]', $door)->length);
    $this->assertSame('blueprint', $foreign($stamper->verdictsOn($this->host($page), $frame_host, $hero_door))['context']['portal']['entity_type'], 'The door shows the blueprint again.');
  }

  /**
   * A claim refuses a page-scope default: nothing lands, in or out of the slot.
   *
   * A slot fill is one section and a claim copies the default's one
   * container into it; a page blueprint is a whole page of any number of
   * roots, and a claim of one would land the first root as the slot
   * section and every following root outside the slot, at the top level of
   * the page's own document (the spill). The claim refuses it after the
   * lookup with the placement's code, BLUEPRINT_WRONG_SCOPE, the corrective
   * naming the slot block's configuration on the frame's canvas, and the
   * page's document is untouched: its own sections as before, the slot
   * still unfilled.
   */
  public function testClaimRefusesPageScopeDefault(): void {
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName('stark'));
    $this->config(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'main' => ['label' => 'Main', 'tier' => SlotRoster::TIER_FRAME],
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_FRAME],
      ])
      ->save();
    // A page blueprint of two roots: valid data at the storage door (the
    // one-root rule is block scope's), the one road that still brings
    // several roots to a slot door.
    $this->createBlueprint([
      'label' => 'Landing',
      'machine_name' => 'landing',
      'scope' => BlueprintInterface::SCOPE_PAGE,
    ], [
      $this->section('layout_onecol', [$this->pluginComponent('system_powered_by_block')]),
      $this->section('layout_onecol', [$this->pluginComponent('system_powered_by_block')]),
    ]);
    $definition = $this->container->get('daedalus_blueprint_library.discovery')->getBlueprint('landing');
    $this->assertSame(BlueprintInterface::SCOPE_PAGE, $definition['scope']);
    $this->assertCount(2, $definition['layout'], 'Two roots to spill.');
    $hero_door = $this->container->get('uuid')->generate();
    $frame = Frame::create(['id' => 'stark']);
    $frame->appendSection($this->section('layout_onecol', [
      new SectionComponent($hero_door, 'content', [
        'id' => SlotBlock::PLUGIN_ID . ':hero',
        'label_display' => '0',
        'context_mapping' => SlotBlock::CONTEXT_MAPPING,
        SlotBlock::DEFAULT_KEY => 'landing',
      ]),
    ]));
    $frame->save();
    $frame_host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($frame);
    $inner_render = $this->container->get('daedalus_blueprint.inner_render');
    ['entity' => $page, 'root_section' => $root] = $this->createHost();
    $host = $this->host($page);
    $this->assertSame('landing', $inner_render->slotDoor('hero', $host)->default());
    $this->assertNull($inner_render->slotFiller('hero', $frame_host, $page));

    $result = $this->operations()->batchOperations($host, [
      ['op' => BlueprintOperationHandler::DETACH, 'slot' => 'hero'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertCount(1, $result->getErrors());
    $error = $result->getErrors()[0];
    $this->assertSame(['slot', 'BLUEPRINT_WRONG_SCOPE'], [$error->getPath(), $error->getCode()]);
    $this->assertSame('Slot "hero" defaults to blueprint "landing", a page blueprint: a whole page is never a slot\'s default, and a claim copies one container into the slot.', $error->getMessage());
    $this->assertSame([
      'slot' => 'hero',
      'blueprint' => 'landing',
      'provider' => NULL,
      'scope' => BlueprintInterface::SCOPE_PAGE,
    ], $error->getContext());
    $this->assertStringContainsString('the block\'s configuration on the "' . $frame->label() . '" canvas', $error->getHint(), 'The corrective names where the default is set.');
    $this->assertStringContainsString('entity_type: ' . FrameInterface::ENTITY_TYPE_ID . ', entity_id: stark', $error->getHint());

    // Nothing landed: not a slot section, not a spilled root.
    $sections = $this->host($this->reload($page))->ownSections();
    $this->assertCount(1, $sections, 'The page\'s own document is unchanged.');
    $this->assertSame($root, $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertSame([], $sections[0]->getComponents());
    $this->assertNull(LayoutSerializer::slotOf($sections[0]));
    $this->assertNull($inner_render->slotFiller('hero', $frame_host, $this->reload($page)), 'The slot is still unfilled.');
  }

}
