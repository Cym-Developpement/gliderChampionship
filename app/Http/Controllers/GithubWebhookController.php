<?php

namespace App\Http\Controllers;

use App\Services\DeployService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Réception des webhooks GitHub : déclenche un déploiement sur push.
 *
 * Configuration côté GitHub (Settings → Webhooks) :
 *   Payload URL  : https://…/webhook/github
 *   Content type : application/json
 *   Secret       : la valeur de GITHUB_WEBHOOK_SECRET
 *   Événements   : « Just the push event »
 */
class GithubWebhookController extends Controller
{
    public function __invoke(Request $request, DeployService $deployer)
    {
        if (!config('services.github.deploy_enabled')) {
            return response()->json(['error' => 'Déploiement automatique désactivé.'], 503);
        }

        $secret = (string) config('services.github.webhook_secret');
        if ($secret === '') {
            Log::channel('deploy')->error('Webhook reçu mais GITHUB_WEBHOOK_SECRET n\'est pas défini.');
            return response()->json(['error' => 'Secret non configuré.'], 503);
        }

        if (!$this->signatureIsValid($request, $secret)) {
            Log::channel('deploy')->warning('Webhook rejeté : signature invalide.', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Signature invalide.'], 401);
        }

        $event = (string) $request->header('X-GitHub-Event', '');

        if ($event === 'ping') {
            return response()->json(['ok' => true, 'pong' => true]);
        }

        if ($event !== 'push') {
            return response()->json(['ok' => true, 'ignored' => 'événement ' . $event]);
        }

        $branch   = (string) config('services.github.deploy_branch', 'main');
        $ref      = (string) $request->input('ref', '');
        $expected = 'refs/heads/' . $branch;

        if ($ref !== $expected) {
            return response()->json(['ok' => true, 'ignored' => 'branche ' . $ref]);
        }

        Log::channel('deploy')->info('Push reçu, déploiement programmé.', [
            'ref'     => $ref,
            'commits' => count((array) $request->input('commits', [])),
            'pusher'  => $request->input('pusher.name'),
        ]);

        // GitHub coupe la connexion au bout de 10 s : on répond tout de suite et
        // le déploiement se poursuit une fois la réponse envoyée.
        // clone_url sert uniquement au tout premier déploiement, quand le
        // répertoire n'est pas encore un dépôt git.
        $cloneUrl = (string) $request->input('repository.clone_url', '');

        // Mode diagnostic : ?sync=1 exécute le déploiement immédiatement et
        // renvoie le journal complet. GitHub n'envoie jamais ce paramètre, sa
        // livraison conserve donc sa réponse immédiate.
        if ($request->boolean('sync')) {
            $result = $deployer->run($cloneUrl !== '' ? $cloneUrl : null);

            return response()->json([
                'ok'          => $result['ok'],
                'branch'      => $branch,
                'environment' => $deployer->environment(),
                'log'         => explode(PHP_EOL, $result['log']),
            ], $result['ok'] ? 200 : 500, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        dispatch(function () use ($deployer, $cloneUrl) {
            @ignore_user_abort(true);
            @set_time_limit(0);
            $deployer->run($cloneUrl !== '' ? $cloneUrl : null);
        })->afterResponse();

        // Content-Length + Connection: close permettent aux SAPI dépourvues de
        // fastcgi_finish_request() (mod_php…) de libérer GitHub sans attendre.
        $payload = json_encode(['ok' => true, 'deploying' => $branch]);

        return response($payload, 202, [
            'Content-Type'   => 'application/json',
            'Content-Length' => (string) strlen($payload),
            'Connection'     => 'close',
        ]);
    }

    /**
     * Mode debug : déclenche un déploiement par simple GET, sans signature,
     * et renvoie le journal brut en text/plain.
     *
     * Réservé au diagnostic sur un hébergement sans accès SSH. L'accès est
     * conditionné à GITHUB_DEPLOY_DEBUG=true — sans quoi la route répond 404,
     * ce qui ne révèle pas son existence. À remettre à false ensuite : sans
     * signature, quiconque connaît l'URL peut déclencher un déploiement et
     * lire les chemins du serveur.
     */
    public function debug(Request $request, DeployService $deployer)
    {
        if (!config('services.github.deploy_debug')) {
            abort(404);
        }

        $lines = [];

        if ($request->boolean('dry')) {
            // Hors mode dry, le journal du déploiement rapporte déjà ces lignes.
            $lines[] = '=== Environnement ===';
            foreach ($deployer->environment() as $label => $value) {
                // str_pad compterait les octets : « répertoire » serait désaligné.
                $lines[] = $label . str_repeat(' ', max(1, 12 - mb_strlen($label))) . ': ' . $value;
            }
            $lines[] = '';
            $lines[] = '(mode dry : environnement seulement, aucun déploiement lancé)';
        } else {
            Log::channel('deploy')->warning('Déploiement déclenché en mode debug (sans signature).', [
                'ip' => $request->ip(),
            ]);

            $result  = $deployer->run();
            $lines[] = '=== Déploiement ===';
            $lines[] = $result['log'];
            $lines[] = '';
            $lines[] = $result['ok'] ? '>>> SUCCÈS' : '>>> ÉCHEC';
        }

        return response(implode(PHP_EOL, $lines) . PHP_EOL, 200, [
            'Content-Type'  => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag'  => 'noindex, nofollow',
        ]);
    }

    /** Contrôle HMAC SHA-256 de la charge utile brute. */
    private function signatureIsValid(Request $request, string $secret): bool
    {
        $received = (string) $request->header('X-Hub-Signature-256', '');
        if ($received === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $received);
    }
}
