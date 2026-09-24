<?php

namespace YesWikiRepo;

use Exception;

class WebhookController extends Controller
{
    const RELEASE_ACTIONS = ['published', 'released', 'prereleased'];

    public function run($params, string $event = 'push'): void
    {
        $this->repo->load();
        $repositoryUrl = $this->getRepository($params);
        $trigger = $this->describeTrigger($params, $event);
        $results = [];
        $ignored = '';

        if ($event === 'release') {
            $action = $params['action'] ?? '';
            if (in_array($action, self::RELEASE_ACTIONS, true)) {
                $results = $this->repo->updateHookForLatestTag(
                    $repositoryUrl,
                    $params['release']['tag_name'] ?? ''
                );
            } else {
                $ignored = "l'action `{$action}` n'est pas suivie";
            }
        } else {
            $ref = $params['ref'] ?? '';
            if (str_starts_with($ref, 'refs/tags/')) {
                $results = $this->repo->updateHookForLatestTag(
                    $repositoryUrl,
                    substr($ref, strlen('refs/tags/'))
                );
            } else {
                $results = $this->repo->updateHook($repositoryUrl, $this->getBranch($params));
            }
        }

        if (empty($results)) {
            $reason = $ignored !== '' ? $ignored : 'aucun canal ne suit ce dépôt sur cette branche ou cette série';
            syslog(LOG_INFO, "{$trigger} : rien à construire, {$reason}");
            return;
        }

        $this->sendMattermostNotification($results, $trigger);
    }

    /** What GitHub just sent, so a notification says what it is answering. */
    private function describeTrigger($params, string $event): string
    {
        $repository = $params['repository']['full_name'] ?? $this->getRepository($params);
        $who = $params['pusher']['name'] ?? $params['sender']['login'] ?? '';
        $by = $who === '' ? '' : ' par ' . $who;

        if ($event === 'release') {
            $tag = $params['release']['tag_name'] ?? '';
            $action = $params['action'] ?? '';
            return "Release `{$tag}` ({$action}) sur **{$repository}**{$by}";
        }

        $ref = $params['ref'] ?? '';
        if (str_starts_with($ref, 'refs/tags/')) {
            return 'Tag `' . substr($ref, strlen('refs/tags/')) . "` poussé sur **{$repository}**{$by}";
        }

        return 'Push sur **' . $repository . '** branche `'
            . substr($ref, strlen('refs/heads/')) . "`{$by}";
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
    private function getRepository($params): string
    {
        if (isset($params['repository']) && isset($params['repository']['html_url'])) {
            $repoUrl = $params['repository']['html_url'];
            return substr($repoUrl, -1) == '/' ? substr($repoUrl, 0, -1) : $repoUrl;
        }
        throw new Exception("Bad hook format.");
    }
}
