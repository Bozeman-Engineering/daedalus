<?php

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\daedalus_ui\Event\LayoutBuilderNewMedia;
use Drupal\daedalus_layout\NewMedia;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\daedalus_layout\Form\MediaBlockFileAssociationForm;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Daedalus UI new media.
 */
class DaedalusUiNewMedia implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    protected NewMedia $newMedia,
    protected FormBuilderInterface $formBuilder,
    protected AccountInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    if (class_exists(LayoutBuilderNewMedia::class)) {
      $events[LayoutBuilderNewMedia::class] = ['onNewMedia', 100];
    }
    return $events;
  }

  /**
   * On new media.
   *
   * A user dragged a Media Type compatible file from their desktop and
   * dropped it at the insertion caret. Resolve which block type carries
   * the file: a stored file association answers immediately (create the
   * media, set the resolution — the client places the block through the
   * blueprint endpoint); otherwise open the block-type chooser dialog,
   * whose submit hands the same resolution back to the client.
   *
   * @param \Drupal\daedalus_ui\Event\LayoutBuilderNewMedia $event
   *   The new media event.
   *
   * @throws \Drupal\Core\Form\EnforcedResponseException
   * @throws \Drupal\Core\Form\FormAjaxException
   */
  public function onNewMedia(LayoutBuilderNewMedia $event) {
    $file = $event->getRequest()->getSession()->get('uploaded_file');
    $file_extension = pathinfo($file->getFileUri(), PATHINFO_EXTENSION);
    $file_extension = strtolower($file_extension);

    $form_state = (new FormState())->setFormState([
      'file_extension' => $file_extension,
      'entity' => $event->getEntity(),
      'view_mode' => $event->getViewMode(),
    ]);

    // Has the user already chosen a block type for this file extension?
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    $settings = $user->daedalus_ui_settings->getValue();
    if (!empty($settings[0]['file_associations'][$file_extension])) {
      $resolution = $this->newMedia->buildResolution($settings[0]['file_associations'][$file_extension], $form_state);
      $event->setResolution($resolution);
      return;
    }

    // When the form is initially loaded we return it in a dialog. When the form
    // is submitted we do not continue below in this controller because the
    // submission uses Ajax. Core throws an exception when ajax is used.
    // @see MediaBlockFileAssociationForm->ajaxSubmit
    $form = $this->formBuilder->buildForm(MediaBlockFileAssociationForm::class, $form_state);

    $response = new AjaxResponse();
    $response->addCommand(new OpenModalDialogCommand(
      $this->t("@file_extension's block type", [
        '@file_extension' => ucfirst($file_extension),
      ]),
      $form,
      ['width' => '800']
    ));
    $event->setResponse($response);
  }

}
