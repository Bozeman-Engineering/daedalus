<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the design skill: the one page skill the suite ships.
 *
 * The design skill is the ONE copy of the page design doctrine, shaped in three
 * parts: a thinking step (subject, audience, the one job, the one thing to
 * remember, one direction) with the two postures; distinctiveness inside the
 * system (on brand is a boundary with full liberty inside it, blueprints are
 * blocks or inspiration, never anchors) with the composition principles
 * (hierarchy, rhythm, contrast, one signature); and the anti-patterns (the
 * generic page). Then the generation workflow (read, skills, plan, compose and
 * style in one motion, iterate) and the few recipes that need a worked example
 * (centering). It carries NO concept menu and NO section-move list: a static
 * recipe is a silhouette every page would share regardless of brand, so the
 * shape of a page is decided per page. It is page-builder-generic on
 * purpose: the design system brief carries the system's
 * identity, axes, looks and blueprints, the Page language section carries every
 * write mechanic, and the schema carries the block catalog, so the doctrine
 * must name zero bundle block types, zero materials (no variant id the suite
 * ships), restate no grammar and say nothing about type or color (the system's
 * business). This test pins discovery through the real SkillsResolver glob, the
 * three parts and the workflow anchors, the absence of the retired menu and
 * move list, the diet (bundle vocabulary, the retired config-styling
 * vocabulary, the suite's shipped variant ids, the styling skill that dissolved
 * into the cached surface), and the create-entity seam: the skill declares
 * itself a blocking step of the create_entity workflow via `blocking_step_of`,
 * and the composed create_entity checklist must actually carry that step
 * without daedalus_blueprint naming the skill.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DesignSkillTest extends KernelTestBase {

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
    'daedalus_stylish_layouts',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The composed create_entity checklist lists the bundles with their
    // page mark (BundleListBuilder asks PageHosts::isPage()), which builds
    // the dialect catalog and with it the roster; the daedalus_stylish
    // provider reads all four material storages.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_variant');
  }

  /**
   * Tests discovery, composition, and the skill's load-bearing content.
   */
  public function testDesignSkillDiscoveryAndContent(): void {
    $resolver = $this->container->get('daedalus_blueprint.skills_resolver');

    // Discovered by filename suffix from daedalus_stylish's own directory; the
    // styling skill is gone (its mechanics are cached), so the page has
    // one skill.
    $definition = $resolver->getSkill('design');
    $this->assertIsArray($definition, 'The design skill is discovered.');
    $this->assertNull($resolver->getSkill('styling'), 'The styling skill dissolved into the cached surface.');
    $this->assertSame('Design', $definition['label']);
    $this->assertSame('default', $definition['type']);
    // The description is the catalog entry that makes the LLM invoke the
    // skill: it triggers on every page act (design, build, restyle,
    // rework) and advertises the re-fetch role.
    $this->assertStringContainsString('TRIGGER', $definition['description']);
    $this->assertStringContainsString('design, build, restyle or rework a page', $definition['description']);
    $this->assertStringContainsString('re-invoke', $definition['description']);

    // The default skill type returns the instructions verbatim; compose it
    // exactly as the eb_skill tool does.
    $definition['id'] = 'design';
    $type = $this->container->get('daedalus_blueprint.skill_type_manager')
      ->getType($definition['type']);
    $instructions = $type->compose($definition, []);
    $this->assertSame($definition['instructions'], $instructions);
    // Whitespace-normalized copy for needles YAML wrapping may split
    // across lines.
    $flat = preg_replace('/\s+/', ' ', $instructions);

    // The routing paragraph: facts live in the brief, the Page language
    // section and the schema, and the doctrine deliberately names no
    // block types, no materials and no section recipe.
    $this->assertStringContainsString('design system brief', $instructions);
    $this->assertStringContainsString('Page language section', $instructions);
    $this->assertStringContainsString('eb_read_schema', $instructions);
    $this->assertStringContainsString('materials', $instructions);
    $this->assertStringContainsString('no block types and no materials on purpose', $flat);
    $this->assertStringContainsString('carries no section recipe', $flat);

    // The generation workflow, in order: read, skills, plan, compose and
    // style in one motion, iterate. No render step: this is contrib, and
    // doctrine must not assume a headless browser exists.
    $this->assertStringContainsString('The generation workflow', $instructions);
    foreach (['READ.', 'SKILLS.', 'PLAN.', 'COMPOSE AND STYLE IN ONE MOTION.', 'ITERATE.'] as $i => $step) {
      $this->assertStringContainsString(($i + 1) . '. ' . $step, $instructions);
    }
    $this->assertStringNotContainsString('render_page', $instructions);
    // Workflow facts: the SKILLS step names the skins skill alone (the
    // styling skill is gone; an edit turn never needs a skill for
    // grammar), one page_create document for new pages, one atomic
    // page_edit batch for additions, and subject grounding in the plan.
    $this->assertStringContainsString('skins skill', $instructions);
    $this->assertStringNotContainsString('styling skill', $instructions);
    $this->assertStringNotContainsString('eb_skill styling', $instructions);
    $this->assertStringContainsString('ONE page_create document', $instructions);
    $this->assertStringContainsString('ONE atomic page_edit batch', $instructions);
    $this->assertStringContainsString("the page's single job", $flat);
    // The PLAN step is the thinking step applied, and it names the three
    // ways a blueprint can serve a page (filled, built beside, placed as
    // is) without preferring any: a blueprint is a material, not the
    // mechanism.
    $this->assertStringContainsString('Think, then commit (above)', $instructions);
    $this->assertStringContainsString('which blueprints filled, built beside, or placed as is', $flat);
    // The centering recipe moved here from the styling skill: the
    // container arranges its contents, so the write lands on it. A
    // container is born a flex column, so the recipe is the flex one and
    // no display is ever written on a container.
    // The children rule: one selector on the container, never the look
    // copied onto each child (the grammar itself is the cached STYLES
    // line's; the skill carries the doctrine).
    $this->assertStringContainsString("Style a container's children with a selector on the container", $flat);
    $this->assertStringContainsString('never by copying the look onto each child', $flat);
    // The relations narrow the set and a position fact rides the last
    // compound as a pseudo-class; the skill names no block type (the
    // grammar and the site's types are the cached STYLES line's).
    $this->assertStringContainsString('A relation narrows the set further (`+`, `~`, `:has()`, `:not()`', $flat);
    $this->assertStringContainsString('a position fact (`:not(:last-child)`) is a pseudo-class on the last compound, after the qualifiers', $flat);
    $this->assertStringNotContainsString('pb-', $instructions, 'The skill names no block type.');
    $this->assertStringContainsString('never write a display on a container', $flat);
    $this->assertStringContainsString('#hero { align-items: center }', $instructions);
    $this->assertStringContainsString('justify-content: center', $instructions);
    $this->assertStringContainsString('margin-top: auto', $instructions);
    $this->assertStringContainsString('align-items: center', $instructions);

    // Part one, the thinking step: five questions (subject, audience, the
    // one job, the one thing to remember, one direction) and a commitment
    // to one direction; the current page is never a model for a new one.
    $this->assertStringContainsString('Think, then commit', $instructions);
    $this->assertStringContainsString('what is the subject', $flat);
    $this->assertStringContainsString('who arrives at this page', $flat);
    $this->assertStringContainsString('the ONE job the page must do', $flat);
    $this->assertStringContainsString('remember an hour later, the one thing', $flat);
    $this->assertStringContainsString('Commit to one direction', $instructions);
    $this->assertStringContainsString('not a model for a new one', $instructions);

    // The postures: on brand is the default and the posture is session
    // intent; on brand is a boundary (a legal arrangement of the
    // materials) with full creative liberty inside it, never a look to
    // copy; workshopping puts new styling on the table and carries one
    // risk, a signature element, the plan critique and the graduation
    // path back into the materials.
    $this->assertStringContainsString('The two postures', $instructions);
    $this->assertStringContainsString('Default to on brand', $instructions);
    $this->assertStringContainsString('validation governs what SAVES', $instructions);
    $this->assertStringContainsString('a boundary, not a look to copy', $flat);
    $this->assertStringContainsString('full creative liberty', $instructions);
    $this->assertStringContainsString('new styling is on the table', $flat);
    $this->assertStringContainsString('signature element', $instructions);
    $this->assertStringContainsString('ANY similar brief', $instructions);
    $this->assertStringContainsString('graduate it deliberately', $instructions);

    // Part two, distinctiveness inside the system: arrangement is the
    // difference between pages built from the same brushes; a material is
    // a choice, never a checklist; blueprints are pre-approved blocks and
    // worked examples, never anchors, and per page each is filled, built
    // beside or skipped, with hand-rolled work as on brand as a placement
    // (hand-rolling is never discouraged). Saving one is reactive only
    // (the user asks, the AI never volunteers it); a saved blueprint is a
    // page of its own, edited with the page tools as entity_type blueprint
    // when the user means the blueprint, not a placement. Promotion prose
    // for variants, tokens and the skin stays. Placement mechanics are the
    // cached blueprint sentence's, so the doctrine spells no <pb-blueprint>
    // grammar; the retired place_blueprint op and the retired schema
    // key are never named (the retired word is the recipe's
    // PromptSurfaceSnapshotTest's to ban, on every tier).
    $this->assertStringContainsString('Distinctive inside the system', $instructions);
    $this->assertStringContainsString('The system is not a template', $instructions);
    $this->assertStringContainsString('a material is a choice, never a checklist', $flat);
    $this->assertStringContainsString('never anchors', $instructions);
    $this->assertStringContainsString('fill it with this page\'s content when it fits, build beside it in the same spirit, or skip it for a better idea', $flat);
    $this->assertStringContainsString('as on brand as a placement', $flat);
    $this->assertStringNotContainsString('<pb-blueprint', $instructions);
    $this->assertStringContainsString('only when the user asks', $instructions);
    $this->assertStringNotContainsString('propose saving', $instructions);
    $this->assertStringContainsString('save_material', $instructions);
    $this->assertStringContainsString('propose promoting', $instructions);
    $this->assertStringNotContainsString('place_blueprint', $instructions);
    $this->assertStringContainsString('A saved blueprint is a page of its own', $instructions);
    $this->assertStringContainsString('edit it with the page tools as entity_type blueprint', $flat);

    // The composition principles: hierarchy, rhythm, contrast, one
    // signature, copy and images as material, one purpose per section.
    // Principles only: no move is named and no move list exists.
    $this->assertStringContainsString('## Composition', $instructions);
    $this->assertStringContainsString('Principles, not moves', $instructions);
    foreach (['Hierarchy:', 'Rhythm:', 'Contrast:', 'One signature:'] as $principle) {
      $this->assertStringContainsString('- ' . $principle, $instructions);
    }
    $this->assertStringContainsString('never skipping levels', $instructions);
    $this->assertStringContainsString('Negative space is a material', $instructions);
    $this->assertStringContainsString('could only exist for this page', $instructions);
    $this->assertStringContainsString('never "Lorem ipsum"', $instructions);
    $this->assertStringContainsString('never a placeholder', $instructions);
    $this->assertStringContainsString('One purpose per section', $instructions);

    // Part three, the anti-patterns: the generic page is named so it can
    // be revised out of a plan (the hero/grid/CTA silhouette, equal
    // cards, every band the same shape, filler copy, a blueprint with the
    // copy swapped, even emphasis), and a match is a formula to redo.
    $this->assertStringContainsString('The generic page', $instructions);
    $this->assertStringContainsString('the silhouette every generated page shares', $flat);
    $this->assertStringContainsString('Cards of the same size for things of different importance', $flat);
    $this->assertStringContainsString('Every band the same shape', $flat);
    $this->assertStringContainsString('A blueprint with the sample copy swapped', $flat);
    $this->assertStringContainsString('Emphasis spread evenly', $instructions);
    $this->assertStringContainsString('a formula, not a decision', $instructions);

    // The retired doctrine: no concept menu and no section-move list, in
    // any spelling. The concept names, the move names as chapter items
    // and the menu's chapter headings must never return: a static recipe
    // is what makes every page share one silhouette.
    foreach ([
      'concept',
      'Concept',
      'Showcase',
      'Spotlight',
      'Narrative',
      'Editorial',
      'section moves',
      'Build the arc',
      'Leans on:',
      '- Hero:',
      '- Grid:',
      '- Close:',
    ] as $retired) {
      $this->assertStringNotContainsString($retired, $instructions, "Doctrine must not carry the retired recipe: {$retired}");
    }
    // The move list's one grammar fact (past four children a columns
    // container splits) moved to the columns variant's own description,
    // where a material fact belongs; the doctrine restates none of it.
    $this->assertStringNotContainsString('5+', $instructions);
    $this->assertStringNotContainsString('child count', $instructions);
    // Motion restraint: one signature entrance per page where the brief
    // offers animations, nothing looping beside reading, and reduced
    // motion is the worn stop's business (the setter unsets itself), so
    // the doctrine never trades motion away for it. The mechanics (which
    // attribute, the trigger pair) are the cached surface's.
    $this->assertStringContainsString('Motion is seasoning', $instructions);
    $this->assertStringContainsString('one signature move an entrance', $instructions);
    $this->assertStringContainsString('never animate every section', $instructions);
    $this->assertStringContainsString("worn stop's own business", $instructions);
    $this->assertStringNotContainsString('animation="', $instructions);
    $this->assertStringNotContainsString('animation-name', $instructions);
    $this->assertStringNotContainsString('timeline-trigger', $instructions);
    // Type and color are the design system's (the identity, the skin,
    // the tokens): the doctrine says nothing about sizes, weights, fonts
    // or colors, and the type rule is the brief's dressed-tags line and
    // the cached grammar (clamp() unsupported), never restated here.
    $this->assertStringNotContainsString('clamp()', $instructions);
    $this->assertStringNotContainsString('type-scale token', $instructions);
    $this->assertStringNotContainsString('narrow-band rule', $instructions);
    foreach (['font-size', 'font-weight', 'font-family', 'decisively larger', 'color:', 'palette', 'contrast ratio'] as $styling) {
      $this->assertStringNotContainsString($styling, $instructions, "Doctrine must not carry type or color guidance: {$styling}");
    }

    // The material diet: no variant id the suite ships (daedalus_stylish's block
    // entrances and hover, daedalus_stylish_layouts' widths, columns and
    // entrances) appears as a word in the doctrine, and no wear spelling
    // is restated (the ELEMENTS lines teach those).
    $importer = $this->container->get('daedalus_stylish.variant_importer');
    $ids = [];
    foreach (['daedalus_stylish', 'daedalus_stylish_layouts'] as $module) {
      $ids = [...$ids, ...array_keys($importer->getShippedDefinitions($module))];
    }
    $this->assertContains('columns', $ids, 'The fixture reads the suite\'s shipped variants.');
    $this->assertContains('row', $ids, 'The Row arrangement ships: the doctrine may say Row and Columns, never the lowercase ids.');
    $this->assertContains('block_fade_in', $ids);
    foreach ($ids as $id) {
      $this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($id, '/') . '\b/', $instructions, "Doctrine must not name the material {$id}.");
    }
    foreach (['layout="', 'width="', 'class="', 'hover="'] as $spelling) {
      $this->assertStringNotContainsString($spelling, $instructions, "Doctrine must not restate the wear spelling {$spelling}.");
    }

    // The bundle-vocabulary diet: zero bundle block type machine names
    // (the shipped catalogs' types and the retired ones alike), no bundle
    // field names, and none of the retired config-styling vocabulary
    // (title_size scales, layout column plugins, view modes).
    foreach ([
      'call_to_action',
      'basic block',
      'basic_block',
      'teaser block',
      'heading block',
      'image block',
      'text block',
      'formatted_text',
      'field_heading',
      'title_size',
      'text-jumbo',
      'text-large',
      'card_left',
      'card_right',
      'view mode',
      'single-column',
      'multi-column',
      'daedalus_stylish_onecol',
      'daedalus_stylish_twocol',
      'navigation_layout',
    ] as $banned) {
      $this->assertStringNotContainsString($banned, $instructions, "Doctrine must not carry bundle vocabulary: {$banned}");
    }
  }

  /**
   * Tests the skill inserts itself into the create_entity workflow.
   *
   * The seam is declarative: the design skill's `blocking_step_of` entry
   * must surface in the composed create_entity checklist, rendered by
   * daedalus_blueprint's CreateEntitySkillType without that module naming
   * the design skill anywhere (its skill description names no skill
   * either: the checklist is the one place the page's skills are named).
   */
  public function testDesignSkillIsBlockingCreateEntityStep(): void {
    $resolver = $this->container->get('daedalus_blueprint.skills_resolver');

    $design = $resolver->getSkill('design');
    $this->assertIsArray($design['blocking_step_of'] ?? NULL);
    $step = $design['blocking_step_of']['create_entity'];
    $this->assertStringContainsString('eb_skill design', $step);

    $create = $resolver->getSkill('create_entity');
    $this->assertIsArray($create, 'The create_entity skill is discovered.');
    $this->assertStringNotContainsString('design', $create['description'], 'create_entity names no skill: the composed checklist does.');
    $this->assertStringNotContainsString('styling', $create['description']);
    $create['id'] = 'create_entity';
    $type = $this->container->get('daedalus_blueprint.skill_type_manager')
      ->getType($create['type']);
    $output = $type->compose($create, []);

    $this->assertStringContainsString('BLOCKING REQUIREMENT', $output);
    $this->assertStringContainsString($step, $output);
    // The declared step lands between the schema read and the plan's
    // approval; the checklist itself carries no concept step any more
    // (the doctrine is the declaring skill's alone).
    $this->assertGreaterThan(
      strpos($output, 'eb_read_schema'),
      strpos($output, 'eb_skill design'),
    );
    $this->assertLessThan(
      strpos($output, 'Present your plan'),
      strpos($output, 'eb_skill design'),
    );
    $this->assertStringNotContainsString('concept', $output);
  }

}
