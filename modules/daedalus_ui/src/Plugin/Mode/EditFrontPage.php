<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Plugin\Mode;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_ui\ModePluginBase;
use Drupal\daedalus_ui\Attribute\Mode;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\daedalus_ui\ToolPluginManager;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Edit mode front page plugin.
 *
 * Modes use cookies to improve performance. Cookies on the / path are
 * problematic because they apply to the whole site. Let's make the edit_mode
 * button a link when it appears on the / path that points to the page
 * responsible for the front page, like /node/1
 */
#[Mode(
  id: 'edit_front_page',
  label: new TranslatableMarkup('Edit Mode'),
  weight: 100,
)]
final class EditFrontPage extends ModePluginBase {

  use StringTranslationTrait;

  /**
   * The entity being edited.
   */
  public EntityInterface $entity;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('extension.list.module'),
      $container->get('event_dispatcher'),
      $container->get('plugin.manager.tools'),
      $container->get('current_route_match'),
      $container->get('daedalus_ui.ui'),
      $container->get('request_stack'),
    );
  }

  public function __construct(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ModuleExtensionList $extensionList,
    protected EventDispatcherInterface $eventDispatcher,
    protected ToolPluginManager $toolManager,
    protected RouteMatchInterface $routeMatch,
    protected DaedalusUi $ui,
    private readonly RequestStack $requestStack,
  ) {
    parent::__construct($container, $configuration, $plugin_id, $plugin_definition, $this->extensionList, $ui);
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    $path = $this->requestStack->getCurrentRequest()->getPathInfo();
    $entity = $this->ui->deriveEntityFromRoute();
    return !empty($entity) && $path === '/';
  }

  /**
   * {@inheritdoc}
   */
  public function getIconPath(): string {
    $path = $this->extensionList->getPath('daedalus_ui');
    return "/$path/assets/code.svg";
  }

  /**
   * {@inheritdoc}
   */
  public function buildModeButton(): array {
    $entity = $this->daedalusUi->deriveEntityFromRoute();
    $mode_state = $this->ui->getMode();
    $mode_id = $this->getPluginId();
    $url = $entity->toUrl();
    return [
      '#type' => 'inline_template',
      '#template' => "<a data-mode='{{mode_id}}' data-daedalus-ui-tooltip='{{mode}}' class='daedalus-ui-mode-link toolbar-button toolbar-button--primary{{toolbar_state}}' data-index-text='0' data-icon-text='{{icon_text}}' href='{{url}}' onclick='Drupal.Daedalus.setCookieValue(\"navigationMode\", \"edit\", { path: \"{{url}}\" })'>{{ icon('daedalus_ui', icon_id, { class: 'toolbar-button__icon', size: 20 }) }}<span class='toolbar-button__label'>{{label}}</span></a>",
      '#context' => [
        'icon_id' => basename($this->getIconPath(), '.svg'),
        'mode_id' => $mode_id,
        'label' => $this->label(),
        'icon_text' => $this->label(),
        'toolbar_state' => $mode_state === $mode_id ? ' active' : '',
        'mode' => $this->t('@label mode', ['@label' => $this->label()]),
        'url' => $url,
      ],
      '#cache' => ['contexts' => ['url.path']],
    ];
  }

}
