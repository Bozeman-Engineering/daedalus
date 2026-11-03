<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Unit;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\HostnameFilter;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_ai\ModelChoice;
use Drupal\daedalus_ai\ModelChoiceOption;
use Drupal\daedalus_ui\EditorSettings;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The choice resolver: the curated list, the permission, the stored key.
 *
 * The editor-preference lane accepts any string, so the stored value is
 * untrusted and this resolver is where it is validated: a choice counts
 * only when the account holds the permission and the key is in the
 * curated list; everything else answers NULL so the caller runs the site's
 * defaults, and a key that is not in the list is a notice, never a throw.
 *
 * Every curated row names the managed provider, the one the chat runs on
 * without a choice (the assistant's own llm_provider, else the site's
 * default chat provider); a row on another provider is refused the way a
 * malformed row is, since a choice switches the model, never the provider.
 */
#[Group('daedalus_ai')]
final class ModelChoiceTest extends UnitTestCase {

  /**
   * The provider the chat runs on in these tests.
   */
  private const MANAGED = 'daedalus_anthropic';

  /**
   * The curated rows the tests run against, as config stores them.
   */
  private const CURATED = [
    ['provider' => self::MANAGED, 'model' => 'claude-haiku-5-5', 'label' => 'Fast'],
    ['provider' => self::MANAGED, 'model' => 'claude-sonnet-5-5', 'label' => ''],
  ];

  /**
   * An empty list answers no choices and no current choice.
   */
  public function testEmptyList(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('notice');
    $choice = $this->resolver([], 'daedalus_anthropic__claude-haiku-5-5', $logger);
    $this->assertSame([], $choice->choices());
    $this->assertFalse($choice->hasChoices());
    $this->assertNull($choice->current($this->account(TRUE)));
  }

  /**
   * The list is read in config order with the key in the option grammar.
   */
  public function testChoices(): void {
    $choices = $this->resolver(self::CURATED, NULL)->choices();
    $this->assertCount(2, $choices);
    $this->assertContainsOnlyInstancesOf(ModelChoiceOption::class, $choices);
    $this->assertSame('daedalus_anthropic', $choices[0]->provider);
    $this->assertSame('claude-haiku-5-5', $choices[0]->model);
    $this->assertSame('Fast', $choices[0]->label);
    $this->assertSame('daedalus_anthropic__claude-haiku-5-5', $choices[0]->key());
    // An empty label is no label.
    $this->assertNull($choices[1]->label);
    $this->assertSame(self::CURATED[0], $choices[0]->toConfig());
  }

  /**
   * A stored key in the list, with the permission, answers the option.
   */
  public function testStoredKeyInTheList(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('notice');
    $current = $this->resolver(self::CURATED, 'daedalus_anthropic__claude-sonnet-5-5', $logger)
      ->current($this->account(TRUE));
    $this->assertInstanceOf(ModelChoiceOption::class, $current);
    $this->assertSame('claude-sonnet-5-5', $current->model);
  }

  /**
   * Without the permission the stored key is not even read.
   */
  public function testWithoutThePermission(): void {
    $settings = $this->createMock(EditorSettings::class);
    $settings->expects($this->never())->method('getFor');
    $choice = new ModelChoice(
      $this->configFactory(self::CURATED),
      $settings,
      $this->createMock(LoggerInterface::class),
      $this->entityTypeManager(self::MANAGED),
      $this->providerManager(NULL),
    );
    $this->assertNull($choice->current($this->account(FALSE)));
  }

  /**
   * A stored key not in the list answers NULL and one notice per request.
   */
  public function testStoredKeyNotInTheList(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('notice')->with(
      $this->stringContains('@value'),
      $this->callback(static fn (array $context) => $context['@value'] === 'daedalus_anthropic__claude-opus-5-5'
        && $context['@uid'] === 7
        && $context['@why'] === 'is not in the curated list'),
    );
    $choice = $this->resolver(self::CURATED, 'daedalus_anthropic__claude-opus-5-5', $logger);
    $account = $this->account(TRUE);
    $this->assertNull($choice->current($account));
    // The second read in the same request logs nothing more.
    $this->assertNull($choice->current($account));
  }

