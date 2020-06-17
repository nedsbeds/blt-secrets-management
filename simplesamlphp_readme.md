Acquia BLT Secret management for SimpleSAMLPHP
====
This BLT plugin also includes additional commands to store secrets to be used with SimpleSAMLPHP integration.

## Creating a new vault

Initialize the new vault by calling `secrets:simplesamlphp:vault:init` which will prompt you for a new password to encrypt your vault.
It will create a minimal vault file for adding your secrets.

## Editing your vault

Call the command `secrets:simplesamlphp:edit` which will prompt for your password to decrypt the vault file. 
Your default editor will open with a temp file where you can make your changes. Once done, save and close the file for it to be re-enccrypted. 
You should now commit the vault file to your repository

> Note: You can change the default editor by setting the environment variable DEFAULT_EDITOR
> 
> e.g. `export DEFAULT_EDITOR=subl -w`


## Diff command

Call the command `secrets:simplesamlphp:diff` with a drush alias and the plugin will first create your simplesamlphp.secrets.php file from your encrypted information, then run a php lint to ensure it is valid PHP. It will then show you any differences between the generated settings file and the file on that environment.  

## Deploy command

Call the command `secrets:simplesamlphp:deploy` with a drush alias and the plugin will first create your simplesamlphp.secrets.php file from your encrypted information, then run a php lint to ensure it is valid PHP. It will then overwrite the settings file on that environment with your new values.

## Adding new settings

The plugin requires to elements. 
* the vault-file with your credentials
* A simplesamlphp.secrets.php template to show how your credentials are used 

The vault file is in JSON format and allows you to have a different credential per environment.

```json
{
  "simplesamlphp_secrets": {
    "secretsalt": "y0h9d13pki9qdhfm3l5nws4jjn55j6hj",
    "auth.adminpassword": {
      "@local": "localsecret",
      "@dev": "devsecret",
      "@test": "testsecret",
      "@prod": "prodsecret"
    }
  }
}
```

You should add your own credentials in the `simplesaml_secrets` array, and update the environment names to match your drush aliases

To edit this file, run `secrets:simplesamlphp:edit`

The secrets.settings.php template is located in `/secrets/simplesamlphp.secrets.php.j2` and uses the jinja templating language.

```php
<?php

// Example simplesamlphp config
$config['secretsalt'] = '{{simplesamlphp_secrets['secretsalt']}}';
$config['auth.adminpassword'] = '{{simplesamlphp_secrets['auth.adminpassword'][drush_alias]}}';
```

The jinja variable `{{simplesamlphp_secrets['auth.adminpassword'][drush_alias]}}` will get data from your vault.

Add new settings as needed.

## Passwords in keychain

To make dealing with the vault easier, you can run `secrets:keychain:init` which will prompt you for your vault password, add it to your keychain and then use that in future instead of prompting for your password each time. Note that it will store the password in the index in the keychain that the Drupal secrets vault uses, so the two vaults will need to be created with the same password if the keychain and both vaults are used.

## Appending to `acquia_config.php`

In order for the SimpleSAMLPHP app to pick up the secrets file created by the vault, you can run `secrets:simplesamlphp:acquia_config:include`. This will append:

```php
if (file_exists('{RELATIVE PATH TO LOCAL SECRETS INCLUDE FILE}')) {
  // If the local simplesamlphp secrets file exists, include it.
  include '{RELATIVE PATH TO LOCAL SECRETS INCLUDE FILE}';
}
elseif (file_exists('/mnt/files/' . getenv('AH_SITE_GROUP') . '.' . getenv('AH_SITE_ENVIRONMENT') . '/simplesamlphp.secrets.php')) {
  // Otherwise include the cloud simplesamlphp secrets file if it exists.
  include '/mnt/files/' . getenv('AH_SITE_GROUP') . '.' . getenv('AH_SITE_ENVIRONMENT') . '/simplesamlphp.secrets.php';
}
```

The local secrets include file is located at `/scripts/simplesamlphp/secrets.php.local` in your BLT project, if you have either run a diff or deploy to your local alias.

Note that the include is only appended to the `/simplesamlphp/acquia_config.php` in your BLT project. You may need to manually edit the file to place the include in the appropriate place or delete any lines from `acquia_config.php` that the include is meant to replace.

Once ready, run `source:build:simplesamlphp-config` to make sure the SimpleSAMLPHP config is correctly updated and copied to the app directory in `/vendor`.