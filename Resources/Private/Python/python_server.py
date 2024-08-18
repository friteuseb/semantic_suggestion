import json
import logging
from pathlib import Path
import re
from collections import Counter
import base64

import nltk
from nltk.tokenize import word_tokenize, sent_tokenize
from nltk.corpus import stopwords
from flask import Flask, request, jsonify

# Gestion des dépendances optionnelles
try:
    import spacy
    SPACY_AVAILABLE = True
except ImportError:
    SPACY_AVAILABLE = False
    print("Spacy n'est pas installé. L'extraction d'entités nommées sera désactivée.")

try:
    from transformers import pipeline
    TRANSFORMERS_AVAILABLE = True
except ImportError:
    TRANSFORMERS_AVAILABLE = False
    print("Transformers n'est pas installé. L'analyse de sentiment sera limitée.")

app = Flask(__name__)

class NLPAnalyzer:
    def __init__(self, log_file_path=None):
        self.logger = self.setup_logging(log_file_path)
        self.load_resources()

    def setup_logging(self, log_file_path=None):
        log_file = Path(log_file_path) if log_file_path else Path(__file__).parent / "nlp_analysis.log"
        logging.basicConfig(
            level=logging.DEBUG,
            format='%(asctime)s - %(levelname)s - %(message)s',
            handlers=[
                logging.FileHandler(log_file),
                logging.StreamHandler()
            ]
        )
        return logging.getLogger(__name__)

    def load_resources(self):
        self.load_nltk_resources()
        self.nlp = self.load_spacy()

    def load_nltk_resources(self):
        try:
            nltk.download('punkt', quiet=True)
            nltk.download('stopwords', quiet=True)
        except Exception as e:
            self.logger.warning(f"Impossible de télécharger les ressources NLTK: {str(e)}")

    def load_spacy(self):
        if SPACY_AVAILABLE:
            try:
                return spacy.load("fr_core_news_sm")
            except Exception as e:
                self.logger.warning(f"Impossible de charger spaCy: {str(e)}")
        return None

    # Les méthodes extract_keyphrases, categorize_text, calculate_readability_score,
    # extract_named_entities, analyze_sentiment restent identiques à votre script original

    def analyze_text(self, text):
        try:
            self.logger.info(f"Starting analysis of text: {text[:50]}...")
            
            result = {
                "sentiment": self.analyze_sentiment(text),
                "keyphrases": self.extract_keyphrases(text),
                "category": self.categorize_text(text),
                "named_entities": self.extract_named_entities(text),
                "readability_score": self.calculate_readability_score(text),
                "word_count": len(word_tokenize(text, language='french')),
                "sentence_count": len(sent_tokenize(text, language='french')),
                "language": "fr",
            }
            
            result["average_sentence_length"] = result["word_count"] / result["sentence_count"] if result["sentence_count"] > 0 else 0

            self.logger.info(f"Analysis completed successfully. Result: {json.dumps(result)}")
            return result
        except Exception as e:
            self.logger.exception(f"Analysis error: {str(e)}")
            return {
                "error": f"Analysis error: {str(e)}",
                "sentiment": "neutral",
                "keyphrases": [],
                "category": "Non catégorisé",
                "named_entities": [],
                "readability_score": 0,
                "word_count": 0,
                "sentence_count": 0,
                "average_sentence_length": 0,
                "language": "unknown"
            }

analyzer = NLPAnalyzer()

@app.route('/analyze', methods=['POST'])
def analyze():
    data = request.json
    if not data or 'content' not in data:
        return jsonify({"error": "No text provided for analysis"}), 400

    text = data['content']
    try:
        # Essayer de décoder en base64, si ça échoue, utiliser le texte tel quel
        text = base64.b64decode(text).decode('utf-8')
    except:
        pass

    analyzer.logger.info(f"Received text for analysis: {text[:50]}...")
    result = analyzer.analyze_text(text)
    return jsonify(result)

if __name__ == "__main__":
    app.run(debug=True, host='0.0.0.0', port=5000)