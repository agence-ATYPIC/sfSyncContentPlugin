<?php

/*
 * This file is part of the sfSyncContentPlugin package
 * (c) 2009 P'unk Avenue LLC, www.punkave.com
 */

/**
 * @package    sfSyncContentPlugin
 * @subpackage Tasks
 * @author     Tom Boutell <tom@punkave.com>
 */

class sfMySqlDumpTask extends sfBaseTask
{
  protected function configure()
  {
    $this->addOptions(array(
      new sfCommandOption('application', null, sfCommandOption::PARAMETER_REQUIRED, 'The application name'),
      new sfCommandOption('env', null, sfCommandOption::PARAMETER_REQUIRED, 'The environment', 'dev'),
      new sfCommandOption('connection', null, sfCommandOption::PARAMETER_REQUIRED, 'The connection name', 'doctrine'),
      // add your own options here
      new sfCommandOption('ignore-tables', null, sfCommandOption::PARAMETER_OPTIONAL, 'Comma-separated list of tables from the remote database to ignore'),
    ));

    $this->namespace        = 'project';
    $this->name             = 'mysql-dump';
    $this->briefDescription = 'Outputs a MySQL SQL dump';
    $this->detailedDescription = <<<EOF
You must specify the application ("frontend") the environment ("dev", "staging", "production"),
and an optional database connection name ("doctrine"). If you do not specify a database connection name the first (usually only) database connection for the specified environment is used. This task is
primarily intended to be run remotely by the sync-content task.',

EOF;
  }

  protected function execute($args = array(), $options = array())
  {
    $conn = false;
    if (isset($args['connection']))
    {
      $conn = $args['connection'];
    }
    $dbparams = sfSyncContentTools::getDatabaseParams($this->configuration, $conn);
    $params = sfSyncContentTools::shellDatabaseParams($dbparams);

    // set up the ignore flag
    $ignoreFlag = '';
    if (isset($options['ignore-tables']) && !empty($options['ignore-tables']))
    {
        foreach(explode(',', $options['ignore-tables']) as $ignoreTable)
        {
            $ignoreFlag .= ' ' . escapeshellarg('--ignore-table=' . $dbparams['dbname'] . '.' . $ignoreTable) . ' ';
        }
    }

    // Right to stdout for the convenience of the remote ssh connection from
    // sync-content
    $command = "mysqldump --skip-opt --add-drop-table --create-options " .
      "--disable-keys --extended-insert --set-charset $params $ignoreFlag";
    system($command, $result);
    
    if ($result != 0)
    {
      throw new sfException("mysqldump failed");
    }
  }
}
