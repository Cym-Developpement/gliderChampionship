<?php
/**
 * Installeur web — Glider Championship
 *
 * À placer dans public/ et appeler via https://.../install.php
 * Prérequis : `composer install --no-dev --optimize-autoloader` déjà exécuté.
 *
 * Le script refuse de s'exécuter une fois storage/installed.lock créé.
 * SUPPRIMEZ CE FICHIER une fois l'installation terminée (bouton prévu en fin de procédure).
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(300);

define('BASE_PATH', dirname(__DIR__));
define('LOCK_FILE', BASE_PATH . '/storage/installed.lock');

// ─── Helpers ─────────────────────────────────────────────────────────────────

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function checked(string $key): bool
{
    return !empty($_POST[$key]);
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function is_writable_path(string $path): bool
{
    return file_exists($path) && is_writable($path);
}

/** Échappe une valeur pour le fichier .env */
function env_quote(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (preg_match('/[\s#"\'$]/', $value)) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
    return $value;
}

/** Clé APP_KEY compatible AES-256-CBC */
function generate_app_key(): string
{
    return 'base64:' . base64_encode(random_bytes(32));
}

/** Supprime récursivement le contenu d'un répertoire de cache */
function purge_bootstrap_cache(): void
{
    foreach (glob(BASE_PATH . '/bootstrap/cache/*.php') ?: [] as $file) {
        @unlink($file);
    }
}

// ─── Vérifications système ───────────────────────────────────────────────────

function system_checks(): array
{
    $checks = [];

    $checks[] = [
        'label' => 'PHP >= 8.2',
        'ok'    => PHP_VERSION_ID >= 80200,
        'value' => PHP_VERSION,
        'fatal' => true,
    ];

    foreach (['pdo', 'pdo_sqlite', 'mbstring', 'openssl', 'simplexml', 'curl', 'fileinfo', 'ctype', 'json', 'tokenizer'] as $ext) {
        $optional = in_array($ext, ['pdo_sqlite', 'curl'], true);
        $checks[] = [
            'label' => 'Extension ' . $ext . ($optional ? ' (recommandée)' : ''),
            'ok'    => extension_loaded($ext),
            'value' => extension_loaded($ext) ? 'chargée' : 'absente',
            'fatal' => !$optional,
        ];
    }

    $checks[] = [
        'label' => 'Dépendances Composer (vendor/)',
        'ok'    => is_file(BASE_PATH . '/vendor/autoload.php'),
        'value' => is_file(BASE_PATH . '/vendor/autoload.php') ? 'installées' : 'lancez composer install --no-dev',
        'fatal' => true,
    ];

    foreach ([
        'Racine du projet (écriture .env)' => BASE_PATH,
        'storage/'                         => BASE_PATH . '/storage',
        'storage/framework/'               => BASE_PATH . '/storage/framework',
        'storage/logs/'                    => BASE_PATH . '/storage/logs',
        'bootstrap/cache/'                 => BASE_PATH . '/bootstrap/cache',
        'database/'                        => BASE_PATH . '/database',
        'public/ (lien storage)'           => __DIR__,
    ] as $label => $path) {
        $checks[] = [
            'label' => $label,
            'ok'    => is_writable_path($path),
            'value' => is_writable_path($path) ? 'accessible en écriture' : 'non accessible en écriture',
            'fatal' => true,
        ];
    }

    return $checks;
}

function checks_ok(array $checks): bool
{
    foreach ($checks as $c) {
        if ($c['fatal'] && !$c['ok']) {
            return false;
        }
    }
    return true;
}

// ─── Génération du .env ──────────────────────────────────────────────────────

