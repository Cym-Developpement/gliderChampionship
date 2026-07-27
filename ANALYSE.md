# Analyse de l'application Glider Championship

## Description

Application web de **suivi en temps réel et classement** pour un championnat de vol à voile (planeur). Elle permet de :
- Suivre les planeurs en direct sur une carte interactive
- Afficher un classement live des pilotes
- Intégrer les données de position OGN (Open Glider Network)

---

## Stack technique

| Composant | Technologie |
|-----------|-------------|
| Backend | Laravel 12 (PHP 8.2+) |
| Base de données | SQLite (configurable MySQL/PostgreSQL) |
| Frontend | Vanilla JS (ES6+) + Leaflet 1.9.4 |
| CSS | Bootstrap 5.3.3 + Tailwind CSS 4.0 |
| Build | Vite 7.0.7 |
| Données temps réel | OGN (Open Glider Network) |
| Cartographie | OpenStreetMap + OpenAIP |

---

## Ce qui est fait

### Carte interactive
- [x] Affichage carte Leaflet avec tuiles OpenStreetMap
- [x] Couche overlay OpenAIP (espaces aériens) si clé API configurée
- [x] Marqueurs planeurs avec rotation selon le cap
- [x] Auto-zoom sur la zone de compétition au chargement
- [x] Système de cache/proxy pour les tuiles OSM et OpenAIP (TTL 7 jours)

### Classement live (Leaderboard)
- [x] Classement des pilotes trié par score décroissant
- [x] Pagination automatique (10 pilotes/page avec rotation)
- [x] Badges médaille pour le top 3 (Or/Argent/Bronze)
- [x] Affichage callsign, nom, marque/modèle du planeur
- [x] Photos des pilotes

### Télémétrie en temps réel
- [x] Badges d'altitude (code couleur : bleu si > 500m, rouge sinon)
- [x] Vitesse sol
- [x] Vitesse verticale (vario) avec indicateurs +/-
- [x] Rafraîchissement auto : positions toutes les 10s, scores toutes les 15s

### Intégration OGN
- [x] Mode production : données réelles OGN via `live.glidernet.org`
- [x] Mode développement : positions simulées déterministes (`DEV_FAKE_POSITIONS=true`)
- [x] Filtrage des participants par immatriculation
- [x] Requêtes par zone géographique (bounding box)

### Modèle de données
- [x] Compétitions (nom, point de départ, coordonnées, rayon)
- [x] Participants (immatriculation, OGN ID, planeur)
- [x] Pilotes (callsign, photo, planeur)
- [x] Scores participants et pilotes avec horodatage
- [x] Relation many-to-many pilotes/participants (table pivot)
- [x] 14 fichiers de migration

### Alimentation des données
- [x] Seeder d'échantillon (18 participants, 18 pilotes avec scores)
- [x] Import JSON (`JsonImportSeeder` pour `competition.json` et `participants.json`)

### API endpoints
- [x] `GET /api/competition` - Infos compétition
- [x] `GET /api/participants` - Liste participants
- [x] `GET /api/pilots` - Liste pilotes
- [x] `GET /api/live` - Scores participants en live
- [x] `GET /api/live-pilots` - Scores pilotes en live
- [x] `GET /positions` - Proxy positions OGN
- [x] `GET /tiles/osm/{z}/{x}/{y}.png` - Proxy tuiles OSM
- [x] `GET /tiles/openaip/{z}/{x}/{y}.png` - Proxy tuiles OpenAIP

---

## Ce qui reste a faire

### Interface d'administration
- [ ] Panel admin pour gérer les compétitions (CRUD)
- [ ] Interface pour ajouter/modifier/supprimer des pilotes
- [ ] Interface pour ajouter/modifier/supprimer des participants
- [ ] Interface d'upload de photos de pilotes
- [ ] Interface de saisie/modification des scores

### Authentification & Autorisation
- [ ] Intégration de l'authentification utilisateur (modèle User existe mais non connecté)
- [ ] Protection des routes d'administration
- [ ] Rôles et permissions (admin, organisateur, spectateur)

### Gestion des scores
- [ ] Mécanisme de saisie des scores (actuellement lecture seule depuis la BDD)
- [ ] Import de scores depuis un fichier externe
- [ ] Calcul automatique des scores (si règles de scoring définies)
- [ ] Historique et évolution des scores dans le temps

### Améliorations frontend
- [ ] Vue responsive / mobile optimisée
- [ ] Mode plein écran pour affichage sur écran de compétition
- [ ] Tracé des trajectoires des planeurs sur la carte
- [ ] Affichage des zones de tâche/circuit de compétition sur la carte
- [ ] Filtrage/sélection d'un pilote spécifique sur la carte
- [ ] Popup d'informations détaillées au clic sur un marqueur planeur

### Fonctionnalités temps réel
- [ ] WebSockets pour le push temps réel (actuellement polling HTTP)
- [ ] Notifications en cas d'événement (atterrissage, franchissement de seuil, etc.)

### Données & Import
- [ ] Interface web pour importer competition.json / participants.json
- [ ] Validation des données importées
- [ ] Export des résultats (PDF, CSV)

### Tests
- [ ] Tests unitaires (stubs existants mais vides)
- [ ] Tests fonctionnels des API
- [ ] Tests end-to-end du frontend

### Documentation & Déploiement
- [ ] README spécifique au projet (actuellement le README par défaut de Laravel)
- [ ] Guide d'installation et configuration
- [ ] Documentation de l'API
- [ ] Configuration de déploiement production (Docker, serveur, etc.)

### Divers
- [ ] Gestion multi-compétitions (actuellement une seule compétition active)
- [ ] Internationalisation (actuellement en français uniquement)
- [ ] Logs et monitoring des erreurs OGN
- [ ] Gestion des cas de perte de connexion OGN

---

## Structure du projet

```
app/
├── Http/Controllers/
│   ├── ApiController.php        # Endpoints API (compétition, pilotes, scores)
│   ├── OgnController.php        # Proxy positions OGN
│   └── TileProxyController.php  # Cache/proxy tuiles cartographiques
├── Models/
│   ├── Competition.php
│   ├── Participant.php
│   ├── Pilot.php
│   ├── Score.php
│   ├── PilotScore.php
│   └── User.php
database/
├── migrations/                  # 14 migrations
├── seeders/
│   ├── SampleDataSeeder.php     # Données d'exemple
│   └── JsonImportSeeder.php     # Import JSON
resources/views/
└── home.blade.php               # Vue principale (carte + classement)
routes/
└── web.php                      # Toutes les routes (8 endpoints)
```

---

## Variables d'environnement clés

| Variable | Description |
|----------|-------------|
| `DEV_FAKE_POSITIONS` | `true` pour simuler les positions en dev |
| `OPENAIP_API_KEY` | Clé API OpenAIP pour les espaces aériens |
| `DB_CONNECTION` | `sqlite` par défaut |
