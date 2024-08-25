# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


# Changelog for Semantic Suggestion Extension


## Changelog 1.3.0

## New Features

### Server-Side Pagination

- Implemented server-side pagination for suggestions in `SuggestionsController.php`.
- Added pagination parameters (`$currentPage` and `$itemsPerPage`) to the `listAction()` method.
- Adapted `generateSuggestions()` and `findSimilarPages()` methods to support pagination.
- Added pagination information to the view for frontend rendering.

## Performance Improvements

### Optimization of Similarity Calculation

- Enhanced `calculateSimilarity()` method in `PageAnalysisService.php` for improved performance.
- Separated `calculateRecencyBoost()` method for better code readability and maintainability.

## Backend Enhancements

### Caching Implementation

- Introduced a caching system for analysis results in `SemanticBackendController.php`.

### Refactoring of `indexAction()`

- Restructured the method to utilize caching and improve overall readability.
- Extracted data processing logic into a new `processAnalysisData()` method.

### Error Handling Improvements

- Added try-catch blocks for enhanced error management and logging.

### TypoScript Integration

- Improved usage of TypoScript parameters such as `parentPageId`, `depth`, and `proximityThreshold`.

## Configuration and Flexibility

### New Configuration Parameters

- Added new TypoScript parameters to control statistics generation and other features:
  - `showStatistics`
  - `showPerformanceMetrics`
  - `showLanguageStatistics`
  - `calculateDistribution`
  - `calculateTopSimilarPairs`
- Updated `processAnalysisData()` method to handle these new configurations.

### Language-Specific Functionality

- Implemented support for language-specific features, including handling of translated pages.

## Code Quality and Maintenance

### Refactoring

- Improved code structure in `SuggestionsController.php` and `SemanticBackendController.php` for better maintainability.
- Enhanced method organization in `PageAnalysisService.php`.

## Known Issues and Future Improvements

- Further optimization of `getPages()` and `getPageTranslations()` methods may be needed for better performance.
- Some performance enhancements mentioned in the previous changelog, such as efficient data structures and set operations, may require additional implementation or verification.

---

This update significantly improves the extension's performance, flexibility, and robustness while ensuring more efficient handling of configurations and analyses.




## [1.2.0]

### Added new styles for the backend module, thanks to Renan Gauthier !

- New styles for the backend module, adopting the official style_guide, all made by Renan Gautier (Gloumi)

## [1.1.0]

### Added

- Advanced integration of NLP (Natural Language Processing) functionalities with the semantic_suggestion_nlp extension :
  - New `NlpAnalysisService` for NLP content analysis
  - `NlpAnalysisHook` for flexible integration of NLP analyses
  - Calculation of NLP statistics including text complexity, sentiment, and top keywords
- Option to toggle NLP analysis in extension settings


- Comprehensive unit tests for `SuggestionsController`
  - Tests for cache handling in list action
  - Verification of page analysis integration
  - Validation of view data assignment
  - Checks for proper HTTP response generation

- Recency-based boost system for page suggestions
  - Implemented a recency-based boost system for page suggestions
  - New `recencyWeight` parameter to control the importance of recency in similarity calculations
  - Added detailed information about similarity and recency in analysis results

  #### Changes
  
  - Major refactoring of `SemanticBackendController` for improved handling of classic and NLP analyses
  - Enhanced `preparePageData` method in `PageAnalysisService` to include NLP results
  - Updated hook system for better extensibility

  - Redesigned the `calculateSimilarity` method to better account for page recency
  - Improved the `calculateRecencyBoost` method for better normalization of page ages
  - Updated the `analyzePages` method to include more information in results
  - Update README.md to integrate an explicit but light documentation


  #### Fixes
  - Fixed various bugs related to similarity calculations
  - Improved error handling and added additional logging for easier debugging


## [1.0.8] - 2024-08-08

### Added
- New unit tests for improved code coverage
- Implemented more accurate similarity calculation between pages

### Changed
- Optimized performance of PageAnalysisService
- Refactored code for better readability and maintainability
- Updated inline code documentation

### Fixed
- Resolved 'pid' column ambiguity issue in SQL queries
- Corrected edge case handling in similarity calculations

### Security
- Improved handling of user inputs in database queries



## [1.0.7] - 2024-08-04
Enhanced excerpt generation for suggestions, providing more accurate and relevant content previews from various page elements.

### Added
- Support for Bootstrap Package content retrieval in excerpt generation
- Improved content analysis for more accurate semantic suggestions

### Changed
- Enhanced compatibility with various TYPO3 content structures
- Optimized content retrieval process for better performance
- Refactored code for improved maintainability and readability

### Improved
- Better handling of different page layouts and content elements
- Enhanced excerpt generation to include relevant page content
- Optimized backend module for better performance with large numbers of pages

### Fixed
- Resolved issues with content retrieval in specific TYPO3 setups
- Fixed potential performance bottlenecks in similarity calculations

### Security
- Improved content retrieval security by respecting hidden and deleted flags



## [1.0.6] - 2024-08-03
### Added

New performance metrics in the backend module for better analysis
Ability to exclude specific pages from suggestions via TypoScript configuration
Configuration option for excerpt length in suggestions

### Changed

Improved caching mechanism for faster analysis results
Updated TypoScript setup and constants with more detailed comments
Refactored PageAnalysisService for better performance

### Fixed

Issue with suggestion display in frontend when using cached results



## [1.0.4] - 2024-08-01

### Added
- Implemented a new visual representation of similarity scores using progress bars in the backend module.
- Added a distribution chart for similarity scores to provide a better overview of the analysis results.

### Changed
- Improved the calculation of top similar page pairs to ensure unique and accurate results.
- Enhanced the display of statistical data in the backend module for better readability and comprehension.
- Updated the template to use native Fluid operations instead of custom ViewHelpers for better compatibility.

### Fixed
- Corrected the algorithm for calculating the top 5 most similar page pairs to avoid repetition and ensure diversity in results.
- Fixed an issue where the distribution of similarity scores was not being calculated correctly.
- Resolved display issues in the backend module related to long page titles and inconsistent data presentation.

### Performance
- Optimized the calculation of similarity statistics to improve overall module performance.

### UI/UX
- Redesigned the backend module layout for a more compact and informative display of analysis results.
- Implemented responsive design improvements to ensure proper display on various screen sizes.

### Development
- Refactored the `calculateStatistics` method in `SemanticBackendController` for improved code clarity and efficiency.
- Updated Fluid templates to better handle edge cases and improve overall stability.


## [1.0.3] - 2024-07-31

### Removed
- Completely removed the dashboard widget to streamline the extension and focus on core functionalities.

### Added
- Implemented a comprehensive .gitignore file for better development file management.

### Changed
- Refactored and optimized the extension structure following the widget removal.


## [1.0.1] - 2023-07-07
### Changed
- Updated extension state from alpha to beta
- Improved README.md with a new Roadmap section

### Added
- CHANGELOG.md file to track changes
- Version and license information in composer.json

## [1.0.0] - 2023-07-01
### Added
- Initial release of the Semantic Suggestion extension
- Functionality to suggest semantically related pages in TYPO3
- Configuration options via TypoScript
- Support for TYPO3 v12.4