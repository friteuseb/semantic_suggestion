..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

..  _installation-requirements:

Requirements
============

..  list-table::
    :header-rows: 1

    *   -   Requirement
        -   Version
    *   -   TYPO3
        -   12.4 → 14.x
    *   -   PHP
        -   8.1 or higher
    *   -   ``typo3/cms-scheduler``
        -   same as core, required
    *   -   ``cywolf/nlp-tools`` (``nlp_tools``)
        -   ^2.0, **required**

..  warning::
    ``nlp_tools`` is a hard requirement, even though :file:`ext_emconf.php` lists it
    under ``suggests``. All text processing and the whole TF-IDF vectorisation live
    there: without it no vector can be built, every score stays at :php:`0.0` and
    nothing is ever stored. Composer installs it automatically; a TER install does
    not, so install it manually in that case.

..  _installation-composer:

Installation with Composer
==========================

..  code-block:: shell

    composer require talan-hdf/semantic-suggestion
    vendor/bin/typo3 extension:setup --extension=semantic_suggestion
    vendor/bin/typo3 cache:flush

:shell:`extension:setup` creates the table
:sql:`tx_semanticsuggestion_similarities`. You can also apply the schema from
:guilabel:`Admin Tools > Maintenance > Analyze Database Structure`.

..  _installation-ter:

Installation from the TER
=========================

#.  Install and activate ``nlp_tools`` first — see the warning above.
#.  Install `semantic_suggestion
    <https://extensions.typo3.org/extension/semantic_suggestion>`__ from
    :guilabel:`Admin Tools > Extensions`, or upload the archive to
    :file:`typo3conf/ext/`.
#.  Activate the extension, then update the database schema.

..  _installation-upgrade:

Upgrading an existing installation
==================================

Coming from 3.x or 4.0.0, two upgrade wizards must run. Skipping the first one makes
suggestions disappear for every analysis whose task started on a subtree instead of a
site root:

..  code-block:: shell

    vendor/bin/typo3 upgrade:run semanticSuggestionMigrateRootPageId
    vendor/bin/typo3 upgrade:run semanticSuggestionLabelAnalysisRows

They are also listed in :guilabel:`Admin Tools > Upgrade > Upgrade Wizard`. See
:ref:`upgrade` for what they change and how to verify the result.

..  _installation-first-run:

First analysis run
==================

Nothing is displayed until the scheduler task has run once.

#.  Create a :guilabel:`Semantic Suggestion: Generate Similarities` task in the
    :guilabel:`Scheduler` module and set its start page to a site root — see
    :ref:`configuration-scheduler`.
#.  Run it with :guilabel:`Execute now`, or from the CLI:

    ..  code-block:: shell

        vendor/bin/typo3 scheduler:run --task=<uid>

#.  Open :guilabel:`Web > Semantic Suggestion` to confirm that pairs were stored.
#.  Add the plugin to your templates — see :ref:`integration`.

If the module reports no analysis, run :shell:`vendor/bin/typo3 semantic:diagnostic`
and read :ref:`troubleshooting`.
