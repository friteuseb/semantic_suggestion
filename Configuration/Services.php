<?php

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TalanHdf\SemanticSuggestion\Controller\LegacySemanticBackendController;
use TalanHdf\SemanticSuggestion\Controller\SemanticBackendController;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
// Supprimer les imports Monolog s'ils ne sont plus utilisés ailleurs dans CE fichier
// use TYPO3\CMS\Core\Core\Environment;
// use Monolog\Logger;
// use Monolog\Handler\RotatingFileHandler;
// use Monolog\Level;

return function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public(false);

    $services->load('TalanHdf\\SemanticSuggestion\\', '../Classes/*')
        ->exclude('../Classes/Controller/*');

    // --- Enregistrements Inconditionnels ---
    $services->set(TalanHdf\SemanticSuggestion\Controller\SuggestionsController::class)->public(true);
    $services->set(TalanHdf\SemanticSuggestion\Task\GenerateSimilaritiesTask::class)->public(true);
    $services->set(TalanHdf\SemanticSuggestion\Command\DiagnosticCommand::class)
        ->tag('console.command', ['command' => 'semantic:diagnostic']);

    $services->set(TalanHdf\SemanticSuggestion\Command\DebugSchedulerCommand::class)
        ->tag('console.command', ['command' => 'semantic:debug-scheduler']);

    $services->set(TalanHdf\SemanticSuggestion\Service\PageAnalysisService::class)->public(true);
    $services->set(TalanHdf\SemanticSuggestion\Service\SuggestionService::class)->public(true);
    $services->set(TalanHdf\SemanticSuggestion\Service\UtilityService::class)->public(true);
    $services->set(TalanHdf\SemanticSuggestion\Service\LanguageService::class);
    $services->set(TalanHdf\SemanticSuggestion\Service\SiteLanguageService::class);
    $services->set(TalanHdf\SemanticSuggestion\Hooks\DataHandlerHook::class);

    // Services nlp_tools (optional - will be auto-instantiated if available)
    if (class_exists(\Cywolf\NlpTools\Service\LanguageDetectionService::class)) {
        $services->set(Cywolf\NlpTools\Service\LanguageDetectionService::class)->public(true);
        $services->set(Cywolf\NlpTools\Service\TextAnalysisService::class)->public(true);
        $services->set(Cywolf\NlpTools\Service\TextVectorizerService::class)->public(true);
        $services->set(Cywolf\NlpTools\Service\StopWordsFactory::class)->public(true);
    }

    // Rendre les services Core nécessaires publics pour l'injection
    $services->set(TYPO3\CMS\Core\Log\LogManager::class)->public(true);
    $services->set(TYPO3\CMS\Core\Messaging\FlashMessageService::class)->public(true);
    $services->set(TYPO3\CMS\Backend\Template\ModuleTemplateFactory::class)->public(true);
    $services->set(TYPO3\CMS\Core\Context\Context::class)->public(true);
    $services->set(TYPO3\CMS\Core\Site\SiteFinder::class)->public(true);
    $services->set(TYPO3\CMS\Core\Resource\FileRepository::class)->public(true);
    $services->set(TYPO3\CMS\Core\Domain\Repository\PageRepository::class)->public(true);
    $services->set(TYPO3\CMS\Extbase\Configuration\ConfigurationManager::class)->public(true);
    $services->alias(TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::class, TYPO3\CMS\Extbase\Configuration\ConfigurationManager::class)->public(true);
    $services->set(TYPO3\CMS\Core\Database\ConnectionPool::class)->public(true);

    // --- Enregistrements Conditionnels ---
    $versionInformation = GeneralUtility::makeInstance(Typo3Version::class);

    if ($versionInformation->getMajorVersion() < 13) {
        // Services pour v12
        $services->set(LegacySemanticBackendController::class)
        ->public(true)
        ->tag('controller.backend_controller')
        ->args([
            service(TYPO3\CMS\Backend\Template\ModuleTemplateFactory::class),
            service(TalanHdf\SemanticSuggestion\Service\PageAnalysisService::class),
            service(TYPO3\CMS\Core\Log\LogManager::class),
            service(TalanHdf\SemanticSuggestion\Service\LanguageService::class),
            service(TYPO3\CMS\Core\Messaging\FlashMessageService::class),
            service(TYPO3\CMS\Core\Domain\Repository\PageRepository::class),
            service(TYPO3\CMS\Core\Database\ConnectionPool::class) // This is now the 7th argument
        ]);

    } else {
        // v13+ definition (already correct)
        $services->set(SemanticBackendController::class)
        ->public(true)
        ->tag('controller.backend_controller')
        ->args([
            service(TYPO3\CMS\Backend\Template\ModuleTemplateFactory::class),
            service(TalanHdf\SemanticSuggestion\Service\PageAnalysisService::class),
            service(TYPO3\CMS\Core\Log\LogManager::class),
            service(TalanHdf\SemanticSuggestion\Service\LanguageService::class),
            service(TYPO3\CMS\Core\Messaging\FlashMessageService::class),
            service(TYPO3\CMS\Core\Domain\Repository\PageRepository::class),
            service(TYPO3\CMS\Core\Database\ConnectionPool::class)
        ]);
    }

    // Debug Controller (available for all versions)
    $services->set(TalanHdf\SemanticSuggestion\Controller\DebugController::class)
        ->public(true)
        ->tag('controller.backend_controller')
        ->args([
            service(TYPO3\CMS\Backend\Template\ModuleTemplateFactory::class),
            service(TYPO3\CMS\Core\Database\ConnectionPool::class),
            service(TYPO3\CMS\Core\Site\SiteFinder::class),
            service(TYPO3\CMS\Core\Cache\CacheManager::class)
        ]);

    // --- SUPPRIMER la configuration du logger Monolog personnalisé ---
    /*
    $services->set('monolog.logger.semantic_suggestion', Logger::class)
        ->arg('$name', 'semantic_suggestion')
        ->call('pushHandler', [service('monolog.handler.semantic_suggestion')]);

    $services->set('monolog.handler.semantic_suggestion', RotatingFileHandler::class)
        ->arg('$filename', Environment::getVarPath() . '/log/semantic_suggestion.log')
        ->arg('$level', 100); // Utiliser la valeur entière pour DEBUG (PSR-3)
    */

     // --- SUPPRIMER les appels setLogger ---
     /*
     $pageAnalysisServiceDef = $services->get(TalanHdf\SemanticSuggestion\Service\PageAnalysisService::class);
     if (is_a(TalanHdf\SemanticSuggestion\Service\PageAnalysisService::class, \Psr\Log\LoggerAwareInterface::class, true)) {
         $pageAnalysisServiceDef->call('setLogger', [service('monolog.logger.semantic_suggestion')]); // Ne pas appeler ce service inexistant
     }

     $languageServiceDef = $services->get(TalanHdf\SemanticSuggestion\Service\LanguageService::class);
     if (is_a(TalanHdf\SemanticSuggestion\Service\LanguageService::class, \Psr\Log\LoggerAwareInterface::class, true)) {
         $languageServiceDef->call('setLogger', [service('monolog.logger.semantic_suggestion')]); // Ne pas appeler ce service inexistant
     }

    $suggestionsControllerDef = $services->get(TalanHdf\SemanticSuggestion\Controller\SuggestionsController::class);
    if (is_a(TalanHdf\SemanticSuggestion\Controller\SuggestionsController::class, \Psr\Log\LoggerAwareInterface::class, true)) {
        $suggestionsControllerDef->call('setLogger', [service('monolog.logger.semantic_suggestion')]); // Ne pas appeler ce service inexistant
    }
    */
};