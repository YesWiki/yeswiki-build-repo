<?php

namespace YesWikiRepo;

use GuzzleHttp\Client;
use ThibaudDauce\Mattermost\Attachment;
use ThibaudDauce\Mattermost\Mattermost;
use ThibaudDauce\Mattermost\Message;

class ScriptController extends Controller
{
    public function run($params): void
    {
        if (isset($params['action'])) {
            $this->repo->load();
            switch ($params['action']) {
                case 'build':
                    $target = $params['target'] ?? '';
                    $trigger = $target === ''
                        ? 'Build manuel de tous les paquets'
                        : "Build manuel de `{$target}`";
                    $results = $this->repo->build($params['target'] ?? null);
                    if (empty($results)) {
                        echo "{$trigger} : rien à construire, aucun paquet ne porte ce nom.\n";
                        break;
                    }
                    $this->sendMattermostNotification($results, $trigger);
                    break;
                case 'binary-check':
                    $this->checkBinaries(!empty($params['remind']));
                    break;
                case 'notify-test':
                    echo implode("\n", $this->diagnoseNotification()) . "\n";
                    break;
                case 'prune':
                    $this->prune(!empty($params['dry-run']));
                    break;
                case 'purge':
                    $log = $this->repo->purge();
                    $this->sendPurgeNotification($log);
                    break;
            }
        }
    }

    /** Delete the old branch builds of every channel, or only list them with dry-run. */
    private function prune(bool $dryRun): void
    {
        $total = 0;
        foreach ((new BuildPruner($this->repo->localConf))->pruneAll($dryRun) as $channel => $result) {
            $total += $result['bytes'];
            foreach ($result['versions'] as $version) {
                echo ($dryRun ? 'would delete ' : 'deleted ') . "{$channel}/{$version}\n";
            }
            printf("%s : %d files, %.1f MB\n", $channel, $result['files'], $result['bytes'] / 1e6);
        }
        printf("%s %.2f GB\n", $dryRun ? 'Would free' : 'Freed', $total / 1e9);
    }

    /** Publish the binaries signed since the last run, announce new ones to sign, and remind of pending ones when asked. */
    private function checkBinaries(bool $remind): void
    {
        $publisher = new BinaryPublisher($this->repo->localConf);
        foreach ($this->repo->binaryConf as $channel => $binaryConf) {
            $result = $publisher->publish($channel, $binaryConf);
            echo "{$channel} : {$result['binaryStatus']} {$result['tag']} {$result['error']}\n";

            if ($result['newlyPublished']) {
                $this->sendMattermostNotification([$result], "Binaire YesWiki `{$result['tag']}` publié sur le canal {$channel}");
            }

            if ($result['binaryStatus'] === BinaryPublisher::UNSIGNED) {
                $isNew = $publisher->isNewlyPending($result);
                if ($isNew || $remind) {
                    $this->sendPlainNotification($this->signatureRequest($publisher, $result, $isNew));
                }
            } elseif ($result['binaryStatus'] === BinaryPublisher::FAILED) {
                syslog(LOG_ERR, "Binary check of {$channel} failed : {$result['error']}");
                if ($remind) {
                    $this->sendMattermostNotification([$result], "Vérification du binaire du canal {$channel} impossible");
                }
            }
        }
    }

    /** The message asking for a binary to be signed, first announcement or reminder. */
    private function signatureRequest(BinaryPublisher $publisher, array $result, bool $isNew): string
    {
        $since = $publisher->pendingSince($result['channel']);
        $title = $isNew
            ? ":lock: Nouveau binaire YesWiki `{$result['tag']}` à signer pour le canal {$result['channel']}"
            : ":alarm_clock: Rappel : le binaire YesWiki `{$result['tag']}` du canal {$result['channel']} attend sa signature"
                . ($since !== '' ? " depuis le {$since}" : '');

        return $title . "\n" . $result['error']
            . "\nIl sera publié dans le repository dès que les `.sig` seront sur la release " . $result['releaseUrl']
            . "\n```\n" . $publisher->signingCommands($result) . "\n```";
    }

    private function sendPurgeNotification(string $log): void
    {
        if (empty($this->repo->localConf['mattermost-hook-url'])) {
            return;
        }
        $repoUrl = $this->repo->localConf['repo-url'];
        $authorName = $this->repo->localConf['mattermost-authorName'];
        $authorIcon = $this->repo->localConf['mattermost-authorIcon'];

        $mattermost = new Mattermost(new Client(), $this->repo->localConf['mattermost-hook-url']);
        $message = (new Message())
            ->text('Suppression du repository')
            ->channel($this->repo->localConf['mattermost-channel'])
            ->username($authorName)
            ->iconUrl($authorIcon)
            ->attachment(function (Attachment $attachment) use ($log, $repoUrl, $authorName, $authorIcon) {
                $attachment
                    ->fallback('Suppression du repository')
                    ->info()
                    ->authorName($authorName)
                    ->authorIcon($authorIcon)
                    ->authorLink($repoUrl)
                    ->field('Log', $log, false);
            });
        $mattermost->send($message);
    }
}
