<?php

namespace YesWikiRepo;

use Exception;

/**
 * Publishes the signed self-contained binaries that CI attached to a GitHub release, and tracks the
 * ones still waiting for their offline signature.
 */
class BinaryPublisher
{
    const INDEX_NAME = 'binary.json';
    const PENDING_NAME = 'binary-pending.json';

    const PUBLISHED = 'published';
    const UNCHANGED = 'unchanged';
    const UNSIGNED = 'unsigned';
    const FAILED = 'failed';

    /** @var array */
    private $localConf;

    /** @var string[] */
    private $log = [];

    /** @var bool */
    private $wrote = false;

    public function __construct(array $localConf)
    {
        $this->localConf = $localConf;
    }

    /** Publish the signed platforms of the newest release of one channel, and answer a result row the notifier reads. */
    public function publish(string $channel, array $binaryConf): array
    {
        $this->log = [];
        $this->wrote = false;

        try {
            $release = $this->newestRelease($binaryConf);
            $version = ltrim($release['tag_name'], 'v');
            $signed = $this->signedPlatforms($release, $binaryConf);
            $unsigned = $this->unsignedPlatforms($release, $binaryConf);

            $status = self::UNCHANGED;
            if (!empty($signed) && !$this->alreadyPublished($channel, $version, $signed)) {
                $written = $this->downloadPlatforms($channel, $release, $signed);
                $this->writeIndex($channel, $release, $written);
                $this->forgetPending($channel, $unsigned);
                $this->wrote = true;
                $status = self::PUBLISHED;
            } elseif (!empty($signed)) {
                $this->log[] = 'v' . $version . ' is already published for ' . implode(', ', $signed);
            }

            if (!empty($unsigned)) {
                $this->log[] = "Pour signer :\n" . $this->commandsFor($release['tag_name'], $this->githubRepository($binaryConf), $unsigned);
                return $this->result($channel, $version, false, $this->awaitingSignature($release, $unsigned), self::UNSIGNED, $release, $binaryConf);
            }

            return $this->result($channel, $version, true, '', $status, $release, $binaryConf);
        } catch (Exception $exception) {
            return $this->result($channel, '', false, $exception->getMessage(), self::FAILED);
        }
    }

    /** Is this unsigned release new since the last "to sign" notification? Remembers it when it is. */
    public function isNewlyPending(array $result): bool
    {
        $path = $this->channelPath($result['channel']) . '/' . self::PENDING_NAME;
        $pending = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (($pending['tag'] ?? '') === $result['tag']) {
            return false;
        }

        file_put_contents($path, json_encode(['tag' => $result['tag'], 'since' => date('c')], JSON_PRETTY_PRINT) . "\n");
        return true;
    }

    /** When the pending release of a channel was first announced, or an empty string. */
    public function pendingSince(string $channel): string
    {
        $path = $this->channelPath($channel) . '/' . self::PENDING_NAME;
        $pending = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;

        return substr($pending['since'] ?? '', 0, 10);
    }

    /** The commands that sign the pending platforms of a release and upload their signatures. */
    public function signingCommands(array $result): string
    {
        return $this->commandsFor($result['tag'], $result['githubRepository'], $result['pending']);
    }

    /** gh commands that download a release, sign the given platforms and upload their signatures. */
    private function commandsFor(string $tag, string $repo, array $platforms): string
    {
        $lines = ["gh release download {$tag} -R {$repo} -p 'yeswiki-linux-*'"];
        foreach ($platforms as $platform) {
            $lines[] = "yeswiki sign --key ~/.yeswiki-signing/yeswiki-release.key yeswiki-{$platform}";
        }
        foreach ($platforms as $platform) {
            $lines[] = "gh release upload {$tag} -R {$repo} yeswiki-{$platform}.sig";
        }

        return implode("\n", $lines);
    }

    /** The newest release that carries binaries, so a source-only tag does not blank the index. */
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