function build_env(array $cfg): string
{
    $lines = [
        'APP_NAME=' . env_quote($cfg['app_name']),
        'APP_ENV=production',
        'APP_KEY=' . $cfg['app_key'],
        'APP_DEBUG=false',
        'APP_URL=' . env_quote($cfg['app_url']),
        '',
        'APP_LOCALE=fr',
        'APP_FALLBACK_LOCALE=fr',
        'APP_FAKER_LOCALE=fr_FR',
        '',
        'APP_MAINTENANCE_DRIVER=file',
        'PHP_CLI_SERVER_WORKERS=4',
        'BCRYPT_ROUNDS=12',
        '',
        'LOG_CHANNEL=stack',
        'LOG_STACK=single',
        'LOG_DEPRECATIONS_CHANNEL=null',
        'LOG_LEVEL=warning',
        '',
    ];

    if ($cfg['db_driver'] === 'sqlite') {
        $lines[] = 'DB_CONNECTION=sqlite';
    } else {
        $lines[] = 'DB_CONNECTION=mysql';
        $lines[] = 'DB_HOST=' . env_quote($cfg['db_host']);
        $lines[] = 'DB_PORT=' . env_quote($cfg['db_port']);
        $lines[] = 'DB_DATABASE=' . env_quote($cfg['db_database']);
        $lines[] = 'DB_USERNAME=' . env_quote($cfg['db_username']);
        $lines[] = 'DB_PASSWORD=' . env_quote($cfg['db_password']);
    }

    $secureCookie = str_starts_with($cfg['app_url'], 'https://') ? 'true' : 'false';

    $lines = array_merge($lines, [
        '',
        'SESSION_DRIVER=database',
        'SESSION_LIFETIME=120',
        'SESSION_ENCRYPT=false',
        'SESSION_PATH=/',
        'SESSION_DOMAIN=null',
        'SESSION_SECURE_COOKIE=' . $secureCookie,
        '',
        'BROADCAST_CONNECTION=log',
        'FILESYSTEM_DISK=local',
        'QUEUE_CONNECTION=database',
        'CACHE_STORE=database',
        '',
        'MAIL_MAILER=log',
        'MAIL_FROM_ADDRESS=' . env_quote($cfg['admin_email']),
        'MAIL_FROM_NAME="${APP_NAME}"',
        '',
        '# Positions simulées — doit rester false en production',
        'DEV_FAKE_POSITIONS=false',
        '',
        '# Espaces aériens OpenAIP (optionnel)',
        'OPENAIP_API_KEY=' . env_quote($cfg['openaip_key']),
        'OPENAIP_TILES_URL="https://tiles.openaip.net/tiles/{z}/{x}/{y}.png?apiKey={API_KEY}"',
        '',
        '# Cache des tuiles cartographiques (secondes)',
        'TILE_CACHE_TTL_SECONDS=604800',
        '',
        'VITE_APP_NAME="${APP_NAME}"',
        '',
    ]);

    return implode(PHP_EOL, $lines);
}

// ─── Étapes d'installation ───────────────────────────────────────────────────

/**
 * @return array{steps: array<int, array{label: string, ok: bool, detail: string}>, ok: bool}
 */
