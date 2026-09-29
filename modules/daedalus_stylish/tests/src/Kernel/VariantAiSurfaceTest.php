<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the variant AI surface: the materials fragment's variants roster.
 *
 * The published variants land inside every style_guide a schema carries,
 * as the materials fragment's variants group (core's MaterialsSchema),
 * keyed by the machine name the apply_variant operation speaks, each with
 * its scope, its axis when not the built-in one, its applicability when
 * the axis is root-only in its scope (serialized onto the entry, no
 * axis-vocabulary join), and its description, the when-to-reach-for-this
 * payload the guidance paragraph's "reuse a saved variant first" lean
 * depends on. This pins the roster's shape, the published-only rule, the
 * alter context's invalidation tag, and the materials-first placement.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantAiSurfaceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_guide_test',
    'daedalus_stylish_control_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_variant');
    // The materials hook queries the swatch and skin storages on the same
    // alter.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * Runs the daedalus_blueprint_schema alter over a style-guide-bearing schema.
   *
   * @return array
   *   [the altered schema, the alter context].
   */
  protected function alteredSchema(): array {
    $schema = [
      'fields' => [
        'name' => ['type' => 'string'],
        'daedalus_stylish_styles' => ['type' => 'daedalus_stylish_styles', 'style_guide' => ['guidance' => 'x']],
      ],
    ];
    $context = ['entity_type' => 'entity_test', 'bundle' => 'entity_test', 'view_mode' => 'full', 'cache_tags' => []];
    $this->container->get('module_handler')->alter('daedalus_blueprint_schema', $schema, $context);
    return [$schema, $context];
  }

  /**
   * The variants roster lands with the published roster, descriptions in.
   */
  public function testVariantsRoster(): void {
    // A root-only axis on the container kind: the depth fact must ride
    // the variant's own entry as an applies line, not an axis-vocabulary
    // join the roster no longer carries.
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['variant_axes' => [['width' => ['depth' => 'root']]]],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();

    DaedalusStylishVariant::create([
      'label' => 'Wide hero',
      'machine_name' => 'wide_hero',
      'scope' => 'kind:container',
      'description' => 'A full-bleed opener for landing pages; reach for it on the first section.',
      'styles' => ['' => ['default' => ['padding' => '4rem']]],
    ])->save();
    DaedalusStylishVariant::create([
      'label' => 'Emphatic testimonial',
      'machine_name' => 'testimonial_emphatic',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
      'styles' => ['' => ['default' => ['font-size' => '1.5rem']]],
    ])->save();
    DaedalusStylishVariant::create([
      'label' => 'Full width',
      'machine_name' => 'container_full',
      'scope' => 'kind:container',
      'axis' => 'width',
      'styles' => ['' => ['default' => ['max-width' => 'none']]],
    ])->save();
    $draft = DaedalusStylishVariant::create([
      'label' => 'Unreleased look',
      'machine_name' => 'unreleased_look',
      'scope' => 'kind:container',
      'styles' => ['' => ['default' => ['padding' => '1rem']]],
    ]);
    $draft->setUnpublished();
    $draft->save();

    [$schema, $context] = $this->alteredSchema();
    $materials = $schema['materials'] ?? NULL;
    $this->assertIsArray($materials, 'The materials fragment landed on the schema.');

    // The reference grammar rides the description, door-neutral: the page
    // language's wear spellings, never a JSON op (the creation recipe is
    // the brief's MINT line, not the schema's). The owned_by sentence is
    // stencil vocabulary and rides only while the roster has stencils;
    // this test runs without that module, so the guard must keep it out
    // (owned_by entries could not appear either: the ownership fields
    // live on the stencil module).
    $this->assertStringContainsString('class="name"', $materials['guidance']);
    $this->assertStringNotContainsString('apply_variant', $materials['guidance']);
    $this->assertStringContainsString('machine name', $materials['guidance']);
    $this->assertStringNotContainsString('owned_by', $materials['guidance']);
    $this->assertStringNotContainsString('give it a description', $materials['guidance']);

    // Entries key by machine name; the spelling is how the page language
    // wears the variant (a style variant as a class, a declared axis as its
    // attribute on the element's tag: the container's width, a block
    // type's emphasis), the description is the payload, the axis appears
    // only when it is not the built-in style axis, and keys with nothing to
    // say are omitted rather than emitted empty.
    $roster = $materials['variants'];
    $this->assertSame([
      'label' => 'Wide hero',
      'spelling' => 'class="wide_hero"',
      'scope' => 'kind:container',
      'description' => 'A full-bleed opener for landing pages; reach for it on the first section.',
    ], $roster['wide_hero']);
    $this->assertSame([
      'label' => 'Emphatic testimonial',
      'spelling' => 'emphasis="emphatic"',
      'scope' => 'block_type:testimonial',
      'axis' => 'emphasis',
    ], $roster['testimonial_emphatic']);

    // The applicability join is resolved server-side: a variant on its
    // scope's root-only axis says so on its own entry.
    $this->assertSame('width', $roster['container_full']['axis']);
    $this->assertStringStartsWith('width="', $roster['container_full']['spelling']);
    $this->assertStringContainsString('top-level sections only', $roster['container_full']['applies']);
    $this->assertArrayNotHasKey('applies', $roster['wide_hero']);

    // Published variants only: a draft is not applicable vocabulary.
    $this->assertArrayNotHasKey('unreleased_look', $roster);

    // The published-variant invalidation domain rides the alter context.
    $this->assertContains('daedalus_variant_list', $context['cache_tags']);

    // The materials are the schema's own key; the field's style guide is
    // untouched.
    $this->assertSame(['guidance'], array_keys($schema['fields']['daedalus_stylish_styles']['style_guide']));

    // Non-styling fields are untouched.
    $this->assertArrayNotHasKey('style_guide', $schema['fields']['name']);
  }

  /**
   * No variants means NO variants group: empty is absent, grows-by-data.
   *
   * The fragment still lands and its guidance says an absent kind has no
   * materials on this site, which keeps the AI from inventing machine
   * names as well as an empty map would.
   */
  public function testEmptyRoster(): void {
    [$schema] = $this->alteredSchema();
    $materials = $schema['materials'] ?? NULL;
    $this->assertIsArray($materials, 'The fragment exists with no saved variants.');
    $this->assertArrayNotHasKey('variants', $materials);
    $this->assertStringContainsString('a kind absent here has no materials on this site', $materials['guidance']);
  }


  /**
   * The op guidance and the JSON door teach wear at a rule.
   *
   * The three variant ops say an element key or a rule key and name the
   * two refusals (a composition, a root-only axis); the style map field's
   * guidance says which variants a rule may wear (its landing's).
   */
  public function testRuleWearGuidance(): void {
    $guidance = $this->container->get('daedalus_stylish.operation_handler')->getGuidance();
    foreach (['update_style', 'apply_variant', 'remove_variant', 'detach_variant'] as $op) {
      $this->assertStringContainsString('an element or rule key', $guidance[$op], $op);
    }
    $this->assertStringContainsString('a rule wears at its matches, never a composition or root-only axis', $guidance['apply_variant']);
    $this->assertStringContainsString("a rule's parts continue under it", $guidance['detach_variant']);

    $door = $this->container->get('daedalus_stylish.field_handler.style_map')->getGuidance();
    $this->assertStringContainsString('a RULE entry ("{key}::{selector}") wears a variant too', $door);
    $this->assertStringContainsString('::> pb-text wears text variants, ::a link variants', $door);
    $this->assertStringContainsString('a rule never wears a composition variant or a root-only axis', $door);
  }

}
