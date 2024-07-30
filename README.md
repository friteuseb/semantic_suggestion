# TYPO3 Extension: Semantic Suggestion

This extension provides a plugin for TYPO3 v12 that suggests up to 3 semantically related pages.

## Features

- Analyzes subpages of a specified parent page
- Displays title, associated media, and text excerpt of suggested pages
- Configurable via TypoScript
- Allows setting the parent page ID and proximity threshold
- Optimized performance by storing proximity scores in the database and updating them periodically

## Installation

1. Install the extension via composer:
   ```
   composer require talan/semantic-suggestion
   ```

2. Activate the extension in the TYPO3 Extension Manager

## Configuration

Edit your TypoScript setup and adjust the following parameters:

```
plugin.tx_semanticsuggestion {
    settings {
        parentPageId = 1
        proximityThreshold = 0.7
        maxSuggestions = 3
        excerptLength = 150
    }
}
```

## Usage

Insert the plugin "Semantic Suggestions" on the desired page using the TYPO3 backend.

To add the plugin directly in your fluid template, add   
```
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

### Detailed File Descriptions

- **Classes/Controller/SuggestionsController.php**: Handles the logic for retrieving and displaying related pages based on calculated similarity scores.
- **Classes/Service/PageAnalysisService.php**: Contains the logic for analyzing pages, calculating similarity scores, and interacting with the database.
- **Classes/Hooks/DataHandlerHook.php**: Hook to recalculate similarity scores when the content of a page is modified.
- **Configuration/TypoScript/setup.typoscript**: Contains the TypoScript setup configuration for the extension.
- **Resources/Private/Templates/Suggestions/List.html**: Fluid template for rendering the list of suggested pages.
- **ext_tables.sql**: SQL script to create the `tx_semanticsuggestion_scores` table for storing similarity scores.
- **composer.json**: Composer configuration file for managing the extension dependencies.
- **ext_emconf.php**: Metadata about the extension, such as title, description, and version.
- **ext_localconf.php**: Registers the plugin and hooks with TYPO3.
- **ext_tables.php**: Registers the TypoScript static template with TYPO3.
- **README.md**: Documentation file (this file).

## Support

For support and further information, please contact:

Wolfangel Cyril  
Email: cyril.wolfangel@gmail.com
