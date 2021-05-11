<?php

namespace YesWikiRepo;

use Exception;
use GuzzleHttp\Client;
use ThibaudDauce\Mattermost\Mattermost;
use ThibaudDauce\Mattermost\Message;
use ThibaudDauce\Mattermost\Attachment;

class ScriptController extends Controller
{
    public function run($params)
    {
        if (isset($params['action'])) {
            $this->repo->load();
            switch ($params['action']) {
                case 'init':
                    $this->repo->init();
                break;
                case 'update':
                    if (empty($params['target'])) {
                        $this->repo->update();
                    } else {
                        $this->repo->update($params['target']);
                    }
                break;
                case 'purge':
                    $this->repo->purge();
                break;
            }
            if (!empty($this->repo->localConf['mattermost-hook-url'])) {
                $mattermost = new Mattermost(new Client(), $this->repo->localConf['mattermost-hook-url']);
                $message = (new Message())
                    ->text('Action lancée : '.$params['action'])
                    ->channel($this->repo->localConf['mattermost-channel'])
                    ->username($this->repo->localConf['mattermost-authorName'])
                    ->iconUrl($this->repo->localConf['mattermost-authorIcon'])
                    ->attachment(function (Attachment $attachment) {
                        $attachment->fallback('This is the fallback test for the attachment.')
                            ->success()
                            ->authorName($this->repo->localConf['mattermost-authorName'])
                            ->authorIcon($this->repo->localConf['mattermost-authorIcon'])
                            ->authorLink($this->repo->localConf['repo-url'])
                            //->title('Voir le repository', $this->repo->localConf['repo-url'])
                            ->field('Log du build', 'Testing with a very long piece of text that will take up the whole width of the table. And then some more text to make it extra long.', false);
                    });

                $mattermost->send($message);
            }
        }
    }
}
