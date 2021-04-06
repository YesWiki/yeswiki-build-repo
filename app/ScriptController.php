<?php
namespace YesWikiRepo;

use \Exception;
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
                $mattermost = new Mattermost(new Client, $this->repo->localConf['mattermost-hook-url']);
                $message = (new Message)
                    ->text('Action lancée : '.$params['action'])
                    ->channel('repository')
                    ->username('Repository Builder')
                    ->iconUrl('https://www.veryicon.com/icon/png/Movie%20%26%20TV/Futurama%20Vol.%206%20-%20The%20Movies/Steamboat%20Bender.png')
                    ->attachment(function (Attachment $attachment) {
                        $attachment->fallback('This is the fallback test for the attachment.')
                            ->success()
                            //->pretext('This is optional pretext that shows above the attachment.')
                            ->text('This is the text. **Finaly!**')
                            ->authorName('Repository Builder')
                            ->authorIcon('https://www.veryicon.com/icon/png/Movie%20%26%20TV/Futurama%20Vol.%206%20-%20The%20Movies/Steamboat%20Bender.png')
                            ->authorLink('https://repository.yeswiki.net/')
                            ->title('Example attachment', 'http://docs.mattermost.com/developer/message-attachments.html')
                            ->field('Long field', 'Testing with a very long piece of text that will take up the whole width of the table. And then some more text to make it extra long.', false)
                            ->field('Column one', 'Testing.', true)
                            ->field('Column two', 'Testing.', true)
                            ->field('Column one again', 'Testing.', true)
                            ->imageUrl('http://www.mattermost.org/wp-content/uploads/2016/03/logoHorizontal_WS.png')
                            ->action([
                                'name' => 'Voir le repository',
                                'integration' => [
                                    'url' => 'https://repository.yeswiki.net/',
                                ]
                            ]);
                    });

                $mattermost->send($message);
            }
        }
    }
}
