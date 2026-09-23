<?php

namespace YesWikiRepo;

use Exception;

class PackageBuilder
{
    /** Development files and folders at the root of the sources, left out of every archive. */
    private const EXCLUDED_FROM_ARCHIVE = [
        'tests', 'docker', 'phpstan', 'binary', '.vscode',
        'Makefile', 'shell.nix', 'playwright.config.ts', 'eslint.config.mjs',
        '.php-cs-fixer.dist.php', '.phpactor.json', '.prettierrc', '.prettierignore',
        '.envrc', '.env.example', '.dockerignore', '.editorconfig', '.git-blame-ignore-revs',
    ];

    private $composerFile;
    /** @param string $composerFile path to the composer binary */
    public function __construct($composerFile)
    {
        $this->composerFile = $composerFile;
    }

    /** Build the archive of a package from its sources and return its updated informations. */
    public function build($srcFile, $destDir, $pkgName, $pkgInfos): array
    {
        if (empty($pkgInfos['tag'])) {
            $timestamp = $this->getBuildTimestamp($srcFile);
            $pkgInfos['version'] = $timestamp . '-' . $this->getCommitNumberForDay($srcFile, $timestamp);
        } else {
            $pkgInfos['version'] = str_replace('v', '', $pkgInfos['tag']);
        }
        $this->installDeps($srcFile);

        if (substr($pkgName, 0, strlen("yeswiki-")) == "yeswiki-") {
            $yeswikiVersion = $pkgInfos['branch'] = str_replace('yeswiki-', '', $pkgName);
            syslog(LOG_INFO, "Changing YesWiki version in constants.php to {$yeswikiVersion} {$pkgInfos['version']}");
            $constantsFile = $this->findConstantsFile($srcFile);
            $file = file_get_contents($constantsFile);
            $file = preg_replace('/define\([\'"]YESWIKI_VERSION[\'"], .*\);/Ui', 'define("YESWIKI_VERSION", \'' . $yeswikiVersion . '\');', $file);
            $file = preg_replace('/define\([\'"]YESWIKI_RELEASE[\'"], .*\);/Ui', 'define("YESWIKI_RELEASE", \'' . $pkgInfos['version'] . '\');', $file);
            file_put_contents($constantsFile, $file);
        }
        $pkgInfos['file'] = $this->getFilename(
            $pkgName,
            $pkgInfos['version']
        );
        $archiveFile = $destDir . $pkgInfos['file'];
        $this->buildArchive($srcFile, $archiveFile);

        $this->makeMD5($archiveFile);

        $this->makeSymlinks($archiveFile, $destDir . $pkgName . '-latest.zip');

        $ver = $this->getMinimalPhpVersion($srcFile);
        if ($ver) {
            $pkgInfos['minimal_php_version'] = $ver;
        }

        return $pkgInfos;
    }

    /** The constants.php of the core: includes/ up to doryphore, src/ from ectoplasme. */
    private function findConstantsFile($srcFile): string
    {
        foreach (['/src/constants.php', '/includes/constants.php'] as $candidate) {
            if (file_exists($srcFile . $candidate)) {
                return $srcFile . $candidate;
            }
        }
        throw new Exception("No includes/constants.php nor src/constants.php in " . basename($srcFile));
    }

    /** Download a file to a temporary filename. */
    private function download($sourceUrl, $prefix = ""): string
    {
        $downloadedFile = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($downloadedFile, fopen($sourceUrl, 'r'));
        return $downloadedFile;
    }

    /** Date of the last commit of a git folder, as YYYY-MM-DD. */
    private function getBuildTimestamp($archiveFile): string
    {
        $date = exec('cd ' . $archiveFile . '; git log --pretty="%cd" --date=short -1 .');
        return $date;
    }

    /** Number of commits of a git folder on a given day. */
    private function getCommitNumberForDay($archiveFile, $day): int
    {
        exec('cd ' . $archiveFile . '; git log --pretty="%cd" --date=short --after="' . $day . ' 00:00" --before="' . $day . ' 23:59" .', $output);
        $nbCommits = count($output);
        return $nbCommits;
    }

