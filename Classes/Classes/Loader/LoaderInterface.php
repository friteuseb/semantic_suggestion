<?php
namespace TalanHdf\SemanticSuggestion\Loader;

interface LoaderInterface
{
    /**
     * @param mixed $identifier Un identifiant pour le contenu (par exemple, un ID de page)
     * @return string Le contenu chargé sous forme de chaîne
     */
    public function load($identifier): string;
}