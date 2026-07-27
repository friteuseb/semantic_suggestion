..  include:: /Includes.rst.txt

..  _start:

===================
Semantic Suggestion
===================

:Extension key:
    semantic_suggestion

:Package name:
    talan-hdf/semantic-suggestion

:Version:
    |release|

:Language:
    en

:Author:
    Wolfangel Cyril

:License:
    This document is published under the
    `Open Publication License <https://www.opencontent.org/openpub/>`__.

:Rendered:
    |today|

----

Semantic Suggestion suggests related pages by comparing the text of the pages
themselves, instead of relying on categories or hand-made links. A scheduler task
analyses a page tree, stores a similarity score for every relevant page pair, and a
frontend plugin renders the best matches for the page being displayed.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        What the extension does, what it does not do, and how it performs.

    ..  card:: :ref:`Installation <installation>`

        Requirements, Composer and TER installation, first analysis run.

    ..  card:: :ref:`Configuration <configuration>`

        The scheduler task, every TypoScript setting, and the settings that are
        ignored.

    ..  card:: :ref:`Integration <integration>`

        Rendering the suggestions: content element, Fluid, Bootstrap Package,
        templates and CSS.

    ..  card:: :ref:`Backend module <backend-module>`

        Reading the stored analyses and the access rules that apply to editors.

    ..  card:: :ref:`Multi-site and multilingual <multisite>`

        One task per site, one run per language, and what stays instance-wide.

    ..  card:: :ref:`How it works <how-it-works>`

        The analysis pipeline, the scoring formula, language handling and caching.

    ..  card:: :ref:`Troubleshooting <troubleshooting>`

        Diagnosing an empty suggestion list, debug mode, useful SQL.

    ..  card:: :ref:`Upgrade <upgrade>`

        Migrating from 3.x / 4.0.0, and the behaviour changes of 4.1.

..  toctree::
    :hidden:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Integration/Index
    BackendModule/Index
    MultiSite/Index
    HowItWorks/Index
    Troubleshooting/Index
    Upgrade/Index
    ChangeLog/Index

..  toctree::
    :hidden:

    Sitemap
