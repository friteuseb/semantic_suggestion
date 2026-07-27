<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Task;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\AbstractAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class GenerateSimilaritiesAdditionalFieldProvider extends AbstractAdditionalFieldProvider
{
    /**
     * Gets additional fields to render in the scheduler backend module
     */
    public function getAdditionalFields(array &$taskInfo, $task, SchedulerModuleController $schedulerModule): array
    {
        $additionalFields = [];
        
        // Champ pour startPageId
        if (empty($taskInfo['startPageId'])) {
            if ($task instanceof GenerateSimilaritiesTask) {
                $taskInfo['startPageId'] = $task->startPageId;
            } else {
                $taskInfo['startPageId'] = 1; // Valeur par défaut
            }
        }
        
        $fieldId = 'task_startPageId';
        $fieldCode = '<input type="number" class="form-control" name="tx_scheduler[startPageId]" id="' . $fieldId . '" value="' . (int)$taskInfo['startPageId'] . '" />';
        $additionalFields[$fieldId] = [
            'code' => $fieldCode,
            'label' => LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.task.start_page_id', 'semantic_suggestion') ?? 'Start Page ID (Analysis starting point)',
            'cshKey' => '_MOD_system_txschedulerM1',
            'cshLabel' => $fieldId
        ];
        
        // Champ pour excludePages
        if (empty($taskInfo['excludePages'])) {
            if ($task instanceof GenerateSimilaritiesTask) {
                $taskInfo['excludePages'] = $task->excludePages;
            } else {
                $taskInfo['excludePages'] = ''; // Valeur par défaut
            }
        }
        
        $fieldId = 'task_excludePages';
        $fieldCode = '<input type="text" class="form-control" name="tx_scheduler[excludePages]" id="' . $fieldId . '" value="' . htmlspecialchars($taskInfo['excludePages']) . '" placeholder="ex: 42,56,78" />';
        $additionalFields[$fieldId] = [
            'code' => $fieldCode,
            'label' => LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.task.exclude_pages', 'semantic_suggestion') ?? 'Pages to exclude (comma separated)',
            'cshKey' => '_MOD_system_txschedulerM1',
            'cshLabel' => $fieldId
        ];
        
        // NEW: Field for recursiveExclusion
        if (!isset($taskInfo['recursiveExclusion'])) {
            if ($task instanceof GenerateSimilaritiesTask) {
                $taskInfo['recursiveExclusion'] = $task->recursiveExclusion;
            } else {
                $taskInfo['recursiveExclusion'] = true; // Default: recursive
            }
        }
        
        $fieldId = 'task_recursiveExclusion';
        $checked = $taskInfo['recursiveExclusion'] ? 'checked="checked"' : '';
        $fieldCode = '<div class="form-check">
            <input type="checkbox" class="form-check-input" name="tx_scheduler[recursiveExclusion]" id="' . $fieldId . '" value="1" ' . $checked . ' />
            <label class="form-check-label" for="' . $fieldId . '">
                ' . (LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.task.recursive_exclusion.help', 'semantic_suggestion') ?? 'Exclude recursively sub-pages') . '
            </label>
        </div>
        <small class="form-text text-muted">
            ' . (LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.task.recursive_exclusion.description', 'semantic_suggestion') ?? 'If checked: exclude the page AND all its sub-pages<br>If unchecked: exclude only the page, but analyze its sub-pages') . '
        </small>';
        
        $additionalFields[$fieldId] = [
            'code' => $fieldCode,
            'label' => LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.task.recursive_exclusion', 'semantic_suggestion') ?? 'Recursive exclusion',
            'cshKey' => '_MOD_system_txschedulerM1',
            'cshLabel' => $fieldId
        ];
        
        // NEW: Quality Level (unified configuration)
        if (!isset($taskInfo['qualityLevel'])) {
            if ($task instanceof GenerateSimilaritiesTask) {
                $taskInfo['qualityLevel'] = $task->qualityLevel;
            } else {
                $taskInfo['qualityLevel'] = 0.3; // Default quality level
            }
        }

        $fieldId = 'task_qualityLevel';
        // Storage threshold equals the quality level, floored — no offset.
        $storageThreshold = max(GenerateSimilaritiesTask::MINIMUM_STORAGE_THRESHOLD, (float)$taskInfo['qualityLevel']);
        $displayThreshold = (float)$taskInfo['qualityLevel'];

        $fieldCode = '<div class="form-group">
            <input type="number" class="form-control" name="tx_scheduler[qualityLevel]" id="' . $fieldId . '" value="' . number_format((float)$taskInfo['qualityLevel'], 2) . '" step="0.01" min="0.1" max="1" />
            <small class="form-text text-muted">
                Defines the minimum similarity score for storing page pairs.<br>
                Storage threshold = Quality Level (direct mapping).<br>
                Example: 0.3 stores pairs ≥ 0.3, including high similarities like 0.85.<br>
                Higher values = better performance but fewer stored pairs.<br>
                <em>Note: Display filtering is controlled separately in TypoScript.</em>
            </small>
        </div>';

        $additionalFields[$fieldId] = [
            'code' => $fieldCode,
            'label' => LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.task.storage_quality_level', 'semantic_suggestion') ?? 'Storage Quality Level (0.1-1.0) - Controls what similarities get stored in database',
            'cshKey' => '_MOD_system_txschedulerM1',
            'cshLabel' => $fieldId
        ];

        // NEW: Language ID field
        if (!isset($taskInfo['languageId'])) {
            if ($task instanceof GenerateSimilaritiesTask) {
                $taskInfo['languageId'] = $task->languageId;
            } else {
                $taskInfo['languageId'] = -1; // Default: all languages
            }
        }

        $fieldId = 'task_languageId';
        $fieldCode = '<div class="form-group">
            <input type="number" class="form-control" name="tx_scheduler[languageId]" id="' . $fieldId . '" value="' . (int)$taskInfo['languageId'] . '" min="-1" />
            <small class="form-text text-muted">
                Language ID to process (-1 = all languages, 0 = default, 1 = first language, etc.)<br>
                Use this to limit processing to a specific language for better performance.
            </small>
        </div>';

        $additionalFields[$fieldId] = [
            'code' => $fieldCode,
            'label' => 'Language ID (-1 for all languages)',
            'cshKey' => '_MOD_system_txschedulerM1',
            'cshLabel' => $fieldId
        ];

        // Legacy support: minimumSimilarity is derived, never entered by the user
        if (!isset($taskInfo['minimumSimilarity'])) {
            $taskInfo['minimumSimilarity'] = $task instanceof GenerateSimilaritiesTask
                ? $task->getStorageThreshold()
                : max(GenerateSimilaritiesTask::MINIMUM_STORAGE_THRESHOLD, (float)$taskInfo['qualityLevel']);
        }

        return $additionalFields;
    }
    
    /**
     * Validates the additional fields' values
     */
    public function validateAdditionalFields(array &$submittedData, SchedulerModuleController $schedulerModule): bool
    {
        $result = true;
        
        // Validation de startPageId
        if ((int)$submittedData['startPageId'] <= 0) {
            $schedulerModule->addMessage(
                LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.validation.invalid_start_page', 'semantic_suggestion') ?? 'The start page ID must be a positive integer.',
                FlashMessage::ERROR
            );
            $result = false;
        }
        
        // Validation de qualityLevel
        $qualityLevel = isset($submittedData['qualityLevel']) ? (float)$submittedData['qualityLevel'] : 0.3;
        if ($qualityLevel < 0.1 || $qualityLevel > 1) {
            $schedulerModule->addMessage(
                LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.validation.invalid_quality_level', 'semantic_suggestion') ?? 'The quality level must be between 0.1 and 1.0.',
                FlashMessage::ERROR
            );
            $result = false;
        }

        // Legacy validation: minimumSimilarity (computed, no user input needed)
        if (isset($submittedData['minimumSimilarity'])) {
            $minimumSimilarity = (float)$submittedData['minimumSimilarity'];
            if ($minimumSimilarity < 0 || $minimumSimilarity > 1) {
                $schedulerModule->addMessage(
                    LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.validation.invalid_similarity', 'semantic_suggestion') ?? 'The similarity threshold must be a value between 0 and 1.',
                    FlashMessage::ERROR
                );
                $result = false;
            }
        }
        
        // Validation de excludePages
        if (!empty($submittedData['excludePages'])) {
            $excludePages = GeneralUtility::trimExplode(',', $submittedData['excludePages'], true);
            foreach ($excludePages as $pageId) {
                if (!is_numeric($pageId) || (int)$pageId <= 0) {
                    $schedulerModule->addMessage(
                        LocalizationUtility::translate('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.validation.invalid_exclude_pages', 'semantic_suggestion') ?? 'The exclude pages list must contain only valid page IDs (positive integers).',
                        FlashMessage::ERROR
                    );
                    $result = false;
                    break;
                }
            }
        }
        
        // Validation of recursiveExclusion: no specific validation needed
        // as it's a boolean handled by checkbox

        // Validation of languageId
        if (isset($submittedData['languageId'])) {
            $languageId = (int)$submittedData['languageId'];
            if ($languageId < -1) {
                $schedulerModule->addMessage(
                    'The language ID must be -1 (all languages) or a non-negative integer.',
                    FlashMessage::ERROR
                );
                $result = false;
            }
        }

        return $result;
    }
    
    /**
     * Saves additional field values in task object
     */
    public function saveAdditionalFields(array $submittedData, AbstractTask $task): void
    {
        if ($task instanceof GenerateSimilaritiesTask) {
            $task->startPageId = (int)$submittedData['startPageId'];
            $task->excludePages = $submittedData['excludePages'];

            // Quality Level is the only threshold the user sets; the storage
            // threshold is derived from it by the task itself.
            if (isset($submittedData['qualityLevel'])) {
                $task->qualityLevel = (float)$submittedData['qualityLevel'];
            } elseif (isset($submittedData['minimumSimilarity'])) {
                // Legacy payload: treat the old value as the quality level.
                $task->qualityLevel = (float)$submittedData['minimumSimilarity'];
            }

            // Sync the derived property directly rather than through
            // initializeQualityLevel(): its legacy-migration branch would
            // mistake a deliberate 0.3 for "unset" and overwrite it with the
            // previously stored minimumSimilarity.
            $task->minimumSimilarity = $task->getStorageThreshold();

            // Handle checkbox: if not present in $_POST, it's unchecked
            $task->recursiveExclusion = isset($submittedData['recursiveExclusion']) && $submittedData['recursiveExclusion'] === '1';

            // NEW: Language ID
            $task->languageId = (int)$submittedData['languageId'];
        }
    }
    
    /**
     * Gets the language service
     */
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}