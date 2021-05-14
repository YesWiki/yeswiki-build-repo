<?php
namespace YesWikiRepo;

class YunoHost {
    public $repository;

    public function __construct(Repository $repository) {
        $this->repository = $repository;
    }

    public function update() {
        if (!empty($ynhDir = $this->getGitFolder())) {
            $subRepoName = $this->repository->localConf["yunohost-subRepo"];

            $manifest = $this->getManifest($ynhDir);
            $ynhVersions = explode('~ynh', $manifest['version']);

            $subRepo = $this->repository->actualState[$subRepoName];

            $currentYWVersion = $subRepo['yeswiki-'.$subRepoName]['version'];
            $updateYW = $currentYWVersion != $ynhVersions[0];
            if ($updateYW) {
                syslog(LOG_INFO, 'Updating YesWiki_ynh ('.$ynhVersions[0]."=>$currentYWVersion)");
            } else {
                syslog(LOG_INFO, "YesWiki_ynh up to date ($currentYWVersion)");
            }

            $ynhLoginVersion = $this->getYnhLoginLDAPVersion($ynhDir);
            $currentLoginVersion = $subRepo['extension-loginldap']['version'];
            $updateLogin = $currentLoginVersion != $ynhLoginVersion;
            if ($updateLogin) {
                syslog(LOG_INFO, "Updating LoginLDAP ($ynhLoginVersion=>$currentLoginVersion)");
            } else {
                syslog(LOG_INFO, "LoginLDAP up to date ($currentLoginVersion)");
            }

            $newVersion = $manifest['version'];
            $branchName = '';

            if ($updateLogin) {
                $newVersion = $currentYWVersion.'~ynh'.($ynhVersions[1]+1);
                $branchName = 'loginldap-'.$currentLoginVersion;
                $this->updateLogin($ynhDir, $ynhLoginVersion, $currentLoginVersion);
            }
            if ($updateYW) {
                $newVersion = $currentYWVersion.'~ynh1';
                $branchName = 'yeswiki-'.$currentYWVersion . ((empty($branchName))? '' : '-'.$branchName);
                $this->updateReadmes($ynhDir, $ynhVersions[0], $currentYWVersion);
                $this->updateAppSrc($ynhDir, $subRepoName, $ynhVersions[0], $currentYWVersion);
            }

            if (!empty($branchName)) {
                $this->replaceInFile($ynhDir.'/manifest.json', $manifest['version'], $newVersion);

                $this->updateCheckProcess($ynhDir);

                if ($this->checkoutBranch($ynhDir, $branchName)) {
                    $this->commit($ynhDir, 'Update to YesWiki '.$currentYWVersion.', LoginLDAP '.$currentLoginVersion);
                    $this->push($ynhDir, $branchName);
                }
            }
        }
    }

    private function getGitFolder() {
        if (!empty($this->repository->localConf["yunohost-git"])) {
            $ynhPkg = [
                "repository" => $this->repository->localConf["yunohost-git"],
                "branch" => "testing",
            ];

            return $this->repository->getGitFolder($ynhPkg);
        } else {
            return "";
        }
    }

    private function getManifest($ynhDir) {
        $manifest = new JsonFile($ynhDir . '/manifest.json');
        $manifest->read();
        return $manifest;
    }

    private function getYnhLoginLDAPVersion($ynhDir) {
        $commonshContents = file_get_contents($ynhDir.'/scripts/_common.sh');
        preg_match('/loginldap_version="([0-9-]+)"/', $commonshContents, $matches);
        return $matches[1];
    }

    private function replaceInFile($file, $search, $replace) {
        $contents = file_get_contents($file);
        $contents = str_replace($search, $replace, $contents);
        file_put_contents($file, $contents);
    }

    private function updateLogin($ynhDir, $ynhLoginVersion, $currentLoginVersion) {
        $this->replaceInFile($ynhDir.'/scripts/_common.sh', $ynhLoginVersion, $currentLoginVersion);
    }

    private function updateReadmes($ynhDir, $ynhVersion, $currentYWVersion) {
        $this->replaceInFile($ynhDir.'/README.md', $ynhVersion, $currentYWVersion);
        $this->replaceInFile($ynhDir.'/README_fr.md', $ynhVersion, $currentYWVersion);
    }

    private function updateAppSrc($ynhDir, $subRepoName, $ynhVersion, $currentYWVersion) {
        $md5File = $this->repository->localConf['repo-path'] . $subRepoName . '/yeswiki-' . $subRepoName . '-' . $currentYWVersion . '.zip.md5';
        $md5 = explode(' ', file_get_contents($md5File))[0];

        $appSrcContents = file_get_contents($ynhDir.'/conf/app.src');
        $appSrcContents = str_replace($ynhVersion, $currentYWVersion, $appSrcContents);
        $appSrcContents = preg_replace('/SOURCE_SUM=[0-9a-f]+/i', 'SOURCE_SUM='.$md5, $appSrcContents);
        file_put_contents($ynhDir.'/conf/app.src', $appSrcContents);
    }

    private function updateCheckProcess($ynhDir) {
        $masterHash = exec('cd '.$ynhDir.'; git rev-parse origin/master');

        $checkProcessContents = file_get_contents($ynhDir.'/check_process');
        $checkProcessContents = preg_replace('/commit=[0-9a-f]+/i', 'commit='.$masterHash, $checkProcessContents);
        file_put_contents($ynhDir.'/check_process', $checkProcessContents);
    }

    private function checkoutBranch($ynhDir, $branchName) {
        $branches = exec('cd '.$ynhDir.'; git branch -l');
        if (!str_contains($branches, $branchName)) {
            syslog(LOG_INFO, "Checking out branch $branchName");
            echo exec('cd '.$ynhDir.'; git checkout -b '.$branchName);
            return true;
        } else {
            syslog(LOG_INFO, "Branch $branchName exists, not overwriting");
            return false;
        }
    }

    private function commit($ynhDir, $message) {
        echo exec('cd '.$ynhDir.'; git commit -a -m "'.$message.'"');
    }

    private function push($ynhDir, $branchName) {
        echo exec('cd '.$ynhDir.'; git push origin '.$branchName);
    }
}
