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
   * whose submit hands the same resolution back to the client. The form
   * is only built here: it submits to its own route
   * (daedalus_layout.media_file_association), where core's form token
   * guards it, never back to the upload route.
   *
   * @param \Drupal\daedalus_ui\Event\LayoutBuilderNewMedia $event
   *   The new media event.
   */
  public function onNewMedia(LayoutBuilderNewMedia $event) {
    $file_extension = $this->newMedia->uploadedFileExtension();

    // Has the user already chosen a block type for this file extension?
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    $settings = $user->daedalus_ui_settings->getValue();
    if (!empty($settings[0]['file_associations'][$file_extension])) {
      $form_state = (new FormState())->setFormState(['file_extension' => $file_extension]);
      $resolution = $this->newMedia->buildResolution($settings[0]['file_associations'][$file_extension], $form_state);
      $event->setResolution($resolution);
      return;
    }

    // The form takes the host as its route does, as a build argument, and
    // reads the uploaded file from the session itself.
    $form_state = (new FormState())->addBuildInfo('args', [$event->getEntity()]);
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
