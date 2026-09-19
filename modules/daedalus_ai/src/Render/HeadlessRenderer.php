<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Render;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Renders a page in headless Chrome and screenshots the canvas.
 *
 * Speaks the plain W3C WebDriver wire protocol over HTTP (no Mink/Behat
 * runtime dependency) against the endpoint configured in
 * daedalus_ai.settings.webdriver_url, e.g. a selenium standalone container.
 *
 * The render replicates the calling editor's browsing context instead of
 * inventing its own: the caller passes the cookies to carry (the editor's
 * session cookie plus navigationMode=edit), so the headless page is the
 * same edit-mode page the editor sees, staged tempstore changes included.
 * The target URL carries the canvas state vector boot parameters
 * (?canvasWidth/?canvasScheme/?canvasMotion, see daedalus_ui
 * vector-state.js), so the page poses itself; this service only waits for
 * the pose to land and captures #daedalus-ui-stage, the element
 * the vector narrows. Body-level edit chrome (top bar, handles, ruler,
 * sidebars) sits outside that element and stays out of the shot.
 *
 * The editor's session cookie is forwarded only to the configured WebDriver
 * endpoint and, from the headless browser, back to this site itself. It
 * never reaches the model.
 */
final class HeadlessRenderer implements HeadlessRendererInterface {

  /**
   * The element the screenshot captures: the canvas the vector poses.
   */
  private const STAGE_SELECTOR = '#daedalus-ui-stage';

  /**
   * Capture ceiling in CSS pixels; taller pages are cut with a notice.
   */
  private const MAX_CAPTURE_HEIGHT = 4000;

  /**
   * Seconds to wait for the page to load and the vector to apply.
   */
  private const READY_TIMEOUT = 20;

