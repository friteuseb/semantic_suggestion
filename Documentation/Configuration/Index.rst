..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The extension is configured in two places, and they do different jobs:

..  list-table::
    :header-rows: 1

    *   -   Where
        -   Decides
    *   -   Scheduler task
        -   Which pages are analysed, and which pairs are **stored**.
    *   -   TypoScript
        -   How the stored pairs are **displayed**, and how the backend module renders.

A third place, the extension configuration in
:guilabel:`Admin Tools > Settings > Extension Configuration`, only acts as a fallback —
see :ref:`configuration-extension`.

..  contents::
    :local:

..  _configuration-scheduler:

Scheduler task
==============

Create a :guilabel:`Semantic Suggestion: Generate Similarities` task in the
:guilabel:`Scheduler` module. Its fields:

..  _task-startPageId:

..  confval:: startPageId
    :name: task-startPageId
    :type: integer
    :Default: 1

    Page the analysis starts from. It may be a site root or any page below it; the
    subtree is walked to full depth. The site the page belongs to is resolved
    automatically and stored separately — see :ref:`configuration-columns`.

..  _task-qualityLevel:

..  confval:: qualityLevel
    :name: task-qualityLevel
    :type: float
    :Default: 0.3

    Storage threshold, between 0.1 and 1.0. A pair scoring below it is **not written
    to the database**, so it can never be displayed either.

    The threshold applied is exactly this value, floored at 0.05. No offset is
    applied. Lowering it later requires a full re-run to produce the missing pairs.

..  _task-excludePages:

..  confval:: excludePages
    :name: task-excludePages
    :type: string
    :Default: (empty)

    Comma-separated page UIDs that are not analysed at all, for example
    :typoscript:`42,56,78`.

..  _task-recursiveExclusion:

..  confval:: recursiveExclusion
    :name: task-recursiveExclusion
    :type: boolean
    :Default: 1

    When enabled, an excluded page takes its whole subtree with it.

    When disabled, only the excluded page itself is skipped and its children are
    still analysed. Note that those children are then collected without the
    exclusion list, so an excluded page nested under another excluded page *is*
    analysed in that mode.

..  _task-languageId:

..  confval:: languageId
    :name: task-languageId
    :type: integer
    :Default: -1

    :typoscript:`-1` analyses every language configured on the site in a single run,
    which is what a multilingual site normally wants. Set an explicit language ID
    only when you need a different quality level per language, and create one task
    per language — see :ref:`multisite-multilingual`.

..  note::
    The task also carries a legacy ``minimumSimilarity`` property. It is now a
    read-only mirror of the storage threshold; setting it has no effect. A task saved
    before ``qualityLevel`` existed is migrated on its next run, the old value
    becoming the quality level.

Scheduling: daily or weekly, off-peak. Each run deletes and rewrites only its own
rows, identified by ``root_page_id`` + ``scope_page_id`` + ``sys_language_uid``, so
several tasks can coexist — one per site, or one per subtree of a site.

..  _configuration-typoscript:

TypoScript
==========

All settings live under
:typoscript:`plugin.tx_semanticsuggestion_suggestions.settings` and are exposed to the
:guilabel:`Constant Editor` under the category :guilabel:`semantic_suggestion`. The
extension loads its own TypoScript instance-wide, so overrides belong in a site's root
template.

Display
-------

..  _ts-qualityLevel:

..  confval:: qualityLevel
    :name: ts-qualityLevel
    :type: float
    :Default: 0.3
    :Path: plugin.tx_semanticsuggestion_suggestions.settings

    Display filter. Stored pairs scoring below this value are not shown.

    It is independent from the task's quality level. Setting it *lower* than the
    task's simply shows everything that was stored; setting it *higher* shows fewer,
    better matches. It can never reveal a pair that was never stored.

..  _ts-maxSuggestions:

..  confval:: maxSuggestions
    :name: ts-maxSuggestions
    :type: integer
    :Default: 3
    :Path: plugin.tx_semanticsuggestion_suggestions.settings

    Number of suggestions rendered, after exclusions and the language check.

..  _ts-excludePages:

