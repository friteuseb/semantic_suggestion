# TYPO3 Extension: Semantic Suggestion

[![TYPO3 12](https://img.shields.io/badge/TYPO3-12-orange.svg)](https://get.typo3.org/version/12)
[![TYPO3 13](https://img.shields.io/badge/TYPO3-13-orange.svg)](https://get.typo3.org/version/13)
[![Latest Stable Version](https://img.shields.io/packagist/v/talan-hdf/semantic-suggestion.svg)](https://packagist.org/packages/talan-hdf/semantic-suggestion)
[![License](https://img.shields.io/packagist/l/talan-hdf/semantic-suggestion.svg)](https://packagist.org/packages/talan-hdf/semantic-suggestion)

This extension provides a plugin for TYPO3 v12 that suggests semantically related pages. Semantic suggestion enhances user experience by automatically recommending relevant content, increasing engagement and time spent on your website.

## Introduction

Semantic Suggestion analyzes the content of your pages and creates intelligent connections between them. By understanding the context and meaning of your content, it offers visitors related pages that are truly relevant to their interests, improving navigation and content discovery.


### Frontend View
![Frontend view with the same theme](Documentation/Medias/frontend_on_the_same_theme_view.jpg)


## Requirements

- TYPO3 12.0.0-13.9.99
- PHP 8.0 or higher

## Features

- Analyzes subpages of a specified parent page
- Displays title, associated media, and enhanced text excerpt of suggested pages
- Configurable via TypoScript
- Allows setting the parent page ID, proximity threshold, and search depth
- Optimized performance by storing proximity scores in the database and updating them periodically
- Built-in multilingual support
- Improved compatibility with various TYPO3 content structures, including Bootstrap Package
- Option to exclude specific pages from analysis and suggestions

## Installation

### Composer Installation (recommended)

1. Install the extension via composer:
   ```
   composer require talan-hdf/semantic-suggestion
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
        excludePages = 8,9,3456
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
- `excludePages`: Comma-separated list of page UIDs to exclude from analysis and suggestions


## Usage

Insert the plugin "Semantic Suggestions" on the desired page using the TYPO3 backend.
To add the plugin directly in your Fluid template, use:

```html
<f:cObject typescriptObjectPath="lib.semantic_suggestion" />
```

## Integration in Fluid Templates with custom Viewhelper

The Semantic Suggestion extension now offers a custom ViewHelper for easy integration into your Fluid templates. This provides a flexible way to display semantic suggestions directly in your pages.

### Using the SuggestionsViewHelper

1. First, declare the namespace for the ViewHelper at the top of your Fluid template:

   ```html
   {namespace semanticSuggestion=TalanHdf\SemanticSuggestion\ViewHelpers}
   ```

2. You can then use the ViewHelper in your template as follows:

   ```html
   <semanticSuggestion:suggestions 
       pageUid="{data.uid}" 
       parentPageId="1" 
       proximityThreshold="0.3" 
       maxSuggestions="5" 
       depth="1">
       <!-- Your custom rendering here -->
   </semanticSuggestion:suggestions>
   ```

   The ViewHelper accepts the following arguments:
   - `pageUid` (required): The UID of the current page
   - `parentPageId` (optional, default: 0): The parent page ID to start the analysis from
   - `proximityThreshold` (optional, default: 0.3): The threshold for considering pages as similar
   - `maxSuggestions` (optional, default: 5): The maximum number of suggestions to display
   - `depth` (optional, default: 0): The depth of the page tree to analyze

3. Inside the ViewHelper tags, you can customize how the suggestions are rendered. Here's an example:

   ```html
   <semanticSuggestion:suggestions pageUid="{data.uid}" parentPageId="1" proximityThreshold="0.3" maxSuggestions="5" depth="1">
       <f:if condition="{suggestions}">
           <f:then>
               <ul>
                   <f:for each="{suggestions}" as="suggestion">
                       <li>
                           <f:link.page pageUid="{suggestion.data.uid}">
                               {suggestion.data.title}
                           </f:link.page>
                           <p>Similarity: {suggestion.similarity -> f:format.number(decimals: 2)}</p>
                       </li>
                   </f:for>
               </ul>
           </f:then>
           <f:else>
               <p>No related pages found.</p>
           </f:else>
       </f:if>
   </semanticSuggestion:suggestions>
   ```

   This example creates an unordered list of related pages, displaying the title and similarity score for each suggestion.

### Benefits of Using the ViewHelper

1. **Flexibility**: You can easily customize the output directly in your Fluid templates.
2. **Performance**: The ViewHelper handles caching and efficient data retrieval internally.
3. **Ease of Use**: No need to modify controllers or create new templates - simply add the ViewHelper where you want the suggestions to appear.
4. **Configurability**: All major parameters can be adjusted directly in the template, allowing for easy customization per page or section.

Remember to clear the TYPO3 cache after adding the ViewHelper to your templates for the changes to take effect.


## Similarity Logic

The extension uses a custom similarity calculation to determine related pages. Here is an overview of the logic:

1. **Data Gathering**: For each subpage of the specified parent page, the extension gathers the title, description, keywords, and content.
2. **Similarity Calculation**: The extension compares each pair of pages by calculating a similarity score based on the intersection and union of their words. The similarity score is the ratio of the number of common words to the total number of unique words, weighted by the importance of each field.
3. **Proximity Threshold**: Only pages with a similarity score above the configured threshold are considered related and displayed.
4. **Caching Scores**: To optimize performance, the calculated scores are stored in a database table `tx_semanticsuggestion_scores`. These scores are periodically updated or when the page content changes.


## Backend Module

![Backend module](Documentation/Medias/backend_module.png)

The Semantic Suggestion extension now includes a powerful backend module that provides comprehensive insights into the semantic relationships between your pages. This module offers the following features:

- **Similarity Analysis**: Visualize the semantic similarity between pages in your TYPO3 installation.
- **Top Similar Pairs**: Quickly identify the most semantically related page pairs.
- **Distribution of Similarity Scores**: Get a clear overview of how similarity is distributed across your content.
- **Configurable Analysis**: Set custom parameters such as parent page ID, analysis depth, and similarity thresholds.
- **Visual Representation**: Utilize progress bars and charts for an intuitive understanding of the data.
- **Detailed Statistics**: View in-depth statistics about page similarities and content relationships.

To access the module, navigate to the backend and look for "Semantic Suggestion" under the Web menu. This tool is invaluable for content managers and editors looking to optimize content structure, improve internal linking, and understand the thematic relationships within their website.

Note: The effectiveness of the semantic analysis depends on the quality and quantity of your content. For best results, ensure your pages have meaningful titles, descriptions, and content.

##  Backend Module - Performance Metrics

The Semantic Suggestion extension provides,  through the backend module, performance metrics to help you understand and optimize its operation. Here's an explanation of each metric:

![Backend module](Documentation/Medias/backend_module_performance_metrics.jpg)

### Execution Time (seconds)

**Calculation:** This is the total time taken to perform the semantic analysis, including page retrieval, similarity calculations, and caching operations.

**Interpretation:** 
- A lower value indicates faster execution.
- If consistently high, consider optimizing your content structure or increasing the caching duration.
- Note: When results are from cache, this value may appear as 0.00 seconds.

### Total Pages Analyzed

**Calculation:** The total number of pages included in the semantic analysis.

**Interpretation:**
- This number depends on your page tree structure and the configured depth of analysis.
- A higher number may lead to more accurate suggestions but can increase execution time.

### Similarity Calculations

**Calculation:** The total number of page-to-page similarity comparisons performed.

**Interpretation:**
- This is typically calculated as `n * (n-1) / 2`, where `n` is the number of pages analyzed.
- A higher number indicates more comprehensive analysis but may impact performance.

### Results from Cache

**Calculation:** A boolean indicator (Yes/No) showing whether the results were retrieved from cache.

**Interpretation:**
- "Yes" indicates that the results were retrieved from cache, resulting in faster execution.
- "No" means a fresh analysis was performed.
- Frequent "No" results might indicate that your cache is being cleared too often or that your content is changing frequently.

### Optimizing Performance

1. **Caching:** Ensure your caching configuration is appropriate for your update frequency.
2. **Analysis Depth:** Adjust the analysis depth to balance between comprehensive results and performance.
3. **Excluded Pages:** Use the `excludePages` setting to omit irrelevant pages from analysis.
4. **Content Structure:** Organize your content to minimize the number of pages that need to be analyzed without compromising suggestion quality.

By monitoring these metrics, you can fine-tune the extension's configuration to achieve the best balance between performance and suggestion accuracy for your specific use case.


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
- Optimized content retrieval process for improved performance with large numbers of pages
- Efficient handling of excluded pages to reduce unnecessary calculations
- Improved caching mechanisms for faster retrieval of analysis results
- Batch processing of page analysis to manage server load effectively

## File Structure and Logic

```
semantic_suggestion/
├── Classes/
│   ├── Controller/
│   │   ├── SemanticBackendController.php
│   │   └── SuggestionsController.php
│   └── Service/
│       └── PageAnalysisService.php
├── Configuration/
│   ├── Backend/
│   │   ├── Modules.php
│   │   └── Routes.php
│   ├── TCA/
│   │   └── Overrides/
│   │       ├── sys_template.php
│   │       └── tt_content.php
│   ├── TypoScript/
│   │   ├── constants.typoscript
│   │   └── setup.typoscript
│   └── Services.yaml
├── Documentation/
│   ├── Index.rst
│   ├── Installation/
│   │   └── Index.rst
│   ├── Introduction/
│   │   └── Index.rst
│   └── Medias/
│       ├── backend_module.png
│       ├── backend_module_performance_metrics.jpg
│       └── frontend_on_the_same_theme_view.jpg
├── Resources/
│   ├── Private/
│   │   ├── Language/
│   │   │   ├── locallang.xlf
│   │   │   ├── locallang_be.xlf
│   │   │   ├── locallang_mod.xlf
│   │   │   └── locallang_semanticproximity.xlf
│   │   ├── Layouts/
│   │   │   └── Default.html
│   │   └── Templates/
│   │       ├── SemanticBackend/
│   │       │   ├── Index.html
│   │       │   └── List.html
│   │       └── Suggestions/
│   │           └── List.html
│   └── Public/
│       ├── Css/
│       │   └── SemanticSuggestion.css
│       └── Icons/
│           ├── Extension.svg
│           ├── module-semantic-suggestion.svg
│           └── user_mod_semanticproximity.svg
├── Tests/
│   ├── Fixtures/
│   │   └── pages.xml
│   ├── Integration/
│   │   └── Service/
│   │       └── PageAnalysisServiceIntegrationTest.php
│   └── Unit/
│       └── Service/
│           └── PageAnalysisServiceTest.php
├── .env
├── .gitignore
├── CHANGELOG.md
├── IMPROVEMENTS.MD
├── LICENSE
├── README.md
├── ROADMAP_TO_STABLE.md
├── composer.json
├── ext_conf_template.txt
├── ext_emconf.php
├── ext_localconf.php
├── ext_tables.php
└── phpunit.xml.dist
```



## Unit Tests

The Semantic Suggestion extension includes a comprehensive suite of unit tests to ensure the reliability and correctness of its core functionalities. These tests are crucial for maintaining the quality of the extension and facilitating future development.

### Test Coverage

Our unit tests cover the following key areas:

1. **Page Data Preparation**: Ensures that page data is correctly prepared and weighted for analysis.
2. **Page Analysis**: Verifies the overall page analysis process, including caching mechanisms.
3. **Similarity Calculation**: Tests the accuracy of similarity calculations between different pages.
4. **Common Keywords Detection**: Checks the functionality for finding common keywords between pages.
5. **Relevance Determination**: Validates the logic for determining the relevance level based on similarity scores.
6. **Performance Testing**: Evaluates the service's ability to handle large datasets efficiently.
7. **Cache Handling**: Verifies proper use of caching mechanisms for improved performance.
8. **Edge Case Handling**: Tests the service's behavior with empty pages and extremely large content.
9. **Content Size Limits**: Checks if appropriate size limits are applied to different fields.

### List of Test Functions

1. **setUp()**: Initializes mocks and test environment.
2. **testPreparePageData()**: Verifies correct page data preparation and weighting.
3. **testAnalyzePages()**: Ensures expected results and proper cache usage.
4. **testCalculateSimilarity()**: Checks consistency of similarity calculations.
5. **testFindCommonKeywords()**: Validates common keyword detection.
6. **testDetermineRelevance()**: Confirms accurate relevance determination.
7. **testPerformanceWithLargeDataset()**: Evaluates efficiency with large datasets.
8. **testAnalyzePagesWithCacheHit()**: Verifies correct cache usage.
9. **testPageWithoutContent()**: Ensures proper handling of empty pages.
10. **testPageWithVeryLargeContent()**: Checks handling of large text volumes.
11. **testContentSizeLimits()**: Validates content size limit enforcement.



## Unit Tests for SuggestionsController


   ```bash
   ddev exec vendor/bin/phpunit -c vendor/typo3/testing-framework/Resources/Core/Build/UnitTests.xml packages/semantic_suggestion/Tests/Unit/ --testdox --colors=always
   ```

### Overview
The unit tests for the `SuggestionsController` are designed to ensure the proper functioning of the semantic suggestion feature in our TYPO3 extension. These tests focus on verifying the controller's behavior in various scenarios, particularly in handling cache and generating page suggestions.

### Test Objectives

1. **Cache Handling**
   - Verify that the controller correctly uses cached data when available.
   - Ensure that the controller generates new suggestions when cache is not available.

2. **Page Analysis Integration**
   - Test the integration between the controller and the `PageAnalysisService`.
   - Confirm that page analysis results are correctly processed and passed to the view.

3. **View Interaction**
   - Validate that the controller correctly assigns data to the view.
   - Ensure that the assigned data matches the expected format and content.

4. **Response Generation**
   - Verify that the controller always returns a valid `ResponseInterface` object.

5. **Error Handling**
   - Test the controller's behavior in edge cases and error scenarios.

### Key Test Cases

1. **Cache Hit Scenario**
   - Checks if the controller retrieves and uses cached suggestions correctly.

2. **Cache Miss Scenario**
   - Verifies that the controller generates new suggestions when cache is empty.
   - Ensures proper interaction with `PageAnalysisService` for fresh data.

3. **Data Assignment to View**
   - Tests if the correct data is assigned to the view in both cache hit and miss scenarios.

4. **Response Validation**
   - Confirms that all controller actions return a valid HTTP response.

### Benefits

- Ensures reliability of the semantic suggestion feature.
- Facilitates easier maintenance and future development.
- Helps catch potential issues early in the development cycle.
- Provides documentation of expected controller behavior.



This comprehensive set of tests ensures that our PageAnalysisService is robust, efficient, and capable of handling various scenarios, from normal operations to edge cases.


### Running the Tests

To run the unit tests, follow these steps:

1. Ensure you have a development environment set up with DDEV.
2. Open a terminal and navigate to your project root.
3. Run the following command:

   ```bash
   ddev exec vendor/bin/phpunit -c packages/semantic_suggestion/phpunit.xml.dist --testdox --colors=always
   ```

   This command will execute all unit tests and provide a detailed, color-coded output of the results.

4. To run a specific test, you can add the test method name to the command:

   ```bash
   ddev exec vendor/bin/phpunit -c packages/semantic_suggestion/phpunit.xml.dist --filter testMethodName
   ```

   Replace `testMethodName` with the name of the specific test you want to run (e.g., `testCalculateSimilarity`).

### Interpreting Test Results

- Green checkmarks (✔) indicate passed tests.
- Red crosses (✘) indicate failed tests.
- Yellow exclamation marks (⚠) indicate risky or incomplete tests.

The test output will provide detailed information about any failures or issues, helping you quickly identify and address problems.

Regular execution of these tests is recommended, especially after making changes to the codebase, to ensure continued functionality and to catch any regressions early in the development process.


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