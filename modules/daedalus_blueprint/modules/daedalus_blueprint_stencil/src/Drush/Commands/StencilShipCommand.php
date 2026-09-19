<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\daedalus_blueprint\Drush\Commands\RunsAsSelectedUserTrait;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint_stencil\Exception\StencilExportException;
use Drupal\daedalus_blueprint_stencil\ShipPlan;
use Drupal\daedalus_blueprint_stencil\StencilShipper;
use Drush\Commands\AutowireTrait;
use Drush\Exceptions\UserAbortException;
use Drush\Style\DrushStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ships a placed element to a module as a stencil file plus its variants.
 *
 * The build-on-the-canvas-then-ship workflow's shipping end, a thin
 * adapter over the stencil shipper: point it at the host entity and the
 * root element (typically a section container), name the module, and the
 * shipper works out whether the stencil and its variants exist anywhere,
 * writes MODULE.daedalus_blueprint.stencils.ID.yml and
 * MODULE.daedalus_stylish.variants.ID.yml, and leaves the site consistent (a content
 * stencil carrying the id graduates: its icon media is copied to
 * assets/stencils/ID.<ext> and named by the file's icon key, its owned
 * variants are adopted in place and the entity is deleted, behind a
 * confirmation). --dry-run prints the same per-item report without
 * touching anything.
 */
#[AsCommand(
  name: 'daedalus-blueprint:stencil-ship',
  description: 'Ships a placed element to a module as a stencil file plus its variants file.',
)]
final class StencilShipCommand extends Command {

  use AutowireTrait;
  use RunsAsSelectedUserTrait;

  /**
   * Constructs a StencilShipCommand instance.
   *
   * @param \Drupal\daedalus_blueprint_stencil\StencilShipper $shipper
   *   The stencil shipper.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   Switches to the --user account for the capture (used by the trait).
   */
  public function __construct(
    #[Autowire(service: 'daedalus_blueprint_stencil.shipper')]
    protected StencilShipper $shipper,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountSwitcherInterface $accountSwitcher,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addArgument('entity_type', InputArgument::REQUIRED, 'The host entity type ID (the entity whose style map holds the subtree\'s styles).')
      ->addArgument('id', InputArgument::REQUIRED, 'The host entity ID.')
      ->addArgument('element', InputArgument::REQUIRED, 'UUID of the root element to ship: a section (top-level band or nested) or an inline-block component.')
      ->addArgument('module', InputArgument::REQUIRED, 'The enabled module to ship into; the files land at its root.')
      ->addOption('id', NULL, InputOption::VALUE_REQUIRED, 'The stencil id, overriding the placement\'s provenance stamp. Required when the element was not instantiated from a stencil.')
      ->addOption('label', NULL, InputOption::VALUE_REQUIRED, 'The stencil label. Defaults to the graduating content stencil\'s, then the existing file\'s, then the element\'s display label.')
      ->addOption('description', NULL, InputOption::VALUE_REQUIRED, 'The stencil description. Defaults the same way, ending at a placeholder you must edit.')
      ->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Print the report without writing files, adopting variants, or deleting anything.')
      ->addOption('user', NULL, InputOption::VALUE_REQUIRED, 'User ID to capture as; field serialization is access-filtered for this user. Drush runs as anonymous by default, which sees almost nothing.', '1')
      ->addUsage('node 22 09c62771-ba5a-4d3d-b25b-e3ad5590f26f mymodule --dry-run')
      ->addUsage('node 22 09c62771-ba5a-4d3d-b25b-e3ad5590f26f mymodule --id=call_to_action --label=\'Call to Action\' -y');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new DrushStyle($input, $output);
    $entity_type = (string) $input->getArgument('entity_type');
    $id = (string) $input->getArgument('id');
    $element = (string) $input->getArgument('element');
    $module = (string) $input->getArgument('module');
    $uid = (int) $input->getOption('user');

    $entity = $this->entityTypeManager->getStorage($entity_type)->load($id);
    if ($entity === NULL) {
      $io->error("Entity {$entity_type} {$id} not found.");
      return self::FAILURE;
    }

    $options = [];
    foreach (['id', 'label', 'description'] as $option) {
      if (is_string($input->getOption($option))) {
        $options[$option] = $input->getOption($option);
      }
    }

    try {
      $plan = $this->runAsUser($uid, fn (): ShipPlan => $this->shipper->plan($entity, $element, $module, $options));
      $this->report($io, $plan);
      if ($input->getOption('dry-run')) {
        $io->comment('Dry run: nothing was written.');
        return self::SUCCESS;
      }
      if ($plan->graduates() && !$io->confirm(sprintf('Delete the content stencil "%s" (%s) after shipping it, adopting its variants in place?', $plan->graduating->label(), $plan->graduating->id()))) {
        throw new UserAbortException();
      }
      $plan = $this->runAsUser($uid, fn (): ShipPlan => $this->shipper->ship($entity, $element, $module, $options));
    }
    catch (MaterialGestureException $exception) {
      // The shipper's refusals and daedalus_stylish's variant refusals both carry
      // blueprint errors: shape them for the terminal and exit non-zero.
      $io->error($exception->getMessage());
      foreach ($exception->getErrors() as $error) {
        $output->writeln(sprintf('  %s [%s]: %s', $error->getPath(), $error->getCode(), $error->getMessage()));
        if ($error->getHint() !== '') {
          $output->writeln(sprintf('    Hint: %s', $error->getHint()));
        }
      }
      return self::FAILURE;
    }
    catch (StencilExportException $exception) {
      // The capture's refusal: the message is the operator-facing diagnosis.
      $io->error($exception->getMessage());
      return self::FAILURE;
    }

    $io->success(sprintf('Shipped the "%s" stencil to %s.', $plan->id, $this->shipper->filePath($module, $plan->id)));
    return self::SUCCESS;
  }

  /**
   * Prints the plan in the sync commands' line-per-item form.
   *
   * The dry run and the real run print the same lines: the stencil file's
   * outcome, the icon's outcome (copied and kept name the icon file), each
   * variant's outcome (owned ones name their stencil), each skipped
   * variant with its reason, the graduating content stencil, and every
   * warning.
   */
  protected function report(DrushStyle $io, ShipPlan $plan): void {
    $io->writeln(sprintf('%s: stencil %s %s (%s)', $plan->module, $plan->id, $plan->stencil, $this->shipper->filePath($plan->module, $plan->id)));
    $icon_file = isset($plan->definition['icon']) ? sprintf(' (%s)', $this->shipper->iconFilePath($plan->module, $plan->definition['icon'])) : '';
    $io->writeln(sprintf('%s: icon %s %s%s', $plan->module, $plan->id, $plan->icon, $icon_file));
    foreach ($plan->variantOutcomes as $name => $outcome) {
      $owner = isset($plan->variants[$name]['stencil']) ? sprintf(' (stencil: %s)', $plan->variants[$name]['stencil']) : '';
      $io->writeln(sprintf('%s: variant %s %s%s', $plan->module, $name, $outcome, $owner));
    }
    foreach ($plan->skipped as $name => $reason) {
      $io->writeln(sprintf('%s: variant %s skipped: %s', $plan->module, $name, $reason));
    }
    if ($plan->graduates()) {
      $io->writeln(sprintf('%s: content stencil "%s" (%s) graduates: deleted after the ship, its owned variants adopted in place', $plan->module, $plan->graduating->label(), $plan->graduating->id()));
    }
    foreach ($plan->warnings as $warning) {
      $io->writeln("<comment>Warning: {$warning}</comment>");
    }
  }

}
