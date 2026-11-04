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
 * remember, one direction) with the creative license (the brand is carried
 * by the brief, the skin and the law, so spend nothing on restraint; invent
 * in structure, values from what the brief names); the composition
 * principles (hierarchy, rhythm,
 * contrast, one signature); and the template test (the habit every generated
 * page shares, the engine behind it on this surface, and the check the model
 * runs on its plan before presenting it: swap the subject, and what still
 * fits was never decided). Then
 * the generation workflow (read, skills, plan, compose and style in one
 * motion, iterate) and the few recipes that need a worked example
 * (centering). It carries NO concept menu and NO section-move list: a static
 * recipe is a silhouette every page would share regardless of brand, so the
 * shape of a page is decided per page. It is page-builder-generic on
 * purpose: the design system brief carries the system's
 * identity, axes, looks and blueprints, the Page language section carries every
 * write mechanic, and the schema carries the block catalog, so the doctrine
 * must name zero bundle block types, zero materials (no variant id the suite
 * ships), restate no grammar and say nothing about type or color (the system's
 * business). It restates no workflow rule either: the loop (a matching skill
 * before anything else, plan then wait for the go-ahead, one atomic batch
 * with structure and styling together, targeted edits, save discipline) is
 * the cached Workflow section's alone, so the PLAN step ends at presenting
 * the plan and the COMPOSE step carries craft only. It is also the skill a
 * new page needs: its trigger names creating a new page in its first words
 * (a cold create matches it from the catalog line alone), and `reframe:
 * true` with the not-a-model sentence carry the reframing a new page needs
 * after a run of small edits. This test pins discovery through the real
 * SkillsResolver glob, the three parts and the workflow anchors, the new-page
 * trigger and the reframe pair, the absence of the loop's sentences and of
 * the retired checklist seam (`blocking_step_of`), the absence of the retired
 * menu, move list and posture doctrine (the on brand / workshopping lever
 * that the brief's anchoring made redundant), and the diet (bundle
 * vocabulary, the retired config-styling vocabulary, the suite's shipped
 * variant ids, the styling skill that dissolved into the cached surface).
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
    'filter',
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
    // skill: its first words name creating a new page (this is the skill
    // a new page needs, and a cold create must match it from the catalog
    // line alone), then every other page act (design, build, restyle,
    // rework), and it advertises the re-fetch role.
    $this->assertStringStartsWith('TRIGGER when: about to create a new page', $definition['description']);
    $this->assertStringContainsString('design, build, restyle or rework a page', $definition['description']);
    $this->assertStringContainsString('re-invoke', $definition['description']);
    // The description advertises the creative stance in the place the two
    // postures used to sit.
    $this->assertStringContainsString('the creative stance', $definition['description']);
    // The reframe pair: invoking the skill closes the working transcript's
    // segment, and the not-a-model sentence (asserted below) says what the
    // page in front of the model is not. Together they carry the reframing
    // a new page needs after a run of small edits.
    $this->assertTrue($definition['reframe'], 'The design skill reframes the work.');
    // The checklist seam is gone with the create-an-entity checklist: the
    // skill declares no blocking step of any workflow.
    $this->assertArrayNotHasKey('blocking_step_of', $definition);

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

    // The routing paragraph: the thesis, then the precedence clause naming
    // the three sources (the brief, the Page language section, the
    // schema) and nothing more: no table of contents for sections the
    // model already holds, and no commentary on what the doctrine omits.
    $this->assertStringContainsString('This skill is process and taste', $flat);
    $this->assertStringContainsString('The facts live elsewhere and win on conflict', $flat);
    $this->assertStringContainsString('design system brief', $instructions);
    $this->assertStringContainsString('Page language section', $flat);
    $this->assertStringContainsString('eb_read_schema', $instructions);
    $this->assertStringNotContainsString('no block types and no materials on purpose', $flat);
    $this->assertStringNotContainsString('carries no section recipe', $flat);
    // The model is never told it has seen the whole library: the schema
    // prints the blueprints shared with it, under a group index
    // (MaterialsSchema), so no sentence claims every blueprint.
    $this->assertStringNotContainsString('every blueprint', $flat);

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
    // grammar), and subject grounding in the plan.
    $this->assertStringContainsString('skins skill', $instructions);
    $this->assertStringNotContainsString('styling skill', $instructions);
    $this->assertStringNotContainsString('eb_skill styling', $instructions);
    $this->assertStringContainsString("the page's single job", $flat);
    // The loop's rules live in the cached Workflow section alone: the
    // skill restates none of them (one batch, one document, never bare
    // structure to style later, never rebuilds, the waiting). The PLAN
    // step ends at presenting the plan; whether the build then waits for
    // the go-ahead is the Workflow's rule, not this skill's.
    foreach ([
      'ONE atomic',
      'ONE page_create document',
      'bare structure',
      'never rebuilds',
      'and wait',
      'wait for approval',
      'BLOCKING REQUIREMENT',
    ] as $loop) {
      $this->assertStringNotContainsString($loop, $instructions, "The skill must not restate the loop: {$loop}");
    }
    $this->assertStringContainsString('Then present this plan.', $flat);
    // The PLAN step is the thinking step applied: each planned section is
    // declared a placement (which blueprint) or new, and the plan runs
    // the template test (below) before it is presented, unconditionally,
    // no longer gated on a posture. The test lives in its own section;
    // the step points at it rather than restating it.
    $this->assertStringContainsString('Think, then commit (above)', $instructions);
    $this->assertStringContainsString('for each section say whether it is a placement (which blueprint) or new', $flat);
    $this->assertStringContainsString('Run the template test (above)', $flat);
    $this->assertStringNotContainsString('Critique the plan', $instructions);
    $this->assertStringNotContainsString('ANY similar brief', $instructions);
    // The stamp sentence rides the COMPOSE step: the matrix of
    // 2026-10-24 (set prune2-20261024-144900 against the 2026-10-22
    // baseline) showed Sonnet and Haiku planning placements by name and
    // writing them without blueprint="id" once the skill stopped saying
    // it; the Page language's cached sentence alone did not carry the
    // act. One sentence, at the step where the write happens (Tim,
    // 2026-10-24).
    $this->assertStringContainsString('A section the plan called a placement is written as that blueprint\'s printed fragment', $flat);
    $this->assertStringContainsString('blueprint="id" on its root', $flat);
    $this->assertStringContainsString('the stamp is what makes it a placement', $flat);
    // The centering recipe moved here from the styling skill: the
    // container arranges its contents, so the write lands on it. A
    // container is born a flex column, so the recipe is the flex one.
    // The display rule is about arrangement alone: display and
    // flex-direction are never written to arrange a container's children
    // (the layout axis does that), so the roster may keep display for
    // its other uses and the doctrine no longer bans it outright.
    // The children rule: one selector on the container, never the look
    // copied onto each child (the grammar itself is the cached STYLES
    // line's; the skill carries the doctrine).
    $this->assertStringContainsString("Style a container's children with a selector on the container", $flat);
    $this->assertStringContainsString('never by copying the look onto each child', $flat);
    // The relations narrow the set and a position pseudo-class qualifies
    // its compound like them (selector text, never a state); the skill
    // names no block type (the grammar and the site's types are the
    // cached STYLES line's).
    $this->assertStringContainsString('A relation narrows the set further (`+`, `~`, `:has()`, `:not()`', $flat);
    $this->assertStringContainsString('a position pseudo-class (`:first-child`, `:not(:last-child)`, `:nth-child(2n+1)`) qualifies the compound it stands on, like `:has()` and `:not()`: it is selector text, never a state', $flat);
    $this->assertStringNotContainsString('position fact', $flat);
    // The WHERE is CSS's grammar: a class and an attribute qualify their
    // compound, a state before the last compound qualifies it (the
    // reveal patterns) and on the last compound is the condition, a
    // selector list is one block per member read back as one `:is()`
    // rule; admission is the site's.
    $this->assertStringContainsString('So do a class (`#section.open > *` the children while the section wears a class, `#section > *.active` a child wearing one) and an attribute (`#section [aria-expanded="true"] > *`, `#section > *[aria-current="page"]`): the selector grammar is CSS\'s, and the site decides what it admits', $flat);
    $this->assertStringContainsString('A state qualifies too when it stands on a compound before the last (`#section:hover > *` the children while the section is hovered, `#section > *:hover a` the links inside a hovered child: the reveal patterns), and on the last compound it is the rule\'s condition (`#section > *:hover`)', $flat);
    $this->assertStringContainsString('A selector list is one block for every member (`#section h2, #section h3 { ... }`), read back as the one rule it is, `#section *:is(h2, h3)`: alternatives at one compound are `:is()` (`#section > *:is(p, h2)` either kind of child, `#section:is(:hover, :focus-within) > *` the children while the section is hovered or holds focus), and a list whose members differ at more than one compound is separate rules.', $flat);
    $this->assertStringNotContainsString('goes on the last compound', $flat);
    $this->assertDoesNotMatchRegularExpression('/\bd-[a-z]/', $instructions, 'The skill names no block type.');
    $this->assertStringContainsString("Never write `display` or `flex-direction` to arrange a container's children; the layout axis does that", $flat);
    $this->assertStringNotContainsString('display upgrade', $instructions);
    $this->assertStringNotContainsString('never write a display on a container', $flat);
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

    // The creative license, kept concise: the brand is carried by the brief,
    // the skin and the law, so the doctrine spends nothing on restraint; a
    // material is a shortcut, never a limit; invention goes into structure,
    // arrangement and selection, and the values are the brief's (the Law line
    // in the brief says per site what a value may be; the skill does not
    // restate it). The rest of the old creative-stance and distinctiveness
    // sections were cut on 2026-10-24: the materials ladder and the two-outcome
    // placement rule are the brief's and the Page language's, and the
    // open-vs-strict pair showed the skill's restatements doing the composing.
    // Their retired spellings stay guarded.
    $this->assertStringContainsString('## Spend it on the page', $instructions);
    $this->assertStringContainsString('spend nothing on restraint and everything on the page', $flat);
    $this->assertStringContainsString('A material is a shortcut, never a limit', $flat);
    $this->assertStringContainsString('build it without hesitation from containers, text, images and styles', $flat);
    $this->assertStringContainsString("the values are the brief's", $flat);
    $this->assertStringNotContainsString('strict site', $instructions, 'The law is the brief\'s to state, per site.');
    foreach ([
      '## Be creative inside the system',
      'Distinctive inside the system',
      'ENVISION what the section',
      'There is no third thing between the two',
      'Materials first',
      'write raw values last',
      'validation governs what SAVES',
      '<d-blueprint',
      'place_blueprint',
      'never volunteer',
      'propose saving',
    ] as $retired) {
      $this->assertStringNotContainsString($retired, $flat, "Retired doctrine must not return: {$retired}");
    }

    // The retired posture doctrine, in any spelling and in any case, in
    // the whole file (description and comment included): the on brand /
    // workshopping lever, the "never anchors" and "build beside"
    // blueprint outcomes, the default and the one-risk cap. The brief,
    // the skin and the law carry the brand on every turn, so a posture
    // has nothing left to switch. The retired checklist seam goes with
    // them: the create-an-entity checklist is gone, so no skill declares a
    // blocking step of it.
    $whole = file_get_contents($this->container->get('module_handler')->getModule('daedalus_stylish')->getPath() . '/daedalus_stylish.daedalus_blueprint.skills.design.yml');
    $this->assertStringNotContainsString('blocking_step_of', $whole);
    foreach ([
      'posture',
      'on brand',
      'on-brand',
      'workshopping',
      'never anchors',
      'build beside',
      'build with or beside',
      'beside',
      'Default to on brand',
      'one real aesthetic risk',
    ] as $retired) {
      $this->assertStringNotContainsStringIgnoringCase($retired, $whole, "The skill file must not carry the retired posture doctrine: {$retired}");
    }

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

    // Part three, the template test: a check the model runs on its plan,
    // not a list of shapes to avoid (a list is answered at the letter;
    // the open-vs-strict pair both "avoided cards" with the same
    // frameless three-up). It names the habit, the engine specific to
    // this surface (the brief's jobs answered one blueprint per job from the
    // blueprint menu), the test (swap the subject; what still fits was
    // never decided), and that avoiding a shape by reflex is as unchosen
    // as reaching for it. The old heading and its silhouette list are gone.
    $this->assertStringContainsString('## The template test', $instructions);
    $this->assertStringContainsString('the menu laid out in order, not a page designed', $flat);
    $this->assertStringContainsString('A placement is right where a blueprint\'s job is this page\'s job', $flat);
    // A page's top-level container is a "section" everywhere (the UI chip,
    // the element, the blueprint type; Tim's ruling of 2026-10-23); the
    // doctrine never calls it a band.
    $this->assertDoesNotMatchRegularExpression('/\bbands?\b/i', $instructions, 'The doctrine says section, never band.');
    $this->assertStringContainsString('swap in a different subject and keep whatever still fits', $flat);
    $this->assertStringContainsString('was never a decision about this page', $flat);
    $this->assertStringContainsString('avoids cards by reflex is as unchosen as one that reaches for them', $flat);
    $this->assertStringNotContainsString('The generic page', $instructions);
    $this->assertStringNotContainsString('a formula, not a decision', $instructions);

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

    // The material diet: no variant id the suite ships (daedalus_stylish
    // itself ships none; daedalus_stylish_layouts' widths, columns and row)
    // appears as a word in the doctrine, and no wear spelling is restated
    // (the ELEMENTS lines teach those).
    $importer = $this->container->get('daedalus_stylish.variant_importer');
    $ids = array_keys($importer->getShippedDefinitions('daedalus_stylish_layouts'));
    $this->assertContains('columns', $ids, 'The fixture reads the suite\'s shipped variants.');
    $this->assertContains('row', $ids, 'The Row arrangement ships: the doctrine may say Row and Columns, never the lowercase ids.');
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

}
