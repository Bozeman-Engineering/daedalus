<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\daedalus_ui\EditorSettings;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The per-user settings routes take a POST carrying the session token.
 *
 * Three routes write the caller's own preferences: the generic setting, a
 * hotkey, and the removal of a media file association. Each is a state
 * change, so a GET is not a way in (405), a POST without the session token
 * in the X-CSRF-Token header is refused (403) and writes nothing, and a
 * POST with it saves and answers the sentence for the editor as JSON. The
 * token check lives in the route table and the session, which only a real
 * request crosses, so this is a browser-less functional test; the whole
 * scenario rides one method because an install per method is the dominant
 * cost.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
final class SettingsRoutesTest extends BrowserTestBase {

  use DaedalusFixtureTrait;

  /**
   * GET is refused, a token-less POST is refused, a tokened POST saves.
   */
  public function testSettingsRoutesNeedPostAndToken(): void {
    $editor = $this->loginAsEditor();
    $editor->set(EditorSettings::FIELD_NAME, ['file_associations' => ['png' => 'image', 'svg' => 'vector']]);
    $editor->save();
    $uid = (int) $editor->id();
    $token = $this->csrfToken();

    $setting = 'daedalus-ui/setting/daedalus_ui/chrome_density';
    $devices = 'daedalus-ui/setting/daedalus_ui/responsive_preview_devices';
    $hotkey = 'daedalus-ui/save-user-hotkey/sidebar.settings/mod+e';
    $association = 'daedalus-ui/remove-media-file-association/png';

    // A GET is not a way in, and neither is the old value-in-the-path form.
    foreach ([$setting, "$setting/minimal", $hotkey, $association] as $path) {
      $status = $this->send('GET', $path, $token)['status'];
      $this->assertContains($status, [404, 405], "GET $path is refused");
      if ($path !== "$setting/minimal") {
        $this->assertSame(405, $status, "GET $path is a method refusal");
      }
    }

    // A POST without the token is refused and nothing is written.
    $this->assertSame(403, $this->send('POST', $setting, NULL, 'minimal')['status']);
    $this->assertSame(403, $this->send('POST', $hotkey, NULL)['status']);
    $this->assertSame(403, $this->send('POST', $association, NULL)['status']);
    $this->assertSame(403, $this->send('POST', $setting, 'not-the-token', 'minimal')['status']);
    $this->assertSame(
      ['file_associations' => ['png' => 'image', 'svg' => 'vector']],
      $this->storedSettings($uid),
      'A refused request changed nothing.',
    );

    // A scalar setting saves and the answer names it.
    $response = $this->send('POST', $setting, $token, 'minimal');
    $this->assertSame(200, $response['status'], $response['body']);
    $this->assertSame(['message' => 'Set chrome_density to minimal.'], Json::decode($response['body']));
    $this->assertSame('minimal', $this->storedSettings($uid)['daedalus_ui']['chrome_density']);

    // A structure saves through the same route and answers no sentence.
    $list = [['label' => 'Pocket', 'width' => 320, 'height' => 640]];
    $response = $this->send('POST', $devices, $token, $list);
    $this->assertSame(200, $response['status'], $response['body']);
    $this->assertSame(['message' => NULL], Json::decode($response['body']));
    $this->assertSame($list, $this->storedSettings($uid)['daedalus_ui']['responsive_preview_devices']);

    // A body that is not JSON is the caller's mistake, not a save.
    $response = $this->send('POST', $setting, $token, NULL, '{broken');
    $this->assertSame(400, $response['status'], $response['body']);
    $this->assertSame('minimal', $this->storedSettings($uid)['daedalus_ui']['chrome_density']);

    // A hotkey saves.
    $response = $this->send('POST', $hotkey, $token);
    $this->assertSame(200, $response['status'], $response['body']);
    $this->assertSame(['message' => 'Set the sidebar.settings hotkey to MOD+E.'], Json::decode($response['body']));
    $this->assertSame('mod+e', $this->storedSettings($uid)['hotkeys']['sidebar.settings']);

    // An association is removed, its neighbor kept.
    $response = $this->send('POST', $association, $token);
    $this->assertSame(200, $response['status'], $response['body']);
    $this->assertSame(['message' => 'Removed png media file association.'], Json::decode($response['body']));
    $this->assertSame(['svg' => 'vector'], $this->storedSettings($uid)['file_associations']);
  }

  /**
   * Sends a request as the browser session.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The route's path.
   * @param string|null $token
   *   The X-CSRF-Token header's value, or NULL to send none.
   * @param mixed $json
   *   The value to send as the JSON body, or NULL for no body.
   * @param string|null $raw
   *   A body to send as written, in place of $json.
   *
   * @return array{status: int, body: string}
   *   The response status and body.
   */
  private function send(string $method, string $path, ?string $token, mixed $json = NULL, ?string $raw = NULL): array {
    $options = [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
      'allow_redirects' => FALSE,
    ];
    if ($token !== NULL) {
      $options['headers']['X-CSRF-Token'] = $token;
    }
    if ($raw !== NULL) {
      $options['headers']['Content-Type'] = 'application/json';
      $options['body'] = $raw;
    }
    elseif ($json !== NULL) {
      $options['json'] = $json;
    }
    $response = $this->getHttpClient()->request($method, $this->buildUrl($path), $options);
    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
  }

  /**
   * The session's CSRF token, as the page builder's JS fetches it.
   */
  private function csrfToken(): string {
    $this->drupalGet('session/token');
    return $this->getSession()->getPage()->getContent();
  }

  /**
   * The user's stored editor settings, read fresh from storage.
   */
  private function storedSettings(int $uid): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $storage->resetCache([$uid]);
    $user = $storage->load($uid);
    $this->assertInstanceOf(User::class, $user);
    return $user->get(EditorSettings::FIELD_NAME)->getValue()[0] ?? [];
  }

}
