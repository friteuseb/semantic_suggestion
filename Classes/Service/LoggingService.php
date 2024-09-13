<?php
namespace TalanHdf\SemanticSuggestion\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\LogManager;

class LoggingService
{
    protected LoggerInterface $logger;

    public function __construct(LogManager $logManager)
    {
        $this->logger = $logManager->getLogger(__CLASS__);
    }

    public function logDebug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    public function logInfo(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    public function logWarning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    public function logError(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }
}