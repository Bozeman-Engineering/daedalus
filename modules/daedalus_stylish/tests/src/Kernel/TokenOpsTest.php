<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialGestureResult;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\TokenManager;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus_stylish\Traits\HostTrait;

/**
 * The token gestures: save_material, update_material, remove_material.
 *
 * A token is a material of the active skin, minted, changed and removed
 * by name through the material gesture family (kind token) on the same
 * door the swatch, variant and animation gestures use. Pins where a write
 * lands: the SITE layer of a shipped skin on a consuming site (a mint is
 * site-minted, an update stores only the facts it changes over the
 * package's record, a removal takes the site's record away and reveals
 * the package's token or deletes a site-minted one, and a shipped token
 * the site never touched cannot be removed), the skin's ONE layer on the
 * site's own skin and under the developer gate (the record itself
 * changes, a removal deletes the token). Plus the lane's governance: the
 * per-verb permissions with "administer skins" as the administer grade,
 * the ai_minting gate on the AI's mint and its advertisement on the
 * schema, validation through the token seam re-rooted onto the gesture's
 * keys, the token's gesture line on the roster, and the write reaching
 * the client through the materials delta.
 */
#[CoversClass(TokenManager::class)]
#[CoversClass(DaedalusStylishMaterialsProvider::class)]
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class TokenOpsTest extends KernelTestBase {

  use HostTrait;
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
    // The materials roster's daedalus_stylish provider reads all four
    // storages on every build, and every validation builds it.
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
   */
  protected function activateOwnSkin(): DaedalusStylishSkinInterface {
    $skin = DaedalusStylishSkin::create(['label' => 'Mine', 'machine_name' => 'mine', 'status' => 1]);
    $skin->setPackageTokens(['space' => ['4' => '1rem']]);
    $skin->save();
    $this->activateSkin($skin);
    return $this->loadSkin('mine');
  }

  /**
   * The provider, the one implementation behind every door.
   */
  protected function provider(): DaedalusStylishMaterialsProvider {
    return $this->container->get('daedalus_stylish.materials_provider');
  }

  /**
   * Runs a token gesture straight on the provider.
   */
  protected function gesture(string $verb, array $operation, ?string $id = NULL, array $options = []): MaterialGestureResult {
    $provider = $this->provider();
    return match ($verb) {
      'capture' => $provider->capture(MaterialKind::Token, $operation, NULL, $options),
      'update' => $provider->update(MaterialKind::Token, (string) $id, $operation, NULL, $options),
      'remove' => $provider->remove(MaterialKind::Token, (string) $id, $options),
    };
  }

  /**
   * The error codes by path a gesture refuses with.
   *
   * @return array<string, string>
   *   path => code.
   */
  protected function refusal(string $verb, array $operation, ?string $id = NULL, array $options = []): array {
    try {
      $this->gesture($verb, $operation, $id, $options);
    }
    catch (MaterialGestureException $exception) {
      $codes = [];
      foreach ($exception->getErrors() as $error) {
        $codes[$error->getPath()] = $error->getCode();
      }
      return $codes;
    }
    $this->fail(sprintf('The %s gesture was expected to refuse.', $verb));
  }

  /**
   * The error codes by path a mint refuses with.
   */
  protected function mintRefusal(string $group, string $label, mixed $value, array $extra = [], array $options = []): array {
    $operation = ['group' => $group, 'label' => $label, 'value' => $value] + $extra;
    return $this->refusal('capture', $operation, NULL, $options);
  }

  /**
   * Runs one op through the real batch door.
   */
  protected function batch(array $operation, array $options = []): BlueprintResult {
    $host = EntityTest::create(['name' => 'Host']);
    $host->save();
    return $this->container->get('daedalus_blueprint.operations')->batchOperations($this->host($host), [$operation], $options);
  }

  /**
   * The materials delta the request carries, drained.
   */
  protected function delta(): array {
    return $this->container->get('daedalus_blueprint.materials_change_set')->flush();
  }

  /**
   * The roster's token record, fresh.
   */
  protected function record(string $id): ?MaterialRecord {
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $builder->reset();
    return $builder->build()->get(MaterialKind::Token, $id);
  }

  /**
   * A mint on a shipped skin lands in the site layer, through the op spine.
   *
   * The consuming site's posture: the package layer and its hash stay
   * what the disk shipped (the skin never reads as modified), the site
   * layer holds the minted record whole, the skin gains a revision naming
   * the gesture, the roster carries the token with its description, and
   * the request's delta upserts it for the client.
   */
  public function testMintLandsInTheSiteLayerOfShippedSkin(): void {
    $this->activateSkin('aurora');
    $before = $this->loadSkin('aurora');
    $package = $before->packageTokens();
    $hash = $before->getSourceHash();
    $revision = (int) $before->getRevisionId();

    $result = $this->batch([
      'op' => 'save_material',
      'kind' => 'token',
      'group' => 'space',
      'label' => 'hero-gap',
      'value' => ['default' => '4rem', 'medium' => '6rem'],
      'description' => 'The gap between a hero and what follows.',
    ]);
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));

    $skin = $this->loadSkin('aurora');
    $this->assertSame($package, $skin->packageTokens(), 'the package layer is untouched');
    $this->assertSame($hash, $skin->getSourceHash());
    $this->assertFalse($skin->isModified(), 'a site write is not a modification of what the package shipped');
    $this->assertSame([
      'space' => [
        'hero-gap' => [
          'value' => ['default' => '4rem', 'medium' => '6rem'],
          'description' => 'The gap between a hero and what follows.',
          'offered' => TRUE,
        ],
      ],
    ], $skin->siteTokens());
    $this->assertSame(['default' => '4rem', 'medium' => '6rem'], $skin->tokens()['space']['hero-gap']['value'], 'the merge carries the site-minted token');
    $this->assertGreaterThan($revision, (int) $skin->getRevisionId(), 'the write is a new revision');
    $this->assertSame('Minted the space/hero-gap token.', $skin->getRevisionLogMessage());

    $record = $this->record('space/hero-gap');
    $this->assertNotNull($record);
    $this->assertSame('The gap between a hero and what follows.', $record->description);
    $this->assertSame('var(--space-hero-gap)', $record->spelling('style'));
    $this->assertTrue($record->updatable, 'a token is always updatable: the site layer takes the write');
    $delta = $this->delta();
    $this->assertContains('space/hero-gap', array_column($delta['upsert'], 'id'), 'the mint rides the response\'s materials delta');
    $this->assertSame([], $delta['remove']);
  }

  /**
   * An update on a shipped skin stores exactly the facts it changes.
   *
   * The site layer over a package token holds the value alone when only
   * the value changed (the shipped description and offered flag show
   * through), gains the offered flag when that changes, and the merge
   * reads the site's facts over the package's. An empty update, a bad
   * type and an unknown id each refuse.
   */
  public function testUpdateOverridesPackageTokenInTheSiteLayer(): void {
    $this->activateSkin('aurora');
    $this->assertSame(self::GUTTER, $this->loadSkin('aurora')->tokens()['space']['gutter']);

    $result = $this->gesture('update', ['value' => '3rem'], 'space/gutter');
    $this->assertSame('space/gutter', $result->id);
    $skin = $this->loadSkin('aurora');
    $this->assertSame(['space' => ['gutter' => ['value' => '3rem']]], $skin->siteTokens(), 'only the value is the site\'s');
    $this->assertSame(['value' => '3rem', 'description' => '', 'offered' => TRUE], $skin->tokens()['space']['gutter']);
    $this->assertSame(self::GUTTER, $skin->packageTokens()['space']['gutter'], 'the package record is untouched');
    $this->assertFalse($skin->isModified());
    $this->assertSame('3rem', $this->record('space/gutter')?->value);

    $this->gesture('update', ['offered' => FALSE, 'description' => 'The band gutter.'], 'space/gutter');
    $skin = $this->loadSkin('aurora');
    $this->assertSame(['value' => '3rem', 'description' => 'The band gutter.', 'offered' => FALSE], $skin->siteTokens()['space']['gutter']);
    $this->assertSame('space/gutter: Updated the description. Withheld from rows.', $skin->getRevisionLogMessage());
    $this->assertFalse($this->record('space/gutter')?->offered);

    $this->assertSame(['' => 'MISSING_PARAMETER'], $this->refusal('update', [], 'space/gutter'));
    $this->assertSame(['value' => 'INVALID_PARAMETER'], $this->refusal('update', ['value' => 12], 'space/gutter'));
    $this->assertSame(['offered' => 'INVALID_PARAMETER'], $this->refusal('update', ['offered' => 'yes'], 'space/gutter'));
    $this->assertSame(['id' => 'STYLE_TOKEN_NOT_FOUND'], $this->refusal('update', ['value' => '1rem'], 'space/ghost'));
    $this->assertSame(['id' => 'STYLE_TOKEN_NOT_FOUND'], $this->refusal('update', ['value' => '1rem'], 'gutter'), 'an id without a scale names nothing');
    $this->assertSame(['id' => 'STYLE_TOKEN_NOT_FOUND'], $this->refusal('remove', [], 'space/ghost'));
  }

  /**
   * A removal on a shipped skin takes the site's record: reveal, delete, refuse.
   *
   * A site override removed reveals the package's token whole (the result
   * still carries the skin, the delta upserts the token), a site-minted
   * token removed is gone (no entity, the delta removes it), and a shipped
   * token the site never touched refuses under the lock.
   */
  public function testRemoveTakesTheSiteRecord(): void {
    $this->activateSkin('aurora');
    $this->gesture('update', ['value' => '3rem'], 'space/gutter');
    $this->gesture('capture', ['group' => 'space', 'label' => 'hero-gap', 'value' => '4rem']);
    $this->delta();

    $result = $this->gesture('remove', [], 'space/gutter');
    $this->assertInstanceOf(DaedalusStylishSkinInterface::class, $result->entity, 'the token still exists: the package\'s shows through');
    $skin = $this->loadSkin('aurora');
    $this->assertArrayNotHasKey('gutter', $skin->siteTokens()['space']);
    $this->assertSame(self::GUTTER, $skin->tokens()['space']['gutter'], 'the package token is revealed whole');
    $this->assertSame('Reverted space/gutter to the shipped token.', $skin->getRevisionLogMessage());
    $this->assertSame(self::GUTTER['value'], $this->record('space/gutter')?->value);
    $delta = $this->delta();
    $this->assertContains('space/gutter', array_column($delta['upsert'], 'id'));
    $this->assertSame([], $delta['remove']);

    $result = $this->gesture('remove', [], 'space/hero-gap');
    $this->assertNull($result->entity, 'a site-minted token is gone');
    $skin = $this->loadSkin('aurora');
    $this->assertSame([], $skin->siteTokens(), 'an emptied scale leaves the layer');
    $this->assertArrayNotHasKey('hero-gap', $skin->tokens()['space']);
    $this->assertNull($this->record('space/hero-gap'));
    $this->assertContains(['kind' => 'token', 'id' => 'space/hero-gap'], $this->delta()['remove']);

    $codes = $this->refusal('remove', [], 'space/gutter');
    $this->assertSame(['id' => 'STYLE_TOKEN_SHIPPED_LOCKED'], $codes);
    $this->assertSame(self::GUTTER, $this->loadSkin('aurora')->packageTokens()['space']['gutter']);
  }

  /**
   * The site's own skin has one layer: every gesture edits the record itself.
   */
  public function testSiteOwnSkinWritesItsOneLayer(): void {
    $this->activateOwnSkin();

    $this->gesture('capture', [
      'group' => 'color',
      'label' => 'accent',
      'value' => ['default' => '#1a1a2e', 'dark' => '#e8e8f0'],
      'offered' => FALSE,
    ]);
    $skin = $this->loadSkin('mine');
    $this->assertSame([], $skin->siteTokens(), 'no site layer on the site\'s own skin');
    $this->assertSame([
      'value' => ['default' => '#1a1a2e', 'dark' => '#e8e8f0'],
      'description' => '',
      'offered' => FALSE,
    ], $skin->packageTokens()['color']['accent']);

    $this->gesture('update', ['description' => 'The one hue.', 'value' => '#101010'], 'color/accent');
    $skin = $this->loadSkin('mine');
    $this->assertSame(['value' => '#101010', 'description' => 'The one hue.', 'offered' => FALSE], $skin->packageTokens()['color']['accent']);
    $this->assertSame([], $skin->siteTokens());

    $result = $this->gesture('remove', [], 'color/accent');
    $this->assertNull($result->entity);
    $skin = $this->loadSkin('mine');
    $this->assertSame(['space'], array_keys($skin->packageTokens()), 'the token and its emptied scale are gone');
    $this->assertNull($this->record('color/accent'));

    // A duplicate name refuses: a token name is identity, never suffixed.
    $this->assertSame(['label' => 'STYLE_TOKEN_EXISTS'], $this->mintRefusal('space', '4', '2rem'));
  }

  /**
   * The developer gate writes the package layer of a shipped skin.
   *
   * The authoring site's posture: the mint lands beside what the disk
   * shipped, the skin reads as modified (the ship lane's signal), and a
   * shipped token can be removed.
   */
  public function testDeveloperGateWritesThePackageLayer(): void {
    $this->activateSkin('aurora');
    $this->setSetting('daedalus_blueprint_developer', TRUE);

    $this->gesture('capture', ['group' => 'space', 'label' => 'hero-gap', 'value' => '4rem']);
    $skin = $this->loadSkin('aurora');
    $this->assertSame([], $skin->siteTokens());
    $this->assertSame(['value' => '4rem', 'description' => '', 'offered' => TRUE], $skin->packageTokens()['space']['hero-gap']);
    $this->assertTrue($skin->isModified());
    $this->assertSame('daedalus_stylish_skins_test', $skin->getProvider(), 'still shipped: the disk lane keeps its subject');

    $this->gesture('update', ['value' => '3rem'], 'space/gutter');
    $this->assertSame('3rem', $this->loadSkin('aurora')->packageTokens()['space']['gutter']['value']);

    $this->assertNull($this->gesture('remove', [], 'space/gutter')->entity);
    $this->assertArrayNotHasKey('gutter', $this->loadSkin('aurora')->packageTokens()['space']);

    // Closing the gate locks the shipped tokens again.
    $this->setSetting('daedalus_blueprint_developer', FALSE);
    $this->assertSame(['id' => 'STYLE_TOKEN_SHIPPED_LOCKED'], $this->refusal('remove', [], 'space/hero-gap'));
  }

  /**
   * Validation runs on the skin as worn, re-rooted onto the gesture's keys.
   *
   * An undeclared scale reports at "group", a malformed name at "label",
   * a value off the scale's shape at "value" (a condition beneath it),
   * and, on a strict site, a removal another token's var() reaches
   * reports at that token's map path under "tokens". A refused write
   * lands nothing.
   */
  public function testValidationRerootsOntoTheGesture(): void {
    $this->activateOwnSkin();

    $this->assertSame(['group' => 'TOKEN_SCALE_UNKNOWN'], $this->mintRefusal('radius', 'sm', '2px'));
    $this->assertSame(['label' => 'INVALID_TOKEN_NAME'], $this->mintRefusal('space', 'Hero Gap', '4rem'));
    $this->assertSame(['value' => 'INVALID_STYLE_VALUE'], $this->mintRefusal('space', 'hero-gap', 'red'));
    $this->assertSame(['value' => 'TOKEN_DEFAULT_REQUIRED'], $this->mintRefusal('space', 'hero-gap', ['narrow' => '1rem']));
    $this->assertSame(
      ['value.narrow' => 'INVALID_STYLE_VALUE'],
      $this->mintRefusal('space', 'hero-gap', ['default' => '1rem', 'narrow' => 'red']),
    );
    $this->assertSame(['value' => 'INVALID_STYLE_VALUE'], $this->refusal('update', ['value' => 'red'], 'space/4'));
    $this->assertSame(['space' => ['4' => ['value' => '1rem', 'description' => '', 'offered' => TRUE]]], $this->loadSkin('mine')->packageTokens(), 'nothing landed');

    // The envelope's own refusals: the keys a token needs, and the one it
    // refuses (its membership is the skin's).
    $this->assertSame(['group' => 'MISSING_PARAMETER'], $this->refusal('capture', ['label' => 'x', 'value' => '1rem']));
    $this->assertSame(['label' => 'MISSING_PARAMETER'], $this->refusal('capture', [
      'group' => 'space',
      'value' => '1rem',
    ]));
    $this->assertSame(['value' => 'MISSING_PARAMETER'], $this->refusal('capture', ['group' => 'space', 'label' => 'x']));
    $this->assertSame(['description' => 'INVALID_PARAMETER'], $this->mintRefusal('space', 'x', '1rem', ['description' => ['no']]));
    $this->assertSame(['global' => 'INVALID_PARAMETER'], $this->mintRefusal('space', 'x', '1rem', ['global' => TRUE]));

    // A token another token's var() reaches is IN USE (the skin is a
    // referencing source of the usage table, TokenManagerTest), so on the
    // op spine its removal refuses with the count on every site, before
    // the strict map's self-consistency would; nothing lands.
    $this->gesture('capture', ['group' => 'space', 'label' => '8', 'value' => 'var(--space-4)']);
    $this->enforce('strict');
    $this->assertSame(['id' => 'STYLE_TOKEN_IN_USE'], $this->refusal('remove', [], 'space/4'));
    $this->assertArrayHasKey('4', $this->loadSkin('mine')->packageTokens()['space']);
  }

  /**
   * No active skin: nothing to mint on.
   */
  public function testNoActiveSkinRefuses(): void {
    $this->assertSame(['' => 'STYLE_SKIN_NONE_ACTIVE'], $this->mintRefusal('space', 'x', '1rem'));
    $this->assertSame(['' => 'STYLE_SKIN_NONE_ACTIVE'], $this->refusal('update', ['value' => '1rem'], 'space/x'));
    $this->assertSame(['' => 'STYLE_SKIN_NONE_ACTIVE'], $this->refusal('remove', [], 'space/x'));
  }

  /**
   * The permissions per verb, with "administer skins" as the administer grade.
   *
   * The delete-in-use grade admits the removal as the swatch lane admits
   * its own (the reference count that decides whether it is needed is the
   * usage lane's).
   */
  public function testPermissions(): void {
    $this->activateOwnSkin();

    $this->setUpCurrentUser([], []);
    $this->assertSame(['' => 'PERMISSION_DENIED'], $this->mintRefusal('space', 'x', '1rem'));
    $this->assertSame(['' => 'PERMISSION_DENIED'], $this->refusal('update', ['value' => '2rem'], 'space/4'));
    $this->assertSame(['' => 'PERMISSION_DENIED'], $this->refusal('remove', [], 'space/4'));

    $this->setUpCurrentUser([], ['create tokens']);
    $this->gesture('capture', ['group' => 'space', 'label' => 'x', 'value' => '1rem']);
    $this->assertSame(['' => 'PERMISSION_DENIED'], $this->refusal('update', ['value' => '2rem'], 'space/x'));

    $this->setUpCurrentUser([], ['update tokens']);
    $this->gesture('update', ['value' => '2rem'], 'space/x');
    $this->assertSame(['' => 'PERMISSION_DENIED'], $this->refusal('remove', [], 'space/x'));

    $this->setUpCurrentUser([], ['delete tokens in use']);
    $this->assertNull($this->gesture('remove', [], 'space/x')->entity);

    $this->setUpCurrentUser([], ['delete tokens']);
    $this->assertNull($this->gesture('remove', [], 'space/4')->entity);

    $this->setUpCurrentUser([], ['administer skins']);
    $this->gesture('capture', ['group' => 'space', 'label' => 'y', 'value' => '1rem']);
    $this->gesture('update', ['value' => '2rem'], 'space/y');
    $this->assertNull($this->gesture('remove', [], 'space/y')->entity);
  }

  /**
   * The ai_minting gate closes the AI's mint alone, and the schema says so.
   */
  public function testAiMintingGate(): void {
    $this->activateOwnSkin();
    $ai = [MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_AI];

    $this->gesture('capture', ['group' => 'space', 'label' => 'a', 'value' => '1rem'], NULL, $ai);
    $this->assertArrayHasKey('a', $this->loadSkin('mine')->packageTokens()['space'], 'an open gate admits the AI\'s mint');

    $this->config('daedalus_stylish.settings')->set('ai_minting', FALSE)->save();
    $this->assertSame(['value' => 'STYLE_TOKEN_AI_MINTING_DISABLED'], $this->mintRefusal('space', 'b', '1rem', [], $ai));
    // Updates stay open (the gate is mint-only), and a person's mint too.
    $this->gesture('update', ['value' => '2rem'], 'space/a', $ai);
    $this->gesture('capture', ['group' => 'space', 'label' => 'b', 'value' => '1rem']);
    $this->assertSame('2rem', $this->loadSkin('mine')->packageTokens()['space']['a']['value']);

    $schema = ['fields' => [], 'styles' => ['type' => 'daedalus_stylish_styles', 'style_guide' => ['guidance' => 'x']]];
    $context = ['entity_type' => 'entity_test', 'bundle' => 'entity_test', 'view_mode' => 'full', 'cache_tags' => []];
    $this->container->get('module_handler')->alter('daedalus_blueprint_schema', $schema, $context);
    $this->assertStringContainsString('never save_material a token, a swatch, an animation or a font', $schema['materials']['guidance'], 'the closed gate names tokens');
  }

  /**
   * The roster teaches the token gesture, and the spine dispatches it here.
   */
  public function testGestureLineNamesTheKind(): void {
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $this->assertSame(['token', 'swatch', 'variant', 'animation', 'font'], array_keys($builder->gestureLines()), 'the token line comes first: roster order');
    $this->assertStringContainsString('group (the scale), label (the token name', $builder->gestureLines()['token']);
    $this->assertInstanceOf(DaedalusStylishMaterialsProvider::class, $builder->gestureProvider(MaterialKind::Token));

    $guidance = $this->container->get('daedalus_blueprint.operation_handler_registry')->getGuidance();
    $this->assertStringContainsString('"kind" (token|swatch|variant|animation|font)', $guidance['save_material']);
    $this->assertStringNotContainsString('skins skill', $guidance['save_material']);
  }

}
