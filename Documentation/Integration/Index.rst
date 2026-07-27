..  include:: /Includes.rst.txt

..  _integration:

===========
Integration
===========

Three ways to get the suggestion list onto a page. All of them read the same stored
rows and honour the same TypoScript settings.

..  contents::
    :local:

..  _integration-content-element:

As a content element
====================

Add the :guilabel:`Semantic Suggestions` element (plugin
``semanticsuggestion_suggestions``) wherever an editor should place the list. No
TypoScript needed.

..  _integration-fluid:

In a Fluid template
===================

The extension registers a reusable TypoScript object. Call it from any Fluid template:

..  code-block:: html

    <div class="section section-semantic-suggestion">
        <f:cObject typoscriptObjectPath="lib.semantic_suggestion" />
    </div>

Or from TypoScript directly:

..  code-block:: typoscript

    page.100 =< lib.semantic_suggestion

``lib.semantic_suggestion`` references — rather than copies — the plugin's ``view``,
``persistence`` and ``settings``, so a template override applies to both integration
paths at once.

..  _integration-bootstrap-package:

Bootstrap Package
=================

The extension ships page templates for every Bootstrap Package layout, each placing the
suggestions after the main content (:typoscript:`colPos 0`) and before the bottom
content (:typoscript:`colPos 9`), inside
:html:`<div class="section section-semantic-suggestion">`.

Enable them in the :guilabel:`Constant Editor`, category
:guilabel:`semantic_suggestion > Template Integration`, or in TypoScript constants:

..  code-block:: typoscript

    plugin.tx_semanticsuggestion_suggestions.settings.overrideBootstrapTemplates = 1

Then flush the cache.

..  warning::
    Set this constant in the root template of the Bootstrap Package site only, never
    globally.

    This extension's TypoScript is loaded instance-wide, so the constant registers our
    page templates in :typoscript:`page.10.templateRootPaths.100` for every site that
    inherits it. The shipped templates are Bootstrap Package templates: they call
    ``lib.dynamicContent`` and read ``theme.pagelayout``, neither of which exists
    elsewhere. On a site that does not use Bootstrap Package but whose page template
    happens to be named :file:`Default.html` — a very common name — our template wins
    and the page body renders empty.

Covered layouts: :file:`Default`, :file:`Simple`, :file:`None`, :file:`2Columns`,
:file:`2Columns2575`, :file:`2Columns5050`, :file:`2ColumnsOffsetRight`,
:file:`3Columns`, :file:`SpecialFeature`, :file:`SpecialStart`,
:file:`SubnavigationLeft`, :file:`SubnavigationRight`,
:file:`SubnavigationLeft2Columns`, :file:`SubnavigationRight2Columns`.

..  _integration-templates:

Overriding the template
=======================

The plugin renders :file:`Templates/Suggestions/List.html`. Point TypoScript at your own
copy, using index ``10`` or above — ``0`` and ``1`` are taken by the extension:

..  code-block:: typoscript

    plugin.tx_semanticsuggestion_suggestions.view {
        templateRootPaths.10 = EXT:my_sitepackage/Resources/Private/Templates/
        partialRootPaths.10  = EXT:my_sitepackage/Resources/Private/Partials/
        layoutRootPaths.10   = EXT:my_sitepackage/Resources/Private/Layouts/
    }

Variables available in :file:`List.html`:

..  list-table::
    :header-rows: 1

    *   -   Variable
        -   Content
    *   -   ``suggestions``
        -   Array keyed by page UID. Each entry has ``similarity`` (float),
            ``excerpt`` (string) and ``data`` (the page record, plus ``data.media``,
            the first media file of the page, or :php:`null`).
    *   -   ``currentPageTitle``
        -   Title of the page being rendered.
    *   -   ``pagination``
        -   ``currentPage``, ``numberOfPages``, ``hasNextPage``, ``hasPreviousPage``,
            ``startRecord``, ``endRecord``, ``totalItems``. The plugin paginates at 10
            items per page, which is above ``maxSuggestions`` by default, so a single
            page of results is the norm.
    *   -   ``debugLogs``
        -   Only filled when :ref:`debugMode <ts-debugMode>` is on, and rendered as a
            visible block.

..  _integration-styling:

Styling
=======

The shipped template reuses Bootstrap card markup, so it inherits the site's styles
when Bootstrap is present. The classes it emits:

..  code-block:: css

    /* wrapper, only present when you add it yourself or use the shipped page templates */
    .section-semantic-suggestion { }

    /* emitted by the plugin template */
    .card-menu { }              /* the list */
    .card-menu-item { }         /* one suggestion, carries proximity="<score>" */
    .card-menu-item .card-title { }
    .card-menu-item .card-text { }   /* excerpt */
    .card-menu-item .card-info { }   /* last modified, score */

..  _integration-disable:

Disabling suggestions on some pages
===================================

Either remove the object for those pages:

..  code-block:: typoscript

    [page["uid"] == 42]
        lib.semantic_suggestion >
    [END]

or keep them out of the suggestion lists of other pages with
:ref:`excludePages <ts-excludePages>`. The two are not the same thing: the first hides
the list *on* page 42, the second stops page 42 from *being suggested*.
