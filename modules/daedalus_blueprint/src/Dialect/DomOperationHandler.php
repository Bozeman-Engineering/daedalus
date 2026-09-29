<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\ValueKind;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;

/**
 * The DOM-vocabulary ops: the dialect's write grammar on the op spine.
 *
 * Six ops, the vocabulary every model already knows (spec v0.2 section 8):
 * insert(html, at), replace(ref, html), remove(ref), move(ref, at),
 * set(ref, attrs), style(css, remove), and a seventh beside them,
 * file(name, type, content): bytes the author wrote (an SVG, a
 * stylesheet, rows), declared in the batch that places them so a page
 * with its own icons is still one gesture. JSON is the envelope, HTML/CSS
 * the payload. Each op TRANSLATES into the batch operations that already exist
 * on the spine (add_section/add_element/update_element/move_element/
 * remove_element and the registered style ops) and executes them as a
 * nested batch (the stencil handler's precedent) so everything the spine
 * already enforces (placement, field validation, style-guide validation)
 * applies with no second validation path, and batch rollback semantics
 * hold.
 *
 * Identity: `ref` is a printed uuid or a live alias. Author-chosen ids on
 * inserted elements become aliases over minted uuids (DialectAliasMap,
 * per entity per conversation via $options['conversation']); results echo
 * the pairings and the printer echoes `data-alias`.
 *
 * The `style` op's CSS rides DialectCssParser (the same instance grammar
 * the document parser uses: published selector grammar and wrappers)
 * and lands as update_style operations, so the one styling validation
 * engine judges every value.
 *
 * The site vocabulary rides in as $options['dialect_catalog'] (a
 * DialectCatalog), the per-call contract; the tool layer threads the
 * generated catalog.
 *
 * A file declaration (the `file` op, a document's <pb-file>) is saved
 * through the FileDeclarationHandlerInterface seam (collected by service
 * tag; daedalus_ai implements it over the file lane and the attachment
 * store) and recorded under its name in the alias layer's files namespace
 * for $options['conversation'], on the overlay the batch settles: later
 * ops in the same batch place it with file="name" or url('file:name'), a
 * rolled-back batch leaves no name behind. A refused body is a dialect
 * error naming the declaration.
 */
class DomOperationHandler implements PageLanguageOperationsInterface {

  /**
   * The DOM insertion positions (insertAdjacentHTML's vocabulary).
   */
  public const POSITIONS = ['beforebegin', 'afterbegin', 'beforeend', 'afterend'];

  /**
   * The DOM op names this handler provides.
   */
  public const OPS = ['insert', 'replace', 'remove', 'move', 'set', 'style', 'file'];

  /**
   * The at.ref spelling for the page itself.
   */
  public const ROOT_REF = 'root';

  /**
   * The seam that saves declared bytes, or NULL on a site without one.
   */
  protected ?FileDeclarationHandlerInterface $fileDeclarations = NULL;

  /**
   * Constructs the handler.
   *
   * @param \Drupal\daedalus_blueprint\Dialect\DialectParser $parser
   *   The dialect parser (op payload fragments).
   * @param \Drupal\daedalus_blueprint\Dialect\DialectAliasMap $aliasMap
   *   The alias layer over minted identity.
   * @param \Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder $treeBuilder
   *   The lean node tree builder (the entity's current shape).
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator (minting for aliased identity).
   * @param \Closure $operations
   *   Service closure resolving daedalus_blueprint.operations. A closure
   *   because the dependency is circular by construction: operations owns
   *   the handler registry that collects this handler.
   */
  public function __construct(
    protected DialectParser $parser,
    protected DialectAliasMap $aliasMap,
    protected NodeTreeBuilder $treeBuilder,
    protected UuidInterface $uuid,
    protected \Closure $operations,
  ) {}

  /**
   * Takes the seam that saves declared bytes (the service collector's call).
   */
  public function setFileDeclarationHandler(FileDeclarationHandlerInterface $handler): void {
    $this->fileDeclarations = $handler;
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations(): array {
    return self::OPS;
  }

  /**
   * {@inheritdoc}
   */
  public function getPageLanguageOperations(): array {
    return self::OPS;
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): array {
    // Terse router lines on purpose: the page-language op set is taught in
    // full in the cached catalog (the Page language prompt section), and
    // these ops' home surface is page_edit; the roll-call only needs to
    // route.
    return [
      'insert' => 'with "html" dialect fragment and "at" {ref, position}; a page-language op, page_edit\'s surface',
      'replace' => 'with "ref" and "html"; a page-language op',
      'remove' => 'with "ref"; a page-language op',
      'move' => 'with "ref" and "at" {ref, position}; a page-language op',
      'set' => 'with "ref" and "attrs"; a page-language op',
      'style' => 'with "css" and/or "remove"; a page-language op',
      'file' => 'with "name", "type" (MIME) and "content"; declares bytes the conversation then holds under name, ahead of the ops that place it; a page-language op',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(FieldableEntityInterface $entity, array $operation, array $options = []): BlueprintResult {
    $catalog = $options['dialect_catalog'] ?? NULL;
    if (!$catalog instanceof DialectCatalog) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('op', 'DIALECT_CATALOG_MISSING', 'The DOM ops need the site vocabulary (a DialectCatalog in the request options) to parse their payloads.', [], 'This is a caller wiring gap, not a batch problem: the tool layer passes the generated catalog as options[dialect_catalog].'),
      ]);
    }
    $conversation = (string) ($options['conversation'] ?? '');
    $tree = $this->treeBuilder->build($entity);
    $index = $this->elementIndex($tree);
    $aliases = $this->aliasMap->aliases($entity, $conversation);

    return match ($operation['op']) {
      'insert' => $this->applyInsert($entity, $operation, $options, $catalog, $conversation, $index, $aliases),
      'replace' => $this->applyReplace($entity, $operation, $options, $catalog, $conversation, $index, $aliases),
      'remove' => $this->applyRemove($entity, $operation, $options, $conversation, $index, $aliases),
      'move' => $this->applyMove($entity, $operation, $options, $conversation, $index, $aliases),
      'set' => $this->applySet($entity, $operation, $options, $catalog, $conversation, $index, $aliases),
      'style' => $this->applyStyle($entity, $operation, $options, $catalog, $conversation, $index, $aliases),
      'file' => $this->applyFile($entity, $operation, $conversation),
    };
  }

