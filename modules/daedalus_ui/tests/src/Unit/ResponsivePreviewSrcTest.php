<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Unit;

use Drupal\daedalus_ui\Controller\ResponsivePreview;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the responsive preview iframe src derivation.
 *
 * The opener passes the edited page's URL as a query parameter, so the
 * derivation is the popup's only defense against being talked into framing
 * another origin, and the place where navigationMode=preview is forced on
 * (the framed page must render without editing chrome no matter what the
 * caller passed).
 */
#[Group('daedalus_ui')]
final class ResponsivePreviewSrcTest extends UnitTestCase {

  /**
   * Accepts site-local paths, rejects everything else.
   */
  #[DataProvider('urlProvider')]
  public function testPreviewSrc(string $url, ?string $expected): void {
    $this->assertSame($expected, ResponsivePreview::previewSrc($url));
  }

  /**
   * Cases: requested URL to expected iframe src (NULL when rejected).
   */
  public static function urlProvider(): array {
    return [
      'plain path' => ['/node/1', '/node/1?navigationMode=preview'],
      'path with query' => ['/node/1?foo=bar', '/node/1?foo=bar&navigationMode=preview'],
      'mode is forced to preview' => ['/node/1?navigationMode=edit', '/node/1?navigationMode=preview'],
      'fragment is dropped' => ['/node/1#main', '/node/1?navigationMode=preview'],
      'front page' => ['/', '/?navigationMode=preview'],
      'empty' => ['', NULL],
      'relative path' => ['node/1', NULL],
      'absolute URL' => ['https://evil.example/x', NULL],
      'protocol-relative' => ['//evil.example/x', NULL],
      'backslash-disguised' => ['/\evil.example/x', NULL],
    ];
  }

}
