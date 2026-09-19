<?php

namespace Drupal\daedalus_layout\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Context\ContextDefinitionInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The per-display promoted blocks selection (the Add panel's Promoted tab).
 *
 * Selection only: which blocks a display promotes is legitimately
 * per-display. Block ICONS are not configured here; the icon is a property
 * of the block TYPE (`block_content.type.*.third_party.daedalus_layout.icon`,
 * edited on the block type form).
 */
class PromotedBlocksForm extends FormBase {

  public function __construct(
    protected BlockManagerInterface $blockManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.block')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'daedalus_layout_promoted_blocks';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?EntityViewDisplayInterface $entity = NULL) {
    $form_state->setStorage(['entity_view_display' => $entity]);
    $third_party_settings = $entity->getThirdPartySetting('daedalus_layout', 'promoted_blocks') ?? [];

    $form['instructions']['#markup'] = $this->t('Select blocks to promote for common usage. Many blocks are seldom needed for normal page builder exercises. The blocks selected below will be promoted for easy use while the rest will still be searchable but not promoted.');

    $form['blocks'] = [
      '#type' => 'details',
      '#tree' => TRUE,
      '#title' => $this->t('Promoted Blocks'),
      '#open' => TRUE,
      '#attributes' => ['id' => 'promoted-blocks'],
    ];

    // Build a list of block options.
    $definitions = $this->blockManager->getFilteredDefinitions('layout_builder', NULL, [
      'list' => 'inline_blocks',
    ]);
    $grouped_definitions = $this->blockManager->getGroupedDefinitions($definitions);
    foreach ($grouped_definitions as $category => $blocks) {
      $options = [];
      foreach ($blocks as $block_id => $block) {
        $options[$block_id] = $this->getBlockLabel($block);
      }
      $category_id = Html::getId($category);
      $form['blocks'][$category_id] = [
        '#type' => 'checkboxes',
        '#title' => $category,
        '#options' => $options,
        '#default_value' => array_intersect($third_party_settings, array_keys($options)),
      ];

      // Move custom block types to the top of the list.
      if ($category === 'Inline blocks') {
        $form['blocks'][$category_id]['#weight'] = -10;
        $form['blocks'][$category_id]['#title'] = $this->t('Custom Blocks');
      }
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
    ];

    return $form;
  }

  /**
   * Get block label.
   *
   * @param array $block_definition
   *   The block definition.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The block label.
   */
  protected function getBlockLabel(array $block_definition): TranslatableMarkup {
    $bundle = '';
    $entity = $block_definition['context_definitions']['entity'] ?? NULL;
    if ($entity instanceof ContextDefinitionInterface) {
      $bundle = $entity->getConstraint('Bundle');
      if (!empty($bundle)) {
        $bundle = ucfirst($bundle[0]);
      }
    }

    return $this->t('@bundle@label', [
      '@bundle' => $bundle . ' ' ?? '',
      '@label' => $block_definition['admin_label'],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save the promoted blocks to the entity view display.
    $form_state->cleanValues();

    $promoted_blocks = array_filter(array_merge(...array_values($form_state->getValue('blocks'))));
    $entity_view_display = $form_state->getStorage()['entity_view_display'];
    $entity_view_display->setThirdPartySetting('daedalus_layout', 'promoted_blocks', $promoted_blocks);
    $entity_view_display->save();
  }

}
