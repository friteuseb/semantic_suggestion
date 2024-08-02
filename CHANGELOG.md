# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


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