<?php
namespace YesWikiRepo;

use \Exception;

class ScriptController extends Controller
{
    public function run($params)
    {
        if (isset($params['action'])) {
            $this->repo->load();
            switch ($params['action']) {
                case 'init':
                    $this->repo->init();
                    return;
                case 'update':
                    if (empty($params['target'])) {
                        $this->repo->update();
                    } else {
                        $this->repo->update($params['target']);
                    }
                    return;
                case 'purge':
                    $this->repo->purge();
                    return;
            }
        }
    }
}
