<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DatabaseService
{
    protected ConnectionPool $connectionPool;

    public function __construct(ConnectionPool $connectionPool)
    {
        $this->connectionPool = $connectionPool;
    }

    public function getQueryBuilder(string $table = 'pages'): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable($table);
    }

    // Ajoutez ici d'autres méthodes liées à la base de données si nécessaire
}