..  confval:: excludePages
    :name: ts-excludePages
    :type: string
    :Default: (empty)
    :Path: plugin.tx_semanticsuggestion_suggestions.settings

    Page UIDs that are never shown **as a suggestion**. This does not stop those
    pages from displaying suggestions of their own, and it does not save any
    processing — the pairs were still computed and stored. To skip a page entirely,
    exclude it in the scheduler task instead.

..  _ts-excerptLength:

..  confval:: excerptLength
    :name: ts-excerptLength
    :type: integer
    :Default: 100
    :Path: plugin.tx_semanticsuggestion_suggestions.settings

    Maximum number of characters of the excerpt; longer text is cut and suffixed with
    an ellipsis.

..  _ts-excerptSources:

..  confval:: excerptSources
    :name: ts-excerptSources
    :type: string
    :Default: bodytext,description,abstract
    :Path: plugin.tx_semanticsuggestion_suggestions.settings

    Ordered list of sources for the excerpt; the first non-empty one wins.
    ``bodytext`` means the rendered content of the page. Not exposed in the Constant
    Editor.

..  _ts-debugMode:

..  confval:: debugMode
    :name: ts-debugMode
    :type: boolean
    :Default: 0
    :Path: plugin.tx_semanticsuggestion_suggestions.settings

    Writes verbose entries to :file:`typo3temp/logs/semantic_suggestion.log` **and
    appends a debug block to the rendered plugin output**. Never enable it in
    production.

Analysis
--------

..  _ts-analyzedFields:

..  confval:: analyzedFields
    :name: ts-analyzedFields
    :type: array of float
    :Default: title 1.5, description 1.0, keywords 2.0, abstract 1.2, content 1.0
    :Path: plugin.tx_semanticsuggestion_suggestions.settings

    Page fields fed to the analysis, and their weight. ``content`` falls back to the
    :sql:`bodytext` of the page's content elements when the page field is empty.

    ..  warning::
        Weights are coarse. A field's text is repeated
        :php:`max(1, round($weight))` times before vectorisation, so:

        *   ``1.0``, ``1.2`` and ``1.4`` all behave identically (repeated once),
        *   ``1.5`` to ``2.4`` are repeated twice,
        *   ``0`` does **not** disable a field — it is still included once.

..  _ts-recencyWeight:

..  confval:: recencyWeight
    :name: ts-recencyWeight
    :type: float
    :Default: 0.2
    :Path: plugin.tx_semanticsuggestion_suggestions.settings

    Share of the final score taken from the recency comparison:

    ..  code-block:: text

        score = semanticSimilarity * (1 - recencyWeight) + recencyBoost * recencyWeight

    ..  warning::
        ``recencyBoost`` is the *absolute difference* between the two pages'
        normalised ages (over a 30-day window), not their freshness. Raising this
        weight therefore favours pairs of pages that were modified at *different*
        times, and it lets a pair with no textual similarity at all reach
        ``recencyWeight`` as a score. Leave it low, or at :typoscript:`0` if you want
        the score to be purely textual.

..  _ts-enableStemming:

..  confval:: enableStemming
    :name: ts-enableStemming
    :type: boolean
    :Default: 1
    :Path: plugin.tx_semanticsuggestion_suggestions.settings

    Reduces words to their stem before vectorising. Only affects languages for which
    ``nlp_tools`` ships a stemmer — see :ref:`how-it-works-languages`.

..  _ts-defaultLanguage:

..  confval:: defaultLanguage
    :name: ts-defaultLanguage
    :type: string
    :Default: en
    :Path: plugin.tx_semanticsuggestion_suggestions.settings

    Last-resort language code, used only when neither the site configuration nor
    content analysis nor ``languageMapping`` could determine one.

..  _ts-languageMapping:

..  confval:: languageMapping
    :name: ts-languageMapping
    :type: array of string
    :Default: (unset)
    :Path: plugin.tx_semanticsuggestion_suggestions.settings

    Legacy mapping of language UID to language code, for example
    :typoscript:`languageMapping.1 = de`. Only consulted after the site configuration
    and content analysis have both failed, which does not happen on a site with a
    proper ``locale``. Keep it unset unless you know you need it.

