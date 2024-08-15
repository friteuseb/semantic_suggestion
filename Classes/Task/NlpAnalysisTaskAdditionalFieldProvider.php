<?php
namespace TalanHdf\SemanticSuggestion\Task;

use TYPO3\CMS\Scheduler\AbstractAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

class NlpAnalysisTaskAdditionalFieldProvider extends AbstractAdditionalFieldProvider
{
    public function getAdditionalFields(array &$taskInfo, $task, SchedulerModuleController $schedulerModule)
    {
        // Add any additional fields you need for your task here
        return [];
    }

    public function validateAdditionalFields(array &$submittedData, SchedulerModuleController $schedulerModule)
    {
        // Validate any additional fields here
        return true;
    }

    public function saveAdditionalFields(array $submittedData, AbstractTask $task)
    {
        // Save any additional fields here
    }
}