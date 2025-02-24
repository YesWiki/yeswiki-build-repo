<?php

namespace YesWikiRepo;

use GuzzleHttp\Client;
use ThibaudDauce\Mattermost\Mattermost;
use ThibaudDauce\Mattermost\Message;
use ThibaudDauce\Mattermost\Attachment;

class ScriptController extends Controller
{
    public function run($params): void
    {
        if (isset($params['action'])) {
            $this->repo->load();
            $log = $message = '';
            switch ($params['action']) {
                case 'build':
                    $log = $this->repo->build($params['target'] ?? null);
                    $message = 'Build du repository'.(!empty($params['target']) ? ' pour '.$params['target'] : '');
                    break;
                case 'purge':
                    $log = $this->repo->purge();
                    $message = 'Suppression du repository';
                    break;
            }
            if (!empty($this->repo->localConf['mattermost-hook-url'])) {
                $mattermost = new Mattermost(new Client(), $this->repo->localConf['mattermost-hook-url']);
                $message = (new Message())
                    ->text($message)
                    ->channel($this->repo->localConf['mattermost-channel'])
                    ->username($this->repo->localConf['mattermost-authorName'])
                    ->iconUrl($this->repo->localConf['mattermost-authorIcon'])
                    ->attachment(function (Attachment $attachment) {
                        $attachment->fallback('Erreur avec le log attaché.')
                            ->success()
                            ->authorName($this->repo->localConf['mattermost-authorName'])
                            ->authorIcon($this->repo->localConf['mattermost-authorIcon'])
                            ->authorLink($this->repo->localConf['repo-url'])
                            //->title('Voir le repository', $this->repo->localConf['repo-url'])
                            ->field('Log du build', $log, false);
                    });

                $mattermost->send($message);
            }
        }
    }
}
