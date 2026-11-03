<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Unit\Provider;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\daedalus_ai\Provider\RenderingPlan;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the rendering plan's shape, its validation and its ride on ChatInput.
 *
 * The plan is the one channel through which the context spine tells a
 * provider where breakpoints and opaque replay content belong. Three
 * things are load-bearing: the builders never mutate (a renderer composes
 * plans step by step and a stale reference must stay what it was),
 * malformed intent is refused at construction (a provider
 * must never see a TTL it cannot place or an index that points nowhere),
 * and the array form on the request metadata reads back as the same plan
 * (the metadata travels through drupal/ai events and logging as an array).
 */
#[Group('daedalus_ai')]
class RenderingPlanTest extends UnitTestCase {

  /**
   * An empty plan asks for nothing and says so in every form.
   */
  public function testEmptyPlan(): void {
    $plan = RenderingPlan::empty();
    $this->assertTrue($plan->isEmpty());
    $this->assertNull($plan->prefixTtl());
    $this->assertSame([], $plan->breakpoints());
    $this->assertSame([], $plan->opaque());
    $this->assertSame([
      'prefix_ttl' => NULL,
      'breakpoints' => [],
      'opaque' => [],
    ], $plan->toArray());
  }

  /**
   * The builders answer new plans, ordered by index, and leave the old one.
   */
  public function testBuildersAreImmutableAndOrdered(): void {
    $empty = RenderingPlan::empty();
    $plan = $empty
      ->withPrefixTtl(RenderingPlan::TTL_1H)
      ->withBreakpoint(7, RenderingPlan::TTL_5M)
      ->withBreakpoint(3, RenderingPlan::TTL_1H)
      ->withOpaque(8, ['type' => 'thinking', 'signature' => 'sig-8'])
      ->withOpaque(2, ['type' => 'thinking', 'signature' => 'sig-2']);

    $this->assertTrue($empty->isEmpty(), 'The original plan is untouched.');
    $this->assertFalse($plan->isEmpty());
    $this->assertSame(RenderingPlan::TTL_1H, $plan->prefixTtl());
    $this->assertSame([3 => RenderingPlan::TTL_1H, 7 => RenderingPlan::TTL_5M], $plan->breakpoints());
    $this->assertSame(RenderingPlan::TTL_5M, $plan->breakpointAt(7));
    $this->assertNull($plan->breakpointAt(5));
    $this->assertSame([2, 8], array_keys($plan->opaque()));
    $this->assertSame(['type' => 'thinking', 'signature' => 'sig-8'], $plan->opaqueAt(8));
    $this->assertNull($plan->opaqueAt(0));

    $replaced = $plan->withBreakpoint(7, RenderingPlan::TTL_1H)->withOpaque(8, ['replaced' => TRUE]);
    $this->assertSame(RenderingPlan::TTL_1H, $replaced->breakpointAt(7), 'A second breakpoint on an index replaces the first.');
    $this->assertSame(['replaced' => TRUE], $replaced->opaqueAt(8));
    $this->assertSame(RenderingPlan::TTL_5M, $plan->breakpointAt(7), 'The earlier plan still holds its own breakpoint.');

    $this->assertNull($plan->withPrefixTtl(NULL)->prefixTtl());
  }

  /**
   * A TTL outside the vocabulary is refused wherever it is given.
   */
  public function testUnknownTtlIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown cache TTL "10m"');
    RenderingPlan::empty()->withBreakpoint(1, '10m');
  }

  /**
   * A TTL outside the vocabulary is refused on the prefix too.
   */
  public function testUnknownPrefixTtlIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    RenderingPlan::empty()->withPrefixTtl('forever');
  }

  /**
   * A negative index points at no message and is refused.
   */
  public function testNegativeIndexIsRefused(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('must not be negative');
    RenderingPlan::empty()->withOpaque(-1, ['type' => 'thinking']);
  }

  /**
   * The plan rides the chat input's request metadata as its array form.
   */
  public function testRoundTripThroughChatInput(): void {
    $plan = RenderingPlan::empty()
      ->withPrefixTtl(RenderingPlan::TTL_5M)
      ->withBreakpoint(5, RenderingPlan::TTL_5M)
      ->withOpaque(4, ['type' => 'thinking', 'thinking' => 'hm', 'signature' => 'abc']);
    $input = new ChatInput([new ChatMessage('user', 'hello')]);

    $this->assertNull(RenderingPlan::fromInput($input), 'An input the spine never rendered carries no plan.');

    $plan->attachTo($input);
    $stored = $input->getRequestMetadataValue(RenderingPlan::METADATA_KEY);
    $this->assertIsArray($stored, 'The metadata holds the serializable array form, not the object.');
    $this->assertSame($plan->toArray(), $stored);

    $read = RenderingPlan::fromInput($input);
    $this->assertInstanceOf(RenderingPlan::class, $read);
    $this->assertEquals($plan, $read);
    $this->assertSame($plan->toArray(), $read->toArray());

    RenderingPlan::empty()->attachTo($input);
    $read = RenderingPlan::fromInput($input);
    $this->assertNotNull($read, 'An attached empty plan is a plan, distinct from none.');
    $this->assertTrue($read->isEmpty());
  }

  /**
   * The array form validates as the builders do.
   */
  public function testFromArrayValidates(): void {
    $plan = RenderingPlan::fromArray([
      'prefix_ttl' => RenderingPlan::TTL_1H,
      'breakpoints' => ['12' => RenderingPlan::TTL_5M, 2 => RenderingPlan::TTL_1H],
    ]);
    $this->assertSame([2 => RenderingPlan::TTL_1H, 12 => RenderingPlan::TTL_5M], $plan->breakpoints());
    $this->assertSame([], $plan->opaque(), 'A missing key reads as empty.');

    $malformed = [
      'bad ttl' => ['breakpoints' => [1 => '2h']],
      'ttl not a string' => ['breakpoints' => [1 => 5]],
      'index not an integer' => ['breakpoints' => ['five' => RenderingPlan::TTL_5M]],
      'opaque not a list' => ['opaque' => 'all'],
      'opaque not an array' => ['opaque' => [3 => 'thinking']],
      'prefix ttl not a string' => ['prefix_ttl' => 300],
    ];
    foreach ($malformed as $label => $values) {
      try {
        RenderingPlan::fromArray($values);
        $this->fail("Malformed plan '$label' was accepted.");
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Metadata under the key that is not an array is refused, not ignored.
   */
  public function testMalformedMetadataIsRefused(): void {
    $input = new ChatInput([new ChatMessage('user', 'hello')]);
    $input->setRequestMetadataValue(RenderingPlan::METADATA_KEY, 'not a plan');
    $this->expectException(\InvalidArgumentException::class);
    RenderingPlan::fromInput($input);
  }

}
