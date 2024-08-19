# Configuration de l'environnement Python local

1. Assurez-vous d'avoir Python 3.9 ou supérieur installé sur votre machine Linux :
   ```bash
   python3 --version
   ```

2. Créez un environnement virtuel pour isoler les dépendances du projet :
   ```bash
   python3 -m venv venv
   source venv/bin/activate
   ```

3. Installez les dépendances nécessaires :
   ```bash
   pip install flask nltk spacy transformers torch
   ```

4. Téléchargez les ressources NLTK nécessaires :
   ```bash
   python -m nltk.downloader punkt stopwords wordnet
   ```

5. Téléchargez le modèle français pour spaCy :
   ```bash
   python -m spacy download fr_core_news_sm
   ```

# Service Python NLP pour l'extension Semantic Suggestion

Ce dossier contient le service Python d'analyse de langage naturel (NLP) utilisé par l'extension Semantic Suggestion pour TYPO3.

## Aperçu

Le service NLP fournit les fonctionnalités suivantes :
- Analyse de sentiment
- Extraction de mots-clés
- Catégorisation de texte
- Extraction d'entités nommées
- Calcul de score de lisibilité

## Prérequis

- Python 3.9+
- Flask
- NLTK
- spaCy (optionnel, pour l'extraction d'entités nommées)
- Transformers (optionnel, pour une analyse de sentiment avancée)

## Installation

1. Assurez-vous que Python 3.9 ou supérieur est installé sur votre système.

2. Installez les dépendances requises :
   ```
   pip install -r requirements.txt
   ```

3. Téléchargez les ressources NLTK nécessaires :
   ```python
   import nltk
   nltk.download('punkt')
   nltk.download('stopwords')
   ```

4. Si vous utilisez spaCy, téléchargez le modèle français :
   ```
   python -m spacy download fr_core_news_sm
   ```

## Utilisation

Pour démarrer le service NLP :

1. Naviguez vers le dossier contenant `nlp_api.py`.
2. Exécutez la commande suivante :
   ```
   python nlp_api.py
   ```

Le service démarrera sur `http://localhost:5000`.

## API

Le service expose un endpoint `/analyze` qui accepte des requêtes POST avec un corps JSON contenant le texte à analyser :

```json
{
  "content": "Votre texte à analyser ici"
}
```

La réponse sera un objet JSON contenant les résultats de l'analyse.

## Intégration avec TYPO3

Ce service est utilisé par l'extension Semantic Suggestion pour TYPO3. L'extension envoie des requêtes à ce service pour analyser le contenu des pages et générer des suggestions sémantiques.

## Dépannage

Si vous rencontrez des problèmes :
1. Vérifiez que toutes les dépendances sont correctement installées.
2. Assurez-vous que le service est en cours d'exécution lorsque vous utilisez l'extension TYPO3.
3. Vérifiez les logs du service pour plus d'informations sur les erreurs potentielles.

## Contributions

Les contributions pour améliorer ce service sont les bienvenues. Veuillez soumettre une pull request ou ouvrir une issue pour discuter des changements proposés.

## Tests

### Test avec cURL

Vous pouvez tester le service NLP directement avec cURL. Assurez-vous que le service est en cours d'exécution, puis utilisez la commande suivante :

```bash
curl http://localhost:5000/analyze -H "Content-Type: application/json" -d '{"content":"SGVsbG8gV29ybGQ="}'
```

Note : Le contenu est encodé en base64. "SGVsbG8gV29ybGQ=" décode en "Hello World".

Vous devriez recevoir une réponse JSON contenant les résultats de l'analyse.

### Test avec le script PHP

Un script de test PHP est fourni dans l'extension pour vérifier la connexion entre TYPO3 et le service NLP. Pour l'exécuter :

1. Assurez-vous que le service Python NLP est en cours d'exécution.
2. Depuis le répertoire racine de votre projet TYPO3, exécutez :

   ```bash
   php packages/semantic_suggestion/Tests/python_api_test.php
   ```

Ce script enverra une requête de test au service NLP et affichera les résultats. Si tout fonctionne correctement, vous devriez voir un tableau PHP imprimé avec les résultats de l'analyse.

## Dépannage

Si les tests échouent :

1. Vérifiez que le service Python est en cours d'exécution sur `http://localhost:5000`.
2. Assurez-vous que toutes les dépendances Python sont correctement installées.
3. Vérifiez les logs du service Python pour toute erreur.
4. Si le test PHP échoue mais que le test cURL réussit, vérifiez que PHP peut faire des requêtes réseau et que l'extension Guzzle est installée.

[... Reste du contenu ...]