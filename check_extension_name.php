<?php

class ExtensionNameChecker
{
    private $extensionPath;
    private $extensionKey;
    private $vendorName;
    private $extensionName;
    private $issues = [];

    public function __construct()
    {
        $this->extensionPath = getcwd();
        $this->extensionKey = basename($this->extensionPath);
        $this->determineVendorAndExtensionName();
    }

    private function determineVendorAndExtensionName()
    {
        $composerJson = $this->parseJsonFile('composer.json');
        if (isset($composerJson['name'])) {
            list($this->vendorName, $this->extensionName) = explode('/', $composerJson['name']);
        } else {
            $this->addIssue('composer.json', 'Missing "name" property');
        }
    }

    public function checkAll()
    {
        $this->checkComposerJson();
        $this->checkExtEmconf();
        $this->checkExtLocalconf();
        $this->checkExtTables();
        $this->checkConfigurationBackendModules();
    }

    private function checkComposerJson()
    {
        $composerJson = $this->parseJsonFile('composer.json');
        $this->checkProperty($composerJson, 'name', "{$this->vendorName}/{$this->extensionName}", 'composer.json');
        $this->checkProperty($composerJson, 'type', 'typo3-cms-extension', 'composer.json');
        $this->checkProperty($composerJson['extra']['typo3/cms'] ?? [], 'extension-key', $this->extensionKey, 'composer.json');
    }

    private function checkExtEmconf()
    {
        $emConf = $this->parsePhpFile('ext_emconf.php');
        if (is_array($emConf) && isset($emConf['$EM_CONF'])) {
            $this->checkProperty($emConf['$EM_CONF'], $this->extensionKey, null, 'ext_emconf.php');
        } else {
            $this->addIssue('ext_emconf.php', "Invalid structure or missing \$EM_CONF");
        }
    }

    private function checkExtLocalconf()
    {
        $content = $this->getFileContent('ext_localconf.php');
        $expectedNames = [$this->extensionName, str_replace('-', '_', $this->extensionName)];
        if (!$this->stringContainsAny($content, $expectedNames)) {
            $this->addIssue('ext_localconf.php', "Extension name not found. Expected one of: " . implode(', ', $expectedNames));
        }
    }

    private function checkExtTables()
    {
        $content = $this->getFileContent('ext_tables.php');
        $expectedNames = [$this->extensionName, str_replace('-', '_', $this->extensionName)];
        if (!$this->stringContainsAny($content, $expectedNames)) {
            $this->addIssue('ext_tables.php', "Extension name not found. Expected one of: " . implode(', ', $expectedNames));
        }
    }

    private function checkConfigurationBackendModules()
    {
        $modulesPhp = $this->parsePhpFile('Configuration/Backend/Modules.php', true);
        if (is_array($modulesPhp)) {
            foreach ($modulesPhp as $moduleConfig) {
                if (isset($moduleConfig['extensionName']) && !in_array($moduleConfig['extensionName'], [$this->extensionName, str_replace('-', '', $this->extensionName)])) {
                    $this->addIssue('Configuration/Backend/Modules.php', "Incorrect extensionName: '{$moduleConfig['extensionName']}', expected '{$this->extensionName}' or '" . str_replace('-', '', $this->extensionName) . "'");
                }
            }
        } else {
            $this->addIssue('Configuration/Backend/Modules.php', 'File not found or invalid');
        }
    }

    private function parseJsonFile($filename)
    {
        $path = "{$this->extensionPath}/{$filename}";
        if (file_exists($path)) {
            return json_decode(file_get_contents($path), true);
        }
        $this->addIssue($filename, 'File not found');
        return [];
    }

    private function parsePhpFile($filename, $returnContentOnError = false)
    {
        $path = "{$this->extensionPath}/{$filename}";
        if (file_exists($path)) {
            try {
                return include $path;
            } catch (\Throwable $e) {
                $this->addIssue($filename, 'Error parsing file: ' . $e->getMessage());
                return $returnContentOnError ? file_get_contents($path) : [];
            }
        }
        $this->addIssue($filename, 'File not found');
        return [];
    }

    private function getFileContent($filename)
    {
        $path = "{$this->extensionPath}/{$filename}";
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        $this->addIssue($filename, 'File not found');
        return '';
    }

    private function checkProperty($array, $key, $expectedValue, $filename)
    {
        if (!isset($array[$key])) {
            $this->addIssue($filename, "Missing '{$key}' property");
        } elseif ($expectedValue !== null && $array[$key] !== $expectedValue) {
            $this->addIssue($filename, "Incorrect '{$key}': '{$array[$key]}', expected '{$expectedValue}'");
        }
    }

    private function stringContainsAny($haystack, $needles)
    {
        foreach ($needles as $needle) {
            if (strpos($haystack, "'" . $needle . "'") !== false) {
                return true;
            }
        }
        return false;
    }

    private function addIssue($file, $message)
    {
        $this->issues[] = "[$file] $message";
    }

    public function getReport()
    {
        if (empty($this->issues)) {
            return "No issues found. Extension name is consistent across all checked files.";
        } else {
            return "Issues found:\n" . implode("\n", $this->issues);
        }
    }
}

// Usage
$checker = new ExtensionNameChecker();
$checker->checkAll();
echo $checker->getReport();