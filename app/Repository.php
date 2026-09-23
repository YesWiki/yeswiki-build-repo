<?php

namespace YesWikiRepo;

use Exception;

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
    /** The YesWiki releases, in the order that gives each one its major version. */
    const RELEASES = ['anacoluthe', 'bachibouzouk', 'cercopitheque', 'doryphore', 'ectoplasme', 'flibustier'];

    public $localConf;
    public $repoConf = null;
    public $actualState = null;
    public $packages;
    /** @var array<string, array> the `binary` block of each channel that ships an executable */
    public $binaryConf = [];

    private $packageBuilder = null;
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
    public function build($packageNameToFind = null): array
    {
        syslog(LOG_INFO, "Building repository {$this->localConf['repo-path']}");
        $results = [];

        foreach ($this->repoConf as $subRepoName => $packages) {
            $this->openChannelState($subRepoName);
            foreach ($packages as $packageName => $packageInfos) {
                if (
                    $packageName === $packageNameToFind
                    or empty($packageNameToFind)
                ) {
                    $results[] = $this->updatePackage($packageName, $packageInfos, $subRepoName);
                }
            }
            if (!file_exists($this->localConf['repo-path'] . '/' . $subRepoName . '/packages.json')) {
                $this->actualState[$subRepoName]->write();
            }
            if (empty($packageNameToFind) || $packageNameToFind === 'binary') {
                $results = array_merge($results, $this->publishBinaries($subRepoName));
            }
        }

        return $results;
    }

    /** Open the state file of a channel, creating its directory when nothing has been built there. */
    private function openChannelState(string $subRepoName): void
    {
        if (!empty($this->actualState[$subRepoName])) {
            return;
        }

        $directory = $this->localConf['repo-path'] . '/' . $subRepoName;
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $this->actualState[$subRepoName] = new JsonFile($directory . '/packages.json');
    }

    /** Publish the binary of the one channel a hook concerns, and leave the other channels alone. */
    private function publishBinariesOf(string $subRepoName, string $repositoryUrl): array
    {
        $core = $this->repoConf[$subRepoName]['yeswiki-' . $subRepoName] ?? null;
        if (empty($core) || rtrim($core['repository'], '/') !== $repositoryUrl) {
            return [];
        }

        return $this->publishBinaries($subRepoName);
    }

    /** Publish the self-contained binary of a channel declaring one, without ever failing the build. */
    private function publishBinaries(string $subRepoName): array
    {
        if (empty($this->binaryConf[$subRepoName])) {
            return [];
        }

        return [(new BinaryPublisher($this->localConf))->publish($subRepoName, $this->binaryConf[$subRepoName])];
    }
    public function updateHook($repositoryUrl, $branch): array
    {
        if (empty($this->actualState)) {
            throw new Exception("Can't update empty repository", 1);
        }

        $results = [];
        foreach ($this->repoConf as $subRepoName => $packages) {
            $this->openChannelState($subRepoName);
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
                $this->actualState[$subRepoName]->write();
            }
        }

        return $results;
    }

    /** Rebuild the channels a pushed tag actually belongs to. */
    public function updateHookForLatestTag($repositoryUrl, string $pushedTag = ''): array
    {
        if (empty($this->actualState)) {
            throw new Exception("Can't update empty repository", 1);
        }

        $results = [];
        foreach ($this->repoConf as $subRepoName => $packages) {
            $this->openChannelState($subRepoName);
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

    /** Does this tag belong to the branch and the version series its channel takes tags from? */
    private function tagFeedsPackage(array $packageInfos, string $pushedTag): bool
    {
        $versionPrefix = $packageInfos['version-prefix'] ?? '';
        if (!empty($versionPrefix) && !empty($pushedTag) && !$this->versionMatchesPrefix($pushedTag, $versionPrefix)) {
            syslog(LOG_INFO, "Tag {$pushedTag} is not of the {$versionPrefix} series, nothing to rebuild");
            return false;
        }

        $tagBranch = $packageInfos['tag-branch'] ?? '';
        if (empty($tagBranch) || empty($pushedTag)) {
            return true;
        }

        $latest = (string) $this->getLatestTag($this->fetchRepository($packageInfos['repository']), $tagBranch, $versionPrefix);
        if (empty($latest) || ltrim($latest, 'v') !== ltrim($pushedTag, 'v')) {
            syslog(LOG_INFO, "Tag {$pushedTag} is not the latest tag of {$tagBranch} ({$latest}), nothing to rebuild");
            return false;
        }

        return true;
    }

    private function updatePackage($packageName, $packageInfos, $subRepoName): array
    {
        $tagToBuild = '';
        try {
            $tagToBuild = $this->resolveTag($packageInfos);
            $refusal = $this->refuseVersion($packageInfos, $tagToBuild);
            if ($refusal !== '') {
                return $this->unbuiltResult($packageName, $packageInfos, $subRepoName, $tagToBuild, $refusal, true);
            }
            $srcFile = $this->getGitFolder($packageInfos, $tagToBuild);
        } catch (Exception $exception) {
            return $this->unbuiltResult($packageName, $packageInfos, $subRepoName, $tagToBuild, $exception->getMessage(), false);
        }

        $updatedPackageInfo = isset($this->actualState[$subRepoName]) && isset($this->actualState[$subRepoName][$packageName])
            ? $this->actualState[$subRepoName][$packageName]
            : [];
        $previousVersion = $updatedPackageInfo['version'] ?? '';
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
            $srcFile,
            $this->localConf['repo-path'] . '/' . $subRepoName . '/',
            $packageName,
            $updatedPackageInfo,
            $tagToBuild
        );

        if ($buildResult['infos'] !== false) {
            $buildResult['infos']['description'] =
                $this->repoConf[$subRepoName][$packageName]['description'];
            $buildResult['infos']['documentation'] =
                $this->repoConf[$subRepoName][$packageName]['documentation'];
            $this->actualState[$subRepoName][$packageName] = $buildResult['infos'];
            $this->actualState[$subRepoName]->write();
        }

        return $this->describePackage($packageName, $subRepoName) + [
            'version'         => $buildResult['infos']['version'] ?? $buildResult['version'],
            'previousVersion' => $previousVersion,
            'url'             => $this->packageUrl($subRepoName, $buildResult['infos']),
            'branch'          => $packageInfos['branch'] ?? null,
            'tag'             => $packageInfos['tag'] ?? null,
            'success'         => $buildResult['success'],
            'skipped'         => false,
            'error'           => $buildResult['error'],
            'elapsed'         => $buildResult['elapsed'],
            'log'             => $buildResult['log'],
        ];
    }

    /** The address the built archive is served at, or an empty string when nothing was built. */
    private function packageUrl(string $subRepoName, $infos): string
    {
        if (empty($infos['file']) || empty($this->localConf['repo-url'])) {
            return '';
        }

        return rtrim($this->localConf['repo-url'], '/') . '/' . $subRepoName . '/' . $infos['file'];
    }

    /** The tag a package builds from, either the one it names or the newest one its channel accepts. */
    private function resolveTag(array $packageInfos): string
    {
        $tag = $packageInfos['tag'] ?? '';
        if (empty($tag)) {
            return '';
        }
        if ($tag !== 'latest') {
            return $tag;
        }

        return (string) $this->getLatestTag(
            $this->fetchRepository($packageInfos['repository']),
            $packageInfos['tag-branch'] ?? '',
            $packageInfos['version-prefix'] ?? ''
        );
    }

    /** Why this version may not be built here, or an empty string when it may. */
    private function refuseVersion(array $packageInfos, string $tag): string
    {
        if (empty($packageInfos['tag'])) {
            return '';
        }

        $versionPrefix = $packageInfos['version-prefix'] ?? '';
        if (empty($tag)) {
            return empty($versionPrefix)
                ? 'this repository carries no tag to build from'
                : "this repository carries no tag of the {$versionPrefix} series this channel distributes";
        }
        if (!empty($versionPrefix) && !$this->versionMatchesPrefix($tag, $versionPrefix)) {
            return "version {$tag} is not of the {$versionPrefix} series this channel distributes";
        }

        return '';
    }

    /** The major version a channel distributes, from its own `version-prefix` or from its release name. */
    private function versionPrefixOf(string $subRepoName, array $subRepoContent): string
    {
        if (isset($subRepoContent['version-prefix'])) {
            return (string) $subRepoContent['version-prefix'];
        }

        $rank = array_search(explode('-', $subRepoName)[0], self::RELEASES, true);

        return $rank === false ? '' : (string) ($rank + 1);
    }

    /** Does this version belong to the series a channel distributes? */
    private function versionMatchesPrefix(string $version, string $versionPrefix): bool
    {
        if ($versionPrefix === '') {
            return true;
        }

        $version = ltrim(trim($version), 'v');
        if (ctype_digit($versionPrefix)) {
            return (bool) preg_match('/^' . $versionPrefix . '(\D|$)/', $version);
        }

        return str_starts_with($version, $versionPrefix);
    }

    /** A result row for a package left unbuilt, reported beside the ones that were built. */
    private function unbuiltResult($packageName, array $packageInfos, string $subRepoName, string $tag, string $reason, bool $skipped): array
    {
        $message = ($skipped ? 'Skipping ' : 'Failed building ') . $packageName . ' : ' . $reason;
        syslog($skipped ? LOG_WARNING : LOG_ERR, $message);

        return $this->describePackage($packageName, $subRepoName) + [
            'version'         => $tag,
            'previousVersion' => $this->actualState[$subRepoName][$packageName]['version'] ?? '',
            'url'             => '',
            'branch'          => $packageInfos['branch'] ?? null,
            'tag'             => $packageInfos['tag'] ?? null,
            'success'         => false,
            'skipped'         => $skipped,
            'error'           => $reason,
            'elapsed'         => 0,
            'log'             => [$message],
        ];
    }

    /** Split a package name into the channel, type and short name the build report reads. */
    private function describePackage($packageName, string $subRepoName): array
    {
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
            'channel'     => $subRepoName,
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
            $versionPrefix = $this->versionPrefixOf($subRepoName, $subRepoContent);
            $packageName = 'yeswiki-' . $subRepoName;
            $rep = explode('/archive', $subRepoContent['repository']);
            $subRepoContent['repository'] = $rep[0];
            $this->repoConf[$subRepoName][$packageName] = array(
                'repository' => $subRepoContent['repository'],
                'branch' => empty($subRepoContent['branch']) ? '' : $subRepoContent['branch'],
                'tag' => empty($subRepoContent['tag']) ? '' : $subRepoContent['tag'],
                'tag-branch' => empty($subRepoContent['tag-branch']) ? '' : $subRepoContent['tag-branch'],
                'version-prefix' => $versionPrefix,
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
                    'version-prefix' => $versionPrefix,
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
                    'version-prefix' => $versionPrefix,
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
    private function buildPackage($srcFile, $destDir, $packageName, $packageInfos, string $resolvedTag = ''): array
    {
        $log = [];
        $time_start = microtime(true);
        $tag = (isset($packageInfos['tag']) && $packageInfos['tag'] == "latest") ? ['tag' => $resolvedTag] : [];
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
    public function getGitFolder($pkgInfos, string $resolvedTag = '')
    {
        if (!empty($pkgInfos['tag'])) {
            $localBranchOrTagName = ' --detach ' . escapeshellarg($resolvedTag ?: $pkgInfos['tag']);
        } else {
            $version = "origin/{$pkgInfos['branch']}";
            $localBranchOrTagName = $pkgInfos['branch'];
        }

        $destDir = $this->fetchRepository($pkgInfos['repository']);
        exec("cd $destDir; git reset --hard --quiet");
        exec("cd $destDir; git clean -ffdx --quiet");
        exec("cd $destDir; git checkout {$localBranchOrTagName} --quiet 2>&1", $output, $checkedOut);
        if ($checkedOut !== 0) {
            throw new Exception("git checkout {$localBranchOrTagName} failed : " . implode("\n", $output));
        }
        if (isset($version)) {
            exec("cd $destDir; git reset --hard $version --quiet");
        }
        return $destDir;
    }
    /** Clone the repository if it is missing, then bring branches and tags up to date. */
    private function fetchRepository($repository): string
    {
        $destDir = getcwd() . '/packages-src/' . basename($repository);
        if (!is_dir($destDir)) {
            $this->git('git clone ' . escapeshellarg($repository) . ' ' . escapeshellarg($destDir), 'clone ' . $repository);
        } else {
            exec("cd $destDir; git remote set-url origin {$repository} > /dev/null 2>&1");
        }
        $this->git("cd $destDir; git fetch --all --tags -f --prune --quiet 2>&1", 'fetch ' . $repository);
        return $destDir;
    }

    /** Run a git command that the build depends on, and fail loudly when it does not work. */
    private function git(string $command, string $what): void
    {
        $output = [];
        $status = 0;
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new Exception('git ' . $what . ' failed (exit code ' . $status . ') : ' . implode("\n", $output));
        }
    }
    private function getLatestTag($destDir, string $tagBranch = '', string $versionPrefix = '')
    {
        if (!empty($versionPrefix)) {
            return $this->getLatestTagOfSeries($destDir, $tagBranch, $versionPrefix);
        }

        try {
            $result =  exec("cd $destDir; {$this->getLatestTagScript($tagBranch)}\n");
            if (!empty($tagBranch)) {
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

    /** The newest tag of a branch, or the newest tag anywhere for a channel naming no branch. */
    private function getLatestTagScript(string $tagBranch = ''): string
    {
        if (empty($tagBranch)) {
            return "git describe --tags --long `git rev-list --tags --max-count=1`";
        }

        return 'git describe --tags --abbrev=0 ' . escapeshellarg('origin/' . $tagBranch);
    }

    /** The newest tag of the version series a channel distributes, ordered by version and not by date. */
    private function getLatestTagOfSeries($destDir, string $tagBranch, string $versionPrefix): string
    {
        $command = 'cd ' . escapeshellarg($destDir) . '; git tag --list --sort=-v:refname';
        if (!empty($tagBranch)) {
            $command .= ' --merged ' . escapeshellarg('origin/' . $tagBranch);
        }

        $tags = [];
        exec($command, $tags);
        foreach ($tags as $tag) {
            if ($this->versionMatchesPrefix($tag, $versionPrefix)) {
                return trim($tag);
            }
        }

        return '';
    }
}
