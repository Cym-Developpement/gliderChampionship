<?php
/**
 * Installeur web — Glider Championship
 *
 * À placer dans public/ et appeler via https://.../install.php
 * Aucun prérequis : l'installeur télécharge Composer si nécessaire et installe
 * lui-même les dépendances.
 *
 * Le script refuse de s'exécuter une fois storage/installed.lock créé.
 * SUPPRIMEZ CE FICHIER une fois l'installation terminée (bouton prévu en fin de procédure).
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);
ini_set('max_execution_time', '0');

define('BASE_PATH', dirname(__DIR__));
define('LOCK_FILE', BASE_PATH . '/storage/installed.lock');
define('COMPOSER_PHAR', BASE_PATH . '/composer.phar');
define('COMPOSER_HOME_DIR', BASE_PATH . '/storage/app/private/composer');
define('VENDOR_AUTOLOAD', BASE_PATH . '/vendor/autoload.php');

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

// ─── Affichage en direct (streaming) ─────────────────────────────────────────

$STREAMING = false;

/** Coupe toute mise en tampon pour que chaque étape parte immédiatement au navigateur. */
function stream_begin(): void
{
    global $STREAMING;
    $STREAMING = true;

    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no'); // nginx : désactive la bufferisation FastCGI
    }

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    ob_implicit_flush(true);
}

function stream_flush(): void
{
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

/** Ouvre une étape avec un badge « en cours ». */
function step_open(string $id, string $label): void
{
    echo '<li class="list-group-item" id="step-' . e($id) . '">'
        . '<span class="badge bg-secondary me-2" id="badge-' . e($id) . '">…</span>'
        . '<span class="fw-medium">' . e($label) . '</span>'
        . '<pre class="detail d-none" id="out-' . e($id) . '"></pre>';
    echo '<script>document.getElementById("step-' . e($id) . '").scrollIntoView({block:"end"});</script>';
    stream_flush();
}

/** Ajoute du texte dans le bloc de sortie de l'étape en cours. */
function step_output(string $id, string $chunk): void
{
    // Composer et Artisan colorisent leur sortie : les séquences ANSI n'ont aucun
    // sens dans un <pre> et pollueraient l'affichage.
    $chunk = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $chunk);
    $chunk = str_replace("\r", '', $chunk);

    if (trim($chunk) === '') {
        return;
    }
    echo '<script>(function(){'
        . 'var o=document.getElementById("out-' . e($id) . '");'
        . 'o.classList.remove("d-none");'
        . 'o.textContent+=' . json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';'
        . 'o.scrollTop=o.scrollHeight;'
        . 'window.scrollTo(0,document.body.scrollHeight);'
        . '})();</script>';
    stream_flush();
}

/** Clôt l'étape en basculant son badge en OK ou ERREUR. */
function step_close(string $id, bool $ok, string $detail = ''): void
{
    if ($detail !== '') {
        step_output($id, $detail);
    }
    echo '<script>(function(){'
        . 'var b=document.getElementById("badge-' . e($id) . '");'
        . 'b.className="badge me-2 bg-' . ($ok ? 'success' : 'danger') . '";'
        . 'b.textContent="' . ($ok ? 'OK' : 'ERREUR') . '";'
        . '})();</script></li>';
    stream_flush();
}

// ─── Exécution de commandes externes ─────────────────────────────────────────

function function_disabled(string $name): bool
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !function_exists($name) || in_array($name, $disabled, true);
}

function can_run_commands(): bool
{
    return !function_disabled('proc_open');
}

/**
 * Exécute une commande sans passer par un shell.
 *
 * @param list<string> $cmd
 * @return array{code: int, output: string}
 */
