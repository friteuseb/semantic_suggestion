..  include:: /Includes.rst.txt

..  _backend-module:

==============
Backend module
==============

:guilabel:`Web > Semantic Suggestion` shows what the scheduler task stored. It never
computes anything itself, so opening it is safe on a production site.

..  figure:: ../Medias/backend_module.png
    :alt: The Semantic Suggestion backend module

    Analysis selector, configuration reminder and statistics.

The page tree is hidden on purpose: the module works on one *analysis* at a time,
picked from a dropdown.

..  _backend-module-sections:

What it shows
=============

..  list-table::
    :header-rows: 1

    *   -   Section
        -   Content
    *   -   Analysis selector
        -   One entry per site, grouped by ``root_page_id``, with the number of pages
            and of stored pairs. Every task of a site appears as a single analysis,
            whichever subtree each one started from.
    *   -   Configuration reminder
        -   The display settings in effect: quality level, ``maxSuggestions``,
            ``excludePages``, ``excerptLength``.
    *   -   Statistics
        -   The five most similar pairs, the five pages with the most links, the score
            distribution, and the totals — all computed with the display filters
            applied, so they reflect what visitors actually see.
    *   -   Language statistics
        -   Distribution of the analysed pages over the site's languages.
    *   -   Performance metrics
        -   Module execution time and the number of stored pairs above the display
            threshold.
    *   -   Link opportunities
        -   Similar pages that do not link to each other yet, orphan pages first — see
            :ref:`backend-module-link-opportunities`.

Each section can be switched off in TypoScript — see
:ref:`configuration-typoscript`.

..  figure:: ../Medias/backend_module_performance_metrics.png
    :alt: Performance metrics section

..  _backend-module-link-opportunities:

Link opportunities
==================

The analysis says which pages are close; the reference index (:sql:`sys_refindex`)
says which pages already link to which. The panel lists the pairs where the first page
does not link to the second yet: each row is a link an editor can add.

*   **Which pairs.** For each page, its 5 closest pages at or above the display quality
    level. Further neighbours are left out: above the threshold a page can still be
    loosely related to dozens of others, and those are not links worth adding.
*   **Which links count as existing.** Links to a page from a visible content element of
    the default language, as recorded in the reference index. Links in other tables
    (news, records) and in translations are not counted.
*   **Order.** Pages that no other page links to (*orphans*) come first, then the most
    similar pairs. A link to an orphan page is worth more than one more link between
    pages that are already well connected.
*   **Action.** The source page links to the *Page* module, where the link is added.

The summary line counts the pairs considered, those already linked, those to add, the
orphan pages, and how many of them have a similar page that could link to them. An
orphan page with no similar page above the threshold needs a link that this analysis
cannot suggest.

..  note::
    The panel reads the reference index. If links were added outside the backend (by
    an import, for instance), update it first:
    :shell:`vendor/bin/typo3 referenceindex:update`.

Default language only: similarity rows store default-language page uids, and
translated content usually mirrors the default links.

..  _backend-module-access:

Access control
==============

*   **Administrators** see every analysis of the instance.
*   **Other backend users** see only the analyses of sites they hold a **webmount**
    on. A webmount on a subpage resolves to that page's site, so an editor mounted on
    one section still sees their whole site's analysis — and nothing from other sites.
*   The ``rootPageId`` URL argument is validated against that same list, so it cannot
    be used to reach another site's data.

A user with no webmount inside any configured site sees an empty module.

..  note::
    Module access itself is granted the usual way, per backend user group
    (:guilabel:`Access Lists > Modules > Web > Semantic Suggestion`).
