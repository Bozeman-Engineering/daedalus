<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Form;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\daedalus_stylish\ConditionTypePluginManager;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The skin add/edit form: the token editing UI.
 *
 * Tokens are authored as YAML — the same notation the disk lane
 * (MODULE.daedalus_stylish.skins.yml) uses, so what an admin types here is exactly
 * what a module would ship — and every submit validates through the token
 * seam (StyleMapValidator::validateTokens()), the same gate the blueprint
 * lane and the importer use. Inspector token pickers and richer editing
 * arrive with token consumption; this form is deliberately plain. Which
 * skin the site wears is not decided here: the site activates a design
 * system (/admin/appearance/design-systems) and wears its default skin.
 */
class DaedalusStylishSkinForm extends ContentEntityForm {

  /**
   * The one validation engine; tokens validate through its token seam.
   */
  protected StyleMapValidator $validator;

  /**
   * Canonicalizes compound condition spellings for storage.
   */
  protected ConditionTypePluginManager $conditionTypes;

  /**
   * Serves the declared token scales for the form's guidance text.
   */
  protected StyleGuide $styleGuide;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->validator = $container->get('daedalus_stylish.style_map_validator');
    $instance->conditionTypes = $container->get('plugin.manager.daedalus_stylish_condition_type');
    $instance->styleGuide = $container->get('daedalus_stylish.style_guide');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $skin = $this->entity;
    assert($skin instanceof DaedalusStylishSkinInterface);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $skin->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['machine_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Machine name'),
      '#default_value' => $skin->getMachineName(),
      '#maxlength' => 255,
      '#description' => $this->t('The stable name the active pointer and skin YAML files reference.'),
      '#machine_name' => [
        'exists' => [static::class, 'skinExists'],
        'source' => ['label'],
      ],
      // Identity: references (the active pointer, disk files) hold the
      // machine name, so it never changes after creation.
      '#disabled' => !$skin->isNew(),
    ];

    $scales = $this->styleGuide->getTokenScales($this->config('system.theme')->get('default') ?: NULL);
    $form['tokens'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Tokens'),
      '#default_value' => $skin->getTokens() === [] ? '' : Yaml::encode($skin->getTokens()),
      '#rows' => 20,
      '#description' => $scales === []
        ? $this->t("This site declares no token scales, so no tokens can be minted; scales are declared in code (the site declaration's token_scales section).")
        : $this->t('YAML mapping each scale to its token values. Declared scales: @scales. A value is a string, or a condition-keyed map whose "default" entry is required, e.g.<br><code>color:<br>&nbsp;&nbsp;accent:<br>&nbsp;&nbsp;&nbsp;&nbsp;default: "#1a1a2e"<br>&nbsp;&nbsp;&nbsp;&nbsp;dark: "#e8e8f0"</code>', ['@scales' => implode(', ', array_keys($scales))]),
    ];

    $form['defaults'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Site defaults'),
      '#default_value' => $skin->getDefaults() === [] ? '' : Yaml::encode($skin->getDefaults()),
      '#rows' => 20,
      '#description' => $this->t("YAML mapping scopes to element-relative styles, LIVE on every placement of the scope (per-element styles override them). Scopes: <code>base:{tag}</code> (bare prose markup), <code>block_type:{bundle}</code>, <code>layout:{layout_id}</code>, <code>kind:{entity|container|field|block}</code>. Keys are <code>''</code> for the scope's element or <code>'::.class-name'</code> for a declared part, e.g.<br><code>kind:container:<br>&nbsp;&nbsp;'':<br>&nbsp;&nbsp;&nbsp;&nbsp;default:<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;gap: 1.5rem</code>"),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Published'),
      '#default_value' => $skin->isNew() ? TRUE : $skin->isPublished(),
      '#description' => $this->t('Unpublished skins are drafts: administer-only, never deliverable.'),
    ];

    return $form;
  }

  /**
   * Machine name exists callback.
   */
  public static function skinExists(string $machine_name): bool {
    $ids = \Drupal::entityTypeManager()->getStorage('daedalus_skin')->getQuery()
      ->accessCheck(FALSE)
      ->condition('machine_name', $machine_name)
      ->range(0, 1)
      ->execute();
    return $ids !== [];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Parse and validate the tokens BEFORE the parent builds and validates
    // the entity, so buildEntity() can read the parsed result from the form
    // state and entity validation never sees malformed input.
    $raw = (string) $form_state->getValue('tokens', '');
    $tokens = [];
    if (trim($raw) !== '') {
      try {
        $tokens = Yaml::decode($raw);
      }
      catch (InvalidDataTypeException $exception) {
        $form_state->setErrorByName('tokens', $this->t('The tokens are not valid YAML: @message', ['@message' => $exception->getMessage()]));
        $tokens = NULL;
      }
      if ($tokens !== NULL && (!is_array($tokens) || (array_is_list($tokens) && $tokens !== []))) {
        $form_state->setErrorByName('tokens', $this->t('The tokens must be a YAML mapping of scale names to token maps.'));
        $tokens = NULL;
      }
      if (is_array($tokens)) {
        foreach ($this->validator->validateTokens($tokens) as $error) {
          $form_state->setErrorByName('tokens', $this->t('@path: @message @hint', [
            '@path' => $error->getPath(),
            '@message' => $error->getMessage(),
            '@hint' => $error->getHint(),
          ]));
        }
      }
    }
    if (is_array($tokens)) {
      // Storage keeps canonical compound condition spellings, the same
      // normalization every other token write path applies.
      $form_state->set('skin_tokens', $this->conditionTypes->normalizeTokensConditions($tokens));
    }

    // The defaults validate through the defaults seam
    // (StyleMapValidator::validateDefaults()), the same gate the blueprint
    // lane and the future disk lane use.
    $raw = (string) $form_state->getValue('defaults', '');
    $defaults = [];
    if (trim($raw) !== '') {
      try {
        $defaults = Yaml::decode($raw);
      }
      catch (InvalidDataTypeException $exception) {
        $form_state->setErrorByName('defaults', $this->t('The defaults are not valid YAML: @message', ['@message' => $exception->getMessage()]));
        $defaults = NULL;
      }
      if ($defaults !== NULL && (!is_array($defaults) || (array_is_list($defaults) && $defaults !== []))) {
        $form_state->setErrorByName('defaults', $this->t('The defaults must be a YAML mapping of scopes to element-relative styles.'));
        $defaults = NULL;
      }
      if (is_array($defaults)) {
        // Self-containment: the references resolve against THIS skin's
        // just-submitted tokens, never whichever skin is currently active.
        foreach ($this->validator->validateDefaults($defaults, ['skin_tokens' => is_array($tokens) ? $tokens : []]) as $error) {
          $form_state->setErrorByName('defaults', $this->t('@path: @message @hint', [
            '@path' => $error->getPath(),
            '@message' => $error->getMessage(),
            '@hint' => $error->getHint(),
          ]));
        }
      }
    }
    if (is_array($defaults)) {
      // Tolerant per scope: an invalid scope value has already failed the
      // form above; normalization must not fatally trip over it while the
      // errors render.
      $form_state->set('skin_defaults', array_map(
        fn ($styles) => is_array($styles) ? $this->conditionTypes->normalizeMapConditions($styles) : $styles,
        $defaults,
      ));
    }
    return parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $skin = parent::buildEntity($form, $form_state);
    assert($skin instanceof DaedalusStylishSkin);
    $skin->set('label', $form_state->getValue('label'));
    if ($skin->isNew()) {
      $skin->set('machine_name', $form_state->getValue('machine_name'));
    }
    $skin->setPublished((bool) $form_state->getValue('status'));
    $tokens = $form_state->get('skin_tokens');
    if (is_array($tokens)) {
      $skin->setTokens($tokens);
    }
    $defaults = $form_state->get('skin_defaults');
    if (is_array($defaults)) {
      $skin->setDefaults($defaults);
    }
    return $skin;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $skin = $this->entity;
    assert($skin instanceof DaedalusStylishSkinInterface);
    // Every save is a revision (the variant pattern): a bad token edit to
    // the site's look must always be one revert away.
    if (!$skin->isNew()) {
      $skin->setNewRevision();
      $skin->setRevisionCreationTime($this->time->getRequestTime());
      $skin->setRevisionUserId((int) $this->currentUser()->id());
    }
    $result = parent::save($form, $form_state);

    $this->messenger()->addStatus($result === SAVED_NEW
      ? $this->t('Created the %label skin.', ['%label' => $skin->label()])
      : $this->t('Updated the %label skin.', ['%label' => $skin->label()]));
    $form_state->setRedirectUrl($skin->toUrl('collection'));
    return $result;
  }

}
