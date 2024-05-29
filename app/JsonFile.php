<?php

namespace YesWikiRepo;

use \Exception;

class JsonFile extends Collection
{
    private $file;

    public function __construct($file)
    {
        parent::__construct();
        $this->file = $file;
    }

    public function read()
    {
        // test if the file is on a url or not
        if (filter_var($this->file, FILTER_VALIDATE_URL) === false) {
            // local file
            $indexFileContent = file_get_contents($this->file);
        } else {
            // url
            $curlSession = curl_init();
            curl_setopt($curlSession, CURLOPT_URL, $this->file);
            curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, true);
            $indexFileContent = curl_exec($curlSession);
            curl_close($curlSession);
        }

        if ($indexFileContent === false) {
            throw new Exception("Error loading json file : " . $this->file, 1);
        }
        $this->elements = json_decode($indexFileContent, true);
        if ($this->elements === null) {
            throw new Exception("Error Processing json file" . $this->file, 1);
        }
    }

    public function write()
    {
        $fileContent = json_encode($this->elements, JSON_PRETTY_PRINT);
        if (file_put_contents($this->file, $fileContent) === false) {
            throw new Exception("Error writing json file : " . $this->file, 1);
        }
    }

    public function fileExist()
    {
        if (is_file($this->file)) {
            return true;
        }
        return false;
    }
}
