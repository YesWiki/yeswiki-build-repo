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
