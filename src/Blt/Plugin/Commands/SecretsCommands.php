<?php

namespace Acquia\SecretsManagement\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Exceptions\BltException;
use Robo\Contract\VerbosityThresholdInterface;

/**
 * Defines commands in the "secrets" namespace.
 */
class SecretsCommands extends BltTasks {

    /**
     * The directory containing this file.
     *
     * @var string
     */
    private $pluginRoot = '/vendor/nedsbeds/blt-secrets-management';

    /**
     * The environments from drush aliases
     *
     * @var array
     */
    private $environments = null;

    /**
     * Initializes default secrets configuration for this project.
     *
     * @command secrets:vault:init
     * @throws \Acquia\Blt\Robo\Exceptions\BltException
     */
    public function secretsInit() {

        if ($this->confirm("<error>Are you sure you want to initialise? This will overwrite any existing encrypted vaults</error>", FALSE)) {

            $result = $this->taskFilesystemStack()
                ->copy($this->getConfigValue('repo.root') . $this->pluginRoot . '/ansible/secrets.settings.php.j2', $this->getConfigValue('repo.root') . '/secrets/secrets.settings.php.j2', TRUE)
                ->copy($this->getConfigValue('repo.root') . $this->pluginRoot . '/ansible/.gitignore', $this->getConfigValue('repo.root') . '/secrets/.gitignore', TRUE)
                ->mkdir($this->getConfigValue('repo.root') . '/secrets/files/common/')
                ->stopOnFail()
                ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
                ->run();

            if (!$result->wasSuccessful()) {
                throw new BltException("Could not initialize secrets configuration.");
            }

            $this->taskExec("ansible-vault encrypt " . $this->getConfigValue('repo.root') . "$this->pluginRoot/ansible/secrets_vault.yml --ask-vault-pass --output secrets/secrets_vault")->run();

            $this->say("<info>A New ansible-vault and template were copied to your repository.</info>");
            $this->say("<info>Run 'blt secrets:edit' to edit your vault.</info>");
        }
    }

    /**
     * Adds your vault password to the keychain.
     *
     * @command secrets:keychain:init
     */
    public function vaultPasswordFileInit() {
        $vaultID = $this->getVaultId();
        $this->taskExec('security add-generic-password -a ' . $vaultID . ' -s "' . $vaultID . ' Password" -w')->run();

        $this->taskFilesystemStack()
            ->touch($this->getConfigValue('repo.root') . '/secrets/.usekeychain')
            ->run();
    }

    /**
     * Get the Password from keychain for decrypting the vault.
     *
     * @command secrets:vault:password
     */
    public function vaultPasswordFile() {
        $vaultID = $this->getVaultId();
        $this->taskExec(
            "/usr/bin/security find-generic-password -w \
        -a \"$vaultID\" -l \"$vaultID Password\" 2> /dev/null"
        )->run();
    }

    /**
     * Edit the vault file.
     *
     * @command secrets:edit
     * @aliases seed
     * @throws \Acquia\Blt\Robo\Exceptions\BltException
     */
    public function secretsEdit() {
        $command = "ansible-vault edit secrets/secrets_vault" . $this->getVaultPasswordCommand();
        $this->taskExec($command)->run();
    }


    /**
     * Encrypt all ad-hoc files.
     *
     * @command secrets:encryptfiles
     * @aliases enfl
     * @throws \Acquia\Blt\Robo\Exceptions\BltException
     */
    public function encryptFiles() {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('secrets/files'));
        $files = [];

        foreach ($iterator as $file) {
            if ($file->isDir()){ continue; }
            // Check if the file is already envcypted.
            if (strpos(file_get_contents($file),'$ANSIBLE_VAULT') === 0) { continue; }

            $files[] = $file->getPathname();
        }
        $filelist = implode(" ", $files);

