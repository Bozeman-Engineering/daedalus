<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Controller;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Controller\ControllerBase;
use Drupal\daedalus_ui\EditorSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Settings sidebar end points.
 *
 * Each route is a POST carrying the session token in the X-CSRF-Token
 * header (the client sends through Drupal.Daedalus.post) and changes only
 * the caller's own preferences. Each answers JSON: `message` is the
 * sentence for the editor, shown by the caller where Edit Mode's
 * notifications are loaded.
 */
final class Settings extends ControllerBase {

  public function __construct(
    protected EditorSettings $editorSettings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('daedalus_ui.editor_settings'),
    );
  }

  /**
   * Saves a namespaced editor preference for the current user.
   *
   * Generic endpoint for server-readable editor preferences; callers reach
   * it through the Drupal.Daedalus.saveSetting() JS helper. The value is
   * the request's JSON body, a scalar (a density, a model id) or a
   * structure (a device list, a map of marks) alike. A scalar is a
   * setting the editor chose in a control, so the answer names it; a
   * structure is a whole list its caller repaints from, so the answer
   * carries no message.
   */
  public function saveSetting(Request $request, string $namespace, string $key): JsonResponse {
    $body = $request->getContent();
    if (strlen($body) > 65536) {
      throw new BadRequestHttpException('Setting value too large.');
    }
    try {
      $value = json_decode($body, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new BadRequestHttpException('Invalid JSON setting value: ' . $e->getMessage(), $e);
    }
    $this->editorSettings->set($namespace, $key, $value);

    $message = NULL;
    if (is_scalar($value)) {
      $message = PlainTextOutput::renderFromHtml($this->t('Set @key to @value.', [
        '@key' => $key,
        '@value' => is_bool($value) ? json_encode($value) : $value,
      ]));
    }
    return new JsonResponse(['message' => $message]);
  }

  /**
   * Save hotkey.
   *
   * Accepts a binding string: '+'-separated lowercase modifiers followed by
   * a key ('v', 'mod+e', 'shift+escape', 'alt'). The binding id is a panel
   * toggle's ("sidebar.{id}") or a JS-registered binding id (e.g. a verb);
   * unknown ids are accepted since the JS hotkey registry, not the server,
   * is the authority on which bindings exist. The message names the
   * binding by its id: the registry owns the labels.
   */
  public function saveHotkey(string $binding_id, string $hotkey): JsonResponse {
    if (strlen($hotkey) > 48 || !preg_match('/^(?:(?:mod|meta|ctrl|alt|shift)\+)*[a-z0-9]+$/', $hotkey)) {
      throw new BadRequestHttpException('Invalid hotkey.');
    }

    $account = $this->currentUser();
    if ($account->isAuthenticated()) {
      $user = $this->entityTypeManager()->getStorage('user')->load($account->id());
      $settings = $user->daedalus_ui_settings->getValue();
      $settings[0]['hotkeys'][$binding_id] = $hotkey;
      $user->set('daedalus_ui_settings', $settings[0]);

      daedalus_ui_save_outside_workspace($user);
    }

    return new JsonResponse([
      'message' => PlainTextOutput::renderFromHtml($this->t('Set the @binding hotkey to @hotkey.', [
        '@binding' => $binding_id,
        '@hotkey' => strtoupper($hotkey),
      ])),
    ]);
  }

  /**
   * Remove media file association.
   */
  public function removeMediaFileAssociation(string $file_extension): JsonResponse {
    $account = $this->currentUser();
    if ($account->isAuthenticated()) {
      $user = $this->entityTypeManager()->getStorage('user')->load($account->id());
      $settings = $user->daedalus_ui_settings->getValue();

      $save = FALSE;
      if (!empty($settings[0]['file_associations'][strtolower($file_extension)])) {
        unset($settings[0]['file_associations'][strtolower($file_extension)]);
        $save = TRUE;
      }
      if (!empty($settings[0]['file_associations'][strtoupper($file_extension)])) {
        unset($settings[0]['file_associations'][strtoupper($file_extension)]);
        $save = TRUE;
      }

      if ($save) {
        $user->set('daedalus_ui_settings', $settings[0]);
        daedalus_ui_save_outside_workspace($user);
      }
    }

    return new JsonResponse([
      'message' => PlainTextOutput::renderFromHtml($this->t('Removed @extension media file association.', ['@extension' => $file_extension])),
    ]);
  }

}
