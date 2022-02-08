<?php
namespace YesWikiRepo;

use \Files\File;
use \Exception;

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
    private $doYnhUpdate = false;

    public function __construct($configFile)
    {
        $this->packages = array();
        $this->localConf = $configFile;
    }

    public function load()
    {
        $this->loadRepoConf();
        $this->loadLocalState();
    }

    public function init()
    {
        if (!empty($this->actualState)) {
            throw new Exception("Can't init unempty repository", 1);
        }

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
    }

    public function purge()
    {
        syslog(LOG_INFO, "Purging repository.");
        (new File($this->localConf['repo-path']))->delete();
        mkdir($this->localConf['repo-path'], 0755, true);
    }

    public function update($packageNameToFind = '')
    {
        if (empty($this->actualState)) {
            throw new Exception("Can't update empty repository", 1);
        }

        // Check if package exist in configuration
        foreach ($this->repoConf as $subRepoName => $packages) {
            foreach ($packages as $packageName => $packageInfos) {
                if (
                    $packageName === $packageNameToFind
                    or empty($packageNameToFind)
                ) {
                    $this->updatePackage($packageName, $packageInfos, $subRepoName);
                }
            }
        }

        $this->updateYunoHost();
    }

    public function updateHook($repositoryUrl, $branch)
    {
        if (empty($this->actualState)) {
            throw new Exception("Can't update empty repository", 1);
        }

        foreach ($this->repoConf as $subRepoName => $packages) {
            foreach ($packages as $packageName => $packageInfos) {
                if ($packageInfos['repository'] === $repositoryUrl
                    and $packageInfos['branch'] === $branch
                ) {
                    $this->updatePackage($packageName, $packageInfos, $subRepoName);
                }
            }
        }

        $this->updateYunoHost();
    }

    private function updatePackage($packageName, $packageInfos, $subRepoName)
    {
        $infos = $this->buildPackage(
            $this->getGitFolder($packageInfos),
            $this->localConf['repo-path'] . $subRepoName . '/',
            $packageName,
            $this->actualState[$subRepoName][$packageName]
        );
        if ($infos !== false) {
            // Au cas ou cela aurait été mis a jour
            $infos['description'] =
                $this->repoConf[$subRepoName][$packageName]['description'];
            $infos['documentation'] =
                $this->repoConf[$subRepoName][$packageName]['documentation'];
            $this->actualState[$subRepoName][$packageName] = $infos;
            $this->actualState[$subRepoName]->write();

            if (
                str_starts_with($packageName, 'yeswiki')
                || str_ends_with($packageName, 'loginldap')
            ) {
                $this->doYnhUpdate = true;
            }
        }
    }

    private function updateYunoHost()
    {
        if ($this->doYnhUpdate) {
            $ynh = new YunoHost($this);
            $ynh->update();
        }
    }

    private function loadRepoConf()
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

            foreach ($subRepoContent['extensions'] as $extName => $extInfos) {
                $packageName = 'extension-' . $extName;
                $this->repoConf[$subRepoName][$packageName] = array(
                    'repository' => $extInfos['repository'],
                    'branch' => empty($extInfos['branch']) ? '' : $extInfos['branch'],
                    'tag' => empty($extInfos['tag']) ? '' : $extInfos['tag'],
                    'documentation' => $extInfos['documentation'],
                    'description' => $extInfos['description'],
                );
            }
            foreach ($subRepoContent['themes'] as $themeName => $themeInfos) {
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

    private function loadLocalState()
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

    private function buildPackage($srcFile, $destDir, $packageName, $packageInfos)
    {
        syslog(LOG_INFO, "Building $packageName...");
        if ($this->packageBuilder === null) {
            $this->packageBuilder = new PackageBuilder(
                $this->localConf['composer-bin']
            );
        }
        try {
            $infos = $this->packageBuilder->build(
                $srcFile,
                $destDir,
                $packageName,
                $packageInfos
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

    public function getGitFolder($pkgInfos)
    {
        $destDir = getcwd().'/packages-src/'.basename($pkgInfos['repository']);
        $version = empty($pkgInfos['tag']) ? 'origin/'.$pkgInfos['branch'] : 'tags/'.$pkgInfos['tag'];
        if (!is_dir($destDir)) {
            echo exec('git clone '.$pkgInfos['repository'].' '.$destDir."\n");
        } else {
            echo exec("cd $destDir; git remote set-url origin {$pkgInfos['repository']}")."\n";
        }
        echo exec('cd '.$destDir.'; git fetch --all --tags -f --prune')."\n";
        echo exec('cd '.$destDir.'; git reset --hard '.$version)."\n";
        return $destDir;
    }
}
