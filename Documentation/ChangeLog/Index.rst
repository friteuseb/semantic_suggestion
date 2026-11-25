..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

The full, per-release changelog lives in the repository, in
`CHANGELOG.md <https://github.com/friteuseb/semantic_suggestion/blob/master/CHANGELOG.md>`__,
and follows `Keep a Changelog <https://keepachangelog.com/en/1.0.0/>`__ and
`Semantic Versioning <https://semver.org/>`__.

Release highlights
==================

..  list-table::
    :header-rows: 1

    *   -   Version
        -   Highlights
    *   -   5.2.0
        -   Optional embedding API (Mistral, OpenAI or an OpenAI-compatible endpoint)
            to compare pages by meaning. Scores are calibrated to the TF-IDF scale;
            TF-IDF stays the default and the fallback. See
            :ref:`configuration-embedding`.
    *   -   5.1.0
        -   Link opportunities in the backend module: similar pages that do not link
            to each other yet, orphan pages first.
    *   -   5.0.0
        -   New similarity computation: far more relevant suggestions in every language
            measured, on a lower score scale. Default quality level 0.1. Requires the
            upgrade wizard and a re-run of every task — see :ref:`upgrade`.
    *   -   4.1.x
        -   Multi-site correctness: suggestions are scoped to the current site, the
            backend module is permission-scoped, cache invalidation is per site, and
            the Bootstrap Package integration became opt-in. TYPO3 14 support.
            Requires the migration wizards — see :ref:`upgrade`.
    *   -   4.0.0
        -   TYPO3 13 support.
    *   -   3.1
        -   Unified ``qualityLevel`` setting, replacing the split
            ``minimumSimilarity`` / ``proximityThreshold`` configuration.
    *   -   3.0.0
        -   TF-IDF scoring, stemming and stop word handling through ``nlp_tools``,
            language detection from the site configuration.
    *   -   2.0.0
        -   Scores moved from the cache to a dedicated database table, computed by a
            scheduler task.

Upgrading between any of these is covered in :ref:`upgrade`.
