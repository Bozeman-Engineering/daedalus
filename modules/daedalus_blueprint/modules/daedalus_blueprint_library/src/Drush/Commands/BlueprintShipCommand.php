<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\daedalus_blueprint\Drush\Commands\RunsAsSelectedUserTrait;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint_library\BlueprintManager;
use Drupal\daedalus_blueprint_library\BlueprintShipper;
use Drupal\daedalus_blueprint_library\ShipPlan;
use Drush\Commands\AutowireTrait;
use Drush\Exceptions\UserAbortException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ships a saved blueprint to a module as a blueprint file plus its variants.
 *
 * The compose-on-the-canvas-then-ship workflow's shipping end, a thin
 * adapter over the blueprint shipper: name the blueprint (machine name or
 * uuid) and the module, and the shipper writes
 * MODULE.daedalus_blueprint.blueprints.ID.yml and
 * MODULE.daedalus_stylish.variants.ID.yml from the blueprint's SAVED
 * document and graduates it: its icon media is copied to
 * assets/blueprints/ID.<ext> and named by the file's icon key, its owned
 * variants are adopted in place and the entity is deleted, behind a
 * confirmation. --dry-run prints the same per-item report plus the file as
 * it would be written, and touches nothing. A file blueprint is refused
 * here as everywhere (read-only on the site): edit the file by hand, or
 * save a new blueprint and ship it.
 */
#[AsCommand(
  name: 'daedalus-blueprint:blueprint-ship',
  description: 'Ships a saved blueprint to a module as a blueprint file plus its variants file; the content blueprint is deleted after the ship.',
)]
final class BlueprintShipCommand extends Command {

  use AutowireTrait;
  use RunsAsSelectedUserTrait;

  /**
   * Constructs a BlueprintShipCommand instance.
   *
   * @param \Drupal\daedalus_blueprint_library\BlueprintShipper $shipper
   *   The blueprint shipper.
   * @param \Drupal\daedalus_blueprint_library\BlueprintManager $manager
   *   The manager: resolves the blueprint and refuses a shipped file.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager (used by the trait).
   * @param \Drupal\Core\Session\AccountSwitcherInterface $accountSwitcher
   *   Switches to the --user account for the tree read (used by the trait).
   */
  public function __construct(
    #[Autowire(service: 'daedalus_blueprint_library.shipper')]
    protected BlueprintShipper $shipper,
    #[Autowire(service: 'daedalus_blueprint_library.manager')]
    protected BlueprintManager $manager,
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
      ->addArgument('blueprint', InputArgument::REQUIRED, 'The saved blueprint to ship: its machine name (the file id) or uuid.')
      ->addArgument('module', InputArgument::REQUIRED, 'The enabled module to ship into; the files land at its root.')
      ->addOption('label', NULL, InputOption::VALUE_REQUIRED, 'The shipped label. Defaults to the blueprint\'s.')
      ->addOption('description', NULL, InputOption::VALUE_REQUIRED, 'The shipped description. Defaults to the blueprint\'s.')
      ->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Print the report and the file as it would be written, without writing files, adopting variants, or deleting anything.')
      ->addOption('user', NULL, InputOption::VALUE_REQUIRED, 'User ID to read the document as; inline block fields are access-filtered for this user. Drush runs as anonymous by default, which sees almost nothing.', '1')
      ->addUsage('call_to_action mymodule --dry-run')
      ->addUsage('call_to_action mymodule --label=\'Call to Action\' -y');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    // SymfonyStyle, not DrushStyle: drush -y makes the input non-interactive,
    // under which confirm() answers its default (yes), and the command
    // then runs where Drush's input service is absent (a command tester).
    $io = new SymfonyStyle($input, $output);
    $reference = (string) $input->getArgument('blueprint');
    $module = (string) $input->getArgument('module');
    $uid = (int) $input->getOption('user');

    $options = [];
    foreach (['label', 'description'] as $option) {
      if (is_string($input->getOption($option))) {
        $options[$option] = $input->getOption($option);
      }
    }

    try {
      $blueprint = $this->manager->resolveUpdatable($reference);
      $plan = $this->runAsUser($uid, fn (): ShipPlan => $this->shipper->plan($blueprint, $module, $options));
      $this->report($io, $plan);
      if ($input->getOption('dry-run')) {
        $io->writeln('');
        $io->writeln(sprintf('%s as it would be written:', $this->shipper->filePath($plan->module, $plan->id)));
        $io->writeln($this->shipper->fileContents($plan));
        $io->comment('Dry run: nothing was written.');
        return self::SUCCESS;
      }
      if (!$io->confirm(sprintf('Ship the "%s" blueprint (%s) to %s and delete it from the site, its owned variants adopted in place?', $plan->blueprint->label(), $plan->blueprint->id(), $module))) {
        throw new UserAbortException();
      }
      $plan = $this->runAsUser($uid, fn (): ShipPlan => $this->shipper->ship($blueprint, $module, $options));
    }
    catch (MaterialGestureException $exception) {
      // The manager's and the shipper's refusals and daedalus_stylish's
      // variant refusals all carry blueprint errors: shape them for the
      // terminal and exit non-zero.
      $io->error($exception->getMessage());
      foreach ($exception->getErrors() as $error) {
        $output->writeln(sprintf('  %s [%s]: %s', $error->getPath(), $error->getCode(), $error->getMessage()));
        if ($error->getHint() !== '') {
          $output->writeln(sprintf('    Hint: %s', $error->getHint()));
        }
      }
      return self::FAILURE;
    }

    $io->success(sprintf('Shipped the "%s" blueprint to %s.', $plan->id, $this->shipper->filePath($module, $plan->id)));
    return self::SUCCESS;
  }

  /**
   * Prints the plan in the sync commands' line-per-item form.
   *
   * The dry run and the real run print the same lines: the blueprint
   * file's outcome, the icon's outcome (copied names the icon file), each
   * variant's outcome (owned ones name their blueprint), each skipped
   * variant with its reason, the graduating blueprint, and every warning.
   */
  protected function report(SymfonyStyle $io, ShipPlan $plan): void {
    $io->writeln(sprintf('%s: blueprint %s %s (%s)', $plan->module, $plan->id, BlueprintShipper::CREATED, $this->shipper->filePath($plan->module, $plan->id)));
    $icon_file = isset($plan->definition['icon']) ? sprintf(' (%s)', $this->shipper->iconFilePath($plan->module, $plan->definition['icon'])) : '';
    $io->writeln(sprintf('%s: icon %s %s%s', $plan->module, $plan->id, $plan->icon, $icon_file));
    foreach ($plan->variantOutcomes as $name => $outcome) {
      $owner = isset($plan->variants[$name][BlueprintShipper::VARIANT_OWNER_KEY]) ? sprintf(' (blueprint: %s)', $plan->variants[$name][BlueprintShipper::VARIANT_OWNER_KEY]) : '';
      $io->writeln(sprintf('%s: variant %s %s%s', $plan->module, $name, $outcome, $owner));
    }
    foreach ($plan->skipped as $name => $reason) {
      $io->writeln(sprintf('%s: variant %s skipped: %s', $plan->module, $name, $reason));
    }
    $io->writeln(sprintf('%s: content blueprint "%s" (%s) graduates: deleted after the ship, its owned variants adopted in place', $plan->module, $plan->blueprint->label(), $plan->blueprint->id()));
    foreach ($plan->warnings as $warning) {
      $io->writeln("<comment>Warning: {$warning}</comment>");
    }
  }

}
