<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_blueprint\BlueprintDeserializerInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\SkinDefaultsFieldHandler;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the distro lock on a skin's package layer.
 *
 * A skin with a LIVING disk source (its provider ships the entry right
 * now) has a read-only package layer on a consuming site: the blueprint
 * lane's tokens write refuses (SkinTokensFieldHandler through
 * SkinPackageLockTrait) and the admin form holds the two tiers disabled,
 * while the importer's own sync passes as the disk's writes (shipped_sync;
 * SkinImporterTest's auto-sync would fail if the gate refused it). The
 * defaults tier needs no lock on the blueprint lane: it has no write
 * there at all (SkinDefaultsFieldHandler refuses every write and names
 * update_default, whose own layer rule is SkinManager::writesSiteLayer()).
 * A skin whose provider stopped shipping it
 * lives on as ordinary site content and stays editable, and a skin the
 * site made has no lock at all. The developer gate lifts the lock for the
 * package author.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SkinDistroLockTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * A tokens write in the blueprint grammar, a bare value as shorthand.
   */
  protected const TOKENS_WRITE = ['space' => ['gutter' => '4rem']];

  /**
   * The record the write above stores.
   */
  protected const TOKENS_STORED = [
    'space' => ['gutter' => ['value' => '4rem', 'description' => '', 'offered' => TRUE]],
  ];

  /**
   * A defaults write against the base:h2 vocabulary the site declares.
   */
  protected const DEFAULTS_WRITE = ['base:h2' => ['' => ['default' => ['font-size' => '3rem']]]];

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
    // The vocabulary the shipped skin and the writes validate against.
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
   * Loads a skin by machine name, bypassing access.
   */
  protected function loadSkin(string $machine_name): ?DaedalusStylishSkinInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_skin');
    $storage->resetCache();
    $skins = $storage->loadByProperties(['machine_name' => $machine_name]);
    $skin = reset($skins) ?: NULL;
    assert($skin === NULL || $skin instanceof DaedalusStylishSkinInterface);
    return $skin;
  }

  /**
   * Writes one package-layer field of a skin through the blueprint lane.
   */
  protected function update(string $machine_name, string $field, array $value): BlueprintResult {
    $deserializer = \Drupal::service('daedalus_blueprint.deserializer');
    assert($deserializer instanceof BlueprintDeserializerInterface);
    return $deserializer->deserialize([
      'entity_type' => 'daedalus_skin',
      'bundle' => 'daedalus_skin',
      '_root_entity' => ['field' => 'machine_name', 'value' => $machine_name],
      'fields' => [$field => $value],
    ]);
  }

  /**
   * The error codes of a blueprint result.
   */
  protected function codes(BlueprintResult $result): array {
    return array_map(static fn ($error) => $error->getCode(), $result->getErrors());
  }

  /**
   * The blueprint lane refuses both package-layer tiers of a shipped skin.
   */
  public function testBlueprintLaneRefusesShippedPackageLayer(): void {
    $before = $this->loadSkin('aurora');
    $tokens = $before->packageTokens();
    $defaults = $before->packageDefaults();

    $result = $this->update('aurora', 'tokens', self::TOKENS_WRITE);
    $this->assertFalse($result->isSuccess(), 'the tokens write refused');
    $this->assertSame(['STYLE_SKIN_SHIPPED_LOCKED'], $this->codes($result));
    $error = $result->getErrors()[0];
    $this->assertSame('fields.tokens', $error->getPath());
    $this->assertSame(['provider' => 'daedalus_stylish_skins_test'], $error->getContext());

    // The defaults tier has no blueprint write on any skin: the refusal
    // is the op-only one, not the lock's.
    $result = $this->update('aurora', 'defaults', self::DEFAULTS_WRITE);
    $this->assertFalse($result->isSuccess(), 'the defaults write refused');
    $this->assertSame([SkinDefaultsFieldHandler::OP_ONLY], $this->codes($result));
    $this->assertSame('fields.defaults', $result->getErrors()[0]->getPath());

    // Refused before any value validation: an invalid payload gets the
    // lock's answer, not the validator's.
    $result = $this->update('aurora', 'tokens', ['nonsense' => ['x' => 'y']]);
    $this->assertSame(['STYLE_SKIN_SHIPPED_LOCKED'], $this->codes($result));

    $after = $this->loadSkin('aurora');
    $this->assertSame($tokens, $after->packageTokens(), 'the package layer is untouched');
    $this->assertSame($defaults, $after->packageDefaults());
    $this->assertFalse($after->isModified());

    // The tokens guidance says so while the lock holds; the defaults
    // guidance says so always (the op is its door, lock or no lock).
    $this->assertStringContainsString('READ-ONLY', (string) \Drupal::service('daedalus_stylish.field_handler.skin_tokens')->getGuidance());
    $this->assertStringContainsString('READ-ONLY here: a skin\'s defaults are written through the update_default operation', (string) \Drupal::service('daedalus_stylish.field_handler.skin_defaults')->getGuidance());
  }

  /**
   * A skin the site made has no lock: the tokens tier writes.
   *
   * The defaults tier refuses on the site's own skin too: no lock, no
   * blueprint write, the op is its door.
   */
  public function testSiteOwnSkinWrites(): void {
    $result = \Drupal::service('daedalus_blueprint.deserializer')->deserialize([
      'entity_type' => 'daedalus_skin',
      'bundle' => 'daedalus_skin',
      'fields' => ['label' => 'Mine', 'machine_name' => 'mine'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->getErrors(), TRUE));
    $result->getEntity()->save();
    $this->assertNull($this->loadSkin('mine')->getProvider());

    $result = $this->update('mine', 'tokens', self::TOKENS_WRITE);
    $this->assertTrue($result->isSuccess(), print_r($result->getErrors(), TRUE));
    $result->getEntity()->save();
    $this->assertSame(self::TOKENS_STORED, $this->loadSkin('mine')->packageTokens());

    $result = $this->update('mine', 'defaults', self::DEFAULTS_WRITE);
    $this->assertSame([SkinDefaultsFieldHandler::OP_ONLY], $this->codes($result));
    $this->assertSame([], $this->loadSkin('mine')->packageDefaults());
  }

  /**
   * A provider that stopped shipping the entry leaves the skin editable.
   *
   * Sync never deletes, so such a skin lives on as ordinary site content:
   * the lock reads the LIVING disk source, not the provider field alone.
   */
  public function testWritesLandWhenProviderStoppedShipping(): void {
    $skin = DaedalusStylishSkin::create([
      'label' => 'Orphaned',
      'machine_name' => 'orphaned',
      'status' => 1,
    ]);
    assert($skin instanceof DaedalusStylishSkinInterface);
    $skin->setPackageTokens(['space' => ['gutter' => '1rem']]);
    // daedalus_stylish_control_test ships no skins file: a provider whose
    // next release dropped the entry.
    $skin->setShippedSource('daedalus_stylish_control_test', ['tokens' => $skin->packageTokens(), 'defaults' => []]);
    $skin->save();
    $this->assertNull(\Drupal::service('daedalus_stylish.skin_importer')->getShippedData($this->loadSkin('orphaned')));

    $result = $this->update('orphaned', 'tokens', self::TOKENS_WRITE);
    $this->assertTrue($result->isSuccess(), print_r($result->getErrors(), TRUE));
    $result->getEntity()->save();
    $this->assertSame(self::TOKENS_STORED, $this->loadSkin('orphaned')->packageTokens());
  }

  /**
   * The developer gate lifts the lock: the package author edits in place.
   *
   * On a development site (`$settings['daedalus_blueprint_developer'] =
   * TRUE`, read live by DistroLock) the writes land and the skin reads as
   * modified against the disk, exactly the state the ship lane carries
   * back. Closing the gate locks the (still shipped) skin again.
   */
  public function testDeveloperGateLiftsTheLock(): void {
    $this->setSetting('daedalus_blueprint_developer', TRUE);
    $this->assertStringNotContainsString('READ-ONLY', (string) \Drupal::service('daedalus_stylish.field_handler.skin_tokens')->getGuidance());

    $result = $this->update('aurora', 'tokens', self::TOKENS_WRITE);
    $this->assertTrue($result->isSuccess(), print_r($result->getErrors(), TRUE));
    $result->getEntity()->save();
    $skin = $this->loadSkin('aurora');
    $this->assertSame(self::TOKENS_STORED, $skin->packageTokens());
    $this->assertTrue($skin->isModified());
    // Still shipped: the provider stamp survives the edit, so the disk
    // lane (ship, the next sync) keeps its subject.
    $this->assertSame('daedalus_stylish_skins_test', $skin->getProvider());

    // The gate lifts the LOCK; the defaults tier still has no blueprint
    // write (the developer edits a shipped skin's defaults through
    // update_default, which writes the package layer under the gate).
    $result = $this->update('aurora', 'defaults', self::DEFAULTS_WRITE);
    $this->assertSame([SkinDefaultsFieldHandler::OP_ONLY], $this->codes($result));

    $this->setSetting('daedalus_blueprint_developer', FALSE);
    $this->assertSame(['STYLE_SKIN_SHIPPED_LOCKED'], $this->codes($this->update('aurora', 'tokens', self::TOKENS_WRITE)));
  }

}
