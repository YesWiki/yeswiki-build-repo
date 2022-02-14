# build-repo

Build scripts and github hook to create a yeswiki repository

## Dependencies

This package needs `git`, installed with a revision higher than `2.0`, `php` (>= 7.3 with `php-zip`, and `exec` function available) and `composer`.

## Configuration

Copy the file `config.php.example` to `config.php` and change the values according to your config.

- config-address: file or url containing a config json for all yeswiki parts (core, extensions, themes). *by default https://raw.githubusercontent.com/YesWiki/yeswiki-config-repo/master/repo.config.json*
- repo-path: local fullpath for the generated files
- repo-url: link to the repository (used in notifications)
- mail-to: email of the admin (receives update informations)
- composer-bin: fullpath to the local composer binary.
- repo-key: if you want to request from an url, you will need to pass this key in the header of your request as Repository-Key
- mattermost-hook-url: the url provided from mattermost to send notification as webhooks
- mattermost-channel: channel used in mattermost for sending notification
- mattermost-authorName: the username for the notification
- mattermost-authorIcon: the url to a picture used as avatar for the notification
- yunohost-subRepo: the branch that is watched for yeswiki_ynh updates
- yunohost-git: a git clone URL, with credentials and push access, of the yeswiki_ynh repository. Empty if you do not want this update mechanism.
- yunohost-git-source-branch: yeswiki_ynh branch from which to create the new commits

## Initialisation of the repository (only once)

`php index.php action=init`
or
request the repo's url with in the header `Repository-Key : <value of the key>` and the GET parameter `action=init`.

## Update all packages

`php index.php action=update`
or
request the repo's url with in the header `Repository-Key : <value of the key>` and the GET parameter `action=update`.

## Update specific package

`php index.php action=update package=<packagename>`
or
request the repo's url with in the header `Repository-Key : <value of the key>` and the GET parameters `action=update&package=<packagename>`.

## Purge

`php index.php action=purge`
or
request the repo's url with in the header `Repository-Key : <value of the key>` and the GET parameter `action=purge`.

## YunoHost integration

On yeswiki core or loginldap update, if the YunoHost package is not up to date, a new branch will be created on the `yunohost-git` repository with updated version number and hash.

For that to work, there should be a commiter known to git, at least for that repository. It can be added via `git config --global user.email "an-email@here"` and `git config --global user.name "Username"`.

It will need manual action for a PR to be created against [YunoHost-Apps/yeswiki_ynh](https://github.com/YunoHost-Apps/yeswiki_ynh) and any member of the YunoHost-Apps org will be able to run the ynh CI on it.
