<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_stylish\Controller\SkinMaterialApiController;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\SkinMaterialValidationException;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinAnimationsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinSiteAnimationsItem;
use Drupal\daedalus_stylish\SkinMaterialDeleteBatch;
use Drupal\daedalus_stylish\SkinMaterialManager;
use Drupal\daedalus_stylish\SkinStylesheet;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the skin's animations section: layers, seam, gestures, delivery.
 *
 * An animation is a record {steps, description, offered} of the skin
 * (SkinAnimationsItem), merged over two layers like a token, validated
 * through StyleMapValidator::validateAnimations() against the skin's own
 * tokens, minted, changed, renamed, reverted and removed through
 * SkinMaterialManager (the material gestures with kind animation and the
 * /daedalus_stylish/skin/animations routes), tracked in the one skin
 * material usage table as its keyframes name, rewritten to `none` before
 * a worn one goes, served on the roster from the active skin and printed
 * as @keyframes motion-{name} in the compiled skin file.
 */
#[CoversClass(SkinAnimationsItem::class)]
#[CoversClass(SkinSiteAnimationsItem::class)]
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SkinAnimationsTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * The fade steps, as a hand-written file or the mint card spells them.
   */
  protected const FADE = ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']];

  /**
   * The fade steps as stored: canonical offsets.
   */
  protected const FADE_CANONICAL = ['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'daedalus_blueprint',
    'filter',
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
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    $this->installConfig(['daedalus_stylish', 'daedalus_blueprint']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'color' => ['label' => 'Color', 'shape' => 'color'],
        'text' => ['label' => 'Type scale', 'shape' => 'dimension'],
      ],
    ]);
    // The container kind grants motion, so a default and a variant may
    // wear an animation here.
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['property_groups' => ['motion' => []]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * The manager, resolved late.
   */
  protected function manager(): SkinMaterialManager {
    return \Drupal::service('daedalus_stylish.skin_material_manager');
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
   * Creates and activates the site's own skin with a token and a fade.
   */
  protected function activateOwnSkin(): DaedalusStylishSkinInterface {
    $skin = DaedalusStylishSkin::create(['label' => 'Mine', 'machine_name' => 'mine', 'status' => 1]);
    $skin->setPackageTokens(['space' => ['4' => '1rem']]);
    $skin->setPackageAnimations(['fade_in' => ['steps' => self::FADE, 'description' => 'The quiet entrance.']]);
    $skin->save();
    $this->activateSkin($skin);
    return $this->loadSkin('mine');
  }

  /**
   * The error codes by path a gesture refuses with.
   */
  protected function refusal(callable $gesture): array {
    try {
      $gesture();
    }
    catch (SkinMaterialValidationException | MaterialGestureException $exception) {
      $codes = [];
      foreach ($exception->getErrors() as $error) {
        $codes[$error->getPath()] = $error->getCode();
      }
      return $codes;
    }
    $this->fail('The gesture was expected to refuse.');
  }

  /**
   * Drives the staged batch's operations by hand and returns the context.
   *
   * batch_process() (the route lane) has moved the operations into the
   * set's queue; the op lane's batch_set() leaves them on the set. Either
   * way the last operation is the tail named.
   *
   * @param string $tail
   *   The expected last operation (SkinMaterialDeleteBatch's method).
   * @param int $count
   *   The expected number of operations.
   */
  protected function driveBatch(string $tail, int $count): array {
    $batch = &batch_get();
    $set = end($batch['sets']);
    $operations = $set['operations'] ?? [];
    if ($operations === [] && isset($set['queue'])) {
      $queue = _batch_queue($set);
      while ($item = $queue->claimItem()) {
        $operations[] = $item->data;
        $queue->deleteItem($item);
      }
    }
    $this->assertCount($count, $operations);
    $this->assertSame([SkinMaterialDeleteBatch::class, $tail], end($operations)[0]);
    $context = [];
    foreach ($operations as [$callback, $arguments]) {
      call_user_func_array($callback, [...$arguments, &$context]);
    }
    $batch = [];
    return $context;
  }

  /**
   * A saved map host wearing a keyframes name on a motion property.
   */
  protected function createWearer(string $value, string $property = 'animation-name'): EntityTest {
    $host = EntityTest::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        'block:' . $this->container->get('uuid')->generate() => ['default' => [$property => $value]],
      ]),
    ]);
    $host->save();
    return $host;
  }

  /**
   * The store canonicalizes: bare steps wrap, offsets settle, layers merge.
   */
  public function testLayersAndCanonicalization(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora', 'status' => 1]);
    $skin->setPackageAnimations([
      'fade_in' => self::FADE,
      'pulse' => ['steps' => ['50%' => ['transform' => 'scale(1.05)'], '0%' => ['transform' => 'scale(1)']], 'description' => 'A heartbeat.', 'offered' => FALSE],
    ]);
    $skin->setSiteAnimations([
      'fade_in' => ['description' => 'Described by the site.'],
      'rise' => ['from' => ['opacity' => '0', 'transform' => 'translateY(1rem)'], 'to' => ['opacity' => '1', 'transform' => 'none']],
    ]);
    $skin->save();
    $skin = $this->loadSkin('aurora');

    $package = $skin->packageAnimations();
    $this->assertSame(['steps' => self::FADE_CANONICAL, 'description' => '', 'offered' => TRUE], $package['fade_in'], 'A bare steps map is the record with the defaults, offsets canonical.');
    $this->assertSame(['0%', '50%'], array_keys($package['pulse']['steps']), 'Steps sort ascending.');
    $this->assertFalse($package['pulse']['offered']);

    $site = $skin->siteAnimations();
    $this->assertSame(['description' => 'Described by the site.'], $site['fade_in'], 'A site record keeps exactly the facts it states.');
    $this->assertSame(['steps'], array_keys($site['rise']), 'A bare steps map in the site layer is {steps}.');
    $this->assertSame(['0%', '100%'], array_keys($site['rise']['steps']));

    $worn = $skin->animations();
    $this->assertSame(['fade_in', 'pulse', 'rise'], array_keys($worn), 'Package order, the site\'s additions after.');
    $this->assertSame(['steps' => self::FADE_CANONICAL, 'description' => 'Described by the site.', 'offered' => TRUE], $worn['fade_in'], 'A site fact replaces, the rest show through.');
    $this->assertSame('', $worn['rise']['description']);
    $this->assertTrue($worn['rise']['offered']);
    $this->assertSame(['fade_in' => self::FADE_CANONICAL, 'pulse' => $package['pulse']['steps'], 'rise' => $worn['rise']['steps']], SkinAnimationsItem::steps($worn));

    // The shipped payload carries the animations tier beside the others;
    // the site layer is outside it.
    $skin->setShippedSource('example', ['tokens' => [], 'animations' => $package, 'fonts' => [], 'defaults' => []])->save();
    $this->assertFalse($skin->isModified());
    $skin->setSiteAnimations(['fade_in' => ['steps' => ['0%' => ['opacity' => '0.5'], '100%' => ['opacity' => '1']]]])->save();
    $this->assertFalse($skin->isModified(), 'A site re-stepping is not a modification of what the package shipped.');
    $skin->setPackageAnimations(['fade_in' => self::FADE]);
    $this->assertTrue($skin->isModified(), 'The package layer is the payload the hash covers.');

    // A corrupt layer fails loudly.
    $this->expectException(\UnexpectedValueException::class);
    SkinAnimationsItem::mergeLayers([], ['ghost' => ['description' => 'no steps']]);
  }

  /**
   * The animations seam: names, records, the steps grammar, the skin's tokens.
   */
  public function testValidation(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $codes = static fn (array $errors): array => array_combine(array_map(static fn ($e) => $e->getPath(), $errors), array_map(static fn ($e) => $e->getCode(), $errors));

    $this->assertSame([], $validator->validateAnimations(SkinAnimationsItem::canonicalizeAnimations(['fade_in' => self::FADE])));
    $this->assertSame(['Fade In' => 'INVALID_ANIMATION_NAME'], $codes($validator->validateAnimations(['Fade In' => ['steps' => self::FADE_CANONICAL, 'description' => '', 'offered' => TRUE]])));
    $this->assertSame(['fade' => 'INVALID_ANIMATION_RECORD'], $codes($validator->validateAnimations(['fade' => 'nope'])));
    $this->assertSame(['fade' => 'INVALID_ANIMATION_RECORD'], $codes($validator->validateAnimations(['fade' => ['steps' => self::FADE_CANONICAL, 'description' => '', 'offered' => TRUE, 'label' => 'Fade']])), 'The record carries steps, description and offered and nothing else.');
    $this->assertSame([
      'fade.description' => 'INVALID_ANIMATION_RECORD',
      'fade.offered' => 'INVALID_ANIMATION_RECORD',
    ], $codes($validator->validateAnimations(['fade' => ['steps' => self::FADE_CANONICAL, 'description' => 3, 'offered' => 'yes']])));
    $this->assertSame(['broken.steps.0%.animation-duration' => 'INVALID_ANIMATION_STEP_PROPERTY'], $codes($validator->validateAnimations(['broken' => ['steps' => ['0%' => ['animation-duration' => '1s']], 'description' => '', 'offered' => TRUE]])), 'The steps seam\'s errors re-root under the name.');
    $this->assertSame(['empty.steps' => 'INVALID_ANIMATION_STEPS'], $codes($validator->validateAnimations(['empty' => ['steps' => [], 'description' => '', 'offered' => TRUE]])));

    // Self-containment: under strict law a step's var() resolves against
    // the skin the animations ride with, never the active skin.
    $this->enforce('strict');
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $slide = ['slide' => ['steps' => ['0%' => ['transform' => 'translateY(var(--space-4))'], '100%' => ['transform' => 'none']], 'description' => '', 'offered' => TRUE]];
    $this->assertSame([], $validator->validateAnimations($slide, ['skin_tokens' => ['space' => ['4' => ['value' => '1rem', 'description' => '', 'offered' => TRUE]]]]));
    $this->assertSame(['slide.steps.0%.transform' => 'STYLE_TOKEN_NOT_MINTED'], $codes($validator->validateAnimations($slide, ['skin_tokens' => []])));

    // A skin's defaults may wear the skin's own motion.
    $defaults = ['kind:container' => ['' => ['default' => ['animation-name' => 'motion-slide']]]];
    $this->assertSame([], $validator->validateDefaults($defaults, ['skin_tokens' => [], 'skin_animations' => $slide]));
    $this->assertSame(['kind:container.default.animation-name' => 'STYLE_ANIMATION_NOT_PUBLISHED'], $codes($validator->validateDefaults($defaults, ['skin_tokens' => [], 'skin_animations' => []])));
  }

  /**
   * The gestures on the site's own skin: mint, update, rename, delete.
   */
  public function testGesturesOnTheOwnSkin(): void {
    $skin = $this->activateOwnSkin();
    $manager = $this->manager();

    // Mint: the record lands canonical in the one layer, the roster and
    // the compiled skin file follow.
    $manager->mintAnimation($skin, 'rise', ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']], ' Lift. ');
    $skin = $this->loadSkin('mine');
    $this->assertSame(['steps' => self::FADE_CANONICAL, 'description' => 'Lift.', 'offered' => TRUE], $skin->packageAnimations()['rise']);
    $this->assertSame('package', $manager->animationLayer($skin, 'rise'));
    $this->assertNull($manager->animationShippedState($skin, 'rise'));
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame(['fade_in', 'rise'], array_keys($roster->animations()));
    $record = $roster->animations()['rise'];
    $this->assertSame(['rise', 'rise', 'Lift.', self::FADE_CANONICAL, MaterialRecord::ORIGIN_USER, TRUE, $skin->getDesignSystem()], [$record->id, $record->label, $record->description, $record->value, $record->origin, $record->updatable, $record->designSystem]);
    $this->assertSame(['animation' => 'motion-rise'], $record->spellings);
    $css = file_get_contents(\Drupal::state()->get(SkinStylesheet::STATE_KEY));
    $this->assertStringContainsString('@keyframes motion-rise {', $css);
    $this->assertStringContainsString('@keyframes motion-fade_in {', $css);
    $this->assertStringContainsString('--space-4: 1rem;', $css);
    $this->assertLessThan(strpos($css, '@keyframes motion-fade_in'), strpos($css, '--space-4: 1rem;'), 'The tokens lead the keyframes in the skin file.');

    // Refusals in the gesture's parameter space.
    $this->assertSame(['label' => 'STYLE_ANIMATION_EXISTS'], $this->refusal(fn () => $manager->mintAnimation($skin, 'rise', self::FADE)));
    $this->assertSame(['label' => 'INVALID_ANIMATION_NAME'], $this->refusal(fn () => $manager->mintAnimation($skin, 'Rise Up', self::FADE)));
    $this->assertSame(['steps.0%.animation-duration' => 'INVALID_ANIMATION_STEP_PROPERTY'], $this->refusal(fn () => $manager->mintAnimation($skin, 'bad', ['0%' => ['animation-duration' => '1s']])));
    $this->assertSame(['' => 'MISSING_PARAMETER'], $this->refusal(fn () => $manager->updateAnimation($skin, 'rise')));

    // Update: steps and facts, each a revisioned save.
    $manager->updateAnimation($skin, 'rise', ['0%' => ['opacity' => '0.2'], '100%' => ['opacity' => '1']], NULL, FALSE);
    $skin = $this->loadSkin('mine');
    $this->assertSame(['steps' => ['0%' => ['opacity' => '0.2'], '100%' => ['opacity' => '1']], 'description' => 'Lift.', 'offered' => FALSE], $skin->animations()['rise']);
    $this->assertStringContainsString('opacity: 0.2;', file_get_contents(\Drupal::state()->get(SkinStylesheet::STATE_KEY)));

    // Rename an unworn animation: the record moves in this request.
    $this->assertSame(0, $manager->renameAnimation($skin, 'rise', 'lift'));
    $skin = $this->loadSkin('mine');
    $this->assertSame(['fade_in', 'lift'], array_keys($skin->animations()));
    $this->assertSame(['label' => 'STYLE_ANIMATION_EXISTS'], $this->refusal(fn () => $manager->renameAnimation($skin, 'lift', 'fade_in')));

    // Delete an unworn animation: gone from the skin and the file.
    $this->assertSame(0, $manager->deleteAnimation($skin, 'lift'));
    $skin = $this->loadSkin('mine');
    $this->assertSame(['fade_in'], array_keys($skin->animations()));
    $this->assertStringNotContainsString('motion-lift', file_get_contents(\Drupal::state()->get(SkinStylesheet::STATE_KEY)));
  }

  /**
   * A worn animation: usage, the in-use grade, the rewrite-to-none batch.
   */
  public function testWornAnimationIsRewrittenBeforeItGoes(): void {
    $skin = $this->activateOwnSkin();
    $manager = $this->manager();
    $usage = $this->container->get('daedalus_stylish.skin_material_usage');
    $this->createWearer('motion-fade_in');
    $this->assertSame(1, $manager->animationUsages('fade_in'), 'The animation-name wear is tracked.');
    $this->createWearer('motion-fade_in 600ms ease-out both, motion-other 1s', 'animation');
    $this->assertSame(2, $manager->animationUsages('fade_in'), 'The shorthand\'s name component is tracked.');
    $variant = DaedalusStylishVariant::create([
      'label' => 'Entrance',
      'machine_name' => 'entrance',
      'scope' => 'kind:container',
    ]);
    $variant->setStyles(['' => ['default' => ['animation-name' => 'motion-fade_in'], 'reduced-motion' => ['animation' => 'none']]]);
    $variant->save();
    $this->assertSame(3, $manager->animationUsages('fade_in'), 'Two hosts and a variant wear it; a name the skin mints nothing under (motion-other) is tracked as a reference form and resolves to nothing.');
    $this->assertSame(1, $manager->animationUsages('other'), 'A motion-namespace name the skin mints nothing under is tracked verbatim; it simply matches no material.');

    // The op spine refuses a worn animation's removal and rename with the
    // count; the in-use grade gates the batch.
    $codes = $this->refusal(fn () => $manager->deleteAnimation($skin, 'fade_in', FALSE));
    $this->assertSame(['id' => 'STYLE_ANIMATION_IN_USE'], $codes);
    $this->assertSame(['id' => 'STYLE_ANIMATION_IN_USE'], $this->refusal(fn () => $manager->renameAnimation($skin, 'fade_in', 'appear', FALSE)));
    $this->setUpCurrentUser([], ['delete skin materials']);
    $this->assertSame(['id' => 'STYLE_ANIMATION_IN_USE'], $this->refusal(fn () => $manager->deleteAnimation($skin, 'fade_in')));

    // The route stages the batch for the in-use grade and drives it here.
    $this->setUpCurrentUser([], ['delete skin materials', 'delete skin materials in use', 'update skin materials']);
    $controller = SkinMaterialApiController::create($this->container);
    $this->assertSame(404, $controller->usage('animations', 'ghost')->getStatusCode());
    $read = json_decode((string) $controller->usage('animations', 'fade_in')->getContent(), TRUE);
    $this->assertSame(['fade_in', 'fade_in', 'motion-fade_in', 3, 'package', FALSE, NULL, NULL], [$read['id'], $read['name'], $read['keyframes'], $read['usages'], $read['layer'], $read['locked'], $read['provider'], $read['shipped_state']]);

    $response = $controller->rename('animations', 'fade_in', Request::create('/', 'POST', [], [], [], [], json_encode(['label' => 'appear'])));
    $this->assertSame(202, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame([FALSE, 'appear', 3], [$body['renamed'], $body['id'], $body['references']]);
    $this->assertSame(['fade_in'], array_column($body['materials']['remove'], 'id'));
    $this->assertSame('appear', $body['materials']['upsert'][0]['id']);
    $this->assertSame(['animation' => 'motion-appear'], $body['materials']['upsert'][0]['spellings']);
    $context = $this->driveBatch('renameSkinMaterial', 4);
    $this->assertSame(3, $context['results']['rewritten']);
    $this->assertSame('appear', $context['results']['renamed']);
    $skin = $this->loadSkin('mine');
    $this->assertSame(['appear'], array_keys($skin->animations()));
    $hosts = EntityTest::loadMultiple();
    $values = array_map(static fn (EntityTest $host): array => array_values(json_decode($host->get(DaedalusStylishFieldManager::FIELD_NAME)->value, TRUE))[0]['default'], $hosts);
    $this->assertSame([['animation-name' => 'motion-appear'], ['animation' => 'motion-appear 600ms ease-out both, motion-other 1s']], array_values($values), 'Every reference is respelled in place, the rest of the value untouched.');
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertSame('motion-appear', $variant->getStyles()['']['default']['animation-name']);
    $this->assertSame(3, $manager->animationUsages('appear'));
    $this->assertSame(0, $manager->animationUsages('fade_in'));

    // Delete: every reference becomes none, then the record goes.
    $response = $controller->delete('animations', 'appear');
    $this->assertSame(202, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame([FALSE, 3], [$body['deleted'], $body['references']]);
    $context = $this->driveBatch('deleteSkinMaterial', 4);
    $this->assertSame('appear', $context['results']['deleted']);
    $this->assertSame([], $this->loadSkin('mine')->animations());
    $values = array_map(static fn (EntityTest $host): array => array_values(json_decode($host->get(DaedalusStylishFieldManager::FIELD_NAME)->value, TRUE))[0]['default'], EntityTest::loadMultiple());
    $this->assertSame([['animation-name' => 'none'], ['animation' => 'none 600ms ease-out both, motion-other 1s']], array_values($values), 'The wearer keeps its timing and animates nothing.');
    $this->assertSame('none', DaedalusStylishVariant::load($variant->id())->getStyles()['']['default']['animation-name']);
    $this->assertSame(0, $usage->countReferencesTo('motion-appear'));
  }

  /**
   * The gestures on a shipped skin: the site layer, the lock, revert.
   */
  public function testGesturesOnAShippedSkin(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_skins_test']);
    $aurora = $this->loadSkin('aurora');
    $this->activateSkin($aurora);
    $manager = $this->manager();
    // The test package ships no animations; give the package layer one
    // as its file would have (the hash keeps the shipped payload honest).
    $this->assertSame([], $aurora->packageAnimations());

    // A mint lands in the site layer; the package layer stays the disk's.
    $manager->mintAnimation($aurora, 'rise', self::FADE, 'Mine.');
    $aurora = $this->loadSkin('aurora');
    $this->assertSame([], $aurora->packageAnimations());
    $this->assertSame(['rise'], array_keys($aurora->siteAnimations()));
    $this->assertSame('site', $manager->animationLayer($aurora, 'rise'));
    $this->assertFalse($aurora->isModified(), 'The site layer is outside the shipped payload.');
    $this->assertSame(MaterialRecord::ORIGIN_USER, $this->container->get('daedalus_blueprint.materials_roster_builder')->build()->animations()['rise']->origin);
    $this->assertTrue($manager->writesSiteLayer($aurora));

    // Revert refuses what the disk never shipped; a site-minted animation
    // deletes outright.
    $this->assertSame(['id' => 'SHIPPED_DEFAULTS_MISSING'], $this->refusal(fn () => $manager->revertAnimation($aurora, 'rise')));
    $this->assertSame(0, $manager->deleteAnimation($aurora, 'rise'));
    $this->assertSame([], $this->loadSkin('aurora')->animations());

    // The provider's gestures ride the same manager: the op spine with
    // kind animation, the ai origin meeting the minting gate.
    $provider = $this->container->get('daedalus_stylish.materials_provider');
    $provider->capture(MaterialKind::Animation, ['label' => 'glide', 'steps' => self::FADE, 'description' => 'Glide.'], NULL, [MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_AI]);
    $this->assertSame(['steps' => self::FADE_CANONICAL, 'description' => 'Glide.', 'offered' => TRUE], $this->loadSkin('aurora')->siteAnimations()['glide']);
    $this->assertSame(['global' => 'INVALID_PARAMETER'], $this->refusal(fn () => $provider->capture(MaterialKind::Animation, ['label' => 'x', 'steps' => self::FADE, 'global' => TRUE], NULL, [])));
    $this->assertSame(['label' => 'MISSING_PARAMETER'], $this->refusal(fn () => $provider->capture(MaterialKind::Animation, ['steps' => self::FADE], NULL, [])));
    $this->config('daedalus_stylish.settings')->set('ai_minting', FALSE)->save();
    $this->assertSame(['steps' => 'STYLE_ANIMATION_AI_MINTING_DISABLED'], $this->refusal(fn () => $provider->capture(MaterialKind::Animation, ['label' => 'y', 'steps' => self::FADE], NULL, [MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_AI])));
    $provider->capture(MaterialKind::Animation, ['label' => 'y', 'steps' => self::FADE], NULL, []);
    $this->assertArrayHasKey('y', $this->loadSkin('aurora')->animations(), 'A person mints past the AI gate.');
    $provider->update(MaterialKind::Animation, 'glide', ['offered' => FALSE, 'description' => 'Quiet.'], NULL, []);
    $this->assertSame(['steps' => self::FADE_CANONICAL, 'description' => 'Quiet.', 'offered' => FALSE], $this->loadSkin('aurora')->animations()['glide']);
    $this->assertSame(['id' => 'STYLE_ANIMATION_NOT_FOUND'], $this->refusal(fn () => $provider->update(MaterialKind::Animation, 'nope', ['steps' => self::FADE], NULL, [])));
    $result = $provider->remove(MaterialKind::Animation, 'glide', []);
    $this->assertNull($result->entity);
    $this->assertArrayNotHasKey('glide', $this->loadSkin('aurora')->animations());
  }

  /**
   * The disk lane carries the tier: import validates, ship writes it back.
   */
  public function testImporterAndShipperCarryAnimations(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_skins_test']);
    $importer = $this->container->get('daedalus_stylish.skin_importer');
    $shipper = $this->container->get('daedalus_stylish.skin_shipper');
    $aurora = $this->loadSkin('aurora');
    $this->assertSame([], $importer->getShippedData($aurora)['animations']);
    $this->assertSame(['tokens', 'animations', 'fonts', 'defaults'], array_keys($importer->getShippedData($aurora)));

    $definition = $shipper->definition($aurora);
    $this->assertSame(['label', 'design_system', 'tokens', 'animations', 'fonts', 'defaults'], array_keys($definition));
    $this->assertSame([], $definition['animations']);

    // The file grammar: a bare steps map or a record per name; a step the
    // seam refuses fails the import loudly at its path.
    $good = ['label' => 'Aurora', 'tokens' => [], 'animations' => ['fade_in' => self::FADE]];
    $importer->checkDefinitionShape('x', 'aurora', $good);
    $this->expectException(\Drupal\daedalus_stylish\Exception\ShippedSkinException::class);
    $importer->checkDefinitionShape('x', 'aurora', ['label' => 'Aurora', 'animations' => ['fade_in']]);
  }

  /**
   * A shipped animation's shipped state and lock, through the manager.
   */
  public function testShippedAnimationLockAndRevert(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Pkg', 'machine_name' => 'pkg', 'status' => 1]);
    $skin->setPackageTokens(['space' => ['4' => '1rem', '8' => '2rem']]);
    $skin->setPackageAnimations(['fade_in' => self::FADE]);
    $skin->save();
    $this->activateSkin($skin);
    $skin = $this->loadSkin('pkg');
    $manager = $this->manager();
    // No provider: the site's own skin, nothing to revert to.
    $this->assertSame(['id' => 'STYLE_ANIMATION_NOT_SHIPPED'], $this->refusal(fn () => $manager->revertAnimation($skin, 'fade_in')));
    $this->assertSame('package', $manager->animationLayer($skin, 'fade_in'));
    $this->assertNull($manager->animationShippedState($skin, 'fade_in'));
    $this->assertFalse($manager->writesSiteLayer($skin));
    // The roster origin is user on the site's own skin.
    $this->assertSame(MaterialRecord::ORIGIN_USER, $this->container->get('daedalus_blueprint.materials_roster_builder')->build()->animations()['fade_in']->origin);
    // The usage mirror reads the skin's own steps too: a step's var() is
    // a token reference of the skin.
    $manager->updateAnimation($skin, 'fade_in', ['0%' => ['opacity' => '0', 'transform' => 'translateY(var(--space-4))'], '100%' => ['opacity' => '1']]);
    $this->assertSame(1, $this->container->get('daedalus_stylish.skin_material_usage')->countReferencesTo('--space-4'));
    // Deleting the referenced token rewrites the step to the literal.
    $this->setUpCurrentUser([], ['delete skin materials in use']);
    $skin = $this->loadSkin('pkg');
    $this->assertSame(1, $manager->deleteToken($skin, 'space', '4'));
    $this->driveBatch('deleteSkinMaterial', 2);
    $this->assertSame('translateY(1rem)', $this->loadSkin('pkg')->animations()['fade_in']['steps']['0%']['transform']);
    $this->assertArrayNotHasKey('4', $this->loadSkin('pkg')->tokens()['space']);
  }

}
