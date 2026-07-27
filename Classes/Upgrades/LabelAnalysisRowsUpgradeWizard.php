<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Upgrades;

use TalanHdf\SemanticSuggestion\Task\GenerateSimilaritiesTask;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Labels this extension's own rows with source = 'analysis'.
 *
 * The `source` column used to be declared only by semantic_suggestion_solr, with a
 * default of 'solr'. This extension never set it, so every row written by the
 * scheduler task silently inherited 'solr' — and the Solr extension deletes by
 * `page_id + sys_language_uid + source = 'solr'`, which meant it erased analysis
 * rows it did not own.
 *
 * Rows written from now on carry 'analysis' explicitly. This wizard fixes the ones
 * already stored, but only when it can be certain they are ours.
 */
class LabelAnalysisRowsUpgradeWizard implements UpgradeWizardInterface
{
    private const TABLE = 'tx_semanticsuggestion_similarities';

    public function getIdentifier(): string
    {
        return 'semanticSuggestionLabelAnalysisRows';
    }

    public function getTitle(): string
    {
        return 'Semantic Suggestion: label existing analysis rows with their source';
    }

    public function getDescription(): string
    {
        if ($this->isSolrExtensionLoaded()) {
            return 'Existing rows carry source="solr" because the column defaulted to that value. '
                . 'semantic_suggestion_solr is installed, so this wizard cannot tell which of those rows '
                . 'came from the analysis task and which came from Solr, and it will not guess. '
                . 'Re-run your "Generate Similarities" scheduler task and your Solr indexer once: each '
                . 'rewrites its own rows with the correct source and the two stop deleting each other.';
        }

        return 'Existing rows carry source="solr" because the column defaulted to that value, even though '
            . 'they were produced by the analysis task. semantic_suggestion_solr is not installed, so every '
            . 'row belongs to this extension and can be relabelled safely.';
    }

    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }

    public function updateNecessary(): bool
    {
        return !$this->isSolrExtensionLoaded() && $this->countMislabelledRows() > 0;
    }

    public function executeUpdate(): bool
    {
        // Refuse to guess: with the Solr extension installed, both producers' rows
        // look identical. Reporting success is correct — there is nothing safe to do,
        // and the description tells the integrator how to resolve it.
        if ($this->isSolrExtensionLoaded()) {
            return true;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::TABLE);

        $queryBuilder
            ->update(self::TABLE)
            ->set('source', GenerateSimilaritiesTask::SOURCE)
            ->where(
                $queryBuilder->expr()->neq(
                    'source',
                    $queryBuilder->createNamedParameter(GenerateSimilaritiesTask::SOURCE)
                )
            )
            ->executeStatement();

        return true;
    }

    private function isSolrExtensionLoaded(): bool
    {
        return ExtensionManagementUtility::isLoaded('semantic_suggestion_solr');
    }

    private function countMislabelledRows(): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::TABLE);

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->neq(
                    'source',
                    $queryBuilder->createNamedParameter(GenerateSimilaritiesTask::SOURCE)
                )
            )
            ->executeQuery()
            ->fetchOne();
    }
}