  /**
   * A malformed stored value (not a string) answers NULL with a notice.
   */
  public function testMalformedStoredValue(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('notice')->with(
      $this->stringContains('@value'),
      $this->callback(static fn (array $context) => $context['@why'] === 'is not a string'),
    );
    $this->assertNull($this->resolver(self::CURATED, ['not' => 'a string'], $logger)->current($this->account(TRUE)));
  }

  /**
   * Nothing stored, or an empty string, answers NULL without a notice.
   */
  public function testNothingStored(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('notice');
    $this->assertNull($this->resolver(self::CURATED, NULL, $logger)->current($this->account(TRUE)));
    $this->assertNull($this->resolver(self::CURATED, '', $logger)->current($this->account(TRUE)));
  }

  /**
   * A malformed config row fails visibly.
   */
  public function testMalformedRow(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->resolver([['provider' => 'daedalus_anthropic']], NULL)->choices();
  }

  /**
   * The managed provider is the assistant's own when it names one.
   */
  public function testManagedProviderFromTheAssistant(): void {
    // The site default names another provider; the assistant's wins.
    $choice = $this->resolver(self::CURATED, NULL, assistantProvider: self::MANAGED, siteDefault: 'anthropic');
    $this->assertSame(self::MANAGED, $choice->managedProvider());
    $this->assertCount(2, $choice->choices());
  }

  /**
   * An assistant on "__default__", with no provider, or missing: the site's.
   */
  public function testManagedProviderFromTheSiteDefault(): void {
    foreach ([ModelChoice::DEFAULT_PROVIDER, '', NULL] as $assistant_provider) {
      $choice = $this->resolver(self::CURATED, NULL, assistantProvider: $assistant_provider, siteDefault: self::MANAGED);
      $this->assertSame(self::MANAGED, $choice->managedProvider(), var_export($assistant_provider, TRUE));
      $this->assertCount(2, $choice->choices());
    }
    // No assistant configured at all: the site's too.
    $choice = $this->resolver(self::CURATED, NULL, assistantId: NULL, siteDefault: self::MANAGED);
    $this->assertSame(self::MANAGED, $choice->managedProvider());
  }