..  important::
    The analysis settings above are read through the Extbase configuration manager,
    which needs a server request. Under a CLI run
    (:shell:`typo3 scheduler:run`, i.e. the normal cron case) there is none, so the
    task falls back to the extension configuration and then to the built-in defaults
    listed here. Do not count on TypoScript to tune the analysis in a cron-driven
    setup — the defaults are what will be used.

Backend module
--------------

Six booleans toggle the sections of the module, all enabled by default:
:typoscript:`showStatistics`, :typoscript:`showPerformanceMetrics`,
:typoscript:`showLanguageStatistics`, :typoscript:`showTopSimilarPairs`,
:typoscript:`showDistributionScores`, :typoscript:`showTopSimilarPages`.

Template integration
--------------------

..  _ts-overrideBootstrapTemplates:

..  confval:: overrideBootstrapTemplates
    :name: ts-overrideBootstrapTemplates
    :type: boolean
    :Default: 0
    :Path: plugin.tx_semanticsuggestion_suggestions.settings

    Registers the shipped Bootstrap Package page templates so suggestions appear
    automatically after the main content. Opt-in, and to be enabled in the constants
    of the Bootstrap Package site only — see :ref:`integration-bootstrap-package`.

..  _configuration-ignored:

Settings that are accepted but ignored
======================================

These appear in older documentation and in configurations carried over from previous
versions. No code reads them, so they can be dropped:

..  list-table::
    :header-rows: 1

    *   -   Setting
        -   Status
    *   -   :typoscript:`minTextLength`
        -   Never read. There is no minimum text length enforced by this extension; a
            page with no text simply produces no vector and therefore no pair. No
            longer present in the shipped TypoScript.
    *   -   :typoscript:`confidenceThreshold`
        -   Never read. The language-detection confidence check lives inside
            ``nlp_tools`` and is not configurable from here. No longer present in the
            shipped TypoScript.
    *   -   :typoscript:`proximityThreshold`
        -   Legacy name of the display threshold. It is only consulted when
            ``qualityLevel`` is absent, and the shipped setup always sets
            ``qualityLevel`` — so editing it has no effect. Use
            :ref:`qualityLevel <ts-qualityLevel>`.
    *   -   :typoscript:`persistence.storagePid`
        -   The plugin stores no records.

..  _configuration-extension:

Extension configuration
=======================

:guilabel:`Admin Tools > Settings > Extension Configuration > semantic_suggestion`
offers ``parentPageId``, ``proximityThreshold``, ``maxSuggestions`` and
``excerptLength``. They are a fallback only:

*   the **backend module** uses them when the corresponding TypoScript setting is
    missing,
*   the **analysis** uses this section when TypoScript is unreachable (the CLI case
    described above),
*   the **frontend plugin** never reads them,
*   ``parentPageId`` is read by no code at all.

..  _configuration-columns:

Database columns
================

:sql:`tx_semanticsuggestion_similarities` holds two different page references, and
mixing them up is the usual cause of "my suggestions disappeared":

..  list-table::
    :header-rows: 1

    *   -   Column
        -   Meaning
    *   -   ``page_id``
        -   The page the suggestions belong to.
    *   -   ``similar_page_id``
        -   A page suggested for it.
    *   -   ``similarity_score``
        -   Score of the pair, 0.0 to 1.0.
    *   -   ``root_page_id``
        -   **The site**: UID of the site root page, resolved automatically. The
            frontend filters on it, which is what keeps suggestions inside a site.
    *   -   ``scope_page_id``
        -   **The task**: the ``startPageId`` that produced the row. Several values
            may share one ``root_page_id`` when different tasks cover different
            subtrees of one site.
    *   -   ``sys_language_uid``
        -   Language the pair was computed in.
    *   -   ``source``
        -   Producer of the row: ``analysis`` for this extension, ``solr`` for
            ``semantic_suggestion_solr``. Each deletes only its own rows.

..  note::
    Before 4.1.0 the column ``root_page_id`` held the task's ``startPageId`` and
    ``scope_page_id`` did not exist. See :ref:`upgrade`.
