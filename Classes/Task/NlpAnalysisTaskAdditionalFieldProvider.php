<?php
namespace TalanHdf\SemanticSuggestion\Task;

use TYPO3\CMS\Scheduler\AbstractAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

class NlpAnalysisTaskAdditionalFieldProvider extends AbstractAdditionalFieldProvider
{
    protected $configurationManager;

    public function injectConfigurationManager(ConfigurationManagerInterface $configurationManager)
    {
        $this->configurationManager = $configurationManager;
    }

    public function getAdditionalFields(array &$taskInfo, $task, SchedulerModuleController $schedulerModule)
    {
        if ($this->configurationManager === null) {
            $this->configurationManager = GeneralUtility::makeInstance(ConfigurationManagerInterface::class);
        }

        $additionalFields = [];

        $config = $this->getTypoScriptConfiguration();

        $additionalFields['parentPageId'] = $this->getReadOnlyField('parentPageId', $config['parentPageId'] ?? '');
        $additionalFields['recursive'] = $this->getReadOnlyField('recursive', $config['recursive'] ?? '');
        $additionalFields['excludePages'] = $this->getReadOnlyField('excludePages', $config['excludePages'] ?? '');
        $additionalFields['proximityThreshold'] = $this->getReadOnlyField('proximityThreshold', $config['proximityThreshold'] ?? '');
        $additionalFields['maxSuggestions'] = $this->getReadOnlyField('maxSuggestions', $config['maxSuggestions'] ?? '');
        $additionalFields['excerptLength'] = $this->getReadOnlyField('excerptLength', $config['excerptLength'] ?? '');
        $additionalFields['recencyWeight'] = $this->getReadOnlyField('recencyWeight', $config['recencyWeight'] ?? '');
        $additionalFields['nlpWeight'] = $this->getReadOnlyField('nlpWeight', $config['nlpWeight'] ?? '');

        return $additionalFields;
    }

    protected function getReadOnlyField($fieldName, $value)
    {
        return [
            'code' => '<input type="text" name="tx_scheduler[' . $fieldName . ']" value="' . htmlspecialchars($value) . '" readonly />',
            'label' => $fieldName,
            'cshKey' => '_MOD_system_txschedulerM1',
            'cshLabel' => $fieldName
        ];
    }

    public function validateAdditionalFields(array &$submittedData, SchedulerModuleController $schedulerModule)
    {
        // No validation needed as fields are read-only
        return true;
    }

    public function saveAdditionalFields(array $submittedData, AbstractTask $task)
    {
        // No saving needed as fields are read-only and values come from TypoScript
    }

   protected function getTypoScriptConfiguration(): array
    {
        $fullTypoScript = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );

        return $fullTypoScript['plugin.']['tx_semanticsuggestion_suggestions.']['settings.'] ?? [];
    }
}