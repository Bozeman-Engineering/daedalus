<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers skill definitions from per-file YAML across modules.
 *
 * Each skill is defined in its own file:
 * MODULE.daedalus_blueprint.skills.SKILL_ID.yml.
 *
 * When multiple modules define the same skill ID, definitions are merged
 * via array_replace_recursive (last module wins for conflicting keys).
 *
 * A definition may declare a `permission` key: without that permission on
 * the acting account the skill does not exist for the request. It is
 * neither listed in the conversation's skill catalog nor invokable through
 * the eb_skill tool, because every consumer reads through this resolver.
 * The gate applies per call, after the cache read, so the cached discovery
 * stays account-independent.
 *
 * A skill is a member of a design system, or global, exactly like a
 * material: its `design_system` key follows the one disk-lane rule
 * (ActiveDesignSystem::shippedMembership(): the key wins, `~` opts out,
 * absent means the system the shipping module packages, global when it
 * packages none), applied per file at discovery, so the cached definition
 * carries the resolved membership. A member of an inactive system does not
 * exist for the request, beside the permission gate: neither listed nor
 * invokable, since every consumer reads through here.
 */
class SkillsResolver implements SkillsResolverInterface {

  protected const CACHE_ID = 'daedalus_blueprint:skills';

  /**
   * Invalidated on module install/uninstall (discovery is per-directory).
   */
  public const CACHE_TAG = 'daedalus_blueprint_skills';

  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected CacheBackendInterface $cache,
    protected AccountInterface $currentUser,
    protected ActiveDesignSystem $activeDesignSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getSkills(): array {
    $active = $this->activeDesignSystem->activeId();
    return array_filter(
      $this->discoverSkills(),
      fn (array $definition): bool => (empty($definition['permission'])
        || $this->currentUser->hasPermission($definition['permission']))
        && (($definition['design_system'] ?? NULL) === NULL || $definition['design_system'] === $active),
    );
  }

  /**
   * Discovers and caches the full account-independent skill catalog.
   *
   * @return array
   *   All skill definitions keyed by skill ID, before the permission gate.
   */
  protected function discoverSkills(): array {
    $cached = $this->cache->get(static::CACHE_ID);
    if ($cached) {
      return $cached->data;
    }

    $skills = [];
    foreach ($this->moduleHandler->getModuleDirectories() as $module => $directory) {
      $pattern = "{$directory}/{$module}.daedalus_blueprint.skills.*.yml";
      foreach (glob($pattern) as $file) {
        $skill_id = $this->extractSkillId($file, $module);
        if ($skill_id === NULL) {
          continue;
        }
        $data = Yaml::parseFile($file);
        if (!is_array($data)) {
          continue;
        }
        $data['design_system'] = $this->membership($module, $skill_id, $file, $data);
        $skills[$skill_id] = isset($skills[$skill_id])
          ? array_replace_recursive($skills[$skill_id], $data)
          : $data;
      }
    }

    $this->moduleHandler->alter('daedalus_blueprint_skills', $skills);

    // Membership resolves against the landed and shipped systems, so a
    // package's files answer right before its install config lands; the
    // list tag is for a site-built system (no shipped file) a member
    // names explicitly, whose deletion must turn the cached membership
    // into a refusal on the next read.
    $this->cache->set(
      static::CACHE_ID,
      $skills,
      CacheBackendInterface::CACHE_PERMANENT,
      [static::CACHE_TAG, ...$this->activeDesignSystem->packagesCacheTags()],
    );

    return $skills;
  }

  /**
   * {@inheritdoc}
   */
  public function getSkill(string $id): ?array {
    $skills = $this->getSkills();
    return $skills[$id] ?? NULL;
  }

  /**
   * A skill file's membership through the one disk-lane rule, loudly.
   *
   * @throws \UnexpectedValueException
   *   When the file's design_system key is malformed or names a design
   *   system that is neither landed on this site nor shipped as install
   *   config by an installed module (a pending system's members resolve;
   *   only an id nothing lands and nothing ships refuses): a developer
   *   error in the shipping module, caught at discovery (the first cache
   *   rebuild) like a malformed blueprint file, never as a skill that
   *   quietly went global.
   */
  protected function membership(string $module, string $skill_id, string $file, array $data): ?string {
    try {
      return $this->activeDesignSystem->shippedMembership($module, $data);
    }
    catch (\InvalidArgumentException $exception) {
      throw new \UnexpectedValueException(sprintf('The "%s" skill of the "%s" module (%s): %s Name a design system the site has landed or an installed module ships, ship the missing one as install config beside the skill, drop the key (the module\'s own shipped design system applies) or set it to ~ (global).', $skill_id, $module, basename($file), $exception->getMessage()), 0, $exception);
    }
  }

  /**
   * Extracts the skill ID from a YAML filename.
   *
   * @param string $file
   *   The full file path.
   * @param string $module
   *   The module machine name.
   *
   * @return string|null
   *   The skill ID, or NULL if the filename doesn't match.
   */
  protected function extractSkillId(string $file, string $module): ?string {
    $basename = basename($file, '.yml');
    $prefix = "{$module}.daedalus_blueprint.skills.";
    if (!str_starts_with($basename, $prefix)) {
      return NULL;
    }
    $skill_id = substr($basename, strlen($prefix));
    return $skill_id !== '' ? $skill_id : NULL;
  }

}
