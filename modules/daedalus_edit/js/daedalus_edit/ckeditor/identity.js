/**
 * @file
 * Eager identity for the canvas CKEditor: the live mirror of IdentityMinter.
 *
 * Every semantic text element of the declared instance vocabulary (the
 * spine's `instances:` declaration, delivered through the contract below)
 * carries a short random base36 id class (e.g. class="s-a3f") in the stored
 * field HTML so cold-loaded pages are addressable by Daedalus Stylish and the markup
 * view with no editor attached. The server's IdentityMinter is the canonical
 * guarantee on every write path; this plugin mirrors it live inside the
 * editor so mid-typing elements are addressable too and blur commits values
 * the server accepts unchanged (the minter's byte-identical no-op makes the
 * presave backstop free).
 *
 * The identity rides a dedicated model attribute ('sid') with explicit
 * two-way converters, NOT a GHS piggyback: GHS attributes are per element
 * type (htmlPAttributes vs htmlH2Attributes), so a p -> h2 rename would drop
 * the identity, while one uniform attribute survives rename (spike-proven).
 * A registerPostFixer maintains the invariant — CKEditor's own paved road
 * for "every X has Y".
 *
 * List identity is the same design one level up. A ul/ol wrapper is not a
 * model element (document lists are flat: paragraphs carrying listItemId /
 * listIndent / listType), so the wrapper's identity rides a dedicated
 * list-scope attribute ('lsid') on the wrapper's own items (the items at
 * the wrapper's indent depth, nested wrappers minting through their own
 * items' lsid), wired through ListEditing.registerDowncastStrategy — the
 * same paved road GHS and list properties use for wrapper attributes. NOT
 * the GHS htmlUlAttributes piggyback for the sid reason exactly: those are
 * per list type (htmlUlAttributes vs htmlOlAttributes, and GHS deletes the
 * mismatched one), so a bulleted -> numbered switch would drop the
 * identity, while the uniform lsid survives it. Items sharing an lsid
 * share one wrapper; differing lsids split wrappers (view attribute
 * elements only merge when identical), which is what keeps two adjacent
 * server-minted lists two lists. List ITEMS are the third scope: an li is
 * not a model element either, so its identity rides the item-scope 'isid'
 * attribute on the item's blocks (scope: 'item' downcast strategy, the
 * htmlLiAttributes road), one identity per listItemId.
 *
 * The IdentityMinter contract (vocabulary, class shape, id length and
 * alphabet) is DELIVERED, not copied: the Edit tool attaches
 * IdentityMinter::clientContract() as drupalSettings.daedalusEdit.identity and
 * this plugin reads it at init, one server truth, nothing to drift. The
 * behavioral rules mirrored live (see daedalus_edit/src/IdentityMinter.php):
 * - Random, not sequential: deleted elements' ids are never reused, so
 *   orphaned style-map entries cannot resurrect onto unrelated elements.
 * - Minting is universal: every vocabulary element mints at any depth,
 *   list items and nested lists included, by ONE duplicate law
 *   everywhere (first carrier in document order owns its id, later
 *   duplicates re-mint) — split, merge, indent, outdent, and type-switch
 *   gestures all resolve through it, no list-specific acceptance.
 * - The one element the client does NOT stamp is the paragraph a
 *   collapsed <li>text</li> holds in the model: it downcasts with no
 *   <p> element in the data, so there is nothing to carry the class —
 *   the server minter finds no paragraph there either (a paragraph that
 *   already carries a sid renders expanded and keeps minting normally).
 */

const { Plugin } = window.CKEditor5.core;

/**
 * The delivered IdentityMinter contract
 * (drupalSettings.daedalusEdit.identity, IdentityMinter::clientContract()).
 *
 * Read per call, not at import: the settings ride the Edit tool's
 * attachments and can land after this module loads. A missing contract
 * throws, minting against a guessed vocabulary would write identity the
 * server disowns, and the retired copies are not coming back.
 *
 * @return {{vocabulary: Set<string>, classPattern: RegExp,
 *   idLength: number, alphabet: string}}
 */
const contract = () => {
  const identity = drupalSettings.daedalusEdit?.identity;
  if (!identity) {
    throw new Error(
      'The eager-identity contract (drupalSettings.daedalusEdit.identity) was not delivered; the Edit tool attaches it.',
    );
  }
  return {
    // Only vocabulary elements' s-* classes upcast to the identity
    // attributes. Identity classes on anything else (a div or span a
    // format lets through) stay plain classes in GHS custody and
    // round-trip untouched, exactly as the server leaves non-vocabulary
    // carriers alone.
    vocabulary: new Set(identity.vocabulary),
    classPattern: new RegExp(identity.classPattern),
    idLength: identity.idLength,
    alphabet: identity.alphabet,
  };
};

