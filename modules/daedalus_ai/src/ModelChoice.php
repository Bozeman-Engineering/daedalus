<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_ui\EditorSettings;
use Psr\Log\LoggerInterface;

/**
 * The curated model list and a user's choice from it.
 *
 * An administrator curates the models users may run the chat on
 * (daedalus_ai.settings model_choices, edited on the AI chat settings
 * form); a user holding the permission picks one from the chat's menu, and
 * the pick rides daedalus_ui's editor-preference lane (namespace
 * daedalus_ai, key model, the value in drupal/ai's provider__model grammar).
 * That lane accepts any string for any key, so the stored value is
 * untrusted and is validated here, where it is read: current() answers an
 * option only when the account holds the permission and the stored key is
 * in the curated list, and NULL otherwise, so a caller runs the site's
 * defaults in every other case. A stored key that is not in the list is a
 * notice (once per request per value), never an exception: the admin may
 * have removed the model since the user chose it.
 *
 * Every curated model is a model of the managed provider, the provider the
 * chat runs on without a choice (managedProvider(): the assistant's own
 * llm_provider, else the site's default chat provider, the resolution
 * ai_assistant_api's runner makes): a choice switches the model, never the
 * provider, because the chat's accelerators, the transcript's opaque pair
 * and the ledger's provider column all assume the spine's provider, and a
 * row on another provider would run the chat off it silently. A row on a
 * foreign provider is refused by choices() the way a malformed row is,
 * since config is the administrator's and fails visibly.
 */
final class ModelChoice {

  /**
   * The editor-preference namespace the choice is stored under.
   */
  public const NAMESPACE = 'daedalus_ai';

  /**
   * The editor-preference key the choice is stored under.
   */
  public const KEY = 'model';

  /**
   * The permission a user needs for their choice to count.
   */
  public const PERMISSION = 'choose ai model';

  /**
   * The config object the curated list lives on.
   */
  public const SETTINGS = 'daedalus_ai.settings';

  /**
   * The config key of the curated list.
   */
  public const CHOICES_KEY = 'model_choices';

  /**
   * The config key naming the assistant the chat talks to.
   */
  public const ASSISTANT_KEY = 'assistant_id';

  /**
   * The assistant's llm_provider value that means "the site's default".
   */
  public const DEFAULT_PROVIDER = '__default__';

  /**
   * The stored values already noticed this request, keyed by uid and value.
   *
   * @var array<string, true>
   */
  private array $noticed = [];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EditorSettings $editorSettings,
    private readonly LoggerInterface $logger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AiProviderPluginManager $aiProvider,
  ) {}

  /**
   * The provider the chat runs on without a choice.
   *
   * Resolved as ai_assistant_api's runner resolves the assistant's pair
   * (AiAssistantApiRunner::getProviderAndModel()), read off the entity
   * rather than the stateful runner: the assistant daedalus_ai.settings
   * names carries its own llm_provider unless that is empty or
   * "__default__", in which case the site's default chat provider
   * (ai.settings default_providers.chat) is the one.
   *
   * @return string
   *   The provider plugin id.
   *
   * @throws \RuntimeException
   *   When neither the assistant nor the site names a chat provider: there
   *   is no managed provider and nothing to curate.
   */
  public function managedProvider(): string {
    $assistant_id = $this->configFactory->get(self::SETTINGS)->get(self::ASSISTANT_KEY);
    $assistant = is_string($assistant_id) && $assistant_id !== ''
      ? $this->entityTypeManager->getStorage('ai_assistant')->load($assistant_id)
      : NULL;
    $provider = $assistant?->get('llm_provider');
    if (is_string($provider) && $provider !== '' && $provider !== self::DEFAULT_PROVIDER) {
      return $provider;
    }
    $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat');
    if (is_array($defaults) && is_string($defaults['provider_id'] ?? NULL) && $defaults['provider_id'] !== '') {
      return $defaults['provider_id'];
    }
    throw new \RuntimeException(sprintf(
      'No managed chat provider: %s names no provider of its own and ai.settings has no default chat provider, so there is nothing to curate.',
      $assistant === NULL
        ? ($assistant_id ? "the assistant \"$assistant_id\" does not exist" : 'no assistant is configured')
        : "the assistant \"$assistant_id\"",
    ));
  }

  /**
   * The curated list, in config order.
   *
   * @return list<\Drupal\daedalus_ai\ModelChoiceOption>
   *   The options; empty when nothing is curated.
   *
   * @throws \InvalidArgumentException
   *   When a config row is malformed or names a provider other than the
   *   managed one (config is the administrator's and a bad row fails
   *   visibly rather than vanishing from the menu).
   * @throws \RuntimeException
   *   When rows exist and no managed provider resolves.
   */
  public function choices(): array {
    $rows = $this->configFactory->get(self::SETTINGS)->get(self::CHOICES_KEY) ?? [];
    if (!is_array($rows)) {
      throw new \InvalidArgumentException(self::SETTINGS . ' ' . self::CHOICES_KEY . ' must be a list of rows.');
    }
    $options = array_map(ModelChoiceOption::fromConfig(...), array_values($rows));
    if ($options === []) {
      return [];
    }
    $managed = $this->managedProvider();
    foreach ($options as $option) {
      if ($option->provider !== $managed) {
        throw new \InvalidArgumentException(sprintf(
          'The model_choices row %s names the provider "%s" where the chat runs on "%s"; a choice switches the model, never the provider.',
          $option->key(),
          $option->provider,
          $managed,
        ));
      }
    }
    return $options;
  }

  /**
   * Whether any model is curated.
   *
   * @return bool
   *   TRUE when the list is non-empty.
   */
  public function hasChoices(): bool {
    return $this->choices() !== [];
  }

  /**
   * The account's valid choice.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account whose preference is read.
   *
   * @return \Drupal\daedalus_ai\ModelChoiceOption|null
   *   The curated option the account chose, or NULL when the account lacks
   *   the permission, chose nothing, or chose a key that is not (or no
   *   longer) curated.
   */
  public function current(AccountInterface $account): ?ModelChoiceOption {
    if (!$account->hasPermission(self::PERMISSION)) {
      return NULL;
    }
    $choices = $this->choices();
    if ($choices === []) {
      return NULL;
    }
    $stored = $this->editorSettings->getFor($account, self::NAMESPACE, self::KEY);
    if ($stored === NULL || $stored === '') {
      return NULL;
    }
    if (!is_string($stored)) {
      $this->noticeOnce($account, var_export($stored, TRUE), 'is not a string');
      return NULL;
    }
    foreach ($choices as $option) {
      if ($option->key() === $stored) {
        return $option;
      }
    }
    $this->noticeOnce($account, $stored, 'is not in the curated list');
    return NULL;
  }

  /**
   * Logs a stored value that cannot be honored, once per request per value.
   */
  private function noticeOnce(AccountInterface $account, string $value, string $why): void {
    $seen = $account->id() . ':' . $value;
    if (isset($this->noticed[$seen])) {
      return;
    }
    $this->noticed[$seen] = TRUE;
    $this->logger->notice('The stored model choice @value of user @uid @why; the site default runs.', [
      '@value' => $value,
      '@uid' => $account->id(),
      '@why' => $why,
    ]);
  }

}
