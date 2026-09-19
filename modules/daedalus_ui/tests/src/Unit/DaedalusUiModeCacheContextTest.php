<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Unit;

use Drupal\daedalus_ui\Cache\DaedalusUiModeCacheContext;
use Drupal\daedalus_ui\ModeState;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests that the cache context varies on the EFFECTIVE mode.
 *
 * The context replaces cookies:navigationMode precisely because the raw
 * cookie is not what a request renders under: a query override must land in
 * its own cache slot, not the cookie-value slot (the render-cache poisoning
 * observed while building the responsive preview).
 *
 * @group daedalus_ui
 */
#[Group('daedalus_ui')]
final class DaedalusUiModeCacheContextTest extends UnitTestCase {

  /**
   * Builds the context for a request with the given cookie and query mode.
   */
  private function context(?string $cookie, ?string $query): DaedalusUiModeCacheContext {
    $request = Request::create('/node/1', 'GET', $query === NULL ? [] : ['navigationMode' => $query]);
    if ($cookie !== NULL) {
      $request->cookies->set('navigationMode', $cookie);
    }
    $stack = new RequestStack();
    $stack->push($request);
    return new DaedalusUiModeCacheContext(new ModeState($stack));
  }

  /**
   * The context value is the resolved mode, query first, then cookie.
   */
  public function testEffectiveModeResolution(): void {
    // No signal at all: the 'none' slot.
    $this->assertSame('none', $this->context(NULL, NULL)->getContext());

    // Cookie only: ordinary page requests in edit mode.
    $this->assertSame('edit', $this->context('edit', NULL)->getContext());

    // Query overrides cookie: these two requests render differently and
    // must not share a slot with plain cookie=edit requests.
    $this->assertSame('none', $this->context('edit', 'none')->getContext());
    $this->assertSame('preview', $this->context('edit', 'preview')->getContext());

    // Query only: endpoint requests carrying mode as a param.
    $this->assertSame('edit', $this->context(NULL, 'edit')->getContext());
  }

}