/**
 * Mints a random id unique against $taken, claiming it there.
 *
 * @param {{idLength: number, alphabet: string}} identity
 *   The delivered contract.
 * @param {Set<string>} taken
 *   Ids already present in the document.
 *
 * @return {string}
 *   The fresh id.
 */
const mintId = (identity, taken) => {
  let id;
  do {
    id = '';
    for (let i = 0; i < identity.idLength; i++) {
      id +=
        identity.alphabet[Math.floor(Math.random() * identity.alphabet.length)];
    }
  } while (taken.has(id));
  taken.add(id);
  return id;
};

/**
 * The identity CKEditor plugin.
 *
 * Injected into every canvas editor via attachEditor's extraPlugins (both
 * the form-swap inline_editor and the blueprint formatted_text editor go
 * through that one attach), so it needs no per-format configuration.
 */
export class Identity extends Plugin {
  /**
   * {@inheritdoc}
   */
  static get pluginName() {
    return 'DaedalusEditIdentity';
  }

  /**
   * {@inheritdoc}
   */
  init() {
    const editor = this.editor;
    const schema = editor.model.schema;
    const identity = contract();

    // One uniform attribute on every block-level element (headings and
    // paragraphs are $block; blockQuote is a container registered apart).
    schema.extend('$block', { allowAttributes: 'sid' });
    if (schema.isRegistered('blockQuote')) {
      schema.extend('blockQuote', { allowAttributes: 'sid' });
    }

    // The mintable model elements: the vocabulary as this editor's config
    // names it. Headings are resolved from heading.options because the
    // model name is configuration (heading1 vs heading2 may map to h2);
    // only h2-h4 views are vocabulary.
    const mintable = new Set(['paragraph']);
    if (schema.isRegistered('blockQuote')) {
      mintable.add('blockQuote');
    }
    for (const option of editor.config.get('heading.options') ?? []) {
      const view =
        typeof option.view === 'string' ? option.view : option.view?.name;
      if (view !== undefined && identity.vocabulary.has(view)) {
        mintable.add(option.model);
      }
    }

    // The list wrapper tags the declared vocabulary mints. Wrappers are
    // not model elements, so their identity rides the list-scope 'lsid'
    // attribute on the wrapper's own items; ListEditing applies it to the
    // ul/ol it builds at downcast (each wrapper reads its own depth's
    // items, so nested wrappers mint through theirs) and treats it as a
    // list attribute everywhere else (stripped when an item leaves the
    // list, copied on split, wrapper boundaries follow it). List items
    // are the same design at item scope: the li is not a model element,
    // so its identity rides the item-scope 'isid' attribute on the item's
    // blocks.
    const listTags = new Set(
      ['ul', 'ol'].filter((tag) => identity.vocabulary.has(tag)),
    );
    let itemTag = identity.vocabulary.has('li');
    if ((listTags.size > 0 || itemTag) && editor.plugins.has('ListEditing')) {
      const listEditing = editor.plugins.get('ListEditing');
      if (listTags.size > 0) {
        schema.extend('$listItem', { allowAttributes: 'lsid' });
        listEditing.registerDowncastStrategy({
          scope: 'list',
          attributeName: 'lsid',
          setAttributeOnDowncast(writer, value, viewElement) {
            if (value) {
              writer.addClass(`s-${value}`, viewElement);
            }
          },
        });
      }
      if (itemTag) {
        schema.extend('$listItem', { allowAttributes: 'isid' });
        listEditing.registerDowncastStrategy({
          scope: 'item',
          attributeName: 'isid',
          setAttributeOnDowncast(writer, value, viewElement) {
            if (value) {
              writer.addClass(`s-${value}`, viewElement);
            }
          },
        });
      }
    } else {
      listTags.clear();
      itemTag = false;
    }

    this._addConverters(identity, listTags, itemTag);
    this._registerPostFixer(identity, mintable, listTags, itemTag);
  }

