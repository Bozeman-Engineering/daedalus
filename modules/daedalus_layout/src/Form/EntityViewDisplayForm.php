<?php

namespace Drupal\daedalus_layout\Form;

use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Class for altering Entity View Display Form.
 */
class EntityViewDisplayForm implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Implements hook_form_FORM_ID_alter().
   */
  public function formAlter(&$form, FormStateInterface $form_state) {
    $entity_view_display_id = $form_state->getFormObject()->getEntity()->id();

    $form['daedalus_layout_buttons'] = [
      '#type' => 'container',
      '#weight' => -9,
      '#attributes' => ['class' => ['display-inline']],
      '#access' => !empty($form['layout']['allow_custom']['#default_value']),
      // Move the manage layout button here.
      'manage_layout' => $form['manage_layout'],
      'configure_default_layout_section' => [
        '#type' => 'link',
        '#title' => $this->t('Configure default layout container'),
        '#attributes' => ['class' => ['button']],
        '#url' => Url::fromRoute('daedalus_layout.settings.configure_default_section', ['entity' => $entity_view_display_id]),
      ],
      'promoted_blocks' => [
        '#type' => 'link',
        '#title' => $this->t('Promoted Blocks'),
        '#attributes' => ['class' => ['button']],
        '#url' => Url::fromRoute('daedalus_layout.settings.promoted_blocks', ['entity' => $entity_view_display_id]),
      ],
    ];
    $form['manage_layout']['#access'] = empty($form['layout']['allow_custom']['#default_value']);
  }

  /**
   * Implements hook_system_breadcrumb_alter().
   */
  public function breadCrumbAlter(Breadcrumb &$breadcrumb, RouteMatchInterface $route_match, array $context) {
    $settings_routes = [
      'daedalus_layout.settings.configure_default_section',
      'daedalus_layout.settings.promoted_blocks',
    ];
    if (in_array($route_match->getRouteName(), $settings_routes)) {
      $entity = $route_match->getParameter('entity');
      $entity_type = $this->entityTypeManager->getStorage($this->entityTypeManager->getDefinition($entity->getTargetEntityTypeId())->getBundleEntityType())->load($entity->getTargetBundle());
      $link = Link::createFromRoute($entity_type->label(), "entity.entity_view_display.{$entity->getTargetEntityTypeId()}.default", [$entity_type->getEntityTypeId() => $entity_type->id()]);
      $breadcrumb->addLink($link);
    }
  }

}
