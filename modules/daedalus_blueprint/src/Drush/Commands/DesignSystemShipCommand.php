<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Drush\Commands;

use Drupal\daedalus_blueprint\DesignSystem\DesignSystemShip;
use Drupal\daedalus_blueprint\DesignSystem\ShipReport;
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
 * Ships a design system to its package module: the CLI adapter over DesignSystemShip.
 *
 * `daedalus_stylish:sync MODULE` brings a package onto the site; this
 * command runs the other direction for a whole design system: the unit
 * as install config, then every package lane (the system's content
 * blueprints graduate into blueprint files with their variants; the
 * module's per-id material files are rebuilt from the site and the
 * system's unshipped fonts, animations, swatches, skins and variants
 * adopted).
 * A module that does not exist is created under --dir and installed
 * first. One line per entry in the sync command's grammar
 * (`MODULE: KIND NAME outcome`), a hand-authored entry the site edited
 * told to be edited by hand, every note a lane made, then every failure
 * with its path, code, message and hint, and a non-zero exit when
 * anything was refused; everything that could ship already did.
 * `--dry-run` prints the identical lines from the lanes' plans and
 * writes nothing.
 *
 * `daedalus-blueprint:blueprint-ship` stays beside this command: it ships
 * ONE saved blueprint, whatever its membership.
 */
#[AsCommand(
  name: 'daedalus-blueprint:design-system:ship',
  description: 'Ships a design system to its package module (created when absent): the unit as install config, the system\'s content blueprints as blueprint files, and the site\'s materials as per-id files.',
  aliases: ['dd-blueprint-ship'],
)]
final class DesignSystemShipCommand extends Command {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: DesignSystemShip::SERVICE_ID)]
    protected DesignSystemShip $ship,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addArgument('system', InputArgument::REQUIRED, 'The design system id (daedalus_blueprint.design_system.ID).')
      ->addArgument('module', InputArgument::OPTIONAL, 'The module to ship into. Defaults to the system\'s package; required, and created when it does not exist, for a system with none.')
      ->addOption('dir', NULL, InputOption::VALUE_REQUIRED, 'Where a created module lands, relative to the Drupal root.', DesignSystemShip::DEFAULT_DIRECTORY)
      ->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Report what the ship would do; write no file, touch no entity.')
      ->addUsage('aurora')
      ->addUsage('aurora --dry-run')
      ->addUsage('my_system my_brand --dir=modules/custom -y');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    // SymfonyStyle, not DrushStyle: drush -y makes the input non-interactive,
    // under which confirm() answers its default (yes), and the command
    // then runs where Drush's input service is absent (a command tester).
    $io = new SymfonyStyle($input, $output);
    $system = (string) $input->getArgument('system');
    $module = $input->getArgument('module');
    $module = is_string($module) && $module !== '' ? $module : NULL;
    try {
      $plan = $this->ship->plan($system, $module);
    }
    catch (\InvalidArgumentException $e) {
      // A usage error (no such system, no module to ship into): the
      // terminal is the recovery, not a stack trace.
      $io->error($e->getMessage());
      return self::FAILURE;
    }
    if ($input->getOption('dry-run')) {
      $this->print($plan, $output);
      $io->comment('Dry run: nothing was written.');
      return $plan->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
    $prepared = $plan->outcome(DesignSystemShip::KIND_MODULE, $plan->module());
    if ($prepared !== NULL) {
      $verb = $prepared === DesignSystemShip::CREATED ? sprintf('created under %s and installed', $input->getOption('dir')) : 'installed';
      if (!$io->confirm(sprintf('The "%s" module is %s, then the "%s" design system ships into it (its content blueprints graduate: deleted on the site after their files are written). Continue?', $plan->module(), $verb, $system))) {
        throw new UserAbortException();
      }
    }
    elseif (($graduating = $this->graduating($plan)) !== [] && !$io->confirm(sprintf('%d content blueprint(s) graduate into %s (%s): each is deleted on the site after its file is written, its owned variants adopted in place. Continue?', count($graduating), $plan->module(), implode(', ', $graduating)))) {
      throw new UserAbortException();
    }
    $report = $this->ship->ship($system, $module, (string) $input->getOption('dir'));
    $this->print($report, $output);
    return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
  }

  /**
   * The blueprints a plan graduates: every blueprint line that is not a refusal.
   *
   * The blueprint lane's only package gesture is graduation, so a
   * blueprint outcome is a content blueprint that leaves the site. The
   * kind is the lane's report kind (BlueprintShipper::KIND), spelled here
   * because the lane lives in the optional library module.
   *
   * @return string[]
   *   The blueprint ids.
   */
  protected function graduating(ShipReport $plan): array {
    $ids = [];
    foreach ($plan->outcomes()['blueprint'] ?? [] as $id => $outcome) {
      if ($outcome !== ShipReport::FAILED) {
        $ids[] = (string) $id;
      }
    }
    return $ids;
  }

  /**
   * Prints the report: outcomes, notes, then failures.
   *
   * One line per entry, `MODULE: KIND NAME outcome`; a hand-authored
   * entry the site edited names its file, since the outcome is an
   * instruction (edit it by hand), not a state of disk. Failures print
   * last, in the report's one grammar.
   */
  protected function print(ShipReport $report, OutputInterface $output): void {
    $module = $report->module();
    foreach ($report->outcomes() as $kind => $outcomes) {
      foreach ($outcomes as $name => $outcome) {
        if ($outcome === ShipReport::FAILED) {
          continue;
        }
        $output->writeln(sprintf('%s: %s %s %s', $module, $kind, $name, $outcome === ShipReport::MODIFIED
          ? sprintf('modified; edit by hand in %s', $report->file($kind, (string) $name))
          : $outcome));
      }
    }
    foreach ($report->notes() as $note) {
      $output->writeln('<comment>' . $note . '</comment>');
    }
    foreach ($report->failureLines() as $line) {
      $output->writeln('<error>' . $line . '</error>');
    }
  }

}
