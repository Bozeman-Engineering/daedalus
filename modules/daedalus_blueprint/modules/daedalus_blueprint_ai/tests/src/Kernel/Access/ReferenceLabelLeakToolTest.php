<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel\Access;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * End-to-end reference-label leak coverage through the live eb_create tool.
 *
 * This is the RF1 review finding ("the net missed a sibling path") proven at
 * the boundary the AI actually reads: the YAML returned by the eb_create_entity
 * tool. The handler-level tests in EntityReferenceFieldHandlerTest prove that
 * label resolution excludes unviewable entities; these tests prove the closure
 * survives the full tool round-trip (deserialize -> BlueprintResult::toArray ->
 * DaedalusBlueprintToolBase::formatResult) and that nothing re-discloses a label
 * downstream of the gate.
 *
 * The leak is reached through a HYDRATING tool. eb_validate runs validate_only
 * (structural Phase A only) and never resolves references, so the reference
 * leak is only reachable through eb_create_entity / eb_edit's hydrating ops,
 * which run the full deserialize. eb_create_entity is the simplest such entry
 * point and is used here; the resolution gate is shared, so one tool proves the
 * boundary plumbing for all of them.
 *
 * Each vector discloses something the AI did NOT itself supply:
 * - similar[] suggestions echo the full real label of a near-match entity;
 * - ambiguous matches[] echo the target_ids/existence of exact-match entities;
 * - a LABEL_MISMATCH warning echoes the real label of a target_id's entity.
 * Echoing back the label the AI typed (e.g. in a NOT_FOUND message) is not a
 * leak, so the assertions target only tokens the AI never supplied, and each
 * vector has an access-conditional positive control proving the suppression is
 * a view-access gate, not blanket silencing.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
class ReferenceLabelLeakToolTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
  ];

  /**
   * The AI function call plugin manager.
   *
   * @var \Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager
   */
  protected $functionCallManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // A single-value reference to entity_test, whose view access is gated by
    // the 'view test entity' permission — the lever these tests pull to make a
    // target viewable or not.
    FieldStorageConfig::create([
      'field_name' => 'field_ref',
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'entity_test'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_ref',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Reference',
      'settings' => ['handler' => 'default'],
    ])->save();

    $this->functionCallManager = $this->container->get('plugin.manager.ai.function_calls');
  }

  /**
   * Runs eb_create_entity with a blueprint and returns the AI-readable output.
   *
   * @param array $blueprint
   *   The blueprint to pass as the tool's "blueprint" parameter.
   *
   * @return string
   *   The tool's readable output (the YAML the AI would receive).
   */
  protected function createEntityOutput(array $blueprint): string {
    $tool = $this->functionCallManager->getFunctionCallFromFunctionName('eb_create_entity');
    $this->assertNotNull($tool, 'The eb_create_entity tool is available.');
    $tool->setContextValue('blueprint', $blueprint);
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * Builds a single-field create blueprint referencing field_ref.
   *
   * @param array $field_ref
   *   The field_ref reference value (target_id and/or _label).
   *
   * @return array
   *   The blueprint.
   */
  protected function refBlueprint(array $field_ref): array {
    return [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['field_ref' => $field_ref],
    ];
  }

  /**
   * A near-match label of an unviewable entity is not offered as a suggestion.
   *
   * RF1: findSimilarLabels runs through the access-checked query, so the full
   * real label "Secret Dossier" of an entity the user cannot view must not
   * appear in the NOT_FOUND similar[] suggestions. The searched substring
   * "Secret" is the AI's own input and may still be echoed.
   */
  public function testSimilarSuggestionSuppressedForUnviewableTarget(): void {
    $this->createTestEntity(['name' => 'Secret Dossier']);
    $this->setUpCurrentUser([], ['administer entity_test content']);

    $output = $this->createEntityOutput($this->refBlueprint(['_label' => 'Secret']));

    $this->assertStringContainsString('REFERENCE_NOT_FOUND', $output);
    $this->assertStringNotContainsString('Dossier', $output);
  }

  /**
   * A near-match label IS suggested when the target is viewable.
   *
   * Access-conditional control for the suppression above: with view access the
   * suggestion is legitimate and the full label appears.
   */
  public function testSimilarSuggestionShownForViewableTarget(): void {
    $this->createTestEntity(['name' => 'Secret Dossier']);
    $this->setUpCurrentUser([], ['administer entity_test content', 'view test entity']);

    $output = $this->createEntityOutput($this->refBlueprint(['_label' => 'Secret']));

    $this->assertStringContainsString('REFERENCE_NOT_FOUND', $output);
    $this->assertStringContainsString('Secret Dossier', $output);
  }

  /**
   * Ambiguous matches against unviewable entities are not disclosed.
   *
   * RF1: two exact-label matches the user cannot view must not surface as a
   * REFERENCE_AMBIGUOUS candidate list (which would echo their target_ids and
   * confirm their existence). With both filtered out, resolution falls through
   * to a plain NOT_FOUND with no matches list.
   */
  public function testAmbiguousMatchesSuppressedForUnviewableTargets(): void {
    $this->createTestEntity(['name' => 'Shared Name']);
    $this->createTestEntity(['name' => 'Shared Name']);
    $this->setUpCurrentUser([], ['administer entity_test content']);

    $output = $this->createEntityOutput($this->refBlueprint(['_label' => 'Shared Name']));

    $this->assertStringContainsString('REFERENCE_NOT_FOUND', $output);
    $this->assertStringNotContainsString('REFERENCE_AMBIGUOUS', $output);
    $this->assertStringNotContainsString('matches', $output);
  }

  /**
   * Ambiguous matches ARE disclosed when the targets are viewable.
   *
   * Access-conditional control: with view access the ambiguity is a legitimate
   * disambiguation prompt and the candidate list is returned.
   */
  public function testAmbiguousMatchesShownForViewableTargets(): void {
    $this->createTestEntity(['name' => 'Shared Name']);
    $this->createTestEntity(['name' => 'Shared Name']);
    $this->setUpCurrentUser([], ['administer entity_test content', 'view test entity']);

    $output = $this->createEntityOutput($this->refBlueprint(['_label' => 'Shared Name']));

    $this->assertStringContainsString('REFERENCE_AMBIGUOUS', $output);
    $this->assertStringContainsString('matches', $output);
  }

  /**
   * A target_id's real label is not echoed when the target is unviewable.
   *
   * RF1: resolving an explicit (AI-supplied) target_id stays valid, but the
   * LABEL_MISMATCH warning echoes the entity's actual label — so it must be
   * suppressed when the user cannot view the target. The AI supplied only the
   * id and a bogus guess; the real label "Real Confidential Label" must not
   * appear.
   */
  public function testTargetIdMismatchLabelSuppressedForUnviewableTarget(): void {
    $target = $this->createTestEntity(['name' => 'Real Confidential Label']);
    $this->setUpCurrentUser([], ['administer entity_test content']);

    $output = $this->createEntityOutput($this->refBlueprint([
      'target_id' => (int) $target->id(),
      '_label' => 'Bogus Guess',
    ]));

    $this->assertStringNotContainsString('Real Confidential Label', $output);
    $this->assertStringNotContainsString('LABEL_MISMATCH', $output);
  }

  /**
   * The LABEL_MISMATCH warning IS emitted when the target is viewable.
   *
   * Access-conditional control: with view access the mismatch warning (and the
   * real label it echoes) is legitimate feedback to the AI.
   */
  public function testTargetIdMismatchLabelShownForViewableTarget(): void {
    $target = $this->createTestEntity(['name' => 'Real Confidential Label']);
    $this->setUpCurrentUser([], ['administer entity_test content', 'view test entity']);

    $output = $this->createEntityOutput($this->refBlueprint([
      'target_id' => (int) $target->id(),
      '_label' => 'Bogus Guess',
    ]));

    $this->assertStringContainsString('LABEL_MISMATCH', $output);
    $this->assertStringContainsString('Real Confidential Label', $output);
  }

}
