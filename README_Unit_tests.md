## Tests Unitaires pour PageAnalysisService

Ce document détaille les tests unitaires implémentés pour la classe `PageAnalysisService`, en expliquant leur objectif et leur fonctionnement.

**Tests de Base**

* **getWeightedWordsReturnsCorrectWeights**
   * Vérifie que la méthode `getWeightedWords` calcule correctement les poids des mots en fonction des champs analysés et de leurs poids respectifs.

* **calculateSimilarityReturnsExpectedValuesForSimilarPages**
   * S'assure que la méthode `calculateSimilarity` renvoie des valeurs de similarité élevées pour des pages ayant un contenu similaire.
   * Vérifie également que le "boost de récence" est faible pour des pages modifiées à des dates proches.

* **calculateSimilarityReturnsLowValuesForDissimilarPages**
   * Confirme que la similarité est faible pour des pages au contenu très différent.
   * Le "boost de récence" devrait être élevé pour des pages modifiées à des dates éloignées.

* **calculateSimilarityHandlesEmptyFields**
   * Gère le cas où certains champs analysés sont vides. La similarité devrait toujours être calculable, mais inférieure à 1.

* **recencyWeightAffectsFinalSimilarity**
   * Vérifie que le paramètre `recencyWeight` influence correctement la similarité finale, en favorisant les pages récentes lorsque le poids est élevé.

* **similarityCalculationWithMissingFields**
   * Teste le calcul de similarité lorsque des champs sont complètement absents dans une des pages.

* **similarityCalculationWithDifferentFieldWeights**
   * S'assure que les différents poids attribués aux champs sont pris en compte dans le calcul de similarité.

* **similarityCalculationWithKeywords**
   * Vérifie que les mots-clés, avec leur poids potentiellement élevé, influencent significativement la similarité.

* **findCommonKeywordsReturnsCorrectResults**
   * Teste la méthode `findCommonKeywords`, qui identifie les mots-clés communs entre deux pages.

* **determineRelevanceReturnsCorrectCategory**
   * S'assure que la méthode `determineRelevance` catégorise correctement la pertinence en fonction de la similarité calculée (Haute, Moyenne, Basse).

* **calculateRecencyBoostReturnsExpectedValues**
   * Vérifie que le "boost de récence" est calculé correctement, en favorisant les pages les plus récentes.

* **calculateFieldSimilarityHandlesEmptyFields**
   * Gère le cas où le contenu d'un champ est vide ou absent lors du calcul de similarité pour ce champ spécifique.

* **analyzePagesShouldHandleEmptyInput**
   * Teste le comportement de la méthode principale `analyzePages` lorsqu'on lui fournit une liste de pages vide.

* **preparePageDataHandlesAllConfiguredFields**
   * Vérifie que la méthode `preparePageData` prépare correctement les données de chaque page, en incluant tous les champs configurés pour l'analyse.

**Tests de Robustesse et Cas Limites**

* **similarityCalculationWithLargeContentDifference**
   * S'assure que la similarité reste faible même si une page a un contenu beaucoup plus long que l'autre.

* **similarityCalculationWithIdenticalContent**
   * Vérifie que la similarité est maximale (1.0) pour des pages identiques.

* **identicalContentShouldHaveMaximumSimilarity**
   * Idem que le précédent, mais formulé différemment pour plus de clarté.

* **completelyDifferentContentShouldHaveMinimumSimilarity**
   * Confirme que la similarité est minimale (proche de 0) pour des pages complètement différentes.

* **partiallyRelatedContentShouldHaveModerateSimilarity**
   * Vérifie que la similarité est modérée pour des pages partiellement liées.

* **keywordsShouldHaveSignificantImpactOnSimilarity**
   * Réaffirme l'importance des mots-clés dans le calcul de similarité.

* **recencyWeightShouldAffectFinalSimilarity**
   * Illustre à nouveau l'influence du poids de récence sur la similarité finale.

* **shortContentShouldNotSkewSimilarityCalculation**
   * S'assure qu'un contenu très court dans une page ne fausse pas le calcul de similarité.

* **fieldWeightsShouldInfluenceSimilarityCalculation**
   * Démontre que les poids attribués aux différents champs sont bien pris en compte.

* **similarityCalculationShouldHandleMissingFields**
   * Même test que `similarityCalculationWithMissingFields`, mais avec une formulation légèrement différente.

* **extremelyLongContentShouldNotOverwhelmOtherFactors**
   * Vérifie qu'un contenu extrêmement long ne domine pas complètement le calcul de similarité, laissant de la place aux autres facteurs.

* **stopWordsShouldNotSignificantlyInfluenceSimilarity**
   * Confirme que les "stop words" (mots courants peu significatifs) n'ont pas un impact majeur sur la similarité.

* **similarityCalculationShouldBeCaseInsensitive**
   * S'assure que la casse (majuscules/minuscules) n'influence pas le calcul de similarité.

* **similarityCalculationShouldHandleMultilingualContent**
   * Vérifie que la similarité peut être détectée même entre des contenus dans différentes langues.

* **similarityCalculationShouldHandleSpecialCharacters**
   * Les caractères spéciaux ne devraient pas poser de problème pour le calcul de similarité

* **similarityCalculationShouldHandleNumericalContent**
   * Le contenu numérique (chiffres, dates, etc.) est pris en compte dans la similarité

* **similarityCalculationShouldHandleEmptyContent**
   * Gère le cas où le contenu d'une page est vide

* **similarityCalculationShouldHandleDuplicateWords**
   * La répétition excessive de mots ne devrait pas augmenter artificiellement la similarité

* **similarityCalculationShouldConsiderWordOrder**
   * L'ordre des mots est pris en compte, mais ne devrait pas être le facteur dominant

* **similarityCalculationShouldHandleLongPhrases**
   * Les phrases longues et complexes sont gérées correctement

* **recencyBoostShouldNotOverrideSemanticallyDissimilarContent**
   * Même si une page est récente, elle ne devrait pas être considérée similaire à une page ancienne si leur contenu est très différent

* **similarityCalculationShouldHandleExtremeCases**
   * Teste des cas limites comme une page vide ou une page extrêmement longue

* **similarityCalculationShouldBeConsistentRegardlessOfPageOrder**
   * Le calcul de similarité doit être le même quel que soit l'ordre dans lequel les pages sont comparées

**Conclusion**

Ces tests unitaires couvrent un large éventail de scénarios pour garantir que la classe `PageAnalysisService` fonctionne correctement et de manière robuste dans diverses situations. Ils valident la logique de calcul de similarité, la prise en compte des différents facteurs (poids des champs, récence, etc.), et la gestion des cas particuliers ou limites.

**Note:** Certains tests peuvent sembler redondants, mais ils sont formulés différemment pour souligner des aspects spécifiques du comportement attendu de la classe.

