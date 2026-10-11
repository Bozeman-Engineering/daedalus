<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Controller\SkinApiController;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Exception\SkinValidationException;
use Drupal\daedalus_stylish\SkinManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the active skin: the site's choice for the active design system.
 *
 * The site activates a design system (daedalus_blueprint.settings
 * active_design_system) and wears the skin the site CHOSE for it
 * (daedalus_stylish.settings skin_choice, keyed by system id) while that
 * names a published, conforming member of the system, else the system's
 * default_skin. SkinManager is the one reader of that derivation,
 * tolerant of a system whose skin is not there (a recipe's activation
 * lands before the skins lane syncs; a deleted skin leaves its system
 * naming nothing) and of a system carrying no skin at all, and the one
 * writer of the choice (choose(), behind the route and the drush
 * command), which refuses what the read would fall back from.
 */
#[Group('daedalus_stylish')]
#[CoversClass(SkinManager::class)]
#[CoversClass(SkinApiController::class)]
#[RunTestsInSeparateProcesses]
class SkinManagerTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
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
    $this->installEntitySchema('daedalus_skin');
    // A switch recompiles the roster-fed files, whose provider reads all
    // four storages.
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
    $this->installConfig(['daedalus_blueprint', 'daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->setUpCurrentUser([], [], TRUE);
    $this->declareContract(['space' => ['gutter']]);
  }

  /**
   * Declares the space scale and a token contract on it.
   */
  protected function declareContract(array $contract): void {
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
      ],
      'token_contract' => $contract,
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
  }

  /**
   * The skin manager.
   */
  protected function skinManager(): SkinManager {
    return $this->container->get('daedalus_stylish.skin_manager');
  }

  /**
   * The activation service.
   */
  protected function active(): ActiveDesignSystem {
    return $this->container->get(ActiveDesignSystem::SERVICE_ID);
  }

  /**
   * A member skin minting the given space tokens.
   */
  protected function createMember(string $machine_name, string $design_system, array $space): DaedalusStylishSkin {
    $skin = DaedalusStylishSkin::create([
      'label' => ucfirst($machine_name),
      'machine_name' => $machine_name,
      'design_system' => $design_system,
    ]);
    $skin->setPackageTokens(['space' => $space])->save();
    return $skin;
  }

  /**
   * The one error code a refused choice carries, keyed by path.
   */
  protected function refusal(callable $gesture): array {
    try {
      $gesture();
    }
    catch (SkinValidationException $exception) {
      $errors = $exception->getErrorsAsArrays();
      $this->assertCount(1, $errors);
      return [$errors[0]['path'] => $errors[0]['code']];
    }
    $this->fail('The choice was not refused.');
  }

  /**
   * Activation derives the skin; daedalus_stylish holds no pointer of its own.
   */
  public function testActiveSkinIsTheActiveSystemsDefaultSkin(): void {
    $manager = $this->skinManager();
    $this->assertNull($manager->activeSkinName());
    $this->assertNull($manager->loadActiveSkin());

    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora', 'default_skin' => 'aurora'])->save();
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();

    // Activating before the skin exists (a recipe's config precedes the
    // skins lane's content): the name is known, the skin is not.
    $this->active()->activate('aurora');
    $this->assertSame('aurora', $manager->activeSkinName());
    $this->assertNull($manager->loadActiveSkin());

    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora']);
    $skin->setPackageDefaults(['kind:container' => ['' => ['default' => ['padding' => '2rem']]]])->save();
    $this->assertSame($skin->id(), $manager->loadActiveSkin()?->id());

    // A system carrying no skin (blueprints and doctrine only) wears none.
    $this->active()->activate('borealis');
    $this->assertNull($manager->activeSkinName());
    $this->assertNull($manager->loadActiveSkin());

    // Switching back derives the skin again; deleting the skin leaves the
    // system naming nothing, which delivery treats as "no skin".
    $this->active()->activate('aurora');
    $this->assertSame('aurora', $manager->activeSkinName());
    $skin->delete();
    $this->assertSame('aurora', $manager->activeSkinName());
    $this->assertNull($manager->loadActiveSkin());
  }

  /**
   * The choice wins while the system may wear it, per system.
   *
   * A choice names a published, conforming MEMBER of the active system;
   * it is keyed by system id, so switching systems and back returns to
   * it; and it is judged on every read, so a chosen skin later
   * unpublished, moved, left nonconforming by the law or deleted falls
   * back to the unit's default_skin, never bricking delivery.
   */
  public function testChoiceWinsWhileTheSystemMayWearIt(): void {
    $manager = $this->skinManager();
    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora', 'default_skin' => 'aurora'])->save();
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis', 'default_skin' => 'north'])->save();
    $this->createMember('aurora', 'aurora', ['gutter' => '1rem', 'tight' => '0.5rem']);
    $dusk = $this->createMember('dusk', 'aurora', ['gutter' => '2rem', 'wide' => '4rem']);
    $this->createMember('north', 'borealis', ['gutter' => '1rem']);
    $this->active()->activate('aurora');
    $this->assertNull($manager->choice('aurora'));
    $this->assertSame('aurora', $manager->activeSkinName());
    $this->assertSame(['aurora', 'dusk'], array_keys($manager->memberSkins('aurora')), 'members by label, the global and other systems\' skins excluded');

    $this->assertSame('aurora', $manager->choose('dusk'));
    $this->assertSame('dusk', $manager->choice('aurora'));
    $this->assertSame(['aurora' => 'dusk'], $this->config('daedalus_stylish.settings')->get('skin_choice'));
    $this->assertSame('dusk', $manager->activeSkinName());
    $this->assertSame($dusk->id(), $manager->loadActiveSkin()?->id());
    $this->assertSame('dusk', $manager->skinNameFor('aurora'));
    $this->assertSame('north', $manager->skinNameFor('borealis'), 'the other system wears its default');
    $this->assertNull($manager->skinNameFor('ghost'), 'a system not on the site wears nothing');

    // Per system: borealis wears its own default; back on aurora the
    // choice holds.
    $this->active()->activate('borealis');
    $this->assertSame('north', $manager->activeSkinName());
    $this->active()->activate('aurora');
    $this->assertSame('dusk', $manager->activeSkinName());

    // Judged on every read: each way the system may no longer wear the
    // chosen skin falls back to the default, and recovers when it may.
    $dusk->setUnpublished()->save();
    $this->assertSame('aurora', $manager->activeSkinName(), 'an unpublished choice falls back');
    $dusk->setPublished()->save();
    $this->assertSame('dusk', $manager->activeSkinName());
    $dusk->setDesignSystem('borealis')->save();
    $this->assertSame('aurora', $manager->activeSkinName(), 'a choice moved to another system falls back');
    $dusk->setDesignSystem('aurora')->save();
    $this->assertSame('dusk', $manager->activeSkinName());
    $this->declareContract(['space' => ['gutter', 'tight']]);
    $this->assertSame('aurora', $manager->activeSkinName(), 'a choice the contract outgrew falls back; the default is never judged');
    $this->declareContract(['space' => ['gutter']]);
    $this->assertSame('dusk', $manager->activeSkinName());
    $this->assertSame('dusk', $manager->choice('aurora'), 'the stored choice survives every fallback');
    $dusk->delete();
    $this->assertSame('aurora', $manager->activeSkinName(), 'a deleted choice falls back');
    $this->assertSame('dusk', $manager->choice('aurora'));
  }

  /**
   * Choosing refuses what the read would fall back from.
   */
  public function testChooseRefusals(): void {
    $manager = $this->skinManager();
    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora', 'default_skin' => 'aurora'])->save();
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $this->createMember('aurora', 'aurora', ['gutter' => '1rem']);
    $dusk = $this->createMember('dusk', 'aurora', ['gutter' => '2rem']);
    $this->createMember('north', 'borealis', ['gutter' => '1rem']);
    $this->createMember('bare', 'aurora', ['wide' => '4rem']);
    DaedalusStylishSkin::create(['label' => 'Global', 'machine_name' => 'global'])->save();

    $this->assertSame(['' => 'STYLE_DESIGN_SYSTEM_NONE_ACTIVE'], $this->refusal(fn () => $manager->choose('dusk')));
    $this->active()->activate('aurora');
    $this->assertSame(['id' => 'STYLE_SKIN_NOT_FOUND'], $this->refusal(fn () => $manager->choose('ghost')));
    $this->assertSame(['id' => 'STYLE_SKIN_NOT_MEMBER'], $this->refusal(fn () => $manager->choose('north')));
    $this->assertSame(['id' => 'STYLE_SKIN_NOT_MEMBER'], $this->refusal(fn () => $manager->choose('global')), 'a global skin is offered under every roster and is nobody\'s look');
    $dusk->setUnpublished()->save();
    $this->assertSame(['id' => 'STYLE_SKIN_UNPUBLISHED'], $this->refusal(fn () => $manager->choose('dusk')));
    try {
      $manager->choose('bare');
      $this->fail('A nonconforming skin was chosen.');
    }
    catch (SkinValidationException $exception) {
      $error = $exception->getErrorsAsArrays()[0];
      $this->assertSame('STYLE_SKIN_NONCONFORMING', $error['code']);
      $this->assertSame(['lacks' => ['space' => ['gutter']]], $error['context']);
    }
    $this->assertNull($manager->choice('aurora'), 'a refused choice writes nothing');
    $this->assertSame('aurora', $manager->activeSkinName());
    // The default itself is a legal choice.
    $this->assertSame('aurora', $manager->choose('aurora'));
    $this->assertSame('aurora', $manager->choice('aurora'));
  }

  /**
   * The choose route: the skin card's adapter over the manager.
   *
   * A write answers the materials delta a switch answers: the skin worn
   * before and the skin worn now both have their token ids recorded
   * (DefaultsSettingsSubscriber), resolved against the fresh roster, so
   * the old skin's own tokens read as removes, the new skin's as
   * upserts and a name both mint as one upsert.
   */
  public function testChooseRoute(): void {
    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora', 'default_skin' => 'aurora'])->save();
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $this->createMember('aurora', 'aurora', ['gutter' => '1rem', 'tight' => '0.5rem']);
    $this->createMember('dusk', 'aurora', ['gutter' => '2rem', 'wide' => '4rem']);
    $this->createMember('north', 'borealis', ['gutter' => '1rem']);
    $controller = SkinApiController::create($this->container);
    $change_set = $this->container->get('daedalus_blueprint.materials_change_set');
    assert($change_set instanceof MaterialsChangeSet);

    $response = $controller->choose('dusk');
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('STYLE_DESIGN_SYSTEM_NONE_ACTIVE', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
    $this->active()->activate('aurora');
    $this->assertSame(404, $controller->choose('ghost')->getStatusCode());
    $this->assertSame(422, $controller->choose('north')->getStatusCode());

    $change_set->flush();
    $response = $controller->choose('dusk');
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('dusk', $payload['chosen']);
    $this->assertSame('aurora', $payload['design_system']);
    $ids = [];
    foreach (['upsert', 'remove'] as $side) {
      $ids[$side] = array_map(static fn (array $entry): string => $entry['kind'] . ':' . $entry['id'], $payload['materials'][$side]);
      sort($ids[$side]);
    }
    $this->assertSame([
      'upsert' => ['token:space/gutter', 'token:space/wide'],
      'remove' => ['token:space/tight'],
    ], $ids);
    $this->assertSame('dusk', $this->skinManager()->activeSkinName());

    // Choosing the skin already worn changes nothing worn: an empty delta.
    $response = $controller->choose('dusk');
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(['upsert' => [], 'remove' => []], $payload['materials']);
  }

  /**
   * The defaults route: the skin session's landing read.
   *
   * After a push of update_default ops the client re-reads the active
   * skin's defaults (the guide payload's skinDefaults, the merge of both
   * layers) beside the defaults stylesheet's current text, so the panel
   * and the canvas land the pushed look without a reload. No published
   * active skin delivers nothing (the CSS is the declared tier's alone).
   */
  public function testDefaultsRoute(): void {
    $controller = SkinApiController::create($this->container);
    $read = static fn (): array => json_decode((string) $controller->defaults()->getContent(), TRUE);

    $payload = $read();
    $this->assertNull($payload['skin'], 'No active skin: nothing delivers.');
    $this->assertSame([], $payload['defaults'], 'No active skin: no defaults.');
    $this->assertIsString($payload['css'], 'The CSS text serves even without a skin (the declared tier).');

    DesignSystem::create(['id' => 'aurora', 'label' => 'Aurora', 'default_skin' => 'aurora'])->save();
    $skin = $this->createMember('aurora', 'aurora', ['gutter' => '1rem']);
    $skin->setPackageDefaults(['base:h2' => ['' => ['default' => ['font-size' => '2rem']]]])->save();
    $this->active()->activate('aurora');

    $payload = $read();
    $this->assertSame('aurora', $payload['skin'], 'The active published skin delivers.');
    $this->assertSame(['base:h2' => ['' => ['default' => ['font-size' => '2rem']]]], $payload['defaults'], 'The defaults serve as stored, the guide payload\'s skinDefaults shape.');
    $this->assertStringContainsString(':where(.daedalus-stylish-token-scope) :where(h2)', $payload['css'], 'The CSS is the defaults stylesheet\'s print: the base block at its bare selector.');
    $this->assertStringContainsString('font-size: 2rem', $payload['css']);

    // A site-layer write over the package's (what update_default lands on
    // a consuming site) serves through the merge, and the CSS follows.
    $skin->setSiteDefaults(['base:h2' => ['' => ['default' => ['font-size' => '3rem']]]])->save();
    $payload = $read();
    $this->assertSame('3rem', $payload['defaults']['base:h2']['']['default']['font-size'], 'The site layer wins the merge the route serves.');
    $this->assertStringContainsString('font-size: 3rem', $payload['css'], 'The CSS prints the merged value.');
    $this->assertStringNotContainsString('font-size: 2rem', $payload['css'], 'The package value beneath the site\'s override does not print.');

    // An unpublished active skin delivers nothing, the guide payload's gate.
    $skin->setUnpublished()->save();
    $payload = $read();
    $this->assertNull($payload['skin'], 'An unpublished active skin delivers nothing.');
    $this->assertSame([], $payload['defaults']);
    $this->assertStringNotContainsString('font-size: 3rem', $payload['css'], 'The CSS prints the declared tier alone.');
  }

}
