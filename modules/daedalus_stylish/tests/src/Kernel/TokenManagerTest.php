<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_stylish\Controller\TokenApiController;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\TokenValidationException;
use Drupal\daedalus_stylish\TokenDeleteBatch;
use Drupal\daedalus_stylish\TokenManager;
use Drupal\daedalus_stylish\TokenUsage;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * The token usage lane: usage, rename, revert and delete in use.
 *
 * The swatch gesture manager's twin at token grain. A token delivers as
 * a custom property, so its usage is the count of sources whose saved
 * values reference that property (TokenUsage), read live behind the
 * palette row; a REFERENCED token is never deleted from under them (the
 * rewrite-then-delete batch behind the "delete tokens in use" grade,
 * every var() becoming the literal), and because the name IS the custom
 * property a rename respells every reference (the rewrite-then-rename
 * batch) before the record moves. A package token cannot be deleted or
 * renamed on a consuming site; a site override dropped reveals it and
 * touches no reference. Revert converges a token toward the disk. The
 * routes adapt onto the manager and answer the materials delta.
 */
#[CoversClass(TokenManager::class)]
#[CoversClass(TokenDeleteBatch::class)]
#[CoversClass(TokenApiController::class)]
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class TokenManagerTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * The aurora package's space/gutter record as the skins file ships it.
   */
  protected const GUTTER = [
    'value' => ['default' => '2rem', 'narrow' => '1rem'],
    'description' => '',
    'offered' => TRUE,
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_control_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    // The vocabulary the shipped skin and the gestures validate against.
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'color' => ['label' => 'Color', 'shape' => 'color'],
        'text' => ['label' => 'Type scale', 'shape' => 'dimension'],
      ],
      'base' => [
        'h2' => ['property_groups' => ['typography' => []]],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->setUpCurrentUser([], [], TRUE);
    // Installing the module syncs aurora and bare: two shipped skins with
    // living disk sources.
    \Drupal::service('module_installer')->install(['daedalus_stylish_skins_test']);
  }

  /**
   * The manager, resolved late (module installs rebuild the container).
   */
  protected function manager(): TokenManager {
    return \Drupal::service('daedalus_stylish.token_manager');
  }

  /**
   * Loads a skin by machine name, fresh from storage.
   */
  protected function loadSkin(string $machine_name): DaedalusStylishSkinInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_skin');
    $storage->resetCache();
    $skins = $storage->loadByProperties(['machine_name' => $machine_name]);
    $skin = reset($skins);
    assert($skin instanceof DaedalusStylishSkinInterface);
    return $skin;
  }

  /**
   * Creates and activates a skin the site made itself: no provider.
   *
   * The space/4 token is conditioned, to pin the literal rule of the
   * delete rewrite; space/double references it, the token-to-token store.
   */
  protected function activateOwnSkin(): DaedalusStylishSkinInterface {
    $skin = DaedalusStylishSkin::create(['label' => 'Mine', 'machine_name' => 'mine', 'status' => 1]);
    $skin->setPackageTokens([
      'space' => [
        '2' => '0.5rem',
        '4' => ['default' => '1rem', 'narrow' => '0.75rem'],
        '8' => '2rem',
        'double' => 'calc(var(--space-4) * 2)',
      ],
    ]);
    $skin->setPackageDefaults(['kind:container' => ['' => ['default' => ['padding' => 'var(--space-4, 1rem)']]]]);
    $skin->save();
    $this->activateSkin($skin);
    return $this->loadSkin('mine');
  }

  /**
   * Creates a saved map host referencing the given custom property.
   */
  protected function createReferencingHost(string $variable, string $property = 'padding'): EntityTest {
    $host = EntityTest::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        'block:' . $this->container->get('uuid')->generate() => ['default' => [$property => sprintf('calc(var(%s, 1rem) * 2)', $variable)]],
      ]),
    ]);
    $host->save();
    return $host;
  }

  /**
   * The error codes by path a gesture refuses with.
   *
   * @return array<string, string>
   *   path => code.
   */
  protected function refusal(callable $gesture): array {
    try {
      $gesture();
    }
    catch (TokenValidationException | MaterialGestureException $exception) {
      $codes = [];
      foreach ($exception->getErrors() as $error) {
        $codes[$error->getPath()] = $error->getCode();
      }
      return $codes;
    }
    $this->fail('The gesture was expected to refuse.');
  }

  /**
   * The materials delta the request carries, drained.
   */
  protected function delta(): array {
    return $this->container->get('daedalus_blueprint.materials_change_set')->flush();
  }

  /**
   * Runs the staged batch's operations the way the batch runner would.
   *
   * @return array
   *   The batch context's results.
   */
  protected function runBatch(string $tail): array {
    $batch = batch_get();
    $operations = $batch['sets'][0]['operations'] ?? [];
    $this->assertNotSame([], $operations, 'a batch was staged');
    $this->assertSame([TokenDeleteBatch::class, $tail], end($operations)[0]);
    $context = ['results' => []];
    foreach ($operations as [$callback, $arguments]) {
      if ($callback[1] === 'rewriteSource') {
        $this->assertSame(TokenDeleteBatch::class, $callback[0]);
        TokenDeleteBatch::rewriteSource($arguments[0], $arguments[1], $arguments[2], $arguments[3], $context);
      }
      else {
        TokenDeleteBatch::$tail($arguments[0], $context);
      }
    }
    return $context['results'];
  }

  /**
   * The usage read: the live count, the layer, the shipped state, per token.
   */
  public function testUsageRead(): void {
    $this->activateSkin('aurora');
    $manager = $this->manager();
    $skin = $manager->activeSkin();
    $manager->mint($skin, 'space', 'hero-gap', '4rem');
    $this->createReferencingHost('--space-gutter');
    $this->createReferencingHost('--space-gutter', 'margin');
    $this->createReferencingHost('--space-hero-gap');

    $this->assertSame('--space-gutter', $manager->variable('space', 'gutter'));
    $this->assertSame(2, $manager->usages('space', 'gutter'));
    $this->assertSame(1, $manager->usages('space', 'hero-gap'));
    $this->assertSame(0, $manager->usages('space', 'ghost'));
    $this->assertSame('package', $manager->layer($skin, 'space', 'gutter'));
    $this->assertSame('site', $manager->layer($skin, 'space', 'hero-gap'));
    $this->assertSame('unmodified', $manager->shippedState($skin, 'space', 'gutter'));
    $this->assertNull($manager->shippedState($skin, 'space', 'hero-gap'), 'no disk file ships a site-minted token');

    $manager->update($skin, 'space', 'gutter', '3rem');
    $this->assertSame('both', $manager->layer($skin, 'space', 'gutter'));
    $this->assertSame('modified', $manager->shippedState($skin, 'space', 'gutter'));

    // The handle the batches carry: the record as worn and the variable.
    $token = $manager->resolve($skin, 'space', 'gutter');
    $this->assertSame('3rem', $token->literal());
    $this->assertSame($skin->id() . ':space/gutter', $token->batchId());
    $this->assertNull($manager->resolve($skin, 'space', 'ghost'));

    // The endpoint: the count beside the management facts.
    $controller = TokenApiController::create($this->container);
    $this->assertSame(404, $controller->usage('space', 'ghost')->getStatusCode());
    $this->assertSame(404, $controller->usage('ghost', 'gutter')->getStatusCode(), 'a scale that does not hold the name names nothing');
    $response = $controller->usage('space', 'gutter');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([
      'id' => 'space/gutter',
      'scale' => 'space',
      'name' => 'gutter',
      'variable' => '--space-gutter',
      'usages' => 2,
      'layer' => 'both',
      'locked' => TRUE,
      'provider' => 'daedalus_stylish_skins_test',
      'shipped_state' => 'modified',
    ], json_decode((string) $response->getContent(), TRUE));
    // The lock lifted: the same read says the package layer is writable.
    $this->setSetting('daedalus_blueprint_developer', TRUE);
    $this->assertFalse(json_decode((string) $controller->usage('space', 'gutter')->getContent(), TRUE)['locked']);
    $this->setSetting('daedalus_blueprint_developer', FALSE);

    // No active skin: the address itself fails, the gesture shape.
    $this->deactivateDesignSystem();
    $response = $controller->usage('space', 'gutter');
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('STYLE_SKIN_NONE_ACTIVE', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
  }

  /**
   * A site override dropped reveals the package's token: no rewrite, no grade.
   */
  public function testRevealTouchesNoReference(): void {
    $this->activateSkin('aurora');
    $skin = $this->manager()->activeSkin();
    $this->manager()->update($skin, 'space', 'gutter', '3rem');
    $this->createReferencingHost('--space-gutter');

    $this->setUpCurrentUser([], ['delete tokens']);
    $result = $this->container->get('daedalus_stylish.materials_provider')->remove(MaterialKind::Token, 'space/gutter', []);
    $this->assertSame(0, $result->staged);
    $this->assertInstanceOf(DaedalusStylishSkinInterface::class, $result->entity, 'the token lives on');
    $this->assertSame(self::GUTTER, $this->loadSkin('aurora')->tokens()['space']['gutter']);
    $this->assertSame(1, $this->manager()->usages('space', 'gutter'), 'the reference still resolves');
  }

  /**
   * A referenced token refuses the base grade, and the op spine altogether.
   */
  public function testDeleteReferencedNeedsInUseGrade(): void {
    $this->activateSkin('aurora');
    $skin = $this->manager()->activeSkin();
    $this->manager()->mint($skin, 'space', 'hero-gap', '4rem');
    $this->createReferencingHost('--space-hero-gap');

    $this->setUpCurrentUser([], ['delete tokens']);
    $this->assertSame(['id' => 'STYLE_TOKEN_IN_USE'], $this->refusal(fn () => $this->manager()->deleteToken($skin, 'space', 'hero-gap')));

    // The in-use grade on the op spine (no batch to drive) refuses with
    // the count instead of stranding the references.
    $this->setUpCurrentUser([], ['delete tokens in use']);
    $provider = $this->container->get('daedalus_stylish.materials_provider');
    $this->assertSame(['id' => 'STYLE_TOKEN_IN_USE'], $this->refusal(fn () => $provider->remove(MaterialKind::Token, 'space/hero-gap', [])));
    $this->assertSame('4rem', $this->loadSkin('aurora')->tokens()['space']['hero-gap']['value'], 'nothing was deleted');

    // A package token refuses under the lock before any count is read.
    $this->createReferencingHost('--space-gutter');
    $this->assertSame(['id' => 'STYLE_TOKEN_SHIPPED_LOCKED'], $this->refusal(fn () => $this->manager()->deleteToken($skin, 'space', 'gutter')));
  }

  /**
   * The referenced delete: batch staged, var() rewritten, then the token goes.
   */
  public function testDeleteReferencedBatch(): void {
    $skin = $this->activateOwnSkin();
    $key = 'block:' . $this->container->get('uuid')->generate();
    $host = EntityTest::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        $key => ['default' => ['padding' => 'calc(var(--space-4, var(--space-2)) + var(--space-8))']],
      ]),
    ]);
    $host->save();
    $variant = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'kind:block']);
    $variant->setStyles(['' => ['default' => ['gap' => 'var(--space-4)']]]);
    $variant->save();

    // The gesture stages the Batch API job (one rewrite per referencing
    // source from the usage table's work list, then the delete) and
    // reports the loud count. Nothing is deleted in this request; the
    // skin counts once (its token and its default, one entity).
    $this->setUpCurrentUser([], ['delete tokens in use']);
    $this->assertSame(3, $this->manager()->deleteToken($skin, 'space', '4'));
    $this->assertTrue($this->manager()->has($this->loadSkin('mine'), 'space', '4'));
    $results = $this->runBatch('deleteToken');

    // Every reference is the token's DEFAULT literal now (the conditioned
    // nuance goes with the token), the whole var() with its fallback, in
    // all three stores and both skin tiers; the token, its usage rows and
    // the roster record are gone.
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test');
    $storage->resetCache([$host->id()]);
    $map = $storage->load($host->id())->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    $this->assertSame('calc(1rem + var(--space-8))', $map[$key]['default']['padding']);
    $variant_storage = $this->container->get('entity_type.manager')->getStorage('daedalus_variant');
    $variant_storage->resetCache([$variant->id()]);
    $this->assertSame(['' => ['default' => ['gap' => '1rem']]], $variant_storage->load($variant->id())->getStyles());
    $fresh = $this->loadSkin('mine');
    $this->assertSame(['2', '8', 'double'], array_map('strval', array_keys($fresh->packageTokens()['space'])));
    $this->assertSame('calc(1rem * 2)', $fresh->packageTokens()['space']['double']['value']);
    $this->assertSame('1rem', $fresh->packageDefaults()['kind:container']['']['default']['padding']);
    $this->assertSame('Deleted the space/4 token.', $fresh->getRevisionLogMessage());
    $this->assertSame(3, $results['rewritten']);
    $this->assertSame('space/4', $results['deleted']);
    $this->assertSame(0, $this->manager()->usages('space', '4'));
    $rows = (int) $this->container->get('database')->select(TokenUsage::TABLE)->condition('token', '--space-4')->countQuery()->execute()->fetchField();
    $this->assertSame(0, $rows);
  }

  /**
   * The delete endpoint: the two grades, the reveal, and the batch handoff.
   */
  public function testDeleteEndpoint(): void {
    $this->activateSkin('aurora');
    $skin = $this->manager()->activeSkin();
    $controller = TokenApiController::create($this->container);

    $this->setUpCurrentUser([], ['delete tokens', 'create tokens', 'update tokens']);
    $this->assertSame(404, $controller->delete('space', 'ghost')->getStatusCode());

    // Unreferenced and site-minted: deleted in this request, the delta
    // removing it.
    $this->manager()->mint($skin, 'space', 'loner', '1rem');
    $this->delta();
    $response = $controller->delete('space', 'loner');
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($payload['deleted']);
    $this->assertSame(0, $payload['references']);
    $this->assertContains(['kind' => 'token', 'id' => 'space/loner'], $payload['materials']['remove']);

    // A site override dropped: the package's token revealed, deleted
    // FALSE, the delta upserting the revealed record.
    $this->manager()->update($skin, 'space', 'gutter', '3rem');
    $this->delta();
    $response = $controller->delete('space', 'gutter');
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertFalse($payload['deleted']);
    $this->assertContains('space/gutter', array_column($payload['materials']['upsert'], 'id'));

    // A package token the site never touched: 422 under the lock.
    $response = $controller->delete('space', 'gutter');
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('STYLE_TOKEN_SHIPPED_LOCKED', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);

    // Referenced without the in-use grade: 403 with the corrective,
    // untouched.
    $this->manager()->mint($skin, 'space', 'hero-gap', '4rem');
    $this->createReferencingHost('--space-hero-gap');
    $response = $controller->delete('space', 'hero-gap');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('STYLE_TOKEN_IN_USE', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
    $this->assertTrue($this->manager()->has($this->loadSkin('aurora'), 'space', 'hero-gap'));

    // Referenced with the grade: 202 and the batch URL for the client to
    // drive, the removal stated for the client to apply at the end.
    $this->setUpCurrentUser([], ['delete tokens in use']);
    $response = $controller->delete('space', 'hero-gap');
    $this->assertSame(202, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertFalse($payload['deleted']);
    $this->assertSame(1, $payload['references']);
    $this->assertStringContainsString('batch', $payload['batch_url']);
    $this->assertSame(['upsert' => [], 'remove' => [['kind' => 'token', 'id' => 'space/hero-gap']]], $payload['materials']);
    $this->assertTrue($this->manager()->has($this->loadSkin('aurora'), 'space', 'hero-gap'));
  }

  /**
   * Rename: the record moves in place, every reference follows.
   */
  public function testRename(): void {
    $skin = $this->activateOwnSkin();
    $this->delta();

    // Unreferenced: moved in this request, order kept, identity gone
    // from the roster under the old id and present under the new.
    $this->assertSame(0, $this->manager()->rename($skin, 'space', '8', 'wide'));
    $fresh = $this->loadSkin('mine');
    $this->assertSame(['2', '4', 'wide', 'double'], array_map('strval', array_keys($fresh->packageTokens()['space'])));
    $this->assertSame('2rem', $fresh->packageTokens()['space']['wide']['value']);
    $this->assertSame('Renamed space/8 to space/wide.', $fresh->getRevisionLogMessage());
    $delta = $this->delta();
    $this->assertContains(['kind' => 'token', 'id' => 'space/8'], $delta['remove']);
    $this->assertContains('space/wide', array_column($delta['upsert'], 'id'));

    // The name's grammar and identity: a taken name, a malformed name, no
    // name, the same name.
    $this->assertSame(['label' => 'STYLE_TOKEN_EXISTS'], $this->refusal(fn () => $this->manager()->rename($fresh, 'space', '2', 'wide')));
    $this->assertSame(['label' => 'INVALID_TOKEN_NAME'], $this->refusal(fn () => $this->manager()->rename($fresh, 'space', '2', 'Wide Gap')));
    $this->assertSame(['label' => 'MISSING_PARAMETER'], $this->refusal(fn () => $this->manager()->rename($fresh, 'space', '2', '  ')));
    $this->assertSame(['label' => 'MISSING_PARAMETER'], $this->refusal(fn () => $this->manager()->rename($fresh, 'space', '2', '2')));

    // Referenced: the rewrite-then-rename batch, respelling each var()
    // and keeping its fallback, then the move; the op spine's posture
    // refuses instead.
    $key = 'block:' . $this->container->get('uuid')->generate();
    $host = EntityTest::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        $key => ['default' => ['padding' => 'calc(var(--space-4, 1rem) + var(--space-40, 0))']],
      ]),
    ]);
    $host->save();
    $this->assertSame(['id' => 'STYLE_TOKEN_IN_USE'], $this->refusal(fn () => $this->manager()->rename($fresh, 'space', '4', 'base', FALSE)));
    $this->assertSame(2, $this->manager()->rename($fresh, 'space', '4', 'base'), 'the host and the skin (its token and default) reference space/4');
    $this->assertTrue($this->manager()->has($this->loadSkin('mine'), 'space', '4'), 'nothing moves in this request');
    $results = $this->runBatch('renameToken');
    $this->assertSame(2, $results['rewritten']);
    $this->assertSame('space/base', $results['renamed']);
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test');
    $storage->resetCache([$host->id()]);
    $map = $storage->load($host->id())->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    $this->assertSame('calc(var(--space-base, 1rem) + var(--space-40, 0))', $map[$key]['default']['padding']);
    $renamed = $this->loadSkin('mine');
    $this->assertSame(['2', 'base', 'wide', 'double'], array_map('strval', array_keys($renamed->packageTokens()['space'])));
    $this->assertSame('calc(var(--space-base) * 2)', $renamed->packageTokens()['space']['double']['value']);
    $this->assertSame('var(--space-base, 1rem)', $renamed->packageDefaults()['kind:container']['']['default']['padding']);
    $this->assertSame(0, $this->manager()->usages('space', '4'));
    $this->assertSame(2, $this->manager()->usages('space', 'base'));
  }

  /**
   * A package token cannot be renamed on a consuming site; a site-minted one can.
   */
  public function testRenameUnderTheLock(): void {
    $this->activateSkin('aurora');
    $skin = $this->manager()->activeSkin();
    $this->assertSame(['id' => 'STYLE_TOKEN_SHIPPED_LOCKED'], $this->refusal(fn () => $this->manager()->rename($skin, 'space', 'gutter', 'gap')));
    $this->manager()->update($skin, 'space', 'gutter', '3rem');
    $this->assertSame(['id' => 'STYLE_TOKEN_SHIPPED_LOCKED'], $this->refusal(fn () => $this->manager()->rename($skin, 'space', 'gutter', 'gap')), 'a site override does not unlock the package\'s name');

    // A site-minted token moves in the site layer.
    $this->manager()->mint($skin, 'space', 'hero-gap', '4rem');
    $this->assertSame(0, $this->manager()->rename($skin, 'space', 'hero-gap', 'stage-gap'));
    $fresh = $this->loadSkin('aurora');
    $this->assertSame(['gutter', 'stage-gap'], array_keys($fresh->siteTokens()['space']));
    $this->assertArrayNotHasKey('stage-gap', $fresh->packageTokens()['space']);

    // The developer gate lifts the lock: the package record moves.
    $this->setSetting('daedalus_blueprint_developer', TRUE);
    $this->assertSame(0, $this->manager()->rename($fresh, 'space', 'gutter', 'gap'));
    $gated = $this->loadSkin('aurora');
    $this->assertSame(['gap'], array_keys($gated->packageTokens()['space']));
    $this->assertSame(['gap', 'stage-gap'], array_keys($gated->siteTokens()['space']), 'the site record over it moves with it');
    $this->assertTrue($gated->isModified());

    // The endpoint: the name gesture's body, the two outcomes.
    $this->setSetting('daedalus_blueprint_developer', FALSE);
    $controller = TokenApiController::create($this->container);
    $response = $controller->rename('space', 'stage-gap', Request::create('/', 'POST', [], [], [], [], 'not json'));
    $this->assertSame(400, $response->getStatusCode());
    $response = $controller->rename('space', 'stage-gap', Request::create('/', 'POST', [], [], [], [], json_encode(['label' => 'hero-gap'])));
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($payload['renamed']);
    $this->assertSame('space/hero-gap', $payload['id']);
    $this->assertContains(['kind' => 'token', 'id' => 'space/stage-gap'], $payload['materials']['remove']);
    $this->createReferencingHost('--space-hero-gap');
    $response = $controller->rename('space', 'hero-gap', Request::create('/', 'POST', [], [], [], [], json_encode(['label' => 'stage-gap'])));
    $this->assertSame(202, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertFalse($payload['renamed']);
    $this->assertSame('space/stage-gap', $payload['id']);
    $this->assertSame(1, $payload['references']);
    $this->assertStringContainsString('batch', $payload['batch_url']);
    // The outcome the batch is committed to: the old id's removal and
    // the renamed record whole (its spelling under the scale's prefix).
    $this->assertSame([['kind' => 'token', 'id' => 'space/hero-gap']], $payload['materials']['remove']);
    $this->assertCount(1, $payload['materials']['upsert']);
    $renamed = $payload['materials']['upsert'][0];
    $this->assertSame('space/stage-gap', $renamed['id']);
    $this->assertSame('stage-gap', $renamed['label']);
    $this->assertSame('var(--space-stage-gap)', $renamed['spellings']['style']);
    $this->assertSame('space', $renamed['group']);
    $this->assertSame('4rem', $renamed['value'], 'the value rides unchanged');
    $response = $controller->rename('space', 'gap', Request::create('/', 'POST', [], [], [], [], json_encode(['label' => 'gutter'])));
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('STYLE_TOKEN_SHIPPED_LOCKED', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
  }

  /**
   * Revert converges a token toward the disk: the site's record dropped.
   */
  public function testRevert(): void {
    $this->activateSkin('aurora');
    $manager = $this->manager();
    $skin = $manager->activeSkin();

    // A site override over a package token: revert drops it, one
    // revisioned save naming the module.
    $manager->update($skin, 'space', 'gutter', '3rem', 'Mine now.');
    $this->assertSame('modified', $manager->shippedState($skin, 'space', 'gutter'));
    $manager->revert($skin, 'space', 'gutter');
    $fresh = $this->loadSkin('aurora');
    $this->assertSame([], $fresh->siteTokens());
    $this->assertSame(self::GUTTER, $fresh->tokens()['space']['gutter']);
    $this->assertSame('Reverted space/gutter to the shipped token of the daedalus_stylish_skins_test module.', $fresh->getRevisionLogMessage());
    $this->assertSame('unmodified', $manager->shippedState($fresh, 'space', 'gutter'));

    // Nothing to revert: no new revision.
    $revision = $fresh->getRevisionId();
    $manager->revert($fresh, 'space', 'gutter');
    $this->assertSame($revision, $this->loadSkin('aurora')->getRevisionId());

    // No disk file ships a site-minted token, or any token of the site's
    // own skin.
    $manager->mint($fresh, 'space', 'hero-gap', '4rem');
    $this->assertSame(['id' => 'SHIPPED_DEFAULTS_MISSING'], $this->refusal(fn () => $manager->revert($fresh, 'space', 'hero-gap')));
    $mine = $this->activateOwnSkin();
    $this->assertSame(['id' => 'STYLE_TOKEN_NOT_SHIPPED'], $this->refusal(fn () => $manager->revert($mine, 'space', '4')));

    // Under the developer gate the package record itself is rewritten
    // from the file, through the endpoint.
    $this->activateSkin('aurora');
    $this->setSetting('daedalus_blueprint_developer', TRUE);
    $aurora = $manager->activeSkin();
    $manager->update($aurora, 'space', 'gutter', '3rem');
    $this->assertSame('3rem', $this->loadSkin('aurora')->packageTokens()['space']['gutter']['value']);
    $this->delta();
    $response = TokenApiController::create($this->container)->revert('space', 'gutter');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertContains('space/gutter', array_column(json_decode((string) $response->getContent(), TRUE)['materials']['upsert'], 'id'));
    $this->assertSame(self::GUTTER, $this->loadSkin('aurora')->packageTokens()['space']['gutter']);
  }

}
