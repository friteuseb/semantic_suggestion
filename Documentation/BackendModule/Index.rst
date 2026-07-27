..  include:: /Includes.rst.txt

..  _backend-module:

==============
Backend module
==============

:guilabel:`Web > Semantic Suggestion` shows what the scheduler task stored. It never
computes anything itself, so opening it is safe on a production site.

..  figure:: /Medias/backend_module.png
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

Each section can be switched off in TypoScript — see
:ref:`configuration-typoscript`.

..  figure:: /Medias/backend_module_performance_metrics.png
    :alt: Performance metrics section

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
