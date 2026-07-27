<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

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

    /**
     * @return array{ok: bool, log: string}
     */
    public function run(): array
    {
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

        // 1. Récupération du code
        $git = $this->findBinary(['git', '/usr/bin/git', '/usr/local/bin/git', '/bin/git']);
        if ($git === null) {
            $this->line('ÉCHEC : binaire git introuvable ou proc_open() désactivée.');
            return false;
        }

        $pull = $this->exec([$git, 'pull', '--ff-only', 'origin', $branch], 300);
        $this->line('$ git pull --ff-only origin ' . $branch);
        $this->line($pull['output']);
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
        return $this->runArtisan();
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

        return $this->phpBinary = $this->findBinary([
            'php',
            'php' . $version,
            '/usr/local/php' . $version . '/bin/php',   // OVH mutualisé
            '/opt/alt/php' . $compact . '/usr/bin/php', // CloudLinux
            '/opt/cpanel/ea-php' . $compact . '/root/usr/bin/php',
            '/opt/plesk/php/' . $version . '/bin/php',
            PHP_BINDIR . '/php',
            '/usr/local/bin/php',
            '/usr/bin/php',
        ], '-r', 'echo PHP_SAPI;', 'cli');
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
     * @param list<string> $cmd
     * @return array{code: int, output: string}
     */
    private function exec(array $cmd, int $timeout): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            return ['code' => -1, 'output' => 'proc_open() est désactivée sur ce serveur.'];
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [
            'PATH'                     => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME'                     => storage_path('app/private/composer'),
            'COMPOSER_HOME'            => storage_path('app/private/composer'),
            'COMPOSER_NO_INTERACTION'  => '1',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'GIT_TERMINAL_PROMPT'      => '0',   // jamais de prompt d'identifiants
        ];

        $process = @proc_open($cmd, $descriptors, $pipes, base_path(), $env);
        if (!is_resource($process)) {
            return ['code' => -1, 'output' => 'Impossible de lancer : ' . implode(' ', $cmd)];
        }

        fclose($pipes[0]);
        $output   = '';
        $start    = time();
        $exitCode = -1;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status  = proc_get_status($process);
            $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($process, 9);
                $output .= PHP_EOL . "[Interrompu après {$timeout} s]";
                break;
            }
            usleep(200000);
        }

        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ['code' => $exitCode, 'output' => trim($output)];
    }

    private function line(string $text): void
    {
        if (trim($text) !== '') {
            $this->log[] = rtrim($text);
        }
    }
}
