<?php
namespace YesWikiRepo;

use \Files\File;
use \Exception;
use \ZipArchive;

class PackageBuilder
{
    private $composerFile;

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
     */
    public function build($srcFile, $destDir, $pkgName, $pkgInfos)
    {
        if (empty($pkgInfos['tag'])) {
            // récupère la date de dernière modification
            $timestamp = $this->getBuildTimestamp($srcFile);
            $pkgInfos['version'] = $timestamp.'-'.$this->getCommitNumberForDay($srcFile, $timestamp);
        } else {
            $pkgInfos['version'] = str_replace('v', '', $pkgInfos['tag']);
        }
        // traitement des données (composer, etc.)
        $this->composer($srcFile);
 
        // For the core YesWiki, change YesWiki version in the files
        if (substr($pkgName, 0, strlen("yeswiki-")) == "yeswiki-") {
            $yeswikiVersion = $pkgInfos['branch'] = str_replace('yeswiki-', '', $pkgName);
            $file = file_get_contents($srcFile.'/includes/constants.php');
            $file = preg_replace('/define\("YESWIKI_VERSION", .*\);/Ui', 'define("YESWIKI_VERSION", \''.$yeswikiVersion.'\');', $file);
            $file = preg_replace('/define\("YESWIKI_RELEASE", .*\);/Ui', 'define("YESWIKI_RELEASE", \''.$pkgInfos['version'].'\');', $file);
            file_put_contents($srcFile.'/includes/constants.php', $file);
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
        $this->makeSymlinks($archiveFile, $destDir.$pkgName.'-latest.zip');

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
    private function download($sourceUrl, $prefix = "")
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
    private function getBuildTimestamp($archiveFile)
    {
        $date = exec('cd '.$archiveFile.'; git log --pretty="%cd" --date=short -1 .');
        return $date;
    }

    /**
     * Number of commits in git log for given day
     *
     * @param  string $archiveFile path to the git folder
     * @param string $day date of commit
     * @return string return number of commits for this day
     */
    private function getCommitNumberForDay($archiveFile, $day)
    {
        exec('cd '.$archiveFile.'; git log --pretty="%cd" --date=short --after="'.$day.' 00:00" --before="'.$day.' 23:59" .', $output);
        $nbCommits = count($output);
        return $nbCommits;
    }

    /**
     * Execute composer in every sub folder containing an "composer.json" file
     * @param  string $path Directory to scan
     * @return void
     */
    private function composer($path)
    {
        // remove existing vendor folder if exists
        if (is_dir($path.'/vendor')) {
            (new File($path.'/vendor'))->delete($path.'/vendor');
        }
        $command = $this->composerFile
            . " install --no-progress --no-dev --optimize-autoloader --working-dir=";
        if (file_exists($path.'/composer.json')) {
            echo exec($command . '"' . $path . '"');
        }
        // check if default extensions need some composer
        if (\is_dir($path.'/tools')) {
            $iterator = new \DirectoryIterator($path.'/tools');
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->isDir() && ! $fileinfo->isDot()) {
                    $extFolder = $fileinfo->getPathname();
                    if (file_exists($extFolder.'/composer.json')) {
                        echo exec($command . '"' . $extFolder . '"');
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
    private function getMinimalPhpVersion($path)
    {
        $ver = null;
        $jsonPath = $path . '/composer.json';
        if (file_exists($jsonPath)) {
            $jsonFile = file_get_contents($jsonPath);
            if (!empty($jsonFile)) {
                $composerData = json_decode($jsonFile, true) ;
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
                        $ver = $major.'.'.$minor.'.'.$fix;
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
    private function buildArchive($sourceDir, $archiveFile)
    {
        $zip = new \ZipArchive;
        $zip->open($archiveFile, \ZipArchive::CREATE);

        $dirlist = new \RecursiveDirectoryIterator(
            $sourceDir,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );
        $filelist = new \RecursiveIteratorIterator($dirlist);

        // get folder name for zip archive
        preg_match('/^.*-(.*)-.*/U', basename($archiveFile), $matches);

        foreach ($filelist as $file) {
            // don't zip the .git folder and the .github folder
            if (!preg_match('/^'.preg_quote($sourceDir, '/').'\/\.git.*/', $file)) {
                $internalFile = str_replace($sourceDir . '/', $matches[1].'/', $file);
                $zip->addFile($file, $internalFile);
            }
        }
        $zip->close();
    }

    /**
     * Generate final archive filename with path
     * @param  [type] $destDir [description]
     * @return [type]         [description]
     */
    private function getFilename($pkgName, $version)
    {
        return $pkgName . '-' . $version . '.zip';
    }

    private function makeMD5($filename)
    {
        $md5 = md5_file($filename);
        $md5 .= ' ' . basename($filename);
        return file_put_contents($filename . '.md5', $md5);
    }

    /**
     * create symlinks from latest
     *
     * @param string $source source path
     * @param string $dest destination path
     * @return string command output
     */
    private function makeSymlinks($source, $dest)
    {
        $output = '';

        // zip symlink
        if (file_exists($dest)) {
            unlink($dest);
        }
        $output .= exec('ln -s '.$source.' '.$dest);

        // md5
        if (file_exists($dest.'.md5')) {
            unlink($dest.'.md5');
        }
        $this->makeMD5($dest);

        return $output;
    }
}
