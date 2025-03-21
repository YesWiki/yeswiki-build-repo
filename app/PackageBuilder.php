<?php

namespace YesWikiRepo;

use Exception;

class PackageBuilder
{
    private $composerFile;
    /**
     * @param mixed $composerFile
     */
    public function __construct($composerFile)
    {
        $this->composerFile = $composerFile;
    }

    /**
     * Build a package
     * @param  string $srcFile      Source archive address
     * @param  string $destDir      Directory where to put package
     * @param  string $packageName  Package's name
     * @param  array  $packageInfos previous version information.
     * @return [type]               updated informations
     * @param mixed $pkgName
     * @param mixed $pkgInfos
     */
    public function build($srcFile, $destDir, $pkgName, $pkgInfos): array
    {
        if (empty($pkgInfos['tag'])) {
            // récupère la date de dernière modification
            $timestamp = $this->getBuildTimestamp($srcFile);
            $pkgInfos['version'] = $timestamp . '-' . $this->getCommitNumberForDay($srcFile, $timestamp);
        } else {
            $pkgInfos['version'] = str_replace('v', '', $pkgInfos['tag']);
        }
        // traitement des données (composer, etc.)
        $this->composer($srcFile);

        // For the core YesWiki, change YesWiki version in the files
        if (substr($pkgName, 0, strlen("yeswiki-")) == "yeswiki-") {
            $yeswikiVersion = $pkgInfos['branch'] = str_replace('yeswiki-', '', $pkgName);
            syslog(LOG_INFO, "Changing YesWiki version in constants.php to {$yeswikiVersion} {$pkgInfos['version']}");
            $file = file_get_contents($srcFile . '/includes/constants.php');
            $file = preg_replace('/define\([\'"]YESWIKI_VERSION[\'"], .*\);/Ui', 'define("YESWIKI_VERSION", \'' . $yeswikiVersion . '\');', $file);
            $file = preg_replace('/define\([\'"]YESWIKI_RELEASE[\'"], .*\);/Ui', 'define("YESWIKI_RELEASE", \'' . $pkgInfos['version'] . '\');', $file);
            file_put_contents($srcFile . '/includes/constants.php', $file);
        }
        // Construire l'archive finale
        $pkgInfos['file'] = $this->getFilename(
            $pkgName,
            $pkgInfos['version']
        );
        $archiveFile = $destDir . $pkgInfos['file'];
        $this->buildArchive($srcFile, $archiveFile);

        // Générer le hash du fichier
        $this->makeMD5($archiveFile);

        // make symlink for the package zip and md5
        $this->makeSymlinks($archiveFile, $destDir . $pkgName . '-latest.zip');

        // get minimum php version if exists
        $ver = $this->getMinimalPhpVersion($srcFile);
        if ($ver) {
            $pkgInfos['minimal_php_version'] = $ver;
        }

        return $pkgInfos;
    }

    /**
     * Download file to temporary filename
     * @param  string $sourceUrl Address where file to download is.
     * @param  string $prefix    Prefix for temporary filename
     * @return string            path to downloaded file.
     */
    private function download($sourceUrl, $prefix = ""): string
    {
        $downloadedFile = tempnam(sys_get_temp_dir(), $prefix);
        file_put_contents($downloadedFile, fopen($sourceUrl, 'r'));
        return $downloadedFile;
    }

    /**
     * Load last file modification from git log
     * @param  string $archiveFile path to the git folder
     * @return string date in YYYY-MM-DD format
     */
    private function getBuildTimestamp($archiveFile): string
    {
        $date = exec('cd ' . $archiveFile . '; git log --pretty="%cd" --date=short -1 .');
        return $date;
    }

    /**
     * Number of commits in git log for given day
     *
     * @param  string $archiveFile path to the git folder
     * @param string $day date of commit
     * @return string return number of commits for this day
     */
    private function getCommitNumberForDay($archiveFile, $day): int
    {
        exec('cd ' . $archiveFile . '; git log --pretty="%cd" --date=short --after="' . $day . ' 00:00" --before="' . $day . ' 23:59" .', $output);
        $nbCommits = count($output);
        return $nbCommits;
    }

