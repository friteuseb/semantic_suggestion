..  include:: /Includes.rst.txt

..  _multisite:

===========================
Multi-site and multilingual
===========================

..  contents::
    :local:

..  _multisite-sites:

Several sites in one instance
=============================

Create one task per site, with ``startPageId`` on that site's root page:

..  code-block:: text

    Task "Similarities – Main site"      startPageId: 1    languageId: -1
    Task "Similarities – Campaign site"  startPageId: 85   languageId: -1

The task resolves the site from ``startPageId`` and only ever walks that site's page
tree, so tasks cannot contaminate each other. Extra tasks on subtrees of a site are
allowed too — for instance a daily task on a fast-moving news section and a weekly one
on the rest. Each rewrites only its own ``scope_page_id``.

What is scoped per site automatically:

*   **Frontend display.** The lookup filters on the current page's site, so a page of
    one site can never be suggested on another. No configuration needed.
*   **Cache invalidation.** Analysis cache entries are tagged ``site_<rootPageId>``,
    so editing content on one site does not invalidate another's analysis.
*   **Backend module.** Permission-scoped per site — see
    :ref:`backend-module-access`.

What is **not** scoped, and needs your attention:

*   **TypoScript.** The extension's constants and setup are loaded instance-wide by
    :file:`ext_localconf.php`, so every setting is shared until a site's root template
    overrides it. This is why the Bootstrap Package integration
    (:ref:`integration-bootstrap-package`) must be enabled per site and never
    globally.
*   **Extension configuration and the log file**, which are instance-wide by nature.

..  _multisite-multilingual:

A multilingual site
===================

Language handling is driven by the site configuration. Each language's ``locale``
gives the language code used for stop words, stemming and vectorisation:

..  code-block:: yaml

    # config/sites/main/config.yaml
    languages:
      -
        languageId: 0
        title: 'English'
        locale: 'en_US.UTF-8'      # → analysed as "en"
      -
        languageId: 1
        title: 'Deutsch'
        locale: 'de_DE.UTF-8'      # → analysed as "de"

**One task is enough.** A multilingual site has a single root page and several
``languages`` entries; with the default ``languageId = -1`` the task iterates over all
of them in one run:

..  code-block:: text

    Task "Similarities – Main site"
      startPageId:  1     # the site root — one per SITE, not per language
      languageId:   -1    # all languages of this site
      qualityLevel: 0.3

Do not create one task per language pointing at different page UIDs: that pattern
belongs to multi-site setups, where each site has its own root page.

Split by language only when a language needs a **different quality level**. Both tasks
then target the same root page with an explicit language ID:

..  code-block:: text

    Task 1  startPageId: 1   languageId: 0   qualityLevel: 0.3
    Task 2  startPageId: 1   languageId: 1   qualityLevel: 0.25

Rows are keyed by ``sys_language_uid`` on top of the site and the scope, so the two
tasks never collide.

..  _multisite-language-boundary:

Languages never mix
===================

The language boundary is enforced three times over, so mixed-language suggestions are
not something you have to configure away:

#.  Pages are collected per language by the task.
#.  Two pages whose detected language codes differ score :php:`0.0` and are not
    stored.
#.  The frontend query filters on ``sys_language_uid`` and re-checks the language of
    each candidate page record.

If suggestions really do cross a language, the cause is almost always a missing or
malformed ``locale`` in the site configuration — see :ref:`troubleshooting`.

..  _multisite-templates:

Per-site templates
==================

Override the plugin template in the site's root template, using index ``10`` or above:

..  code-block:: typoscript

    plugin.tx_semanticsuggestion_suggestions.view {
        templateRootPaths.10 = EXT:my_sitepackage/Resources/Private/Templates/
    }

See :ref:`integration-templates`.
