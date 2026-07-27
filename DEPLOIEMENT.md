# Déploiement — Glider Championship

Procédure d'installation sur un serveur Linux (Debian 12 / Ubuntu 24.04), Nginx + PHP-FPM, base SQLite.

---

## 1. Prérequis serveur

```bash
sudo apt update
sudo apt install -y nginx php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-xml \
    php8.3-mbstring php8.3-curl php8.3-zip unzip git curl
```

Versions minimales : **PHP 8.2** (8.3 recommandé), extensions `pdo_sqlite`, `simplexml` (parsing OGN), `mbstring`, `curl`, `zip`.

Composer :

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Node.js **n'est pas nécessaire** : toutes les vues utilisées chargent Bootstrap et Leaflet depuis un CDN. Vite/Tailwind ne servent qu'à `welcome.blade.php`, qui n'est pas routée. (Si vous voulez tout de même builder : `npm ci && npm run build`.)

**Accès réseau sortant requis** — le serveur doit pouvoir joindre :
- `http://live.glidernet.org` (positions OGN)
- `https://tile.openstreetmap.org` (tuiles carte)
- `https://tiles.openaip.net` (espaces aériens, optionnel)

---

## 2. Récupération du code

```bash
sudo mkdir -p /var/www/glider
sudo chown $USER:$USER /var/www/glider
# depuis votre poste, ou git clone si un dépôt existe
rsync -a --exclude vendor --exclude node_modules --exclude .env \
      --exclude database/database.sqlite ./ serveur:/var/www/glider/
```

> Le projet n'est actuellement pas sous git. Mettre en place un dépôt avant le premier déploiement est fortement recommandé (`git init`, `.env` et `database/database.sqlite` exclus via `.gitignore`).

```bash
cd /var/www/glider
composer install --no-dev --optimize-autoloader
```

---

## 2 bis. Installation assistée par navigateur (alternative aux §3 à §6)

Le fichier `public/install.php` effectue en une passe tout ce que décrivent les sections 3 à 6 : vérification des prérequis, écriture du `.env` (avec `APP_KEY` générée et `APP_DEBUG=false`), test de connexion à la base, migrations, création du compte administrateur, lien `public/storage`, création des répertoires de stockage et caches de production.

```bash
cd /var/www/glider
composer install --no-dev --optimize-autoloader
sudo chown -R www-data:www-data storage bootstrap/cache database
sudo chown www-data:www-data .          # nécessaire pour que l'installeur écrive .env
```

Ouvrir ensuite `https://championnat.example.com/install.php` et suivre le formulaire.

En fin de procédure, l'installeur pose un verrou `storage/installed.lock` (il refuse de se relancer tant qu'il est présent) et propose un bouton d'auto-suppression. **Supprimez `public/install.php` dans tous les cas** : tant qu'il est en ligne, quiconque supprimant le verrou pourrait réécrire la configuration.

```bash
rm -f /var/www/glider/public/install.php
sudo chown root:root /var/www/glider          # rendre la racine non inscriptible après coup
```

Si un `.env` existe déjà, il est sauvegardé en `.env.backup-AAAAMMJJ-HHMMSS` avant d'être remplacé.

La suite reste à faire manuellement : Nginx (§7), HTTPS, cron (§8), configuration de la compétition (§9), sauvegardes (§11).

---

## 3. Configuration

```bash
cp .env.example .env
php artisan key:generate
```

Éditer `.env` :

```dotenv
APP_NAME="Glider Championship"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://championnat.example.com

APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr

LOG_LEVEL=warning

DB_CONNECTION=sqlite
# laisser vide -> database/database.sqlite

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true      # si HTTPS
CACHE_STORE=database
QUEUE_CONNECTION=database

# Positions simulées : DOIT rester false en production
DEV_FAKE_POSITIONS=false

# Espaces aériens (optionnel) — sans clé, la couche OpenAIP est simplement absente
OPENAIP_API_KEY=
OPENAIP_TILES_URL=https://tiles.openaip.net/tiles/{z}/{x}/{y}.png?apiKey={API_KEY}

# Durée de cache des tuiles en secondes (défaut 7 jours)
TILE_CACHE_TTL_SECONDS=604800
```

