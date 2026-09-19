(($, Drupal, once) => {
  const DefaultSidebar = Drupal.Daedalus.DefaultSidebar;
  const sidebarManager = Drupal.Daedalus.SidebarManager;

  /**
   * The entity-form panel's subject model — the 'daedalus_edit_form' JS half of
   * the EntityFormSidebar plugin.
   *
   * The panel (#daedalus_edit_entity_form, right rail / bottom slot) has a
   * SUBJECT: the entity whose cached form it shows. Fetched entity forms are
   * appended into the panel's form-cache mount and stay there for reuse; the
   * subject is recorded on the mount (data-daedalus-edit-subject, DOM as the
   * single source of truth) and exactly the subject's form is shown.
   *
   * The subject's writers:
   * - The toggle icon (and any plain open) is DUMB: it opens the panel on
   *   the TOP-LEVEL entity's form under any tool, fetching it if it is not
   *   cached yet.
   * - Form-path edits retarget the subject (openOn(), called by the form
   *   engine's getForm()).
   * - Subject death (the subject's cached form leaves the DOM: an edit
   *   canceled, a cache eviction, the block deleted) REVERTS the subject to
   *   the top-level entity while the panel is open.
   * Blueprint edits never touch the panel: nothing in the blueprint path
   * calls into this model.
   */
  class DaedalusEditSidebar extends DefaultSidebar {
    type = 'daedalus_edit_form';

    /**
     * The panel's DOM id (the EntityFormSidebar plugin id).
     *
     * @type {string}
     */
    static PANEL_ID = 'daedalus_edit_entity_form';

    /**
     * The form-cache mount's DOM id.
     *
     * @type {string}
     */
    static FORM_CACHE_ID = 'daedalus-edit-form-cache';

    /**
     * The pinned header title's DOM id (EntityFormSidebar::TITLE_ID).
     *
     * @type {string}
     */
    static TITLE_ID = 'daedalus-edit-form-panel-title';

    /**
     * A subject set by openOn() for the open() call it triggers, so the
     * form-path door is not clobbered by open()'s top-level default.
     *
     * @type {?Element}
     */
    pendingSubject = null;

    /**
     * The in-flight top-level form fetch, shared so concurrent doors (the
     * boot restore racing the icon, a revert racing a re-open) never append
     * the same form twice.
     *
     * @type {?Promise}
     */
    topLevelFetch = null;

    /**
     * The panel element.
     *
     * @return {?Element}
     */
    panel() {
      return document.getElementById(DaedalusEditSidebar.PANEL_ID);
    }

    /**
     * The form-cache mount inside the panel.
     *
     * @return {?Element}
     */
    mount() {
      return document.getElementById(DaedalusEditSidebar.FORM_CACHE_ID);
    }

    /**
     * The subject's cached form element, or null.
     *
     * @return {?Element}
     */
    subjectForm() {
      const mount = this.mount();
      const subject = mount?.dataset.daedalusEditSubject;
      if (!subject) {
        return null;
      }
      return mount.querySelector(
        `:scope > [data-daedalus-edit-form-id="${subject}"]`,
      );
    }

    /**
     * The top-level entity's form identity ("{entity_type}::{id}"), from the
     * main entity wrapper's canonical id.
     *
     * @return {?string}
     */
    topLevelSubjectId() {
      const wrapper = document.querySelector(
        '[data-main-entity][data-daedalus-ui-entity-wrapper]',
      );
      if (!wrapper) {
        return null;
      }
      const [entityTypeId, entityId] =
        wrapper.dataset.daedalusUiEntityWrapper.split('::');
      return `${entityTypeId}::${entityId}`;
    }

    /**
     * Makes a cached form the panel's subject.
     *
     * Records the subject on the mount and reconciles visibility. The
     * visibility reconcile also runs as a behavior after every AJAX insert
     * or replace inside the mount, so a form arriving (or being rebuilt by
     * the update cycle's ReplaceCommand) always lands in the recorded
     * subject state.
     *
     * @param {Element} form
     *   The cached form element (a .daedalus-edit-form wrapper in the mount).
     */
    setSubject(form) {
      const mount = this.mount();
      if (!mount) {
        return;
      }
      mount.dataset.daedalusEditSubject = form.dataset.daedalusEditFormId;
      this.applySubject();
    }

    /**
     * Reconciles cached-form visibility with the recorded subject.
     *
     * Also writes the panel's pinned header title from the subject form's
     * data-daedalus-edit-form-title: this is the single reconcile point, so the
     * header stays truthful across subject changes, reverts, and AJAX
     * rebuilds of the subject's form.
     */
    applySubject() {
      const mount = this.mount();
      if (!mount) {
        return;
      }
      const subject = mount.dataset.daedalusEditSubject;
      mount.querySelectorAll(':scope > .daedalus-edit-form').forEach((form) => {
        form.classList.toggle(
          'daedalus-edit-form--subject',
          form.dataset.daedalusEditFormId === subject,
        );
      });
      const title = document.getElementById(DaedalusEditSidebar.TITLE_ID);
      const subjectForm = this.subjectForm();
      if (title && subjectForm) {
        title.textContent = subjectForm.dataset.daedalusEditFormTitle ?? '';
      }
    }

    /**
     * Ensures the top-level entity's form is the subject, fetching it into
     * the cache when absent.
     *
     * @param {boolean} quiet
     *   Fetch without the fullscreen progress indicator (the revert path:
     *   the user did not ask for a form load).
     *
     * @return {Promise}
     *   Resolves once the top-level form is the subject; rejects when no
     *   main entity is derivable or the fetch fails.
     */
    async ensureTopLevelSubject(quiet = false) {
      const subjectId = this.topLevelSubjectId();
      if (!subjectId) {
        throw new Error(
          'Daedalus Edit: no main entity wrapper to derive the top-level form subject from.',
        );
      }
      let form = this.mount()?.querySelector(
        `:scope > [data-daedalus-edit-form-id="${subjectId}"]`,
      );
      if (!form) {
        if (!this.topLevelFetch) {
          this.topLevelFetch = Drupal.Daedalus.Edit.FetchMainEntityForm({
            quiet,
          }).finally(() => {
            this.topLevelFetch = null;
          });
        }
        await this.topLevelFetch;
        form = this.mount()?.querySelector(
          `:scope > [data-daedalus-edit-form-id="${subjectId}"]`,
        );
        if (!form) {
          throw new Error(
            'Daedalus Edit: the top-level entity form did not arrive in the form cache.',
          );
        }
      }
      this.setSubject(form);
    }

    /**
     * The in-flight top-level form fetch, or null when idle.
     *
     * For callers that must see the refetched form before acting on the
     * cache: a form-path edit resuming across a mutation that evicted the
     * root form (the eviction's revert started this fetch) waits on it
     * instead of racing a second fetch of the same form.
     *
     * @return {?Promise}
     */
    pendingFetch() {
      return this.topLevelFetch;
    }

    /**
     * The form-path door: retargets the subject to a cached form and opens
     * the panel.
     *
     * @param {Element} form
     *   The cached form element to show.
     *
     * @return {Promise}
     *   Resolves when the panel is open on this subject.
     */
    async openOn(form) {
      // Set directly (covers the already-open panel, where openSidebar
      // no-ops) AND flag it for open()'s benefit (covers the hidden panel,
      // where open()'s default would otherwise be the top-level subject).
      this.setSubject(form);
      this.pendingSubject = form;
      try {
        await sidebarManager.openSidebar(DaedalusEditSidebar.PANEL_ID);
      } finally {
        this.pendingSubject = null;
      }
    }

    /**
     * Removes a cached form — the subject-death entry point.
     *
     * Every path that takes a cached form out of the DOM (a canceled edit
     * discarding unknowable values, a blueprint mutation evicting a stale
     * form, a deleted block) routes through here so the subject model can
     * react: when the removed form was the subject of an OPEN panel, the
     * subject reverts to the top-level entity (re-fetched if its form was
     * the one removed). A closed panel needs no revert — plain open()
     * defaults to the top-level subject anyway.
     *
     * @param {Element} form
     *   The cached form element to remove.
     *
     * @return {Promise}
     *   Resolves once any revert has settled.
     */
    async removeForm(form) {
      const mount = this.mount();
      const wasSubject =
        !!mount &&
        form.dataset.daedalusEditFormId === mount.dataset.daedalusEditSubject;
      form.remove();
      if (!wasSubject) {
        return;
      }
      delete mount.dataset.daedalusEditSubject;
      const panel = this.panel();
      if (!panel || panel.classList.contains('daedalus-ui-hidden')) {
        return;
      }
      try {
        await this.ensureTopLevelSubject(true);
      } catch (error) {
        // No top-level form to revert to (no main entity, fetch failed): an
        // open panel with nothing to show closes.
        console.error(
          'Daedalus Edit: could not revert the form panel to the top-level entity.',
          error,
        );
        this.closeSidebar(DaedalusEditSidebar.PANEL_ID);
      }
    }

    /**
     * Open the panel.
     *
     * The plain open (toggle icon, hotkey, boot restore) is the DUMB door:
     * it opens on the top-level entity's form under any tool, regardless of
     * selection — fetching the form when it is not cached. The form-path
     * door (openOn) pre-sets its subject, which is respected.
     *
     * @param {string|null} id
     *   The panel id.
     *
     * @return {Promise}
     *   Resolves when the panel is open; rejects when no subject form can
     *   be established.
     */
    async open(id = null) {
      if (this.pendingSubject) {
        this.setSubject(this.pendingSubject);
      } else {
        await this.ensureTopLevelSubject();
      }
      return super.open(id ?? DaedalusEditSidebar.PANEL_ID);
    }

    /**
     * Close the panel.
     *
     * Overrides DefaultSidebar to add validation logic: rejects if there is
     * an invalid form item that needs to be fixed or discarded first.
     *
     * @param {string|null} id
     *   The panel id.
     *
     * @return {Promise}
     *   Resolves when the panel is closed, rejects if there is an invalid
     *   form item.
     */
    close(id = null) {
      return new Promise((resolve, reject) => {
        if (Drupal.Daedalus.Edit.hasInvalidFormItem()) {
          Drupal.Daedalus.Edit.warnAboutInvalidFormItem();
          reject(Drupal.Daedalus.SidebarPluginBase.CLOSE_BLOCKED);
          return;
        }

        // Safe to close - blur the current element if any.
        if (Drupal.Daedalus.Edit.CurrentlyEditingElement) {
          Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin
            .blur()
            .then(() => {
              this.closeSidebar(id);
              resolve();
            })
            .catch((error) => {
              if (error !== 'HTML validation failed') {
                console.error(error);
              }
              reject(error);
            });
        } else {
          this.closeSidebar(id);
          resolve();
        }
      });
    }

    /**
     * Actually close the panel element.
     *
     * @param {string|null} id
     *   The panel id.
     */
    closeSidebar(id = null) {
      const sidebar = this.getElement(id ?? DaedalusEditSidebar.PANEL_ID);
      if (sidebar) {
        this.hideSidebarElement(sidebar);
        document
          .querySelectorAll(`[data-sidebar-button-for="#${sidebar.id}"]`)
          .forEach((button) => button.classList.remove('active'));
      }
    }
  }

  sidebarManager.registerPlugin(new DaedalusEditSidebar());

  /**
   * Keeps cached-form visibility truthful across AJAX arrivals.
   *
   * The update cycle rebuilds a cached form with a ReplaceCommand and new
   * forms arrive by AppendCommand; both attach behaviors afterwards, which
   * is the hook to re-apply the recorded subject so an arriving form never
   * flashes (or lingers) in the wrong state.
   */
  Drupal.behaviors.DaedalusEditFormSubject = {
    attach(context) {
      const mount = document.getElementById(DaedalusEditSidebar.FORM_CACHE_ID);
      if (
        !mount ||
        !(
          context === document ||
          mount.contains(context) ||
          context.contains?.(mount)
        )
      ) {
        return;
      }
      sidebarManager.getPlugin('daedalus_edit_form').applySubject();
    },
  };

  /**
   * Restores the panel's subject on a page load that renders it open.
   *
   * The server renders the panel from its (side, slot) cookie, but cached
   * forms live only client-side: a reload lands an open, empty panel. The
   * boot restore fetches the top-level entity's form — the panel's default
   * subject — so the open panel is truthful again.
   */
  Drupal.behaviors.DaedalusEditFormPanelBoot = {
    attach(context) {
      once(
        'daedalus-edit-form-panel-boot',
        `#${DaedalusEditSidebar.PANEL_ID}`,
        context,
      ).forEach((panel) => {
        if (panel.classList.contains('daedalus-ui-hidden')) {
          return;
        }
        const plugin = sidebarManager.getPlugin('daedalus_edit_form');
        if (!plugin.subjectForm()) {
          plugin.ensureTopLevelSubject(true).catch((error) => {
            console.error(
              'Daedalus Edit: could not restore the form panel subject.',
              error,
            );
            plugin.closeSidebar(DaedalusEditSidebar.PANEL_ID);
          });
        }
      });
    },
  };
})(jQuery, Drupal, once);
