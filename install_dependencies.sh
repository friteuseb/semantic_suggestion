#!/bin/bash

# Chemin vers le fichier requirements.txt
REQUIREMENTS_FILE="./Resources/Private/Python/requirements.txt"

# Vérifier si pip est installé
if ! command -v pip &> /dev/null
then
    echo "pip n'est pas installé. Veuillez installer Python et pip."
    exit 1
fi

# Installer les dépendances Python
echo "Installation des dépendances Python..."
pip install -r "$REQUIREMENTS_FILE"

# Télécharger les ressources NLTK nécessaires
echo "Téléchargement des ressources NLTK..."
python -c "import nltk; nltk.download('punkt'); nltk.download('stopwords')"

echo "Installation terminée avec succès !"