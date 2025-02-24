<?php

namespace YesWikiRepo;

use Exception;

// Polyfills for php<8
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        return $needle !== '' && substr($haystack, -strlen($needle)) === (string)$needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

class Repository
{
    public $localConf;
    public $repoConf = null;
    public $actualState = null;
    public $packages;

    private $packageBuilder = null;
    /**
     * @param mixed $configFile
     */
    public function __construct($configFile)
    {
        $this->packages = array();
        $this->localConf = $configFile;
        if (!is_dir($this->localConf['repo-path'])) {
            mkdir($this->localConf['repo-path'], 0755, true);
        }
    }

    public function load(): void
    {
        $this->loadRepoConf();
        $this->loadLocalState();
    }

    public function purge(): string
    {
        $message = 'Purging repository '.$this->localConf['repo-path'];
        (new File($this->localConf['repo-path']))->delete();
        mkdir($this->localConf['repo-path'], 0755, true);
        $message .= "\n".'Repository '.$this->localConf['repo-path'].' successfully purged';
        syslog(LOG_INFO, $message);
        return $message;
    }
    /**
     * @param mixed $packageNameToFind
     */
    public function build($packageNameToFind = null): ?string
    {

        var_dump($this->actualState);

        syslog(LOG_INFO, "Initialising repository.");

        foreach ($this->repoConf as $subRepoName => $packages) {
            mkdir($this->localConf['repo-path'] . $subRepoName, 0755, true);
            $this->actualState[$subRepoName] = new JsonFile(
                $this->localConf['repo-path'] . $subRepoName . '/packages.json'
            );
            foreach ($packages as $packageName => $package) {
                $infos = $this->buildPackage(
                    $this->getGitFolder($package),
                    $this->localConf['repo-path'] . $subRepoName . '/',
                    $packageName,
                    $package
                );
                if ($infos !== false) {
                    $this->actualState[$subRepoName][$packageName] = $infos;
                }
            }
            // Créé le fichier d'index.
            $this->actualState[$subRepoName]->write();
        }
        if (empty($this->actualState)) {
            throw new Exception("Can't update empty repository", 1);
        }

        // Check if package exist in configuration
        foreach ($this->repoConf as $subRepoName => $packages) {
            if (empty($this->actualState[$subRepoName])) {
                mkdir($this->localConf['repo-path'] . $subRepoName, 0755, true);
                $this->actualState[$subRepoName] = new JsonFile(
                    $this->localConf['repo-path'] . $subRepoName . '/packages.json'
                );
            }
            foreach ($packages as $packageName => $packageInfos) {
                if (
                    $packageName === $packageNameToFind
                    or empty($packageNameToFind)
                ) {
                    $this->updatePackage($packageName, $packageInfos, $subRepoName);
                }
            }
            if (!file_exists($this->localConf['repo-path'] . $subRepoName . '/packages.json')) {
                // Créé le fichier d'index.
                $this->actualState[$subRepoName]->write();
            }
        }
    }
    /**
     * @param mixed $repositoryUrl
     * @param mixed $branch
     */
    public function updateHook($repositoryUrl, $branch): void
    {
        if (empty($this->actualState)) {
            throw new Exception("Can't update empty repository", 1);
        }

        foreach ($this->repoConf as $subRepoName => $packages) {
            foreach ($packages as $packageName => $packageInfos) {
                $waitedRepoUrl = (substr($packageInfos['repository'], -1) == "/")
                    ? substr($packageInfos['repository'], 0, -1)
                    : $packageInfos['repository'];
                if (
                    $waitedRepoUrl === $repositoryUrl
                    and $packageInfos['branch'] === $branch
                ) {
                    $this->updatePackage($packageName, $packageInfos, $subRepoName);
                }
            }
            if (!file_exists($this->localConf['repo-path'] . $subRepoName . '/packages.json')) {
                // Créé le fichier d'index.
                $this->actualState[$subRepoName]->write();
            }
        }
    }
    /**
     * @param mixed $packageName
     * @param mixed $packageInfos
     * @param mixed $subRepoName
     */
    private function updatePackage($packageName, $packageInfos, $subRepoName): void
    {
        $updatedPackageInfo = isset($this->actualState[$subRepoName]) && isset($this->actualState[$subRepoName][$packageName])
            ? $this->actualState[$subRepoName][$packageName]
            : [];
        if (!empty($packageInfos['tag'])) {
            $updatedPackageInfo['tag'] = $packageInfos['tag'];
            if (isset($updatedPackageInfo['branch'])) {
                unset($updatedPackageInfo['branch']);
            }
        } elseif ($packageInfos['branch']) {
            $updatedPackageInfo['branch'] = $packageInfos['branch'];
            if (isset($updatedPackageInfo['tag'])) {
                unset($updatedPackageInfo['tag']);
            }
        }
        $infos = $this->buildPackage(
            $this->getGitFolder($packageInfos),
            $this->localConf['repo-path'] . $subRepoName . '/',
            $packageName,
            $updatedPackageInfo
        );
        if ($infos !== false) {
            // Au cas ou cela aurait été mis a jour
            $infos['description'] =
                $this->repoConf[$subRepoName][$packageName]['description'];
            $infos['documentation'] =
                $this->repoConf[$subRepoName][$packageName]['documentation'];
            $this->actualState[$subRepoName][$packageName] = $infos;
            $this->actualState[$subRepoName]->write();
        }
    }

