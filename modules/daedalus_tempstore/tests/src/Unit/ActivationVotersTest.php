<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_tempstore\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_tempstore\ParamConverter\ActivationVote;
use Drupal\daedalus_tempstore\ParamConverter\ActivationVoters;
use Drupal\daedalus_tempstore\ParamConverter\TempstoreActivationVoterInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the order-independent composition of activation votes.
 *
 * The composition rules are the reason voters replaced the old decoration
 * chain: any Deny wins over any Allow regardless of registration order, and
 * Neutral is distinct from Deny so "no opinion" can never swallow a veto.
 *
 * @group daedalus_tempstore
 */
#[Group('daedalus_tempstore')]
final class ActivationVotersTest extends UnitTestCase {

  /**
   * Creates a fixed-vote voter that records swap notifications.
   */
  private function voter(ActivationVote $vote): TempstoreActivationVoterInterface {
    return new class ($vote) implements TempstoreActivationVoterInterface {

      /**
       * The entities this voter was told about, in swap order.
       *
       * @var \Drupal\Core\Entity\EntityInterface[]
       */
      public array $swapped = [];

      public function __construct(private readonly ActivationVote $fixedVote) {}

      /**
       * {@inheritdoc}
       */
      public function vote(): ActivationVote {
        return $this->fixedVote;
      }

      /**
       * {@inheritdoc}
       */
      public function onEntitySwapped(EntityInterface $entity): void {
        $this->swapped[] = $entity;
      }

    };
  }

  /**
   * Builds a collector from voters.
   */
  private function voters(TempstoreActivationVoterInterface ...$voters): ActivationVoters {
    $collector = new ActivationVoters();
    foreach ($voters as $voter) {
      $collector->addVoter($voter);
    }
    return $collector;
  }

  /**
   * No voters means inactive: daedalus_tempstore stays side-effect free alone.
   */
  public function testNoVotersIsInactive(): void {
    $this->assertFalse($this->voters()->isActive());
  }

  /**
   * Only neutral votes means inactive: nobody asked for swapping.
   */
  public function testNeutralOnlyIsInactive(): void {
    $collector = $this->voters(
      $this->voter(ActivationVote::Neutral),
      $this->voter(ActivationVote::Neutral),
    );
    $this->assertFalse($collector->isActive());
  }

  /**
   * A single allow activates; neutral peers do not interfere.
   */
  public function testAllowActivates(): void {
    $this->assertTrue($this->voters($this->voter(ActivationVote::Allow))->isActive());
    $collector = $this->voters(
      $this->voter(ActivationVote::Neutral),
      $this->voter(ActivationVote::Allow),
    );
    $this->assertTrue($collector->isActive());
  }

  /**
   * Deny beats allow in either registration order.
   */
  public function testDenyWinsRegardlessOfOrder(): void {
    $collector = $this->voters(
      $this->voter(ActivationVote::Allow),
      $this->voter(ActivationVote::Deny),
    );
    $this->assertFalse($collector->isActive());

    $collector = $this->voters(
      $this->voter(ActivationVote::Deny),
      $this->voter(ActivationVote::Allow),
    );
    $this->assertFalse($collector->isActive());
  }

  /**
   * A lone deny (nothing to veto) still leaves swapping inactive.
   */
  public function testDenyOnlyIsInactive(): void {
    $this->assertFalse($this->voters($this->voter(ActivationVote::Deny))->isActive());
  }

  /**
   * Every voter is notified of a swap, regardless of how it voted.
   */
  public function testSwapNotifiesAllVoters(): void {
    $allow = $this->voter(ActivationVote::Allow);
    $neutral = $this->voter(ActivationVote::Neutral);
    $collector = $this->voters($allow, $neutral);

    $entity = $this->createMock(EntityInterface::class);
    $collector->onEntitySwapped($entity);

    $this->assertSame([$entity], $allow->swapped);
    $this->assertSame([$entity], $neutral->swapped);
  }

}
