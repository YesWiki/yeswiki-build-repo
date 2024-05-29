<?php

namespace YesWikiRepo;

class File
{
    protected $path;

    public function __construct($path)
    {
        if (!file_exists($path)) {
            throw new \Exception("Error Processing Request", 1);
        }
        $this->path = $path;
    }

    public function __toString()
    {
        return $this->path;
    }

    /**
     * Copy this file to destination
     * @param  [type] $destination [description]
     * @return [type]          [description]
     */
    public function copy($dest)
    {

        // La source n'existe pas
        if (!file_exists($this->path)) {
            throw new \Exception("Source don't exist : " . $this->path, 1);
        }

        // La destination ne doit pas exister.
        if (file_exists($dest)) {
            throw new \Exception("Destination already exist : $dest", 1);
        }

        if (is_file($this->path)) {
            // Copie d'un fichier dans un dossier.
            if (is_dir($dest)) {
                $dest = $this->addSlash($dest) . basename($this->path);
            }
            copy($this->path, $dest);
            return;
        }

        $dest = $this->addSlash($dest);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->path,
                \RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $path => $file) {
            $fileDest = $dest . $iterator->getSubPathName();
            if (!file_exists(dirname($fileDest))) {
                mkdir(dirname($fileDest), 0777, true);
            }
            copy($path, $fileDest);
        }
    }

    /**
     * Delete file or folder
     */
    public function delete()
    {
        if (!file_exists($this->path)) {
            throw new \Exception(
                "Can't delete something unexisting : " . $this->path,
                1
            );
        }

        if (is_dir($this->path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $this->path,
                    \RecursiveDirectoryIterator::SKIP_DOTS
                ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                    continue;
                }
                unlink($file->getPathname());
            }
            rmdir($this->path);
            return;
        }

        unlink($this->path);
    }

    /**
     * Move file or folder
     * @param  string $destination path where to copy files
     */
    public function move($dest)
    {
        $this->copy($dest);
        $this->delete();
        $this->path = $dest;
    }

    /**
     * Recursively evaluate file/directory disk usage
     * @return integer size in Bytes
     */
    public function diskUsage()
    {
        if (is_dir($this->path)) {
            $diskUsage = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $this->path,
                    \RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                $diskUsage += $file->getSize();
            }
            return $diskUsage;
        }

        return filesize($this->path);
    }

    /**
     * Add directory sperator at end of string if not.
     * @param string $path
     */
    private function addSlash($path)
    {
        if (substr($path, -1) !== DIRECTORY_SEPARATOR) {
            $path .= DIRECTORY_SEPARATOR;
        }
        return $path;
    }
}
