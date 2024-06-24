<?php

namespace YesWikiRepo;

use \Exception;

class HttpRequest
{
    private $server;
    private $post;

    public function __construct($server, $post)
    {
        $this->server = $server;
        $this->post = $post;
    }

    public function isHook()
    {
        if ((
                isset($this->server['HTTP_CONTENT_TYPE'])
                or isset($this->server['CONTENT_TYPE'])
            )
            and isset($this->server['HTTP_X_GITHUB_EVENT'])
        ) {
            return true;
        }
        return false;
    }

    public function getContent()
    {
        $contentType = $this->getContentType();

        if ($contentType === 'application/json') {
            return json_decode(file_get_contents('php://input'), true);
        }


        if ($contentType === 'application/x-www-form-urlencoded') {
            // smee.io workaround (where the post data is a json)
            if (count($this->post) === 1) {
                $this->post = json_decode(file_get_contents('php://input'), true);
            }
            $payload = json_decode($this->post['payload'], true);
            return $payload;
        }

        throw new \Exception("Unsupported content type: $contentType");
    }

    private function getContentType()
    {
        if (isset($this->server['HTTP_CONTENT_TYPE'])) {
            return $this->server['HTTP_CONTENT_TYPE'];
        }
        if (isset($this->server['CONTENT_TYPE'])) {
            return $this->server['CONTENT_TYPE'];
        }
        throw new \Exception("Content type not defined.", 1);
    }
}
