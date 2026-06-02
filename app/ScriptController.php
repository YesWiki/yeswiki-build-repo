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
                    $results = $this->repo->build($params['target'] ?? null);
                    $this->sendMattermostNotification($results);
                    break;
                case 'purge':
                    $log = $this->repo->purge();
                    $this->sendPurgeNotification($log);
                    break;
            }
        }
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
