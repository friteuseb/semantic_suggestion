..  include:: /Includes.rst.txt

..  _upgrade:

=======
Upgrade
=======

..  contents::
    :local:

..  _upgrade-41:

To 4.1 from 3.x or 4.0.0
========================

Version 4.1 changes the meaning of the ``root_page_id`` column and adds
``scope_page_id`` (see :ref:`configuration-columns`). Existing rows must be migrated,
otherwise the frontend — which now filters on the site root — finds nothing for any
analysis whose task started on a subtree.

..  rubric:: 1. Update the database schema

..  code-block:: shell

    vendor/bin/typo3 extension:setup --extension=semantic_suggestion

..  rubric:: 2. Run the upgrade wizards

..  code-block:: shell

    vendor/bin/typo3 upgrade:run semanticSuggestionMigrateRootPageId
    vendor/bin/typo3 upgrade:run semanticSuggestionLabelAnalysisRows

Both are also listed in :guilabel:`Admin Tools > Upgrade > Upgrade Wizard`, as
*"Semantic Suggestion: split root_page_id into site root and analysis scope"* and
*"Semantic Suggestion: label existing analysis rows with their source"*.

..  list-table::
    :header-rows: 1

    *   -   Wizard
        -   What it does
    *   -   ``semanticSuggestionMigrateRootPageId``
        -   Moves the old value into ``scope_page_id`` and fills ``root_page_id`` with
            the real site root of that page. Idempotent, deletes nothing. Rows whose
            start page no longer belongs to any configured site — or whose old value
            was ``0`` — cannot be resolved and are left untouched.
    *   -   ``semanticSuggestionLabelAnalysisRows``
        -   Sets ``source = 'analysis'`` on rows that inherited the column's former
            ``'solr'`` default, which is what made ``semantic_suggestion_solr`` delete
            rows it did not own. It deliberately does nothing when that extension is
            installed, because the two producers' old rows are then
            indistinguishable — in that case re-run the *Generate Similarities* task
            and the Solr indexer once instead, and each rewrites its own rows.

..  rubric:: 3. Verify

..  code-block:: sql

    -- must return 0
    SELECT COUNT(*) FROM tx_semanticsuggestion_similarities WHERE scope_page_id = 0;

Any remaining row is one the wizard could not resolve. Re-run the corresponding
scheduler task to regenerate it, or delete it if that task is gone.

..  rubric:: 4. Review your thresholds

The storage threshold is now exactly the quality level you set on the task, with no
hidden offset. Earlier versions silently stored from ``qualityLevel - 0.1``, so a task
configured at ``0.3`` was really storing from ``0.2``.

Nothing breaks, but each task now stores slightly fewer pairs. Those extra pairs were
below the display threshold and therefore never shown — unless you had deliberately
lowered the TypoScript value below the task's to exploit the buffer. If you did, lower
the **task's** quality level to match and re-run it.

..  rubric:: 5. Review your template integration

``overrideBootstrapTemplates`` now defaults to ``0``. If you relied on the automatic
Bootstrap Package integration, enable it explicitly in the constants of the site that
uses Bootstrap Package — and read the warning in
:ref:`integration-bootstrap-package` first if the instance hosts more than one site.

..  _upgrade-14:

TYPO3 14
========

Upgrade to at least 4.1.2 before moving an instance to TYPO3 14: on earlier versions
the backend module could not be opened at all — every user, administrators included,
was redirected with *"No module access"*.

..  _upgrade-2x:

From 2.x
========

2.x scored pages with a plain word-count cosine similarity, whose values are much
higher than the TF-IDF scores used since 3.0. A configuration carried over unchanged
will usually display nothing.

#.  Make sure ``nlp_tools`` is installed (Composer does it for you).
#.  Lower your thresholds: ``0.25``–``0.35`` instead of the ``0.5``–``0.8`` that made
    sense in 2.x.
#.  Re-run the scheduler task — all scores are recomputed.
#.  Drop ``proximityThreshold`` from your TypoScript in favour of ``qualityLevel``, and
    stop setting ``minimumSimilarity`` on the task; it is now derived from the quality
    level and ignored as input.
