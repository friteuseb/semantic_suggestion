# TYPO3 Extension: Semantic Suggestion

[![TYPO3 12](https://img.shields.io/badge/TYPO3-12-orange.svg)](https://get.typo3.org/version/12)
[![TYPO3 13](https://img.shields.io/badge/TYPO3-13-orange.svg)](https://get.typo3.org/version/13)
[![TYPO3 14](https://img.shields.io/badge/TYPO3-14-orange.svg)](https://get.typo3.org/version/14)
[![Latest Stable Version](https://img.shields.io/packagist/v/talan-hdf/semantic-suggestion.svg)](https://packagist.org/packages/talan-hdf/semantic-suggestion)
[![License](https://img.shields.io/packagist/l/talan-hdf/semantic-suggestion.svg)](https://packagist.org/packages/talan-hdf/semantic-suggestion)

Suggests related pages on a TYPO3 site by comparing the text of the pages themselves,
instead of relying on categories or manual links.

A scheduler task walks a page tree, builds a TF-IDF vector per page (via
[nlp_tools](https://github.com/friteuseb/nlp_tools)), compares them pairwise and stores the
resulting scores in `tx_semanticsuggestion_similarities`. The frontend plugin only reads
that table, so displaying suggestions costs one indexed query.

📖 **Full manual: [Documentation/](Documentation/Index.rst)** — configuration reference,
multi-site setup, upgrade notes and troubleshooting.

## Requirements

| | |
|---|---|
| TYPO3 | 12.4 → 14.x |
| PHP | 8.1+ |
| Required extension | `cywolf/nlp-tools` ^2.0 (`nlp_tools`) |

`nlp_tools` is **not optional**: without it no vector can be built and no similarity is
ever stored. It is a declared dependency in both `composer.json` and `ext_emconf.php`,
so Composer and the Extension Manager pull it in for you.

## Installation

```bash
composer require talan-hdf/semantic-suggestion
vendor/bin/typo3 extension:setup --extension=semantic_suggestion
vendor/bin/typo3 cache:flush
```

Installing from the TER instead? Install `nlp_tools` first; the Extension Manager will
refuse to activate the extension without it.

**Upgrading from 3.x or 4.0.0?** Run the migration wizards, otherwise suggestions
disappear for any task that started on a subtree:

```bash
vendor/bin/typo3 upgrade:run semanticSuggestionMigrateRootPageId
vendor/bin/typo3 upgrade:run semanticSuggestionLabelAnalysisRows
```

See [Upgrade](Documentation/Upgrade/Index.rst) for what these do and how to verify them.

## Quick start

1. **Create the scheduler task** — *Scheduler → Add task → "Semantic Suggestion: Generate
   Similarities"*:

   | Field | Value |
   |---|---|
   | Start page ID | the site root (or any subtree to restrict the scope) |
   | Quality level | `0.1` — pairs scoring below this are not stored |
   | Exclude pages | optional list of UIDs, e.g. `42,56` |
   | Language | `-1` (all languages of the site, in one run) |

   Run it once manually. It logs to the TYPO3 log and to
   `typo3temp/logs/semantic_suggestion.log`.

2. **Display the suggestions** — either add the *Semantic Suggestions* content element to a
   page, or call the plugin from your Fluid page template:

   ```html
   <f:cObject typoscriptObjectPath="lib.semantic_suggestion" />
   ```

   Bootstrap Package users can instead enable the shipped page templates, **in that site's
   constants only**:

   ```typoscript
   plugin.tx_semanticsuggestion_suggestions.settings.overrideBootstrapTemplates = 1
   ```

3. **Check the result** in *Web → Semantic Suggestion*, which shows the stored pairs, the
   score distribution, the pages with the most links, and the **link opportunities**:
   similar pages that do not link to each other yet, orphan pages first.

## Configuration in one table

Everything under `plugin.tx_semanticsuggestion_suggestions.settings` (Constant Editor
category *semantic_suggestion*):

| Setting | Default | Effect |
|---|---|---|
| `qualityLevel` | `0.1` | Display filter: stored pairs scoring below it are not shown |
| `maxSuggestions` | `3` | Number of suggestions rendered |
| `excludePages` | *(empty)* | UIDs never shown **as** a suggestion |
| `excerptLength` | `100` | Characters of the excerpt |
| `excerptSources` | `bodytext,description,abstract` | First non-empty field wins |
| `recencyWeight` | `0.2` | How much a page updated in the last 30 days is favoured; never creates similarity |
| `analyzedFields.*` | `title 1.5`, `keywords 2.0`, `abstract 1.2`, `description 1.0`, `content 1.0` | Field weights: each term counts `weight × (1 + log(occurrences))`; `0` disables the field |
| `enableStemming` | `1` | Stem before vectorizing |
| `debugMode` | `0` | Verbose log **and** debug output in the rendered HTML |
| `overrideBootstrapTemplates` | `0` | Opt-in Bootstrap Package page templates |
| `show*` | `1` | Section toggles of the backend module, `showLinkOpportunities` included |

Two things are worth knowing before tuning:

- **Scores are low by nature.** Pages on the same topic typically score 0.1 to 0.3;
  `0.1` is a sane threshold, `0.05` on a site of short pages. Upgrading from 4.x? See
  the upgrade chapter: 5.0 scores are lower than 4.x ones.
- **Storage and display are separate thresholds.** The task's quality level decides what is
  written to the database; the TypoScript `qualityLevel` filters what is displayed among
  those rows. Setting the display value *below* the task's simply shows everything stored.

Details, and the settings that are accepted but currently ignored, are listed in
[Configuration](Documentation/Configuration/Index.rst).

## Embedding API (optional, 5.2)

By default pages are compared on their words (TF-IDF). An embedding API compares their
meaning instead: two pages about the same subject match even when they use different
words. Set it in *Admin Tools → Settings → Extension Configuration → semantic_suggestion
→ embedding*:

| Setting | Values |
|---|---|
| `embedding.provider` | `tfidf` (default, no API), `mistral`, `openai`, `custom` |
| `embedding.apiKey` | Key of the provider, or `env:VARIABLE_NAME` to read it from the environment |
| `embedding.model` | Empty for the default: `mistral-embed`, `text-embedding-3-small` |
| `embedding.endpoint` | `custom` only: any OpenAI-compatible URL, e.g. Ollama `http://host:11434/v1/embeddings` |
| `embedding.timeout` | Seconds per request, `30` |

Run the scheduler task again afterwards. What to know:

- **Page texts are sent to the provider** (title, description, keywords, abstract,
  content, first 8,000 characters). Mistral hosts in the EU; OpenAI in the US.
- **Cost is negligible.** Each page is sent once, then again only when its text changes;
  vectors are kept in `tx_semanticsuggestion_embeddings`. A 500-page site is well under
  a million tokens for the first run.
- **Scores keep the same scale.** Raw embedding scores run much higher than TF-IDF ones;
  they are centred and rescaled per site, so `qualityLevel` and the relevance labels keep
  their meaning. Sites under 8 pages stay on TF-IDF.
- **TF-IDF is the safety net.** If the API fails (key, quota, timeout), that run uses
  TF-IDF and logs a warning; the next run tries the API again.

See [Configuration](Documentation/Configuration/Index.rst#configuration-embedding).

## Multi-site

One task per site, `startPageId` on each site root. Suggestions can never cross a site
boundary: the frontend lookup filters on the stored `root_page_id`. Non-admin editors only
see the analyses of sites they have a webmount on. See
[Multi-site and multilingual](Documentation/MultiSite/Index.rst).

## Troubleshooting

```bash
# is the NLP stack working?
vendor/bin/typo3 semantic:diagnostic

# what was stored?
SELECT root_page_id, scope_page_id, sys_language_uid, COUNT(*), MAX(similarity_score)
FROM tx_semanticsuggestion_similarities GROUP BY 1, 2, 3;
```

Common causes of "no suggestions at all" and how to tell them apart:
[Troubleshooting](Documentation/Troubleshooting/Index.rst).

## Development

```bash
composer require --dev phpunit/phpunit typo3/testing-framework
vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite unit
```

The extension ships `phpunit.xml.dist` but declares no `require-dev`, so PHPUnit has to be
installed in the host project first.

## License, support

GPL-2.0-or-later — see [LICENSE](LICENSE). Changelog: [CHANGELOG.md](CHANGELOG.md).

- Author: Wolfangel Cyril — cyril.wolfangel@gmail.com
- Issues: <https://github.com/friteuseb/semantic_suggestion/issues>
