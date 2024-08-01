<?php

function analyzeExtension($extensionPath) {
    $info = [];

    // Structure de l'extension
    $info['structure'] = dirToArray($extensionPath);

    // Contenu de ext_emconf.php
    $emconfPath = $extensionPath . '/ext_emconf.php';
    if (file_exists($emconfPath)) {
        $info['ext_emconf'] = file_get_contents($emconfPath);
    }

    // Contenu de composer.json
    $composerPath = $extensionPath . '/composer.json';
    if (file_exists($composerPath)) {
        $info['composer_json'] = json_decode(file_get_contents($composerPath), true);
    }

    // Recherche de classes importantes
    $info['important_classes'] = findImportantClasses($extensionPath);

    return $info;
}

function dirToArray($dir) {
    $result = [];
    $cdir = scandir($dir);
    foreach ($cdir as $key => $value) {
        if (!in_array($value, [".", ".."])) {
            if (is_dir($dir . DIRECTORY_SEPARATOR . $value)) {
                $result[$value] = dirToArray($dir . DIRECTORY_SEPARATOR . $value);
            } else {
                $result[] = $value;
            }
        }
    }
    return $result;
}

function findImportantClasses($dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    $files = new RegexIterator($iterator, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);
    
    $classes = [];
    foreach($files as $file) {
        $content = file_get_contents($file[0]);
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $className = $matches[1];
            $classes[$className] = $file[0];
        }
    }
    return $classes;
}

// Chemin de l'extension
$extensionPath = __DIR__;

// Analyser l'extension
$extensionInfo = analyzeExtension($extensionPath);

// Afficher les résultats
echo json_encode($extensionInfo, JSON_PRETTY_PRINT);
