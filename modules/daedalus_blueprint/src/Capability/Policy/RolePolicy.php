<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability\Policy;

use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\CapabilityPolicyInterface;
use Drupal\daedalus_blueprint\Capability\Refusal;
use Drupal\daedalus_blueprint\ElementLocator;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;

/**
 * Structure and inline block content follow core's Layout Builder grammar.
 *
 * Core's model is honored as the one grammar for what a role may do to a
 * page's document, so a site administrator grants the page builder with
 * the permissions core already documents:
 * - On a content host, configure, arrange and insert (the section tree's
 *   structure) are open to an actor holding one of core's three override
 *   permissions for the host's bundle: `configure any layout`,
 *   `configure all {bundle} {type} layout overrides` or `configure
 *   editable {bundle} {type} layout overrides`. The host access policy
 *   already demands update access on the host, so "all" and "editable"
 *   behave alike here (Edit Mode on an entity the actor cannot edit has
 *   no content story).
 * - An inline block's fields are content of the block, which core places
 *   behind `create and edit custom blocks`; every other content target
 *   (the host's fields, a paragraph) is answered by field access.
 * - On a template (a display host) everything follows `configure any
 *   layout` beside the display's update access, as core's defaults
 *   storage has it.
 * - On the site frame (a frame host) everything follows `configure the
 *   site frame`, the one permission over the frame (FrameInterface).
 * Style is the styling module's half (daedalus_stylish's policy).
 */
final class RolePolicy implements CapabilityPolicyInterface {

  public function __construct(
    private readonly ElementLocator $elementLocator,
    private readonly DaedalusLayoutAdapter $layoutAdapter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function judge(HostInterface $host, AccountInterface $actor, Capability $capability, string $target): ?Refusal {
    if ($capability === Capability::Style) {
      return NULL;
    }
    if ($host->entity() instanceof LayoutEntityDisplayInterface) {
      return $actor->hasPermission('configure any layout')
        ? NULL
        : Refusal::permission(['configure any layout'], sprintf('Editing the template of %s:%s', $host->entityTypeId(), $host->bundle()));
    }
    if ($host instanceof FrameHost) {
      return $actor->hasPermission(FrameInterface::PERMISSION)
        ? NULL
        : Refusal::permission([FrameInterface::PERMISSION], sprintf('Editing the site frame of %s', $host->id()));
    }
    if ($capability === Capability::Content) {
      return $this->judgeContent($host, $actor, $target);
    }
    $permissions = [
      'configure any layout',
      sprintf('configure all %s %s layout overrides', $host->bundle(), $host->entityTypeId()),
      sprintf('configure editable %s %s layout overrides', $host->bundle(), $host->entityTypeId()),
    ];
    foreach ($permissions as $permission) {
      if ($actor->hasPermission($permission)) {
        return NULL;
      }
    }
    return Refusal::permission($permissions, sprintf('Changing the layout of %s:%s', $host->entityTypeId(), $host->bundle()));
  }

  /**
   * Content of an inline block needs core's inline block permission.
   */
  private function judgeContent(HostInterface $host, AccountInterface $actor, string $target): ?Refusal {
    if ($target === $host->uuid() || $actor->hasPermission('create and edit custom blocks')) {
      return NULL;
    }
    $context = $this->elementLocator->locate($host, $target);
    if ($context === NULL || $context->isSection() || $context->isParagraph()) {
      return NULL;
    }
    /** @var \Drupal\layout_builder\SectionComponent $component */
    $component = $context->target;
    if (!$this->layoutAdapter->isInlineBlock($component)) {
      return NULL;
    }
    return Refusal::permission(['create and edit custom blocks'], "Editing the content of inline block '$target'");
  }

}
