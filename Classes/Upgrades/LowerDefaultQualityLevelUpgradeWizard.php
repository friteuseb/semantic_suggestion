<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Upgrades;

use TalanHdf\SemanticSuggestion\Task\GenerateSimilaritiesTask;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * 5.0 scores pages differently and its scores are lower, more so on short pages.
 * Measured on 42 French pages, a task on the 4.x default quality level of 0.3
 * left 26 pages without any suggestion, 39 when the pages were short. This moves
 * tasks still on that default to the 5.0 default.
 *
 * Only tasks at exactly 0.3 are touched. Any other value was chosen by someone
 * and is left alone; the wizard description tells them to review it.
 *
 * TYPO3 14 keeps task settings in the `parameters` JSON column, TYPO3 12 and 13
 * in `serialized_task_object`. Both are handled. The serialized object is edited
 * as a string rather than unserialized, because unserializing it would require
 * allowing every class it references.
 */
class LowerDefaultQualityLevelUpgradeWizard implements UpgradeWizardInterface
{
    private const TABLE = 'tx_scheduler_task';
    private const OLD_DEFAULT = 0.3;

    public function getIdentifier(): string
    {
        return 'semanticSuggestionLowerDefaultQualityLevel';
    }

    public function getTitle(): string
    {
        return 'Semantic Suggestion: move scheduler tasks from the 4.x default quality level (0.3) to 0.1';
    }

    public function getDescription(): string
    {
        return 'Semantic Suggestion 5.0 computes similarity differently and its scores are lower, '
            . 'especially on short pages. Tasks still on the old default quality level of 0.3 would leave '
            . 'many pages without suggestions. This wizard sets them to the new default, '
            . GenerateSimilaritiesTask::DEFAULT_QUALITY_LEVEL . '. Tasks with any other value are left '
            . 'untouched: review them, and the display threshold (plugin.tx_semanticsuggestion_suggestions'
            . '.settings.qualityLevel) if you set it yourself. Then run the task again.';
    }

    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }

    public function updateNecessary(): bool
    {
        return $this->findTasksToUpdate() !== [];
    }

    public function executeUpdate(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);

        foreach ($this->findTasksToUpdate() as $uid => $changes) {
            $connection->update(self::TABLE, $changes, ['uid' => $uid]);
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>> Columns to write, by task uid
     */
    private function findTasksToUpdate(): array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $columns = array_keys($connection->createSchemaManager()->listTableColumns(self::TABLE));
        $hasParameters = in_array('parameters', $columns, true);

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->executeQuery()
            ->fetchAllAssociative();

        $newDefault = GenerateSimilaritiesTask::DEFAULT_QUALITY_LEVEL;
        $updates = [];

        foreach ($rows as $row) {
            $serialized = (string)($row['serialized_task_object'] ?? '');
            $isOurTask = ($row['tasktype'] ?? '') === GenerateSimilaritiesTask::class
                || str_contains($serialized, GenerateSimilaritiesTask::class);
            if (!$isOurTask) {
                continue;
            }

            $changes = [];

            if ($hasParameters && !empty($row['parameters'])) {
                $parameters = json_decode((string)$row['parameters'], true);
                if (is_string($parameters)) {
                    // Encoded twice by a writer that passed a JSON string to the JSON column.
                    $parameters = json_decode($parameters, true);
                }
                if (is_array($parameters) && isset($parameters['qualityLevel'])
                    && abs((float)$parameters['qualityLevel'] - self::OLD_DEFAULT) < 1e-9
                ) {
                    $parameters['qualityLevel'] = $newDefault;
                    $parameters['minimumSimilarity'] = $newDefault;
                    // An array, not a JSON string: the column is typed JSON on TYPO3 14
                    // and the connection encodes it. A string would be encoded twice.
                    $changes['parameters'] = $parameters;
                }
            }

            $pattern = '/(s:12:"qualityLevel";d:)0\.3(0*);/';
            if ($serialized !== '' && preg_match($pattern, $serialized)) {
                $serialized = preg_replace($pattern, '${1}' . $newDefault . ';', $serialized);
                $serialized = preg_replace('/(s:17:"minimumSimilarity";d:)[0-9.]+;/', '${1}' . $newDefault . ';', $serialized);
                $changes['serialized_task_object'] = $serialized;
            }

            if ($changes !== []) {
                $updates[(int)$row['uid']] = $changes;
            }
        }

        return $updates;
    }
}