function run_command(array $cmd, ?string $cwd = null, array $env = [], int $timeout = 900, ?callable $onOutput = null): array
{
    if (!can_run_commands()) {
        return ['code' => -1, 'output' => 'proc_open() est désactivée sur ce serveur (directive disable_functions).'];
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $baseEnv = [
        'PATH'                     => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        'HOME'                     => COMPOSER_HOME_DIR,
        'COMPOSER_HOME'            => COMPOSER_HOME_DIR,
        'COMPOSER_NO_INTERACTION'  => '1',
        'COMPOSER_ALLOW_SUPERUSER' => '1',
        'COMPOSER_PROCESS_TIMEOUT' => (string) $timeout,
    ];

    $process = @proc_open($cmd, $descriptors, $pipes, $cwd ?? BASE_PATH, array_merge($baseEnv, $env));
    if (!is_resource($process)) {
        return ['code' => -1, 'output' => 'Impossible de lancer : ' . implode(' ', $cmd)];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output   = '';
    $start    = time();
    $exitCode = -1;

    $collect = function () use ($pipes, &$output, $onOutput): void {
        $chunk = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        if ($chunk === '') {
            return;
        }
        $output .= $chunk;
        if ($onOutput !== null) {
            $onOutput($chunk);
        }
    };

    while (true) {
        $status = proc_get_status($process);
        $collect();

        if (!$status['running']) {
            $exitCode = (int) $status['exitcode'];
            break;
        }
        if (time() - $start > $timeout) {
            proc_terminate($process, 9);
            $message = PHP_EOL . "[Commande interrompue après {$timeout} s]";
            $output .= $message;
            if ($onOutput !== null) {
                $onOutput($message);
            }
            break;
        }
        usleep(200000);
    }

    $collect();
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return ['code' => $exitCode, 'output' => trim($output)];
}

/** Localise un binaire PHP en ligne de commande (PHP_BINARY vaut php-fpm sous SAPI web). */
function find_php_binary(): ?string
{
    static $cached = false;
    static $found = null;

    if ($cached) {
        return $found;
    }
    $cached = true;

    $version    = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $candidates = [];

    if (PHP_SAPI === 'cli') {
        $candidates[] = PHP_BINARY;
    }
    foreach ([
        PHP_BINDIR,
        '/usr/local/bin',
        '/usr/bin',
        '/bin',
        '/opt/plesk/php/' . $version . '/bin',
        '/opt/cpanel/ea-php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/root/usr/bin',
    ] as $dir) {
        $candidates[] = $dir . '/php';
        $candidates[] = $dir . '/php' . $version;
        $candidates[] = $dir . '/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
    }

    foreach (array_unique($candidates) as $candidate) {
        if (!is_string($candidate) || $candidate === '' || is_dir($candidate) || !@is_executable($candidate)) {
            continue;
        }
        // Vérifie qu'il s'agit bien du binaire CLI et non de php-fpm
        $probe = run_command([$candidate, '-r', 'echo PHP_SAPI;'], BASE_PATH, [], 15);
        if ($probe['code'] === 0 && str_contains($probe['output'], 'cli')) {
            return $found = $candidate;
        }
    }

    return $found = null;
}

/** Téléchargement HTTPS via cURL ou allow_url_fopen. */
function http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'GliderChampionship-Installer',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $code === 200) {
            return $body;
        }
    }

    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $context = stream_context_create([
            'http' => ['timeout' => 120, 'user_agent' => 'GliderChampionship-Installer'],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (is_string($body) && $body !== '') {
            return $body;
        }
    }

    return null;
}

/**
 * Télécharge composer.phar à la racine du projet.
 * Passe par l'installeur officiel (signature SHA-384 vérifiée), avec repli
 * sur le téléchargement direct du phar.
 *
 * @return array{ok: bool, detail: string}
 */
function download_composer(string $phpBin): array
{
    $installer = http_get('https://getcomposer.org/installer');
    $signature = http_get('https://composer.github.io/installer.sig');

    if ($installer !== null && $signature !== null) {
        $expected = trim($signature);
        $actual   = hash('sha384', $installer);

        if (!hash_equals($expected, $actual)) {
            return ['ok' => false, 'detail' => 'Signature SHA-384 de l\'installeur Composer invalide — téléchargement abandonné.'];
        }

        $setupPath = COMPOSER_HOME_DIR . '/composer-setup.php';
        if (@file_put_contents($setupPath, $installer) === false) {
            return ['ok' => false, 'detail' => 'Écriture impossible dans ' . COMPOSER_HOME_DIR];
        }

        $run = run_command([
            $phpBin,
            $setupPath,
            '--install-dir=' . BASE_PATH,
            '--filename=composer.phar',
        ], BASE_PATH, [], 300);
        @unlink($setupPath);

        if (is_file(COMPOSER_PHAR)) {
            return ['ok' => true, 'detail' => 'composer.phar téléchargé via l\'installeur officiel (signature vérifiée).'];
        }

        $fallbackReason = $run['output'] !== '' ? $run['output'] : 'code de sortie ' . $run['code'];
    } else {
        $fallbackReason = 'getcomposer.org injoignable (cURL et allow_url_fopen indisponibles ou réseau bloqué).';
    }

    // Repli : téléchargement direct du phar
    $phar = http_get('https://getcomposer.org/download/latest-stable/composer.phar');
    if ($phar !== null && @file_put_contents(COMPOSER_PHAR, $phar) !== false) {
        @chmod(COMPOSER_PHAR, 0755);
        return ['ok' => true, 'detail' => 'composer.phar téléchargé directement (repli). Cause du repli : ' . $fallbackReason];
    }

    return ['ok' => false, 'detail' => 'Téléchargement de Composer impossible. ' . $fallbackReason];
}

