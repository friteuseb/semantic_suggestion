<?php
namespace Talan\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Database\ConnectionPool;

class BackendController extends ActionController
{
    protected $moduleTemplateFactory;

    public function __construct(ModuleTemplateFactory $moduleTemplateFactory)
    {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
    }

    public function listAction(): ResponseInterface
    {
        $pages = $this->getPages();
        $proximityMatrix = $this->calculateProximityMatrix($pages);

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setContent($this->view->render());
        $this->view->assign('pages', $pages);
        $this->view->assign('proximityMatrix', $proximityMatrix);

        return $moduleTemplate->renderResponse();
    }

    protected function getPages(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        return $queryBuilder
            ->select('uid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
            )
            ->execute()
            ->fetchAllAssociative();
    }

    protected function calculateProximityMatrix(array $pages): array
    {
        $matrix = [];
        foreach ($pages as $page1) {
            foreach ($pages as $page2) {
                if ($page1['uid'] !== $page2['uid']) {
                    $proximity = $this->calculateProximity($page1, $page2);
                    $matrix[$page1['uid']][$page2['uid']] = $proximity;
                }
            }
        }
        return $matrix;
    }

    protected function calculateProximity(array $page1, array $page2): float
    {
        // Ici, vous devriez implémenter votre logique de calcul de proximité sémantique
        // Pour cet exemple, nous utilisons une valeur aléatoire
        return round(mt_rand(0, 100) / 100, 2);
    }
}