<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_blueprint\Entity\DesignSystem;

/**
 * The design system add and edit form: its identity, nothing of its members.
 *
 * A package is a shipped fact (the module the members came from), shown
 * but not edited; a site-built system has none.
 */
class DesignSystemForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $system = $this->entity;
    assert($system instanceof DesignSystemInterface);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $system->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $system->id(),
      '#machine_name' => [
        'exists' => [$this, 'exists'],
        'source' => ['label'],
      ],
      '#disabled' => !$system->isNew(),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#description' => $this->t('What the AI is told this design system is: its identity in one paragraph written for a designer (the look, its mood, what does the work), printed to the AI on every turn as the head of the design system brief.'),
      '#default_value' => $system->getDescription(),
      '#rows' => 3,
    ];
    $form['icon'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Icon'),
      '#description' => $this->t('A root-relative path to an image, or media:UUID. Leave empty for the default icon.'),
      '#default_value' => $system->getIcon(),
      '#maxlength' => 255,
    ];
    $form['default_skin'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default skin'),
      '#description' => $this->t('The machine name of the skin the site activates with this design system. Leave empty for a design system without a skin.'),
      '#default_value' => $system->getDefaultSkin(),
      '#maxlength' => 255,
    ];
    $form['enforcement'] = [
      '#type' => 'select',
      '#title' => $this->t('Enforcement'),
      '#description' => $this->t("How this design system's style law is enforced while it is active. Open: the law shapes offers only. Guarded: authored constraints are law; anything undeclared stays open. Strict: a closed world; only declared properties may be styled and references must name minted tokens."),
      '#options' => [
        DesignSystemInterface::ENFORCEMENT_OPEN => $this->t('Open'),
        DesignSystemInterface::ENFORCEMENT_GUARDED => $this->t('Guarded'),
        DesignSystemInterface::ENFORCEMENT_STRICT => $this->t('Strict'),
      ],
      '#default_value' => $system->getEnforcement(),
    ];
    if ($system->getPackage() !== NULL) {
      $form['package'] = [
        '#type' => 'item',
        '#title' => $this->t('Package'),
        '#markup' => $system->getPackage(),
        '#description' => $this->t('The module whose shipped members belong to this design system.'),
      ];
    }
    return $form;
  }

  /**
   * Whether a design system with the id exists (the machine name check).
   */
  public function exists(string $id): bool {
    return $this->entityTypeManager->getStorage(DesignSystem::ENTITY_TYPE_ID)->load($id) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity($entity, array $form, FormStateInterface $form_state) {
    parent::copyFormValuesToEntity($entity, $form, $form_state);
    // Empty strings are absence for the nullable keys; the package is
    // never a form value.
    foreach (['icon', 'default_skin'] as $key) {
      $entity->set($key, trim((string) $form_state->getValue($key)) === '' ? NULL : trim((string) $form_state->getValue($key)));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = $this->entity->save();
    $this->messenger()->addStatus($status === SAVED_NEW
      ? $this->t('Created the %label design system.', ['%label' => $this->entity->label()])
      : $this->t('Saved the %label design system.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
