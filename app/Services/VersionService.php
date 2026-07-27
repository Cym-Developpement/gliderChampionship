<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Identifie la version du code réellement déployée.
 *
 * Deux sources, dans cet ordre :
 *  1. le dépôt git local, quand il est lisible — toujours à jour ;
 *  2. storage/app/private/version.json, écrit à chaque déploiement réussi,
 *     qui prend le relais si git devient inaccessible (proc_open() désactivée,
 *     .git retiré du serveur).
 *
 * Git d'abord : un version.json figé masquerait sinon les commits suivants
 * sur une machine où le dépôt est pourtant à jour.
 *
 * Sans aucune des deux sources, la méthode renvoie null et le pied de page
 * affiche « version inconnue » plutôt qu'une information fausse.
 */
class VersionService
{
    private const FILE = 'version.json';

    private const CACHE_KEY = 'app.deployed_version';

    /**
     * @return array{sha: string, subject: string, date: ?string}|null
     */
    public static function current(): ?array
    {
        try {
            return Cache::remember(
                self::CACHE_KEY,
                now()->addMinutes(5),
                fn () => self::fromGit() ?? self::fromFile()
            );
        } catch (\Throwable) {
            // Cache indisponible (table absente…) : lecture directe.
            return self::fromGit() ?? self::fromFile();
        }
    }

    /**
     * Fige la version courante après un déploiement.
     *
     * @return array{sha: string, subject: string, date: ?string}|null
     */
    public static function record(): ?array
    {
        $info = self::fromGit();
        if ($info === null) {
            return null;
        }

        Storage::disk('local')->put(self::FILE, (string) json_encode($info, JSON_UNESCAPED_UNICODE));

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // sans conséquence : le cache expire de lui-même
        }

        return $info;
    }

    /** URL du commit sur GitHub, si le dépôt est connu. */
    public static function commitUrl(string $sha): ?string
    {
        $repo = (string) config('services.github.repository_url');
        if ($repo === '') {
            return null;
        }

        return rtrim(preg_replace('/\.git$/', '', $repo), '/') . '/commit/' . $sha;
    }

    private static function fromFile(): ?array
    {
        try {
            if (!Storage::disk('local')->exists(self::FILE)) {
                return null;
            }
            $data = json_decode((string) Storage::disk('local')->get(self::FILE), true);
        } catch (\Throwable) {
            return null;
        }

        return self::normalise(is_array($data) ? $data : []);
    }

    private static function fromGit(): ?array
    {
        try {
            // %x1f = séparateur d'unité, absent d'un message de commit.
            $result = Process::path(base_path())
                ->timeout(10)
                ->run('git log -1 --pretty=format:%H%x1f%s%x1f%cI');

            if (!$result->successful()) {
                return null;
            }
        } catch (\Throwable) {
            return null;   // proc_open() désactivée, git absent…
        }

        $parts = explode("\x1f", trim($result->output()));

        return self::normalise([
            'sha'     => $parts[0] ?? '',
            'subject' => $parts[1] ?? '',
            'date'    => $parts[2] ?? null,
        ]);
    }

    private static function normalise(array $data): ?array
    {
        $sha     = trim((string) ($data['sha'] ?? ''));
        $subject = trim((string) ($data['subject'] ?? ''));

        if ($sha === '' || $subject === '') {
            return null;
        }

        return [
            'sha'     => $sha,
            'subject' => $subject,
            'date'    => $data['date'] ?? null,
        ];
    }
}
