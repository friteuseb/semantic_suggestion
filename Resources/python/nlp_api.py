import sys
import json
import logging
from pathlib import Path
import re
from collections import Counter
import base64

from flask import Flask, request, jsonify
import nltk
from nltk.tokenize import word_tokenize, sent_tokenize
from nltk.corpus import stopwords
from nltk.util import ngrams

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
    sentiment_analyzer = pipeline("sentiment-analysis", model="nlptown/bert-base-multilingual-uncased-sentiment")
except ImportError:
    TRANSFORMERS_AVAILABLE = False
    print("Transformers n'est pas installé. L'analyse de sentiment sera limitée.")

app = Flask(__name__)

class NLPAnalyzer:
    def __init__(self):
        self.setup_logging()
        self.load_resources()

    def setup_logging(self):
        logging.basicConfig(
            level=logging.INFO,
            format='%(asctime)s - %(levelname)s - %(message)s',
            handlers=[
                logging.StreamHandler()
            ]
        )
        self.logger = logging.getLogger(__name__)

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

    def analyze_text(self, text):
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
            "lexical_diversity": self.calculate_lexical_diversity(text),
            "top_n_grams": self.extract_top_n_grams(text),
            "semantic_coherence": self.calculate_semantic_coherence(text),
            "sentiment_distribution": self.analyze_sentiment_distribution(text)
        }
        
        result["average_sentence_length"] = result["word_count"] / result["sentence_count"] if result["sentence_count"] > 0 else 0

        self.logger.info(f"Analysis completed successfully. Result: {json.dumps(result)}")
        return result

    def analyze_sentiment(self, text):
        if TRANSFORMERS_AVAILABLE:
            result = sentiment_analyzer(text[:512])[0]
            return result['label']
        else:
            # Implémentation simplifiée de l'analyse de sentiment
            positive_words = set(['bon', 'excellent', 'super', 'génial', 'heureux'])
            negative_words = set(['mauvais', 'terrible', 'horrible', 'triste', 'déçu'])
            words = word_tokenize(text.lower(), language='french')
            sentiment_score = sum(1 for word in words if word in positive_words) - sum(1 for word in words if word in negative_words)
            if sentiment_score > 0:
                return "POSITIVE"
            elif sentiment_score < 0:
                return "NEGATIVE"
            else:
                return "NEUTRAL"

    def calculate_lexical_diversity(self, text):
        words = word_tokenize(text.lower(), language='french')
        return len(set(words)) / len(words) if words else 0

    def extract_top_n_grams(self, text, n=2, top=5):
        words = word_tokenize(text.lower(), language='french')
        n_grams = list(ngrams(words, n))
        return [' '.join(gram) for gram, _ in Counter(n_grams).most_common(top)]

    def calculate_semantic_coherence(self, text):
        sentences = sent_tokenize(text, language='french')
        if len(sentences) < 2:
            return 1.0
        coherence = sum(len(set(word_tokenize(s1)) & set(word_tokenize(s2))) 
                        for s1, s2 in zip(sentences, sentences[1:])) / (len(sentences) - 1)
        return coherence / max(len(word_tokenize(s)) for s in sentences)

    def analyze_sentiment_distribution(self, text):
        sentences = sent_tokenize(text, language='french')
        sentiments = [self.analyze_sentiment(sentence) for sentence in sentences]
        return {
            "POSITIVE": sentiments.count("POSITIVE") / len(sentiments),
            "NEGATIVE": sentiments.count("NEGATIVE") / len(sentiments),
            "NEUTRAL": sentiments.count("NEUTRAL") / len(sentiments)
        }

    def extract_keyphrases(self, text, num_phrases=5):
        words = word_tokenize(text.lower(), language='french')
        stop_words = set(stopwords.words('french'))
        word_freq = Counter(word for word in words if word.isalnum() and word not in stop_words)
        return [word for word, _ in word_freq.most_common(num_phrases)]

    def categorize_text(self, text):
        # Implémentation simplifiée de la catégorisation
        return "Non catégorisé"

    def extract_named_entities(self, text):
        if self.nlp:
            doc = self.nlp(text)
            return [{"text": ent.text, "label": ent.label_} for ent in doc.ents]
        return []

    def calculate_readability_score(self, text):
        words = word_tokenize(text, language='french')
        sentences = sent_tokenize(text, language='french')
        if not words or not sentences:
            return 0
        avg_sentence_length = len(words) / len(sentences)
        complex_words = sum(1 for word in words if len(word) > 6)
        percent_complex_words = (complex_words / len(words)) * 100
        return 206.835 - (1.015 * avg_sentence_length) - (0.846 * percent_complex_words)

analyzer = NLPAnalyzer()

@app.route('/analyze', methods=['POST'])
def analyze():
    data = request.json
    if not data or 'content' not in data:
        return jsonify({"error": "No text provided for analysis"}), 400

    text = data['content']
    try:
        text = base64.b64decode(text).decode('utf-8')
    except:
        pass

    analyzer.logger.info(f"Received text for analysis: {text[:50]}...")
    result = analyzer.analyze_text(text)
    return jsonify(result)

if __name__ == "__main__":
    app.run(debug=True, host='0.0.0.0', port=5000)