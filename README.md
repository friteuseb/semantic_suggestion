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
- Allows setting the parent page ID and proximity threshold
- Optimized performance by storing proximity scores in the database and updating them periodically

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
        analyzedFields {
            title = 1.5
            description = 1.0
            keywords = 2.0
            content = 1.0
        }
        recursive = 1
    }
}
```

## Usage

Insert the plugin "Semantic Suggestions" on the desired page using the TYPO3 backend.

To add the plugin directly in your Fluid template, use:

```html
<f:cObject typoscriptObjectPath="tt_content.list.20.semanticsuggestion_suggestions" />
```

## Similarity Logic

The extension uses a custom similarity calculation to determine related pages. Here is an overview of the logic:

1. **Data Gathering**: For each subpage of the specified parent page, the extension gathers the title, description, keywords, and content.
2. **Similarity Calculation**: The extension compares each pair of pages by calculating a similarity score based on the intersection and union of their words. The similarity score is the ratio of the number of common words to the total number of unique words.
3. **Proximity Threshold**: Pages with a similarity score above the configured threshold are considered related.
4. **Caching Scores**: To optimize performance, the calculated scores are stored in a database table `tx_semanticsuggestion_scores`. These scores are periodically updated or when the page content changes.

## File Structure and Logic

```
ext_semantics_suggestions/
├── Classes/
│   ├── Controller/
│   │   └── SuggestionsController.php         # Main controller handling the display logic
│   └── Service/
│       └── PageAnalysisService.php           # Service for analyzing pages and calculating similarity scores
│       └── Hooks/
│           └── DataHandlerHook.php           # Hook for updating scores when page content changes
├── Configuration/
│   └── TypoScript/
│       └── setup.typoscript                  # TypoScript configuration for the extension
├── Resources/
│   ├── Private/
│   │   ├── Language/
│   │   │   └── locallang_be.xlf              # Language file for backend labels
│   │   └── Templates/
│   │       └── Suggestions/
│   │           └── List.html                 # Fluid template for rendering suggestions
│   └── Public/
│       └── Icons/
│           └── Extension.svg                 # Icon for the extension
├── ext_tables.sql                            # SQL file for creating the necessary database table
├── composer.json                             # Composer configuration file
├── ext_emconf.php                            # Extension configuration file
├── ext_localconf.php                         # Extension local configuration file
├── ext_tables.php                            # Extension table configuration file
└── README.md                                 # This README file
```

## Detailed Classes

### SuggestionsController

The `SuggestionsController` is responsible for the display logic of suggestions. Key features:

- Uses dependency injection for `PageAnalysisService` and `FileRepository`.
- The `listAction()` method retrieves configuration parameters and uses the `PageAnalysisService` to analyze pages.
- The `findSimilarPages()` method filters similar pages based on the proximity threshold and maximum number of suggestions.

### PageAnalysisService

The `PageAnalysisService` is the core of the extension, responsible for page analysis and similarity calculation. Key features:

- Uses the `ConfigurationManager` to retrieve extension configuration parameters.
- The `analyzePages()` method recursively traverses subpages from a given parent page.
- Similarity calculation is based on a weighted approach of common words between pages.
- Takes into account the current language for page and content analysis.

## Similarity Calculation Method

The similarity calculation between two pages is performed as follows:

1. For each page, a weighted dictionary of words (unique words with their weight) is created.
2. The weight of each word is determined by the field in which it appears (title, description, keywords, content) and the configured weight for that field.
3. Similarity is calculated using the following formula:
   ```
   similarity = sum of weights of common words / sum of weights of all unique words
   ```
4. The result is a score between 0 and 1, where 1 indicates perfect similarity.

## Inserting the Plugin

### In the TYPO3 Backend

1. Create a new content element.
2. Choose the content type "Plugin".
3. Select the plugin "Semantic Suggestions".

### In a Fluid Template

```html
<f:cObject typoscriptObjectPath="tt_content.list.20.semanticsuggestion_suggestions" />
```

### Via TypoScript

```typoscript
lib.semanticSuggestions = USER
lib.semanticSuggestions {
    userFunc = TYPO3\CMS\Extbase\Core\Bootstrap->run
    extensionName = SemanticSuggestion
    pluginName = Suggestions
}
```

Then in your Fluid template:

```html
<f:cObject typoscriptObjectPath="lib.semanticSuggestions" />
```

## Security Considerations

1. **SQL Injection**: The extension uses TYPO3's secure query mechanisms (QueryBuilder) to prevent SQL injections.
2. **XSS**: Outputs in Fluid templates are automatically escaped to prevent XSS attacks.
3. **CSRF**: Forms in the backend use TYPO3's CSRF tokens for protection.
4. **Access Control**: Access to the backend module is restricted to users with appropriate permissions.
5. **Input Validation**: All user inputs are validated and sanitized before use.

## Performance and Optimization

- The extension stores similarity scores in the database to avoid repeated calculations.
- Scores are updated periodically or when page content changes.
- For large sites, consider increasing the frequency of score updates or implementing a more advanced caching system.

## Subtleties and Tips

- The extension takes into account TYPO3's multilingual structure by analyzing pages in the current language.
- Similarity calculation can be adjusted by modifying the weights of different fields in the TypoScript configuration.
- For optimal results, ensure your pages have well-filled titles, descriptions, and keywords.

## Roadmap

We have exciting plans for improving and expanding the Semantic Suggestion extension. Here are some key areas we're focusing on:

1. **Performance Improvements**
   - Implement caching for similarity scores
   - Optimize database queries

2. **Feature Enhancements**
   - Support for multiple parent pages
   - Customizable similarity algorithms
   - Enhanced media handling
   - Improved excerpt generation

3. **UI/UX Improvements**
   - Ensure fully responsive design
   - Implement pagination for suggestions

4. **Code Quality**
   - Expand unit test coverage
   - Improve code documentation

5. **Compatibility and Maintenance**
   - Ensure compatibility with future TYPO3 versions
   - Maintain backward compatibility where possible

6. **Community and Support**
   - Expand user documentation
   - Set up additional support channels

For a full list of planned improvements and feature ideas, please check the [IMPROVEMENTS.md](IMPROVEMENTS.md) file in our repository.

We welcome community input and contributions to help shape the future of this extension!

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

For additional documentation and updates, visit our [GitHub repository](https://github.com/your-username/semantic-suggestion).4. **Code Quality**
   - Expand unit test coverage
   - Improve code documentation

5. **Compatibility and Maintenance**
   - Ensure compatibility with future TYPO3 versions
   - Maintain backward compatibility where possible

6. **Community and Support**
   - Expand user documentation
   - Set up additional support channels

For a full list of planned improvements and feature ideas, please check the [IMPROVEMENTS.md](IMPROVEMENTS.md) file in our repository.

We welcome community input and contributions to help shape the future of this extension!

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