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

    private function getSecretsRemoteLocation($drushAlias) {
        $secrets_remote_location = $this->getConfigValue('secrets.settings-remote-location');
        $environment = $this->getEnvironmentFacts($drushAlias);

        if (!is_null($secrets_remote_location)) {
            return $secrets_remote_location;
        } else if (is_null($secrets_remote_location) && isset($environment['ac-site']) && isset($environment['ac-env'])) {
            // We can calculate the expected location from drush alias.
            return '/mnt/files/' . $environment['ac-site'] . '.' . $environment['ac-env'] . '/secrets.settings.php';
        } else {
            // Could not calculate location and was not set explicitly.
            throw new BltException("Could not determine the secrets.settings.php remote location. Please define in BLT config 'secrets.settings-remote-location'");
        }
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
            'ac-site' => $acSite,
            'ac-env' => $acEnv,
            'stack' => preg_replace('/\D/', '', $acEnv),
            'stack-env' => preg_replace('/\d/', '', $acEnv)
        ];

        // Set a value for local.
        if ($facts['stack'] == "") { $facts['stack'] = "00";}

        return $facts;
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

        $environmentFacts = $this->getEnvironmentFacts($drushAlias);

        $extraVars = " --extra-vars 'drush_alias=" . $drushAlias . " \
        ac-site=" . $environmentFacts['ac-site'] . " \
        ac-env=" . $environmentFacts['ac-env'] . " \
        stack=" . $environmentFacts['stack'] . " \
        stack-env=" . $environmentFacts['stack-env'] . " \
        secret_vault_location=" . $secrets_vault_location . " \
        secret_template_location=" . $secrets_template_location . " \
        ";

        if (!strstr($drushAlias, 'local')) {
            $host = " -i " . $environments[$drushAlias]['host'] . ", -u " . $environments[$drushAlias]['user'];
            $extraVars .= "secret_location=" . $this->getSecretsRemoteLocation($drushAlias) . "' ";
        } else {
            $host = " -i 127.0.0.1";
            $extraVars .= "secret_location=" . $this->getConfigValue('repo.root') . '/docroot/sites/default/settings/secrets.settings.local' . " \
        localsettings_location=" . $this->getConfigValue('repo.root') . '/docroot/sites/default/settings/local.settings.php' . " \
        ansible_connection=local' ";
        }

        $command = "ansible-playbook " . $arguments . " " . $playbook . $host . $extraVars . $this->getVaultPasswordCommand();

        return $command;
    }
}
