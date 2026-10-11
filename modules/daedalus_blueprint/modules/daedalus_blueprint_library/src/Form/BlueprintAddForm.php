<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint_library\BlueprintInterface;
use Drupal\daedalus_blueprint_library\BlueprintSubjects;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The "Add blueprint" door: compose a saved blueprint from scratch.
 *
 * The admin listing's other way into direct editing (capture from a page
 * is the first): the type is picked first, on core's add page (one card
 * per blueprint type the actor may create, skipped when there is one),
 * which lands here with the entity born as that type; the form takes the
 * four facts a blueprint needs before it has a document (label,
 * description, scope, subject), saves the entity with an empty document
 * and lands on its canvas, where the document is composed like any
 * page's. Nothing else is asked here: the machine name derives from the
 * label, the icon, the AI opt-out, the allow-list and publication are the
 * details form's, and a blueprint composed by a person is minted `user`.
 *
 * The subject is picked from the site's page-builder bundles
 * (BlueprintSubjects::options()); the entity's presave re-checks it, so
 * the select is convenience over one rule, not a second gate. The landing
 * enters Edit Mode the way daedalus_ui lands a created page: the
 * path-scoped navigationMode cookie queued for the redirect response
 * through daedalus_ui's one door (ModeState::queueModeCookie).
 */
class BlueprintAddForm extends ContentEntityForm {

  /**
   * The mode state of daedalus_ui, or NULL without daedalus_ui.
   *
   * Untyped on purpose: this module does not depend on daedalus_ui.
   *
   * @var \Drupal\daedalus_ui\ModeState|null
   */
  protected readonly ?object $modeState;

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected BlueprintSubjects $subjects,
    ?object $mode_state = NULL,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->modeState = $mode_state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('daedalus_blueprint_library.subjects'),
      $container->get('daedalus_ui.mode_state', ContainerInterface::NULL_ON_INVALID_REFERENCE),
    );
  }

  /**
   * {@inheritdoc}
   *
   * The four facts, by hand: the form display would offer every detail
   * (subject as two text fields, the allow-list, the AI opt-out, status),
   * and composing from scratch needs none of them yet.
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $options = $this->subjects->options();
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#description' => $this->t('How editors find it in the Add panel.'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#description' => $this->t('What it is and when to reach for it; the AI reads this when deciding what to place.'),
      '#rows' => 3,
      '#required' => TRUE,
    ];
    $form['scope'] = [
      '#type' => 'select',
      '#title' => $this->t('Scope'),
      '#description' => $this->t("What the blueprint stands in for: a block (one container placed into a page) or a page (a whole page's document). Identity: it cannot change later."),
      '#options' => [
        BlueprintInterface::SCOPE_BLOCK => $this->t('Block'),
        BlueprintInterface::SCOPE_PAGE => $this->t('Page'),
      ],
      '#default_value' => BlueprintInterface::SCOPE_BLOCK,
      '#required' => TRUE,
    ];
    $form['subject'] = [
      '#type' => 'select',
      '#title' => $this->t('Subject'),
      '#description' => $this->t('The kind of page the blueprint is material for: its canvas renders against a sample of this bundle, and its field blocks name that bundle\'s fields.'),
      '#options' => $options,
      '#required' => TRUE,
      '#empty_option' => $options === [] ? $this->t('- No page-builder bundle on this site -') : $this->t('- Select -'),
      '#disabled' => $options === [],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    assert($entity instanceof BlueprintInterface);
    [$entity_type_id, $bundle] = explode(':', (string) $form_state->getValue('subject'), 2) + [1 => ''];
    $entity->set('label', trim((string) $form_state->getValue('label')));
    $entity->setDescription(trim((string) $form_state->getValue('description')));
    $entity->set('scope', (string) $form_state->getValue('scope'));
    $entity->setSubject($entity_type_id, $bundle);
    $entity->setOrigin(MaterialRecord::ORIGIN_USER);
  }

  /**
   * {@inheritdoc}
   *
   * The entity's own constraints, flagged on this form's four elements:
   * the base form flags violations through the form display's widgets,
   * and this form builds none.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): EntityInterface {
    $entity = $this->buildEntity($form, $form_state);
    foreach ($entity->validate() as $violation) {
      $field = explode('.', (string) $violation->getPropertyPath())[0];
      $element = match ($field) {
        'subject_type', 'subject_bundle' => 'subject',
        default => $field,
      };
      $form_state->setErrorByName(isset($form[$element]) ? $element : 'label', (string) $violation->getMessage());
    }
    // The storage would refuse an unvalidated save: the rebuilt entity
    // reads this flag (ContentEntityForm::buildEntity).
    $form_state->setTemporaryValue('entity_validated', TRUE);
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Save and compose');
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);
    $entity = $this->entity;
    assert($entity instanceof BlueprintInterface);
    $this->messenger()->addStatus($this->t('The blueprint %label was created. Compose it here, then save.', ['%label' => $entity->label()]));
    $url = $entity->toUrl('canonical');
    // Land on the canvas in Edit Mode: the same queued, path-scoped cookie
    // daedalus_ui sets on a created page's redirect.
    $this->modeState?->queueModeCookie($url->toString());
    $form_state->setRedirectUrl($url);
    return $result;
  }

}
