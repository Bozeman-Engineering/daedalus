<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Capability;

use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\CapabilityPolicyInterface;
use Drupal\daedalus_blueprint\Capability\Refusal;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;

/**
 * Styling follows a per-bundle permission, mirroring core's override grammar.
 *
 * The style half of the role axis (the spine's RolePolicy answers
 * structure and inline block content): on a content host, style on any
 * target is open to an actor holding `style {bundle} {type} elements` for
 * the host's bundle (StylePermissions mints one per bundle with a style
 * store) or the umbrella `style any elements`, so editors can style
 * landing pages and not articles. On a template (a display host) style
 * follows the template rule, `configure any layout` beside the display's
 * update access. A host with no style store has nothing to permit: the
 * operation answers for itself there.
 */
final class StylePolicy implements CapabilityPolicyInterface {

  /**
   * The umbrella permission: style elements on every styled bundle.
   */
  public const ANY = 'style any elements';

  /**
   * The per-bundle permission name.
   */
  public static function permission(string $entity_type_id, string $bundle): string {
    return "style $bundle $entity_type_id elements";
  }

  /**
   * {@inheritdoc}
   */
  public function judge(HostInterface $host, AccountInterface $actor, Capability $capability, string $target): ?Refusal {
    if ($capability !== Capability::Style || !$host->hasStyleStore()) {
      return NULL;
    }
    if ($host->entity() instanceof LayoutEntityDisplayInterface) {
      return $actor->hasPermission('configure any layout')
        ? NULL
        : Refusal::permission(['configure any layout'], sprintf('Styling the template of %s:%s', $host->entityTypeId(), $host->bundle()));
    }
    // The site frame is the site builder's whole: styling it is the one
    // frame permission, as editing it is.
    if ($host instanceof FrameHost) {
      return $actor->hasPermission(FrameInterface::PERMISSION)
        ? NULL
        : Refusal::permission([FrameInterface::PERMISSION], sprintf('Styling the site frame of %s', $host->id()));
    }
    $permissions = [self::permission($host->entityTypeId(), $host->bundle()), self::ANY];
    foreach ($permissions as $permission) {
      if ($actor->hasPermission($permission)) {
        return NULL;
      }
    }
    return Refusal::permission($permissions, sprintf('Styling elements of %s:%s', $host->entityTypeId(), $host->bundle()));
  }

}
