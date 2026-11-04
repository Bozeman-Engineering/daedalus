<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Form;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Checkboxes;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Materials\BudgetReading;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Drupal\daedalus_blueprint\Materials\MaterialsProviderInterface;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The design system size: the usage bar over a box per material.
 *
 * The display of what the site shares with the AI (route
 * daedalus_blueprint.ai_context at MaterialsBudget::DIALOG_PATH, the path
 * the refusal hint names), opened in the daedalus popup by the chat
 * kebab, the stylish cards' link and the blueprint dialogs' link
 * through daedalus_ui's one opener, and a page of its own at that path.
 * Above, the bar of the whole budget (daedalus_blueprint_ai_context over
 * MaterialsBudget::reading()); below, one collapsed section per material
 * kind the roster carries, listing every record of the kind (offered or
 * not; tokens under their scale) as a checkbox: the record's `offered`
 * flag, the same control the cards and the blueprint dialogs carry, with
 * the record's own weight beside it ("Label · about n tokens" from the
 * reading's per-record characters; no figure while a record renders
 * nowhere, because unoffered or because its lines are another record's,
 * as a blueprint's owned looks are). A kind's headings and punctuation
 * belong to no one box and show once, in the legend's kind total, so the
 * boxes' figures plus the kind's shared equal the bar's segment.
 *
 * Save writes each changed box through its kind's gesture provider
 * (MaterialsRosterBuilder::gestureProvider()->update() with the one key
 * `offered` and empty options), the spine's one validation path: the
 * provider's own permission grade and the AI context budget's guard
 * (MaterialsBudget::assertRoom(), AI_CONTEXT_FULL with the one wording)
 * answer here as they answer the AI's ops and the details routes. A
 * refusal becomes an error message in the rebuilt form and the other
 * changes stand; withholds are written before shares, so a submit that
 * trades one material for another passes while the site is full. The
 * submit always rebuilds, so the form answers with the fresh reading
 * (the bar shrinks, the box's figure goes) whether it was submitted by
 * AJAX in the popup or as a page. A record the update gesture may not
 * write (MaterialRecord::$updatable FALSE, a file-shipped blueprint) and
 * a kind no provider on the site teaches a gesture for render disabled
 * and keep their state.
 */
final class AiContextForm extends FormBase {

  /**
   * The form's id.
   *
   * Not the bar's theme hook (daedalus_blueprint_ai_context): the form
   * builder themes a form root by its id, and a form whose id named a
   * registered hook would render through that hook's template.
   */
  public const FORM_ID = 'daedalus_blueprint_ai_context_form';

  /**
   * The id of the wrapper the AJAX submit replaces.
   */
  public const WRAPPER_ID = 'daedalus-blueprint-ai-context';

  /**
   * Protected, not private or readonly: the form rides the form cache.
   *
   * An AJAX rebuild caches the form state with this object serialized in
   * it (FormBuilder::rebuildForm() always caches), so the second Save in
   * one popup unserializes it. FormBase's DependencySerializationTrait
   * records and restores the services it can see from its own scope: a
   * private property of this class is dropped at __sleep() and comes back
   * uninitialized, and a readonly one refuses __wakeup()'s write.
   */
  public function __construct(
    protected MaterialsBudget $budget,
    protected MaterialsRosterBuilder $rosterBuilder,
    protected ActiveDesignSystem $activeDesignSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(MaterialsBudget::class),
      $container->get(MaterialsRosterBuilder::class),
      $container->get(ActiveDesignSystem::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return self::FORM_ID;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $reading = $this->budget->reading();
    $roster = $this->rosterBuilder->build();
    $system = $this->activeDesignSystem->load();
    // The kinds the last submit changed reopen, so a save lands the
    // author back where they were working.
    $reopen = $form_state->get('changed_kinds') ?? [];

    $form['#prefix'] = '<div id="' . self::WRAPPER_ID . '">';
    $form['#suffix'] = '</div>';
    $form['#attributes']['class'][] = 'daedalus-ai-context-form';
    $form['#attached']['library'][] = 'daedalus_blueprint/ai_context';
    $form['messages'] = [
      '#type' => 'status_messages',
      '#weight' => -10,
    ];
    $form['usage'] = [
      '#theme' => 'daedalus_blueprint_ai_context',
      '#reading' => $reading,
      '#design_system' => $system?->label(),
    ];
    $metadata = CacheableMetadata::createFromObject($reading);
    if ($system !== NULL) {
      $metadata = $metadata->merge(CacheableMetadata::createFromObject($system));
    }
    $metadata->applyTo($form['usage']);

    $form['kinds'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['class' => ['daedalus-ai-context-form__kinds']],
    ];
    $roster->getCacheableMetadata()->applyTo($form['kinds']);
    foreach (MaterialKind::cases() as $kind) {
      $records = $roster->ofKind($kind);
      if ($records === []) {
        continue;
      }
      $writable = $this->rosterBuilder->gestureProvider($kind) !== NULL;
      $section = [
        '#type' => 'details',
        '#title' => $this->t('@kind (@count)', ['@kind' => ucfirst($kind->plural()), '@count' => count($records)]),
        '#open' => in_array($kind->value, $reopen, TRUE),
        '#attributes' => [
          'class' => ['daedalus-ai-context-form__kind'],
          'data-kind' => $kind->value,
        ],
      ];
      if ($kind === MaterialKind::Token) {
        foreach ($this->byScale($records) as $scale => $scale_records) {
          $section['scales'][$scale] = $this->boxes($scale_records, $reading, $writable, (string) $scale);
        }
      }
      else {
        $section['records'] = $this->boxes($records, $reading, $writable, ucfirst($kind->plural()), TRUE);
      }
      $form['kinds'][$kind->value] = $section;
    }

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
        '#button_type' => 'primary',
        '#ajax' => [
          'callback' => '::rebuilt',
          'wrapper' => self::WRAPPER_ID,
          'progress' => ['type' => 'fullscreen'],
        ],
      ],
    ];
    return $form;
  }

  /**
   * One checkbox per record: its `offered` flag, its own weight beside it.
   *
   * @param array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord> $records
   *   The records, keyed by id, in roster order.
   * @param \Drupal\daedalus_blueprint\Materials\BudgetReading $reading
   *   The reading the weights come from.
   * @param bool $writable
   *   Whether a provider on the site performs the kind's update gesture;
   *   FALSE renders every box disabled.
   * @param string $title
   *   The group's title: a token scale, else the kind's plural.
   * @param bool $title_invisible
   *   Whether the title is for assistive technology alone (the kind's
   *   section already says it).
   *
   * @return array
   *   A checkboxes element keyed by record id, the offered ids its
   *   default, each non-writable record's box disabled.
   */
  private function boxes(array $records, BudgetReading $reading, bool $writable, string $title, bool $title_invisible = FALSE): array {
    $element = [
      '#type' => 'checkboxes',
      '#title' => $title,
      '#title_display' => $title_invisible ? 'invisible' : 'before',
      '#options' => [],
      '#default_value' => [],
      '#attributes' => ['class' => ['daedalus-ai-context-form__boxes']],
    ];
    foreach ($records as $record) {
      $element['#options'][$record->id] = $this->optionLabel($record, $reading);
      if ($record->offered) {
        $element['#default_value'][] = $record->id;
      }
      if (!$writable || !$record->updatable) {
        $element[$record->id]['#disabled'] = TRUE;
      }
    }
    return $element;
  }

  /**
   * The box's label.
   *
   * The record's, with "about n tokens" while its own lines render.
   */
  private function optionLabel(MaterialRecord $record, BudgetReading $reading): \Stringable|string {
    $characters = $reading->record($record->kind, $record->id);
    if ($characters === 0) {
      return $record->label;
    }
    return $this->t('@label <span class="daedalus-ai-context-form__weight">· about @tokens tokens</span>', [
      '@label' => $record->label,
      '@tokens' => number_format((int) ceil($characters / $reading->charactersPerToken)),
    ]);
  }

  /**
   * The tokens by scale (MaterialRecord::$group), roster order within each.
   *
   * @param array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord> $records
   *   The token records, keyed by id.
   *
   * @return array<string, array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>>
   *   Scale => the scale's records keyed by id, scales in first-seen
   *   order; a token with no scale sits under ''.
   */
  private function byScale(array $records): array {
    $scales = [];
    foreach ($records as $id => $record) {
      $scales[$record->group ?? ''][$id] = $record;
    }
    return $scales;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $roster = $this->rosterBuilder->build();
    $changes = [];
    foreach (MaterialKind::cases() as $kind) {
      $section = $form_state->getValue(['kinds', $kind->value]);
      $provider = $this->rosterBuilder->gestureProvider($kind);
      if ($section === NULL || $provider === NULL) {
        continue;
      }
      $boxes = $kind === MaterialKind::Token
        ? array_merge([], ...array_values($section['scales'] ?? []))
        : ($section['records'] ?? []);
      $checked = Checkboxes::getCheckedCheckboxes($boxes);
      foreach ($roster->ofKind($kind) as $id => $record) {
        if (!$record->updatable) {
          continue;
        }
        $wanted = in_array($id, $checked, TRUE);
        if ($wanted !== $record->offered) {
          $changes[] = [$provider, $record, $wanted];
        }
      }
    }
    // Withholds before shares (a stable sort keeps roster order within
    // each), so trading one material for another passes while the site
    // is full: the guard reads the current reading at each share.
    usort($changes, static fn (array $a, array $b): int => $a[2] <=> $b[2]);
    $changed_kinds = [];
    foreach ($changes as [$provider, $record, $wanted]) {
      assert($provider instanceof MaterialsProviderInterface);
      assert($record instanceof MaterialRecord);
      $changed_kinds[$record->kind->value] = $record->kind->value;
      try {
        $provider->update($record->kind, $record->id, ['offered' => $wanted], NULL, []);
      }
      catch (MaterialGestureException $exception) {
        foreach ($exception->getErrors() as $error) {
          $this->messenger()->addError($this->t('@label: @message @hint', [
            '@label' => $record->label,
            '@message' => $error->getMessage(),
            '@hint' => $error->getHint(),
          ]));
        }
      }
    }
    // The rebuilt boxes read the records, not the submission: a refused
    // share comes back unticked. Without their input the form builder
    // falls back to each box's default on a rebuild.
    $input = $form_state->getUserInput();
    unset($input['kinds']);
    $form_state->setUserInput($input);
    $form_state->set('changed_kinds', array_values($changed_kinds));
    $form_state->setRebuild();
  }

  /**
   * The AJAX submit's answer: the rebuilt form over the fresh reading.
   */
  public function rebuilt(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

}
