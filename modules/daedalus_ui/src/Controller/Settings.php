<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\daedalus_ui\EditorSettings;
use Drupal\daedalus_ui\ToolPluginManager;
use Drupal\daedalus_ui\Ajax\EditModeMessageCommand;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Settings sidebar end points.
 */
final class Settings extends ControllerBase {

  use StringTranslationTrait;

  public function __construct(
    protected ToolPluginManager $toolManager,
    protected EditorSettings $editorSettings,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.tools'),
      $container->get('daedalus_ui.editor_settings'),
    );
  }

  /**
   * Saves a namespaced editor preference for the current user.
   *
   * Generic endpoint for server-readable editor preferences. Scalar values
   * ride the {value} path segment (callers reach that form through the
   * Drupal.Daedalus.saveSetting() JS helper); structured values
   * (lists, maps) are POSTed as an application/json body with {value}
   * omitted. Requiring the JSON content type is deliberate: an HTML form
   * cannot send it cross-origin without a CORS preflight, so it doubles as
   * the CSRF guard for the body-carried variant.
   */
  public function saveSetting(Request $request, string $namespace, string $key, string $value = ''): AjaxResponse {
    if ($value === '' && $request->isMethod('POST')) {
      if (!str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
        throw new BadRequestHttpException('Structured setting values must be posted as application/json.');
      }
      $body = $request->getContent();
      if (strlen($body) > 65536) {
        throw new BadRequestHttpException('Setting value too large.');
      }
      try {
        $decoded = json_decode($body, TRUE, 32, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException $e) {
        throw new BadRequestHttpException('Invalid JSON setting value: ' . $e->getMessage(), $e);
      }
      $this->editorSettings->set($namespace, $key, $decoded);
      $message = (string) $this->t('Saved @key.', ['@key' => $key]);
    }
    else {
      $this->editorSettings->set($namespace, $key, $value);
      $message = (string) $this->t('Set @key to @value.', ['@key' => $key, '@value' => $value]);
    }

    $response = new AjaxResponse();
    $response->addCommand(new EditModeMessageCommand($message, 'status', NULL, 1250));
    return $response;
  }

  /**
   * Save hotkey.
   *
   * Accepts a binding string: '+'-separated lowercase modifiers followed by
   * a key ('v', 'mod+e', 'shift+escape', 'alt'). The binding id is a tool
   * id or a JS-registered binding id (e.g. a verb); unknown ids are
   * accepted since the JS hotkey registry — not the server — is the
   * authority on which bindings exist.
   */
  public function saveHotkey(string $tool_id, string $hotkey) {
    if (strlen($hotkey) > 48 || !preg_match('/^(?:(?:mod|meta|ctrl|alt|shift)\+)*[a-z0-9]+$/', $hotkey)) {
      throw new BadRequestHttpException('Invalid hotkey.');
    }

    $account = $this->currentUser();
    if ($account->isAuthenticated()) {
      $user = $this->entityTypeManager()->getStorage('user')->load($account->id());
      $settings = $user->daedalus_ui_settings->getValue();
      $settings[0]['hotkeys'][$tool_id] = $hotkey;
      $user->set('daedalus_ui_settings', $settings[0]);

      daedalus_ui_save_outside_workspace($user);
    }

    if ($this->toolManager->hasDefinition($tool_id)) {
      $label = $this->toolManager->createInstance($tool_id)->label();
    }
    else {
      $label = $tool_id;
    }

    $response = new AjaxResponse();
    $this->messenger()->addStatus($this->t('Set the %tool hotkey to %hotkey.', [
      '%tool' => $label,
      '%hotkey' => strtoupper($hotkey),
    ]));
    return $response;
  }

  /**
   * Remove media file association.
   */
  public function removeMediaFileAssociation(string $file_extension) {
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

    $response = new AjaxResponse();
    $this->messenger()->addStatus($this->t('Removed @extension media file association.', ['@extension' => $file_extension]));
    return $response;
  }

}
