<?php

namespace Acquia\SecretsManagement\Blt\Plugin\Commands;

use Acquia\Blt\Robo\Exceptions\BltException;
use Robo\Contract\VerbosityThresholdInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Defines commands in the "secrets:simplesamlphp" namespace.
 */
class SimplesamlphpSecretsCommands extends SecretsCommands {

  /**
   * {@inheritdoc}
   */
  protected $secretsPhpFile = 'simplesamlphp.secrets.php';

  /**
   * {@inheritdoc}
   */
  protected $vaultFile = 'simplesamlphp_secrets_vault';

  /**
   * @var string
   */
  protected $editCommand = 'blt secrets:simplesamlphp:edit';

  /**
   * {@inheritdoc}
   */
  protected $playbookFile = 'deploy-simplesamlphp-secrets.yml';

  /**
   * {@inheritdoc}
   */
  protected $playbookConfigKey = 'secrets.simplesamlphp.playbook';

  /**
   * {@inheritdoc}
   */
  protected $localSecretsPath = '/scripts/simplesamlphp/secrets.php.local';

  /**
   * Initializes simplesamlphp secrets configuration for this project.
   *
   * @command secrets:simplesamlphp:vault:init
   */
  public function secretsInit() {
    parent::secretsInit();
  }

  /**
   * Edit the simplesamlphp vault file.
   *
   * @command secrets:simplesamlphp:edit
   * @aliases sesed
   */
  public function secretsEdit() {
    parent::secretsEdit();
  }

  /**
   * {@inheritdoc}
   *
   * @command secrets:simplesamlphp:diff
   * @aliases sesdf
   */
  public function secretsDiff($drushAlias) {
    parent::secretsDiff($drushAlias);
  }

  /**
   * {@inheritdoc}
   *
   * @command secrets:simplesamlphp:deploy
   * @aliases sesdp
   */
  public function secretsDeploy($drushAlias) {
    parent::secretsDeploy($drushAlias);
  }

  /**
   * Appends include for the secrets to the acquia_config.php file.
   *
   * @command secrets:simplesamlphp:acquia_config:include
   * @aliases sesaci
   */
  public function appendToAcquiaConfig() {
    $repo_root = $this->getConfigValue('repo.root');
    $config_file = $repo_root . '/simplesamlphp/config/acquia_config.php';
    if (!file_exists($config_file)) {
      throw new BltException("<error>File $config_file not found. Make sure that you have run 'blt recipes:simplesamlphp:init'.</error>");
    }
    // Local include path is relative to
    // vendor/simplesamlphp/simplesamlphp/config.
    $fs = new Filesystem();
    $local_include = rtrim($fs->makePathRelative($repo_root . $this->localSecretsPath, $repo_root . '/vendor/simplesamlphp/simplesamlphp/config'), '/');
    $include_text = <<<EOT
if (file_exists('$local_include')) {
  // If the local simplesamlphp secrets file exists, include it.
  include '$local_include';
}
elseif (file_exists('/mnt/files/' . getenv('AH_SITE_GROUP') . '.' . getenv('AH_SITE_ENVIRONMENT') . '/simplesamlphp.secrets.php')) {
  // Otherwise include the cloud simplesamlphp secrets file if it exists.
  include '/mnt/files/' . getenv('AH_SITE_GROUP') . '.' . getenv('AH_SITE_ENVIRONMENT') . '/simplesamlphp.secrets.php';
}
EOT;

    $result = $this->taskWriteToFile($config_file)
      ->text($include_text)
      ->append()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
      ->run();
    if (!$result->wasSuccessful()) {
      throw new BltException("Unable modify $config_file.");
    }
    else {
      $this->output->writeln('Secrets file include appended to acquia_config.php. You may need to manually edit the file to work correctly.');
    }
  }

}
