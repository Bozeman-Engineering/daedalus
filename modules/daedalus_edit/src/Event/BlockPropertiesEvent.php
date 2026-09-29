<?php

namespace Drupal\daedalus_edit\Event;

use Drupal\block_content\BlockContentInterface;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Form\FormStateInterface;

/**
 * Block properties event.
 *
 * Allows developers to add custom block properties to inline blocks: form
 * items on the block Configure dialog whose values the dialog commits as
 * the component's configuration keys through the op spine
 * (LayoutBuilderBlockFormAlter::formSubmit). A property is a managed
 * configuration key of the block type (a daedalus_blueprint.managed_keys
 * declaration); the spine refuses an undeclared one.
 */
class BlockPropertiesEvent extends Event {

  /**
   * The block properties (form items added to this event) saved on submit.
   *
   * @var array
   *   An array of $form_state->getValue() parameters.
   *
   * @see \Drupal\Core\Form\FormStateInterface::getValue()
   */
  protected array $blockProperties = [];

  /**
   * Constructs a BlockPropertiesEvent.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $sectionStorage
   *   The nested-aware section storage.
   * @param \Drupal\layout_builder\SectionComponent $sectionComponent
   *   The section component holding the inline block.
   * @param \Drupal\block_content\BlockContentInterface $blockContent
   *   The inline block's content entity.
   * @param array $inlineBlockConfiguration
   *   The inline block plugin configuration.
   * @param array $form
   *   The block form.
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The block form state.
   */
  public function __construct(
    private SectionStorageInterface $sectionStorage,
    private SectionComponent $sectionComponent,
    private BlockContentInterface $blockContent,
    private array $inlineBlockConfiguration,
    private array $form,
    private FormStateInterface $formState,
  ) {}

  /**
   * Gets the section storage.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface
   *   The nested-aware section storage.
   */
  public function getSectionStorage(): SectionStorageInterface {
    return $this->sectionStorage;
  }

  /**
   * Gets the section component.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The section component holding the inline block.
   */
  public function getSectionComponent(): SectionComponent {
    return $this->sectionComponent;
  }

  /**
   * Gets the inline block's content entity.
   *
   * @return \Drupal\block_content\BlockContentInterface
   *   The block content entity.
   */
  public function getBlockContent(): BlockContentInterface {
    return $this->blockContent;
  }

  /**
   * Gets the block form.
   *
   * @return array
   *   The form render array.
   */
  public function getForm(): array {
    return $this->form;
  }

  /**
   * Sets the block form.
   *
   * @param array $form
   *   The form render array.
   */
  public function setForm(array $form): void {
    $this->form = $form;
  }

  /**
   * Gets the block form state.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The form state.
   */
  public function getFormState(): FormStateInterface {
    return $this->formState;
  }

  /**
   * Sets the block form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function setFormState(FormStateInterface $form_state): void {
    $this->formState = $form_state;
  }

  /**
   * Gets the inline block plugin configuration.
   *
   * @return array
   *   The plugin configuration.
   */
  public function getInlineBlockConfiguration(): array {
    return $this->inlineBlockConfiguration;
  }

  /**
   * Gets the block properties added to this event.
   *
   * @return array
   *   The $form_state->getValue() parameters, one per added form item.
   */
  public function getBlockProperties(): array {
    return $this->blockProperties;
  }

  /**
   * Add block property.
   *
   * Informs LayoutBuilderBlockFormAlter::formSubmit that a form item was added
   * whose value is a configuration key to commit.
   *
   * @param string|array $block_property
   *   A form item key or an array of key paths for $form_state->getValue().
   *
   * @see \Drupal\Core\Form\FormStateInterface::getValue()
   */
  public function addBlockProperty($block_property): void {
    $this->blockProperties[] = $block_property;
  }

}
