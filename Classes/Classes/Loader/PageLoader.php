<?php
namespace TalanHdf\SemanticSuggestion\Loader;

use TYPO3\CMS\Core\Domain\Repository\PageRepository;

class PageLoader implements LoaderInterface
{
    protected PageRepository $pageRepository;

    public function __construct(PageRepository $pageRepository)
    {
        $this->pageRepository = $pageRepository;
    }

    public function load($identifier): string
    {
        $page = $this->pageRepository->getPage($identifier);
        // Combinez le titre, le contenu, etc. en une seule chaîne
        return $page['title'] . ' ' . $page['description'] . ' ' . $this->getPageContent($identifier);
    }


    protected function getPageContent(int $pageId, int $languageUid = 0): string
    {
        try {
            $queryBuilder = $this->getQueryBuilder('tt_content');
    
            $content = $queryBuilder
                ->select('bodytext')
                ->from('tt_content')
                ->where(
                    $queryBuilder->expr()->eq('tt_content.pid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('tt_content.hidden', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('tt_content.deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('tt_content.sys_language_uid', $queryBuilder->createNamedParameter($languageUid, \PDO::PARAM_INT))
                )
                ->executeQuery()
                ->fetchAllAssociative();
    
            return implode(' ', array_column($content, 'bodytext'));
        } catch (\Exception $e) {
            $this->logger?->error('Error fetching page content', ['pageId' => $pageId, 'languageUid' => $languageUid, 'exception' => $e->getMessage()]);
            throw $e;
        }
    }

    private function getAllSubpages(int $parentId, int $depth = 0): array
    {
        $allPages = [];
        $queue = [[$parentId, 0]];

        while (!empty($queue)) {
            [$currentId, $currentDepth] = array_shift($queue);

            if ($depth !== -1 && $currentDepth > $depth) {
                continue;
            }

            $pages = $this->getSubpages($currentId);
            $allPages = array_merge($allPages, $pages);

            foreach ($pages as $page) {
                $queue[] = [$page['uid'], $currentDepth + 1];
            }
        }

        return $allPages;
    }
    

    protected function getSubpages(int $parentId, string $languageCode): array
    {
        $this->logger?->info('Fetching subpages', ['parentId' => $parentId, 'languageCode' => $languageCode]);

        try {
            $queryBuilder = $this->getQueryBuilder();

            $languageAspect = $this->context->getAspect('language');
            $languageId = $languageAspect->getId();

            $fieldsToSelect = ['uid', 'title', 'description', 'keywords', 'abstract', 'crdate', 'sys_language_uid'];
            $tableColumns = $queryBuilder->getConnection()->getSchemaManager()->listTableColumns('pages');
            $existingColumns = array_keys($tableColumns);
            $fieldsToSelect = array_intersect($fieldsToSelect, $existingColumns);

          $this->logDebug('Fields to select', ['fields' => $fieldsToSelect]);

            $result = $queryBuilder
                ->select(...$fieldsToSelect)
                ->addSelectLiteral(
                    '(SELECT MAX(tstamp) FROM tt_content WHERE tt_content.pid = pages.uid AND tt_content.deleted = 0 AND tt_content.hidden = 0)'
                )
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentId, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)), 
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, \PDO::PARAM_INT))
                )
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($result as &$page) {
                $page['content_modified_at'] = $page['MAX(tstamp)'] ?? $page['crdate'] ?? time();
                unset($page['MAX(tstamp)']);
            }

            $this->logger?->info('Subpages fetched successfully', ['count' => count($result), 'languageCode' => $languageCode]);
          $this->logDebug('Fetched subpages', ['subpages' => $result]);

            return $result;
        } catch (\Exception $e) {
            $this->logger?->error('Error fetching subpages', ['exception' => $e->getMessage(), 'parentId' => $parentId, 'languageCode' => $languageCode]);
            throw $e; 
        }
    }

    

}