function run_install(array $cfg): array
{
    $steps = [];
    $add = function (string $label, bool $ok, string $detail = '') use (&$steps): bool {
        $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        return $ok;
    };

    // 1. Connexion base de données
    try {
        if ($cfg['db_driver'] === 'sqlite') {
            $sqlitePath = BASE_PATH . '/database/database.sqlite';
            if (!is_file($sqlitePath) && !touch($sqlitePath)) {
                return ['steps' => [['label' => 'Création du fichier SQLite', 'ok' => false, 'detail' => $sqlitePath . ' non créable']], 'ok' => false];
            }
            @chmod($sqlitePath, 0664);
            new PDO('sqlite:' . $sqlitePath);
            $add('Base de données SQLite', true, $sqlitePath);
        } else {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s', $cfg['db_host'], $cfg['db_port'], $cfg['db_database']);
            new PDO($dsn, $cfg['db_username'], $cfg['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $add('Connexion MySQL', true, $cfg['db_username'] . '@' . $cfg['db_host'] . '/' . $cfg['db_database']);
        }
    } catch (Throwable $ex) {
        return ['steps' => [['label' => 'Connexion à la base de données', 'ok' => false, 'detail' => $ex->getMessage()]], 'ok' => false];
    }

    // 2. Écriture du .env (sauvegarde de l'existant)
    $envPath = BASE_PATH . '/.env';
    if (is_file($envPath)) {
        @copy($envPath, $envPath . '.backup-' . date('Ymd-His'));
    }
    if (file_put_contents($envPath, build_env($cfg)) === false) {
        return ['steps' => [['label' => 'Écriture du fichier .env', 'ok' => false, 'detail' => $envPath]], 'ok' => false];
    }
    @chmod($envPath, 0640);
    $add('Fichier .env écrit', true, 'APP_KEY générée, APP_DEBUG=false');

    // 3. Purge des caches obsolètes avant amorçage
    purge_bootstrap_cache();
    $add('Caches bootstrap purgés', true);

    // 4. Amorçage de Laravel (après écriture du .env pour que la config soit lue)
    try {
        require_once BASE_PATH . '/vendor/autoload.php';
        /** @var Illuminate\Foundation\Application $app */
        $app = require BASE_PATH . '/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $add('Application Laravel amorcée', true, 'Laravel ' . $app->version());
    } catch (Throwable $ex) {
        $add('Amorçage de Laravel', false, $ex->getMessage());
        return ['steps' => $steps, 'ok' => false];
    }

    // 5. Migrations
    try {
        Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        $output = trim(Illuminate\Support\Facades\Artisan::output());
        $add('Migrations exécutées', true, $output !== '' ? $output : 'aucune migration en attente');
    } catch (Throwable $ex) {
        $add('Migrations', false, $ex->getMessage());
        return ['steps' => $steps, 'ok' => false];
    }

    // 6. Compte administrateur
    try {
        App\Models\User::updateOrCreate(
            ['email' => $cfg['admin_email']],
            [
                'name'     => $cfg['admin_name'],
                'password' => Illuminate\Support\Facades\Hash::make($cfg['admin_password']),
            ]
        );
        $add('Compte administrateur créé', true, $cfg['admin_email']);
    } catch (Throwable $ex) {
        $add('Compte administrateur', false, $ex->getMessage());
        return ['steps' => $steps, 'ok' => false];
    }

    // 7. Lien symbolique de stockage (le lien livré pointe vers un chemin de développement)
    try {
        $link = __DIR__ . '/storage';
        if (is_link($link)) {
            @unlink($link);
        }
        Illuminate\Support\Facades\Artisan::call('storage:link', ['--force' => true]);
        $ok = is_link($link) || is_dir($link);
        $add('Lien public/storage', $ok, $ok ? '→ storage/app/public' : 'échec — créez-le manuellement');
    } catch (Throwable $ex) {
        $add('Lien public/storage', false, $ex->getMessage());
    }

    // 8. Répertoires d'exécution
    foreach (['app/public/pilots', 'app/private/tiles', 'app/private/igc_tmp'] as $dir) {
        $full = BASE_PATH . '/storage/' . $dir;
        if (!is_dir($full)) {
            @mkdir($full, 0775, true);
        }
    }
    $add('Répertoires de stockage préparés', true, 'pilots, tiles, igc_tmp');

    // 9. Jeu de données de démonstration (optionnel)
    if ($cfg['seed_demo']) {
        try {
            Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
            $add('Données de démonstration importées', true, '18 participants et pilotes fictifs');
        } catch (Throwable $ex) {
            $add('Données de démonstration', false, $ex->getMessage());
        }
    }

    // 10. Caches de production
    //     NB : config:cache est volontairement omis — l'application lit DEV_FAKE_POSITIONS,
    //     OPENAIP_API_KEY et TILE_CACHE_TTL_SECONDS via env() hors des fichiers de config,
    //     ces valeurs deviendraient nulles une fois la config mise en cache.
    try {
        Illuminate\Support\Facades\Artisan::call('view:cache');
        Illuminate\Support\Facades\Artisan::call('route:cache');
        $add('Caches vues et routes générés', true, 'config:cache volontairement non exécuté');
    } catch (Throwable $ex) {
        $add('Caches de production', false, $ex->getMessage());
    }

    // 11. Verrou
    if (@file_put_contents(LOCK_FILE, 'Installé le ' . date('c') . PHP_EOL) === false) {
        $add('Verrou d\'installation', false, LOCK_FILE . ' non créable — l\'installeur restera réexécutable !');
    } else {
        $add('Verrou d\'installation posé', true, 'storage/installed.lock');
    }

    return ['steps' => $steps, 'ok' => true];
}

// ─── Routage du script ───────────────────────────────────────────────────────

$locked    = is_file(LOCK_FILE);
$action    = post('action');
$checks    = system_checks();
$canInstall = checks_ok($checks);
$errors    = [];
$result    = null;

// Auto-suppression après installation
if ($action === 'selfdestruct' && $locked) {
    $deleted = @unlink(__FILE__);
    render_page(function () use ($deleted) { ?>
        <div class="alert alert-<?= $deleted ? 'success' : 'danger' ?>">
            <?= $deleted
                ? '<strong>install.php supprimé.</strong> L\'installeur n\'est plus accessible.'
                : '<strong>Suppression impossible.</strong> Supprimez manuellement <code>public/install.php</code> sur le serveur.' ?>
        </div>
        <a href="/admin/login" class="btn btn-primary">Aller à l'administration</a>
    <?php });
    exit;
}

if ($locked && $action !== 'selfdestruct') {
    render_page(function () { ?>
        <div class="alert alert-warning">
            <h5 class="alert-heading">Application déjà installée</h5>
            <p class="mb-0">
                Le fichier <code>storage/installed.lock</code> est présent. Pour réinstaller,
                supprimez-le sur le serveur puis rechargez cette page.
            </p>
        </div>
        <div class="alert alert-danger">
            <strong>Cet installeur ne doit pas rester accessible en ligne.</strong>
            Supprimez <code>public/install.php</code>.
        </div>
        <form method="post" class="d-flex gap-2">
            <input type="hidden" name="action" value="selfdestruct">
            <button class="btn btn-danger">Supprimer install.php maintenant</button>
            <a href="/admin/login" class="btn btn-outline-secondary">Administration</a>
        </form>
    <?php });
    exit;
}

if ($action === 'install') {
    $cfg = [
        'app_name'       => post('app_name', 'Glider Championship'),
        'app_url'        => rtrim(post('app_url'), '/'),
        'app_key'        => generate_app_key(),
        'db_driver'      => post('db_driver', 'sqlite') === 'mysql' ? 'mysql' : 'sqlite',
        'db_host'        => post('db_host', '127.0.0.1'),
        'db_port'        => post('db_port', '3306'),
        'db_database'    => post('db_database'),
        'db_username'    => post('db_username'),
        'db_password'    => post('db_password'),
        'admin_name'     => post('admin_name', 'Admin'),
        'admin_email'    => post('admin_email'),
        'admin_password' => post('admin_password'),
        'openaip_key'    => post('openaip_key'),
        'seed_demo'      => checked('seed_demo'),
    ];

    if (!$canInstall) {
        $errors[] = 'Des prérequis systèmes ne sont pas satisfaits.';
    }
    if ($cfg['app_name'] === '') {
        $errors[] = 'Le nom de l\'application est requis.';
    }
    if (!filter_var($cfg['app_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'L\'URL de l\'application est invalide (ex : https://championnat.example.com).';
    }
    if (!filter_var($cfg['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'L\'adresse e-mail de l\'administrateur est invalide.';
    }
    if (strlen($cfg['admin_password']) < 10) {
        $errors[] = 'Le mot de passe administrateur doit faire au moins 10 caractères.';
    }
    if ($cfg['admin_password'] !== post('admin_password_confirm')) {
        $errors[] = 'Les deux mots de passe ne correspondent pas.';
    }
    if ($cfg['db_driver'] === 'mysql' && ($cfg['db_database'] === '' || $cfg['db_username'] === '')) {
        $errors[] = 'Nom de base et utilisateur MySQL requis.';
    }

    if (!$errors) {
        $result = run_install($cfg);
    }
}

// ─── Rendu ───────────────────────────────────────────────────────────────────

function render_page(callable $body): void
{
    ?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Installation — Glider Championship</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f6f8; }
        .wrap { max-width: 820px; margin: 2.5rem auto 4rem; }
        code { font-size: .875em; }
        pre.detail { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: .25rem;
                     padding: .5rem .75rem; font-size: .8125rem; max-height: 220px; overflow: auto; margin: .5rem 0 0; }
    </style>
</head>
<body>
<div class="wrap">
    <h1 class="h3 mb-1">Glider Championship</h1>
    <p class="text-muted mb-4">Installation du serveur</p>
    <?php $body(); ?>
</div>
</body>
</html><?php
}

if ($result !== null) {
    render_page(function () use ($result) { ?>
        <?php if ($result['ok']): ?>
            <div class="alert alert-success">
                <h5 class="alert-heading">Installation terminée</h5>
                <p class="mb-0">L'application est opérationnelle.</p>
            </div>
        <?php else: ?>
            <div class="alert alert-danger">
                <h5 class="alert-heading">Installation interrompue</h5>
                <p class="mb-0">Corrigez l'erreur ci-dessous, puis relancez l'installeur.</p>
            </div>
        <?php endif; ?>

        <ul class="list-group mb-4">
            <?php foreach ($result['steps'] as $step): ?>
                <li class="list-group-item">
                    <span class="badge bg-<?= $step['ok'] ? 'success' : 'danger' ?> me-2"><?= $step['ok'] ? 'OK' : 'ERREUR' ?></span>
                    <?= e($step['label']) ?>
                    <?php if ($step['detail'] !== ''): ?>
                        <pre class="detail"><?= e($step['detail']) ?></pre>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($result['ok']): ?>
            <div class="alert alert-danger">
                <h6 class="alert-heading">À faire immédiatement</h6>
                <ol class="mb-0 ps-3">
                    <li>Supprimer <code>public/install.php</code> (bouton ci-dessous).</li>
                    <li>Ajuster les propriétaires côté serveur :
                        <code>chown -R www-data:www-data storage bootstrap/cache database</code></li>
                    <li>La détection des balises est effectuée par le navigateur : un poste doit garder
                        la page <code>/</code> ouverte pendant toute l'épreuve.</li>
                    <li><code>POST /api/validate-turnpoint</code> est public — restreindre l'accès réseau
                        pendant une épreuve officielle (voir <code>DEPLOIEMENT.md</code> §12).</li>
                </ol>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <form method="post"><input type="hidden" name="action" value="selfdestruct">
                    <button class="btn btn-danger">Supprimer install.php</button></form>
                <a href="/admin/login" class="btn btn-primary">Administration</a>
                <a href="/" class="btn btn-outline-secondary">Voir la carte</a>
            </div>
        <?php else: ?>
            <a href="install.php" class="btn btn-primary">Retour au formulaire</a>
        <?php endif; ?>
    <?php });
    exit;
}

render_page(function () use ($checks, $canInstall, $errors) { ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Prérequis</div>
        <ul class="list-group list-group-flush">
            <?php foreach ($checks as $c): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                    <span><?= e($c['label']) ?></span>
                    <span class="badge bg-<?= $c['ok'] ? 'success' : ($c['fatal'] ? 'danger' : 'warning text-dark') ?>">
                        <?= e($c['value']) ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if (!$canInstall): ?>
        <div class="alert alert-danger">
            Corrigez les prérequis en rouge avant de poursuivre. Pour les droits d'écriture :
            <code>chown -R www-data:www-data storage bootstrap/cache database</code>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if (is_file(BASE_PATH . '/.env')): ?>
        <div class="alert alert-warning">
            Un fichier <code>.env</code> existe déjà. Il sera sauvegardé sous
            <code>.env.backup-AAAAMMJJ-HHMMSS</code> puis remplacé.
        </div>
    <?php endif; ?>

    <form method="post" class="card">
        <input type="hidden" name="action" value="install">
        <div class="card-body">

            <h2 class="h6 text-uppercase text-muted mb-3">Application</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Nom</label>
                    <input name="app_name" class="form-control" required
                           value="<?= e(post('app_name', 'Glider Championship')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">URL publique</label>
                    <input name="app_url" type="url" class="form-control" required
                           placeholder="https://championnat.example.com"
                           value="<?= e(post('app_url', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))) ?>">
                    <div class="form-text">Sans slash final. En HTTPS, le cookie de session sera marqué <code>Secure</code>.</div>
                </div>
            </div>

            <h2 class="h6 text-uppercase text-muted mb-3">Base de données</h2>
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="db_driver" id="db_sqlite" value="sqlite"
                           <?= post('db_driver', 'sqlite') !== 'mysql' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="db_sqlite">
                        SQLite — <span class="text-muted">recommandé, aucun service à configurer</span>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="db_driver" id="db_mysql" value="mysql"
                           <?= post('db_driver') === 'mysql' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="db_mysql">MySQL / MariaDB</label>
                </div>
            </div>
            <div class="row g-3 mb-4" id="mysqlFields">
                <div class="col-md-8">
                    <label class="form-label">Hôte</label>
                    <input name="db_host" class="form-control" value="<?= e(post('db_host', '127.0.0.1')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Port</label>
                    <input name="db_port" class="form-control" value="<?= e(post('db_port', '3306')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Base</label>
                    <input name="db_database" class="form-control" value="<?= e(post('db_database')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Utilisateur</label>
                    <input name="db_username" class="form-control" value="<?= e(post('db_username')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Mot de passe</label>
                    <input name="db_password" type="password" class="form-control">
                </div>
                <div class="col-12">
                    <div class="form-text">La base doit exister ; l'installeur ne fait que s'y connecter et y appliquer les migrations.</div>
                </div>
            </div>

            <h2 class="h6 text-uppercase text-muted mb-3">Compte administrateur</h2>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label">Nom</label>
                    <input name="admin_name" class="form-control" required value="<?= e(post('admin_name', 'Admin')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">E-mail</label>
                    <input name="admin_email" type="email" class="form-control" required value="<?= e(post('admin_email')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Mot de passe</label>
                    <input name="admin_password" type="password" class="form-control" required minlength="10">
                    <div class="form-text">10 caractères minimum.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Confirmation</label>
                    <input name="admin_password_confirm" type="password" class="form-control" required minlength="10">
                </div>
            </div>

            <h2 class="h6 text-uppercase text-muted mb-3">Options</h2>
            <div class="mb-3">
                <label class="form-label">Clé API OpenAIP <span class="text-muted fw-normal">(facultatif)</span></label>
                <input name="openaip_key" class="form-control" value="<?= e(post('openaip_key')) ?>">
                <div class="form-text">Sans clé, la couche des espaces aériens est simplement absente de la carte.</div>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="seed_demo" id="seed_demo" <?= checked('seed_demo') ? 'checked' : '' ?>>
                <label class="form-check-label" for="seed_demo">
                    Importer les données de démonstration
                    <span class="text-muted">(18 participants et pilotes fictifs — à ne pas cocher en production)</span>
                </label>
            </div>
        </div>

        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="text-muted small">Le fichier <code>.env</code> sera écrit avec <code>APP_DEBUG=false</code>.</span>
            <button class="btn btn-primary" <?= $canInstall ? '' : 'disabled' ?>>Installer</button>
        </div>
    </form>

    <script>
        const fields = document.getElementById('mysqlFields');
        const toggle = () => {
            const mysql = document.getElementById('db_mysql').checked;
            fields.style.display = mysql ? '' : 'none';
            fields.querySelectorAll('input').forEach(i => i.disabled = !mysql);
        };
        document.querySelectorAll('input[name="db_driver"]').forEach(r => r.addEventListener('change', toggle));
        toggle();
    </script>
<?php });