    /**
     * Execute composer in every sub folder containing an "composer.json" file
     * @param  string $path Directory to scan
     * @return void
     */
    private function composer($path): void
    {
        // remove existing vendor folder if exists
        if (is_dir($path . '/vendor')) {
            (new File($path . '/vendor'))->delete($path . '/vendor');
        }
        if (file_exists($path . '/composer.json')) {
            syslog(LOG_INFO, "Running composer install for the core");
            $command = $this->composerFile." install -q --no-progress --no-dev --optimize-autoloader --working-dir=\"$path\" > /dev/null 2>&1";
            $lastLine = exec($command, $output, $retval);
            if ($retval != 0) {
                throw new Exception("Trouble while starting 'composer' for " . basename($path));
            }
        }
        // check if default extensions need some composer
        if (\is_dir($path . '/tools')) {
            $iterator = new \DirectoryIterator($path . '/tools');
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDir() && !$fileinfo->isDot()) {
                    $extFolder = $fileinfo->getPathname();
                    if (file_exists($extFolder . '/composer.json')) {
                        syslog(LOG_INFO, "Running composer install for the extension ".basename($extFolder));
                        $command = $this->composerFile." install -q --no-progress --no-dev --optimize-autoloader --working-dir=\"$path\" > /dev/null 2>&1";
                        exec($command, $output, $retval);
                        if ($retval != 0) {
                            throw new Exception("Trouble while starting 'composer' for " . basename($path) . "/tools/" . basename($extFolder).":\n".implode("\n", $output));
                        }
                    }
                }
            }
        }
    }

    /**
     * Get the minimal php version from composer.json to be able to use the package
     *
     * @param string $path to source package
     * @return string php version or null
     */
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
                    // accepted format '7','7.3','7.*','7.3.0','7.3.*
                    // and these with '^', '>' or '>=' before
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

    /**
     * Build final Archive
     * @param  string $sourceDir   Source Directory
     * @param  string $archiveFile Archive file name
     * @return string              path to maked archive
     */
    private function buildArchive($sourceDir, $archiveFile): void
    {
        $zip = new \ZipArchive();
        $zip->open($archiveFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $dirlist = new \RecursiveDirectoryIterator(
            $sourceDir,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );
        $filelist = new \RecursiveIteratorIterator($dirlist);

        // get folder name for zip archive
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
        // get the last modified date from git folder
        exec('cd ' . $sourceDir . ' && git --no-pager log -1 --date=format:"%Y%m%d%H%M" --format="%ad"', $out);
        $date = $out[0];
        syslog(LOG_INFO, "Files were last modified on {$date}");
        foreach ($filelist as $file) {
            // don't zip the .git folder and the .github folder
            if (!preg_match('/^' . preg_quote($sourceDir, '/') . '\/\.git.*/', $file)) {
                exec('touch -t ' . $date . ' "' . $file.'"'); // give all files the same date
                $internalFile = str_replace($sourceDir . '/', $folderName . '/', $file);
                $zip->addFile($file, $internalFile);
            }
        }
        $zip->close();
        exec('touch -t ' . $date . ' "' . $archiveFile.'"');
        $zipName = str_replace(dirname(dirname($archiveFile)).'/', '', $archiveFile);
        syslog(LOG_INFO, "The archive $zipName was succesfully created");
    }

    /**
     * Generate final archive filename with path
     * @param  [type] $destDir [description]
     * @return [type]         [description]
     * @param mixed $pkgName
     * @param mixed $version
     */
    private function getFilename($pkgName, $version): string
    {
        return $pkgName . '-' . $version . '.zip';
    }
    /**
     * @param mixed $filename
     */
    private function makeMD5($filename): bool
    {
        $md5 = md5_file($filename);
        $md5 .= ' ' . basename($filename);
        file_put_contents($filename . '.md5', $md5);
        $sha = hash_file('sha256', $filename);
        file_put_contents($filename . '.sha256', $sha);
        return true;
    }

    /**
     * create symlinks from latest
     *
     * @param string $source source path
     * @param string $dest destination path
     * @return string command output
     */
    private function makeSymlinks($source, $dest): string
    {
        $output = '';

        // zip symlink
        if (file_exists($dest)) {
            unlink($dest);
        }
        $output .= exec('ln -s ' . $source . ' ' . $dest);

        // md5
        if (file_exists($dest . '.md5')) {
            unlink($dest . '.md5');
        }
        $this->makeMD5($dest);

        return $output;
    }
}