  /**
   * The WebDriver endpoint resolved for the current render() call.
   */
  private string $endpoint = '';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Renders a URL and returns a PNG screenshot of the stage.
   *
   * @param string $url
   *   The absolute page URL, canvas vector boot parameters included.
   * @param array $cookies
   *   Cookies to set before loading the page, as {name, value} arrays.
   *   Callers pass the editor's session cookie and navigationMode=edit.
   * @param int|null $canvas_width
   *   The posed canvas width in pixels, or NULL for the full canvas. Used
   *   to size the browser window and to confirm the pose landed.
   *
   * @return array{png: string, width: int, height: int, truncated: bool}
   *   The PNG binary, its pixel dimensions, and whether the page was
   *   taller than the capture ceiling.
   *
   * @throws \Drupal\daedalus_ai\Render\HeadlessRenderException
   *   When no endpoint is configured, the browser cannot be driven, or the
   *   page never reaches the posed edit-mode state.
   */
  public function render(string $url, array $cookies, ?int $canvas_width = NULL): array {
    $this->endpoint = rtrim((string) $this->configFactory->get('daedalus_ai.settings')->get('webdriver_url'), '/');
    if ($this->endpoint === '') {
      throw new HeadlessRenderException('The render tool is not available: no WebDriver endpoint is configured (daedalus_ai.settings webdriver_url).');
    }

    $session = $this->createSession();
    try {
      // A window comfortably wider than the posed canvas: the stage
      // narrows itself to the vector's width, so the window only needs to
      // contain it (plus displacement chrome margins).
      $this->command('POST', "/session/$session/window/rect", [
        'width' => max(1280, ($canvas_width ?? 0) + 160),
        'height' => 1080,
      ]);

      // Cookies can only be set for the current document's origin, so touch
      // a cheap same-origin URL before the real navigation.
      $origin = $this->origin($url);
      $this->command('POST', "/session/$session/url", ['url' => $origin . '/robots.txt']);
      // Beyond the caller's cookies, pose the edit chrome itself: an empty
      // "{side}_{slot}_sidebar" cookie is the "deliberately closed" state,
      // so no panel opens to displace or overlay the canvas being captured.
      $chrome_cookies = [
        ['name' => 'left_top_sidebar', 'value' => ''],
        ['name' => 'left_bottom_sidebar', 'value' => ''],
        ['name' => 'right_top_sidebar', 'value' => ''],
        ['name' => 'right_bottom_sidebar', 'value' => ''],
      ];
      foreach (array_merge($cookies, $chrome_cookies) as $cookie) {
        $this->command('POST', "/session/$session/cookie", [
          'cookie' => ['name' => $cookie['name'], 'value' => $cookie['value'], 'path' => '/'],
        ]);
      }

      $this->command('POST', "/session/$session/url", ['url' => $url]);
      $this->waitForPose($session, $canvas_width, $url);

      // Body-level edit chrome (tool pill, sidebar fabs, canvas handles and
      // ruler) floats over the canvas rect; the screenshot is of the PAGE,
      // so hide it. Everything vector-posed has already applied.
      $this->command('POST', "/session/$session/execute/sync", [
        'script' => 'const style = document.createElement("style");'
        . ' style.textContent = "[data-canvas-chrome], .daedalus-ui-sidebar-wrapper, .daedalus-ui-sidebar-fabs, #edit-mode-toolbar { display: none !important; }";'
        . ' document.head.append(style);',
        'args' => [],
      ]);

      // Grow the window to the canvas so the element screenshot captures
      // the whole page, then let late layout (lazy images entering the
      // now-taller viewport) settle.
      $metrics = $this->command('POST', "/session/$session/execute/sync", [
        'script' => 'const root = document.querySelector(arguments[0]);'
        . ' if (!root) { return null; }'
        . ' window.scrollTo(0, 0);'
        . ' const rect = root.getBoundingClientRect();'
        . ' return { top: Math.ceil(rect.top + window.scrollY), height: Math.ceil(rect.height) };',
        'args' => [self::STAGE_SELECTOR],
      ]);
      if (!is_array($metrics)) {
        throw new HeadlessRenderException('The page rendered but has no stage (' . self::STAGE_SELECTOR . '); is daedalus_ui edit mode available on it?');
      }
      $needed = $metrics['top'] + $metrics['height'] + 16;
      $truncated = $needed > self::MAX_CAPTURE_HEIGHT;
      $this->command('POST', "/session/$session/window/rect", [
        'width' => max(1280, ($canvas_width ?? 0) + 160),
        'height' => min(self::MAX_CAPTURE_HEIGHT, max(400, $needed)),
      ]);
      usleep(500000);
      $this->command('POST', "/session/$session/execute/sync", [
        'script' => 'window.scrollTo(0, 0);',
        'args' => [],
      ]);

      $element = $this->command('POST', "/session/$session/element", [
        'using' => 'css selector',
        'value' => self::STAGE_SELECTOR,
      ]);
      $element_id = is_array($element) ? reset($element) : NULL;
      if (!is_string($element_id)) {
        throw new HeadlessRenderException('The stage element could not be resolved for the screenshot.');
      }
      $base64 = $this->command('GET', "/session/$session/element/$element_id/screenshot");
      $png = is_string($base64) ? base64_decode($base64, TRUE) : FALSE;
      if ($png === FALSE || $png === '') {
        throw new HeadlessRenderException('The browser returned an empty screenshot.');
      }
      $size = getimagesizefromstring($png);

      return [
        'png' => $png,
        'width' => $size ? $size[0] : 0,
        'height' => $size ? $size[1] : 0,
        'truncated' => $truncated,
      ];
    }
    finally {
      try {
        $this->command('DELETE', "/session/$session");
      }
      catch (HeadlessRenderException) {
        // The render outcome is already decided; a session that fails to
        // close times out server-side.
      }
    }
  }

