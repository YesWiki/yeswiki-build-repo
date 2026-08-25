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
- github-token: optional, raises the GitHub API rate limit when publishing binaries

## The self-contained binary

A channel whose entry in `repo.config.json` carries a `binary` block also distributes the
self-contained executable, not only extensions and themes:

```json
"ectoplasme": {
  "binary": {
    "releases": "https://api.github.com/repos/YesWiki/yeswiki/releases",
    "platforms": ["linux-x86_64", "linux-aarch64"],
    "tag-prefix": "v5."
  }
}
```

`tag-prefix` is optional and keeps a channel to its own release series, so a 4.x release does not
become the 5.x channel's binary the day both ship one.

`php index.php action=build` then downloads the newest release that carries binaries, checks each
one against the `.sha256` published beside it, files them under `<channel>/binary/<version>/` and
writes `<channel>/binary.json`. That index is what an installed `yeswiki` reads, and it is the only
place it looks: ADR-0016 makes this host the sole distributor, so a private or air-gapped mirror is
a `yeswiki_repository` setting rather than a fork.

**A platform with no `.sig` beside it is skipped and named.** The signing key is held offline and
never reaches CI, so a release arrives here unsigned and someone signs it by hand:

```sh
TAG=v5.0.0-alpha2
REPO=YesWiki/yeswiki

curl -sfLO "https://github.com/$REPO/releases/download/$TAG/yeswiki-linux-x86_64"
yeswiki sign --key ~/.yeswiki-signing/yeswiki-release.key yeswiki-linux-x86_64

# the release itself names the address its assets are uploaded to
UPLOAD=$(curl -sfH "Authorization: Bearer $GITHUB_TOKEN" \
  "https://api.github.com/repos/$REPO/releases/tags/$TAG" \
  | sed -n 's/.*"upload_url": "\([^{"]*\).*/\1/p')

curl -sf -X POST -H "Authorization: Bearer $GITHUB_TOKEN" \
  -H "Content-Type: application/octet-stream" \
  --data-binary @yeswiki-linux-x86_64.sig \
  "$UPLOAD?name=yeswiki-linux-x86_64.sig"
```

Publishing an unsigned binary would put an artefact in the index that every installed binary
refuses, which reads as an outage rather than as a signature nobody made.

`php index.php action=build target=binary` publishes the binaries and nothing else.

## Which branch a tag belongs to

A channel can follow a `branch`, or follow `"tag": "latest"`. A tag name carries no branch, so
`latest` used to mean the newest tag anywhere in the repository: tagging v5.0.0-alpha1 on
`ectoplasme` rebuilt the `doryphore` channel with 5.x code. A channel that adds `tag-branch` says
where its tags come from, and is rebuilt only when the pushed tag is the latest one reachable from
that branch:

```json
"doryphore": {
  "repository": "https://github.com/YesWiki/yeswiki",
  "tag": "latest",
  "tag-branch": "doryphore"
}
```

The key works for extensions and themes as well, and a package that omits it keeps the old
repository-wide behaviour.

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