    /** Download the signed platforms into a directory per version, never overwriting a published file. */
    private function downloadPlatforms(string $channel, array $release, array $signed): array
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
        foreach ($signed as $platform) {
            $name = 'yeswiki-' . $platform;

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

    /** Write the flat index an installed binary reads. */
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

    /** The platforms of a release that carry both their binary and its signature. */
    private function signedPlatforms(array $release, array $binaryConf): array
    {
        $assets = $this->assetNames($release);
        return array_values(array_filter(
            $binaryConf['platforms'] ?? [],
            fn($platform) => isset($assets['yeswiki-' . $platform], $assets['yeswiki-' . $platform . '.sig'])
        ));
    }

    /** The platforms the channel expects that are not signed yet, whether CI uploaded them or not. */
    private function unsignedPlatforms(array $release, array $binaryConf): array
    {
        return array_values(array_diff($binaryConf['platforms'] ?? [], $this->signedPlatforms($release, $binaryConf)));
    }

    private function assetNames(array $release): array
    {
        return array_flip(array_map(fn($asset) => $asset['name'], $release['assets'] ?? []));
    }

    /** Does the index already hold this version for every signed platform? */
    private function alreadyPublished(string $channel, string $version, array $signed): bool
    {
        $path = $this->channelPath($channel) . '/' . self::INDEX_NAME;
        $index = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (($index['version'] ?? '') !== $version) {
            return false;
        }

        return empty(array_diff($signed, array_keys($index['platforms'] ?? [])));
    }

    /** Clear the pending state once nothing of the release waits for a signature any more. */
    private function forgetPending(string $channel, array $unsigned): void
    {
        $path = $this->channelPath($channel) . '/' . self::PENDING_NAME;
        if (empty($unsigned) && file_exists($path)) {
            unlink($path);
        }
    }

    private function awaitingSignature(array $release, array $unsigned): string
    {
        $assets = $this->assetNames($release);
        $built = array_filter($unsigned, fn($platform) => isset($assets['yeswiki-' . $platform]));
        $missing = array_diff($unsigned, $built);
        $parts = [];
        if (!empty($built)) {
            $parts[] = 'à signer : yeswiki-' . implode(', yeswiki-', $built);
        }
        if (!empty($missing)) {
            $parts[] = 'pas encore déposé par la CI : yeswiki-' . implode(', yeswiki-', $missing);
        }

        return $release['tag_name'] . ' attend sa signature (' . implode(' ; ', $parts) . ')';
    }

    /** owner/name of the GitHub repository a releases address points to. */
    private function githubRepository(array $binaryConf): string
    {
        return preg_match('#/repos/([^/]+/[^/]+)/releases#', $binaryConf['releases'] ?? '', $match) ? $match[1] : '';
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

    /** Fetch an address, through the proxy when one is exported. */
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

    private function result(string $channel, string $version, bool $success, string $error, string $status, array $release = [], array $binaryConf = []): array
    {
        $published = in_array($status, [self::PUBLISHED, self::UNCHANGED], true);
        return [
            'packageName' => 'yeswiki-binary',
            'type' => 'binary',
            'shortName' => 'yeswiki-binary',
            'channel' => $channel,
            'version' => $version,
            'previousVersion' => '',
            'url' => $published ? rtrim($this->localConf['repo-url'] ?? '', '/') . '/' . $channel . '/' . self::INDEX_NAME : '',
            'branch' => null,
            'tag' => $version === '' ? '' : 'v' . $version,
            'success' => $success,
            'skipped' => $status === self::UNSIGNED,
            'error' => $error,
            'log' => $this->log,
            'binaryStatus' => $status,
            'newlyPublished' => $this->wrote,
            'pending' => $status === self::UNSIGNED ? $this->unsignedPlatforms($release, $binaryConf) : [],
            'releaseUrl' => $release['html_url'] ?? '',
            'githubRepository' => $this->githubRepository($binaryConf),
        ];
    }
}