  /**
   * Two-way conversion between the sid attribute and the s-* view class.
   *
   * (The lsid downcast needs no converter here: the list downcast
   * strategy registered in init() owns it, because only ListEditing knows
   * when it is building a wrapper.)
   *
   * @param {object} identity
   *   The delivered contract.
   * @param {Set<string>} listTags
   *   The vocabulary's list wrapper tags ('ul'/'ol'), empty when lists
   *   are undeclared or the editor has no list support.
   * @param {boolean} itemTag
   *   Whether list items ('li') are declared vocabulary (and the editor
   *   has list support), so items carry item-scope identity.
   */
  _addConverters(identity, listTags, itemTag) {
    const editor = this.editor;

    // Downcast: sid attribute -> s-* class on the view element (editing and
    // data pipelines both, so the canvas DOM carries identity live).
    editor.conversion.for('downcast').add((dispatcher) => {
      dispatcher.on('attribute:sid', (evt, data, conversionApi) => {
        if (!conversionApi.consumable.consume(data.item, evt.name)) {
          return;
        }
        const viewElement = conversionApi.mapper.toViewElement(data.item);
        if (!viewElement) {
          return;
        }
        const writer = conversionApi.writer;
        if (data.attributeOldValue) {
          writer.removeClass(`s-${data.attributeOldValue}`, viewElement);
        }
        if (data.attributeNewValue) {
          writer.addClass(`s-${data.attributeNewValue}`, viewElement);
        }
      });
    });

    // Upcast in two phases. The claim must beat GHS: under formats that
    // allow class on the vocabulary (all of them, post identity), GHS would
    // otherwise swallow s-* into htmlPAttributes-style per-element-type
    // attributes, which is exactly the rename-fragile custody this plugin
    // exists to avoid. Consuming at high priority — before any converter
    // GHS registers — settles the race by construction. The attribute can
    // only be SET once the element itself has converted, hence the second,
    // lowest-priority phase.
    const claimed = new WeakMap();
    this.editor.conversion.for('upcast').add((dispatcher) => {
      dispatcher.on(
        'element',
        (evt, data, conversionApi) => {
          const viewElement = data.viewItem;
          // List wrappers and items are claimed by the lsid/isid phases
          // below: stamping sid here would land the wrapper's or li's id on
          // its first block.
          if (
            !identity.vocabulary.has(viewElement.name) ||
            listTags.has(viewElement.name) ||
            (itemTag && viewElement.name === 'li')
          ) {
            return;
          }
          for (const name of viewElement.getClassNames()) {
            if (
              identity.classPattern.test(name) &&
              conversionApi.consumable.consume(viewElement, { classes: [name] })
            ) {
              // The first identity class is THE identity, like the server's
              // identityOf(); any further s-* tokens stay ordinary classes.
              claimed.set(viewElement, name.slice(2));
              break;
            }
          }
        },
        { priority: 'high' },
      );

      dispatcher.on(
        'element',
        (evt, data, conversionApi) => {
          const id = claimed.get(data.viewItem);
          if (id === undefined) {
            return;
          }
          claimed.delete(data.viewItem);
          const modelElement = data.modelRange?.start.nodeAfter;
          if (
            modelElement &&
            conversionApi.schema.checkAttribute(modelElement, 'sid')
          ) {
            conversionApi.writer.setAttribute('sid', id, modelElement);
          }
        },
        { priority: 'lowest' },
      );
    });

    if (listTags.size > 0) {
      // List wrapper upcast, the same two phases. The claim must beat
      // GHS's low-priority ul/ol handler, which would swallow the class
      // into the type-bound htmlUlAttributes/htmlOlAttributes custody;
      // the stamp needs the converted items, hence the lowest-priority
      // second phase. Nested wrappers claim too: minting is universal,
      // each wrapper at any depth carries its own identity.
      const claimedLists = new WeakMap();
      editor.conversion.for('upcast').add((dispatcher) => {
        dispatcher.on(
          'element',
          (evt, data, conversionApi) => {
            const viewElement = data.viewItem;
            if (!listTags.has(viewElement.name)) {
              return;
            }
            for (const name of viewElement.getClassNames()) {
              if (
                identity.classPattern.test(name) &&
                conversionApi.consumable.consume(viewElement, {
                  classes: [name],
                })
              ) {
                claimedLists.set(viewElement, name.slice(2));
                break;
              }
            }
          },
          { priority: 'high' },
        );

        dispatcher.on(
          'element',
          (evt, data, conversionApi) => {
            const id = claimedLists.get(data.viewItem);
            if (id === undefined || !data.modelRange) {
              return;
            }
            claimedLists.delete(data.viewItem);
            // The wrapper's items arrive flat, nested lists' items included
            // at higher indents; the wrapper's OWN items are the lowest
            // indent in its range, and only they carry its identity (a
            // nested wrapper's range starts at its own depth, so the same
            // rule serves every level).
            const items = [
              ...data.modelRange.getItems({ shallow: true }),
            ].filter(
              (item) => item.is('element') && item.hasAttribute('listItemId'),
            );
            const indent = Math.min(
              ...items.map((item) => item.getAttribute('listIndent')),
            );
            for (const item of items) {
              if (
                item.getAttribute('listIndent') === indent &&
                conversionApi.schema.checkAttribute(item, 'lsid')
              ) {
                conversionApi.writer.setAttribute('lsid', id, item);
              }
            }
          },
          { priority: 'lowest' },
        );
      });
    }

    if (!itemTag) {
      return;
    }

    // List item upcast, the same two phases at item scope: the claim
    // beats GHS's htmlLiAttributes custody, and the stamp lands the li's
    // identity on the item's own blocks (the blocks sharing the first
    // converted block's listItemId; deeper items in the range belong to
    // nested lists and carry their own lis' identities).
    const claimedItems = new WeakMap();
    editor.conversion.for('upcast').add((dispatcher) => {
      dispatcher.on(
        'element:li',
        (evt, data, conversionApi) => {
          const viewElement = data.viewItem;
          for (const name of viewElement.getClassNames()) {
            if (
              identity.classPattern.test(name) &&
              conversionApi.consumable.consume(viewElement, { classes: [name] })
            ) {
              claimedItems.set(viewElement, name.slice(2));
              break;
            }
          }
        },
        { priority: 'high' },
      );

      dispatcher.on(
        'element:li',
        (evt, data, conversionApi) => {
          const id = claimedItems.get(data.viewItem);
          if (id === undefined || !data.modelRange) {
            return;
          }
          claimedItems.delete(data.viewItem);
          let itemId;
          for (const item of data.modelRange.getItems({ shallow: true })) {
            if (!item.is('element') || !item.hasAttribute('listItemId')) {
              continue;
            }
            itemId ??= item.getAttribute('listItemId');
            if (
              item.getAttribute('listItemId') === itemId &&
              conversionApi.schema.checkAttribute(item, 'isid')
            ) {
              conversionApi.writer.setAttribute('isid', id, item);
            }
          }
        },
        { priority: 'lowest' },
      );
    });
  }

