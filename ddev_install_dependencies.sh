#!/bin/bash

# Chemin vers le fichier requirements.txt dans votre extension
REQUIREMENTS_FILE="/var/www/html/packages/semantic_suggestion/Resources/Private/Python/requirements.txt"

# Installer les dépendances Python
echo "Installation des dépendances Python..."
pip install -r "$REQUIREMENTS_FILE"

# Télécharger les ressources NLTK nécessaires
echo "Téléchargement des ressources NLTK..."
python -c "import nltk; nltk.download('punkt'); nltk.download('stopwords')"

echo "Installation terminée avec succès !"