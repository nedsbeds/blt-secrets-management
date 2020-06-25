Acquia BLT Secret management
====

This is an [Acquia BLT](https://github.com/acquia/blt) plugin providing an easy way to control [secrets storage](https://docs.acquia.com/resource/secrets/) .

This plugin is **community-created** and **community-supported**. Acquia does not provide any direct support for this software or provide any warranty as to its stability.

The plugin uses ansible and ansible-vault to store secrets credentials encrypted in your repository. 
It then allows you to deploy these credentials in a secure and repeatable way, ensuring that secret settings files have correct syntax and are up to date.

## Installation and usage

To use this plugin, you must already have a Drupal project using BLT.
The plugin assumes your drush aliases have ssh hostnames and usernames, and that you have SSH keys to access those environments already configured. 

In your project, require the plugin with Composer:

`composer require nedsbeds/blt-secrets-management`

## Creating a new vault

Initialize the new vault by calling `secrets:vault:init` which will prompt you for a new password to encrypt your vault.
It will create a minimal vault file for adding your secrets.

## Editing your vault

Call the command `secrets:edit` which will prompt for your password to decrypt the vault file. 
Your default editor will open with a temp file where you can make your changes. Once done, save and close the file for it to be re-enccrypted. 
You should now commit the vault file to your repository

> Note: You can change the default editor by setting the environment variable DEFAULT_EDITOR
> 
> e.g. `export DEFAULT_EDITOR=subl -w`


## Diff command

Call the command `secrets:diff` with a drush alias and the plugin will first create your secrets.settings.php file from your encrypted information, then run a php lint to ensure it is valid PHP. It will then show you any differences between the generated settings file and the file on that environment.  

## Deploy command

Call the command `secrets:deploy` with a drush alias and the plugin will first create your secrets.settings.php file from your encrypted information, then run a php lint to ensure it is valid PHP. It will then overwrite the settings file on that environment with your new values.

## Adding new settings

The plugin requires to elements. 
* the vault-file with your credentials
* A settings.secrets.php template to show how your credentials are used 

The vault file is in JSON format and allows you to have a different credential per environment.

```json
{
  "secrets": {
    "example_api_key": {
      "@local": "localsecret",
      "@dev": "devsecret",
      "@test": "stagesecret",
      "@prod": "prodsecret"
      }
    }
}
```

You should add your own credentials in the `secrets` array, and update the environment names to match your drush aliases

To edit this file, run `secrets:edit`

The secrets.settings.php template is located in `/secrets/secrets.settings.php.j2` and uses the jinja templating language.

```php
<?php

// Example setting
$settings['example_api_key'] = '{{secrets['example_api_key'][drush_alias]}}';
```

The jinja variable `{{secrets['example_api_key'][drush_alias]}}` will get data from your vault.

Add new settings as needed.

## Passwords in keychain

To make dealing with the vault easier, you can run `secrets:keychain:init` which will prompt you for your vault password, add it to your keychain and then use that in future instead of prompting for your password each time.


## Example workflow

The imagined workflow for this tool is 

## Integration with SimpleSAMLPHP

If you have a need to secretly store config for a SimpleSAMLPHP app integration, see [simplesamlphp_readme.md](simplesamlphp_readme.md).

# License

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License version 2 as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.