        $this->taskExec("ansible-vault encrypt $filelist --ask-vault-pass")->run();

    }

    /**
     * Provide a diff on your vault for a particular environment.
     *
     * @command secrets:diff
     * @aliases sedf
     * @throws \Robo\Exception\TaskException
     */
    public function secretsDiff($drushAlias) {
        $environments = $this->getEnvironments();

        if (isset($environments[$drushAlias])) {
            $ansibleCommand = $this->getPlaybookCommand(
                $drushAlias,
                $environments,
                "-CD"
            );

            $this->taskExec($ansibleCommand)
                ->run();
        } else {
            throw new BltException("Could not find Drush alias");
        }
    }

    /**
     * Deploy the secrets in a particular environment.
     *
     * @param string $drushAlias
     *   The drush alias for the environment.
     *
     * @command secrets:deploy
     * @aliases sedp
     * @throws \Robo\Exception\TaskException
     */
    public function secretsDeploy($drushAlias) {
        $environments = $this->getEnvironments();

        if (isset($environments[$drushAlias])) {
            $ansibleCommand = $this->getPlaybookCommand($drushAlias, $environments);
            $this->taskExec($ansibleCommand)->run();
        } else {
            throw new BltException("Could not find Drush alias");
        }
    }

    /**
     * Generate an ansible parameter for getting password from keychain.
     *
     * @return string
     *   Return the vault-password-file parameter.
     */
    private function getVaultPasswordCommand() {
        if (file_exists($this->getConfigValue('repo.root') . '/secrets/.usekeychain')) {
            return ' --vault-password-file=' . $this->getConfigValue('repo.root') . $this->pluginRoot . '/ansible/vault_password_file';
        } else {
            return ' --ask-vault-pass';
        }
    }

    /**
     * Get a vault ID for naming keychain entry.
     *
     * @return string
     *   The vault id used to define keychain entry name.
     */
    private function getVaultId() {
        return $this->getConfigValue('project.machine_name') . '-ansible-vault';
    }

    /**
     * Retrieve the drush aliases defined.
     *
     * @return array
     *   The environments defined in drush alias file.
     *
     * @throws \Robo\Exception\TaskException
     */
    private function getEnvironments() {
        if (is_null($this->environments)) {
            $this->environments = unserialize(
                $this->taskDrush()
                    ->Drush("sa --format=php")
                    ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
                    ->run()
                    ->getMessage()
            );
        }
        return $this->environments;
    }

    private function getSecretsRemoteLocation($drushAlias)
    {
        $secrets_remote_location = $this->getConfigValue('secrets.settings-remote-location');
        $environment = $this->getEnvironmentFacts($drushAlias);

        $locations = [];
        if (isset($environment['ac_site']) && isset($environment['ac_env'])) {
            $locations['env_path'] = '/mnt/files/' . $environment['ac_site'] . '.' . $environment['ac_env'] . "/";
        } else {
            // Could not calculate location and was not set explicitly.
            throw new BltException("Could not determine the secrets.settings.php remote location. Please define in BLT config 'secrets.settings-remote-location'");
        }

        if (isset($environment['ac_update_env'])) {
            $locations['update_env_path'] = '/mnt/files/' . $environment['ac_site'] . '.' . $environment['ac_update_env'] . "/";
        }

        if (!is_null($secrets_remote_location)) {
            $locations['secret_location'] = $secrets_remote_location;
        } else {
            // Default is just in the root of the environment directory
            $locations['secret_location'] = 'secrets.settings.php';
        }

        return $locations;
    }

    /**
     * Get the detailed variables for template substitution options.
     *
     * @param $drushAlias
     * The Drush alias
     *
     * @return array
     * Set of variables for template substitution.
     */
    private function getEnvironmentFacts($drushAlias) {
        $environment = $this->getEnvironments()[$drushAlias];

        $acSite = isset($environment['ac-site']) ? $environment['ac-site'] : str_replace("@", '', explode('.', $drushAlias)[0]);
        $acEnv = isset($environment['ac-env']) ? $environment['ac-env'] : explode('.', $drushAlias)[1];

        $facts = [
            'alias' => $drushAlias,
            'ac_site' => $acSite,
            'ac_env' => $acEnv,
            'stack' => preg_replace('/\D/', '', $acEnv),
            'stack_env' => preg_replace('/\d/', '', $acEnv)
        ];

        // Set a value for local.
        if ($facts['stack'] == "") {
            $facts['stack'] = "00";
        }

        // Set the update environment identifier if this is an ACSF project.
        // Mimics the environment detector logic but without requiring Drupal to be bootstrapped.
        if (file_exists($this->getConfigValue('repo.root') . "/docroot/sites/g")) {
            if ($facts['stack_env'] == 'live') {
                $facts['ac_update_env'] = $facts['stack'] . "update";
              } else {
                $facts['ac_update_env'] = $facts['ac_env'] . "up";
              }
        }


        return $facts;
    }

    /**
     * Gather the list of files, combining different directories in to one array
     *
     * @return array $files
     */
    private function getEncryptedFiles($drushAlias) {

        $files = [];
        $environmentFacts = $this->getEnvironmentFacts($drushAlias);


        // Construct the list and precedence of different directories files can be added to.
        $fileOverridePaths = [
            'common',
            $environmentFacts['alias'],
            $environmentFacts['ac_site'],
            $environmentFacts['ac_env'],
            $environmentFacts['stack'],
            $environmentFacts['stack_env']
        ];

        // Loop through each directory and look for files.
        foreach ($fileOverridePaths as $overridePath) {
            $basePath = 'secrets/files/' . $overridePath . "/";

            // Check the directory exists.
            if (is_dir($basePath)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($basePath));

                foreach ($iterator as $file) {
                    if ($file->isDir()) {
                        continue;
                    }

                    // Add to the overall list of files overwriting any needed.
                    $fileId = str_replace($basePath, "", $file->getPathname());
                    $files[$fileId] = $file->getPathname();
                }
            }
        }

        if( count($files) > 0) {
            return $files;
        } else {
            return false;
        }
    }

    /**
     * Get the ansible command for running the main playbook.
     *
     * @param string $drushAlias
     *   The Drush alias.
     * @param array $environments
     *   Full list of environments from SecurityCommands::getEnvironments().
     * @param string $arguments
     *   Any extra arguments to ansible-playbook command.
     *
     * @return string
     *   Full command for running the ansible playbook.
     */
    private function getPlaybookCommand($drushAlias, array $environments, $arguments = "") {
        $defaultPlaybook = $this->getConfigValue('repo.root') . $this->pluginRoot . "/ansible/deploy-secrets.yml";
        $playbook = $this->getConfigValue('secrets.playbook', $defaultPlaybook);

        $secrets_vault_location = $this->getConfigValue('repo.root') . "/secrets/secrets_vault";
        $secrets_template_location = $this->getConfigValue('repo.root') . "/secrets/secrets.settings.php.j2";

        // Detailed set of variables that describe our environment.
        $environmentFacts = $this->getEnvironmentFacts($drushAlias);
        // Information about where we are going to place our secrets file on current env.
        $secretsRemoteLocation = $this->getSecretsRemoteLocation($drushAlias);
        // Get the list of files we need to deploy.
        $files = $this->getEncryptedFiles($drushAlias);

        $extraVars = [
            'drush_alias' => $environmentFacts['alias'],
            'ac_site' => $environmentFacts['ac_site'],
            'ac_env' => $environmentFacts['ac_env'],
            'stack=' => $environmentFacts['stack'],
            'stack_env' => $environmentFacts['stack_env'],
            'secret_vault_location' => $secrets_vault_location,
            'secret_template_location' => $secrets_template_location,
            'env_path' => $secretsRemoteLocation['env_path'],
        ];

        // In ACSF sites we pass some extra variables.
        if (isset($environmentFacts['ac_update_env'])) {
            $extraVars['ac_update_env'] = $environmentFacts['ac_update_env'];
            $extraVars['update_env_path'] = $secretsRemoteLocation['update_env_path'];
        }

        // Add the list of files and corresponding destinations
        if ($files !== false) {
            $extraVars['file_srcs'] = array_values($files);
            $extraVars['file_dests'] = array_keys($files);
        }

        // The connection string on local is different.
        // The localsettings_location variable defines where we want an include for our templated file.
        if (!strstr($drushAlias, 'local')) {
            $host = " -i " . $environments[$drushAlias]['host'] . ", -u " . $environments[$drushAlias]['user'];
            $extraVars['secret_location'] = $secretsRemoteLocation['secret_location'];
        } else {
            $host = " -i 127.0.0.1";
            $extraVars['secret_location'] = $this->getConfigValue('repo.root') . '/docroot/sites/default/settings/secrets.settings.local';
            $extraVars['localsettings_location'] = $this->getConfigValue('repo.root') . '/docroot/sites/default/settings/local.settings.php';
            $extraVars['ansible_connection'] = 'local';
        }

        $extraVarsEncoded = " --extra-vars '" . json_encode($extraVars) . "' ";

        $command = "ansible-playbook " . $arguments . " " . $playbook . $host . $extraVarsEncoded . $this->getVaultPasswordCommand();

        $this->say("<info>Computed Variables:\n" . print_r($extraVars, true) . "</info>");

        return $command;
    }
}
