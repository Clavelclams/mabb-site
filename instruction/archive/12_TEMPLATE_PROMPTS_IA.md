# Templates Prompts IA — MABB / PIRB

> Dernière mise à jour : 2026-02-12
> Templates réutilisables pour les sessions de travail avec un assistant IA.

## Contexte obligatoire (à inclure dans chaque prompt)

```
Tu travailles sur le projet MABB / PIRB.
Avant toute action :
- Lis intégralement le dossier /instruction/.
- Applique strictement la gouvernance documentaire (00_GOUVERNANCE_DOC.md).
- Appuie-toi sur les fichiers existants (roadmaps, registres, arborescence).
- Ne supprime, ne renomme, ne réorganise rien sans le documenter.

Pendant l'exécution :
- Toute modification fonctionnelle → mise à jour roadmap concernée.
- Toute contrainte technique → entrée dans 06_REGISTRE_TECHNIQUE.md.
- Toute décision structurante → entrée dans 08_ADR.md.
- Ne crée pas de redondance documentaire.

Après l'exécution :
- Mets à jour 13_CLAUDE_LOG.md (date, objectif, actions, fichiers modifiés).
- Si conflit documentaire → STOP et signale sans agir.
```

## Template : Création d'entité

```
Crée l'entité Doctrine [NomEntité] dans src/Entity/[Module]/.

Contraintes :
- Respecter le modèle de données du CDC (section 4).
- Ajouter club_id si donnée métier (multi-tenant, cf. ADR-0003).
- Ajouter created_at, updated_at (timestamps).
- Ajouter deleted_at si soft delete applicable (cf. RT-0006).
- Créer le Repository correspondant dans src/Repository/[Module]/.
- Créer la migration Doctrine.
- Vérifier la cohérence avec le dictionnaire_db (shemas/dictionnaire_db.md).
```

## Template : Création de Voter

```
Crée le Voter Symfony [NomVoter] dans src/Security/Voter/.

Contraintes :
- Vérifier le filtrage par club_id (cf. RT-0001, ADR-0003).
- Respecter la matrice des permissions du CDC (section 2.5).
- Tester que les données du club A ne sont jamais visibles par un utilisateur du club B.
```

## Template : Création d'endpoint API

```
Crée l'endpoint API Platform [Méthode] [Route].

Contraintes :
- Groupes de sérialisation stricts (cf. CDC section 9.3, RT-0008).
- Filtrage club_id + Voter sur l'accès.
- Rôles autorisés : [préciser].
- Rate limiting si endpoint sensible.
```

## Template : Audit & mise à jour documentation

```
Lis intégralement les fichiers du dossier /instruction/.
Analyse la cohérence, détecte les incohérences, manques, doublons ou obsolescences.
Propose et applique les mises à jour nécessaires en respectant la gouvernance (00_GOUVERNANCE_DOC.md).
Si conflit documentaire → STOP et signale sans agir.
Mets à jour 13_CLAUDE_LOG.md à la fin.
```
