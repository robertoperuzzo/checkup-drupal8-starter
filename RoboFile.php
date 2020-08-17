<?php

define('OUTPUT_REDIRECTION', '>');

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Site\Settings;
use Robo\Exception\TaskException;

/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
class RoboFile extends \Robo\Tasks {

  use \Boedah\Robo\Task\Drush\loadTasks;

  const PROJECT_FOLDER = __DIR__;
  const DRUPAL_ROOT_FOLDER = self::PROJECT_FOLDER . '/web';
  const DATABASE_DUMP_FOLDER = self::PROJECT_FOLDER . '/backups';
  const CHECKUP_FOLDER = self::PROJECT_FOLDER . '/checkup';
  const REPORT_FILE_SECURITY = self::CHECKUP_FOLDER . '/security.out';

  /**
   * Site.
   *
   * @var string
   */
  protected $site = 'default';

  /**
   * Checup modules.
   *
   * @var array
   */
  protected $checkupModules = [
    'hacked',
    'security_review',
  ];

  /**
   * Run modules checkup.
   *
   * @command checkup:modules
   *
   * @return \Robo\Collection\CollectionBuilder
   */
  public function checkupModules() {
    $task_list = [
      // 'download-modules' => NULL,
      // 'run-checkup' => NULL,
      // 'cacheClear' => $this->initDrush()->clearCache(),
      'securityUpdates' => $this->initDrush()
        ->option('security-only')
        ->drush('ups'),
      'listEnabledModules'  => $this->initDrush()
        ->option('status=enabled')
        ->drush('pml'),
      'listDisabledModules'  => $this->initDrush()
        ->option('status="disabled,not installed"')
        ->drush('pml'),
      'enableModules' => $this->initDrush()
        ->drush(),
    ];
    $this->getBuilder()->addTaskList($task_list);
    return $this->getBuilder();
  }

  /**
   * Run security checkup.
   *
   * @command checkup:security
   *
   * @return \Robo\Collection\CollectionBuilder
   */
  public function checkupSecurity() {
    $task_list = [
      'cacheClear' => $this->initDrush()->clearCache(),
      'downloadModules' => $this->initDrush()
        ->arg('hacked')
        ->arg('security_review')
        ->drush('dl'),
      'enableModules' => $this->initDrush()
        ->arg('hacked')
        ->arg('security_review')
        ->drush('en'),
      'hacked'  => $this->initDrush()
        ->option('force-rebuild')
        ->drush('hlp'),
      'securityReview'  => $this->initDrush()
        ->option('full')
        ->option('store')
        ->drush('secrev'),
    ];
    $this->getBuilder()->addTaskList($task_list);
    return $this->getBuilder();
  }

  /**
   * Uninstall checkup's modules.
   *
   * @command checkup:uninstall
   *
   * @return \Robo\Collection\CollectionBuilder
   */
  public function checkupUninstall() {
    $task_list = [
      'disableModules' => NULL,
      'uninstallModules' => NULL,
      'deleteModules' => NULL,
      'cacheRebuild' => $this->initDrush()->drush('cache:rebuild'),
    ];
    $this->getBuilder()->addTaskList($task_list);
    return $this->getBuilder();
  }

  /**
   * Setup from database.
   *
   * @command install:database
   *
   * @param string $dump_file
   *
   * @return \Robo\Collection\CollectionBuilder
   */
  public function installDatabase($dump_file) {
    $task_list = [
      'sqlDrop' => $this->initDrush()
        ->drush('sql:drop'),
      'sqlCli' => $this->initDrush()
        ->arg("< $dump_file")
        ->drush('sql:cli'),
      'cacheClear' => $this->initDrush()->clearCache(),
    ];
    $this->getBuilder()->addTaskList($task_list);
    return $this->getBuilder();
  }

  /**
   * Compute various metrics.
   *
   * @command analyze:php
   */
  public function computeMetrics() {
    $this->taskExec('vendor/bin/phpqa')
      ->option('analyzedDirs', 'web/modules/custom')
      ->option('buildDir', 'reports')
      ->option('ignoredFiles', '*\\\.css,*\\\.md,*\\\.txt,*\\\.info,*\\\.yml')
      ->option(
        'tools',
        'phpcpd:0,phpcs:0,phpmd:0,phpmetrics,phploc,pdepend,security-checker,phpstan'
      )
      ->option('execution', 'no-parallel')
      ->option('report')
      ->run();
  }

  /**
   * Scaffold file for Drupal.
   *
   * Create the settings.php and service.yml from default file template or twig
   * twig template.
   *
   * @return $this
   *
   * @throws \Robo\Exception\TaskException
   */
  public function scaffold() {
    $base = self::DRUPAL_ROOT_FOLDER . "/sites/{$this->site}";
    $scaffold = self::PROJECT_FOLDER . "/scaffold";

    // Throws exception if dir site folder not exists.
    if (!file_exists($base)) {
      $this->io()->warning('No website found! You have to copy Drupal codebase in /web folder.');
      return NULL;
    }

    $settingsLocalSource = $scaffold . DIRECTORY_SEPARATOR . 'tpl.settings.local.php';
    $settingsOriginCopy = $scaffold . DIRECTORY_SEPARATOR . 'origin.settings.php';
    $settingsLocaldestination = $base . DIRECTORY_SEPARATOR . 'settings.local.php';
    $settingsFilePath = $base . DIRECTORY_SEPARATOR . 'settings.php';
    $settingsSourceAppend = $scaffold . DIRECTORY_SEPARATOR . 'tpl.settings.php';

    if (!file_exists($settingsOriginCopy)) {
      $this->taskFilesystemStack()
        ->copy($settingsFilePath, $settingsOriginCopy)
        ->run();
    }

    $this->getBuilder()->addTaskList([
      'add-settings-local-php' => $this->taskFilesystemStack()
        ->copy($settingsLocalSource, $settingsLocaldestination),
      'set-permission-default' =>  $this->taskFilesystemStack()
        ->chmod($base, 0755),
      'write-permission-settings-php' =>  $this->taskFilesystemStack()
        ->chmod($settingsFilePath, 0644),
      'append-settings-php' => $this->taskConcat([
        $settingsOriginCopy,
        $settingsSourceAppend,
      ])->to($settingsFilePath),
      'read-permission-settings-php' =>  $this->taskFilesystemStack()
        ->chmod($settingsFilePath, 0444),
    ]);

    return $this->getBuilder();
  }

  /**
   * Sets up drush defaults.
   *
   * @param string $site
   * @return \Boedah\Robo\Task\Drush\DrushStack
   */
  protected function initDrush($site = 'default') {
    return $this->taskDrushStack('/usr/local/bin/drush')
      ->drupalRootDirectory(self::DRUPAL_ROOT_FOLDER)
      ->uri($site);
  }

}
