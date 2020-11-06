# build-repo
Build scripts and github hook to create a yeswiki repository

## Configuration
Copy the file `repo.config.json.sample` to `repo.config.json` and change the values according to your config
 - config-address: file or url containing a config json for all yeswiki parts (core, extensions, themes). *by default https://raw.githubusercontent.com/YesWiki/yeswiki-config-repo/master/repo.config.json*
 - repo-path: local fullpath for the generated files
 - mail-to: email of the admin (receives update informations)
 - composer-bin: fullpath to the local composer binary.

## Initialisation
`php index.php action=init`
or
open the url in a browser with the GET parameter action=init.
