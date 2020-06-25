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
   * The secrets PHP file name.
   *
   * @var string
   */
  protected $secretsPhpFile = 'secrets.settings.php';

  /**
   * The ansible vault base file name.
   *
   * @var string
   */
  protected $vaultFile = 'secrets_vault';

  /**
   * The BLT command to edit the secrets vault.
   *
   * @var string
   */
  protected $editCommand = 'blt secrets:edit';

  /**
   * The ansible playbook file name.
   * @var string
   */
  protected $playbookFile = 'deploy-secrets.yml';

  /**
   * The ansible playbook file name.
   *
   * @var string
   */
  protected $playbookConfigKey = 'secrets.playbook';

  /**
   * Path to the local secrets file.
   *
   * @var string
   */
  protected $localSecretsPath = '/docroot/sites/default/settings/secrets.settings.local';

  /**
   * Initializes default secrets configuration for this project.
   *
   * @command secrets:vault:init
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   */
  public function secretsInit() {

    if ($this->confirm("<error>Are you sure you want to initialise? This will overwrite any existing encrypted vaults</error>", FALSE)) {

      $result = $this->taskFilesystemStack()
        ->copy($this->getConfigValue('repo.root') . $this->pluginRoot . '/ansible/' . $this->secretsPhpFile .'.j2', $this->getConfigValue('repo.root') . '/secrets/' . $this->secretsPhpFile .'.j2', TRUE)
        ->copy($this->getConfigValue('repo.root') . $this->pluginRoot . '/ansible/.gitignore', $this->getConfigValue('repo.root') . '/secrets/.gitignore', TRUE)
        ->stopOnFail()
        ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
        ->run();

      if (!$result->wasSuccessful()) {
        throw new BltException("Could not initialize secrets configuration.");
      }

      if (file_exists($this->getConfigValue('repo.root') . '/secrets/.usekeychain') &&
          $this->confirm("Use vault password in keychain?")) {
        $password_command = $this->getVaultPasswordCommand();
      }
      else {
        $password_command = ' --ask-vault-pass';
      }
      $this->taskExec("ansible-vault encrypt " . $this->getConfigValue('repo.root') . "$this->pluginRoot/ansible/$this->vaultFile.yml $password_command --output secrets/$this->vaultFile")->run();

      $this->say("<info>A New ansible-vault and template were copied to your repository.</info>");
      $this->say("<info>Run '$this->editCommand' to edit your vault.</info>");
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
    $command = "ansible-vault edit secrets/$this->vaultFile" . $this->getVaultPasswordCommand();
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
    return unserialize(
      $this->taskDrush()
        ->Drush("sa --format=php")
        ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
        ->run()
        ->getMessage()
    );
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
    $defaultPlaybook = $this->getConfigValue('repo.root') . $this->pluginRoot . "/ansible/$this->playbookFile";
    $playbook = $this->getConfigValue($this->playbookConfigKey, $defaultPlaybook);

    $secret_vault_location = $this->getConfigValue('repo.root') . "/secrets/$this->vaultFile";
    $secret_template_location = $this->getConfigValue('repo.root') . "/secrets/$this->secretsPhpFile.j2";

    if (!strstr($drushAlias, 'local')) {
      $secret_location = '/mnt/files/' . $environments[$drushAlias]['ac-site'] . '.' . $environments[$drushAlias]['ac-env'] . '/'. $this->secretsPhpFile;

      $command = "ansible-playbook " . $arguments . " " . $playbook .
        " -i " . $environments[$drushAlias]['host'] . ", -u " . $environments[$drushAlias]['user'] . " \
        --extra-vars 'drush_alias=" . $drushAlias . " \
        secret_vault_location=" . $secret_vault_location . " \
        secret_template_location=" . $secret_template_location . " \
        secret_location=" . $secret_location . "' "
        . $this->getVaultPasswordCommand();
    } else {
      $secret_location = $this->getConfigValue('repo.root') . $this->localSecretsPath;
      $local_extra_vars = $this->getConfigValue('repo.root') . '/docroot/sites/default/settings/local.settings.php';

      $command = "ansible-playbook " . $arguments . " " . $playbook . " -i 127.0.0.1, \
        --extra-vars 'drush_alias=" . $drushAlias . " \
        secret_vault_location=" . $secret_vault_location . " \
        secret_template_location=" . $secret_template_location . " \
        secret_location=" . $secret_location . " \
        secret_location_dir=" . dirname($secret_location) . " \
        localsettings_location=" . $local_extra_vars . " \
        ansible_connection=local' "
        . $this->getVaultPasswordCommand();
    }

    return $command;
  }

}
