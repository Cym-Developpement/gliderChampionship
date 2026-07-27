<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Déploiement déclenché par le webhook GitHub : git pull, dépendances,
 * migrations et régénération des caches.
 */
class DeployService
{
    /** Empêche deux déploiements simultanés (deux push rapprochés). */
    private const LOCK_FILE = 'deploy.lock';

    /** @var list<string> */
    private array $log = [];

    private ?string $phpBinary = null;

    private ?string $cloneUrl = null;

    /**
     * @param string|null $cloneUrl URL de clonage transmise par le webhook,
     *                              utilisée si le répertoire n'est pas encore
     *                              un dépôt git et qu'aucune URL n'est configurée.
     * @return array{ok: bool, log: string}
     */
    public function run(?string $cloneUrl = null): array
    {
        $this->cloneUrl = $cloneUrl;

        $lock = $this->acquireLock();
        if ($lock === null) {
            return ['ok' => false, 'log' => 'Un déploiement est déjà en cours.'];
        }

        try {
            $ok = $this->deploy();
        } catch (\Throwable $ex) {
            $this->line('EXCEPTION : ' . $ex->getMessage());
            $ok = false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $log = implode(PHP_EOL, $this->log);
        Log::channel('deploy')->{$ok ? 'info' : 'error'}('Déploiement ' . ($ok ? 'réussi' : 'échoué'), ['log' => $log]);

        return ['ok' => $ok, 'log' => $log];
    }

    private function deploy(): bool
    {
        $branch = (string) config('services.github.deploy_branch', 'main');

        // 0. Capacités de l'hébergement — première chose à vérifier en cas d'échec
        foreach ($this->environment() as $label => $value) {
            $this->line($label . ' : ' . $value);
        }
        $this->line('');

        if (!$this->commandsAvailable()) {
            $this->line('ÉCHEC : proc_open() est désactivée sur cet hébergement (directive disable_functions).');
            $this->line('Le déploiement automatique ne peut pas lancer git. Passez par GitHub Actions en FTP/SSH.');
            return false;
        }

        // 1. Récupération du code
        $git = $this->findBinary(['git', '/usr/bin/git', '/usr/local/bin/git', '/bin/git']);
        if ($git === null) {
            $this->line('ÉCHEC : binaire git introuvable dans le PATH ni aux emplacements usuels.');
            return false;
        }

        if (!$this->ensureRepository($git, $branch)) {
            return false;
        }

        $pull = $this->exec([$git, 'pull', '--ff-only', 'origin', $branch], 300);
        $this->line('$ git pull --ff-only origin ' . $branch);
        $this->line($pull['output']);

        // Le code arrivant aussi par FTP, des fichiers suivis peuvent diverger
        // et bloquer la fusion. On ne les écarte qu'à ce moment-là : les
        // supprimer d'emblée détruirait un travail en cours sans nécessité.
        if ($pull['code'] !== 0 && $this->blockedByLocalChanges($pull['output'])) {
            $this->line('Fichiers locaux divergents — réalignement sur le dépôt.');

            $discard = $this->exec([$git, 'checkout', '--', '.'], 60);
            $this->line('$ git checkout -- .');
            $this->line($discard['output']);

            $pull = $this->exec([$git, 'pull', '--ff-only', 'origin', $branch], 300);
            $this->line('$ git pull --ff-only origin ' . $branch . '   (seconde tentative)');
            $this->line($pull['output']);
        }

        if ($pull['code'] !== 0) {
            $this->line('ÉCHEC : git pull a retourné ' . $pull['code'] . '.');
            return false;
        }

        // 2. Dépendances — uniquement si composer.lock a bougé
        if ($this->lockFileChanged($git)) {
            if (!$this->installDependencies()) {
                return false;
            }
        } else {
            $this->line('composer.lock inchangé — dépendances non réinstallées.');
        }

        // 3. Migrations et caches
        //    Exécutés dans un sous-processus quand c'est possible : le code PHP
        //    chargé en mémoire est celui d'avant le pull.
        if (!$this->runArtisan()) {
            return false;
        }

        // 4. Fige la version déployée pour le pied de page de l'administration.
        $version = VersionService::record();
        $this->line($version !== null
            ? 'Version déployée : ' . substr($version['sha'], 0, 7) . ' — ' . $version['subject']
            : 'Version non enregistrée (git illisible).');

        return true;
    }

    /**
     * S'assure que le répertoire est un dépôt git rattaché à origin.
     *
     * Premier déploiement sur un répertoire téléversé « à plat » : on initialise
     * le dépôt puis on se cale sur la branche distante avec `reset --mixed`,
     * qui repositionne l'historique sans toucher aux fichiers présents. Un
     * `reset --hard` écraserait les éventuels ajustements faits sur le serveur.
     */
    private function ensureRepository(string $git, string $branch): bool
    {
        $inside = $this->exec([$git, 'rev-parse', '--is-inside-work-tree'], 30);

        if ($inside['code'] === 0 && str_contains($inside['output'], 'true')) {
            return $this->ensureOrigin($git);
        }

        $url = $this->repositoryUrl();
        if ($url === null) {
            $this->line('ÉCHEC : le répertoire n\'est pas un dépôt git et aucune URL de clonage n\'est disponible.');
            $this->line('Renseignez GITHUB_REPOSITORY_URL dans le .env.');
            return false;
        }

        $this->line('Répertoire non versionné — initialisation du dépôt.');

        foreach ([
            [$git, 'init', '-b', $branch],
            [$git, 'remote', 'add', 'origin', $url],
            [$git, 'fetch', 'origin', $branch],
        ] as $command) {
            $run = $this->exec($command, 300);
            $this->line('$ git ' . implode(' ', array_slice($command, 1)));
            $this->line($run['output']);

            if ($run['code'] !== 0) {
                $this->line('ÉCHEC : initialisation interrompue (code ' . $run['code'] . ').');
                return false;
            }
        }

        // Rattache l'historique sans modifier le contenu du répertoire.
        $reset = $this->exec([$git, 'reset', '--mixed', 'FETCH_HEAD'], 120);
        $this->line('$ git reset --mixed FETCH_HEAD');
        $this->line($reset['output']);

        if ($reset['code'] !== 0) {
            $this->line('ÉCHEC : impossible de se caler sur ' . $branch . '.');
            return false;
        }

        $this->exec([$git, 'branch', '--set-upstream-to=origin/' . $branch, $branch], 30);

        // Signale les fichiers suivis qui diffèrent déjà du distant : le pull
        // suivant échouerait dessus, autant que ce soit explicite dans le log.
        $status = $this->exec([$git, 'status', '--porcelain', '--untracked-files=no'], 60);
        if (trim($status['output']) !== '') {
            $this->line('Fichiers suivis différant du dépôt distant :');
            $this->line($status['output']);
            $this->line('Ils seront conservés ; un git pull ultérieur peut échouer tant qu\'ils divergent.');
        }

        $this->line('Dépôt initialisé sur ' . $url . ' (branche ' . $branch . ').');

        return true;
    }

    /** Le pull a-t-il échoué à cause de modifications locales, et non d'autre chose ? */
    private function blockedByLocalChanges(string $output): bool
    {
        foreach (['local changes', 'would be overwritten', 'modifications locales'] as $marker) {
            if (stripos($output, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Ajoute le remote origin s'il manque à un dépôt existant. */
    private function ensureOrigin(string $git): bool
    {
        $remote = $this->exec([$git, 'remote', 'get-url', 'origin'], 30);
        if ($remote['code'] === 0 && trim($remote['output']) !== '') {
            return true;
        }

        $url = $this->repositoryUrl();
        if ($url === null) {
            $this->line('ÉCHEC : dépôt git sans remote « origin » et aucune URL configurée.');
            return false;
        }

        $add = $this->exec([$git, 'remote', 'add', 'origin', $url], 30);
        $this->line('$ git remote add origin ' . $url);
        $this->line($add['output']);

        return $add['code'] === 0;
    }

    /**
     * URL de clonage : configuration d'abord, charge utile du webhook ensuite.
     * Restreinte à HTTPS sur github.com — on ne clone pas une URL arbitraire,
     * ni une URL en ssh:// ou file:// qui pourrait pointer n'importe où.
     */
    private function repositoryUrl(): ?string
    {
        foreach ([(string) config('services.github.repository_url'), (string) $this->cloneUrl] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            $parts = parse_url($candidate);
            if (($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'github.com') {
                return $candidate;
            }

            $this->line('URL de dépôt ignorée (HTTPS sur github.com attendu) : ' . $candidate);
        }

        return null;
    }

    /** Compare composer.lock avant/après le pull via le diff du dernier merge. */
    private function lockFileChanged(string $git): bool
    {
        $diff = $this->exec([$git, 'diff', '--name-only', 'HEAD@{1}', 'HEAD'], 60);

        // En cas de doute (premier pull, reflog vide…), on réinstalle.
        if ($diff['code'] !== 0) {
            return true;
        }

        return str_contains($diff['output'], 'composer.lock')
            || str_contains($diff['output'], 'composer.json');
    }

    private function installDependencies(): bool
    {
        $php = $this->phpBinary();
        if ($php === null) {
            $this->line('AVERTISSEMENT : aucun binaire PHP CLI, composer install non exécuté.');
            $this->line('Lancez-le manuellement si les dépendances ont changé.');
            return true;   // non bloquant : le code est à jour, seul vendor/ peut être en retard
        }

        $composer = $this->findComposer();
        if ($composer === null) {
            $this->line('AVERTISSEMENT : Composer introuvable, dépendances non mises à jour.');
            return true;
        }

        $run = $this->exec([
            $php, '-d', 'memory_limit=-1', $composer,
            'install', '--no-dev', '--optimize-autoloader',
            '--no-interaction', '--no-progress', '--prefer-dist',
        ], 900);

        $this->line('$ composer install --no-dev --optimize-autoloader');
        $this->line($run['output']);

        if ($run['code'] !== 0) {
            $this->line('ÉCHEC : composer install a retourné ' . $run['code'] . '.');
            return false;
        }

        return true;
    }

    /**
     * migrate --force puis régénération des caches.
     * config:cache reste volontairement exclu : plusieurs env() sont lus hors
     * des fichiers de config et deviendraient nuls (voir DEPLOIEMENT.md §6).
     */
    private function runArtisan(): bool
    {
        $commands = [
            ['migrate', ['--force' => true]],
            ['view:cache', []],
            ['route:cache', []],
        ];

        $php = $this->phpBinary();

        foreach ($commands as [$command, $options]) {
            if ($php !== null) {
                $args = [$php, base_path('artisan'), $command];
                foreach (array_keys($options) as $flag) {
                    $args[] = $flag;
                }
                $run = $this->exec($args, 300);
                $this->line('$ php artisan ' . $command);
                $this->line($run['output']);

                if ($run['code'] !== 0) {
                    $this->line('ÉCHEC : artisan ' . $command . ' a retourné ' . $run['code'] . '.');
                    return false;
                }
                continue;
            }

            // Repli sans binaire CLI : exécution dans le processus courant.
            try {
                Artisan::call($command, $options);
                $this->line('$ artisan ' . $command . ' (en processus)');
                $this->line(trim(Artisan::output()));
            } catch (\Throwable $ex) {
                $this->line('ÉCHEC : artisan ' . $command . ' — ' . $ex->getMessage());
                return false;
            }
        }

        return true;
    }

    // ─── Utilitaires ─────────────────────────────────────────────────────────

    /** proc_open() est fréquemment désactivée en mutualisé. */
    public function commandsAvailable(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('proc_open') && !in_array('proc_open', $disabled, true);
    }

    /**
     * Capacités de l'hébergement, pour comprendre un échec sans accès SSH.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $commands = $this->commandsAvailable();

        return [
            'proc_open'  => $commands ? 'autorisée' : 'DÉSACTIVÉE',
            'répertoire' => base_path(),
            'dépôt git'  => is_dir(base_path('.git')) ? 'présent' : 'absent',
            'git'        => $commands
                ? ($this->findBinary(['git', '/usr/bin/git', '/usr/local/bin/git', '/bin/git']) ?? 'INTROUVABLE')
                : 'non testable',
            'php CLI'    => $commands ? ($this->phpBinary() ?? 'INTROUVABLE') : 'non testable',
            'PATH'       => getenv('PATH') ?: '(vide)',
            'branche'    => (string) config('services.github.deploy_branch', 'main'),
        ];
    }

    /** @return resource|null */
    private function acquireLock()
    {
        $path = storage_path('app/private/' . self::LOCK_FILE);
        $dir  = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    private function phpBinary(): ?string
    {
        if ($this->phpBinary !== null) {
            return $this->phpBinary;
        }

        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $compact = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;

        $candidates = [];

        // PHP_BINARY vaut php-fpm ou php-cgi sous SAPI web : inutilisable tel
        // quel, mais directement exploitable dans le cas contraire.
        if (!preg_match('/(fpm|cgi)/', PHP_BINARY)) {
            $candidates[] = PHP_BINARY;
        }

        $candidates = array_merge($candidates, [
            PHP_BINDIR . '/php' . $version,
            PHP_BINDIR . '/php' . PHP_MAJOR_VERSION,
            PHP_BINDIR . '/php',
            '/usr/local/php' . $version . '/bin/php',   // OVH mutualisé
            '/opt/alt/php' . $compact . '/usr/bin/php', // CloudLinux
            '/opt/cpanel/ea-php' . $compact . '/root/usr/bin/php',
            '/opt/plesk/php/' . $version . '/bin/php',
            '/usr/local/bin/php',
            '/usr/bin/php',
            // En dernier ressort le nom nu : le shell le résout avec son propre
            // PATH, ce qui aboutit là où les chemins devinés échouent.
            'php' . $version,
            'php',
        ]);

        return $this->phpBinary = $this->findBinary($candidates, '-r', 'echo PHP_SAPI;', 'cli');
    }

    private function findComposer(): ?string
    {
        foreach ([base_path('composer.phar'), '/usr/local/bin/composer', '/usr/bin/composer'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Cherche un binaire en tentant de l'exécuter : is_executable() échoue sous
     * open_basedir même quand le binaire est utilisable.
     *
     * @param list<string> $candidates
     */
    private function findBinary(array $candidates, string ...$probe): ?string
    {
        $expect = array_pop($probe) ?? '';

        foreach ($candidates as $candidate) {
            $args = $probe === [] ? [$candidate, '--version'] : array_merge([$candidate], $probe);
            $run  = $this->exec($args, 15);

            if ($run['code'] === 0 && ($expect === '' || str_contains($run['output'], $expect))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Exécute une commande via Symfony Process.
     *
     * Point décisif sur hébergement mutualisé : Process **hérite** de
     * l'environnement du parent et passe par le shell, qui résout donc les
     * binaires avec son propre PATH. Un proc_open() nourri d'un tableau
     * d'environnement le remplace au contraire intégralement — et un PATH
     * deviné rendait git introuvable alors qu'il est bien installé.
     *
     * @param list<string> $cmd
     * @return array{code: int, output: string}
     */
    private function exec(array $cmd, int $timeout, ?callable $onOutput = null): array
    {
        if (!$this->commandsAvailable()) {
            return ['code' => -1, 'output' => 'proc_open() est désactivée sur ce serveur.'];
        }

        try {
            $process = new Process($cmd, base_path(), [
                // Ajoutées à l'environnement hérité, non substituées à lui.
                'HOME'                     => base_path(),
                'COMPOSER_HOME'            => base_path() . '/.composer',
                'COMPOSER_NO_INTERACTION'  => '1',
                'COMPOSER_ALLOW_SUPERUSER' => '1',
                'GIT_TERMINAL_PROMPT'      => '0',   // jamais d'invite d'identifiants
            ]);
            $process->setTimeout($timeout);

            $process->run($onOutput === null ? null : function ($type, $buffer) use ($onOutput) {
                $onOutput($buffer);
            });

            return [
                'code'   => $process->getExitCode() ?? -1,
                'output' => trim($process->getOutput() . $process->getErrorOutput()),
            ];
        } catch (\Throwable $ex) {
            return ['code' => -1, 'output' => $ex->getMessage()];
        }
    }

    private function line(string $text): void
    {
        // Composer et Artisan colorisent leur sortie : illisible dans un journal.
        $text = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text);

        if (trim($text) !== '') {
            $this->log[] = rtrim($text);
        }
    }
}