  /**
   * The invariant: every mintable element carries a unique sid, every
   * list run at every depth carries a unique wrapper lsid, and every
   * list item carries a unique isid.
   *
   * @param {object} identity
   *   The delivered contract.
   * @param {Set<string>} mintable
   *   The model element names minting applies to.
   * @param {Set<string>} listTags
   *   The vocabulary's list wrapper tags ('ul'/'ol'), empty when lists
   *   are undeclared or the editor has no list support.
   * @param {boolean} itemTag
   *   Whether list items ('li') are declared vocabulary (and the editor
   *   has list support), so items carry item-scope identity.
   */
  _registerPostFixer(identity, mintable, listTags, itemTag) {
    const model = this.editor.model;
    // The wrapper tag each list type downcasts to, the client twin of the
    // server minting the ul/ol elements it finds in the data.
    const tagOfType = {
      bulleted: 'ul',
      customBulleted: 'ul',
      numbered: 'ol',
      customNumbered: 'ol',
    };
    model.document.registerPostFixer((writer) => {
      const root = model.document.getRoot();

      // First pass mirrors the minter's: every id anywhere in the document
      // is taken before any minting, so a fresh mint can never collide with
      // an id a LATER element already owns (which would wrongly re-mint the
      // rightful owner when the walk reached it). It also counts each list
      // item's blocks, which the collapsed-paragraph rule below needs.
      const taken = new Set();
      const elements = [];
      const itemBlocks = new Map();
      for (const item of model.createRangeIn(root).getItems()) {
        if (!item.is('element')) {
          continue;
        }
        elements.push(item);
        for (const attribute of ['lsid', 'isid', 'sid']) {
          const id = item.getAttribute(attribute);
          if (id !== undefined) {
            taken.add(id);
          }
        }
        const itemId = item.getAttribute('listItemId');
        if (itemId !== undefined) {
          itemBlocks.set(itemId, (itemBlocks.get(itemId) ?? 0) + 1);
        }
      }

      // Second pass: ownership and minting, ONE duplicate law for every
      // identity scope. First carrier in document order owns its id (even
      // a non-mintable one); later duplicates re-mint. A wrapper id
      // precedes its item's, which precedes its blocks' (<ul class>, then
      // <li class>, then <p class> in the data), so each element resolves
      // lsid, then isid, then sid.
      const seen = new Set();
      let changed = false;
      // The list runs per indent depth: runs[d] is the current wrapper at
      // depth d (consecutive same-type items, ListEditing's unit of one
      // wrapper). An item at depth d closes any deeper runs: a later
      // deeper item belongs to a NEW nested wrapper under a new parent
      // item. `id` is the run's wrapper identity; `stale` is a duplicate
      // id the run just re-minted away from, so every item of a pasted
      // duplicate list follows its run to ONE fresh id instead of
      // splintering.
      let runs = [];
      // The current list item: consecutive blocks sharing a listItemId,
      // one li, one identity.
      let listItem = null;
      for (const element of elements) {
        if (element.hasAttribute('listItemId')) {
          const indent = element.getAttribute('listIndent');
          const type = element.getAttribute('listType');
          if (runs.length > indent + 1) {
            runs.length = indent + 1;
          }
          let run = runs[indent];
          if (!run || run.parent !== element.parent || run.type !== type) {
            run = { parent: element.parent, type, id: null, stale: null };
            runs[indent] = run;
          }
          if (listTags.has(tagOfType[type])) {
            const lsid = element.getAttribute('lsid');
            if (lsid !== undefined && lsid === run.id) {
              // Continuing the current wrapper.
            } else if (lsid !== undefined && lsid === run.stale) {
              // The duplicated wrapper this run already re-minted.
              writer.setAttribute('lsid', run.id, element);
              changed = true;
            } else if (lsid !== undefined && !seen.has(lsid)) {
              // First carrier: owns the id, and the run continues (or
              // splits into a second wrapper) under it.
              seen.add(lsid);
              run.id = lsid;
              run.stale = null;
            } else if (lsid === undefined && run.id !== null) {
              // An identity-less block joining an identified run (typing,
              // paste) adopts the wrapper's id: one visual list stays one
              // wrapper.
              writer.setAttribute('lsid', run.id, element);
              changed = true;
            } else {
              // Missing on a fresh run, or a duplicate of an id owned
              // elsewhere: mint.
              const fresh = mintId(identity, taken);
              writer.setAttribute('lsid', fresh, element);
              seen.add(fresh);
              run.stale = lsid ?? null;
              run.id = fresh;
              changed = true;
            }
          }
          if (itemTag) {
            const itemId = element.getAttribute('listItemId');
            const isid = element.getAttribute('isid');
            if (!listItem || listItem.itemId !== itemId) {
              // The first block of a new item resolves the li's identity
              // by the duplicate law: own it if unclaimed, re-mint a
              // duplicate (an enter-split copies list attributes onto the
              // new item, exactly the duplicate case).
              let id = isid;
              if (id === undefined || seen.has(id)) {
                id = mintId(identity, taken);
                writer.setAttribute('isid', id, element);
                changed = true;
              }
              seen.add(id);
              listItem = { itemId, id };
            } else if (isid !== listItem.id) {
              // A later block of the same item: one li, one identity.
              writer.setAttribute('isid', listItem.id, element);
              changed = true;
            }
          }
        } else {
          if (runs.some((run) => run && run.parent === element.parent)) {
            runs = [];
          }
          listItem = null;
        }

        const sid = element.getAttribute('sid');
        if (sid !== undefined && !seen.has(sid)) {
          seen.add(sid);
          continue;
        }
        if (!mintable.has(element.name)) {
          continue;
        }
        // A solitary sid-less list paragraph downcasts collapsed into its
        // li (no <p> element exists in the data), so there is nothing to
        // carry a class; the server minter finds no paragraph there
        // either. Any other list block renders as its own element and
        // mints like one outside a list.
        if (
          sid === undefined &&
          element.name === 'paragraph' &&
          element.hasAttribute('listItemId') &&
          itemBlocks.get(element.getAttribute('listItemId')) === 1
        ) {
          continue;
        }
        const fresh = mintId(identity, taken);
        writer.setAttribute('sid', fresh, element);
        seen.add(fresh);
        changed = true;
      }
      return changed;
    });
  }
}