`APP_DEBUG=false` est impératif : en `true`, une exception expose la stack trace et le contenu de `.env`.

### Variante MySQL/MariaDB

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=glider
DB_USERNAME=glider
DB_PASSWORD=•••
```

```sql
CREATE DATABASE glider CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'glider'@'localhost' IDENTIFIED BY '•••';
GRANT ALL PRIVILEGES ON glider.* TO 'glider'@'localhost';
```

---

## 4. Base de données

```bash
touch database/database.sqlite          # uniquement en SQLite
php artisan migrate --force
php artisan db:seed --class=AdminUserSeeder --force
```

`AdminUserSeeder` crée le compte `admin@glider.local` / `admin1234`.
**Changez ce mot de passe immédiatement après le premier déploiement** :

```bash
php artisan tinker --execute="\App\Models\User::where('email','admin@glider.local')->update(['password'=>bcrypt('VOTRE_MOT_DE_PASSE')]);"
```

Ne pas lancer `php artisan db:seed` sans `--class` : le seeder par défaut injecte 18 participants et pilotes de démonstration.

---

## 5. Stockage et permissions

Le lien `public/storage` présent dans les sources pointe vers un chemin de développement — il faut le recréer :

```bash
rm -f public/storage
php artisan storage:link
```

```bash
sudo chown -R www-data:www-data /var/www/glider/storage \
                                /var/www/glider/bootstrap/cache \
                                /var/www/glider/database
sudo find /var/www/glider/storage -type d -exec chmod 775 {} \;
sudo find /var/www/glider/storage -type f -exec chmod 664 {} \;
```

En SQLite, `www-data` doit pouvoir écrire **le fichier `.sqlite` et le répertoire `database/`** (SQLite y crée les fichiers `-wal`/`-journal`).

Répertoires alimentés à l'exécution : `storage/app/public/pilots` (photos), `storage/app/private/tiles` (cache tuiles, peut atteindre plusieurs centaines de Mo), `storage/app/private/igc_tmp` (fichiers IGC temporaires), `storage/logs/ogn-*.log` (rotation 7 jours).

---

## 6. Optimisations Laravel

```bash
php artisan route:cache
php artisan view:cache
```

> **Ne pas exécuter `php artisan config:cache`.** L'application lit `DEV_FAKE_POSITIONS`, `OPENAIP_API_KEY` et `TILE_CACHE_TTL_SECONDS` via `env()` en dehors des fichiers de config ; une fois la config mise en cache, ces appels renvoient `null` — la couche OpenAIP cesse de fonctionner. Si vous voulez activer ce cache, il faut d'abord déplacer ces variables dans `config/services.php` et remplacer les `env()` par des `config()` dans `OgnController`, `TileProxyController`, `ApiController` et `routes/web.php`.

À chaque mise à jour du code :

```bash
cd /var/www/glider
php artisan down
git pull                                    # ou rsync
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan route:clear && php artisan route:cache
php artisan view:clear  && php artisan view:cache
sudo systemctl reload php8.3-fpm
php artisan up
```

---

## 7. Nginx

`/etc/nginx/sites-available/glider` :

```nginx
server {
    listen 80;
    server_name championnat.example.com;
    root /var/www/glider/public;

    index index.php;
    charset utf-8;

    client_max_body_size 24M;      # uploads IGC (max 20 Mo) et photos pilotes

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }

    location ~ /\.(?!well-known).* { deny all; }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;
}
```

```bash
sudo ln -s /etc/nginx/sites-available/glider /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

HTTPS :

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d championnat.example.com
```

### Variante Apache

```apache
<VirtualHost *:80>
    ServerName championnat.example.com
    DocumentRoot /var/www/glider/public

    <Directory /var/www/glider/public>
        AllowOverride All
        Require all granted
    </Directory>

    php_value upload_max_filesize 24M
    php_value post_max_size 24M
