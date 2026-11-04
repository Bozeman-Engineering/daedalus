<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Materials;

use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Element;
use Drupal\daedalus_blueprint\Form\AiContextForm;
use Drupal\daedalus_blueprint\Materials\BudgetReading;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint_materials_test\TestGroupsContributor;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The design system size form: a box per record, written through the providers.
 *
 * Over the fixture provider (one record of every kind, tokens two, plus
 * a third color token, so one kind holds several writable records): the
 * built form lists
 * each kind's records under a collapsed section titled by plural and
 * count, tokens under their scale, the offered ones checked with their
 * own weight in the label, a record the update gesture may not write
 * disabled (the file-shipped blueprint; every record of a kind no
 * provider teaches a gesture for); a submit unticking a record is one
 * provider update with the one key `offered`, after which the roster
 * reads the flag off and the reading shrinks, and the rebuilt form shows
 * it so; a submit ticking one past a tiny budget is refused by the
 * guard with the one wording as an error message, writes nothing for it
 * and rebuilds with the box unticked, while the withhold in the same
 * submit went first and stands.
 */
#[CoversClass(AiContextForm::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
final class AiContextFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'daedalus_blueprint',
    'filter',
    'daedalus_blueprint_materials_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['daedalus_blueprint']);
    TestMaterialsProvider::$records = [
      ...TestMaterialsProvider::referenceRecords(),
      new MaterialRecord(
        kind: MaterialKind::Token,
        id: 'color/sky-blue',
        label: 'sky-blue',
        value: '#8cd3ff',
        group: 'color',
        spellings: [MaterialRecord::SLOT_STYLE => 'var(--color-sky-blue)'],
      ),
    ];
    TestMaterialsProvider::$gestures = [];
    TestGroupsContributor::$groups = [];
  }

  /**
   * The built form.
   *
   * A section per kind, a box per record, weights and disabling.
   */
  public function testBuildListsEveryRecord(): void {
    $reading = $this->reading();
    $form_state = new FormState();
    $form = $this->container->get('form_builder')->buildForm(AiContextForm::class, $form_state);

    $this->assertSame('<div id="' . AiContextForm::WRAPPER_ID . '">', $form['#prefix'], 'The AJAX submit replaces the whole form.');
    $this->assertSame($reading->toArray(), $form['usage']['#reading']->toArray(), 'The bar draws the current reading.');
    $this->assertSame(AiContextForm::WRAPPER_ID, $form['actions']['submit']['#ajax']['wrapper']);
    // The fixture serves no font: no section for it; the others in
    // MaterialKind order, collapsed, titled by plural and count.
    $this->assertSame(['token', 'variant', 'blueprint', 'animation'], Element::children($form['kinds']));
    foreach (Element::children($form['kinds']) as $kind) {
      $this->assertSame('details', $form['kinds'][$kind]['#type']);
      $this->assertFalse($form['kinds'][$kind]['#open'], $kind);
    }
    $this->assertSame('Tokens (3)', (string) $form['kinds']['token']['#title']);
    $this->assertSame('Variants (5)', (string) $form['kinds']['variant']['#title']);

    // Tokens: the kind the fixture writes, under their scale; all three
    // offered, all enabled, each with its own figure.
    $this->assertSame(['color'], Element::children($form['kinds']['token']['scales']));
    $boxes = $form['kinds']['token']['scales']['color'];
    $this->assertSame('color', (string) $boxes['#title']);
    $ids = ['color/accent', 'color/lime-green', 'color/sky-blue'];
    $this->assertSame($ids, array_keys($boxes['#options']));
    $this->assertSame($ids, $boxes['#default_value']);
    foreach ($ids as $id) {
      $this->assertTrue(empty($boxes[$id]['#disabled']), $id);
      $this->assertTrue($boxes[$id]['#checked'], $id);
      $this->assertStringContainsString($this->figure($reading, MaterialKind::Token, $id), (string) $boxes[$id]['#title']);
    }

    // Animations: the fixture teaches no animation gesture, so the box is
    // disabled and keeps its state (checked: offered).
    $boxes = $form['kinds']['animation']['records'];
    $this->assertSame('invisible', $boxes['#title_display']);
    $this->assertTrue($boxes['fade_in']['#disabled']);
    $this->assertTrue($boxes['fade_in']['#checked']);
    $this->assertStringContainsString($this->figure($reading, MaterialKind::Animation, 'fade_in'), (string) $boxes['#options']['fade_in']);

    // The file-shipped blueprint: a writable kind, a record the gesture
    // may not write, so disabled and checked.
    $hero = $form['kinds']['blueprint']['records']['hero'];
    $this->assertTrue($hero['#disabled']);
    $this->assertTrue($hero['#checked']);
    $this->assertStringContainsString($this->figure($reading, MaterialKind::Blueprint, 'hero'), (string) $hero['#title']);

    // A record whose own lines render nowhere carries no figure: the
    // blueprint's owned look weighs under the blueprint's id.
    $this->assertSame(0, $reading->record(MaterialKind::Variant, 'hero_glow'));
    $this->assertSame('Hero glow', (string) $form['kinds']['variant']['records']['#options']['hero_glow']);
  }

  /**
   * Unticking a record writes `offered` false through its provider.
   *
   * The reading shrinks.
   */
  public function testUntickWritesThroughTheProvider(): void {
    $before = $this->reading();
    $weight = $before->record(MaterialKind::Token, 'color/lime-green');
    $this->assertGreaterThan(0, $weight);

    // The lime token unticked; an animation and the shipped blueprint
    // "unticked" too, which the form ignores: no provider writes the
    // first kind, and the second record is not updatable.
    $form_state = (new FormState())->setRequestMethod('POST')->setValues([
      'kinds' => [
        'token' => [
          'scales' => [
            'color' => [
              'color/accent' => 'color/accent',
              'color/lime-green' => NULL,
              'color/sky-blue' => 'color/sky-blue',
            ],
          ],
        ],
        'animation' => ['records' => ['fade_in' => NULL]],
        'blueprint' => ['records' => ['hero' => NULL]],
      ],
    ]);
    $this->container->get('form_builder')->submitForm(AiContextForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());

    $this->assertSame([
      [
        'verb' => 'update',
        'kind' => 'token',
        'id' => 'color/lime-green',
        'operation' => ['offered' => FALSE],
        'entity' => NULL,
        'options' => [],
      ],
    ], TestMaterialsProvider::$gestures, 'One gesture: the token, with the one key, no host, empty options.');
    $roster = $this->roster();
    $this->assertFalse($roster->get(MaterialKind::Token, 'color/lime-green')->offered);
    $this->assertTrue($roster->get(MaterialKind::Token, 'color/sky-blue')->offered);
    $this->assertTrue($roster->get(MaterialKind::Token, 'color/accent')->offered);
    $this->assertTrue($roster->get(MaterialKind::Animation, 'fade_in')->offered);
    $this->assertTrue($roster->get(MaterialKind::Blueprint, 'hero')->offered);

    $after = $this->reading();
    $this->assertSame(0, $after->record(MaterialKind::Token, 'color/lime-green'), 'An unoffered record renders nowhere.');
    $this->assertSame($before->record(MaterialKind::Token, 'color/sky-blue'), $after->record(MaterialKind::Token, 'color/sky-blue'));
    $this->assertLessThanOrEqual($before->of(MaterialKind::Token) - $weight, $after->of(MaterialKind::Token), 'The kind lost at least the record\'s own lines.');
    $this->assertSame($before->of(MaterialKind::Animation), $after->of(MaterialKind::Animation));
    $this->assertLessThan($before->total, $after->total);

    // The submit asked for a rebuild (a programmatic submit never performs
    // it, so the test does); the rebuilt form reads the fresh reading and
    // the records, not the submission: the box unticked without a figure,
    // its section reopened.
    $this->assertTrue($form_state->isRebuilding());
    $rebuilt = $this->container->get('form_builder')->rebuildForm(AiContextForm::FORM_ID, $form_state);
    $this->assertSame($after->toArray(), $rebuilt['usage']['#reading']->toArray());
    $this->assertFalse($rebuilt['kinds']['token']['scales']['color']['color/lime-green']['#checked']);
    $this->assertSame('lime-green', (string) $rebuilt['kinds']['token']['scales']['color']['#options']['color/lime-green']);
    $this->assertTrue($rebuilt['kinds']['token']['scales']['color']['color/sky-blue']['#checked']);
    $this->assertTrue($rebuilt['kinds']['token']['#open']);
    $this->assertFalse($rebuilt['kinds']['animation']['#open']);
    $this->assertSame([], $this->container->get('messenger')->messagesByType(MessengerInterface::TYPE_ERROR));
  }

  /**
   * The form survives the form cache: a second Save in one popup submits.
   *
   * An AJAX rebuild caches the form state with the form object serialized
   * in it, and the next submit unserializes it; FormBase's
   * DependencySerializationTrait restores only the services it can see
   * from its own scope (the FJS kebab leg's second Save answered a 500
   * from a private promoted property).
   */
  public function testSubmitSurvivesTheFormCache(): void {
    $form_object = $this->container->get('class_resolver')->getInstanceFromDefinition(AiContextForm::class);
    $form_object = unserialize(serialize($form_object), ['allowed_classes' => [AiContextForm::class]]);
    assert($form_object instanceof AiContextForm);
    $form_state = (new FormState())->setRequestMethod('POST')->setValues([
      'kinds' => [
        'token' => [
          'scales' => [
            'color' => [
              'color/accent' => 'color/accent',
              'color/lime-green' => NULL,
              'color/sky-blue' => 'color/sky-blue',
            ],
          ],
        ],
      ],
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertCount(1, TestMaterialsProvider::$gestures);
    $this->assertFalse($this->roster()->get(MaterialKind::Token, 'color/lime-green')->offered);
    $rebuilt = $this->container->get('form_builder')->rebuildForm(AiContextForm::FORM_ID, $form_state);
    $this->assertFalse($rebuilt['kinds']['token']['scales']['color']['color/lime-green']['#checked']);
  }

  /**
   * A share past a tiny budget is refused as a message.
   *
   * The withhold beside it stands.
   */
  public function testFullSiteRefusesTheShareAndKeepsTheRest(): void {
    $this->container->get('daedalus_blueprint.materials_roster_builder')
      ->gestureProvider(MaterialKind::Token)
      ->update(MaterialKind::Token, 'color/lime-green', ['offered' => FALSE], NULL, []);
    TestMaterialsProvider::$gestures = [];
    $this->config(MaterialsBudget::CONFIG_NAME)->set(MaterialsBudget::KEY_BUDGET, 1)->save();
    $this->assertTrue($this->reading()->over);

    // Tick the withheld token back on and untick another in one submit
    // (the accent stays ticked, unchanged): the withhold is written
    // first, the share is refused.
    $form_state = (new FormState())->setRequestMethod('POST')->setValues([
      'kinds' => [
        'token' => [
          'scales' => [
            'color' => [
              'color/accent' => 'color/accent',
              'color/lime-green' => 'color/lime-green',
              'color/sky-blue' => NULL,
            ],
          ],
        ],
      ],
    ]);
    $this->container->get('form_builder')->submitForm(AiContextForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors(), 'A refusal is a message, never a form error.');

    $this->assertSame(['color/sky-blue', 'color/lime-green'], array_column(TestMaterialsProvider::$gestures, 'id'), 'The withhold went first.');
    $this->assertSame([['offered' => FALSE], ['offered' => TRUE]], array_column(TestMaterialsProvider::$gestures, 'operation'));
    $roster = $this->roster();
    $this->assertFalse($roster->get(MaterialKind::Token, 'color/sky-blue')->offered, 'The withhold stands.');
    $this->assertFalse($roster->get(MaterialKind::Token, 'color/lime-green')->offered, 'The refused share wrote nothing.');

    $messages = $this->container->get('messenger')->messagesByType(MessengerInterface::TYPE_ERROR);
    $this->assertCount(1, $messages);
    $message = (string) $messages[0];
    $this->assertStringStartsWith('lime-green: The AI context budget is full', $message);
    $this->assertStringContainsString('Offer to the AI', $message);
    $this->assertStringContainsString(MaterialsBudget::DIALOG_PATH, $message);

    // The rebuilt boxes read the records, not the submission.
    $rebuilt = $this->container->get('form_builder')->rebuildForm(AiContextForm::FORM_ID, $form_state);
    $this->assertFalse($rebuilt['kinds']['token']['scales']['color']['color/lime-green']['#checked']);
    $this->assertFalse($rebuilt['kinds']['token']['scales']['color']['color/sky-blue']['#checked']);
    $this->assertTrue($rebuilt['usage']['#reading']->over);
  }

  /**
   * The current reading, from the spine.
   */
  private function reading(): BudgetReading {
    return $this->container->get('daedalus_blueprint.materials_budget')->reading();
  }

  /**
   * The current roster, from the spine.
   */
  private function roster(): MaterialsRoster {
    return $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
  }

  /**
   * The figure a record's label carries.
   *
   * "about n tokens" at the reading's ratio.
   */
  private function figure(BudgetReading $reading, MaterialKind $kind, string $id): string {
    $characters = $reading->record($kind, $id);
    $this->assertGreaterThan(0, $characters, sprintf('%s "%s" renders its own lines.', $kind->noun(), $id));
    return 'about ' . number_format((int) ceil($characters / $reading->charactersPerToken)) . ' tokens';
  }

}
