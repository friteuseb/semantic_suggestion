# TYPO3 Extension: Semantic Suggestion

[![TYPO3 12](https://img.shields.io/badge/TYPO3-12-orange.svg)](https://get.typo3.org/version/12)

This extension provides a plugin for TYPO3 v12 that suggests semantically related pages. Semantic suggestion enhances user experience by automatically recommending relevant content, increasing engagement and time spent on your website.

## Introduction

Semantic Suggestion analyzes the content of your pages and creates intelligent connections between them. By understanding the context and meaning of your content, it offers visitors related pages that are truly relevant to their interests, improving navigation and content discovery.

## Requirements

- TYPO3 12.4.0-12.4.99
- PHP 8.0 or higher

## Features

- Analyzes subpages of a specified parent page
- Displays title, associated media, and text excerpt of suggested pages
- Configurable via TypoScript
- Allows setting the parent page ID, proximity threshold, and search depth
- Optimized performance by storing proximity scores in the database and updating them periodically
- Built-in multilingual support

## Installation

### Composer Installation (recommended)

1. Install the extension via composer:
   ```
   composer require talan/semantic-suggestion
   ```

2. Activate the extension in the TYPO3 Extension Manager

### Manual Installation

1. Download the extension from the [TYPO3 Extension Repository (TER)](https://extensions.typo3.org/) or the GitHub repository.
2. Upload the extension file to your TYPO3 installation's `typo3conf/ext/` directory.
3. In the TYPO3 backend, go to the Extension Manager and activate the "Semantic Suggestion" extension.


## Configuration

Edit your TypoScript setup and adjust the following parameters:

```typoscript
plugin.tx_semanticsuggestion {
    settings {
        parentPageId = 1
        proximityThreshold = 0.7
        maxSuggestions = 3
        excerptLength = 150
        recursive = 1
        analyzedFields {
            title = 1.5
            description = 1.0
            keywords = 2.0
            abstract = 1.2
            content = 1.0
        }
    }
}
```

### Weight System for Analyzed Fields

The `analyzedFields` section allows you to configure the importance of different content fields in the similarity calculation. Here's how the weight system works:

- Weights can be any positive number.
- A weight of 1.0 is considered standard importance.
- Weights greater than 1.0 increase a field's importance.
- Weights less than 1.0 decrease a field's importance.
- There is no strict maximum; you can use values like 3.0 or higher for fields you consider extremely important.

Example weight ranges:
- 0.5: Half as important as standard
- 1.0: Standard importance
- 1.5: 50% more important than standard
- 2.0: Twice as important as standard
- 3.0 and above: Significantly more important than standard

Adjust these weights based on your specific content structure and similarity requirements. For example, if titles are crucial for determining similarity in your case, you might increase the title weight to 2.0 or higher.

### Configuration Parameters

- `parentPageId`: The ID of the parent page from which the analysis starts
- `proximityThreshold`: The minimum similarity threshold for displaying a suggestion (0.0 to 1.0)
- `maxSuggestions`: The maximum number of suggestions to display
- `excerptLength`: The maximum length of the text excerpt for each suggestion
- `recursive`: The search depth in the page tree (0 = only direct children)


## Usage

Insert the plugin "Semantic Suggestions" on the desired page using the TYPO3 backend.

To add the plugin directly in your Fluid template, use:

```html
<f:cObject typescriptObjectPath="tt_content.list.20.semanticsuggestion_suggestions" />
```

Or use the defined library:

```html
<f:cObject typescriptObjectPath="lib.semantic_suggestion" />
```

## Similarity Logic

The extension uses a custom similarity calculation to determine related pages. Here is an overview of the logic:

1. **Data Gathering**: For each subpage of the specified parent page, the extension gathers the title, description, keywords, and content.
2. **Similarity Calculation**: The extension compares each pair of pages by calculating a similarity score based on the intersection and union of their words. The similarity score is the ratio of the number of common words to the total number of unique words, weighted by the importance of each field.
3. **Proximity Threshold**: Only pages with a similarity score above the configured threshold are considered related and displayed.
4. **Caching Scores**: To optimize performance, the calculated scores are stored in a database table `tx_semanticsuggestion_scores`. These scores are periodically updated or when the page content changes.

## Display Customization

The Fluid template (List.html) can be customized to adapt the display of suggestions to your needs. You can override this template by configuring your own template paths in TypoScript.

## Multilingual Support

The extension supports TYPO3's multilingual structure. It analyzes and suggests pages in the current site language.

## Debugging and Maintenance

The extension uses TYPO3's logging system. You can configure logging to get more information about the analysis and suggestion process.

## Security

- Protection against SQL injections through the use of TYPO3's secure query mechanisms (QueryBuilder)
- Protection against XSS attacks thanks to automatic output escaping in Fluid templates
- Access control restricted to users with appropriate permissions

## Performance

- Storage of similarity scores in the database to avoid repeated calculations
- Periodic update of scores or when page content changes

## File Structure and Logic

```
ext_semantic_suggestions/
├── Classes/
│   ├── Controller/
│   │   └── SuggestionsController.php
│   └── Service/
│       └── PageAnalysisService.php
│       └── Hooks/
│           └── DataHandlerHook.php
├── Configuration/
│   └── TypoScript/
│       └── setup.typoscript
├── Resources/
│   ├── Private/
│   │   ├── Language/
│   │   │   └── locallang.xlf
│   │   └── Templates/
│   │       └── Suggestions/
│   │           └── List.html
│   └── Public/
│       └── Icons/
│           └── Extension.svg
├── ext_emconf.php
├── ext_localconf.php
└── ext_tables.php
```

## Contributing

Contributions to the Semantic Suggestion extension are welcome! Here's how you can contribute:

1. Fork the repository
2. Create a new branch for your feature or bug fix
3. Make your changes and commit them with a clear commit message
4. Push your changes to your fork
5. Submit a pull request to the main repository

Please make sure to follow the existing coding standards and include appropriate tests for your changes.

## License

This project is licensed under the GNU General Public License v2.0 or later. See the [LICENSE](LICENSE) file for more details.

## Support

For support and further information, please contact:

Wolfangel Cyril  
Email: cyril.wolfangel@gmail.com

For bug reports and feature requests, please use the [GitHub issue tracker](https://github.com/your-username/semantic-suggestion/issues).

For additional documentation and updates, visit our [GitHub repository](https://github.com/your-username/semantic-suggestion).