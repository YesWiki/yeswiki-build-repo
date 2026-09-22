<?php

namespace YesWikiRepo;

use Exception;

/**
 * Publishes the self-contained binary into the repository, beside the extension and theme zips.
 *
 * ADR-0016 made this host the only distributor, and its 2026-08-21 amendment extended that from
 * extensions and themes to core itself. CI builds the executables on GitHub and this is what moves
 * them here: an installed binary reads `<repo>/<channel>/binary.json` and nothing else, so a
 * private or air-gapped mirror stays a config change rather than a fork.
 *
 * Nothing here signs anything. The signing key is held offline and never reaches CI, so a release
 * arrives unsigned and is signed by hand before this runs. A platform whose `.sig` is missing is
 * skipped and named, because publishing it would put an artefact in the index that every installed
 * binary refuses -- which reads as an outage rather than as a signature nobody made.
 */
class BinaryPublisher
{
    const INDEX_NAME = 'binary.json';

    /** @var array */
    private $localConf;

    /** @var string[] */
    private $log = [];

    public function __construct(array $localConf)
    {
        $this->localConf = $localConf;
    }

    /**
     * Publish the newest signed release of one channel, and answer a result row the notifier reads.
     */
    public function publish(string $channel, array $binaryConf): array
    {
        $this->log = [];

        try {
            $release = $this->newestRelease($binaryConf);
            $written = $this->downloadPlatforms($channel, $release, $binaryConf);

            if (empty($written)) {
                throw new Exception(
                    'no platform of ' . $release['tag_name'] . ' carries a signature yet, so nothing was published. '
                    . 'Sign the artefacts with `yeswiki sign --key <key> <file>` and upload the .sig files first.'
                );
            }

            $this->writeIndex($channel, $release, $written);

            return $this->result($channel, ltrim($release['tag_name'], 'v'), true, '');
        } catch (Exception $exception) {
            return $this->result($channel, '', false, $exception->getMessage());
        }
    }

    /**
     * The newest release that actually carries binaries, so a source-only tag does not blank the index.
     */
    private function newestRelease(array $binaryConf): array
    {
        $address = $binaryConf['releases'] ?? '';
        if (empty($address)) {
            throw new Exception('the channel names no `releases` address to read');
        }

        $releases = json_decode($this->fetch($address, true), true);
        if (!is_array($releases)) {
            throw new Exception($address . ' did not answer a list of releases');
        }

        $prefix = $binaryConf['tag-prefix'] ?? '';
        foreach ($releases as $release) {
            if (!empty($release['draft'])) {
                continue;
            }
            if ($prefix !== '' && strpos($release['tag_name'], $prefix) !== 0) {
                continue;
            }
            foreach (($release['assets'] ?? []) as $asset) {
                if (strpos($asset['name'], 'yeswiki-linux-') === 0) {
                    return $release;
                }
            }
        }

        throw new Exception('no release carries a yeswiki-linux-* asset yet');
    }

    /**
     * Download every platform that is signed, into a directory named after the version.
     *
     * Versioned rather than overwritten: an installed binary reads the index, follows the url it
     * finds and downloads it, and those two steps are not one transaction. Replacing a file in
     * place would hand somebody mid-upgrade the new bytes under the old checksum.
     */
    private function downloadPlatforms(string $channel, array $release, array $binaryConf): array
    {
        $version = ltrim($release['tag_name'], 'v');
        $directory = $this->channelPath($channel) . '/binary/' . $version;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new Exception('could not create ' . $directory);
        }

        $assets = [];
        foreach (($release['assets'] ?? []) as $asset) {
            $assets[$asset['name']] = $asset['browser_download_url'];
        }

