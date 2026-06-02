<?php
namespace YesWikiRepo;

use \Exception;
use GuzzleHttp\Client;
use ThibaudDauce\Mattermost\Attachment;
use ThibaudDauce\Mattermost\Mattermost;
use ThibaudDauce\Mattermost\Message;

abstract class Controller
{
    protected $repo;

    public function __construct($repo)
    {
        $this->repo = $repo;
    }

    abstract public function run($params);

    /**
     * Send a Mattermost notification with one attachment per built package.
     *
     * Each result array must have keys:
     *   type, shortName, version, branch, tag, success, error, log (string[])
     */
    protected function sendMattermostNotification(array $results, string $title = ''): void
    {
        if (empty($this->repo->localConf['mattermost-hook-url']) || empty($results)) {
            return;
        }

        $mattermost = new Mattermost(new Client(), $this->repo->localConf['mattermost-hook-url']);

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $failCount = count($results) - $successCount;

        $summary = $title ?: ($failCount > 0
            ? "Build terminé avec {$failCount} erreur(s) sur " . count($results) . " paquet(s)"
            : "Build réussi : {$successCount} paquet(s) mis à jour");

        $message = (new Message())
            ->text($summary)
            ->channel($this->repo->localConf['mattermost-channel'])
            ->username($this->repo->localConf['mattermost-authorName'])
            ->iconUrl($this->repo->localConf['mattermost-authorIcon']);

        foreach ($results as $result) {
            $actionDesc = $this->buildActionDescription($result);
            $logText = implode("\n", $result['log']);
            $repoUrl = $this->repo->localConf['repo-url'];
            $authorName = $this->repo->localConf['mattermost-authorName'];
            $authorIcon = $this->repo->localConf['mattermost-authorIcon'];

            $elapsed = $result['elapsed'] ?? null;
            $message = $message->attachment(
                function (Attachment $attachment) use ($result, $actionDesc, $logText, $elapsed, $repoUrl, $authorName, $authorIcon) {
                    $attachment
                        ->fallback($actionDesc)
                        ->authorName($authorName)
                        ->authorIcon($authorIcon)
                        ->authorLink($repoUrl)
                        ->title($actionDesc);
                    if ($result['success']) {
                        $attachment->success()->field('Status', ':white_check_mark: Success', true);
                    } else {
                        $attachment->error()->field('Status', ':x: Failed', true);
                    }
                    if ($elapsed !== null) {
                        $attachment->field('Elapsed', "{$elapsed}s", true);
                    }
                    $attachment->field('Log', "```\n{$logText}\n```", false);
                }
            );
        }

        $mattermost->send($message);
    }

    private function buildActionDescription(array $result): string
    {
        $type = $result['type'];
        $name = $result['shortName'];

        if (!empty($result['tag']) && $result['tag'] === 'latest') {
            return "Build {$type} **{$name}** with tag **{$result['version']}**";
        }
        if (!empty($result['branch'])) {
            return "Build {$type} **{$name}** from latest branch **{$result['branch']}**";
        }
        if (!empty($result['tag'])) {
            return "Build {$type} **{$name}** with tag **{$result['tag']}**";
        }
        return "Build {$type} **{$name}**";
    }
}
