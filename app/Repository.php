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
    /** @var array<string, array> the `binary` block of each channel that ships an executable */
    public $binaryConf = [];

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
    public function build($packageNameToFind = null): array
    {
        syslog(LOG_INFO, "Building repository {$this->localConf['repo-path']}");
        $results = [];

        foreach ($this->repoConf as $subRepoName => $packages) {
            if (empty($this->actualState[$subRepoName])) {
                mkdir($this->localConf['repo-path'] . '/' . $subRepoName, 0755, true);
                $this->actualState[$subRepoName] = new JsonFile(
                    $this->localConf['repo-path'] . '/' . $subRepoName . '/packages.json'
                );
            }
            foreach ($packages as $packageName => $packageInfos) {
                if (
                    $packageName === $packageNameToFind
                    or empty($packageNameToFind)
                ) {
                    $results[] = $this->updatePackage($packageName, $packageInfos, $subRepoName);
                }
            }
            if (!file_exists($this->localConf['repo-path'] . '/' . $subRepoName . '/packages.json')) {
                // Créé le fichier d'index.
                $this->actualState[$subRepoName]->write();
            }
            if (empty($packageNameToFind) || $packageNameToFind === 'binary') {
                $results = array_merge($results, $this->publishBinaries($subRepoName));
            }
        }

        return $results;
    }

    /**
     * Publish the binary of the one channel a hook concerns, and leave the other channels alone.
     *
     * A release is published on one branch, so it says nothing about a channel following another.
     */
    private function publishBinariesOf(string $subRepoName, string $repositoryUrl): array
    {
        $core = $this->repoConf[$subRepoName]['yeswiki-' . $subRepoName] ?? null;
        if (empty($core) || rtrim($core['repository'], '/') !== $repositoryUrl) {
            return [];
        }

        return $this->publishBinaries($subRepoName);
    }

    /**
     * Publish the self-contained binary for a channel that declares one (single-binary 05).
     *
     * A channel with no `binary` block publishes none, which is every channel that predates the
     * binary. Nothing here fails a build: a repository that could not reach GitHub should still
     * serve the extensions and themes it already has.
     */
    private function publishBinaries(string $subRepoName): array
    {
        if (empty($this->binaryConf[$subRepoName])) {
            return [];
        }

        return [(new BinaryPublisher($this->localConf))->publish($subRepoName, $this->binaryConf[$subRepoName])];
    }
    /**
     * @param mixed $repositoryUrl
     * @param mixed $branch
     */
    public function updateHook($repositoryUrl, $branch): array
    {
        if (empty($this->actualState)) {
            throw new Exception("Can't update empty repository", 1);
        }

        $results = [];
        foreach ($this->repoConf as $subRepoName => $packages) {
            foreach ($packages as $packageName => $packageInfos) {
                $waitedRepoUrl = (substr($packageInfos['repository'], -1) == "/")
                    ? substr($packageInfos['repository'], 0, -1)
                    : $packageInfos['repository'];
                if (
                    $waitedRepoUrl === $repositoryUrl
                    and $packageInfos['branch'] === $branch
                ) {
                    $results[] = $this->updatePackage($packageName, $packageInfos, $subRepoName);
                }
            }
            if (!file_exists($this->localConf['repo-path']. '/' . $subRepoName . '/packages.json')) {
                // Créé le fichier d'index.
                $this->actualState[$subRepoName]->write();
            }
        }

        return $results;
    }

    /**
     * Rebuild what a pushed tag actually changed.
     *
     * A tag name says nothing about the branch it sits on, and `tag: latest` used to mean "the
     * newest tag anywhere in the repository". Pushing v5.0.0-alpha1 on ectoplasme therefore
     * rebuilt the doryphore channel with 5.x code. A channel that names a `tag-branch` is now
     * rebuilt only when the pushed tag is the latest one reachable from that branch.
     */
    public function updateHookForLatestTag($repositoryUrl, string $pushedTag = ''): array
    {
        if (empty($this->actualState)) {
            throw new Exception("Can't update empty repository", 1);
        }

        $results = [];
        foreach ($this->repoConf as $subRepoName => $packages) {
            foreach ($packages as $packageName => $packageInfos) {
                $waitedRepoUrl = (substr($packageInfos['repository'], -1) == "/")
                    ? substr($packageInfos['repository'], 0, -1)
                    : $packageInfos['repository'];
                if (
                    $waitedRepoUrl === $repositoryUrl
                    && !empty($packageInfos['tag'])
                    && $packageInfos['tag'] === 'latest'
                    && $this->tagFeedsPackage($packageInfos, $pushedTag)
                ) {
                    $results[] = $this->updatePackage($packageName, $packageInfos, $subRepoName);
                }
            }
            if (!file_exists($this->localConf['repo-path'] . '/' . $subRepoName . '/packages.json')) {
                $this->actualState[$subRepoName]->write();
            }
            $results = array_merge($results, $this->publishBinariesOf($subRepoName, $repositoryUrl));
        }

        return $results;
    }

    /**
     * Does this tag belong to the branch its channel takes tags from?
     *
     * A channel naming no `tag-branch` keeps the old answer -- yes -- because that is what every
     * channel written before this one meant. A channel naming one is rebuilt only when the pushed
     * tag is the latest reachable from that branch: a tag pushed on another branch leaves it
     * alone, and an old tag re-pushed would only rebuild the version it already serves.
     */
    private function tagFeedsPackage(array $packageInfos, string $pushedTag): bool
    {
        $tagBranch = $packageInfos['tag-branch'] ?? '';
        if (empty($tagBranch) || empty($pushedTag)) {
            return true;
        }

        $latest = $this->getLatestTag($this->fetchRepository($packageInfos['repository']), $tagBranch);
        if ($latest === '' || ltrim($latest, 'v') !== ltrim($pushedTag, 'v')) {
            syslog(LOG_INFO, "Tag {$pushedTag} is not the latest tag of {$tagBranch} ({$latest}), nothing to rebuild");
            return false;
        }

        return true;
    }

    /**
     * @param mixed $packageName
     * @param mixed $packageInfos
     * @param mixed $subRepoName
     */
    private function updatePackage($packageName, $packageInfos, $subRepoName): array
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

        $buildResult = $this->buildPackage(
            $this->getGitFolder($packageInfos),
            $this->localConf['repo-path'] . '/' . $subRepoName . '/',
            $packageName,
            $updatedPackageInfo,
            $packageInfos['tag-branch'] ?? ''
        );

        if ($buildResult['infos'] !== false) {
            $buildResult['infos']['description'] =
                $this->repoConf[$subRepoName][$packageName]['description'];
            $buildResult['infos']['documentation'] =
                $this->repoConf[$subRepoName][$packageName]['documentation'];
            $this->actualState[$subRepoName][$packageName] = $buildResult['infos'];
            $this->actualState[$subRepoName]->write();
        }

        if (str_starts_with($packageName, 'yeswiki-')) {
            $type = 'core yeswiki';
            $shortName = substr($packageName, strlen('yeswiki-'));
        } elseif (str_starts_with($packageName, 'extension-')) {
            $type = 'extension';
            $shortName = substr($packageName, strlen('extension-'));
        } elseif (str_starts_with($packageName, 'theme-')) {
            $type = 'theme';
            $shortName = substr($packageName, strlen('theme-'));
        } else {
            $type = 'package';
            $shortName = $packageName;
        }

        return [
            'packageName' => $packageName,
            'type'        => $type,
            'shortName'   => $shortName,
            'version'     => $buildResult['version'],
            'branch'      => $packageInfos['branch'] ?? null,
            'tag'         => $packageInfos['tag'] ?? null,
            'success'     => $buildResult['success'],
            'error'       => $buildResult['error'],
            'elapsed'     => $buildResult['elapsed'],
            'log'         => $buildResult['log'],
        ];
    }

    private function loadRepoConf(): void
    {
        $repoConf = new JsonFile($this->localConf['config-address']);
        $repoConf->read();
        foreach ($repoConf as $subRepoName => $subRepoContent) {
            $this->repoConf[$subRepoName] = new JsonFile(
                $this->localConf['repo-path'] . '/' . $subRepoName . '/packages.json'
            );
            if (!empty($subRepoContent['binary'])) {
                $this->binaryConf[$subRepoName] = $subRepoContent['binary'];
            }
            $packageName = 'yeswiki-' . $subRepoName;
            $rep = explode('/archive', $subRepoContent['repository']);
            $subRepoContent['repository'] = $rep[0];
            $this->repoConf[$subRepoName][$packageName] = array(
                'repository' => $subRepoContent['repository'],
                'branch' => empty($subRepoContent['branch']) ? '' : $subRepoContent['branch'],
                'tag' => empty($subRepoContent['tag']) ? '' : $subRepoContent['tag'],
                'tag-branch' => empty($subRepoContent['tag-branch']) ? '' : $subRepoContent['tag-branch'],
                'documentation' => $subRepoContent['documentation'],
                'description' => $subRepoContent['description'],
            );

            foreach (($subRepoContent['extensions'] ?? []) as $extName => $extInfos) {
                $packageName = 'extension-' . $extName;
                $this->repoConf[$subRepoName][$packageName] = array(
                    'repository' => $extInfos['repository'],
                    'branch' => empty($extInfos['branch']) ? '' : $extInfos['branch'],
                    'tag' => empty($extInfos['tag']) ? '' : $extInfos['tag'],
                    'tag-branch' => empty($extInfos['tag-branch']) ? '' : $extInfos['tag-branch'],
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
                    'tag-branch' => empty($themeInfos['tag-branch']) ? '' : $themeInfos['tag-branch'],
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
    private function buildPackage($srcFile, $destDir, $packageName, $packageInfos, string $tagBranch = ''): array
    {
        $log = [];
        $time_start = microtime(true);
        $tag = (isset($packageInfos['tag']) && $packageInfos['tag'] == "latest") ? ['tag' => $this->getLatestTag($srcFile, $tagBranch)] : [];
        $version = !empty($tag['tag']) ? $tag['tag'] : ($packageInfos['branch'] ?? '');

        $separator = "----------------------------------------------------------";
        syslog(LOG_INFO, $separator);
        $log[] = $separator;
        $buildMsg = "Building $packageName version $version";
        syslog(LOG_INFO, $buildMsg);
        $log[] = $buildMsg;

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
                array_merge($packageInfos, $tag)
            );
            $time_end = microtime(true);
            $elapsed = round($time_end - $time_start, 2);
            $successMsg = "$packageName has been built in {$elapsed} seconds";
            syslog(LOG_INFO, $successMsg);
            $log[] = $successMsg;
            return ['infos' => $infos, 'log' => $log, 'version' => $version, 'elapsed' => $elapsed, 'success' => true, 'error' => null];
        } catch (Exception $e) {
            $elapsed = round(microtime(true) - $time_start, 2);
            $errMsg = "Failed building $packageName : " . $e->getMessage();
            syslog(LOG_ERR, $errMsg);
            $log[] = $errMsg;
            return ['infos' => false, 'log' => $log, 'version' => $version, 'elapsed' => $elapsed, 'success' => false, 'error' => $e->getMessage()];
        }
    }
    /**
     * @param mixed $pkgInfos
     */
    public function getGitFolder($pkgInfos)
    {
        if (!empty($pkgInfos['tag'])) {
            if ($pkgInfos['tag'] == 'latest') {
                $script = $this->getLatestTagScript($pkgInfos['tag-branch'] ?? '');
                $localBranchOrTagName = " --detach \$({$script})";
            } else {
                $localBranchOrTagName = $pkgInfos['tag'];
            }
        } else {
            $version = "origin/{$pkgInfos['branch']}";
            $localBranchOrTagName = $pkgInfos['branch'];
        }

        $destDir = $this->fetchRepository($pkgInfos['repository']);
        exec("cd $destDir; git reset --hard --quiet"); // remove current changes before checkout
        exec("cd $destDir; git checkout {$localBranchOrTagName} --quiet");
        if (isset($version)) {
            exec("cd $destDir; git reset --hard $version --quiet");
        }
        return $destDir;
    }
    /**
     * Clone the repository if it is missing, then bring branches and tags up to date.
     */
    private function fetchRepository($repository): string
    {
        $destDir = getcwd() . '/packages-src/' . basename($repository);
        if (!is_dir($destDir)) {
            exec('git clone ' . $repository . ' ' . $destDir);
        } else {
            exec("cd $destDir; git remote set-url origin {$repository} > /dev/null 2>&1");
        }
        exec("cd $destDir; git fetch --all --tags -f --prune --quiet");
        return $destDir;
    }
    /**
     * @param mixed $destDir
     */
    private function getLatestTag($destDir, string $tagBranch = '')
    {
        try {
            $result =  exec("cd $destDir; {$this->getLatestTagScript($tagBranch)}\n");
            if (!empty($tagBranch)) {
                // already an exact tag name rather than a describe suffix, and a pre-release tag
                // such as v5.0.0-alpha1 must keep the part the normalisation below would cut
                return $result;
            }
            if (preg_match('/^(v?\d+\.\d+(?:-|\.)\d+)-.*$/', $result, $match)) {
                return str_replace('-', '.', $match[1]);
            }
            return $result;
        } catch (\Throwable $th) {
            return '';
        }
    }

    /**
     * The newest tag of a branch, or -- for a channel naming no branch -- the newest tag anywhere.
     *
     * The second answer is the one that shipped 5.x code to the doryphore channel, so a channel
     * that cares about which branch its tags come from names a `tag-branch` and gets the first.
     */
    private function getLatestTagScript(string $tagBranch = ''): string
    {
        if (empty($tagBranch)) {
            return "git describe --tags --long `git rev-list --tags --max-count=1`";
        }

        return 'git describe --tags --abbrev=0 ' . escapeshellarg('origin/' . $tagBranch);
    }
}
