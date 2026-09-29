<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai\Render\HeadlessRendererInterface;
use Drupal\daedalus_ai\Render\HeadlessRenderException;
use Drupal\daedalus_ai\Render\StateVector;
use Drupal\daedalus_ai\Render\ToolImagesResult;
use Drupal\daedalus_blueprint_ai\ConversationContext;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\daedalus_ui\File\FileLaneException;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Exception\UndefinedLinkTemplateException;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the page at a canvas state vector and screenshots it back.
 *
 * The verify half of render-and-verify: after styling or responsive work,
 * the model poses the same state vector the human canvas chips address
 * (?canvasWidth/?canvasScheme/?canvasMotion) and looks at the actual
 * pixels instead of trusting that plausible CSS is correct CSS. The page
 * loads headlessly in Edit Mode as the current editor (same session), so
 * staged, unsaved changes are what get rendered.
 *
 * The shot reaches the model twice over: once as bytes on the next turn
 * (the ai_agents image contract) and once as a handle. It is saved through
 * the one file lane and held by the attachment store for the conversation
 * under `render-<entity type>-<entity id>[-<pose>].png`, the name the text
 * result reports, so after the memory runner strips the bytes the model
 * can still re-open it with view_file, and a later turn can compare a new
 * render against it.
 */
#[FunctionCall(
  id: 'daedalus_ai:render_page',
  function_name: 'render_page',
  name: 'Render Page',
  description: 'Renders an entity\'s page in a real headless browser and returns a screenshot for you to inspect. The page loads in Edit Mode as the current editor, so your staged (unsaved) changes are included. Pose the canvas state vector to verify responsive and styling work: canvas_width narrows the canvas so container-breakpoint conditions (narrow/medium) re-evaluate, canvas_scheme forces light/dark, canvas_motion forces reduced motion. The screenshot arrives as an image attached to your next turn; ALWAYS look at it before declaring visual work done. It is also kept under the name the result reports, so view_file(name) re-opens it later. Render once per state you changed styles for (e.g. once at full width for the base look, once at canvas_width=896 for medium, once at canvas_width=375 for narrow behavior).',
  group: 'information_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: TRUE,
      description: new TranslatableMarkup('The entity type ID of the page to render (e.g. node).'),
    ),
    'entity_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity ID'),
      required: TRUE,
      description: new TranslatableMarkup('The entity ID of the page to render.'),
    ),
    'canvas_width' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Canvas width'),
      required: FALSE,
      description: new TranslatableMarkup('Canvas width in CSS pixels (240-10000), or "full" (default) for the full viewport. Use e.g. 375 to check narrow-container behavior and 896 for medium.'),
    ),
    'canvas_scheme' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Color scheme'),
      required: FALSE,
      description: new TranslatableMarkup('Color scheme to force: "auto" (default, follow the OS), "light", or "dark".'),
    ),
    'canvas_motion' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Motion'),
      required: FALSE,
      description: new TranslatableMarkup('Motion preference to force: "auto" (default, follow the OS), "full", or "reduce".'),
    ),
  ],
)]
final class RenderPage extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The request stack, source of the editor's session cookie.
   */
  protected RequestStack $requestStack;

  /**
   * The headless renderer.
   */
  protected HeadlessRendererInterface $renderer;

  /**
   * The file lane the shot is saved through.
   */
  protected FileLane $fileLane;

  /**
   * The attachment store that holds the shot for the conversation.
   */
  protected Attachments $attachments;

  /**
   * The conversation the shot is held for.
   */
  protected ConversationContext $conversationContext;

  /**
   * The screenshot result, delivered via the ai_agents image contract.
   */
  protected ?ToolImagesResult $result = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
      $container->get('plugin.manager.ai_data_type_converter'),
    );
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->requestStack = $container->get('request_stack');
    $instance->renderer = $container->get('daedalus_ai.headless_renderer');
    $instance->fileLane = $container->get('daedalus_ui.file_lane');
    $instance->attachments = $container->get('daedalus_ai.attachments');
    $instance->conversationContext = $container->get('daedalus_blueprint_ai.conversation_context');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $entity_type = $this->getContextValue('entity_type');
    $entity_id = $this->getContextValue('entity_id');

    try {
      $vector = StateVector::fromToolInput(
        $this->getContextValue('canvas_width') ?: NULL,
        $this->getContextValue('canvas_scheme') ?: NULL,
        $this->getContextValue('canvas_motion') ?: NULL,
      );
    }
    catch (\InvalidArgumentException $e) {
      $this->setOutput($e->getMessage());
      return;
    }

    $storage = $this->entityTypeManager->hasDefinition($entity_type)
      ? $this->entityTypeManager->getStorage($entity_type)
      : NULL;
    if (!$storage) {
      $this->setOutput("Unknown entity type: $entity_type.");
      return;
    }
    $entity = $storage->load($entity_id);
    if (!$entity) {
      $this->setOutput("Entity $entity_type:$entity_id not found.");
      return;
    }
    if (!$entity->access('view')) {
      $this->setOutput("Access denied for 'view' on $entity_type:$entity_id.");
      return;
    }

    try {
      $url = $entity->toUrl('canonical', [
        'absolute' => TRUE,
        'query' => $vector->toQuery(),
      ])->toString();
    }
    catch (UndefinedLinkTemplateException | EntityMalformedException) {
      $this->setOutput("$entity_type:$entity_id has no page of its own. Render the entity whose page hosts it (usually the current node).");
      return;
    }

    // The headless browser replicates the editor's browsing context: their
    // session cookie (staged tempstore edits are keyed to it) plus the
    // edit-mode cookie daedalus_ui renders server-side chrome from.
    // The cookie travels only to the WebDriver endpoint and back to this
    // site; it is never part of the model-visible output.
    $request = $this->requestStack->getCurrentRequest();
    $session = $request?->hasSession() ? $request->getSession() : NULL;
    if (!$session || $session->getId() === '') {
      $this->setOutput('Rendering requires an authenticated editor session; this tool only works from the page builder chat.');
      return;
    }
    $cookies = [
      ['name' => $session->getName(), 'value' => $session->getId()],
      ['name' => 'navigationMode', 'value' => 'edit'],
    ];

    try {
      $shot = $this->renderer->render($url, $cookies, $vector->width);
    }
    catch (HeadlessRenderException $e) {
      $this->setOutput('Render failed: ' . $e->getMessage());
      return;
    }

    $name = "render-$entity_type-$entity_id" . ($vector->slug() === '' ? '' : '-' . $vector->slug()) . '.png';
    $this->result = new ToolImagesResult([
      new ImageFile($shot['png'], 'image/png', $name),
    ]);
    $lines = [
      "Rendered $url at " . $vector->describe() . '.',
      'The screenshot (' . $shot['width'] . 'x' . $shot['height'] . ' pixels) arrives as an image on your next turn. Inspect it before declaring visual work done.',
    ];
    if ($shot['truncated']) {
      $lines[] = 'Note: the page is taller than the capture ceiling; the screenshot is cut at the bottom.';
    }
    $lines[] = $this->keep($name, $shot['png']);
    $this->setOutput(implode(' ', $lines));
  }

  /**
   * Saves the shot through the lane and holds it for the conversation.
   *
   * @return string
   *   The sentence naming the held file, or why it was not kept: the model
   *   still receives the bytes on the next turn either way, so a shot that
   *   cannot be kept is reported, not lost.
   */
  protected function keep(string $name, string $png): string {
    $thread = $this->conversationContext->get();
    if ($thread === '') {
      return 'It was not kept for later: this run has no conversation to hold it.';
    }
    try {
      $file = $this->fileLane->fromBytes($name, 'image/png', $png, Attachments::DIRECTORY);
    }
    catch (FileLaneException $e) {
      return 'It was not kept for later: ' . $e->getMessage();
    }
    $held = $this->attachments->attach($file, $thread);
    return "It is kept as \"$held\"; view_file(\"$held\") re-opens it later.";
  }

  /**
   * Half of ai_agents' duck-typed tool image contract.
   *
   * @see \Drupal\daedalus_ai\Render\ToolImagesResult
   */
  public function getPluginInstance(): static {
    return $this;
  }

  /**
   * The other half: the result whose _images reach the model.
   *
   * @see \Drupal\daedalus_ai\Render\ToolImagesResult
   */
  public function getResult(): ?ToolImagesResult {
    return $this->result;
  }

}
