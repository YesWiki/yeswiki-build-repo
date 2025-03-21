<?php

namespace YesWikiRepo;

use Exception;

class WebhookController extends Controller
{
    public function run($params): void
    {
        $this->repo->load();
        $this->repo->updateHook(
            $this->getRepository($params),
            $this->getBranch($params)
        );
    }

    public function isAuthorizedHook(): bool
    {
        $header = getallheaders();
        $content = file_get_contents('php://input');
        return (!empty($this->repo->localConf['github-secret']) &&
            isset($header['X-Hub-Signature-256']) &&
            function_exists('hash_hmac') &&
            $header['X-Hub-Signature-256'] == 'sha256='.hash_hmac('sha256', $content, $this->repo->localConf['github-secret'])
        );
    }
    /**
     * @param mixed $params
     */
    private function getBranch($params): string
    {
        if (empty($params['ref'])) {
            throw new Exception("'ref' should be set !");
        }
        if (!is_string($params['ref'])) {
            throw new Exception("'ref' should be a string !");
        }
        return substr($params['ref'], strlen('refs/heads/'));
    }
    /**
     * @param mixed $params
     */
    private function getRepository($params): string
    {
        if (isset($params['repository']) && isset($params['repository']['html_url'])) {
            $repoUrl = $params['repository']['html_url'];
            return substr($repoUrl, -1) == '/' ? substr($repoUrl, 0, -1) : $repoUrl;
        }
        throw new Exception("Bad hook format.");
    }
}
