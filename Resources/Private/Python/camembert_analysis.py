import sys
import json
import logging
from pathlib import Path
import re
from collections import Counter
import nltk
from nltk.tokenize import word_tokenize, sent_tokenize
from nltk.corpus import stopwords

# Configuration du logging
log_file = Path(__file__).parent / "nlp_analysis.log"
logging.basicConfig(
    level=logging.DEBUG,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler(log_file),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

# Téléchargement des ressources NLTK nécessaires
try:
    nltk.download('punkt', quiet=True)
    nltk.download('stopwords', quiet=True)
except Exception as e:
    logger.warning(f"Impossible de télécharger les ressources NLTK: {str(e)}")

# Chargement du modèle spaCy pour le français (optionnel)
try:
    import spacy
    nlp = spacy.load("fr_core_news_sm")
except Exception as e:
    logger.warning(f"Impossible de charger spaCy: {str(e)}")
    nlp = None

def extract_keyphrases(text, num_phrases=5, max_words=20):
    logger.info("Extracting key phrases")
    try:
        sentences = sent_tokenize(text, language='french')
        stop_words = set(stopwords.words('french'))
        
        word_freq = Counter(word.lower() for sentence in sentences for word in word_tokenize(sentence, language='french') if word.lower() not in stop_words)
        
        scored_sentences = [(sum(word_freq[word.lower()] for word in word_tokenize(sentence, language='french') if word.lower() not in stop_words), sentence) for sentence in sentences]
        
        top_sentences = sorted(scored_sentences, reverse=True)[:num_phrases]
        
        keyphrases = [' '.join(sentence.split()[:max_words]) + ('...' if len(sentence.split()) > max_words else '') for _, sentence in top_sentences]
        
        logger.info(f"Extracted key phrases: {keyphrases}")
        return keyphrases
    except Exception as e:
        logger.error(f"Error in key phrase extraction: {str(e)}")
        return []

def categorize_text(text):
    logger.info("Categorizing text")
    categories = {
        'Technologie': ['smartphone', 'écran', 'digital', 'internet', 'application', 'téléphone', 'tablette', 'ordinateur'],
        'Santé': ['sommeil', 'santé', 'bien-être', 'fatigue', 'repos', 'mélatonine', 'hormone', 'rythme circadien', 'insomnie'],
        'Éducation': ['adolescent', 'école', 'apprentissage', 'éducation', 'étude', 'concentration'],
        'Divertissement': ['binge', 'watching', 'série', 'film', 'divertissement', 'jeu'],
        'Productivité': ['travail', 'productivité', 'performance', 'concentration', 'efficacité']
    }
    
    text = text.lower()
    scores = {category: sum(text.count(word) for word in words) for category, words in categories.items()}
    
    if max(scores.values()) > 0:
        category = max(scores, key=scores.get)
    else:
        category = "Non catégorisé"
    
    logger.info(f"Categorized as: {category}")
    return category

def calculate_readability_score(text):
    words = word_tokenize(text, language='french')
    sentences = sent_tokenize(text, language='french')
    if not words or not sentences:
        return 0
    avg_sentence_length = len(words) / len(sentences)
    complex_words = len([word for word in words if len(word) > 6])
    percent_complex_words = (complex_words / len(words)) * 100
    flesch_score = 206.835 - (1.015 * avg_sentence_length) - (0.846 * percent_complex_words)
    return max(0, min(100, flesch_score))


def extract_named_entities(text):
    logger.info("Extracting named entities")
    if nlp:
        try:
            doc = nlp(text)
            entities = [ent.text for ent in doc.ents]
            # Nettoyage amélioré des entités
            cleaned_entities = [re.sub(r'^[lL]e\s|^[lL]a\s|^[lL]\'\s', '', ent).strip() for ent in entities]
            cleaned_entities = [ent for ent in cleaned_entities if len(ent) > 1 and "'" not in ent]  # Supprimer les entités trop courtes et celles avec des apostrophes isolées
            logger.info(f"Extracted named entities: {cleaned_entities}")
            return cleaned_entities
        except Exception as e:
            logger.error(f"Error in named entity extraction: {str(e)}")
    return []

def analyze_sentiment(text):
    logger.info("Analyzing sentiment")
    try:
        from transformers import pipeline
        sentiment_pipeline = pipeline("sentiment-analysis", model="nlptown/bert-base-multilingual-uncased-sentiment")
        result = sentiment_pipeline(text[:512])[0]  # Limit to 512 tokens
        sentiment = "positif" if result['label'] in ['5 stars', '4 stars'] else "négatif"
        logger.info(f"Sentiment analysis completed: {sentiment}")
        return sentiment
    except Exception as e:
        logger.error(f"Error in sentiment analysis: {str(e)}")
        return "neutral"

def analyze_text(text):
    try:
        logger.info(f"Starting analysis of text: {text[:50]}...")
        
        result = {
            "sentiment": analyze_sentiment(text),
            "keyphrases": extract_keyphrases(text),
            "category": categorize_text(text),
            "named_entities": extract_named_entities(text),
            "readability_score": calculate_readability_score(text)
        }

        logger.info(f"Analysis completed successfully. Result: {json.dumps(result)}")
        print(json.dumps(result))
    except Exception as e:
        logger.exception(f"Analysis error: {str(e)}")
        print(json.dumps({
            "error": f"Analysis error: {str(e)}",
            "sentiment": "neutral",
            "keyphrases": [],
            "category": "Non catégorisé",
            "named_entities": [],
            "readability_score": 0
        }))

if __name__ == "__main__":
    if len(sys.argv) < 2:
        logger.error("No text provided for analysis")
        print(json.dumps({
            "error": "No text provided for analysis"
        }))
        sys.exit(1)
    
    text = sys.argv[1]
    logger.info(f"Received text for analysis: {text[:50]}...")
    analyze_text(text)