<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Compat;

use Drupal\Core\Session\AccountInterface;
use Drupal\filter\FilterFormatInterface;

/**
 * Text format lookups on core 11.3 and 11.4.
 *
 * Core 11.4 added \Drupal\filter\FilterFormatRepositoryInterface and
 * deprecated filter_formats() and filter_default_format() in its favor; core
 * 11.3 has only the functions. This answers the repository methods Daedalus
 * uses through whichever the running core has, with the same semantics: the
 * enabled formats by weight, filtered by access('use') for an account.
 *
 * Core 11.3 compatibility: see CORE_11_3_COMPAT.md at the project root.
 */
final class FilterFormats {

  /**
   * Core's text format repository, NULL on core 11.3.
   *
   * @var \Drupal\filter\FilterFormatRepositoryInterface|null
   */
  protected ?object $repository;

  /**
   * Constructs a FilterFormats object.
   *
   * @param \Drupal\filter\FilterFormatRepositoryInterface|null $repository
   *   Core's text format repository, NULL on core 11.3.
   */
  public function __construct(?object $repository) {
    $this->repository = $repository;
  }

  /**
   * The enabled text formats, by weight.
   *
   * @return array<string, \Drupal\filter\FilterFormatInterface>
   *   The formats keyed by id.
   */
  public function getAllFormats(): array {
    if ($this->repository) {
      return $this->repository->getAllFormats();
    }
    // Core 11.3 compatibility: the function is deprecated on 11.4, where this
    // branch is never reached.
    // @phpstan-ignore function.deprecated
    return filter_formats();
  }

  /**
   * The enabled text formats an account may use, by weight.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return array<string, \Drupal\filter\FilterFormatInterface>
   *   The formats keyed by id.
   */
  public function getFormatsForAccount(AccountInterface $account): array {
    if ($this->repository) {
      return $this->repository->getFormatsForAccount($account);
    }
    // Core 11.3 compatibility: the function is deprecated on 11.4, where this
    // branch is never reached.
    // @phpstan-ignore function.deprecated
    return filter_formats($account);
  }

  /**
   * An account's default text format: the first it may use, by weight.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\filter\FilterFormatInterface
   *   The default format.
   */
  public function getDefaultFormat(AccountInterface $account): FilterFormatInterface {
    if ($this->repository) {
      return $this->repository->getDefaultFormat($account);
    }
    // Core 11.3 compatibility: the function is deprecated on 11.4, where this
    // branch is never reached.
    // @phpstan-ignore function.deprecated
    $formats = filter_formats($account);
    return reset($formats);
  }

}
