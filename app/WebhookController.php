<?php

namespace YesWikiRepo;

use Exception;

class WebhookController extends Controller
{
    public function run($params)
    {
        $this->repo->load();
        $this->repo->activateExceptionPassThrough();
        file_put_contents('webhook.log',  $this->getRepository($params) . "\n" . $this->getBranch($params) . "\n---------\n", FILE_APPEND | LOCK_EX);

        $this->repo->updateHook(
            $this->getRepository($params),
            $this->getBranch($params)
        );
        $this->repo->inactivateExceptionPassThrough();
    }

    public function isAuthorizedHook(): bool
    {
        if (!empty($this->repo->localConf['testing_mode']) && $this->repo->localConf['testing_mode'] === true) {
            return true; // in testing mode, everything is permitted
        }
        $header = getallheaders();
        $content = file_get_contents('php://input');
        return (!empty($this->repo->localConf['github-secret']) &&
            isset($header['X-Hub-Signature-256']) &&
            function_exists('hash_hmac') &&
            $header['X-Hub-Signature-256'] == 'sha256=' . hash_hmac('sha256', $content, $this->repo->localConf['github-secret'])
        );
    }

    private function getBranch($params)
    {
        if (empty($params['ref'])) {
            throw new Exception("'ref' should be set !");
        }
        if (!is_string($params['ref'])) {
            throw new Exception("'ref' should be a string !");
        }
        return substr($params['ref'], strlen('refs/heads/'));
    }

    private function getRepository($params)
    {
        if (isset($params['repository']) && isset($params['repository']['html_url'])) {
            $repoUrl = $params['repository']['html_url'];
            return substr($repoUrl, -1) == '/' ? substr($repoUrl, 0, -1) : $repoUrl;
        }
        throw new Exception("Bad hook format.");
    }
}
