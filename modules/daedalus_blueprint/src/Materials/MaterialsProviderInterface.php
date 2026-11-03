<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * A source of material records: one per module that owns a material kind.
 *
 * Registered with the daedalus_blueprint.materials_provider service tag and
 * collected by MaterialsRosterBuilder. A provider is the ONE place its
 * kinds' entities are read into records: it knows the entity, the
 * published rule, the sort order, and the spellings (the reserved
 * variable prefix, the attribute derivation); nothing downstream re-walks
 * the storage. daedalus_stylish provides tokens, variants, animations and
 * fonts; daedalus_blueprint_library provides blueprints.
 *
 * A provider is also the ONE implementation of its kinds' gestures: the
 * material gesture family (save_material / update_material /
 * remove_material on the op spine, the admin routes as adapters) dispatches
 * by kind to capture(), update() and remove() here, so adding a kind adds
 * no op, no verb and no route. The provider that teaches a kind's gesture
 * line (gestureLines()) is the provider that performs it.
 */
interface MaterialsProviderInterface {

  /**
   * The provider's records, in roster order.
   *
   * Published materials only (the roster IS the published set), each
   * kind in the order its consumers list it (weight-then-label for
   * variants, label for animations and fonts, skin order for tokens).
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   The records. Ids must be unique within a kind and spellings unique
   *   within a slot across the whole roster; the roster refuses
   *   duplicates loudly.
   */
  public function records(): array;

  /**
   * What the records derive from: the tags and contexts that bust them.
   *
   * Every consumer that caches a projection of the roster attaches the
   * roster's merged metadata, so a provider names the whole domain its
   * records read (entity list tags, the config that points at the active
   * set, the declarations the spellings derive from).
   */
  public function cacheMetadata(): CacheableMetadata;

  /**
   * The keys save_material takes for each kind this provider owns.
   *
   * One clause per kind, taught on the cached prompt's MATERIALS mint line
   * after "save_material {kind, label, ...}:" (the provider is the one
   * place that knows its kind's keys). The op and the user's gate are not
   * the provider's to restate: the prompt names save_material once and
   * frames every clause with "only when the user asks". A kind with no
   * gesture is absent, and a kind taught here is a kind capture() /
   * update() / remove() perform, whatever the site's posture over the
   * AI's mints (mintPosture()) says: the dispatch is by kind and the
   * gate is judged by origin inside the lane.
   *
   * @return array<string, string>
   *   Kind value => the sentence.
   */
  public function gestureLines(): array;

  /**
   * The site's posture over the AI's mints of this provider's kinds.
   *
   * NULL while every gesture taught here is open to the AI; otherwise one
   * sentence saying which of this provider's lanes the site has closed
   * to the AI and what to offer instead. A closed lane refuses inside
   * capture() by origin; this is the advertisement that precedes the
   * refusal, stated once here and taught beside the gesture lines on the
   * cached prompt's MINT line (a mint can be asked on any turn, so the
   * posture rides the always-on layer) and on the JSON door's materials
   * guidance (the provider's own schema hook). A provider that gates no
   * lane answers NULL. The posture is a fact of the provider's config, so
   * cacheMetadata() names the config it reads.
   *
   * @return string|null
   *   The sentence, or NULL while the lanes are open.
   */
  public function mintPosture(): ?string;

  /**
   * Mints a material of a kind this provider owns (save_material).
   *
   * The provider owns its lane end to end: the permission grade the
   * gesture needs, which keys the gesture takes for the kind (taught on
   * gestureLines()), capture from the host's staged state when the gesture
   * names an element, validation through the kind's value seam, and the
   * origin stamp. The write reaches every client through the kind's entity
   * hook on the MaterialsChangeSet, never through the result.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialKind $kind
   *   A kind this provider teaches a gesture for.
   * @param array $operation
   *   The gesture's keys (label, value, element, ...), the op payload
   *   without op/kind. An `element` is already a uuid: the op handler
   *   resolved any page-language alias.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The host whose staged state a capture reads (the batch's live host
   *   on the op spine), or NULL on a lane with no page behind it.
   * @param array $options
   *   The request options (MaterialGestureOperationHandler::ORIGIN_OPTION
   *   names the writer's origin; absent means a person).
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  public function capture(MaterialKind $kind, array $operation, ?HostInterface $host, array $options): MaterialGestureResult;

  /**
   * Rewrites a saved material in place (update_material).
   *
   * Which keys may change is the kind's: a value, a label, a description,
   * or a fresh capture from an element pushed into the material. At least
   * one must be present; the provider refuses an empty update loudly.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialKind $kind
   *   A kind this provider teaches a gesture for.
   * @param string $id
   *   The material's roster id.
   * @param array $operation
   *   The keys to change, the op payload without op/kind/id (an `element`
   *   already a uuid).
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The host a push captures from, or NULL.
   * @param array $options
   *   The request options.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  public function update(MaterialKind $kind, string $id, array $operation, ?HostInterface $host, array $options): MaterialGestureResult;

  /**
   * Deletes a material (remove_material).
   *
   * A material nothing references goes in this request. A referenced one
   * needs its references rewritten first; a caller that can drive a Batch
   * API job says so with MaterialGestureOperationHandler::BATCH_OPTION and
   * receives the staged count on the result, every other caller is refused
   * with the reference count so the owner can decide.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialKind $kind
   *   A kind this provider teaches a gesture for.
   * @param string $id
   *   The material's roster id.
   * @param array $options
   *   The request options.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  public function remove(MaterialKind $kind, string $id, array $options): MaterialGestureResult;

  /**
   * Drops whatever the provider memoizes per request about its records' inputs.
   *
   * Called by MaterialsRosterBuilder::reset() after the builder drops its
   * own rosters, so the roster and every memo its records derive from are
   * one derived value forgotten together: a law or material change
   * mid-request resets the roster once and no provider keeps answering
   * from the pre-change world. A provider with no per-request memo does
   * nothing. Persisted caches are not the provider's to touch here; they
   * ride the tags cacheMetadata() names.
   */
  public function reset(): void;

}
