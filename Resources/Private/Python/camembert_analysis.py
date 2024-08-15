import sys
import json

try:
    from transformers import CamembertTokenizer, CamembertForSequenceClassification, pipeline
except ImportError as e:
    print(json.dumps({
        "error": f"Import error: {str(e)}. Please make sure all required libraries are installed."
    }))
    sys.exit(1)

def analyze_text(text):
    try:
    # Chargement du modèle et du tokenizer
    tokenizer = CamembertTokenizer.from_pretrained("camembert-base")
    model = CamembertForSequenceClassification.from_pretrained("camembert-base")

    # Analyse de sentiment
    sentiment_pipeline = pipeline("sentiment-analysis", model=model, tokenizer=tokenizer)
    sentiment_result = sentiment_pipeline(text)[0]
    sentiment = "positif" if sentiment_result['label'] == "LABEL_1" else "négatif"

    # Extraction de mots-clés (utilisation des tokens les plus fréquents comme approximation)
    tokens = tokenizer.tokenize(text)
    word_freq = {}
    for token in tokens:
        if token.startswith("▁"):  # Les tokens qui commencent par ▁ sont généralement des mots complets
            word = token[1:]
            word_freq[word] = word_freq.get(word, 0) + 1
    keywords = sorted(word_freq, key=word_freq.get, reverse=True)[:5]

    # Pour la classification et l'extraction d'entités nommées, nous aurions besoin de modèles spécifiques
    # Ici, nous retournons des valeurs par défaut
    category = "non classifié"
    named_entities = []

      result = {
            "sentiment": sentiment,
            "keywords": keywords,
            "category": category,
            "named_entities": named_entities
        }

        print(json.dumps(result))
    except Exception as e:
        print(json.dumps({
            "error": f"Analysis error: {str(e)}"
        }))
        sys.exit(1)

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({
            "error": "No text provided for analysis"
        }))
        sys.exit(1)
    
    text = sys.argv[1]
    analyze_text(text)