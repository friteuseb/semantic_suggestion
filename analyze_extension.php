<?php

// Placer ce fichier à la racine de votre extension et le nommer analyze_extension.php

function analyzeExtension($dir) {
    $result = [
        'structure' => [],
        'classes' => [],
        'functions' => []
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $path => $file) {
        $relativePath = str_replace($dir . '/', '', $path);
        if ($file->isDir()) {
            $result['structure'][$relativePath] = [];
        } else {
            $result['structure'][dirname($relativePath)][] = basename($relativePath);
            if ($file->getExtension() == 'php') {
                $content = file_get_contents($path);
                analyzePhpFile($content, $result['classes'], $result['functions'], $relativePath);
            }
        }
    }

    return $result;
}

function analyzePhpFile($content, &$classes, &$functions, $filename) {
    $tokens = token_get_all($content);
    $namespace = '';
    $currentClass = '';

    foreach ($tokens as $token) {
        if (is_array($token)) {
            if ($token[0] == T_NAMESPACE) {
                $namespace = getFullName($tokens, key($tokens));
            } elseif ($token[0] == T_CLASS) {
                $currentClass = $namespace . '\\' . getFullName($tokens, key($tokens));
                $classes[$currentClass] = ['file' => $filename, 'methods' => []];
            } elseif ($token[0] == T_FUNCTION) {
                $functionName = getFullName($tokens, key($tokens));
                if ($currentClass) {
                    $classes[$currentClass]['methods'][] = $functionName;
                } else {
                    $functions[] = ['name' => $namespace . '\\' . $functionName, 'file' => $filename];
                }
            }
        }
    }
}

function getFullName($tokens, $start) {
    $name = '';
    $i = $start + 1;
    while (isset($tokens[$i])) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
            $name .= $tokens[$i][1];
        } elseif ($tokens[$i] === '{' || $tokens[$i] === ';') {
            break;
        }
        $i++;
    }
    return trim($name);
}

// Exécution de l'analyse
$extensionPath = __DIR__;
$analysis = analyzeExtension($extensionPath);

// Création du rapport
$report = "Structure de l'extension:\n";
$report .= print_r($analysis['structure'], true);

$report .= "\nClasses et méthodes:\n";
$report .= print_r($analysis['classes'], true);

$report .= "\nFonctions globales:\n";
$report .= print_r($analysis['functions'], true);

// Écriture du rapport dans un fichier
file_put_contents(__DIR__ . '/extension_analysis_report.txt', $report);

echo "L'analyse est terminée. Le rapport a été enregistré dans 'extension_analysis_report.txt'.";