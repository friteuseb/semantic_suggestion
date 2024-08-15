<?php
namespace TalanHdf\SemanticSuggestion\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use TalanHdf\SemanticSuggestion\Service\NlpService;

class NlpAnalysisCommand extends Command
{
    protected $nlpService;

    public function __construct(NlpService $nlpService)
    {
        $this->nlpService = $nlpService;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('semanticsuggestion:nlpanalysis')
            ->setDescription('Run NLP analysis on a given text')
            ->addArgument('text', InputArgument::REQUIRED, 'The text to analyze');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $text = $input->getArgument('text');
        $result = $this->nlpService->analyzeContent($text);

        $output->writeln('NLP Analysis Result:');
        $output->writeln(json_encode($result, JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }
}