</VirtualHost>
```

`sudo a2enmod rewrite && sudo systemctl reload apache2` — le `.htaccess` fourni par Laravel dans `public/` fait le reste.

---

## 8. Services d'arrière-plan

Aucun n'est strictement requis : le projet ne dispatche aucun job et ne définit aucune tâche planifiée. Deux ajouts conseillés malgré tout.

**Nettoyage des sessions / cache expirés** (crontab de `www-data`) :

```cron
* * * * * cd /var/www/glider && php artisan schedule:run >> /dev/null 2>&1
```

**Worker de queue** — inutile en l'état, à ajouter seulement si des jobs sont introduits :

```ini
# /etc/systemd/system/glider-queue.service
[Unit]
Description=Glider Championship queue worker
After=network.target

[Service]
User=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/glider/artisan queue:work --tries=3 --sleep=3

[Install]
WantedBy=multi-user.target
```

---

## 9. Mise en service de la compétition

1. Se connecter sur `https://…/admin/login`.
2. **Compétition** : nom, point de départ (nom, latitude, longitude, rayon km).
3. **Balises** : saisie manuelle ou import d'un fichier `.cup` (SeeYou).
4. **Pilotes** : nom, indicatif, photo.
5. **Participants** : immatriculation, identifiant OGN, planeur, handicap.
6. **Attribution OGN** : associer chaque participant à son identifiant FLARM/OGN.
7. **Réglages** : formule de score, base, seuils de proximité (`proximity_far_m`, `proximity_near_m`), mode d'affichage carte.
8. Créer un **jour de compétition** puis le **démarrer**.

Écrans publics : `/` (carte + classement live), `/podium` (podium du jour), `/podium-general` (classement général), `/podium-display` (affichage plein écran).

> **Point d'exploitation important** : la détection de passage aux balises est effectuée par le JavaScript de la page `/`, pas par le serveur. Il faut donc **qu'au moins un poste garde la page `/` ouverte en permanence pendant l'épreuve**, sinon aucune balise n'est validée et les scores restent à zéro. Prévoir un poste dédié (borne d'affichage, machine de l'organisation) et vérifier qu'il ne se met pas en veille.

---

## 10. Vérifications post-déploiement

```bash
curl -s https://championnat.example.com/up                     # health check Laravel
curl -s https://championnat.example.com/api/competition | head # compétition active
curl -s "https://championnat.example.com/positions?lat=45.5&lng=5.9&radiusKm=20" | head
curl -sI https://championnat.example.com/tiles/osm/10/525/365.png | head -3
```

- `/up` → 200
- `/api/competition` → JSON avec `"devMode": false`
- `/positions` → `{"items":[…]}`, vide hors période de vol
- tuile OSM → `Content-Type: image/png`

Journaux : `storage/logs/laravel.log` (applicatif), `storage/logs/ogn-YYYY-MM-DD.log` (flux OGN), `/var/log/nginx/error.log`.

---

## 11. Sauvegarde

```bash
#!/bin/bash
# /usr/local/bin/glider-backup.sh
DEST=/var/backups/glider
mkdir -p "$DEST"
STAMP=$(date +%F-%H%M)
sqlite3 /var/www/glider/database/database.sqlite ".backup '$DEST/db-$STAMP.sqlite'"
tar czf "$DEST/photos-$STAMP.tar.gz" -C /var/www/glider/storage/app/public pilots
find "$DEST" -mtime +30 -delete
```

```cron
0 2 * * * /usr/local/bin/glider-backup.sh
```

Pendant une épreuve, une sauvegarde horaire de la base est prudente : elle contient les validations de balises et les scores figés.

---

## 12. Points de sécurité à connaître

- `POST /api/validate-turnpoint` est **public** : n'importe qui peut valider une balise pour n'importe quel pilote depuis la console de son navigateur. Tant que ce n'est pas corrigé côté code, ne pas exposer l'application sur Internet ouvert pendant une épreuve officielle — la restreindre au réseau de l'organisation (`allow`/`deny` Nginx ou VPN).
- `DEV_FAKE_POSITIONS` doit rester à `false` : à `true`, la route `POST /api/dev/positions` permet de déplacer arbitrairement les planeurs.
- Le proxy de tuiles n'impose aucune borne sur `z/x/y` : un tiers peut faire grossir `storage/app/private/tiles` indéfiniment. Surveiller l'espace disque, ou placer une limite de débit Nginx sur `/tiles/`.
- Changer le mot de passe du compte admin par défaut (§4).