    private function loadRepoConf(): void
    {
        $repoConf = new JsonFile($this->localConf['config-address']);
        $repoConf->read();
        foreach ($repoConf as $subRepoName => $subRepoContent) {
            $this->repoConf[$subRepoName] = new JsonFile(
                $this->localConf['repo-path'] . $subRepoName . '/packages.json'
            );
            $packageName = 'yeswiki-' . $subRepoName;
            $rep = explode('/archive', $subRepoContent['repository']);
            $subRepoContent['repository'] = $rep[0];
            $this->repoConf[$subRepoName][$packageName] = array(
                'repository' => $subRepoContent['repository'],
                'branch' => empty($subRepoContent['branch']) ? '' : $subRepoContent['branch'],
                'tag' => empty($subRepoContent['tag']) ? '' : $subRepoContent['tag'],
                'documentation' => $subRepoContent['documentation'],
                'description' => $subRepoContent['description'],
            );

            foreach (($subRepoContent['extensions'] ?? []) as $extName => $extInfos) {
                $packageName = 'extension-' . $extName;
                $this->repoConf[$subRepoName][$packageName] = array(
                    'repository' => $extInfos['repository'],
                    'branch' => empty($extInfos['branch']) ? '' : $extInfos['branch'],
                    'tag' => empty($extInfos['tag']) ? '' : $extInfos['tag'],
                    'documentation' => $extInfos['documentation'],
                    'description' => $extInfos['description'],
                );
            }
            foreach (($subRepoContent['themes'] ?? []) as $themeName => $themeInfos) {
                $packageName = 'theme-' . $themeName;
                $this->repoConf[$subRepoName][$packageName] = array(
                    'repository' => $themeInfos['repository'],
                    'branch' => empty($themeInfos['branch']) ? '' : $themeInfos['branch'],
                    'tag' => empty($themeInfos['tag']) ? '' : $themeInfos['tag'],
                    'documentation' => $themeInfos['documentation'],
                    'description' => $themeInfos['description'],
                );
            }
        }
    }

    private function loadLocalState(): void
    {
        $dirlist = new \RecursiveDirectoryIterator(
            $this->localConf['repo-path'],
            \RecursiveDirectoryIterator::SKIP_DOTS
        );
        $filelist = new \RecursiveIteratorIterator($dirlist);
        $this->actualState = array();
        foreach ($filelist as $file) {
            if (basename($file) === 'packages.json') {
                $subRepoName = basename(dirname($file));
                $this->actualState[$subRepoName] = new JsonFile($file);
                $this->actualState[$subRepoName]->read();
            }
        }
    }
    /**
     * @param mixed $srcFile
     * @param mixed $destDir
     * @param mixed $packageName
     * @param mixed $packageInfos
     */
    private function buildPackage($srcFile, $destDir, $packageName, $packageInfos)
    {
        syslog(LOG_INFO, "Building $packageName...");
        if ($this->packageBuilder === null) {
            if (!empty($this->localConf['home-dir'])) {
                putenv("HOME={$this->localConf['home-dir']}");
            }
            $this->packageBuilder = new PackageBuilder(
                $this->localConf['composer-bin']
            );
        }
        try {
            $infos = $this->packageBuilder->build(
                $srcFile,
                $destDir,
                $packageName,
                array_merge($packageInfos, (isset($packageInfos['tag']) && $packageInfos['tag'] == "latest") ? ['tag' => $this->getLatestTag($srcFile)] : [])
            );
        } catch (Exception $e) {
            syslog(
                LOG_ERR,
                "Failed building $packageName : " . $e->getMessage()
            );
            return false;
        }
        syslog(LOG_INFO, "$packageName has been built...");
        return $infos;
    }
    /**
     * @param mixed $pkgInfos
     */
    public function getGitFolder($pkgInfos)
    {
        if (!empty($pkgInfos['tag'])) {
            if ($pkgInfos['tag'] == 'latest') {
                $localBranchOrTagName = " --detach \$({$this->getLatestTagScript()})";
            } else {
                $localBranchOrTagName = $pkgInfos['tag'];
            }
        } else {
            $version = "origin/{$pkgInfos['branch']}";
            $localBranchOrTagName = $pkgInfos['branch'];
        }

        $destDir = getcwd() . '/packages-src/' . basename($pkgInfos['repository']);
        if (!is_dir($destDir)) {
            echo exec('git clone ' . $pkgInfos['repository'] . ' ' . $destDir . "\n");
        } else {
            echo exec("cd $destDir; git remote set-url origin {$pkgInfos['repository']}") . "\n";
        }
        echo exec("cd $destDir; git fetch --all --tags -f --prune") . "\n";
        echo exec("cd $destDir; git reset --hard") . "\n"; // remove current changes before checkout
        echo exec("cd $destDir; git checkout {$localBranchOrTagName}") . "\n";
        if (isset($version)) {
            echo exec("cd $destDir; git reset --hard $version") . "\n";
        }
        return $destDir;
    }
    /**
     * @param mixed $destDir
     */
    private function getLatestTag($destDir)
    {
        try {
            $result =  exec("cd $destDir; {$this->getLatestTagScript()}\n");
            if (preg_match('/^(v?\d+\.\d+(?:-|\.)\d+)-.*$/', $result, $match)) {
                return str_replace('-', '.', $match[1]);
            }
            return $result;
        } catch (\Throwable $th) {
            return '';
        }
    }

    private function getLatestTagScript(): string
    {
        return "git describe --tags --long `git rev-list --tags --max-count=1`";
    }
}
