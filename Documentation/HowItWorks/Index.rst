..  include:: /Includes.rst.txt

..  _how-it-works:

============
How it works
============

..  contents::
    :local:

..  _how-it-works-pipeline:

The analysis pipeline
=====================

Everything below happens inside the scheduler task.

#.  **Scope.** The site is resolved from ``startPageId``, and the languages to process
    are taken from its site configuration (all of them, or the one given by
    ``languageId``).

#.  **Page collection.** The subtree below ``startPageId`` is walked to full depth, per
    language. Deleted and hidden pages are skipped, as are the pages listed in
    ``excludePages``.

#.  **Text preparation.** For every page, its language is resolved from the site
    configuration (see :ref:`how-it-works-languages`), then each field of
    :ref:`analyzedFields <ts-analyzedFields>` is read. ``content`` falls back to the
    :sql:`bodytext` of the page's content elements in that language when the page field
    itself is empty. Stop words of that language are removed, then — if
    :ref:`enableStemming <ts-enableStemming>` is on — the text is stemmed with that
    language's stemmer.

#.  **Vectorisation.** Pages are grouped by that same language code and each group is
    vectorised together, so all pages of one language share a single vocabulary and
    IDF corpus. Every page is vectorised exactly once, not once per comparison. A term
    counts :php:`weight * (1 + log(occurrences))` per field (see
    :ref:`analyzedFields <ts-analyzedFields>`), times its IDF. The whole text is used:
    there is no length cut-off.

#.  **Comparison.** Every page is compared with every other page of the same language
    using cosine similarity on those vectors, then weighted by the freshness of the
    suggested page:

    ..  code-block:: text

        score = cosine(vector1, vector2) * ((1 - recencyWeight) + recencyWeight * freshness)

    Pairs of different languages score :php:`0.0` and are dropped.

#.  **Storage.** Pairs scoring at or above the task's quality level are written to
    :sql:`tx_semanticsuggestion_similarities`, in one transaction, in batches of 100.
    The rows of the previous run of *this* task — same site, same scope, same language
    — are deleted first, so a run never disturbs another task's results.

The frontend does none of this: it reads the stored rows for the current page, filters
them by the display quality level, ``excludePages`` and the language, and renders at
most ``maxSuggestions`` of them.

..  _how-it-works-scores:

Reading the scores
==================

The score is a cosine similarity between TF-IDF vectors, lowered by up to
``recencyWeight`` for pages that have not changed recently. Scores are low by nature,
and lower on short pages, which share fewer terms. Measured on 42 French pages in six
topics:

..  list-table::
    :header-rows: 1

    *   -   Pages
        -   Same topic (median)
        -   Different topics (highest)
        -   Top three on topic, at 0.1
    *   -   Long (encyclopaedia articles)
        -   0.22
        -   0.18
        -   99 %
    *   -   Short (2,500 characters)
        -   0.13
        -   0.10
        -   100 %

``0.1`` is a sane quality level; lower it to ``0.05`` on a site of short pages, raise
it to ``0.2`` to keep only close matches. ``0.5`` and above match almost nothing.

The relevance labels shown in the backend module are thresholds on that score:
``High`` from 0.25, ``Medium`` from 0.1, ``Low`` below.

..  _how-it-works-languages:

Language handling
=================

The language is resolved **per page**, once, and the same value is then used for stop
word removal, stemming and vectorisation. The order is:

#.  **The site configuration.** The ``locale`` of the page's language, reduced to its
    two-letter code (:yaml:`de_DE.UTF-8` → ``de``). This is the normal case and it
    always wins for a page belonging to a configured site.
#.  **Content analysis**, by ``nlp_tools``, only when the step above found nothing —
    a page outside any configured site.
#.  :ref:`defaultLanguage <ts-defaultLanguage>`, when there is not even any text to
    analyse.

..  note::
    Content analysis never overrides the site configuration. It is a fallback for
    pages outside any site, not a second opinion.

..  note::
    Nothing in this chain depends on the current request, so a run from cron
    (:shell:`typo3 scheduler:run`) and a run from :guilabel:`Execute now` in the
    backend resolve the same language for the same page. Before 4.1.4 they did not:
    stop word removal and stemming took the language from the request context, which
    under a CLI run meant ``en`` for every page of every language.

Stop words, stemming and detection profiles come from ``nlp_tools``, which supports
six languages:

..  list-table::
    :header-rows: 1

    *   -   Language
        -   Code
        -   Stop words
        -   Stemmer (Snowball)
    *   -   German
        -   ``de``
        -   yes
        -   yes
    *   -   English
        -   ``en``
        -   yes
        -   yes
    *   -   Spanish
        -   ``es``
        -   yes
        -   yes
    *   -   French
        -   ``fr``
        -   yes
        -   yes
    *   -   Italian
        -   ``it``
        -   yes
        -   yes
    *   -   Portuguese
        -   ``pt``
        -   yes
        -   yes
    *   -   any other
        -   —
        -   English list used as fallback
        -   none, words are only tokenised

Any other language still gets TF-IDF vectorisation, which is what does most of the
work; it just loses stemming and gets an unhelpful stop word list. Note also that
content-based detection can only ever return one of the six codes above.

German is the language this extension was tuned on: the Snowball stemmer is what makes
``Automobilindustrie`` and ``Automobil`` share a stem, and umlauts are handled. A lower
quality level than for English is a reasonable starting point on a German site.

..  _how-it-works-limitations:

Known limitations
=================

*   **The comparison is quadratic** in the number of pages of a scope. Split a very
    large site into several tasks on subtrees rather than raising the quality level to
    compensate.

..  _how-it-works-caching:

Caching
=======

The extension registers a cache named ``semantic_suggestion`` (file backend, 24 h,
member of the ``pages`` group). It holds analysis results and the intermediate
``nlp_tools`` results, and entries are tagged ``site_<rootPageId>``.

Invalidation happens on two occasions:

*   the scheduler task flushes its site's tag after saving,
*   a DataHandler hook flushes the tag of the affected site when a page or a content
    element is changed, moved or deleted. Records being *created* carry a ``NEW…``
    placeholder instead of a UID and cannot be resolved to a site at that point; they
    are picked up by the next analysis run anyway.

..  code-block:: shell

    # flush everything, including this cache
    vendor/bin/typo3 cache:flush
