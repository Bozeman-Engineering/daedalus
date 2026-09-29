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
 * Tests the AI-minting governance gate (daedalus_stylish.settings ai_minting).
 *
 * AI minting is its own governance axis, a site setting, never derived
 * from strict: strict governs what consumers may wear, ai_minting governs
 * whether AI may EXTEND the vocabulary. The blueprint create lane is where
 * every AI mint enters the spine (the generic create-entity tool, the HTTP
 * lane), and every viable mint writes the value field, so the one
 * enforcement point is SwatchValueFieldHandler on create-lane writes. This
 * pins all four lanes against a closed gate: the blueprint mint refuses
 * with STYLE_SWATCH_AI_MINTING_DISABLED, blueprint UPDATES of existing
 * swatches stay open (the gate is mint-only), the importer's shipped_sync
 * disk lane is exempt, and the human gesture lane (SwatchManager::mint)
 * never passes the handler at all. Plus the discovery half: the palette
 * fragment's description advertises the closed gate, and only an explicit
 * FALSE closes it (sites predating the key keep the open lane).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SwatchAiMintingGateTest extends KernelTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    // The roster's daedalus_stylish provider queries the variant and skin storages
    // unconditionally on the same schema alter.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    // Entity access is not what this test pins (DaedalusStylishSwatchTest covers
    // the permission grid); the acting account carries the full swatch
    // grant so every refusal below is the gate, never access.
    $this->setUpCurrentUser([], ['create swatches', 'update swatches', 'administer swatches']);
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
      'entity_type' => 'daedalus_swatch',
      'bundle' => 'daedalus_swatch',
      'fields' => [
        'label' => 'Brand red',
        'machine_name' => 'brand_red',
        'kind' => 'color',
        'value' => '#e94560',
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
   * Shipped install default and a missing key both leave the lane open.
   */
  public function testOpenByDefaultAndFailOpen(): void {
    $this->assertTrue($this->config('daedalus_stylish.settings')->get('ai_minting'), 'The shipped default is an open gate.');

    $result = $this->deserialize($this->mintBlueprint());
    $this->assertTrue($result->isSuccess(), 'The blueprint mint succeeds with the shipped default.');
    $result->getEntity()->save();

    // A site predating the key (no ai_minting in stored config) keeps the
    // open lane until the post_update seeds it: only an explicit FALSE
    // closes the gate.
    $this->setAiMinting(NULL);
    $blueprint = $this->mintBlueprint();
    $blueprint['fields']['label'] = 'Brand blue';
    $blueprint['fields']['machine_name'] = 'brand_blue';
    $blueprint['fields']['value'] = '#1a1a2e';
    $this->assertTrue($this->deserialize($blueprint)->isSuccess(), 'A missing key fails open.');
  }

  /**
   * A closed gate refuses the blueprint mint, and only the mint.
   */
  public function testClosedGateRefusesMintsOnly(): void {
    // Seed an existing swatch through the still-open lane.
    $result = $this->deserialize($this->mintBlueprint());
    $this->assertTrue($result->isSuccess());
    $swatch = $result->getEntity();
    $swatch->setPublished();
    $swatch->save();

    $this->setAiMinting(FALSE);

    // The mint refuses at the spine with the governance error.
    $blueprint = $this->mintBlueprint();
    $blueprint['fields']['label'] = 'Brand blue';
    $blueprint['fields']['machine_name'] = 'brand_blue';
    $blueprint['fields']['value'] = '#1a1a2e';
    $refused = $this->deserialize($blueprint);
    $this->assertFalse($refused->isSuccess(), 'The blueprint mint refuses under a closed gate.');
    $codes = array_map(static fn (BlueprintError $error) => $error->getCode(), $refused->getErrors());
    $this->assertContains('STYLE_SWATCH_AI_MINTING_DISABLED', $codes);
    $paths = array_map(static fn (BlueprintError $error) => $error->getPath(), $refused->getErrors());
    $this->assertContains('fields.value', $paths, 'The refusal lands on the value field, the one field every mint writes.');

    // Updating an EXISTING swatch stays open: the gate governs vocabulary
    // extension, not value edits (those ride entity access).
    $update = $this->deserialize([
      'entity_type' => 'daedalus_swatch',
      'bundle' => 'daedalus_swatch',
      '_root_entity' => ['field' => 'machine_name', 'value' => 'brand_red'],
      'fields' => ['value' => '#111111'],
    ]);
    $this->assertTrue($update->isSuccess(), 'A closed gate leaves updates of existing swatches open.');
  }

  /**
   * The op lane honors the gate by origin: the AI's save_material refuses.
   *
   * The material gesture family is the AI's mint lane: a
   * save_material with kind swatch carrying the AI tools' origin option
   * meets the same governance gate the blueprint create lane does, while
   * the same op from a person (no origin option, or user) passes: the gate
   * governs whether the AI may extend the vocabulary, never people.
   */
  public function testClosedGateRefusesTheAiOpLane(): void {
    $this->setAiMinting(FALSE);
    $provider = $this->container->get('daedalus_stylish.materials_provider');
    $gesture = ['label' => 'Op teal', 'value' => '#0aa8a8'];

    try {
      $provider->capture(MaterialKind::Swatch, $gesture, NULL, [MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_AI]);
      $this->fail('Expected STYLE_SWATCH_AI_MINTING_DISABLED.');
    }
    catch (MaterialGestureException $exception) {
      $this->assertSame('STYLE_SWATCH_AI_MINTING_DISABLED', $exception->getErrors()[0]->getCode());
    }
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('daedalus_swatch')->loadByProperties(['label' => 'Op teal']));

    $result = $provider->capture(MaterialKind::Swatch, $gesture, NULL, [MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_USER]);
    $this->assertSame('op_teal', $result->id);
    $this->assertSame('user', $result->entity->getOrigin());

    // An open gate lets the AI mint, and the mint carries its origin.
    $this->setAiMinting(TRUE);
    $result = $provider->capture(MaterialKind::Swatch, ['label' => 'Ai teal', 'value' => '#0aa8a9'], NULL, [MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_AI]);
    $this->assertSame('ai', $result->entity->getOrigin());
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame('ai', $roster->get(MaterialKind::Swatch, 'ai_teal')->origin);
  }

  /**
   * The disk and human lanes ignore the gate.
   */
  public function testDiskAndHumanLanesExempt(): void {
    $this->setAiMinting(FALSE);

    // Disk: the importer deserializes with shipped_sync, the one author
    // the gate must never refuse (module installs would break).
    $shipped = $this->deserialize($this->mintBlueprint(), ['shipped_sync' => TRUE]);
    $this->assertTrue($shipped->isSuccess(), 'shipped_sync creates pass a closed gate.');

    // Human: the palette gesture lane never passes the handler; the
    // "create swatches" permission is its governance.
    $minted = $this->container->get('daedalus_stylish.swatch_manager')->mint('Hero teal', 'color', '#0aa8a8');
    $this->assertSame('hero_teal', $minted->getMachineName(), 'The human mint gesture ignores the gate.');
  }

  /**
   * The materials fragment advertises the closed gate to the AI.
   */
  public function testFragmentAdvertisesClosedGate(): void {
    $alter = function (): array {
      $schema = [
        'fields' => [
          'daedalus_stylish_styles' => ['type' => 'daedalus_stylish_styles', 'style_guide' => ['guidance' => 'x']],
        ],
      ];
      $context = ['entity_type' => 'entity_test', 'bundle' => 'entity_test', 'view_mode' => 'full', 'cache_tags' => []];
      $this->container->get('module_handler')->alter('daedalus_blueprint_schema', $schema, $context);
      return [$schema['materials'], $context];
    };

    [$fragment, $context] = $alter();
    $this->assertStringNotContainsString('AI minting is DISABLED', $fragment['guidance'], 'An open gate stays out of the description.');
    $this->assertContains('config:daedalus_stylish.settings', $context['cache_tags'], 'The fragment invalidates when the governance posture changes.');

    $this->setAiMinting(FALSE);
    [$fragment] = $alter();
    $this->assertStringContainsString('AI minting is DISABLED', $fragment['guidance'], 'A closed gate is advertised, not discovered by refusal.');
    $this->assertStringContainsString('never save_material a swatch', $fragment['guidance']);
  }

}