  /**
   * Neither the assistant nor the site names a chat provider.
   *
   * There is nothing to curate: managedProvider() throws naming what is
   * missing, choices() throws the same once rows exist, and an empty list
   * never asks (a site with no provider still answers "nothing curated").
   */
  public function testNoManagedProvider(): void {
    $empty = $this->resolver([], NULL, assistantProvider: '', siteDefault: NULL);
    $this->assertSame([], $empty->choices());
    $this->assertFalse($empty->hasChoices());

    $curated = $this->resolver(self::CURATED, NULL, assistantProvider: ModelChoice::DEFAULT_PROVIDER, siteDefault: NULL);
    try {
      $curated->managedProvider();
      $this->fail('No managed provider resolves.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('the assistant "daedalus"', $e->getMessage());
      $this->assertStringContainsString('no default chat provider', $e->getMessage());
    }
    $this->expectException(\RuntimeException::class);
    $curated->choices();
  }

  /**
   * A row on a provider other than the managed one is refused by name.
   */
  public function testForeignRowRefused(): void {
    $rows = [
      self::CURATED[0],
      ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'label' => ''],
    ];
    $choice = $this->resolver($rows, 'daedalus_anthropic__claude-haiku-5-5');
    try {
      $choice->choices();
      $this->fail('A foreign row is refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('anthropic__claude-haiku-5-5', $e->getMessage());
      $this->assertStringContainsString('"anthropic"', $e->getMessage());
      $this->assertStringContainsString('"daedalus_anthropic"', $e->getMessage());
    }
    // current() reads the list, so it refuses the same way: a stored key on
    // the managed provider does not rescue a list with a foreign row.
    $this->expectException(\InvalidArgumentException::class);
    $choice->current($this->account(TRUE));
  }

  /**
   * The option grammar splits at the first separator.
   */
  public function testSplitKey(): void {
    $this->assertSame(['daedalus_anthropic', 'claude-haiku-5-5'], ModelChoiceOption::splitKey('daedalus_anthropic__claude-haiku-5-5'));
    $this->assertSame(['p', 'm__x'], ModelChoiceOption::splitKey('p__m__x'));
    $this->assertNull(ModelChoiceOption::splitKey('nothing'));
    $this->assertNull(ModelChoiceOption::splitKey('__m'));
    $this->assertNull(ModelChoiceOption::splitKey('p__'));
  }

  /**
   * Builds a resolver over curated rows and one stored value.
   *
   * @param array $curated
   *   The model_choices rows.
   * @param mixed $stored
   *   What the editor-preference lane answers for daedalus_ai/model.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   The logger, or an indifferent mock.
   * @param string|null $assistantProvider
   *   The configured assistant's llm_provider, or NULL for an assistant
   *   that does not exist.
   * @param string|null $siteDefault
   *   The site's default chat provider id, or NULL for none.
   * @param string|null $assistantId
   *   The assistant daedalus_ai.settings names, or NULL for none.
   */
  private function resolver(array $curated, mixed $stored, ?LoggerInterface $logger = NULL, ?string $assistantProvider = self::MANAGED, ?string $siteDefault = NULL, ?string $assistantId = 'daedalus'): ModelChoice {
    $settings = $this->createMock(EditorSettings::class);
    $settings->method('getFor')
      ->with($this->isInstanceOf(AccountInterface::class), ModelChoice::NAMESPACE, ModelChoice::KEY)
      ->willReturn($stored);
    return new ModelChoice(
      $this->configFactory($curated, $assistantId),
      $settings,
      $logger ?? $this->createMock(LoggerInterface::class),
      $this->entityTypeManager($assistantProvider),
      $this->providerManager($siteDefault),
    );
  }

  /**
   * The daedalus_ai.settings config with the curated rows and the assistant id.
   */
  private function configFactory(array $curated, ?string $assistantId = 'daedalus'): ConfigFactoryInterface {
    return $this->getConfigFactoryStub([
      ModelChoice::SETTINGS => [
        ModelChoice::CHOICES_KEY => $curated,
        ModelChoice::ASSISTANT_KEY => $assistantId,
      ],
    ]);
  }

  /**
   * An ai_assistant storage answering one assistant, "daedalus".
   *
   * @param string|null $provider
   *   The assistant's llm_provider, or NULL for no assistant at all.
   */
  private function entityTypeManager(?string $provider): EntityTypeManagerInterface {
    $assistant = NULL;
    if ($provider !== NULL) {
      $assistant = $this->createMock(ConfigEntityInterface::class);
      $assistant->method('get')->with('llm_provider')->willReturn($provider);
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(static fn (string $id) => $id === 'daedalus' ? $assistant : NULL);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('ai_assistant')->willReturn($storage);
    return $manager;
  }

  /**
   * The real (final) provider manager over ai.settings' chat default.
   *
   * @param string|null $provider
   *   The default chat provider id, or NULL for no default.
   */
  private function providerManager(?string $provider): AiProviderPluginManager {
    $container = $this->createMock(ContainerInterface::class);
    $behavior = ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE;
    $container->method('get')->willReturnMap([
      ['event_dispatcher', $behavior, $this->createMock(EventDispatcherInterface::class)],
      ['logger.factory', $behavior, $this->createMock(LoggerChannelFactoryInterface::class)],
      [
        'config.factory',
        $behavior,
        $this->getConfigFactoryStub([
          'ai.settings' => [
            'default_providers' => $provider === NULL ? [] : ['chat' => ['provider_id' => $provider, 'model_id' => 'm']],
          ],
        ]),
      ],
    ]);
    return new AiProviderPluginManager(
      new \ArrayObject(),
      $this->createMock(CacheBackendInterface::class),
      $this->createMock(ModuleHandlerInterface::class),
      $container,
      $this->createMock(MessengerInterface::class),
      $this->createMock(UuidInterface::class),
      $this->createMock(HostnameFilter::class),
    );
  }

  /**
   * An account with or without the permission, uid 7.
   */
  private function account(bool $permitted): AccountInterface&MockObject {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(static fn (string $permission) => $permitted && $permission === ModelChoice::PERMISSION);
    $account->method('id')->willReturn(7);
    return $account;
  }

}
