<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_ai\Controller\DeferredProcessingController;
use Drupal\daedalus_ai\DeferredProcessor\DeferredProcessorManager;
use Drupal\daedalus_ai\DeferredProcessor\ImageGenerationProcessor;
use Drupal\daedalus_ai\MutatedTurn;
use Drupal\daedalus_ai\PendingMedia\GenerationJob;
use Drupal\daedalus_ai_ledger\CostResolver;
use Drupal\daedalus_ai_ledger\UsageGate;
use Drupal\daedalus_ai_ledger\Window;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\Tests\daedalus_ai_ledger\Traits\DaedalusAiServicesTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Route;

/**
 * Tests the gates on the deferred generation routes.
 *
 * Two routes spend on the AI provider outside a chat turn: the one that
 * runs a batch's jobs and the one that retries a batch's failures. Both
 * take the session's header token and ask for the AI permission; the retry
 * road, which makes a model call and generates again, passes the usage
 * gate and the image generation switch first, generates only from the job
 * the server stored (never from the request), and takes a bounded list.
 *
 * The daedalus_ai module is not enabled (see the base class), so the
 * routes are read from the module's routing file and judged by core's own
 * access checks, and the controller is built from its real collaborators.
 * The provider is the one boundary stood in for: the processor is the real
 * one with its two provider calls (the prompt rewrite, the generation)
 * recorded instead of made.
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class DeferredRouteGateTest extends DaedalusAiKernelTestBase {

  use DaedalusAiServicesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_ai_ledger',
  ];

  /**
   * The media uuid a stored job waits under.
   */
  private const MEDIA_UUID = '6f1c2f0a-3b7e-4c59-9a55-0d9f6f4b1c11';

  /**
   * The processor, its provider calls recorded.
   *
   * @var \Drupal\daedalus_ai\DeferredProcessor\ImageGenerationProcessor&object{rewrites: array, generated: array}
   */
  private ImageGenerationProcessor $processor;

  /**
   * The controller under test.
   */
  private DeferredProcessingController $controller;

  /**
   * The page the routes name.
   */
  private EntityTest $page;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $this->registerDaedalusAiServices($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('ai_usage');
    $this->installConfig(['daedalus_ai_ledger']);

    // An ordinary account (the base created uid 1 first) that may update
    // the media a job fills. The routes' permissions are judged on their
    // own, below; the controller asks the gates.
    $this->currentUser = $this->setUpCurrentUser(
      ['name' => 'ai editor'],
      ['view media', 'update any media', 'use daedalus ui edit mode'],
    );

    Media::create([
      'bundle' => 'image',
      'name' => 'Pending',
      'uuid' => self::MEDIA_UUID,
    ])->save();
    $this->page = EntityTest::create(['name' => 'Page']);
    $this->page->save();

    $this->processor = new class(
      $this->container->get('ai.provider'),
      $this->container->get('entity_type.manager'),
      $this->jobStore,
      $this->container->get('logger.channel.default'),
    ) extends ImageGenerationProcessor {

      /**
       * What each rewrite call was handed: [prompt, alt text, reason] rows.
       *
       * @var array
       */
      public array $rewrites = [];

      /**
       * The jobs generation was asked for.
       *
       * @var \Drupal\daedalus_ai\PendingMedia\GenerationJob[]
       */
      public array $generated = [];

      /**
       * {@inheritdoc}
       */
      protected function rewritePrompts(array $jobs): ?array {
        $this->rewrites[] = array_map(
          static fn (array $pair): array => [$pair[0]->prompt, $pair[0]->altText, $pair[1]],
          $jobs,
        );
        return array_map(static fn (array $pair): string => 'rewritten: ' . $pair[0]->prompt, $jobs);
      }

      /**
       * {@inheritdoc}
       */
      protected function generateImageFile(GenerationJob $job): ?FileInterface {
        $this->generated[] = $job;
        return NULL;
      }

    };
    $manager = new DeferredProcessorManager();
    $manager->addProcessor($this->processor);

    $this->controller = new DeferredProcessingController(
      $manager,
      $this->container->get('tempstore.private'),
      $this->createMock(MutatedTurn::class),
      $this->container->get('daedalus_blueprint.storage_handler'),
      $this->container->get('daedalus_ui.ui'),
      $this->container->get('daedalus_blueprint.host_resolver'),
      $this->gate,
      $this->container->get('daedalus_ai_ledger.usage_gate'),
    );
    $this->controller->setStringTranslation($this->container->get('string_translation'));
  }

  /**
   * Both routes take a POST with the header token and the AI permission.
   */
  public function testRoutesAskForTheTokenAndTheAiPermission(): void {
    $routes = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/daedalus_ai.routing.yml'));
    $permission_check = $this->container->get('access_check.permission');
    $token_check = $this->container->get('access_check.header.csrf');

    $ai_editor = $this->holding(['use daedalus ui edit mode', 'use ai assistant']);
    $editor_without_ai = $this->holding(['use daedalus ui edit mode']);
    $ai_without_editor = $this->holding(['use ai assistant']);

    // A request that carries a session cookie: the header check judges
    // only a request with a session, the one a forged post rides.
    $with_session = function (?string $token): Request {
      $request = Request::create('/daedalus-ai/deferred/entity_test/1/default', 'POST');
      $request->cookies->set($this->container->get('session_configuration')->getOptions($request)['name'], 'a-session');
      if ($token !== NULL) {
        $request->headers->set('X-CSRF-Token', $token);
      }
      return $request;
    };
    $token = $this->container->get('csrf_token')->get(CsrfRequestHeaderAccessCheck::TOKEN_KEY);

    foreach (['daedalus_ai.deferred_processing', 'daedalus_ai.deferred_retry'] as $name) {
      $definition = $routes[$name];
      $route = new Route($definition['path'], $definition['defaults'], $definition['requirements'], $definition['options'], '', [], $definition['methods']);

      $this->assertSame(['POST'], $route->getMethods(), "$name takes a POST only.");
      $this->assertSame('entity.update', $route->getRequirement('_entity_access'), "$name still asks for update on the page.");

      $this->assertTrue($permission_check->access($route, $ai_editor)->isAllowed(), "$name: an editor who may use the assistant passes.");
      $this->assertFalse($permission_check->access($route, $editor_without_ai)->isAllowed(), "$name: an editor without the AI permission is refused.");
      $this->assertFalse($permission_check->access($route, $ai_without_editor)->isAllowed(), "$name: the AI permission without Edit Mode is refused.");

      $this->assertTrue($token_check->applies($route), "$name carries the header token requirement.");
      $this->assertTrue($token_check->access($with_session(NULL), $this->currentUser)->isForbidden(), "$name: a session's POST without the token is refused.");
      $this->assertTrue($token_check->access($with_session('forged'), $this->currentUser)->isForbidden(), "$name: a wrong token is refused.");
      $this->assertTrue($token_check->access($with_session($token), $this->currentUser)->isAllowed(), "$name: the session's token passes.");
    }
  }

  /**
   * A retry generates from the stored job, whatever the request carries.
   */
  public function testRetryGeneratesFromTheStoredJob(): void {
    $this->storeJob();

    $answer = $this->retry([
      [
        'type' => ImageGenerationProcessor::TYPE,
        'media_uuid' => self::MEDIA_UUID,
        'reason' => 'Rejected by moderation.',
        'retryable' => TRUE,
        // What a forged request would steer the provider with.
        'data' => [
          'prompt' => 'the request body prompt',
          'alt_text' => 'the request body alt',
          'configuration' => ['api_key' => 'from-the-request', 'base_url' => 'https://example.invalid'],
        ],
        'target' => 'block:abc:field_image',
      ],
      // A second entry for the same media would generate twice.
      [
        'type' => ImageGenerationProcessor::TYPE,
        'media_uuid' => self::MEDIA_UUID,
        'reason' => 'Again.',
      ],
    ]);

    $this->assertSame([[['a stored prompt', 'Stored alt', 'Rejected by moderation.']]], $this->processor->rewrites, 'The rewrite reads the stored prompt and alt text, once per media.');
    $this->assertCount(1, $this->processor->generated, 'One generation per media uuid.');
    $job = $this->processor->generated[0];
    $this->assertSame('rewritten: a stored prompt', $job->prompt);
    $this->assertSame('Stored alt', $job->altText);
    $this->assertSame(['aspect_ratio' => '16:9'], $job->configuration, 'The provider configuration is the stored job\'s.');
    $this->assertSame(['aspect_ratio' => '16:9'], $this->jobStore->get(self::MEDIA_UUID)->configuration, 'The re-registered job keeps the stored configuration.');

    // The stand-in generated nothing, so the job is reported failed again
    // with the target it came in with.
    $this->assertCount(1, $answer['failures']);
    $this->assertSame(self::MEDIA_UUID, $answer['failures'][0]['media_uuid']);
    $this->assertSame('block:abc:field_image', $answer['failures'][0]['target']);
    $this->assertTrue($answer['failures'][0]['retryable']);
  }

  /**
   * A failure with no stored job of this user's is not retried.
   */
  public function testRetryWithoutStoredJobSpendsNothing(): void {
    // The job belongs to another account: the store is per user.
    $owner = $this->currentUser;
    $this->storeJob();
    $this->setCurrentUser($this->createUser(['view media', 'update any media', 'use daedalus ui edit mode']));

    $answer = $this->retry([
      [
        'type' => ImageGenerationProcessor::TYPE,
        'media_uuid' => self::MEDIA_UUID,
        'reason' => 'Rejected.',
        'data' => ['prompt' => 'spend on this', 'configuration' => ['api_key' => 'from-the-request']],
      ],
      ['type' => 'no_such_processor', 'media_uuid' => 'other', 'reason' => 'Unknown type.'],
    ]);

    $this->assertSame([], $this->processor->rewrites, 'No model call for a job the caller does not hold.');
    $this->assertSame([], $this->processor->generated);
    $this->assertSame([
      [
        'type' => ImageGenerationProcessor::TYPE,
        'media_uuid' => self::MEDIA_UUID,
        'reason' => 'Rejected.',
        'retryable' => FALSE,
        'data' => [],
        'target' => NULL,
      ],
      [
        'type' => 'no_such_processor',
        'media_uuid' => 'other',
        'reason' => 'Unknown type.',
        'retryable' => FALSE,
        'data' => [],
        'target' => NULL,
      ],
    ], $answer['failures'], 'Unretried failures come back not retryable, without the request\'s data.');

    $this->setCurrentUser($owner);
    $this->assertSame('a stored prompt', $this->jobStore->get(self::MEDIA_UUID)->prompt, 'The owner\'s job is untouched.');
  }

  /**
   * The image switch and the usage gate refuse a retry before it spends.
   */
  public function testRetryPassesTheSwitchAndTheUsageGate(): void {
    $this->storeJob();
    $failures = [
      ['type' => ImageGenerationProcessor::TYPE, 'media_uuid' => self::MEDIA_UUID, 'reason' => 'Rejected.'],
    ];

    // The site-wide switch.
    $this->setSiteGate(FALSE);
    $answer = $this->retry($failures);
    $this->assertSame('Image generation is turned off.', $answer['failures'][0]['reason']);
    $this->assertFalse($answer['failures'][0]['retryable']);
    $this->setSiteGate(TRUE);

    // The user's own switch.
    $this->setUserGate('0');
    $answer = $this->retry($failures);
    $this->assertSame('Image generation is turned off.', $answer['failures'][0]['reason']);
    $this->assertFalse($answer['failures'][0]['retryable']);
    $this->setUserGate('1');

    // A user window at its cap: the gate a chat turn meets in the runner.
    $this->config(CostResolver::SETTINGS)
      ->set(UsageGate::CAPS_KEY, [
        ['scope' => Window::SCOPE_USER, 'period' => 'PT1H', 'usd' => 0.0, 'anchor' => ''],
      ])
      ->save();
    $answer = $this->retry($failures);
    $this->assertStringContainsString('reached the AI usage limit', $answer['failures'][0]['reason']);
    $this->assertFalse($answer['failures'][0]['retryable']);

    $this->assertSame([], $this->processor->rewrites, 'No refusal reached the model.');
    $this->assertSame([], $this->processor->generated, 'No refusal generated.');
    $this->assertSame('a stored prompt', $this->jobStore->get(self::MEDIA_UUID)->prompt, 'A refused retry leaves the job as stored.');

    // With the cap lifted the same request runs.
    $this->config(CostResolver::SETTINGS)->set(UsageGate::CAPS_KEY, [])->save();
    $this->retry($failures);
    $this->assertCount(1, $this->processor->rewrites);
    $this->assertCount(1, $this->processor->generated);
  }

  /**
   * The failures list is bounded and its entries are checked.
   */
  public function testRetryFailuresListIsBounded(): void {
    $this->storeJob();
    $failure = static fn (int $i): array => [
      'type' => ImageGenerationProcessor::TYPE,
      'media_uuid' => "media-$i",
      'reason' => 'Rejected.',
    ];

    $refused = [
      'one over the ceiling' => array_map($failure, range(0, DeferredProcessingController::MAX_RETRY_FAILURES)),
      'no list' => NULL,
      'an empty list' => [],
      'an entry that is not a map' => ['image_generation'],
      'an entry without a media uuid' => [['type' => ImageGenerationProcessor::TYPE]],
      'an entry without a type' => [['media_uuid' => self::MEDIA_UUID]],
    ];
    foreach ($refused as $case => $failures) {
      try {
        $this->retry($failures);
        $this->fail("A retry with $case was accepted.");
      }
      catch (BadRequestHttpException) {
        // Refused before anything ran.
      }
    }
    $this->assertSame([], $this->processor->rewrites);
    $this->assertSame([], $this->processor->generated);

    // The ceiling itself passes, and an overlong reason is cut before the
    // model sees it.
    $at_ceiling = array_map($failure, range(1, DeferredProcessingController::MAX_RETRY_FAILURES - 1));
    $at_ceiling[] = [
      'type' => ImageGenerationProcessor::TYPE,
      'media_uuid' => self::MEDIA_UUID,
      'reason' => str_repeat('x', DeferredProcessingController::MAX_REASON_LENGTH + 500),
    ];
    $answer = $this->retry($at_ceiling);
    $this->assertCount(DeferredProcessingController::MAX_RETRY_FAILURES, $answer['failures']);
    $this->assertCount(1, $this->processor->rewrites[0], 'Only the failure with a stored job reaches the rewrite.');
    $this->assertSame(DeferredProcessingController::MAX_REASON_LENGTH, mb_strlen($this->processor->rewrites[0][0][2]));
  }

  /**
   * An account double holding exactly the given permissions.
   *
   * The AI permission is daedalus_ai's, a module this test does not enable,
   * and a saved role drops a permission no enabled module declares; the
   * permission check only asks the account, so a double answers it.
   *
   * @param string[] $permissions
   *   The permissions.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  private function holding(array $permissions): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => in_array($permission, $permissions, TRUE),
    );
    return $account;
  }

  /**
   * Stores the current user's pending job under the media uuid.
   */
  private function storeJob(): void {
    $this->jobStore->set(new GenerationJob(
      mediaUuid: self::MEDIA_UUID,
      prompt: 'a stored prompt',
      altText: 'Stored alt',
      configuration: ['aspect_ratio' => '16:9'],
    ));
  }

  /**
   * Posts a failures list to the retry controller.
   *
   * @param mixed $failures
   *   The body's `failures` value; NULL leaves the key out.
   *
   * @return array
   *   The decoded answer.
   */
  private function retry(mixed $failures): array {
    $body = $failures === NULL ? [] : ['failures' => $failures];
    $request = Request::create('/daedalus-ai/deferred-retry/entity_test/' . $this->page->id() . '/default', 'POST', content: Json::encode($body));
    $response = $this->controller->retry($request, 'entity_test', $this->page, 'default');
    return Json::decode($response->getContent());
  }

}
