#!/bin/bash

# Chemin vers le dossier du script
SCRIPT_DIR="/var/www/html/packages/semantic_suggestion/Resources/Private/Python"

# Vérifiez si l'environnement virtuel existe, sinon créez-le
if [ ! -d "$SCRIPT_DIR/nlp_venv" ]; then
    python3 -m venv "$SCRIPT_DIR/nlp_venv"
fi

# Activez l'environnement virtuel
source "$SCRIPT_DIR/nlp_venv/bin/activate"

# Vérifiez si les dépendances sont installées, sinon installez-les
if [ ! -f "$SCRIPT_DIR/requirements.txt" ]; then
    pip install nltk spacy transformers
    pip freeze > "$SCRIPT_DIR/requirements.txt"
else
    pip install -r "$SCRIPT_DIR/requirements.txt"
fi

# Exécutez le script Python
python "$SCRIPT_DIR/camembert_analysis.py" "$@"

# Désactivez l'environnement virtuel
deactivate