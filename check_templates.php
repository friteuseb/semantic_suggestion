<?php

class TemplateChecker
{
    private $extensionPath;
    private $issues = [];

    public function __construct($extensionPath = null)
    {
        $this->extensionPath = $extensionPath ?: getcwd();
    }

    public function checkAll()
    {
        $this->checkModulesConfiguration();
        $this->checkTypoScriptConfiguration();
        $this->checkControllers();
        $this->checkTemplateFiles();
    }

    private function checkModulesConfiguration()
    {
        $modulesFile = $this->extensionPath . '/Configuration/Backend/Modules.php';
        if (file_exists($modulesFile)) {
            $modules = include $modulesFile;
            foreach ($modules as $moduleKey => $moduleConfig) {
                if (isset($moduleConfig['controllerActions'])) {
                    foreach ($moduleConfig['controllerActions'] as $controller => $actions) {
                        $this->checkControllerActions($controller, $actions);
                    }
                } else {
                    $this->addIssue("Missing controllerActions in module configuration for $moduleKey");
                }
            }
        } else {
            $this->addIssue("Modules configuration file not found: $modulesFile");
        }
    }

    private function checkControllerActions($controller, $actions)
    {
        $controllerName = basename(str_replace('\\', '/', $controller));
        $controllerName = str_replace('Controller', '', $controllerName);
        foreach ($actions as $action) {
            $templatePath = $this->extensionPath . "/Resources/Private/Templates/$controllerName/$action.html";
            if (!file_exists($templatePath)) {
                $this->addIssue("Template file not found for action $action in controller $controller: $templatePath");
            }
        }
    }

    private function checkTypoScriptConfiguration()
    {
        $setupFile = $this->extensionPath . '/Configuration/TypoScript/setup.typoscript';
        if (file_exists($setupFile)) {
            $content = file_get_contents($setupFile);
            if (strpos($content, 'templateRootPaths') === false) {
                $this->addIssue("templateRootPaths not found in TypoScript setup");
            }
            if (strpos($content, 'partialRootPaths') === false) {
                $this->addIssue("partialRootPaths not found in TypoScript setup");
            }
            if (strpos($content, 'layoutRootPaths') === false) {
                $this->addIssue("layoutRootPaths not found in TypoScript setup");
            }
        } else {
            $this->addIssue("TypoScript setup file not found: $setupFile");
        }
    }

    private function checkControllers()
    {
        $controllersDir = $this->extensionPath . '/Classes/Controller';
        if (is_dir($controllersDir)) {
            $controllers = glob($controllersDir . '/*Controller.php');
            foreach ($controllers as $controller) {
                $this->checkControllerFile($controller);
            }
        } else {
            $this->addIssue("Controllers directory not found: $controllersDir");
        }
    }

    private function checkControllerFile($controllerFile)
    {
        $content = file_get_contents($controllerFile);
        preg_match_all('/public function (\w+)Action/', $content, $matches);
        $controllerName = basename($controllerFile, 'Controller.php');
        foreach ($matches[1] as $action) {
            $templatePath = $this->extensionPath . "/Resources/Private/Templates/$controllerName/$action.html";
            if (!file_exists($templatePath)) {
                $this->addIssue("Template file not found for action $action in controller $controllerName: $templatePath");
            }
        }
    }

    private function checkTemplateFiles()
    {
        $templatesDir = $this->extensionPath . '/Resources/Private/Templates';
        if (is_dir($templatesDir)) {
            $this->checkTemplateDirectory($templatesDir);
        } else {
            $this->addIssue("Templates directory not found: $templatesDir");
        }
    }

    private function checkTemplateDirectory($dir)
    {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->checkTemplateDirectory($file);
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'html') {
                // Vérifier le contenu du fichier template si nécessaire
            }
        }
    }

    private function addIssue($message)
    {
        $this->issues[] = $message;
    }

    public function getReport()
    {
        if (empty($this->issues)) {
            return "No issues found with templates and their configuration.";
        } else {
            return "Issues found:\n" . implode("\n", $this->issues);
        }
    }
}

// Usage
$checker = new TemplateChecker();
$checker->checkAll();
echo $checker->getReport();
