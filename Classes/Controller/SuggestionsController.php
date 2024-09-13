<?php
namespace TalanHdf\SemanticSuggestion\Controller;

use TalanHdf\SemanticSuggestion\Service\SemanticSuggestionService;

class SuggestionsController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{
    protected SemanticSuggestionService $suggestionService;

    public function __construct(SemanticSuggestionService $suggestionService)
    {
        $this->suggestionService = $suggestionService;
    }

    public function listAction()
    {
        $currentPageId = $this->getCurrentPageId();
        $potentialSuggestions = $this->getPotentialSuggestions();
        
        $suggestions = $this->suggestionService->getSuggestions($currentPageId, $potentialSuggestions);

        $this->view->assign('suggestions', $suggestions);
    }

    protected function getCurrentPageId(): int
    {
        // Récupérez l'ID de la page courante
    }

    protected function getPotentialSuggestions(): array
    {
        // Récupérez la liste des pages potentielles pour les suggestions
        // Cette logique était probablement dans votre ancien PageAnalysisService
    }
}