        $written = [];
        foreach (($binaryConf['platforms'] ?? []) as $platform) {
            $name = 'yeswiki-' . $platform;

            if (empty($assets[$name])) {
                $this->log[] = $name . ' is not in ' . $release['tag_name'];
                continue;
            }
            if (empty($assets[$name . '.sig'])) {
                $this->log[] = $name . ' has no signature yet, so it was not published';
                continue;
            }

            $binary = $this->download($assets[$name], $directory . '/' . $name);
            $this->download($assets[$name . '.sig'], $directory . '/' . $name . '.sig');

            $digest = hash_file('sha256', $binary);
            if (!empty($assets[$name . '.sha256'])) {
                $stated = $this->download($assets[$name . '.sha256'], $directory . '/' . $name . '.sha256');
                $published = strtok(trim(file_get_contents($stated)), " \t\n");
                if (strcasecmp($published, $digest) !== 0) {
                    throw new Exception($name . ' downloaded as ' . $digest . ' and the release says ' . $published);
                }
            }
            if (!empty($assets[$name . '.build.json'])) {
                $this->download($assets[$name . '.build.json'], $directory . '/' . $name . '.build.json');
            }

            $written[$platform] = [
                'url' => $this->publicUrl($channel, $version, $name),
                'sha256' => $digest,
                'signature' => $this->publicUrl($channel, $version, $name . '.sig'),
                'bytes' => filesize($binary),
            ];
            $this->log[] = $name . ' published, ' . round(filesize($binary) / 1048576) . ' MB';
        }

        return $written;
    }

    /**
     * The index an installed binary reads. Flat and greppable on purpose: an operator mirroring
     * this by hand should be able to read it, and a shell script should be able to pull a url out
     * of it without a JSON library.
     */
    private function writeIndex(string $channel, array $release, array $platforms): void
    {
        $index = [
            'version' => ltrim($release['tag_name'], 'v'),
            'released' => substr($release['published_at'] ?? '', 0, 10),
            'platforms' => $platforms,
        ];

        $path = $this->channelPath($channel) . '/' . self::INDEX_NAME;
        $written = file_put_contents(
            $path,
            json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        if ($written === false) {
            throw new Exception('could not write ' . $path);
        }
        $this->log[] = 'wrote ' . $path;
    }

    private function channelPath(string $channel): string
    {
        return rtrim($this->localConf['repo-path'], '/') . '/' . $channel;
    }

    private function publicUrl(string $channel, string $version, string $name): string
    {
        return rtrim($this->localConf['repo-url'], '/') . '/' . $channel . '/binary/' . $version . '/' . $name;
    }

    private function download(string $address, string $to): string
    {
        $content = $this->fetch($address, false);
        if (file_put_contents($to, $content) === false) {
            throw new Exception('could not write ' . $to);
        }

        return $to;
    }

    /**
     * A plain stream fetch, through the proxy when one is exported, because the machine this runs
     * on may not reach GitHub directly.
     */
    private function fetch(string $address, bool $api): string
    {
        $headers = ['User-Agent: yeswiki-build-repo'];
        if ($api) {
            $headers[] = 'Accept: application/vnd.github+json';
            if (!empty($this->localConf['github-token'])) {
                $headers[] = 'Authorization: Bearer ' . $this->localConf['github-token'];
            }
        }

        $options = [
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 300,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
        ];

        $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: '';
        if ($proxy !== '') {
            $options['http']['proxy'] = str_replace(['http://', 'https://'], 'tcp://', $proxy);
            $options['http']['request_fulluri'] = true;
        }

        $content = @file_get_contents($address, false, stream_context_create($options));
        if ($content === false) {
            $why = error_get_last();
            throw new Exception('could not fetch ' . $address . ': ' . ($why['message'] ?? 'unknown error'));
        }

        return $content;
    }

    private function result(string $channel, string $version, bool $success, string $error): array
    {
        return [
            'packageName' => 'yeswiki-binary',
            'type' => 'binary',
            'shortName' => 'yeswiki-binary',
            'channel' => $channel,
            'version' => $version,
            'previousVersion' => '',
            'url' => $version === '' ? '' : rtrim($this->localConf['repo-url'] ?? '', '/')
                . '/' . $channel . '/' . self::INDEX_NAME,
            'branch' => $channel,
            'tag' => $version === '' ? '' : 'v' . $version,
            'success' => $success,
            'skipped' => false,
            'error' => $error,
            'log' => $this->log,
        ];
    }
}
