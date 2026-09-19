<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the AI-minting governance gate for animations.
 *
 * One setting (daedalus_stylish.settings ai_minting) governs whether AI may EXTEND
 * the vocabulary, swatches and animations alike; the swatch gate test
 * pins the swatch lanes and the fragment advertisement, this one pins the
 * animation lanes against a closed gate: the blueprint mint refuses with
 * STYLE_ANIMATION_AI_MINTING_DISABLED (on the steps field, the one field
 * every mint writes), blueprint UPDATES of existing animations stay open,
 * the importer's shipped_sync disk lane is exempt, the human gesture lane
 * (AnimationManager::mint) never passes the handler, and the op lane
 * honors the gate by origin. Plus the blueprint lane's own facts: the
 * steps validate through the seam and store canonical, and a shipped
 * animation is read-only there (the distro lock's blueprint gate).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class AnimationAiMintingGateTest extends KernelTestBase {

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
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_animation');
    // The roster's daedalus_stylish provider queries the swatch, variant and skin
    // storages unconditionally on the same schema alter.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    // Entity access is not what this test pins (DaedalusStylishAnimationTest
    // covers the permission grid); the acting account carries the full
    // animation grant so every refusal below is the gate, never access.
    $this->setUpCurrentUser([], ['create animations', 'update animations', 'administer animations'], TRUE);
  }

  /**
   * Flips the governance setting.
   */
  protected function setAiMinting(?bool $enabled): void {
    $settings = $this->config('daedalus_stylish.settings');
    if ($enabled === NULL) {
      $settings->clear('ai_minting')->save();
    }
    else {
      $settings->set('ai_minting', $enabled)->save();
    }
  }

  /**
   * The blueprint create payload of an AI mint.
   */
  protected function mintBlueprint(): array {
    return [
      'entity_type' => 'daedalus_animation',
      'bundle' => 'daedalus_animation',
      'fields' => [
        'label' => 'Fade in',
        'machine_name' => 'fade_in',
        'description' => 'A soft entrance.',
        'steps' => ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']],
      ],
    ];
  }

  /**
   * Runs a blueprint through the deserializer, the AI lane's one spine.
   */
  protected function deserialize(array $blueprint, array $options = []) {
    return $this->container->get('daedalus_blueprint.deserializer')->deserialize($blueprint, $options);
  }

  /**
   * The blueprint lane: the steps seam, canonical storage, fail-open gate.
   */
  public function testBlueprintLaneValidatesAndStoresCanonical(): void {
    $this->assertTrue($this->config('daedalus_stylish.settings')->get('ai_minting'), 'The shipped default is an open gate.');

    $result = $this->deserialize($this->mintBlueprint());
    $this->assertTrue($result->isSuccess(), json_encode($result->toArray()));
    $animation = $result->getEntity();
    $animation->save();
    // Offsets stored canonical and the description landed.
    $this->assertSame(['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']], $animation->getSteps());
    $this->assertSame('A soft entrance.', $animation->getDescription());
    $this->assertSame('user', $animation->getOrigin());

    // The seam refuses at the spine, re-rooted onto the fields object.
    $blueprint = $this->mintBlueprint();
    $blueprint['fields']['machine_name'] = 'broken';
    $blueprint['fields']['steps'] = ['0%' => ['animation-duration' => '1s']];
    $refused = $this->deserialize($blueprint);
    $this->assertFalse($refused->isSuccess());
    $this->assertSame(['fields.steps.0%.animation-duration'], array_map(static fn (BlueprintError $error) => $error->getPath(), $refused->getErrors()));
    $this->assertSame('INVALID_ANIMATION_STEP_PROPERTY', $refused->getErrors()[0]->getCode());

    // A site predating the key (no ai_minting in stored config) keeps the
    // open lane until the post_update seeds it: only an explicit FALSE
    // closes the gate.
    $this->setAiMinting(NULL);
    $blueprint = $this->mintBlueprint();
    $blueprint['fields']['label'] = 'Rise';
    $blueprint['fields']['machine_name'] = 'rise';
    $this->assertTrue($this->deserialize($blueprint)->isSuccess(), 'A missing key fails open.');
  }

  /**
   * A closed gate refuses the blueprint mint, and only the mint.
   */
  public function testClosedGateRefusesMintsOnly(): void {
    // Seed an existing animation through the still-open lane.
    $result = $this->deserialize($this->mintBlueprint());
    $this->assertTrue($result->isSuccess());
    $animation = $result->getEntity();
    $animation->setPublished();
    $animation->save();

    $this->setAiMinting(FALSE);

    // The mint refuses at the spine with the governance error.
    $blueprint = $this->mintBlueprint();
    $blueprint['fields']['label'] = 'Rise';
    $blueprint['fields']['machine_name'] = 'rise';
    $refused = $this->deserialize($blueprint);
    $this->assertFalse($refused->isSuccess(), 'The blueprint mint refuses under a closed gate.');
    $codes = array_map(static fn (BlueprintError $error) => $error->getCode(), $refused->getErrors());
    $this->assertContains('STYLE_ANIMATION_AI_MINTING_DISABLED', $codes);
    $paths = array_map(static fn (BlueprintError $error) => $error->getPath(), $refused->getErrors());
    $this->assertContains('fields.steps', $paths, 'The refusal lands on the steps field, the one field every mint writes.');

    // Updating an EXISTING animation stays open: the gate governs
    // vocabulary extension, not step edits (those ride entity access).
    $update = $this->deserialize([
      'entity_type' => 'daedalus_animation',
      'bundle' => 'daedalus_animation',
      '_root_entity' => ['field' => 'machine_name', 'value' => 'fade_in'],
      'fields' => ['steps' => ['0%' => ['opacity' => '0.3'], '100%' => ['opacity' => '1']]],
    ]);
    $this->assertTrue($update->isSuccess(), 'A closed gate leaves updates of existing animations open.');
  }

  /**
   * The op lane honors the gate by origin: the AI's save_material refuses.
   */
  public function testClosedGateRefusesTheAiOpLane(): void {
    $this->setAiMinting(FALSE);
    $provider = $this->container->get('daedalus_stylish.materials_provider');
    $gesture = ['label' => 'Op fade', 'steps' => ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']]];

    try {
      $provider->capture(MaterialKind::Animation, $gesture, NULL, [MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_AI]);
      $this->fail('Expected STYLE_ANIMATION_AI_MINTING_DISABLED.');
    }
    catch (MaterialGestureException $exception) {
      $this->assertSame('STYLE_ANIMATION_AI_MINTING_DISABLED', $exception->getErrors()[0]->getCode());
    }
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('daedalus_animation')->loadByProperties(['label' => 'Op fade']));

    $result = $provider->capture(MaterialKind::Animation, $gesture, NULL, [MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_USER]);
    $this->assertSame('op_fade', $result->id);
    $this->assertSame('user', $result->entity->getOrigin());

    // An open gate lets the AI mint, and the mint carries its origin.
    $this->setAiMinting(TRUE);
    $result = $provider->capture(MaterialKind::Animation, [
      'label' => 'Ai fade',
      'steps' => ['0%' => ['opacity' => '0']],
    ], NULL, [MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_AI]);
    $this->assertSame('ai', $result->entity->getOrigin());
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame('ai', $roster->get(MaterialKind::Animation, 'ai_fade')->origin);
  }

  /**
   * The disk and human lanes ignore the gate; shipped entries lock.
   */
  public function testDiskAndHumanLanesExempt(): void {
    $this->setAiMinting(FALSE);

    // Disk: the importer deserializes with shipped_sync, the one author
    // the gate must never refuse (module installs would break).
    $shipped = $this->deserialize($this->mintBlueprint(), ['shipped_sync' => TRUE]);
    $this->assertTrue($shipped->isSuccess(), 'shipped_sync creates pass a closed gate.');

    // Human: the Materials gesture lane never passes the handler; the
    // "create animations" permission is its governance.
    $minted = $this->container->get('daedalus_stylish.animation_manager')->mint('Hero rise', [
      '0%' => ['opacity' => '0'],
      '100%' => ['opacity' => '1'],
    ]);
    $this->assertSame('hero_rise', $minted->getMachineName(), 'The human mint gesture ignores the gate.');

    // The distro lock's blueprint gate: an animation a module ships right
    // now refuses a generic update before validation.
    \Drupal::service('module_installer')->install(['daedalus_stylish_animations_test']);
    $locked = $this->deserialize([
      'entity_type' => 'daedalus_animation',
      'bundle' => 'daedalus_animation',
      '_root_entity' => ['field' => 'machine_name', 'value' => 'test_rise'],
      'fields' => ['steps' => ['0%' => ['opacity' => '0.5']]],
    ]);
    $this->assertFalse($locked->isSuccess());
    $this->assertSame('STYLE_ANIMATION_SHIPPED_LOCKED', $locked->getErrors()[0]->getCode());
  }

  /**
   * The materials fragment advertises the closed gate for animations too.
   */
  public function testFragmentAdvertisesClosedGate(): void {
    $this->setAiMinting(FALSE);
    $schema = [
      'fields' => [
        'daedalus_stylish_styles' => ['type' => 'daedalus_stylish_styles', 'style_guide' => ['guidance' => 'x']],
      ],
    ];
    $context = ['entity_type' => 'entity_test', 'bundle' => 'entity_test', 'view_mode' => 'full', 'cache_tags' => []];
    $this->container->get('module_handler')->alter('daedalus_blueprint_schema', $schema, $context);
    $this->assertStringContainsString('never save_material a swatch or an animation', $schema['materials']['guidance']);
  }

}
