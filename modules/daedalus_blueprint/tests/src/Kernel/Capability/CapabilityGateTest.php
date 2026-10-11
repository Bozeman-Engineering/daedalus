<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Capability;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\Capability\CapabilityResolver;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\OperationHandler\OperationHandlerInterface;
use Drupal\daedalus_blueprint\OperationHandler\OperationHandlerRegistry;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The capability gate at the op door.
 *
 * Every operation, built-in or registered, declares what it requires of
 * its targets and is judged by the capability resolver before dispatch:
 * on a plain host (a bundle whose display is Layout Builder enabled and
 * not overridable, so the page renders its template and owns only its
 * fields) every gesture at a template element, styling included, answers
 * FOREIGN_DOCUMENT while the root's own fields and style key stay open;
 * on a non-default translation the document is frozen and translatable
 * content open; an actor who may not update the host is refused; a
 * system batch is not judged; and a handler that declares nothing is
 * refused at registration.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(CapabilityResolver::class)]
#[Group('daedalus_blueprint')]
class CapabilityGateTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
   */
  protected object $operations;

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
      'daedalus_ui',
      'daedalus_layout',
      'daedalus_stylish',
      'daedalus_stylish_guide_test',
      'daedalus_stylish_unbound_test',
      'language',
    ]);
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('entity_test_mul');
    $this->installConfig(['block_content', 'language']);
    $this->installEntitySchema('daedalus_variant');
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
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->createBlockContentType('basic', 'Basic');
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test_rev', 'entity_test_rev');
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test_mul', 'entity_test_mul');
    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * A plain host: a page of a non-overridable bundle rendering its template.
   *
   * @return array{0: \Drupal\daedalus_blueprint\Host\HostInterface, 1: string, 2: string}
   *   The host, the template section's uuid and the template block's uuid.
   */
  protected function plainHost(): array {
    $uuids = new PhpUuid();
    $section_uuid = $uuids->generate();
    $block_uuid = $uuids->generate();
    $template = $this->createSection('layout_onecol', [
      ['uuid' => $block_uuid, 'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Template block']],
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', $section_uuid);
    $display = LayoutBuilderEntityViewDisplay::load('entity_test_rev.entity_test_rev.default') ?? LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->removeAllSections()->appendSection($template)->save();

    $plain = $this->createRevEntity(['name' => 'Plain']);
    $host = $this->host($plain);
    $this->assertTrue($host->hasDocument(), 'The locked bundle carries the override field; the page owns what it holds, nothing here.');
    $this->assertNotNull($this->container->get('daedalus_blueprint.host_resolver')->foreignOwner($host), 'The page renders its template.');
    return [$host, $section_uuid, $block_uuid];
  }

  /**
   * The first error's code, path and context of a failed batch.
   *
   * @return array{code: string, path: string, context: array}
   *   The error facts.
   */
  protected function firstError(BlueprintResult $result): array {
    $this->assertFalse($result->isSuccess(), 'The batch was refused.');
    $error = $result->getErrors()[0];
    return ['code' => $error->getCode(), 'path' => $error->getPath(), 'context' => $error->getContext()];
  }

  /**
   * Runs one op as a batch on a host.
   */
  protected function apply(HostInterface $host, array $op, array $options = []): BlueprintResult {
    return $this->operations->batchOperations($host, [$op], $options);
  }

  /**
   * Styling a template element from a plain host is foreign at every door.
   *
   * The ops update_style and apply_variant, the dialect's style op
   * (expanding into update_style in a nested batch) and a top-level styles
   * entry of the whole-document path all name the template section's key
   * and are refused with FOREIGN_DOCUMENT; the root's own field and its
   * own style key pass the gate.
   */
  public function testPlainHostStylingIsForeign(): void {
    [$host, $section_uuid] = $this->plainHost();
    $owner = ['entity_type' => 'entity_view_display', 'entity_id' => 'entity_test_rev.entity_test_rev.default'];

    $error = $this->firstError($this->apply($host, [
      'op' => 'update_style',
      'key' => "container:$section_uuid",
      'properties' => ['padding' => '1rem'],
    ]));
    $this->assertSame('FOREIGN_DOCUMENT', $error['code']);
    $this->assertSame('key', $error['path']);
    $this->assertSame($owner, $error['context']['owner']);
    $this->assertSame('entity_test_rev.entity_test_rev.default', $error['context']['portal']['entity_id'], 'The admin may open the template: the portal is offered.');

    $error = $this->firstError($this->apply($host, [
      'op' => 'apply_variant',
      'key' => "container:$section_uuid",
      'variant' => 'band',
    ]));
    $this->assertSame('FOREIGN_DOCUMENT', $error['code']);
    $this->assertSame('key', $error['path']);

    // A rule key under the element is the element's too.
    $error = $this->firstError($this->apply($host, [
      'op' => 'update_style',
      'key' => "container:$section_uuid::> *",
      'properties' => ['gap' => '1rem'],
    ]));
    $this->assertSame('FOREIGN_DOCUMENT', $error['code']);

    $catalog = new DialectCatalog(containerLayoutId: 'layout_onecol', axes: [], blockTypes: [], conditions: []);
    $result = $this->apply($host, [
      'op' => 'style',
      'css' => "#$section_uuid { padding: 1rem; }",
    ], ['dialect_catalog' => $catalog, 'conversation' => 'c1']);
    $this->assertFalse($result->isSuccess());
    $this->assertContains('FOREIGN_DOCUMENT', array_map(static fn ($error) => $error->getCode(), $result->getErrors()), 'The dialect op is judged through the spine ops it expands into.');

    $deserializer = $this->container->get('daedalus_blueprint.deserializer');
    $result = $deserializer->deserialize([
      'entity_type' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      '_root_entity' => ['field' => 'id', 'value' => (int) $host->id()],
      'fields' => [],
      'styles' => ["container:$section_uuid" => ['default' => ['padding' => '1rem']]],
    ]);
    $error = $this->firstError($result);
    $this->assertSame('FOREIGN_DOCUMENT', $error['code']);
    $this->assertSame("styles.container:$section_uuid", $error['path']);

    // The root is the page's own: its fields and its own style key.
    $result = $this->apply($host, [
      'op' => 'update_element',
      'element' => $host->uuid(),
      'properties' => ['name' => 'Renamed'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('Renamed', $host->fields()->label());

    $result = $this->apply($host, [
      'op' => 'apply_variant',
      'key' => 'entity:' . $host->uuid(),
      'variant' => 'no_such_variant',
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertNotSame('FOREIGN_DOCUMENT', $result->getErrors()[0]->getCode(), 'The door opened: the style handler itself answers for the root key.');
    $this->assertSame('semantic', $result->getPhase());
  }

  /**
   * Structure on a plain host belongs to the template, at every door.
   *
   * An insert anchored on a template element, beside one (the destination
   * is the root flow, the template's), a move, a removal and the
   * whole-document path's layout key are refused with FOREIGN_DOCUMENT; an
   * actor who may not open the template gets the owner as the cause but no
   * portal.
   */
  public function testPlainHostStructureIsForeign(): void {
    [$host, $section_uuid, $block_uuid] = $this->plainHost();

    $error = $this->firstError($this->apply($host, [
      'op' => 'add_element',
      'block_type' => 'basic',
      'location' => ['anchor' => $section_uuid, 'placement' => 'inside'],
    ]));
    $this->assertSame(['FOREIGN_DOCUMENT', 'location.anchor'], [$error['code'], $error['path']]);

    $error = $this->firstError($this->apply($host, [
      'op' => 'add_section',
      'location' => ['anchor' => $section_uuid, 'placement' => 'after'],
    ]));
    $this->assertSame(['FOREIGN_DOCUMENT', 'location.anchor'], [$error['code'], $error['path']], 'Beside a top-level template section is the root flow, which the template owns.');

    $error = $this->firstError($this->apply($host, [
      'op' => 'add_section',
      'location' => ['anchor' => $host->uuid(), 'placement' => 'inside'],
    ]));
    $this->assertSame(['FOREIGN_DOCUMENT', 'location.anchor'], [$error['code'], $error['path']]);

    $error = $this->firstError($this->apply($host, [
      'op' => 'move_element',
      'element' => $block_uuid,
      'location' => ['anchor' => $section_uuid, 'placement' => 'inside'],
    ]));
    $this->assertSame(['FOREIGN_DOCUMENT', 'element'], [$error['code'], $error['path']]);

    $error = $this->firstError($this->apply($host, [
      'op' => 'update_element',
      'element' => $block_uuid,
      'properties' => ['label' => 'Mine now'],
    ]));
    $this->assertSame(['FOREIGN_DOCUMENT', 'element'], [$error['code'], $error['path']]);

    $deserializer = $this->container->get('daedalus_blueprint.deserializer');
    $error = $this->firstError($deserializer->deserialize([
      'entity_type' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      '_root_entity' => ['field' => 'id', 'value' => (int) $host->id()],
      'fields' => [],
      'layout' => [['layout_id' => 'layout_onecol', 'regions' => []]],
    ]));
    $this->assertSame(['FOREIGN_DOCUMENT', 'layout'], [$error['code'], $error['path']]);

    // An editor who may update the page but not the template: the cause
    // rides, the portal does not.
    $editor = $this->createUser(['administer entity_test content']);
    $error = $this->firstError($this->apply($host, [
      'op' => 'remove_element',
      'element' => $block_uuid,
    ], ['actor' => $editor]));
    $this->assertSame('FOREIGN_DOCUMENT', $error['code']);
    $this->assertSame('entity_test_rev.entity_test_rev.default', $error['context']['owner']['entity_id']);
    $this->assertArrayNotHasKey('portal', $error['context']);
  }

  /**
   * A non-default translation freezes the document and keeps content open.
   */
  public function testTranslationFreezesTheDocument(): void {
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->enableLayoutBuilder('entity_test_mul', 'entity_test_mul');
    $uuids = new PhpUuid();
    $first_uuid = $uuids->generate();
    $second_uuid = $uuids->generate();
    $first = $this->createSection('layout_onecol');
    $first->setThirdPartySetting('daedalus_layout', 'uuid', $first_uuid);
    $second = $this->createSection('layout_onecol');
    $second->setThirdPartySetting('daedalus_layout', 'uuid', $second_uuid);
    $entity = $this->entityTypeManager->getStorage('entity_test_mul')->create([
      'name' => 'Host',
      'langcode' => 'en',
      'layout_builder__layout' => [$first, $second],
    ]);
    $entity->addTranslation('es', ['name' => 'Anfitrión']);
    $entity->save();
    $host = $this->host($entity);

    $error = $this->firstError($this->apply($host, [
      'op' => 'move_element',
      'element' => $first_uuid,
      'location' => ['anchor' => $second_uuid, 'placement' => 'after'],
    ], ['langcode' => 'es']));
    $this->assertSame(['LAYOUT_NOT_TRANSLATABLE', 'element'], [$error['code'], $error['path']]);

    $error = $this->firstError($this->apply($host, [
      'op' => 'update_style',
      'key' => "container:$first_uuid",
      'properties' => ['padding' => '1rem'],
    ], ['langcode' => 'es']));
    $this->assertSame(['LAYOUT_NOT_TRANSLATABLE', 'key'], [$error['code'], $error['path']]);

    $result = $this->apply($host, [
      'op' => 'update_element',
      'element' => $entity->uuid(),
      'properties' => ['name' => 'Anfitriona'],
    ], ['langcode' => 'es']);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('Anfitriona', $entity->getTranslation('es')->label());
    $this->assertSame('Host', $entity->label(), 'The default translation keeps its own value.');

    // The same move through the default translation lands.
    $result = $this->apply($host, [
      'op' => 'move_element',
      'element' => $first_uuid,
      'location' => ['anchor' => $second_uuid, 'placement' => 'after'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
  }

  /**
   * An actor without update access is refused; a system batch is not judged.
   *
   * The actor is the batch's: the current user by default, the `actor`
   * option when the caller names one, so a batch run for an admin passes
   * where the restricted current user is refused; a system batch (the
   * seed, an importer) is not judged at all.
   */
  public function testActorAndSystemPosture(): void {
    $entity = $this->createTestEntity(['name' => 'Mine']);
    $host = $this->host($entity);
    $admin = $this->createUser([], NULL, TRUE);
    $this->setUpCurrentUser([], []);
    $op = ['op' => 'update_element', 'element' => $entity->uuid(), 'properties' => ['name' => 'Taken']];

    $error = $this->firstError($this->apply($host, $op));
    $this->assertSame('ACCESS_DENIED_ENTITY', $error['code']);
    $this->assertSame('Mine', $entity->label());

    $result = $this->apply($host, $op, ['actor' => $admin]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('Taken', $entity->label());

    $result = $this->apply($host, [
      'op' => 'update_element',
      'element' => $entity->uuid(),
      'properties' => ['name' => 'Seeded'],
    ], ['system' => TRUE]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('Seeded', $entity->label());

    // The whole-document path is the same door.
    $deserializer = $this->container->get('daedalus_blueprint.deserializer');
    $blueprint = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => ['name' => 'Document'],
    ];
    $this->assertSame('ACCESS_DENIED_ENTITY', $this->firstError($deserializer->deserialize($blueprint))['code']);
    $this->assertTrue($deserializer->deserialize($blueprint, ['actor' => $admin])->isSuccess());
    $this->assertTrue($deserializer->deserialize($blueprint, ['system' => TRUE])->isSuccess());
  }

  /**
   * A handler that provides an operation without declaring its requirements is refused.
   */
  public function testHandlerWithoutDeclarationFailsRegistration(): void {
    $registry = new OperationHandlerRegistry();
    $handler = new class() implements OperationHandlerInterface {

      /**
       * {@inheritdoc}
       */
      public function getOperations(): array {
        return ['undeclared_op'];
      }

      /**
       * {@inheritdoc}
       */
      public function getRequirements(): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function apply(HostInterface $host, array $operation, array $options = []): BlueprintResult {
        return BlueprintResult::success($host);
      }

      /**
       * {@inheritdoc}
       */
      public function getGuidance(): array {
        return [];
      }

    };
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('"undeclared_op" without declaring what it requires');
    $registry->addHandler($handler);
  }

}