/**
 * Renvoie la commande Composer à exécuter, en le téléchargeant si absent.
 *
 * @return array{ok: bool, cmd: list<string>, detail: string}
 */
function resolve_composer(string $phpBin): array
{
    foreach ([COMPOSER_PHAR, '/usr/local/bin/composer', '/usr/bin/composer', '/opt/composer/composer'] as $path) {
        if (is_file($path)) {
            return [
                'ok'     => true,
                'cmd'    => [$phpBin, '-d', 'memory_limit=-1', $path],
                'detail' => 'Composer trouvé : ' . $path,
            ];
        }
    }

    if (!is_dir(COMPOSER_HOME_DIR)) {
        @mkdir(COMPOSER_HOME_DIR, 0775, true);
    }

    $download = download_composer($phpBin);
    if (!$download['ok']) {
        return ['ok' => false, 'cmd' => [], 'detail' => $download['detail']];
    }

    return [
        'ok'     => true,
        'cmd'    => [$phpBin, '-d', 'memory_limit=-1', COMPOSER_PHAR],
        'detail' => $download['detail'],
    ];
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

    $vendorReady = is_file(VENDOR_AUTOLOAD);

    $checks[] = [
        'label' => 'Dépendances Composer (vendor/)',
        'ok'    => true,
        'value' => $vendorReady ? 'déjà installées' : 'seront installées par l\'installeur',
        'fatal' => false,
    ];

    // Ces trois points ne sont bloquants que s'il faut réellement lancer Composer.
    $needsComposer = !$vendorReady;

    $checks[] = [
        'label' => 'Exécution de commandes (proc_open)' . ($needsComposer ? '' : ' — non requis'),
        'ok'    => can_run_commands(),
        'value' => can_run_commands() ? 'autorisée' : 'désactivée (disable_functions)',
        'fatal' => $needsComposer,
    ];

    $phpBin = can_run_commands() ? find_php_binary() : null;
    $checks[] = [
        'label' => 'Binaire PHP en ligne de commande' . ($needsComposer ? '' : ' — non requis'),
        'ok'    => $phpBin !== null,
        'value' => $phpBin ?? 'introuvable',
        'fatal' => $needsComposer,
    ];

    $composerFound = is_file(COMPOSER_PHAR) || is_file('/usr/local/bin/composer') || is_file('/usr/bin/composer');
    // Ordre important : ne sonde le réseau que si Composer manque réellement.
    $netOk = $composerFound || !$needsComposer || http_get('https://composer.github.io/installer.sig') !== null;
    $checks[] = [
        'label' => 'Composer',
        'ok'    => $composerFound || $netOk,
        'value' => $composerFound
            ? 'présent sur le serveur'
            : ($netOk ? 'sera téléchargé depuis getcomposer.org' : 'absent et getcomposer.org injoignable'),
        'fatal' => $needsComposer,
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
 * Exécute l'installation en diffusant chaque étape au navigateur.
 * Renvoie true si toutes les étapes bloquantes ont réussi.
 */
function run_install(array $cfg): bool
{
    // 1. Connexion à la base de données
    step_open('db', 'Connexion à la base de données');
    try {
        if ($cfg['db_driver'] === 'sqlite') {
            $sqlitePath = BASE_PATH . '/database/database.sqlite';
            if (!is_file($sqlitePath)) {
                step_output('db', "Création de {$sqlitePath}\n");
                if (!touch($sqlitePath)) {
                    step_close('db', false, 'Fichier non créable — vérifiez les droits sur database/');
                    return false;
                }
            }
            @chmod($sqlitePath, 0664);
            new PDO('sqlite:' . $sqlitePath);
            step_close('db', true, 'SQLite : ' . $sqlitePath);
        } else {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s', $cfg['db_host'], $cfg['db_port'], $cfg['db_database']);
            new PDO($dsn, $cfg['db_username'], $cfg['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            step_close('db', true, 'MySQL : ' . $cfg['db_username'] . '@' . $cfg['db_host'] . '/' . $cfg['db_database']);
        }
    } catch (Throwable $ex) {
        step_close('db', false, $ex->getMessage());
        return false;
    }

    // 2. Dépendances Composer
    if (!is_file(VENDOR_AUTOLOAD) || $cfg['force_composer']) {
        step_open('php', 'Recherche du binaire PHP en ligne de commande');
        $phpBin = find_php_binary();
        if ($phpBin === null) {
            step_close('php', false, "Introuvable.\nLancez manuellement à la racine du projet :\n  composer install --no-dev --optimize-autoloader");
            return false;
        }
        step_close('php', true, $phpBin);

        step_open('composer', 'Mise à disposition de Composer');
        $composer = resolve_composer($phpBin);
        if (!$composer['ok']) {
            step_close('composer', false, $composer['detail']);
            return false;
        }
        step_close('composer', true, $composer['detail']);

        if (!is_dir(COMPOSER_HOME_DIR)) {
            @mkdir(COMPOSER_HOME_DIR, 0775, true);
        }

        step_open('deps', 'composer install --no-dev --optimize-autoloader');
        step_output('deps', "Téléchargement des dépendances, cela peut prendre plusieurs minutes…\n\n");
        $install = run_command(
            array_merge($composer['cmd'], [
                'install', '--no-dev', '--optimize-autoloader',
                '--no-interaction', '--no-progress', '--prefer-dist',
            ]),
            BASE_PATH,
            [],
            900,
            fn(string $chunk) => step_output('deps', $chunk)   // sortie Composer en direct
        );

        if ($install['code'] !== 0 || !is_file(VENDOR_AUTOLOAD)) {
            step_close('deps', false, "\nÉchec (code " . $install['code'] . ').');
            return false;
        }
        step_close('deps', true, "\nDépendances installées.");
    } else {
        step_open('deps', 'Dépendances Composer');
        step_close('deps', true, 'vendor/ déjà présent — installation ignorée');
    }

    // 3. Écriture du .env
    step_open('env', 'Écriture du fichier .env');
    $envPath = BASE_PATH . '/.env';
    if (is_file($envPath)) {
        $backup = $envPath . '.backup-' . date('Ymd-His');
        @copy($envPath, $backup);
        step_output('env', 'Sauvegarde de l\'existant : ' . basename($backup) . "\n");
    }
    if (file_put_contents($envPath, build_env($cfg)) === false) {
        step_close('env', false, 'Écriture impossible dans ' . $envPath);
        return false;
    }
    @chmod($envPath, 0640);
    step_close('env', true, 'APP_KEY générée, APP_DEBUG=false');

    // 4. Purge des caches obsolètes avant amorçage
    step_open('purge', 'Purge des caches bootstrap');
    purge_bootstrap_cache();
    step_close('purge', true);

    // 5. Amorçage de Laravel (après écriture du .env pour que la config soit lue)
    step_open('boot', 'Amorçage de l\'application Laravel');
    try {
        require_once VENDOR_AUTOLOAD;
        /** @var Illuminate\Foundation\Application $app */
        $app = require BASE_PATH . '/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        step_close('boot', true, 'Laravel ' . $app->version());
    } catch (Throwable $ex) {
        step_close('boot', false, $ex->getMessage());
        return false;
    }

    // 6. Migrations
    step_open('migrate', 'Exécution des migrations');
    try {
        Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        $output = trim(Illuminate\Support\Facades\Artisan::output());
        step_close('migrate', true, $output !== '' ? $output : 'aucune migration en attente');
    } catch (Throwable $ex) {
        step_close('migrate', false, $ex->getMessage());
        return false;
    }

    // 7. Compte administrateur
    step_open('admin', 'Création du compte administrateur');
    try {
        App\Models\User::updateOrCreate(
            ['email' => $cfg['admin_email']],
            [
                'name'     => $cfg['admin_name'],
                'password' => Illuminate\Support\Facades\Hash::make($cfg['admin_password']),
            ]
        );
        step_close('admin', true, $cfg['admin_email']);
    } catch (Throwable $ex) {
        step_close('admin', false, $ex->getMessage());
        return false;
    }

    // 8. Lien symbolique de stockage (le lien livré pointe vers un chemin de développement)
    step_open('link', 'Création du lien public/storage');
    try {
        $link = __DIR__ . '/storage';
        if (is_link($link)) {
            @unlink($link);
            step_output('link', "Ancien lien supprimé.\n");
        }
        Illuminate\Support\Facades\Artisan::call('storage:link', ['--force' => true]);
        $ok = is_link($link) || is_dir($link);
        step_close('link', $ok, $ok ? '→ storage/app/public' : 'échec — créez-le manuellement');
    } catch (Throwable $ex) {
        step_close('link', false, $ex->getMessage());
    }

    // 9. Répertoires d'exécution
    step_open('dirs', 'Préparation des répertoires de stockage');
    foreach (['app/public/pilots', 'app/private/tiles', 'app/private/igc_tmp'] as $dir) {
        $full = BASE_PATH . '/storage/' . $dir;
        if (!is_dir($full)) {
            @mkdir($full, 0775, true);
        }
    }
    step_close('dirs', true, 'pilots, tiles, igc_tmp');

    // 10. Jeu de données de démonstration (optionnel)
    if ($cfg['seed_demo']) {
        step_open('seed', 'Import des données de démonstration');
        try {
            Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
            step_close('seed', true, trim(Illuminate\Support\Facades\Artisan::output()));
        } catch (Throwable $ex) {
            step_close('seed', false, $ex->getMessage());
        }
    }

    // 11. Caches de production
    //     NB : config:cache est volontairement omis — l'application lit DEV_FAKE_POSITIONS,
    //     OPENAIP_API_KEY et TILE_CACHE_TTL_SECONDS via env() hors des fichiers de config,
    //     ces valeurs deviendraient nulles une fois la config mise en cache.
    step_open('cache', 'Génération des caches de production');
    try {
        Illuminate\Support\Facades\Artisan::call('view:cache');
        Illuminate\Support\Facades\Artisan::call('route:cache');
        step_close('cache', true, 'vues et routes mises en cache (config:cache volontairement non exécuté)');
    } catch (Throwable $ex) {
        step_close('cache', false, $ex->getMessage());
    }

    // 12. Verrou
    step_open('lock', 'Pose du verrou d\'installation');
    if (@file_put_contents(LOCK_FILE, 'Installé le ' . date('c') . PHP_EOL) === false) {
        step_close('lock', false, LOCK_FILE . ' non créable — l\'installeur restera réexécutable !');
    } else {
        step_close('lock', true, 'storage/installed.lock');
    }

    return true;
}

// ─── Routage du script ───────────────────────────────────────────────────────

$locked    = is_file(LOCK_FILE);
$action    = post('action');
$checks    = system_checks();
$canInstall = checks_ok($checks);
$errors     = [];

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
        'force_composer' => checked('force_composer'),
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
        stream_begin();
        page_head();

        echo '<div class="alert alert-info d-flex align-items-center gap-2" id="running">'
            . '<div class="spinner-border spinner-border-sm" role="status"></div>'
            . '<div>Installation en cours — ne fermez pas cette page.</div></div>';
        echo '<ul class="list-group mb-4">';
        stream_flush();

        $ok = run_install($cfg);

        echo '</ul>';
        echo '<script>document.getElementById("running").remove();</script>';
        render_outcome($ok);
        page_foot();
        exit;
    }
}

/** Bloc final affiché une fois toutes les étapes diffusées. */
function render_outcome(bool $ok): void
{
    if (!$ok) { ?>
        <div class="alert alert-danger">
            <h5 class="alert-heading">Installation interrompue</h5>
            <p class="mb-0">Corrigez l'erreur signalée ci-dessus, puis relancez l'installeur.</p>
        </div>
        <a href="install.php" class="btn btn-primary">Retour au formulaire</a>
        <?php
        return;
    } ?>
    <div class="alert alert-success">
        <h5 class="alert-heading">Installation terminée</h5>
        <p class="mb-0">L'application est opérationnelle.</p>
    </div>

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
<?php }

// ─── Rendu ───────────────────────────────────────────────────────────────────

function render_page(callable $body): void
{
    page_head();
    $body();
    page_foot();
}

function page_head(): void
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
<?php
    // Bourrage : certains navigateurs et proxys n'affichent rien avant ~1 Ko reçu.
    echo '<!--' . str_repeat(' ', 2048) . '-->' . PHP_EOL;
}

function page_foot(): void
{
    ?>
</div>
</body>
</html><?php
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
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="force_composer" id="force_composer" <?= checked('force_composer') ? 'checked' : '' ?>>
                <label class="form-check-label" for="force_composer">
                    Relancer <code>composer install</code> même si <code>vendor/</code> existe déjà
                </label>
            </div>
        </div>

        <div class="card-footer d-flex justify-content-between align-items-center gap-3">
            <span class="text-muted small">
                Le fichier <code>.env</code> sera écrit avec <code>APP_DEBUG=false</code>.
                <?php if (!is_file(VENDOR_AUTOLOAD)): ?>
                    <br>L'installation des dépendances peut demander plusieurs minutes — ne fermez pas la page.
                <?php endif; ?>
            </span>
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