  /**
   * Creates a headless browser session and returns its id.
   */
  private function createSession(): string {
    $value = $this->command('POST', '/session', [
      'capabilities' => [
        'alwaysMatch' => [
          'browserName' => 'chrome',
          'acceptInsecureCerts' => TRUE,
          'goog:chromeOptions' => [
            'args' => [
              '--headless',
              '--disable-gpu',
              '--disable-dev-shm-usage',
              '--hide-scrollbars',
              '--force-device-scale-factor=1',
              // Chrome silently upgrades http:// navigation to https; with
              // insecure certs accepted the upgrade "succeeds", the scheme
              // changes, and Drupal expects a different session cookie name
              // (SSESS vs SESS) — the render would be anonymous. The URL the
              // editor uses is the URL that must load.
              '--disable-features=HttpsUpgrades,HttpsFirstBalancedModeAutoEnable',
            ],
          ],
        ],
      ],
    ]);
    $session = $value['sessionId'] ?? NULL;
    if (!is_string($session) || $session === '') {
      throw new HeadlessRenderException('The WebDriver endpoint did not return a session.');
    }
    return $session;
  }

  /**
   * Waits for the page to load and the canvas vector pose to land.
   *
   * The canvas-vector behavior mirrors its state onto the html element
   * (data-canvas-scheme is always stamped once applied, data-canvas-width
   * when a width is posed), so those attributes are the ready signal. A
   * page that never stamps them never initialized the vector: not an
   * edit-mode page for this user.
   */
  private function waitForPose(string $session, ?int $canvas_width, string $url): void {
    $script = 'if (document.readyState !== "complete") { return "loading"; }'
      . ' const html = document.documentElement;'
      . ' if (!html.hasAttribute("data-canvas-scheme")) { return "no-vector"; }'
      . ' if (arguments[0] !== null && html.getAttribute("data-canvas-width") !== String(arguments[0])) { return "width-pending"; }'
      . ' if (document.fonts && document.fonts.status === "loading") { return "fonts"; }'
      . ' return "ready";';
    $deadline = microtime(TRUE) + self::READY_TIMEOUT;
    $state = 'loading';
    while (microtime(TRUE) < $deadline) {
      $state = $this->command('POST', "/session/$session/execute/sync", [
        'script' => $script,
        'args' => [$canvas_width],
      ]);
      if ($state === 'ready') {
        return;
      }
      usleep(250000);
    }
    // Only the hard states are fatal: a page still waiting on fonts is
    // renderable, a page that never booted the vector is not the page the
    // caller asked to verify.
    if ($state === 'loading' || $state === 'no-vector' || $state === 'width-pending') {
      throw new HeadlessRenderException(
        "The page at $url never reached the posed edit-mode state (last state: $state)."
        . ' The render loads the page in Edit Mode as the current editor; make sure this user can edit this page.'
      );
    }
  }

  /**
   * Sends one WebDriver command and returns its unwrapped value.
   */
  private function command(string $method, string $path, ?array $payload = NULL): mixed {
    $options = ['timeout' => 65, 'http_errors' => FALSE];
    if ($method === 'POST') {
      // The W3C protocol requires a JSON object body on every POST.
      $options['json'] = $payload === NULL || $payload === [] ? (object) [] : $payload;
    }
    try {
      $response = $this->httpClient->request($method, $this->endpoint . $path, $options);
    }
    catch (\Exception $e) {
      throw new HeadlessRenderException("The WebDriver endpoint could not be reached ($method $path): " . $e->getMessage(), 0, $e);
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() >= 400) {
      $message = $data['value']['message'] ?? ('HTTP ' . $response->getStatusCode());
      throw new HeadlessRenderException("WebDriver error ($method $path): " . strtok($message, "\n"));
    }
    return $data['value'] ?? NULL;
  }

  /**
   * The scheme://host[:port] origin of a URL.
   */
  private function origin(string $url): string {
    $parts = parse_url($url);
    if (empty($parts['scheme']) || empty($parts['host'])) {
      throw new HeadlessRenderException("Not an absolute URL: $url.");
    }
    return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
  }

}
