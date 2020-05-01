# Drupal Libraries Installer

_Drupal Libraries Installer_ is a [composer][composer] plugin that allows for easily
managing external libraries required for Drupal modules/themes that are not available
as composer packages. This plugin is another piece of the puzzle towards managing all
external dependencies for a Drupal site in a single place: the `composer.json` file.

## How to Use

1. Add _Drupal Libraries Installer_ to your Drupal site project:

    ```sh
    composer require balbuf/drupal-libraries-installer
    ```

1. Add libraries to your `composer.json` file via the `drupal-libraries` property
within `extra`. A library is specified using its name as the key and a URL to 
its distribution ZIP/RAR/TAR/TGZ/TAR.GZ/TAR.BZ2 file as the value.
It'll attempt to guess the library type from the URL.
If the file is not a ZIP file, then the URL must end with the file extension:

    ```json
    {
        "extra": {
            "drupal-libraries": {
                "chosen": "https://github.com/harvesthq/chosen/releases/download/v1.8.2/chosen_v1.8.2.zip",
                "flexslider": "https://github.com/woocommerce/FlexSlider/archive/2.6.4.zip",
                "moment": "https://registry.npmjs.org/moment/-/moment-2.25.0.tgz"
            }
        }
    }
    ```

    Or alternatively if you want to specify a more detailed definition:

    ```json
    {
        "extra": {
            "drupal-libraries": {
                "chosen": {
                    "url": "https://github.com/harvesthq/chosen/releases/download/v1.8.2/chosen_v1.8.2.zip"
                },
                "flexslider": {
                    "url": "https://github.com/woocommerce/FlexSlider/archive/2.6.4.zip",
                    "version": "2.6.4",
                    "type": "zip",
                    "ignore": ["bower_components", "demo", "node_modules"]
                },
                "moment": {
                    "url": "https://registry.npmjs.org/moment/-/moment-2.25.0.tgz",
                    "shasum": "e961ab9a5848a1cf2c52b1af4e6c82a8401e7fe9"
                },
                "select2": {
                     "url": "https://github.com/select2/select2/archive/4.0.13.zip",
                     "ignore": [
                         ".*",
                         "*.{md}",
                         "Gruntfile.js",
                         "{docs,src,tests}"
                     ]
                },
                "custom-tar-asset": {
                    "url": "https://assets.custom-url.com/unconventional/url/path",
                    "type": "tar",
                    "ignore": [".*", "*.{txt,md}"]
                }
            }
        }
    }
    ```

   Where the configuration options are as follows:
   - `url`: The library URL (mandatory).
   - `version`: The version of the library (defaults to `1.0.0`).
   - `type`: The type of library archive, one of (zip, tar, rar, gzip), support depends on your composer version (defaults to `zip`). 
   - `ignore`: Array of folders/file globs to remove from the library (defaults to `[]`). See [PSA-2011-002](https://www.drupal.org/node/1189632).
   - `shasum`: The SHA1 hash of the asset (optional).

    _See below for how to find the ZIP URL for a GitHub repo._

1. Ensure composer packages of type `drupal-library` are configured to install to the
appropriate path. By default, [`composer/installers`][installers] (a dependency of
this plugin and likely already included in your project) will install these packages
to `/libraries/{$name}/` in the root of your repo. You may wish to change this via
the `installer-paths` property (within `extra`) of your `composer.json`:

    ```json
    {
        "extra": {
            "installer-paths": {
                "web/libraries/{$name}/": ["type:drupal-library"]
            }
        }
    }
    ```

    _See the `composer/installers` [README][installers readme] for more information on
    the `installer-paths` property._

1. Run `composer install`. Libraries are downloaded and unpacked into place upon running
`composer install` or `composer update`. (To upgrade a library, simply swap out its URL
in your `composer.json` file and run `composer install` again.)

## Installing libraries declared by other packages.

Set the `drupal-libraries-dependencies` extra property to `true` to fetch libraries
declared by all the packages in your project. Only the first declaration of the 
library is ever used, so if you find that multiple packages require different
versions of the same library, you can declare the correct version in the 
project's `composer.json`.

```json
{
    "extra": {
        "drupal-libraries-dependencies": true
    }
}
```

Alternatively you can restrict it to specific packages (recommended) by setting
it to an array of packages which you'd like to pull in libraries for.

```json
{
    "extra": {
        "drupal-libraries-dependencies": [
          "drupal/project1",
          "drupal/project2"
        ]
    }
}
```

## How to reference an npm package.

For example, if you're interested in downloading version 2.25.0 of the 
[`moment`](https://www.npmjs.com/package/moment) npm package.

- Run `npm view moment@2.25.0`.
- Use the `.tarball` value as the library `url`.
- Use the `.shasum` value as the library `shasum`.

## How to Reference a GitHub Repo as a ZIP File

If you are directed to download a library from its GitHub repo, follow these instructions
to find a link to the ZIP file version of the code base:

### Preferred Method

It is best to reference a specific release of the library so that the same version of
code is downloaded every time for each user of the project. To see what releases are
available:

1. Click the `X releases` link (where `X` is some number) near the top of the
GitHub repo's home page. You can also reach the release page by appending `/releases/`
to the repo's home page URL, e.g. for `https://github.com/harvesthq/chosen`, you'd
go to `https://github.com/harvesthq/chosen/releases/`.

1. Identify which release you'd like to use. You'll likely want to use the latest release
unless the module noted a specific version requirement.

1. For that release, find the "Assets" section. If the repo provides its own distribution
ZIP file, that will be listed as one of the first files in the list. If so, you'll want to
try using that first in case it includes pre-built files not available directly in the repo.
Otherwise, use the "Source code (zip)" link for that release. Simply copy the URL for the
desired link to use within your `composer.json` file.

### Alternate Method

If the library does not provide any releases, you can still reference it in ZIP file form.
The downside is that any time you download this ZIP, the contents may change based on the
state of the repo. There is no guarantee that separate users of the project will have the
exact same version of the library.

1. Click the green `Clone or download` button on the repo's home page.

1. Copy the URL for the `Download ZIP` link to use within your `composer.json` file.

## Notes

- This plugin is essentially a shortcut for explicitly declaring the composer package
information for each library zip you need to include in your project, e.g.:
    ```json
    {
        "repositories": [
            {
                "type": "package",
                "package": {
                    "name": "harvesthq/chosen",
                    "version": "1.8.2",
                    "type": "drupal-library",
                    "dist": {
                      "url": "https://github.com/harvesthq/chosen/releases/download/v1.8.2/chosen_v1.8.2.zip",
                      "type": "zip"
                    }
                }
            }
        ],
        "require": {
            "harvesthq/chosen": "1.8.2"
        }
    }
    ```
    While that method is perfectly viable and works right out of the box with no additional
    plugin, it is also cumbersome, not very user-friendly, and quite verbose, adding
    a lot of additional noise to your `composer.json` file.
- Libraries are installed after actual composer packages are installed and are not
subject to the dependency-resolving algorithm inherent to composer. What this means
is that libraries cannot be specified with a range of compatible versions (rather,
a specific version of a library's distribution file must be chosen), and if libraries
have any other additional library dependencies of their own, these must be explicitly
added to the list.
- Because libraries are installed after composer packages, it's possible that a library
installed by this plugin could overwrite a composer package in the event of an
install-path collision.
- While many libraries are JS- and/or CSS-based and available via [npm][npm], there is
no way to install these packages directly into the proper /libraries/ folder with npm.
As well, modules will often list their external library requirements as links to ZIP
distribution files or GitHub repos, making it easier to reference and pull in these
dependencies in that manner with this plugin.

[composer]: https://getcomposer.org/
[npm]: https://www.npmjs.com/
[installers]: https://packagist.org/packages/composer/installers
[installers readme]: https://github.com/composer/installers#custom-install-paths
