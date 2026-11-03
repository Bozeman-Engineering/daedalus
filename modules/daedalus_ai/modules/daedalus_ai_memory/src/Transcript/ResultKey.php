<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Transcript;

/**
 * Mints the key under which a tool result states a known fact.
 *
 * A keyed result is one whose later copy supersedes its earlier one: the
 * schema of a bundle, a skill's doctrine, the print of a host, the read of
 * an entity. The key is what a segment's carry-over lists as "loaded", what
 * the segment policy counts, and what a prune by key would act on. A tool
 * whose result is not such a fact (an edit's reply, a validation, a bundle
 * list) has no key.
 *
 * The grammar, by the page builder's function names:
 * - eb_read_schema: schema:{entity_type}.{bundle}, then /{view_mode} and
 *   #{group} when the call narrowed by them.
 * - eb_skill: skill:{skill}.
 * - page_read and page_edit: print:{entity_type}:{entity_id} (both reply
 *   with the host's print, so the newer supersedes the older).
 * - eb_read_entity: read:{entity_type}:{entity_id}; eb_read_element adds
 *   #{element}.
 */
final class ResultKey {

  public const SCHEMA = 'schema';

  public const SKILL = 'skill';

  public const PRINT = 'print';

  public const READ = 'read';

  /**
   * The key of the fact a call's result states, or NULL when it states none.
   */
  public static function fromCall(ToolCall $call): ?string {
    $arguments = $call->arguments;
    switch ($call->name) {
      case 'eb_read_schema':
        $entity_type = self::string($arguments, 'entity_type');
        $bundle = self::string($arguments, 'bundle');
        if ($entity_type === NULL || $bundle === NULL) {
          return NULL;
        }
        $key = self::SCHEMA . ':' . $entity_type . '.' . $bundle;
        if (($view_mode = self::string($arguments, 'view_mode')) !== NULL) {
          $key .= '/' . $view_mode;
        }
        if (($group = self::string($arguments, 'group')) !== NULL) {
          $key .= '#' . $group;
        }
        return $key;

      case 'eb_skill':
        $skill = self::string($arguments, 'skill');
        return $skill === NULL ? NULL : self::SKILL . ':' . $skill;

      case 'page_read':
      case 'page_edit':
        return self::entityKey(self::PRINT, $arguments);

      case 'eb_read_entity':
        return self::entityKey(self::READ, $arguments);

      case 'eb_read_element':
        $key = self::entityKey(self::READ, $arguments);
        $element = self::string($arguments, 'element');
        return $key === NULL || $element === NULL ? $key : $key . '#' . $element;

      default:
        return NULL;
    }
  }

  /**
   * A {prefix}:{entity_type}:{entity_id} key, or NULL when either is absent.
   */
  private static function entityKey(string $prefix, array $arguments): ?string {
    $entity_type = self::string($arguments, 'entity_type');
    $entity_id = self::string($arguments, 'entity_id');
    if ($entity_type === NULL || $entity_id === NULL) {
      return NULL;
    }
    return $prefix . ':' . $entity_type . ':' . $entity_id;
  }

  /**
   * A non-empty scalar argument as a string, or NULL.
   */
  private static function string(array $arguments, string $name): ?string {
    $value = $arguments[$name] ?? NULL;
    if (is_int($value)) {
      return (string) $value;
    }
    if (!is_string($value) || trim($value) === '') {
      return NULL;
    }
    return trim($value);
  }

}
