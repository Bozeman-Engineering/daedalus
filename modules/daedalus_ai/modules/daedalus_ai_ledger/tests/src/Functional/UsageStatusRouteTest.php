<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_ledger\Functional;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Component\Serialization\Json;
use Drupal\daedalus_ai_ledger\CostResolver;
use Drupal\daedalus_ai_ledger\UsageGate;
use Drupal\daedalus_ai_ledger\UsageLedger;
use Drupal\daedalus_ai_ledger\Window;
use Drupal\daedalus_ai_test\Plugin\AiProvider\StubProvider;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The usage status route: the chat's meter over HTTP.
 *
 * The route is the one road the chat has to the gate's status, so what
 * it answers and to whom is the contract: anonymous never reaches it,
 * the recipe's editor (whose role carries `use ai assistant` as shipped)
 * gets an empty list while no cap is configured, and with a cap and one
 * priced leg of their own gets exactly the six keys the kebab reads, no
 * USD among them; an administrator, whose bypass skips the user windows
 * only, is answered the site window alone when both scopes are
 * configured (the gate refuses them on a site window, so it is theirs to
 * see). The site is the AI umbrella recipe's product and the whole
 * scenario rides one method: an install per method is the dominant cost.
 */
#[Group('daedalus_ai_ledger')]
#[RunTestsInSeparateProcesses]
final class UsageStatusRouteTest extends BrowserTestBase {

  use DaedalusFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'daedalus_theme';

  /**
   * The route's path.
   */
  private const PATH = 'daedalus-ai/usage';

  /**
   * {@inheritdoc}
   */
  protected function installRecipePath(): string {
    return dirname($this->root) . '/recipes/daedalus_recipe/ai';
  }

  /**
   * The stub provider's proxy, the one the leg runs through.
   *
   * ProviderProxy forwards every operation through __call() and declares
   * none, so the call site reads it as an object, the way the ledger's
   * Kernel classes hold theirs.
   */
  private function stubProvider(): object {
    return $this->container->get('ai.provider')->createInstance('daedalus_ai_test_stub');
  }

  /**
   * Anonymous is refused; the editor reads their windows, never in USD.
   */
  public function testStatusRoute(): void {
    $assert = $this->assertSession();

    // Anonymous never reaches the route.
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(403);

    // The editor with the caps as shipped (none): nothing to show.
    $editor = $this->loginAsEditor();
    $this->assertSame([], $this->config(CostResolver::SETTINGS)->get(UsageGate::CAPS_KEY), 'The caps ship empty.');
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $this->assertStringStartsWith('application/json', (string) $this->getSession()->getResponseHeader('Content-Type'));
    $this->assertSame('{"windows":[]}', $this->getSession()->getPage()->getContent());

    // A user cap of one dollar over the hour, and one leg of the editor's
    // through the stub provider priced at a quarter (1000 input tokens at
    // 250 USD per million, the other buckets counting nothing). The stub
    // lives in daedalus_ai's test module, which the recipe does not
    // enable; the leg runs in this process as the editor, so the row is
    // theirs.
    \Drupal::service('module_installer')->install(['daedalus_ai_test']);
    $this->config(CostResolver::SETTINGS)
      ->set(CostResolver::PRICES_KEY, [
        [
          'model' => StubProvider::MODEL_ID,
          'input' => 250.0,
          'output' => 0.0,
          'cache_read' => 0.0,
          'cache_write' => 0.0,
          'per_call' => 0.0,
        ],
      ])
      ->set(UsageGate::CAPS_KEY, [
        ['scope' => Window::SCOPE_USER, 'period' => 'PT1H', 'usd' => 1.0, 'anchor' => ''],
      ])
      ->save();
    $switcher = \Drupal::service('account_switcher');
    $switcher->switchTo($editor);
    $this->stubProvider()->chat(
      new ChatInput([new ChatMessage('user', 'Hello')]),
      StubProvider::MODEL_ID,
      [UsageLedger::RUNNER_TAG_PREFIX . 'job-route'],
    );
    $switcher->switchBack();
    $row = \Drupal::service('daedalus_ai_ledger.usage_ledger')->newestLeg('job-route');
    $this->assertNotNull($row);
    $this->assertSame((int) $editor->id(), (int) $row->getOwnerId());
    $this->assertEqualsWithDelta(0.25, $row->cost(), 1e-9);

    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $body = $this->getSession()->getPage()->getContent();
    $answer = Json::decode($body);
    $this->assertCount(1, $answer['windows']);
    $window = $answer['windows'][0];
    $this->assertSame(['scope', 'period', 'label', 'remaining', 'reached', 'reset_at'], array_keys($window), 'Exactly the keys the kebab reads.');
    $this->assertSame(Window::SCOPE_USER, $window['scope']);
    $this->assertSame('PT1H', $window['period']);
    $this->assertSame('1 hour', $window['label']);
    $this->assertEqualsWithDelta(0.75, $window['remaining'], 1e-9);
    $this->assertFalse($window['reached']);
    $this->assertSame($row->created() + 3600, $window['reset_at'], 'The fraction next rises when the leg ages out.');
    $this->assertStringNotContainsStringIgnoringCase('usd', $body, 'No dollar figure leaves the server.');
    $this->assertStringNotContainsString('0.25', $body);

    // Another editor's meter is their own: the whole cap is left and
    // nothing is inside to age out.
    $this->loginAsEditor();
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $window = Json::decode($this->getSession()->getPage()->getContent())['windows'][0];
    $this->assertSame(1.0, $window['remaining']);
    $this->assertNull($window['reset_at']);

    // An administrator over a site window and a user window together:
    // the bypass skips the user window only, so the answer is the site
    // window alone, summing the first editor's quarter against the site's
    // dollar.
    $this->config(CostResolver::SETTINGS)
      ->set(UsageGate::CAPS_KEY, [
        ['scope' => Window::SCOPE_USER, 'period' => 'PT1H', 'usd' => 1.0, 'anchor' => ''],
        ['scope' => Window::SCOPE_SITE, 'period' => 'P1M', 'usd' => 1.0, 'anchor' => ''],
      ])
      ->save();
    $admin = $this->drupalCreateUser([], NULL, TRUE);
    $this->assertTrue($admin->hasPermission(UsageGate::BYPASS_PERMISSION), 'The administrator holds the bypass.');
    $this->drupalLogin($admin);
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $answer = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertCount(1, $answer['windows'], 'The site window alone: the bypass skips the user window only.');
    $this->assertSame(Window::SCOPE_SITE, $answer['windows'][0]['scope']);
    $this->assertSame('P1M', $answer['windows'][0]['period']);
    $this->assertEqualsWithDelta(0.75, $answer['windows'][0]['remaining'], 1e-9, 'The site window sums every row on the site.');
    $this->assertFalse($answer['windows'][0]['reached']);

    // Logged out again, refused again.
    $this->drupalLogout();
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(403);
  }

}
