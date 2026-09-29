<?php

namespace YesWikiRepo;

class BuildPruner
{
    const DEFAULT_KEEP = 15;

    private $repoPath;
    private $keep;

    public function __construct(array $localConf)
    {
        $this->repoPath = rtrim($localConf['repo-path'], '/');
        $this->keep = isset($localConf['keep-builds']) ? (int) $localConf['keep-builds'] : self::DEFAULT_KEEP;
    }

    /** Prune the branch builds of every channel on disk, including channels no longer configured. */
    public function pruneAll(bool $dryRun = false): array
    {
        $report = [];
        foreach (glob($this->repoPath . '/*', GLOB_ONLYDIR) as $channelDir) {
            $report[basename($channelDir)] = $this->pruneChannel(basename($channelDir), null, $dryRun);
        }
        return $report;
    }

    /** Delete all but the newest branch builds of one channel, or of one package in it, and return what went. */
    public function pruneChannel(string $channel, ?string $packageName = null, bool $dryRun = false): array
    {
        $result = ['files' => 0, 'bytes' => 0, 'versions' => []];
        if ($this->keep < 1) {
            return $result;
        }

        $channelDir = $this->repoPath . '/' . $channel;
        $protected = $this->servedArchives($channelDir);
        foreach ($this->branchBuilds($channelDir) as $name => $builds) {
            if ($packageName !== null && $name !== $packageName) {
                continue;
            }
            uksort($builds, function ($a, $b) {
                return strnatcmp($b, $a);
            });
            foreach (array_slice($builds, $this->keep, null, true) as $version => $archive) {
                if (in_array($archive, $protected, true)) {
                    continue;
                }
                foreach ([$archive, $archive . '.md5', $archive . '.sha256'] as $file) {
                    $path = $channelDir . '/' . $file;
                    if (!is_file($path) || is_link($path)) {
                        continue;
                    }
                    $result['bytes'] += filesize($path);
                    $result['files']++;
                    if (!$dryRun) {
                        unlink($path);
                    }
                }
                $result['versions'][] = $name . ' ' . $version;
            }
        }
        return $result;
    }

    /** The branch builds of a channel, as package name => [version => archive filename]. */
    private function branchBuilds(string $channelDir): array
    {
        $builds = [];
        foreach (glob($channelDir . '/*.zip') as $path) {
            $file = basename($path);
            if (!is_link($path) && preg_match('/^(.+)-(\d{4}-\d{2}-\d{2}-\d+)\.zip$/', $file, $match)) {
                $builds[$match[1]][$match[2]] = $file;
            }
        }
        return $builds;
    }

    /** Archives that packages.json or a -latest link still points to, never deleted. */
    private function servedArchives(string $channelDir): array
    {
        $served = [];
        foreach (glob($channelDir . '/*-latest.zip') as $link) {
            if (is_link($link)) {
                $served[] = basename(readlink($link));
            }
        }
        $packagesFile = $channelDir . '/packages.json';
        if (is_file($packagesFile)) {
            foreach ((array) json_decode(file_get_contents($packagesFile), true) as $infos) {
                if (!empty($infos['file'])) {
                    $served[] = $infos['file'];
                }
            }
        }
        return $served;
    }
}
