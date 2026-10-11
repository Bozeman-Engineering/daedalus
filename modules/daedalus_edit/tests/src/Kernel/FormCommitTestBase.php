<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\daedalus_blueprint_api\Ajax\BlueprintApplyResponseCommand;
use Drupal\daedalus_layout\NestedAwareSectionStorage;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * The fixture and the programmatic-submit recipe of the form transport's tests.
 *
 * A plain node bundle (no Layout Builder, translatable) for the panel's
 * entity form, and a page bundle with a Layout Builder override holding one
 * inline form_block placement in a uuid-stamped section for the panel's
 * block form and the Configure dialogs. Forms are submitted through the
 * real form builder, so every site alter, widget extraction and validation
 * runs, and their AJAX callback is played exactly as FormAjaxResponseBuilder
 * does.
 */
abstract class FormCommitTestBase extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The daedalus_ui hard dependencies are invisible to kernel environments
   * and must be listed explicitly (see PageTitleEditingTest); daedalus_layout
   * owns the nested section storage the block form requires;
   * content_translation makes the node bundle translatable for the
   * translation leg.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'editor',
    'node',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'language',
    'content_translation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'field_sample_value',
    'daedalus_edit',
    'daedalus_layout',
  ];

  /**
   * The component UUID of the inline block fixture.
   */
  protected const BLOCK_UUID = 'faf95c53-35e7-74e1-184e-041152031df4';

  /**
   * The daedalus_layout uuid of the section holding the inline block.
   */
  protected const SECTION_UUID = '2d1f0a4e-6b7c-4d8e-9f01-23456789abcd';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('block_content');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    // system: the whole-entity render behind the mutation response prints
    // the node's created date through the system date formats. language:
    // the configurable language the translation leg adds.
    $this->installConfig(['system', 'language', 'block_content']);
    ConfigurableLanguage::createFromLangcode('es')->save();

    // The root form subject: a bundle without Layout Builder, translatable.
    // Every bundle gets a form display naming its widgets: a runtime display
    // hides configurable fields by default, and a field with no widget is
    // not on the form at all.
    NodeType::create(['type' => 'plain', 'name' => 'Plain'])->save();
    $this->createStringField('node', 'plain', 'field_subtitle', 'Subtitle');
    $this->formDisplay('node', 'plain', ['title', 'field_subtitle']);
    $this->container->get('content_translation.manager')->setEnabled('node', 'plain', TRUE);

    // The block form subject: an inline block placed in a Layout Builder
    // override on a second bundle.
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    BlockContentType::create(['id' => 'form_block', 'label' => 'Form block', 'revision' => FALSE])->save();
    $this->createStringField('block_content', 'form_block', 'field_fb_text', 'Text');
    $this->createStringField('block_content', 'form_block', 'field_fb_other', 'Other');
    $this->formDisplay('block_content', 'form_block', ['info', 'field_fb_text', 'field_fb_other']);

    // The editor, created after the bundles so their per-bundle permissions
    // exist: the inline-editing permission gates the forms' route and the H1
    // attribution; the edit-mode permission stamps the placements'
    // selectable keys on the response render (daedalus_layout's
    // LayoutBuilderBlockAttributes). What the forms write is judged at the
    // op door by the capability resolver: update access on the host (node
    // edit), the inline block's fields behind core's `create and edit
    // custom blocks`, its label behind core's override permission for the
    // page bundle (the role policy).
    $this->setUpCurrentUser([], [
      'access inline editing',
      'use daedalus ui edit mode',
      'access content',
      'edit any plain content',
      'edit any page content',
      'create and edit custom blocks',
      'configure editable page node layout overrides',
    ]);
  }

  /**
   * Saves a bundle's default form display with string widgets for the fields.
   */
  protected function formDisplay(string $entity_type, string $bundle, array $field_names): void {
    $display = EntityFormDisplay::create([
      'targetEntityType' => $entity_type,
      'bundle' => $bundle,
      'mode' => 'default',
      'status' => TRUE,
    ]);
    foreach ($field_names as $field_name) {
      $display->setComponent($field_name, ['type' => 'string_textfield']);
    }
    $display->save();
  }

  /**
   * Creates a single-value string field on a bundle.
   */
  protected function createStringField(string $entity_type, string $bundle, string $field_name, string $label): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $label,
    ])->save();
  }

  /**
   * The saved plain node the root-form legs edit.
   */
  protected function createPlainNode(): NodeInterface {
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Saved',
      'field_subtitle' => 'Sub',
      'langcode' => 'en',
    ]);
    $node->save();
    return $node;
  }

  /**
   * The saved page node holding one inline form_block placement.
   *
   * @param string $layout_id
   *   The section's layout plugin.
   * @param string $region
   *   The region the block is placed in.
   */
  protected function createPageNode(string $layout_id = 'layout_onecol', string $region = 'content'): NodeInterface {
    $block = BlockContent::create([
      'type' => 'form_block',
      'reusable' => FALSE,
      'info' => 'My Block',
      'field_fb_text' => 'Original',
      'field_fb_other' => 'Keep',
    ]);
    $component = new SectionComponent(self::BLOCK_UUID, $region, [
      'id' => 'inline_block:form_block',
      'provider' => 'layout_builder',
      'label' => 'My Block',
      'label_display' => 'visible',
      'view_mode' => 'full',
      'block_serialized' => serialize($block),
    ]);
    $section = new Section($layout_id);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', self::SECTION_UUID);
    $section->appendComponent($component);
    $node = Node::create([
      'type' => 'page',
      'title' => 'Page',
      'langcode' => 'en',
      OverridesSectionStorage::FIELD_NAME => [$section],
    ]);
    $node->save();
    return $node;
  }

  /**
   * The node's working copy, as the storage handler serves it.
   */
  protected function working(NodeInterface $node): NodeInterface {
    /** @var \Drupal\node\NodeInterface */
    return $this->workingHost($node)->entity();
  }

  /**
   * The node's working copy's host, as the storage handler serves it.
   */
  protected function workingHost(NodeInterface $node): HostInterface {
    return $this->container->get('daedalus_blueprint.storage_handler')->load('node', $node->id());
  }

  /**
   * The node's stored row, with no static-cache residue.
   */
  protected function stored(NodeInterface $node): NodeInterface {
    /** @var \Drupal\node\NodeInterface */
    return $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
  }

  /**
   * The section as the working copy carries it.
   */
  protected function workingSection(NodeInterface $node): Section {
    return $this->working($node)->get(OverridesSectionStorage::FIELD_NAME)->get(0)->section;
  }

  /**
   * The inline block's component as the working copy carries it.
   */
  protected function workingComponent(NodeInterface $node): SectionComponent {
    return $this->workingSection($node)->getComponent(self::BLOCK_UUID);
  }

  /**
   * The inline block as the working copy's component carries it.
   */
  protected function workingBlock(NodeInterface $node): BlockContent {
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    return unserialize($this->workingComponent($node)->get('configuration')['block_serialized']);
  }

  /**
   * The block form's storage: the tempstore-tracked override, nested-aware.
   */
  protected function blockFormStorage(NodeInterface $node): NestedSectionStorageInterface {
    $storage = $this->container->get('plugin.manager.layout_builder.section_storage')->load('overrides', [
      'entity' => EntityContext::fromEntity($node),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ]);
    $storage = $this->container->get('layout_builder.tempstore_repository')->get($storage);
    if (!$storage instanceof NestedSectionStorageInterface) {
      $storage = NestedAwareSectionStorage::wrap($storage);
    }
    return $storage;
  }

  /**
   * A request shaped like a panel form's AJAX submit, as the client sends it.
   *
   * The form was fetched through ModePluginBase.ajax() with the mode on the
   * query, and core's #ajax keeps that query on every submit; the route
   * carries the subject's root. Pushed on the stack for the whole round (form
   * build, submit, callback), since the inline-editing alter and the
   * response render both read the mode from it.
   */
  protected function formRequest(NodeInterface $node, string $path): Request {
    $request = $this->editModeRequest($path, ['viewMode' => 'default']);
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'daedalus_edit.entity_form');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/daedalus-edit/entity-form/{entity_type}/{entity}'));
    $request->attributes->set('entity_type', 'node');
    $request->attributes->set('entity', $node);
    $request->attributes->set('_raw_variables', new InputBag(['entity_type' => 'node', 'entity' => $node->id()]));
    return $request;
  }

  /**
   * An edit-mode AJAX form submit request with no route yet.
   */
  protected function editModeRequest(string $path, array $post = []): Request {
    $separator = str_contains($path, '?') ? '&' : '?';
    return Request::create($path . $separator . 'navigationMode=edit&ajax_form=1&_wrapper_format=drupal_ajax', 'POST', $post);
  }

  /**
   * Submits a form programmatically and plays its AJAX callback.
   *
   * FormBuilder::submitForm() runs the real pipeline (alters, widget
   * extraction, validation, submit handlers); the triggering element's
   * #ajax callback is then invoked exactly as FormAjaxResponseBuilder does.
   *
   * @param object|string $form_arg
   *   The form object or class.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state carrying the values (the Update button selected by
   *   its value) and build args.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request the round runs under.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The callback's answer.
   */
  protected function submitAndAnswer(object|string $form_arg, FormStateInterface $form_state, Request $request): AjaxResponse {
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $this->container->get('form_builder')->submitForm($form_arg, $form_state);
      $form = $form_state->getCompleteForm();
      $triggering = $form_state->getTriggeringElement();
      $this->assertNotNull($triggering, 'The Update button triggered the submit.');
      $callback = $form_state->prepareCallback($triggering['#ajax']['callback']);
      $response = call_user_func_array($callback, [&$form, &$form_state, $request]);
    }
    finally {
      $request_stack->pop();
      $this->container->get('daedalus_ui.ui')->clearDerivedHost();
    }
    $this->assertInstanceOf(AjaxResponse::class, $response);
    return $response;
  }

  /**
   * The one apply-response command's payload, asserting it is the only command.
   */
  protected function appliedPayload(AjaxResponse $response): array {
    $commands = $response->getCommands();
    $this->assertCount(1, $commands, 'The transport answers exactly one command.');
    $this->assertSame(BlueprintApplyResponseCommand::COMMAND, $commands[0]['command']);
    return $commands[0]['payload'];
  }

  /**
   * The plain node's entity form, built for the given entity object.
   */
  protected function nodeFormState(NodeInterface $entity, array $values): array {
    $form_object = $this->container->get('entity_type.manager')->getFormObject('node', 'default');
    $form_object->setEntity($entity);
    $form_state = (new FormState())->setFormState(['daedalus_edit_form' => TRUE]);
    $form_state->setValues($values + ['op' => 'Update']);
    return [$form_object, $form_state];
  }

}
