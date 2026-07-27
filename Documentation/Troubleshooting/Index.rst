..  include:: /Includes.rst.txt

..  _troubleshooting:

===============
Troubleshooting
===============

..  contents::
    :local:

..  _troubleshooting-tools:

The three tools
===============

..  rubric:: 1. The diagnostic command

Checks that the ``nlp_tools`` stack is reachable and working, on German samples:

..  code-block:: shell

    vendor/bin/typo3 semantic:diagnostic

If it cannot instantiate the services, nothing else will work — reinstall
``nlp_tools`` before looking any further.

..  rubric:: 2. The stored rows

Everything the frontend can possibly show is in one table. This is the fastest way to
tell a *storage* problem from a *display* problem:

..  code-block:: sql

    SELECT root_page_id, scope_page_id, sys_language_uid, source,
           COUNT(*) AS pairs, MIN(similarity_score), MAX(similarity_score)
    FROM tx_semanticsuggestion_similarities
    GROUP BY root_page_id, scope_page_id, sys_language_uid, source;

Several ``scope_page_id`` values under one ``root_page_id`` is normal — it means
several tasks cover different subtrees of the same site.

..  rubric:: 3. Debug mode

..  code-block:: typoscript

    plugin.tx_semanticsuggestion_suggestions.settings.debugMode = 1

Writes to :file:`typo3temp/logs/semantic_suggestion.log` and appends a debug block to
the plugin output. Turn it off again afterwards — it is visible to visitors.

..  _troubleshooting-no-suggestions:

No suggestions anywhere
=======================

Work down this list; each step rules out the ones above it.

..  list-table::
    :header-rows: 1

    *   -   Check
        -   How
        -   If it fails
    *   -   The task ran
        -   Its last execution in the :guilabel:`Scheduler` module, or the log line
            ``Starting similarity generation task``
        -   Run it once with :guilabel:`Execute now`
    *   -   Rows exist
        -   The query above
        -   See :ref:`troubleshooting-nothing-stored`
    *   -   Rows exist **for this page**
        -   :sql:`SELECT * FROM tx_semanticsuggestion_similarities WHERE page_id = <uid>`
        -   The page was outside the task's scope, or excluded, or has too little text
    *   -   ``root_page_id`` is the **site root**
        -   Compare it with the site's root page UID
        -   The 4.1 migration wizard has not run — see :ref:`upgrade`
    *   -   The display threshold is not above every score
        -   Compare :ref:`qualityLevel <ts-qualityLevel>` with the ``MAX()`` above
        -   Lower the display value
    *   -   The plugin is actually rendered
        -   Look for the wrapper markup in the page source
        -   See :ref:`integration`

..  _troubleshooting-nothing-stored:

The task runs but stores nothing
================================

..  list-table::
    :header-rows: 1

    *   -   Cause
        -   Sign
        -   Fix
    *   -   ``nlp_tools`` missing or broken
        -   :shell:`semantic:diagnostic` fails, or the log shows *Failed to create
            TF-IDF vectors*
        -   Install ``nlp_tools``; without it every score is :php:`0.0`
    *   -   Quality level too high
        -   Log line ``Using threshold for filtering`` with a high value
        -   Set the task's quality level to ``0.25``–``0.3`` and re-run
    *   -   Pages have no text
        -   Log warnings *One or both pages have no text content*
        -   Nothing to do: image-only pages produce no vector
    *   -   ``startPageId`` outside any site
        -   The task fails with an exception in the log
        -   Point it at a page belonging to a configured site
    *   -   Everything excluded
        -   ``No pages found for language`` in the log
        -   Review ``excludePages`` and ``recursiveExclusion``

..  _troubleshooting-quality:

Suggestions are irrelevant
==========================

*   **Raise the display quality level** first: it costs nothing and needs no re-run.
    Only raise the *task's* level once you know which value you want, since lowering it
    again requires a full re-analysis.
*   **Set** :ref:`recencyWeight <ts-recencyWeight>` **to** :typoscript:`0` if you see
    unrelated pages being suggested. The recency term is a difference of ages, not a
    freshness bonus, and it can carry a pair with no textual overlap.
*   **Check the language** of the affected pages: an unsupported language loses
    stemming and gets the English stop word list, which makes scores noisier — see
    :ref:`how-it-works-languages`.
*   **Do not expect much from field weights.** They are applied as text repetition, so
    only differences of half a point or more change anything.

..  _troubleshooting-mixed-language:

Suggestions from another language
=================================

The language boundary is enforced in three places, so this practically only happens
when the site configuration is incomplete. Check that every language of the site has a
full ``locale``:

..  code-block:: yaml

    languages:
      -
        languageId: 1
        locale: 'de_DE.UTF-8'   # not just 'de'

Then re-run the task, since the stored rows were computed with the old configuration.

..  _troubleshooting-cross-site:

Suggestions from another site
=============================

Not possible since 4.1.0 — unless the rows predate it and the migration wizard has not
run. Verify:

..  code-block:: sql

    -- must return 0
    SELECT COUNT(*) FROM tx_semanticsuggestion_similarities WHERE scope_page_id = 0;

See :ref:`upgrade`.

..  _troubleshooting-timeout:

The task times out
==================

The comparison is quadratic in the number of pages of one scope, so the answer is to
reduce the scope rather than to raise the threshold:

*   split the site into several tasks on subtrees, each with its own frequency,
*   or run it from the CLI, where :php:`max_execution_time` is usually unlimited:

    ..  code-block:: shell

        vendor/bin/typo3 scheduler:run --task=<uid>

..  _troubleshooting-module:

The backend module is empty or unreachable
==========================================

*   **"No module access"** on TYPO3 14 with versions before 4.1.2: known bug, upgrade.
*   **An empty analysis list for an editor**: they have no webmount inside a site that
    has an analysis — see :ref:`backend-module-access`. Administrators always see
    everything.
*   **"No similarity analysis found"** for an administrator: the table is empty, go
    back to :ref:`troubleshooting-nothing-stored`.

..  _troubleshooting-empty-page:

A page renders empty after enabling the Bootstrap Package integration
=====================================================================

``overrideBootstrapTemplates`` was enabled globally instead of in the Bootstrap Package
site's own constants, and the shipped :file:`Default.html` replaced another site's page
template. Set the constant in the right root template only — see
:ref:`integration-bootstrap-package`.