  /**
   * Applies a file op: the declared bytes saved and named for the batch.
   *
   * Not a page mutation: the entity is untouched and the result carries
   * no target. The op's whole effect is the file the conversation now
   * holds under name, which the ops after it place.
   */
  protected function applyFile(FieldableEntityInterface $entity, array $operation, string $conversation): BlueprintResult {
    $errors = [];
    foreach (['name', 'type', 'content'] as $key) {
      $value = $operation[$key] ?? NULL;
      if (!is_string($value) || trim($value) === '') {
        $errors[] = new BlueprintError($key, 'MISSING_PARAMETER', sprintf('file needs "%s": %s.', $key, match ($key) {
          'name' => 'the filename (with its extension) the file is held under',
          'type' => 'the MIME type (image/svg+xml, text/css, text/csv, text/plain, text/markdown, application/json, text/html)',
          'content' => 'the file\'s bytes',
        }), [], 'A file op is {op: "file", name: "logo.svg", type: "image/svg+xml", content: "<svg ...>...</svg>"}, placed ahead of the ops that use file="logo.svg" or url(\'file:logo.svg\').');
      }
    }
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }
    $name = trim($operation['name']);
    if (str_contains($name, '/') || str_contains($name, '\\')) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('name', 'MISSING_PARAMETER', sprintf('file "name" is a bare filename, not a path: "%s".', $name), ['name' => $name], 'Write name: "logo.svg"; the conversation holds the file by that name.'),
      ]);
    }
    $error = $this->declareFile($conversation, $name, trim($operation['type']), trim($operation['content']), 'content');
    return $error === NULL ? BlueprintResult::success($entity) : BlueprintResult::structuralFailure([$error]);
  }

  /**
   * Declares one file through the seam, on the batch's overlay.
   *
   * The two authoring forms (the file op, a document's <pb-file>) share
   * this one call and one error shape: a wiring gap (no seam on this
   * site), a write without a conversation (nothing could hold the file),
   * and the seam's refusal (the lane's reasons) each name the declaration
   * by the name the author gave it.
   *
   * @param string $conversation
   *   The conversation the file is held for ('' when the caller has none).
   * @param string $name
   *   The declared name.
   * @param string $type
   *   The declared MIME type.
   * @param string $content
   *   The bytes.
   * @param string $path
   *   The payload path the error answers on ('content' for the op,
   *   'layout' for a document).
   * @param int|null $line
   *   The declaration's source line in a document (joins the message, the
   *   one fact a payload path cannot carry), NULL for an op.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError|null
   *   The error that refused the declaration, or NULL when it was saved.
   */
  protected function declareFile(string $conversation, string $name, string $type, string $content, string $path, ?int $line = NULL): ?BlueprintError {
    $where = $line === NULL ? '' : sprintf(' (line %d)', $line);
    if ($this->fileDeclarations === NULL) {
      return new BlueprintError($path, 'FILE_STORE_MISSING', sprintf('The file "%s" cannot be declared: this site holds no files for a conversation.%s', $name, $where), ['name' => $name], 'This is a site wiring gap, not a batch problem: no daedalus_blueprint.file_declaration_handler service is registered (daedalus_ai provides one).');
    }
    if ($conversation === '') {
      return new BlueprintError($path, 'CONVERSATION_MISSING', sprintf('The file "%s" cannot be declared: no conversation rides this write to hold it.%s', $name, $where), ['name' => $name], 'The page tools carry the conversation; the HTTP op endpoint takes thread_id in the request body.');
    }
    try {
      $this->fileDeclarations->declare($conversation, $name, $type, $content);
    }
    catch (FileDeclarationException $e) {
      return new BlueprintError(
        $path,
        'FILE_REJECTED',
        sprintf('The file "%s" was refused: %s%s', $name, $e->getMessage(), $where),
        ['name' => $name, 'reasons' => $e->messages],
        'Fix the declaration and resend the whole batch: the name\'s extension must be one the site accepts (svg, css, csv, txt, md, json, html), the type must agree with it, and the bytes must pass the site\'s file validation.',
      );
    }
    return NULL;
  }

  /**
   * Applies an insert op: parse the fragment, expand, run the batch.
   */
  protected function applyInsert(FieldableEntityInterface $entity, array $operation, array $options, DialectCatalog $catalog, string $conversation, array $index, array $aliases): BlueprintResult {
    $at = $this->requireAt($operation, $entity, $index, $aliases, $errors);
    $html = $operation['html'] ?? NULL;
    if (!is_string($html) || trim($html) === '') {
      $errors[] = new BlueprintError('html', 'MISSING_PARAMETER', 'insert needs "html": a dialect fragment.', [], 'Write the elements to insert in the dialect (<pb-container>, <pb-text ...>, ...).');
    }
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }
    [$anchor, $position] = $at;

    $root_anchor = $anchor === $entity->uuid();
    if ($root_anchor && in_array($position, ['beforebegin', 'afterend'], TRUE)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('at.position', 'BAD_OP', 'The page has no outside: root takes afterbegin or beforeend.', [], 'Use beforeend to append to the page, afterbegin to prepend, or anchor an element for beforebegin/afterend.'),
      ]);
    }
    $top_level = $root_anchor || (!empty($index[$anchor]['top_level']) && in_array($position, [
      'beforebegin',
      'afterend',
    ], TRUE));

    $parse = $this->parser->parseFragment($html, $catalog, $top_level);
    if (!$parse->isValid()) {
      return BlueprintResult::structuralFailure($this->toBlueprintErrors($parse->errors, 'html'));
    }
    if ($root_anchor) {
      foreach ($parse->tree as $node) {
        if (!in_array($node['kind'] ?? '', ['container', 'stencil'], TRUE)) {
          return BlueprintResult::structuralFailure([
            new BlueprintError('html', 'ILLEGAL_CONTAINMENT', 'The top level of a page holds <pb-container> elements; wrap the fragment in one.', [], 'Blocks live inside containers; insert into a container, or wrap the blocks in <pb-container>.'),
          ]);
        }
      }
    }

    $first_location = $this->firstLocation($entity, $anchor, $position, $index);
    $ops = [];
    $style_ops = [];
    $minted = [];
    $errors = [];
    $this->expandNodes($parse->tree, $first_location, $catalog, $ops, $style_ops, $minted, $errors);
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }

    return $this->runTranslated($entity, array_merge($ops, $style_ops), $options, $conversation, $minted);
  }

  /**
   * Applies a replace op: insert the fragment beside, remove the target.
   */
  protected function applyReplace(FieldableEntityInterface $entity, array $operation, array $options, DialectCatalog $catalog, string $conversation, array $index, array $aliases): BlueprintResult {
    $errors = [];
    $target = $this->requireRef($operation, $entity, $index, $aliases, $errors);
    $html = $operation['html'] ?? NULL;
    if (!is_string($html) || trim($html) === '') {
      $errors[] = new BlueprintError('html', 'MISSING_PARAMETER', 'replace needs "html": the replacement dialect fragment.', [], 'To delete an element use the remove op; to edit it in place prefer set.');
    }
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }

    $top_level = !empty($index[$target]['top_level']);
    $parse = $this->parser->parseFragment($html, $catalog, $top_level);
    if (!$parse->isValid()) {
      return BlueprintResult::structuralFailure($this->toBlueprintErrors($parse->errors, 'html'));
    }

    $ops = [];
    $style_ops = [];
    $minted = [];
    $errors = [];
    $this->expandNodes($parse->tree, ['anchor' => $target, 'placement' => 'before'], $catalog, $ops, $style_ops, $minted, $errors);
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }
    $ops[] = ['op' => 'remove_element', 'element' => $target];

    $result = $this->runTranslated($entity, array_merge($ops, $style_ops), $options, $conversation, $minted, forget: [$target]);
    return $result;
  }

  /**
   * Applies a remove op.
   */
  protected function applyRemove(FieldableEntityInterface $entity, array $operation, array $options, string $conversation, array $index, array $aliases): BlueprintResult {
    $errors = [];
    $target = $this->requireRef($operation, $entity, $index, $aliases, $errors);
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }
    return $this->runTranslated($entity, [['op' => 'remove_element', 'element' => $target]], $options, $conversation, [], forget: [$target]);
  }

  /**
   * Applies a move op.
   */
  protected function applyMove(FieldableEntityInterface $entity, array $operation, array $options, string $conversation, array $index, array $aliases): BlueprintResult {
    $errors = [];
    $target = $this->requireRef($operation, $entity, $index, $aliases, $errors);
    $at = $this->requireAt($operation, $entity, $index, $aliases, $errors);
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }
    [$anchor, $position] = $at;
    if ($anchor === $entity->uuid() && in_array($position, ['beforebegin', 'afterend'], TRUE)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('at.position', 'BAD_OP', 'The page has no outside: root takes afterbegin or beforeend.', [], 'Use beforeend to move to the end of the page, afterbegin for the start.'),
      ]);
    }
    $location = $this->firstLocation($entity, $anchor, $position, $index);
    return $this->runTranslated($entity, [['op' => 'move_element', 'element' => $target, 'location' => $location]], $options, $conversation, []);
  }

  /**
   * Applies a set op: patch fields, the element's variant axes, class, style.
   */
  protected function applySet(FieldableEntityInterface $entity, array $operation, array $options, DialectCatalog $catalog, string $conversation, array $index, array $aliases): BlueprintResult {
    $errors = [];
    $target = $this->requireRef($operation, $entity, $index, $aliases, $errors);
    $attrs = $operation['attrs'] ?? NULL;
    $detach = $operation['detach'] ?? NULL;
    if ($attrs !== NULL && (!is_array($attrs) || array_is_list($attrs))) {
      $errors[] = new BlueprintError('attrs', 'BAD_OP', '"attrs" is a map of attribute names to values (null clears).', [], 'Attribute names are the element\'s dialect attributes plus class, style and body.');
      $attrs = NULL;
    }
    if ($detach !== NULL && (!is_array($detach) || !array_is_list($detach) || array_filter($detach, static fn ($item): bool => !is_string($item) || $item === '') !== [])) {
      $errors[] = new BlueprintError('detach', 'BAD_OP', '"detach" is a list of worn variants: class names, or an axis attribute name for the variant worn on that axis.', [], 'Detaching materializes the variant\'s styles onto the element and unlinks it, so the look stays but stops following the variant.');
      $detach = NULL;
    }
    if (($attrs === NULL || $attrs === []) && ($detach === NULL || $detach === [])) {
      $errors[] = new BlueprintError('attrs', 'MISSING_PARAMETER', 'set needs "attrs" (a map of attribute names to values; null clears) and/or "detach" (worn variants to materialize and unlink).', [], 'Attribute names are the element\'s dialect attributes plus class, style and body; on ref "root" they are the page\'s own fields.');
    }
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }
    $attrs ??= [];
    $detach ??= [];

    // The root: the page's own fields, JSON values under the field
    // handlers (what page_create's "fields" takes). No styles, no wear.
    if ($target === $entity->uuid()) {
      if ($detach !== [] || isset($attrs['class']) || isset($attrs['style'])) {
        return BlueprintResult::structuralFailure([
          new BlueprintError('attrs', 'ILLEGAL_ATTR', 'The page itself has no class, style, or variants; style its containers.', [], 'ref "root" takes the page\'s own fields (title, ...), the ones page_create\'s "fields" takes.'),
        ]);
      }
      return $this->runTranslated($entity, [['op' => 'update_element', 'element' => $target, 'properties' => $attrs]], $options, $conversation, []);
    }

    $info = $index[$target] ?? NULL;
    if ($info === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('ref', 'ELEMENT_NOT_FOUND', sprintf('No element %s on this page.', $target), [], 'Refs are the ids in the page print (uuids or data-alias values).'),
      ]);
    }

    $is_container = $info['kind'] === 'container';
    $block_type = $info['block_type'];
    $spec = $is_container ? NULL : ($block_type !== NULL ? $catalog->blockType($block_type) : NULL);
    // The tag the correctives name is the one the print uses: an undeclared
    // type and a plugin block are the generic <pb-block> form.
    $tag = $is_container ? 'pb-container' : ($spec !== NULL ? DialectCatalog::tagForBlockType($block_type) : 'pb-block');
    $key_prefix = $is_container ? 'container:' : 'block:';
    $css = new DialectCssParser($catalog);
    $own = $info['node']['styles'][''] ?? [];
    $roster = $catalog->classRosterFor($is_container, $block_type, $info['plugin_id']);
    $axes = $catalog->axesFor($is_container, $block_type, $info['plugin_id']);

    $properties = [];
    $ops = [];
    $dialect_errors = [];
    $this->expandDetach($detach, $catalog, $axes, $key_prefix . $target, $own, $ops, $errors);
    foreach ($attrs as $name => $value) {
      $name = (string) $name;
      if ($name === 'id' || str_starts_with($name, 'data-') || in_array($name, DialectParser::residueAttrs(), TRUE)) {
        $errors[] = new BlueprintError("attrs.$name", 'SERVER_DERIVED_ATTR', sprintf('%s is server-derived and never authored.', $name), [], 'Identity is minted; aliases come from ids on inserted elements.');
        continue;
      }
      if ($name === 'stencil') {
        $errors[] = new BlueprintError('attrs.stencil', 'READONLY_ATTR', 'stencil is placement provenance, fixed when the element is created; set cannot patch it.', [], 'Provenance rides the element in an insert/replace fragment (stencil="id" as printed); a fresh copy is <pb-stencil name="id" />.');
        continue;
      }
      if ($tag === 'pb-image' && $name === 'src') {
        $errors[] = new BlueprintError('attrs.src', 'ILLEGAL_SRC', 'src is not writable on <pb-image>.', [], 'Reference existing media with media="id or label" or mint one with generate="prompt" + alt.');
        continue;
      }
      if ($is_container && $name === DialectWalk::NAME_ATTR) {
        // The container's name is the section's label: the same
        // update_element {label} the tree's rename takes (null clears it,
        // so the section reads by its kind again).
        $properties['label'] = $value === NULL ? NULL : (string) $value;
        continue;
      }
      if ($name === 'style') {
        $patch = [];
        if ($value !== NULL) {
          $patch = $css->parseDeclarations((string) $value, NULL, 'attrs.style', $dialect_errors);
        }
        foreach (array_keys($own['default'] ?? []) as $current) {
          $patch[$current] ??= NULL;
        }
        if ($patch !== []) {
          $ops[] = ['op' => 'update_style', 'key' => $key_prefix . $target, 'properties' => $patch];
        }
        continue;
      }
      if ($name === 'class') {
        $class_errors = [];
        $this->expandClassPatch($value, $catalog, $tag, $roster, $key_prefix . $target, $own, $ops, $class_errors);
        array_push($errors, ...$this->toBlueprintErrors($class_errors, 'attrs.class'));
        continue;
      }
      if (isset($axes[$name])) {
        $axis_errors = [];
        $this->expandAxisPatch($name, $value, $catalog, $axes, $tag, $info, $key_prefix . $target, $own, $ops, $axis_errors);
        array_push($errors, ...$this->toBlueprintErrors($axis_errors, "attrs.$name"));
        continue;
      }
      $attr_spec = $spec['attributes'][$name] ?? NULL;
      if ($attr_spec !== NULL) {
        if ($value === NULL) {
          if (isset($attr_spec['key'])) {
            $existing = is_array($properties[$attr_spec['field']] ?? NULL) ? $properties[$attr_spec['field']] : [];
            $existing[$attr_spec['key']] = NULL;
            $properties[$attr_spec['field']] = $existing;
          }
          else {
            $properties[$attr_spec['field']] = NULL;
          }
          continue;
        }
        $value = (string) $value;
        if (isset($attr_spec['values']) && !in_array($value, $attr_spec['values'], TRUE)) {
          $errors[] = new BlueprintError("attrs.$name", 'BAD_ATTR_VALUE', sprintf('%s="%s" on <%s> is not an allowed value.', $name, $value, $tag), [
            'attribute' => $name,
            'value' => $value,
            'allowed' => $attr_spec['values'],
          ], sprintf('Allowed values: %s.', implode(', ', $attr_spec['values'])));
          continue;
        }
        DialectWalk::applyFieldAttr($properties, $attr_spec, $value);
        continue;
      }
      // The body is the one authored surface that is not an attribute; the
      // attrs map addresses it by the contract's slot name (body), the same
      // naming system as every other key, never by the raw field name.
      if (!$is_container && isset($spec['body']['field']) && $name === DialectCatalog::BODY_ATTR) {
        if (($spec['body']['kind'] ?? '') === 'rich' && $value !== NULL) {
          // An authored identity claimed by two mintable elements would
          // be silently re-minted at stage time, orphaning every style
          // this batch writes against the class: refuse it loudly.
          $body_html = is_array($value) ? (string) ($value['value'] ?? '') : (string) $value;
          foreach (DialectTextInstances::collisions($body_html, $catalog) as $collision) {
            $errors[] = new BlueprintError("attrs.$name", 'IDENTITY_COLLISION', sprintf('Class "%s" identifies more than one element in the body; an identity names ONE element.', $collision), [], 'Give each element its own class from the identity namespace, or drop the duplicate and let one be minted.');
          }
        }
        $properties[$spec['body']['field']] = $value;
        continue;
      }
      $legal = array_merge(
        ['class', 'style'],
        $is_container ? [DialectWalk::NAME_ATTR] : [],
        array_keys($axes),
        array_keys($spec['attributes'] ?? []),
        !$is_container && isset($spec['body']['field']) ? [DialectCatalog::BODY_ATTR] : [],
      );
      sort($legal);
      $errors[] = new BlueprintError("attrs.$name", 'ILLEGAL_ATTR', sprintf('%s is not an attribute of <%s>.', $name, $tag), [
        'attribute' => $name,
        'legal' => $legal,
      ], sprintf('Legal attribute names of <%s>: %s.', $tag, implode(', ', $legal)));
    }

    array_push($errors, ...$this->toBlueprintErrors($dialect_errors, 'attrs.style'));
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }
    if ($properties !== []) {
      array_unshift($ops, ['op' => 'update_element', 'element' => $target, 'properties' => $properties]);
    }
    if ($ops === []) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('attrs', 'MISSING_PARAMETER', 'The operation changes nothing.', [], 'Provide attribute values to set or null to clear.'),
      ]);
    }
    return $this->runTranslated($entity, $ops, $options, $conversation, []);
  }

  /**
   * Expands a set op's detach list into detach_variant ops.
   *
   * Each entry names a worn variant by its class, or by the axis attribute
   * of the element whose worn value should be materialized (detach:
   * ["width"] unlinks the container from its width variant; a block's own
   * axes detach the same way). Whether the variant is actually worn is the
   * spine's call (detach_variant refuses an absent reference), so the
   * dialect only spells the name.
   *
   * @param array $detach
   *   The detach list as written: class names or axis attribute names.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param array $axes
   *   The element's axis view (DialectCatalog::axesFor()).
   * @param string $key
   *   The target's style-map key (container: or block: plus its uuid).
   * @param array $own
   *   The target's own style-map entry: its worn variants and default styles.
   * @param array $ops
   *   Collector for the spine ops (by reference).
   * @param array $errors
   *   Collector for the errors found (by reference).
   */
  protected function expandDetach(array $detach, DialectCatalog $catalog, array $axes, string $key, array $own, array &$ops, array &$errors): void {
    foreach ($detach as $i => $name) {
      $class = $catalog->resolve($name, MaterialRecord::SLOT_CLASS);
      $variant = $class->kind === ValueKind::Variant ? $class->id : NULL;
      if ($variant === NULL && isset($axes[$name])) {
        foreach ($own['variants'] ?? [] as $reference) {
          $mapping = $catalog->axisForVariant($reference['variant'] ?? '');
          if ($mapping !== NULL && $mapping[0] === $name) {
            $variant = $reference['variant'];
          }
        }
        if ($variant === NULL) {
          $errors[] = new BlueprintError("detach.$i", 'BAD_OP', sprintf('The element wears no %s variant to detach.', $name), ['attribute' => $name], 'Detach names a worn class, or an axis attribute of the element whose value is worn.');
          continue;
        }
      }
      if ($variant === NULL) {
        $errors[] = new BlueprintError("detach.$i", 'UNKNOWN_CLASS', sprintf('"%s" is not a published style variant%s.', $name, $axes === [] ? '' : ' or an axis attribute of the element (' . implode(', ', array_keys($axes)) . ')'), [
          'reference' => $name,
          'worn' => array_column($own['variants'] ?? [], 'variant'),
        ], 'Detach names a worn variant by its class (the class="" spelling in the print).');
        continue;
      }
      $ops[] = ['op' => 'detach_variant', 'key' => $key, 'variant' => $variant];
    }
  }

  /**
   * Applies a style op: <style>-block CSS plus an explicit remove list.
   *
   * Rules land as update_style writes at the selector's key; a rule's
   * wear declarations (`class: x`, `{axis}: {value}`, the parser's
   * 'variants' assignment) land as apply_variant at the rule key, and a
   * remove entry naming "class" or an axis attribute of the rule's
   * landing takes that wear off (remove_variant per matching reference,
   * read from the rule's staged entry), the twin of the set op's class
   * restatement and {axis: null} on an element.
   */
  protected function applyStyle(FieldableEntityInterface $entity, array $operation, array $options, DialectCatalog $catalog, string $conversation, array $index, array $aliases): BlueprintResult {
    $css_text = $operation['css'] ?? NULL;
    $remove = $operation['remove'] ?? [];
    if (($css_text === NULL || $css_text === '') && ($remove === [] || $remove === NULL)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('css', 'MISSING_PARAMETER', 'style needs "css" (rules to set) and/or "remove" (overrides to delete).', [], 'Removal is not unset (a real CSS value): list {selector, properties: [names]} entries under remove.'),
      ]);
    }

    // Addressable ids: every element uuid, plus every live alias, carrying
    // the info the selector grammar checks (kind, block type for parts,
    // and the rich body content instance selectors existence-check
    // against), by the ONE derivation the document parser uses too
    // (DialectCssParser::knownId()). The tree is the entity's STAGED
    // state, so a body written by an earlier op in the same batch (its
    // authored or minted identities included) is what `#id .s-x`
    // validates against.
    $known_ids = [];
    foreach ($index as $uuid => $info) {
      $known_ids[$uuid] = DialectCssParser::knownId($info['node'], $catalog);
    }
    foreach ($aliases as $alias => $uuid) {
      if (isset($known_ids[$uuid])) {
        $known_ids[$alias] = $known_ids[$uuid];
      }
    }

    $css = new DialectCssParser($catalog);
    $dialect_errors = [];
    $assignments = [];
    if (is_string($css_text) && $css_text !== '') {
      $assignments = $css->parseStyleBlock($css_text, 1, $known_ids, $dialect_errors);
    }
    elseif ($css_text !== NULL && !is_string($css_text)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('css', 'BAD_OP', 'css is CSS text under the <style>-block grammar.', [], 'Selectors: #id, then a relative selector under it (#id .declared-part, #id p, #id > *, #id > pb-text, #id > pb-text .link) with states on the addressed element (#id:hover, #id > pb-text:hover) and its generated box last (#id::after, #id > pb-text:hover::before); conditions via the published wrappers, nesting accepted as sugar.'),
      ]);
    }

    $errors = [];
    if (!is_array($remove)) {
      $errors[] = new BlueprintError('remove', 'BAD_OP', 'remove is a list of {selector, properties: [property names]} entries.', [], 'Each entry deletes the named properties from the styles the selector addresses; at a rule, "class" or an axis name takes the rule\'s wear off.');
      $remove = [];
    }
    // A rule's wear removals: [path, id, local, states, names], resolved
    // against the rule's current entry once the id is known below.
    $wear_removals = [];
    foreach ($remove as $i => $entry) {
      if (!is_array($entry) || !isset($entry['selector']) || !isset($entry['properties']) || !is_array($entry['properties'])) {
        $errors[] = new BlueprintError("remove.$i", 'BAD_OP', 'remove entries are {selector, properties: [property names]}.', [], 'Name the selector whose styles to trim and the properties to delete; at a rule, "class" or an axis name takes the rule\'s wear off.');
        continue;
      }
      $target = $css->parseSelector((string) $entry['selector'], NULL, $known_ids, $dialect_errors);
      if ($target === NULL) {
        continue;
      }
      $landing = $css->ruleLanding($target['local'], $known_ids[$target['id']] ?? NULL);
      $properties = [];
      $wear = [];
      foreach ($entry['properties'] as $property) {
        $property = (string) $property;
        if ($property === 'class' || isset($landing['axes'][$property])) {
          $wear[] = $property;
          continue;
        }
        $properties[$property] = NULL;
      }
      if ($properties !== []) {
        $assignments[] = [$target['id'], $target['local'], $catalog->canonicalCompound($target['states']), $properties];
      }
      if ($wear !== []) {
        $wear_removals[] = ["remove.$i", $target['id'], $target['local'], $target['states'], $wear];
      }
    }

    array_push($errors, ...$this->toBlueprintErrors($dialect_errors, 'css'));
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }

    $resolve = function (string $id) use ($aliases, $index): ?string {
      $uuid = DialectParser::isUuid($id) ? $id : ($aliases[$id] ?? NULL);
      return $uuid !== NULL && isset($index[$uuid]) ? $uuid : NULL;
    };
    $not_found = static fn (string $id): BlueprintResult => BlueprintResult::structuralFailure([
      new BlueprintError('css', 'ELEMENT_NOT_FOUND', sprintf('No element #%s on this page.', $id), [], 'Refs are the ids in the page print (uuids or data-alias values).'),
    ]);

    $ops = [];
    foreach ($assignments as [$id, $local, $condition, $payload]) {
      if ($payload === []) {
        continue;
      }
      $uuid = $resolve($id);
      if ($uuid === NULL) {
        // parseStyleBlock already vouched for the id; this guards races.
        return $not_found($id);
      }
      $prefix = $index[$uuid]['kind'] === 'container' ? 'container:' : 'block:';
      if ($condition === 'variants') {
        // The rule's wear declarations (DialectCssParser::peelWear()):
        // each reference lands as the spine's apply_variant at the rule
        // key, the wear itself the validator's judgement (landing, flat,
        // not top-level only). An axis wear replaces the same-axis
        // reference by apply_variant's contract; a class wear adds, as
        // every css rule merges into what is there.
        foreach ($payload as $reference) {
          $ops[] = ['op' => 'apply_variant', 'key' => $prefix . $uuid . $local, 'variant' => $reference['variant']];
        }
        continue;
      }
      $ops[] = [
        'op' => 'update_style',
        'key' => $prefix . $uuid . $local,
        'condition' => $condition,
        'properties' => $payload,
      ];
    }
    foreach ($wear_removals as [$path, $id, $local, $states, $names]) {
      $uuid = $resolve($id);
      if ($uuid === NULL) {
        return $not_found($id);
      }
      if ($local === '') {
        $errors[] = new BlueprintError($path, 'BAD_OP', sprintf('#%s wears its variants on its own tag; the remove list trims a rule\'s wear ("class" or an axis name on #%s > pb-text), never the element\'s.', $id, $id), ['properties' => $names], 'Take a variant off the element with set {ref, attrs: {class: "..."}} (restating the classes it keeps) or {axis: null}; detach materializes it instead.');
        continue;
      }
      if ($states !== []) {
        $errors[] = new BlueprintError($path, 'BAD_OP', 'A rule wears a variant in its plain block, never under a state; name the rule without its pseudo-classes.', ['properties' => $names], sprintf('remove: [{selector: "#%s %s", properties: [...]}].', $id, substr($local, 2)));
        continue;
      }
      $prefix = $index[$uuid]['kind'] === 'container' ? 'container:' : 'block:';
      $worn = $index[$uuid]['node']['styles'][$local]['variants'] ?? [];
      foreach ($names as $name) {
        $matched = [];
        foreach ($worn as $reference) {
          $variant = (string) ($reference['variant'] ?? '');
          $mapping = $catalog->axisForVariant($variant);
          if ($name === 'class' ? ($mapping === NULL && $catalog->classForVariant($variant) !== NULL) : ($mapping !== NULL && $mapping[0] === $name)) {
            $matched[] = $variant;
          }
        }
        if ($matched === []) {
          $errors[] = new BlueprintError($path, 'BAD_OP', sprintf('The rule #%s %s wears no %s variant to remove.', $id, substr($local, 2), $name === 'class' ? 'class' : $name), ['property' => $name, 'worn' => array_column($worn, 'variant')], 'Remove names "class" (every style class the rule wears) or an axis attribute name the rule wears a value on.');
          continue;
        }
        foreach ($matched as $variant) {
          $ops[] = ['op' => 'remove_variant', 'key' => $prefix . $uuid . $local, 'variant' => $variant];
        }
      }
    }
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }
    if ($ops === []) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('css', 'MISSING_PARAMETER', 'The operation changes nothing.', [], 'Provide rules with declarations, or remove entries with property names.'),
      ]);
    }
    return $this->runTranslated($entity, $ops, $options, $conversation, []);
  }

  /**
   * Applies a whole dialect DOCUMENT to an entity, appending at the root.
   *
   * The page_create path: a new page's layout arrives as one dialect
   * document (top-level containers plus an optional <style> block whose
   * conditions and parts land injected on the nodes), and the whole tree
   * expands into one translated spine batch, exactly as an insert does
   * for a fragment. Aliases mint and persist the same way, so the create
   * result echoes id => uuid pairings the model can keep using.
   *
   * Not an op: creates are not page mutations, so the document path is a
   * public method for the tool layer rather than a seventh op name.
   *
   * One round for every fact: a document that fails the grammar is still
   * JUDGED whole. The parse's best-effort tree (every element and every
   * grammatical declaration that did parse) expands into the same batch
   * and runs as a judged batch (BlueprintOperations' judge option:
   * evaluated in full, nothing landed, no alias recorded), so the
   * semantic verdicts the validator holds (a property outside the
   * element's vocabulary roster, an unknown variant) come back beside the
   * grammar errors instead of one round later on the same bytes.
   */
  public function applyDocument(FieldableEntityInterface $entity, string $html, array $options = []): BlueprintResult {
    $catalog = $options['dialect_catalog'] ?? NULL;
    if (!$catalog instanceof DialectCatalog) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('layout', 'DIALECT_CATALOG_MISSING', 'The dialect document path needs the site vocabulary (a DialectCatalog in the request options) to parse its payload.', [], 'This is a caller wiring gap, not a document problem: the tool layer passes the generated catalog as options[dialect_catalog].'),
      ]);
    }
    $conversation = (string) ($options['conversation'] ?? '');

    $parse = $this->parser->parseDocument($html, $catalog);
    $parse_errors = $this->toBlueprintErrors($parse->errors, 'layout');

    $tree = $this->treeBuilder->build($entity);
    $index = $this->elementIndex($tree);
    $first_location = $this->firstLocation($entity, (string) $entity->uuid(), 'beforeend', $index);

    $ops = [];
    $style_ops = [];
    $minted = [];
    $errors = [];
    $this->expandNodes($parse->tree, $first_location, $catalog, $ops, $style_ops, $minted, $errors);
    if ($errors) {
      return BlueprintResult::structuralFailure(array_merge($parse_errors, $errors));
    }
    if ($ops === [] && $style_ops === []) {
      return BlueprintResult::structuralFailure($parse_errors ?: [
        new BlueprintError('layout', 'MISSING_PARAMETER', 'The document holds no elements.', [], 'A page document is one or more <pb-container> elements, with an optional <style> block and <pb-file> declarations.'),
      ]);
    }

    // The declarations land before the batch, on the overlay the batch
    // settles, so file="name" and url('file:name') anywhere in the
    // document resolve whatever the source order; a refusal joins the
    // grammar errors and dooms the gesture to a judged batch (nothing
    // lands, the overlay is discarded).
    foreach ($parse->files as $file) {
      $error = $this->declareFile($conversation, $file['name'], $file['type'], $file['content'], 'layout', $file['line']);
      if ($error !== NULL) {
        $parse_errors[] = $error;
      }
    }

    return $this->runTranslated($entity, array_merge($ops, $style_ops), $options, $conversation, $minted, judge: $parse_errors);
  }

  /**
   * Expands parsed fragment nodes into spine ops, chaining sibling order.
   *
   * The first node takes the position-mapped location; each following
   * sibling anchors after the previous node, so document order survives
   * any insertion position.
   *
   * @param array $nodes
   *   The parsed fragment nodes, in document order.
   * @param array $first_location
   *   The location the first node lands at.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param array $ops
   *   Collector for the structural ops (by reference).
   * @param array $style_ops
   *   Collector for the style ops that follow the batch (by reference).
   * @param array $minted
   *   Collector: alias => uuid for author-chosen ids in the fragment.
   * @param array $errors
   *   Collector for dialect errors (by reference).
   */
  protected function expandNodes(array $nodes, array $first_location, DialectCatalog $catalog, array &$ops, array &$style_ops, array &$minted, array &$errors): void {
    $previous = NULL;
    foreach ($nodes as $node) {
      $location = $previous === NULL ? $first_location : ['anchor' => $previous, 'placement' => 'after'];
      $previous = $this->expandNode($node, $location, $catalog, $ops, $style_ops, $minted, $errors);
    }
  }

  /**
   * Expands one parsed node into spine ops; returns its uuid.
   */
  protected function expandNode(array $node, array $location, DialectCatalog $catalog, array &$ops, array &$style_ops, array &$minted, array &$errors): string {
    $uuid = $node['uuid'] ?? $this->uuid->generate();
    if (isset($node['id'])) {
      $minted[(string) $node['id']] = $uuid;
    }
    $kind = $node['kind'] ?? '';

    switch ($kind) {
      case 'container':
        $op = [
          'op' => 'add_section',
          'location' => $location,
          'uuid' => $uuid,
          'layout' => $catalog->containerLayoutId(),
        ];
        // Provenance is a birth fact: the stencil="" the parser accepted
        // rides the root create op exactly as instantiate_stencil stamps
        // it, so the trailing style ops and the ownership rule see it.
        if (isset($node['stencil'])) {
          $op['stencil'] = $node['stencil'];
        }
        // So is the name: a born container's name="" lands in the
        // section's label slot (layout_settings.label, the slot the
        // tree's rename and set {name} write), never as a second op.
        if (isset($node['label'])) {
          $op['layout_settings'] = ['label' => $node['label']];
        }
        $ops[] = $op;
        foreach ($node['children'] ?? [] as $child) {
          // 'inside' appends: sequential appends into the fresh container
          // preserve document order.
          $this->expandNode($child, ['anchor' => $uuid, 'placement' => 'inside'], $catalog, $ops, $style_ops, $minted, $errors);
        }
        $this->expandNodeStyles($node, 'container:' . $uuid, $style_ops);
        return $uuid;

      case 'block':
        $op = ['op' => 'add_element', 'uuid' => $uuid, 'location' => $location];
        if (isset($node['plugin_id'])) {
          $op['plugin_id'] = $node['plugin_id'];
        }
        else {
          $op['block_type'] = $node['block_type'] ?? NULL;
          $op['properties'] = $node['block_fields'] ?? [];
        }
        if (isset($node['stencil'])) {
          $op['additional'][LayoutSerializer::STENCIL_PROVENANCE_KEY] = $node['stencil'];
        }
        $ops[] = $op;
        $this->expandNodeStyles($node, 'block:' . $uuid, $style_ops);
        return $uuid;

      case 'stencil':
        if (($node['styles'] ?? []) !== []) {
          $errors[] = new BlueprintError('html', 'ILLEGAL_ATTR', 'style/class on a <pb-stencil> reference is not supported.', [], 'Place the stencil, then style the placed elements (they come back with ids).');
        }
        $ops[] = [
          'op' => 'instantiate_stencil',
          'stencil' => (string) ($node['name'] ?? ''),
          'location' => $location,
          'uuid' => $uuid,
        ];
        return $uuid;
    }

    $errors[] = new BlueprintError('html', 'ILLEGAL_CONTAINMENT', sprintf('A %s node is not insertable here.', $kind === '' ? 'malformed' : $kind), [], 'Fragments hold containers, blocks, and stencil references.');
    return $uuid;
  }

  /**
   * Expands a parsed node's style entries into trailing style ops.
   *
   * Fragment nodes only ever carry the '' entry (style="" defaults plus
   * variant wear; conditions and parts ride the style op, spec section
   * 7). Document nodes (applyDocument) additionally carry the entries the
   * document's <style> block injected — selector locals ('::.class',
   * '::> *', '::p') and condition-keyed maps — so the walk covers every
   * local and every condition.
   */
  protected function expandNodeStyles(array $node, string $key, array &$style_ops): void {
    foreach ($node['styles'] ?? [] as $local => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      foreach ($entry['variants'] ?? [] as $reference) {
        $style_ops[] = ['op' => 'apply_variant', 'key' => $key . $local, 'variant' => $reference['variant']];
      }
      foreach ($entry as $condition => $properties) {
        if ($condition === 'variants' || !is_array($properties) || $properties === []) {
          continue;
        }
        $op = ['op' => 'update_style', 'key' => $key . $local, 'properties' => $properties];
        if ($condition !== 'default') {
          $op['condition'] = (string) $condition;
        }
        $style_ops[] = $op;
      }
    }
  }

  /**
   * Expands a set op's class patch into variant apply/remove ops.
   *
   * Each class token resolves through the grammar's class slot (the same
   * corrective as the document parser's, DialectWalk::unknownClass()).
   *
   * @param mixed $value
   *   The class patch as written.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param string $tag
   *   The element's tag, for the corrective.
   * @param array $roster
   *   The element's declared class roster (class => variant id).
   * @param string $key
   *   The target's style-map key (container: or block: plus its uuid).
   * @param array $own
   *   The target's own style-map entry: its worn variants and default styles.
   * @param array $ops
   *   Collector for the spine ops (by reference).
   * @param \Drupal\daedalus_blueprint\Dialect\DialectError[] $errors
   *   Collector for dialect errors (re-addressed to attrs.class).
   */
  protected function expandClassPatch(mixed $value, DialectCatalog $catalog, string $tag, array $roster, string $key, array $own, array &$ops, array &$errors): void {
    $wanted = [];
    foreach ($catalog->references($value === NULL ? '' : (string) $value, MaterialRecord::SLOT_CLASS) as $reference) {
      if ($reference->kind !== ValueKind::Variant) {
        $errors[] = DialectWalk::unknownClass($reference, $tag, $roster, $catalog, NULL);
        continue;
      }
      $wanted[$reference->id] = TRUE;
    }
    // The class attribute is the element's whole style-axis wear: every
    // worn style variant (declared roster or not) is in the diff; axis
    // variants (width, layout) are not classes and stay.
    $current = [];
    foreach ($own['variants'] ?? [] as $reference) {
      $variant = (string) ($reference['variant'] ?? '');
      if ($catalog->classForVariant($variant) !== NULL) {
        $current[$variant] = TRUE;
      }
    }
    foreach (array_keys(array_diff_key($current, $wanted)) as $variant) {
      $ops[] = ['op' => 'remove_variant', 'key' => $key, 'variant' => $variant];
    }
    foreach (array_keys(array_diff_key($wanted, $current)) as $variant) {
      $ops[] = ['op' => 'apply_variant', 'key' => $key, 'variant' => $variant];
    }
  }

  /**
   * Expands a set op's axis attribute patch into variant ops.
   *
   * The value resolves through the grammar's attribute slot for the scope
   * the element's axis view names (the document parser's read), the same
   * corrective as the parser's for a value no variant spells; the
   * container's axes and a block's own axes patch alike.
   *
   * @param string $axis
   *   The axis attribute name.
   * @param mixed $value
   *   The value as written; NULL removes the worn variant of that axis.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param array $axes
   *   The element's axis view (DialectCatalog::axesFor()).
   * @param string $tag
   *   The element's tag, for the corrective.
   * @param array $info
   *   The target's index entry (its node, plugin id and top-level flag).
   * @param string $key
   *   The target's style-map key (container: or block: plus its uuid).
   * @param array $own
   *   The target's own style-map entry: its worn variants and default styles.
   * @param array $ops
   *   Collector for the spine ops (by reference).
   * @param \Drupal\daedalus_blueprint\Dialect\DialectError[] $errors
   *   Collector for dialect errors (re-addressed to attrs.{axis}).
   */
  protected function expandAxisPatch(string $axis, mixed $value, DialectCatalog $catalog, array $axes, string $tag, array $info, string $key, array $own, array &$ops, array &$errors): void {
    if ($value === NULL) {
      foreach ($own['variants'] ?? [] as $reference) {
        $mapping = $catalog->axisForVariant($reference['variant'] ?? '');
        if ($mapping !== NULL && $mapping[0] === $axis) {
          $ops[] = ['op' => 'remove_variant', 'key' => $key, 'variant' => $reference['variant']];
        }
      }
      return;
    }
    $slot = MaterialRecord::attrSlot($axes[$axis]['scope'], $axis);
    $reference = $catalog->resolve((string) $value, $slot);
    if ($reference->kind !== ValueKind::Variant) {
      $allowed = array_keys($axes[$axis]['values']);
      $errors[] = DialectCssParser::unknownReference('BAD_ATTR_VALUE', sprintf('%s="%s" on <%s> names no published variant', $axis, (string) $value, $tag), $reference, $slot, $catalog, sprintf('Allowed values: %s.', implode(', ', $allowed)), NULL, [
        'attribute' => $axis,
        'allowed' => $allowed,
      ]);
      return;
    }
    if ($reference->record->rootOnly() && empty($info['top_level'])) {
      $errors[] = new DialectError('AXIS_NOT_TOP_LEVEL', sprintf('%s applies to top-level containers only, never nested inside another container', $axis), NULL, [
        'attribute' => $axis,
        'variant' => $reference->id,
      ], 'Set it on the outermost container.');
      return;
    }
    // apply_variant replaces the same-axis reference (daedalus_stylish's contract),
    // so no explicit remove is needed.
    $ops[] = ['op' => 'apply_variant', 'key' => $key, 'variant' => $reference->id];
  }

  /**
   * Runs translated spine ops as a batch, aliases riding its settlement.
   *
   * The batch is DRESSED (BlueprintOperationsInterface::batchOperations()'s
   * `dressed` option): the dialect is a total statement, so a container or
   * block a document or fragment authors wears exactly the axes and
   * classes it states, and the styling layer stamps no birth default
   * under it (an omitted axis is bare). One rule for every document lane
   * (page_create, page_edit, eb_create_entity); the op door (a flat
   * add_section/add_element, the palette's blank drop) is where birth
   * lives. The print emits every worn axis, so the round trip is lossless.
   *
   * Minted aliases are recorded (and removed elements' aliases forgotten)
   * on the overlay BEFORE the batch runs: BlueprintOperations settles the
   * alias overlay (persist on success, discard on failure) when the
   * OUTERMOST batch returns, and this handler runs at any depth — nested
   * inside page_edit's outer batch, or AS the outermost on the document
   * path (applyDocument). Recording after the batch would land the
   * aliases on an overlay an outermost settlement had already flushed,
   * so a created page's ids would die with the request. Recording first
   * is safe: a failed batch discards the overlay at settlement, so no
   * alias ever outlives identity that was never born.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param array $ops
   *   The translated spine ops, in batch order.
   * @param array $options
   *   The batch options; the dressed flag is set here.
   * @param string $conversation
   *   The conversation id the aliases belong to.
   * @param array $minted
   *   The alias => uuid pairings this op created.
   * @param string[] $forget
   *   Uuids whose aliases stop resolving (removed elements).
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $judge
   *   Errors that already doom the gesture (a document's grammar errors).
   *   When any are given the batch is JUDGED, never landed: it runs as a
   *   judged batch for its verdicts, no alias is recorded or forgotten,
   *   and the result is a structural failure carrying these errors first
   *   and the batch's (re-addressed) errors after them.
   */
  protected function runTranslated(FieldableEntityInterface $entity, array $ops, array $options, string $conversation, array $minted, array $forget = [], array $judge = []): BlueprintResult {
    if ($judge !== []) {
      $result = ($this->operations)()->batchOperations($entity, $ops, ['dressed' => TRUE, 'judge' => TRUE] + $options);
      $verdicts = $result->isSuccess() ? [] : $this->addressErrors($result->getErrors(), $ops, $minted + $this->aliasMap->aliases($entity, $conversation));
      return BlueprintResult::structuralFailure(array_merge($judge, $verdicts));
    }
    foreach ($forget as $uuid) {
      $this->aliasMap->forget($entity, $conversation, $uuid);
    }
    foreach ($minted as $alias => $uuid) {
      $this->aliasMap->record($entity, $conversation, $alias, $uuid);
    }
    $result = ($this->operations)()->batchOperations($entity, $ops, ['dressed' => TRUE] + $options);
    if (!$result->isSuccess()) {
      return $result->withErrors($this->addressErrors($result->getErrors(), $ops, $minted + $this->aliasMap->aliases($entity, $conversation)));
    }
    return $minted === [] ? $result : $result->withAliases($minted);
  }

  /**
   * Rewrites a translated batch's errors into the author's terms.
   *
   * The nested batch numbers the TRANSLATED ops (a six-section document is
   * ~80 of them), so its operation_index names nothing the author wrote.
   * Each op knows its element (a minted uuid, a targeted element, or a
   * style key), and the author knows that element by the alias they chose
   * or the uuid the print showed; the error is re-addressed to that, and a
   * style op's "properties.X" path is restated as "style.X" (or
   * "<condition>.X" under a condition), the attribute grammar the author
   * used. Errors without an index (batch-level) pass through.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The nested batch's errors.
   * @param array $ops
   *   The translated ops, in batch order.
   * @param array<string, string> $aliases
   *   Map of alias => uuid, minted pairings first.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The re-addressed errors.
   */
  protected function addressErrors(array $errors, array $ops, array $aliases): array {
    $names = [];
    foreach ($aliases as $alias => $uuid) {
      $names[$uuid] ??= $alias;
    }
    $addresses = [];
    foreach ($ops as $i => $op) {
      $uuid = NULL;
      $local = '';
      if (isset($op['uuid'])) {
        $uuid = (string) $op['uuid'];
      }
      elseif (isset($op['element'])) {
        $uuid = (string) $op['element'];
      }
      elseif (isset($op['key']) && preg_match('/^(?:container|block):([0-9a-f-]{36})(.*)$/', (string) $op['key'], $m)) {
        $uuid = $m[1];
        $local = $m[2];
      }
      if ($uuid !== NULL) {
        $addresses[$i] = [($names[$uuid] ?? $uuid) . $local, $op];
      }
    }
    return array_map(static function (BlueprintError $error) use ($addresses): BlueprintError {
      $index = $error->getOperationIndex();
      if ($index === NULL || !isset($addresses[$index])) {
        return $error;
      }
      [$element, $op] = $addresses[$index];
      $path = NULL;
      if (($op['op'] ?? '') === 'update_style' && str_starts_with($error->getPath(), 'properties.')) {
        $condition = (string) ($op['condition'] ?? 'default');
        $path = ($condition === 'default' ? 'style' : $condition) . substr($error->getPath(), strlen('properties'));
      }
      return $error->withElement($element, $path);
    }, $errors);
  }

  /**
   * Resolves the required ref (or id) parameter of an op.
   */
  protected function requireRef(array $operation, FieldableEntityInterface $entity, array $index, array $aliases, ?array &$errors): ?string {
    $errors ??= [];
    $ref = $operation['ref'] ?? $operation['id'] ?? NULL;
    if (!is_string($ref) || $ref === '') {
      $errors[] = new BlueprintError('ref', 'MISSING_PARAMETER', sprintf('%s needs "ref": a uuid or alias from the page print.', $operation['op'] ?? 'the op'), [], 'Refs are the ids in the page print (uuids or data-alias values).');
      return NULL;
    }
    return $this->resolveRef($ref, $entity, $index, $aliases, $errors);
  }

  /**
   * Resolves the required at parameter, returning [anchor uuid, position].
   */
  protected function requireAt(array $operation, FieldableEntityInterface $entity, array $index, array $aliases, ?array &$errors): ?array {
    $errors ??= [];
    $at = $operation['at'] ?? NULL;
    if (!is_array($at) || !isset($at['ref']) || !isset($at['position'])) {
      $errors[] = new BlueprintError('at', 'BAD_OP', '"at" must be {ref, position}.', [], 'ref anchors an element (or "root" for the page); position is beforebegin, afterbegin, beforeend, or afterend.');
      return NULL;
    }
    $position = $at['position'];
    if (!in_array($position, self::POSITIONS, TRUE)) {
      $errors[] = new BlueprintError('at.position', 'BAD_OP', sprintf('position "%s"; legal: %s.', (string) $position, implode(', ', self::POSITIONS)), [], 'The DOM insertion vocabulary: beforebegin/afterend beside the anchor, afterbegin/beforeend inside it.');
      return NULL;
    }
    $anchor = $this->resolveRef((string) $at['ref'], $entity, $index, $aliases, $errors, 'at.ref');
    if ($anchor === NULL) {
      return NULL;
    }
    return [$anchor, $position];
  }

  /**
   * Resolves a ref spelling to a uuid: root, printed uuid, or alias.
   */
  protected function resolveRef(string $ref, FieldableEntityInterface $entity, array $index, array $aliases, array &$errors, string $path = 'ref'): ?string {
    if ($ref === self::ROOT_REF || $ref === $entity->uuid()) {
      return (string) $entity->uuid();
    }
    if (DialectParser::isUuid($ref)) {
      return $ref;
    }
    if (isset($aliases[$ref])) {
      return $aliases[$ref];
    }
    $known = array_keys(array_filter($aliases, static fn (string $uuid): bool => isset($index[$uuid])));
    $errors[] = new BlueprintError($path, 'UNKNOWN_REF', sprintf('"%s" is not a live alias or uuid.', $ref), ['known_aliases' => $known], 'Refs are the ids in the page print (uuids or data-alias values); aliases are minted by inserting elements with ids.');
    return NULL;
  }

  /**
   * The spine location for a DOM position at an anchor.
   *
   * Beforebegin/afterend are before/after in the anchor's flow; beforeend
   * appends inside; afterbegin is before the anchor's first child (inside
   * when it has none). The root entity anchors afterbegin/beforeend over
   * its top-level flow.
   */
  protected function firstLocation(FieldableEntityInterface $entity, string $anchor, string $position, array $index): array {
    if ($anchor === $entity->uuid()) {
      if ($position === 'afterbegin') {
        $first = $this->firstTopLevelUuid($index);
        if ($first !== NULL) {
          return ['anchor' => $first, 'placement' => 'before'];
        }
      }
      return ['anchor' => $anchor, 'placement' => 'inside'];
    }
    return match ($position) {
      'beforebegin' => ['anchor' => $anchor, 'placement' => 'before'],
      'afterend' => ['anchor' => $anchor, 'placement' => 'after'],
      'beforeend' => ['anchor' => $anchor, 'placement' => 'inside'],
      'afterbegin' => ($first = $index[$anchor]['children'][0] ?? NULL) !== NULL
        ? ['anchor' => $first, 'placement' => 'before']
        : ['anchor' => $anchor, 'placement' => 'inside'],
    };
  }

  /**
   * The first top-level element's uuid, if any.
   */
  protected function firstTopLevelUuid(array $index): ?string {
    foreach ($index as $uuid => $info) {
      if (!empty($info['top_level'])) {
        return (string) $uuid;
      }
    }
    return NULL;
  }

  /**
   * Flattens the lean node tree into a uuid-keyed element index.
   *
   * Region nodes (multi-region layouts) are transparent: their children
   * index as the containing element's children.
   *
   * @return array
   *   uuid => ['kind', 'block_type' => ?string, 'plugin_id' => ?string,
   *   'top_level' => bool,
   *   'children' => [child uuids], 'node' => the lean node].
   */
  protected function elementIndex(array $tree): array {
    $index = [];
    $walk = function (array $nodes, bool $top_level) use (&$walk, &$index): array {
      $uuids = [];
      foreach ($nodes as $node) {
        if (!is_array($node)) {
          continue;
        }
        if (($node['kind'] ?? '') === 'region') {
          array_push($uuids, ...$walk($node['children'] ?? [], FALSE));
          continue;
        }
        $uuid = $node['uuid'] ?? NULL;
        $children = $walk($node['children'] ?? [], FALSE);
        if ($uuid !== NULL) {
          $index[$uuid] = [
            'kind' => $node['kind'] ?? '',
            'block_type' => $node['block_type'] ?? NULL,
            'plugin_id' => isset($node['plugin_id']) ? (string) $node['plugin_id'] : NULL,
            'top_level' => $top_level,
            'children' => $children,
            'node' => $node,
          ];
          $uuids[] = (string) $uuid;
        }
      }
      return $uuids;
    };
    $walk($tree, TRUE);
    return $index;
  }

  /**
   * Converts dialect errors to blueprint errors on a payload path.
   *
   * The two doors answer with one shape: the dialect error's context and
   * hint ride through, and the source line joins the message (the one
   * fact a payload path cannot carry).
   */
  protected function toBlueprintErrors(array $dialect_errors, string $path): array {
    return array_map(
      static fn (DialectError $error): BlueprintError => $error->toBlueprintError($path),
      $dialect_errors,
    );
  }

  /**
   * Validates a whole op batch at the envelope level, without an entity.
   *
   * The pure-grammar layer: envelope shapes, DOM positions, fragment and
   * CSS payload grammar with id existence unknown. Ops that are not DOM
   * ops are not judged here (at the spine they resolve through the
   * registry). $top_level_refs seeds the ids/uuids of the page's existing
   * top-level containers, so a fragment inserted beside one validates its
   * top-level-only axes; roots a batch itself lands at top level join the
   * set for later ops.
   *
   * @return \Drupal\daedalus_blueprint\Dialect\DialectError[]
   *   Envelope and payload grammar errors, messages prefixed "op N:".
   */
  public function validateBatch(array $batch, DialectCatalog $catalog, array $top_level_refs = []): array {
    $errors = [];
    $ops = $batch;
    if (isset($ops['op'])) {
      $ops = [$ops];
    }
    elseif (isset($ops['ops']) && is_array($ops['ops'])) {
      $ops = $ops['ops'];
    }
    if (!is_array($ops) || !array_is_list($ops)) {
      return [new DialectError('BAD_OP', 'a batch is a list of ops, one {op: ...} object, or {ops: [...]}')];
    }
    $refs = array_fill_keys($top_level_refs, TRUE);
    $css = new DialectCssParser($catalog);

    foreach ($ops as $i => $op) {
      if (!is_array($op) || !isset($op['op'])) {
        $errors[] = new DialectError('BAD_OP', sprintf('op %d: not an {op: ...} object', $i));
        continue;
      }
      $name = $op['op'];
      if (!in_array($name, self::OPS, TRUE)) {
        continue;
      }
      $op_errors = [];
      $at = $op['at'] ?? NULL;
      if (in_array($name, ['insert', 'move'], TRUE)) {
        if (!is_array($at) || !isset($at['ref']) || !isset($at['position'])) {
          $op_errors[] = new DialectError('BAD_OP', sprintf('%s needs "at" {ref, position}', $name));
        }
        elseif (!in_array($at['position'], self::POSITIONS, TRUE)) {
          $op_errors[] = new DialectError('BAD_OP', sprintf('position "%s"; legal: %s', (string) $at['position'], implode(', ', self::POSITIONS)));
        }
      }
      if (in_array($name, ['replace', 'remove', 'move', 'set'], TRUE) && empty($op['ref']) && empty($op['id'])) {
        $op_errors[] = new DialectError('BAD_OP', sprintf('%s needs "ref" (uuid or alias)', $name));
      }
      if (in_array($name, ['insert', 'replace'], TRUE)) {
        $html = $op['html'] ?? NULL;
        if (!is_string($html) || trim($html) === '') {
          $op_errors[] = new DialectError('BAD_OP', sprintf('%s needs "html"', $name));
        }
        else {
          $top = ($name === 'replace' && isset($refs[$op['ref'] ?? $op['id'] ?? '']))
            || ($name === 'insert' && is_array($at) && isset($refs[$at['ref'] ?? '']) && in_array($at['position'] ?? '', [
              'beforebegin',
              'afterend',
            ], TRUE))
            || ($name === 'insert' && is_array($at) && ($at['ref'] ?? '') === self::ROOT_REF);
          $parse = $this->parser->parseFragment($html, $catalog, $top);
          array_push($op_errors, ...$parse->errors);
          if ($top) {
            foreach ($parse->tree as $node) {
              if (($node['kind'] ?? '') === 'container' && (isset($node['uuid']) || isset($node['id']))) {
                $refs[$node['uuid'] ?? $node['id']] = TRUE;
              }
            }
          }
        }
      }
      if ($name === 'set') {
        $attrs = $op['attrs'] ?? NULL;
        $detach = $op['detach'] ?? NULL;
        $has_attrs = is_array($attrs) && $attrs !== [] && !array_is_list($attrs);
        $has_detach = is_array($detach) && $detach !== [] && array_is_list($detach);
        if (!$has_attrs && !$has_detach) {
          $op_errors[] = new DialectError('BAD_OP', 'set needs "attrs" (a map; null values clear) and/or "detach" (a list of worn variants)');
        }
        if ($has_attrs && isset($attrs['style']) && is_string($attrs['style'])) {
          $css->parseDeclarations($attrs['style'], NULL, sprintf('op %d set style', $i), $op_errors);
        }
      }
      if ($name === 'file') {
        foreach (['name', 'type', 'content'] as $key) {
          if (!is_string($op[$key] ?? NULL) || trim($op[$key]) === '') {
            $op_errors[] = new DialectError('BAD_OP', sprintf('file needs "%s" (a non-empty string)', $key));
          }
        }
      }
      if ($name === 'style') {
        if (empty($op['css']) && empty($op['remove'])) {
          $op_errors[] = new DialectError('BAD_OP', 'style needs "css" and/or "remove"');
        }
        if (isset($op['css']) && is_string($op['css'])) {
          $css->parseStyleBlock($op['css'], 1, NULL, $op_errors);
        }
        foreach (is_array($op['remove'] ?? NULL) ? $op['remove'] : [] as $entry) {
          if (!is_array($entry) || !isset($entry['selector']) || !isset($entry['properties'])) {
            $op_errors[] = new DialectError('BAD_OP', 'remove entries are {selector, properties: [property names]}');
          }
          else {
            $css->parseSelector((string) $entry['selector'], NULL, NULL, $op_errors);
          }
        }
      }
      foreach ($op_errors as $error) {
        $errors[] = new DialectError($error->code, sprintf('op %d: %s', $i, $error->message), $error->line, $error->context, $error->hint);
      }
    }
    return $errors;
  }

}
