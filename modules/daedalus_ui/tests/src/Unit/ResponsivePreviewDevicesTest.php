<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Unit;

use Drupal\daedalus_ui\Controller\ResponsivePreview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the user device list sanitization.
 *
 * User devices reach the popup from the generic editor-settings store,
 * which holds values opaquely, so this read-side normalization is where the
 * device grammar is enforced: whatever ends up stored, only well-formed
 * entries (non-empty label, in-bounds numeric dimensions) reach the shell.
 */
#[Group('daedalus_ui')]
final class ResponsivePreviewDevicesTest extends UnitTestCase {

  /**
   * Well-formed entries pass, malformed ones are dropped.
   */
  #[DataProvider('storedProvider')]
  public function testSanitizeUserDevices(mixed $stored, array $expected): void {
    $this->assertSame($expected, ResponsivePreview::sanitizeUserDevices($stored));
  }

  /**
   * Cases: stored value to the device list the shell receives.
   */
  public static function storedProvider(): array {
    $kiosk = ['label' => 'Kiosk', 'width' => 800, 'height' => 1200];
    return [
      'unset (get default)' => [[], []],
      'not a list' => ['bogus', []],
      'valid device' => [[$kiosk], [$kiosk]],
      'numeric strings and untrimmed label normalize' => [
        [['label' => ' Kiosk ', 'width' => '800', 'height' => '1200']],
        [$kiosk],
      ],
      'missing label dropped' => [[['width' => 800, 'height' => 1200]], []],
      'blank label dropped' => [[['label' => '  ', 'width' => 800, 'height' => 1200]], []],
      'non-numeric dimension dropped' => [[['label' => 'K', 'width' => 'wide', 'height' => 1200]], []],
      'below minimum dropped' => [[['label' => 'K', 'width' => 49, 'height' => 1200]], []],
      'above maximum dropped' => [[['label' => 'K', 'width' => 800, 'height' => 10001]], []],
      'non-array entry dropped, valid sibling kept' => [['bogus', $kiosk], [$kiosk]],
    ];
  }

}