    /** Install the composer and yarn dependencies of the core and of each bundled extension. */
    private function installDeps($path): void
    {
        if (is_dir($path . '/vendor')) {
            (new File($path . '/vendor'))->delete();
        }
        if (file_exists($path . '/composer.json')) {
            syslog(LOG_INFO, "Running composer install for the core");
            $this->run($this->composerCommand($path), "'composer' for " . basename($path));
        }
        foreach (['tools', 'extensions'] as $extensionsDir) {
            if (!\is_dir($path . '/' . $extensionsDir)) {
                continue;
            }
            $iterator = new \DirectoryIterator($path . '/' . $extensionsDir);
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDir() && !$fileinfo->isDot()) {
                    $extFolder = $fileinfo->getPathname();
                    $extName = basename($path) . '/' . $extensionsDir . '/' . basename($extFolder);
                    if (file_exists($extFolder . '/composer.json')) {
                        syslog(LOG_INFO, "Running composer install for the extension ".basename($extFolder));
                        $this->run($this->composerCommand($extFolder), "'composer' for " . $extName);
                    }
                    if (file_exists($extFolder . '/package.json')) {
                        syslog(LOG_INFO, "Running yarn install for the extension ".basename($extFolder));
                        $this->removeNodeModules($extFolder);
                        $this->run($this->yarnCommand($extFolder), "'yarn install' for " . $extName);
                    }
                }
            }
        }
        if (file_exists($path . '/package.json')) {
            syslog(LOG_INFO, "Running yarn install for the core");
            $this->removeNodeModules($path);
            $this->run($this->yarnCommand($path, true), "'yarn install' for " . basename($path));
            $this->removeNodeModules($path);
        }
    }

    /** Remove node_modules, whose .yarn-integrity would hide a package moved out of devDependencies. */
    private function removeNodeModules($folder): void
    {
        if (is_dir($folder . '/node_modules')) {
            (new File($folder . '/node_modules'))->delete();
        }
    }

    /** HOME for composer and yarn, unset when the build comes from the web server. */
    private function homePrefix(): string
    {
        return 'HOME=' . escapeshellarg(getenv('HOME') ?: '/tmp') . ' ';
    }

    /** Composer install command for a folder. */
    private function composerCommand($workingDir): string
    {
        return $this->homePrefix() . $this->composerFile
            . ' install --no-progress --no-dev --optimize-autoloader --working-dir='
            . escapeshellarg($workingDir) . ' 2>&1';
    }

    /** Writable cache folder for npm and yarn, as the HOME of the build account may not be. */
    private function nodeCacheFolder(): string
    {
        $folder = sys_get_temp_dir() . '/yeswiki-build-cache';
        foreach ([$folder, $folder . '/npm', $folder . '/yarn'] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }
        return $folder;
    }

    /** Environment for yarn: writable caches and no interactive npx prompt. */
    private function nodeEnvPrefix(): string
    {
        $cache = $this->nodeCacheFolder();
        return 'npm_config_cache=' . escapeshellarg($cache . '/npm') . ' '
            . 'YARN_CACHE_FOLDER=' . escapeshellarg($cache . '/yarn') . ' '
            . 'npm_config_update_notifier=false npm_config_yes=true ';
    }

    /** Does the package.json of this folder define a postinstall script? */
    private function hasPostinstallScript($workingDir): bool
    {
        $jsonPath = $workingDir . '/package.json';
        if (!file_exists($jsonPath)) {
            return false;
        }
        $packageData = json_decode(file_get_contents($jsonPath), true);
        return !empty($packageData['scripts']['postinstall']);
    }

    /** Yarn install without dependency scripts, then the package's own postinstall; devDependencies only when node_modules is thrown away after. */
    private function yarnCommand($workingDir, bool $withDevDependencies = false): string
    {
        $prefix = $this->homePrefix() . $this->nodeEnvPrefix();
        $command = $prefix . 'yarn install --ignore-optional'
            . ($withDevDependencies ? '' : ' --production')
            . ' --non-interactive --ignore-scripts --cwd '
            . escapeshellarg($workingDir) . ' 2>&1';
        if ($this->hasPostinstallScript($workingDir)) {
            $command .= ' && ' . $prefix . 'yarn --non-interactive --cwd '
                . escapeshellarg($workingDir) . ' run postinstall 2>&1';
        }
        return $command;
    }

    /** Run a dependency install command and throw with its output when it fails. */
    private function run($command, $what): void
    {
        $output = [];
        exec($command, $output, $retval);
        if ($retval != 0) {
            throw new Exception(
                "Trouble while starting " . $what . " (exit code $retval):\n" . implode("\n", $output)
            );
        }
    }

    /** Minimal php version required by the composer.json of a package. */
    private function getMinimalPhpVersion($path): ?string
    {
        $ver = null;
        $jsonPath = $path . '/composer.json';
        if (file_exists($jsonPath)) {
            $jsonFile = file_get_contents($jsonPath);
            if (!empty($jsonFile)) {
                $composerData = json_decode($jsonFile, true);
                if (!empty($composerData['require']['php'])) {
                    $rawNeededPHPRevision = $composerData['require']['php'];
                    $matches = [];
                    if (preg_match('/^(\^|>=|>)?([0-9]*)(?:\.([0-9\*]*))?(?:\.([0-9\*]*))?/', $rawNeededPHPRevision, $matches)) {
                        $major = $matches[2];
                        $minor = $matches[3] ?? 0;
                        $minor = ($minor == '*') ? 0 : $minor;
                        $fix = $matches[4] ?? 0;
                        $fix = ($fix == '*') ? 0 : $fix;
                        $ver = $major . '.' . $minor . '.' . $fix;
                    }
                }
            }
        }
        return $ver;
    }

    /** Zip the sources, all files dated from the last commit. */
    private function buildArchive($sourceDir, $archiveFile): void
    {
        $zip = new \ZipArchive();
        $zip->open($archiveFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $dirlist = new \RecursiveDirectoryIterator(
            $sourceDir,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );
        $filelist = new \RecursiveIteratorIterator($dirlist);

        $baseName = basename($archiveFile);
        if (substr($baseName, -4) == '.zip') {
            $baseName = substr($baseName, 0, -4);
        }
        if (
            preg_match('/((?:-\d+){1,4}|-\d+\.\d+\.\d+)$/', $baseName, $match1) &&
            preg_match('/^[^-]+-(.*)' . preg_quote($match1[1], '/') . '$/', $baseName, $matches)
        ) {
            $folderName = $matches[1];
        } elseif (preg_match('/^[^-]+-(.+)$/', $baseName, $matches)) {
            $folderName = $matches[1];
        } else {
            $folderName = $baseName;
        }
        exec('cd ' . $sourceDir . ' && git --no-pager log -1 --date=format:"%Y%m%d%H%M" --format="%ad"', $out);
        $date = $out[0];
        syslog(LOG_INFO, "Files were last modified on {$date}");
        foreach ($filelist as $file) {
            $relativePath = substr($file, strlen($sourceDir) + 1);
            if (!$this->isExcludedFromArchive($relativePath)) {
                exec('touch -t ' . $date . ' "' . $file.'"');
                $zip->addFile($file, $folderName . '/' . $relativePath);
            }
        }
        $zip->close();
        exec('touch -t ' . $date . ' "' . $archiveFile.'"');
        $zipName = str_replace(dirname(dirname($archiveFile)).'/', '', $archiveFile);
        syslog(LOG_INFO, "The archive $zipName was succesfully created");
    }

    /** Is this path, relative to the sources, a git or development file kept out of the archive? */
    private function isExcludedFromArchive(string $relativePath): bool
    {
        $root = explode('/', $relativePath)[0];
        return substr($root, 0, 4) === '.git' || in_array($root, self::EXCLUDED_FROM_ARCHIVE, true);
    }

    /** Filename of the archive of a package version. */
    private function getFilename($pkgName, $version): string
    {
        return $pkgName . '-' . $version . '.zip';
    }
    /** Write the md5 and sha256 of a file beside it. */
    private function makeMD5($filename): bool
    {
        $md5 = md5_file($filename);
        $md5 .= ' ' . basename($filename);
        file_put_contents($filename . '.md5', $md5);
        $sha = hash_file('sha256', $filename);
        file_put_contents($filename . '.sha256', $sha);
        return true;
    }

    /** Point the -latest symlink and its checksums to an archive. */
    private function makeSymlinks($source, $dest): string
    {
        $output = '';

        if (file_exists($dest)) {
            unlink($dest);
        }
        $output .= exec('ln -s ' . $source . ' ' . $dest);

        if (file_exists($dest . '.md5')) {
            unlink($dest . '.md5');
        }
        $this->makeMD5($dest);

        return $output;
    }
}
