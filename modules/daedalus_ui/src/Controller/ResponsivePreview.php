<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\daedalus_ui\EditorSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The responsive preview pop-out window.
 *
 * A minimal shell around a device-sized iframe. The popup window itself
 * cannot be the device viewport: browsers clamp popup windows to a minimum
 * outer width (~500px in Chrome), so resizing the window to phone width
 * silently fails. Instead the window is a comfortable shell (device
 * selector + size readout) whose iframe is sized exactly to the selected
 * device; media and container queries respond to the iframe viewport.
 *
 * The iframe loads the edited page's own URL with ?navigationMode=preview:
 * edits stage in tempstore keyed to the user session (+ workspace), and
 * preview mode activates the tempstore entity swap (see
 * DaedalusUiActivationVoter) while suppressing the editing
 * chrome, which only renders in edit mode proper (ModeState reads the query
 * parameter before the cookie). The popup never mutates anything; it is a
 * projection of the edit session, never a second writer.
 *
 * The route is declared a pop-out (_daedalus_ui.popout), so
 * PopoutPageVariant forces the simple_page display variant and the window
 * gets no block chrome (headers, menus, footers).
 */
final class ResponsivePreview extends ControllerBase {

  /**
   * The EditorSettings key (namespace 'daedalus_ui') for user devices.
   *
   * The stored value is a list of ['label' => string, 'width' => int,
   * 'height' => int] maps, written whole on every add/remove from the popup
   * shell. Deliberately EditorSettings rather than localStorage: a curated
   * device list is worth syncing across browsers and machines. Not exposed
   * in the settings sidebar; the popup is the only UI.
   */
  public const DEVICES_SETTING = 'responsive_preview_devices';

  /**
   * User devices smaller or larger than this are dropped on read.
   *
   * The bounds are rendering sanity for the iframe (and the popup UI
   * enforces them on entry), not real-device limits.
   */
  public const MIN_DIMENSION = 50;
  public const MAX_DIMENSION = 10000;

  /**
   * The shipped default devices.
   *
   * Site defaults every user gets; user-added devices from EditorSettings
   * are appended after them. Dimensions are CSS-pixel viewports.
   */
  public const DEVICES = [
    ['id' => 'iphone_se', 'label' => 'iPhone SE', 'width' => 375, 'height' => 667],
    ['id' => 'iphone_17', 'label' => 'iPhone 17', 'width' => 402, 'height' => 874],
    ['id' => 'iphone_17_pro_max', 'label' => 'iPhone 17 Pro Max', 'width' => 440, 'height' => 956],
    ['id' => 'ipad', 'label' => 'iPad', 'width' => 820, 'height' => 1180],
    ['id' => 'ipad_pro_13', 'label' => 'iPad Pro 13"', 'width' => 1032, 'height' => 1376],
    ['id' => 'laptop', 'label' => 'Laptop', 'width' => 1440, 'height' => 900],
  ];

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
   * Builds the preview shell.
   */
  public function build(Request $request): array {
    $src = self::previewSrc((string) $request->query->get('url', ''));
    if ($src === NULL) {
      throw new BadRequestHttpException('The url query parameter must be a site-local path.');
    }

    return [
      'preview' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => 'daedalus-ui-responsive-preview-root',
        ],
      ],
      '#attached' => [
        'library' => ['daedalus_ui/responsive_preview'],
        'drupalSettings' => [
          'daedalusUi' => [
            'responsivePreview' => [
              'src' => $src,
              // The edit-session scope rides along for the BroadcastChannel
              // pairing (prefix 'preview'): the live-update layer opens the
              // wire here to hear the leader's mutated pings.
              'scope' => $request->query->get('scope'),
              'devices' => self::DEVICES,
              'userDevices' => self::sanitizeUserDevices(
                $this->editorSettings->get('daedalus_ui', self::DEVICES_SETTING, []),
              ),
            ],
          ],
        ],
      ],
      '#cache' => [
        // The user context (not just permissions) because the device list is
        // a per-user preference; the user tag re-renders the shell when a
        // device is added or removed (EditorSettings saves the user entity).
        'contexts' => ['user', 'url.query_args:url', 'url.query_args:scope'],
        'tags' => ['user:' . $this->currentUser()->id()],
      ],
    ];
  }

  /**
   * Derives the iframe src from the requested page URL.
   *
   * Only a site-local path is accepted (the popup must never be talked
   * into framing another origin), and navigationMode=preview is forced onto
   * the query so the framed page renders the staged tempstore state without
   * editing chrome regardless of what the caller passed.
   *
   * @param string $url
   *   The edited page's URL as passed by the opener (path + query string).
   *
   * @return string|null
   *   The iframe src, or NULL when the URL is not a site-local path.
   */
  public static function previewSrc(string $url): ?string {
    // A protocol-relative '//host' or anything not starting with '/' is not
    // a local path. '/\host' counts too: browsers normalize backslashes to
    // slashes when resolving, so it would resolve protocol-relative.
    if (!preg_match('#^/(?![/\\\\])#', $url)) {
      return NULL;
    }
    $parts = parse_url($url);
    if ($parts === FALSE || isset($parts['scheme']) || isset($parts['host']) || empty($parts['path'])) {
      return NULL;
    }
    $query = [];
    if (!empty($parts['query'])) {
      parse_str($parts['query'], $query);
    }
    $query['navigationMode'] = 'preview';
    return $parts['path'] . '?' . http_build_query($query);
  }

  /**
   * Normalizes the stored user device list for the shell.
   *
   * The generic settings endpoint stores values opaquely, so the device
   * grammar is enforced here where the list is consumed: entries that are
   * not a map with a non-empty label and in-bounds numeric dimensions are
   * dropped rather than repaired (the popup UI only writes valid entries;
   * anything else was hand-crafted).
   *
   * @param mixed $stored
   *   The raw stored setting value.
   *
   * @return array
   *   A list of ['label' => string, 'width' => int, 'height' => int].
   */
  public static function sanitizeUserDevices(mixed $stored): array {
    if (!is_array($stored)) {
      return [];
    }
    $devices = [];
    foreach ($stored as $device) {
      if (!is_array($device)) {
        continue;
      }
      $label = trim((string) ($device['label'] ?? ''));
      $width = $device['width'] ?? NULL;
      $height = $device['height'] ?? NULL;
      if ($label === '' || !is_numeric($width) || !is_numeric($height)) {
        continue;
      }
      $width = (int) $width;
      $height = (int) $height;
      if (min($width, $height) < self::MIN_DIMENSION || max($width, $height) > self::MAX_DIMENSION) {
        continue;
      }
      $devices[] = ['label' => $label, 'width' => $width, 'height' => $height];
    }
    return $devices;
  }

}
