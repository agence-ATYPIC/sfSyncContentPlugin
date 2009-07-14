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

class sfSyncContentTask extends sfBaseTask
{
  protected function configure()
  {
    $this->addArguments(array(
      new sfCommandArgument('application', 
        sfCommandArgument::REQUIRED, 
        'The application name ("frontend")'),
      new sfCommandArgument('localenv', 
        sfCommandArgument::REQUIRED, 
        'The local environment and optional database connection name ("dev" or "dev:doctrine")'),
      new sfCommandArgument('direction', 
        sfCommandArgument::REQUIRED, 
        'Either "from" or "to"; when you specify "from" content is copied FROM the remote site, when you specify "to" content is copied TO the remote site'),
      new sfCommandArgument('remoteenv',
        sfCommandArgument::REQUIRED, 
        'The remote environment and site, with an optional database connection name ("dev@staging" or "prod@production:propel"). The site name must be defined in properties.ini')));

    $this->namespace        = 'project';
    $this->name             = 'sync-content';
    $this->briefDescription = 'Synchronize content (not code) between Symfony instances';
    $this->detailedDescription = <<<EOF
You must specify the application ("frontend"), local environment and an optional database connection name ("dev" or "dev:doctrine"), "to" or "from", and the remote database connection name, environment and site ("dev@staging:doctrine", "prod@production:doctrine", etc). If you do not specify a database connection name the first (usually only) database connection in databases.yml for the specified environment is used. In addition to the database, data folders listed at app_sfSyncContentPlugin_content will also be synced.',

EOF;
  }

  protected function execute($args = array(), $options = array())
  {

    /**
    * syncs your content (not code) to or from the production or staging server.
    * That means syncing two things: the database and any data folders that
    * have been configured via app.yml.
    */

    if (count($args) != 5)
    {
      throw new sfException('You must specify the application ("frontend"), the local environment and an optional database connection name ("dev" or "dev:doctrine"), "to" or "from", and the remote database connection name, environment and site ("dev@staging:doctrine", "prod@production:doctrine", etc). If you do not specify a database connection name the first (usually only) database connection in databases.yml for the specified environment is used.');
    }
    
    $settings = parse_ini_file("config/properties.ini", true);
    if ($settings === false)
    {
      throw new sfException("You must be in a symfony project directory");
    }
    $application = $args['application'];
    $this->checkAppExists($application);
    $direction = $args['direction'];
    if (($direction != 'to') && ($direction != 'from'))
    {
      throw new sfException("The third argument must be either 'to' or 'from'");
    }  

    if (!preg_match('/^(.*)\@(.*)(:(\w+))?$/', $args['remoteenv'], $matches))
    {
      throw new sfException("Fourth argument must be of the form environment@site with an optional database connection name, example: dev@staging or dev@staging:propel; the site must be defined in properties.ini");
    }
    $envRemote = $matches[1];
    $site = $matches[2];
    $connectionNameRemote = $matches[4];
    if (!isset($connectionNameRemote))
    {
      $connectionNameRemote = false;
    }
    
    $found = false;
    foreach ($settings as $section => $data)
    {
      if ($site == $section)
      {
        $found = true;
        break;   
      }
    }

    if (!$found)
    {
      throw new sfException("Fourth argument must be of the form environment@site with an optional database connection name, example: dev@staging or dev@staging:propel; the site must be defined in properties.ini");
    }

    if (!preg_match('/^(\w+)(:(\w+))?$/', $args['localenv'], $matches))
    {
      throw new sfException("Second argument must be an environment name followed, optionally, by a database connection name. Example #1: dev Example #2: prod:propel");
    }
    $env = $matches[1];
    $connectionName = $matches[2];
    if (!isset($connectionName))
    {
      $connectionName = false;
    }
    $pathLocal = '.';
    $pathRemote = $data['user'] . '@' . $data['host'] . ':' . $data['dir'];

    $dbDataLocal = $this->_content_sync_get_db_data($application, $pathLocal, $env, $connectionName);
    $dbDataRemote = $this->_content_sync_get_db_data($application, $pathRemote, $envRemote, $connectionNameRemote);

    $stamp = date('Y-m-d-H-i-s', time());
    $cmd = "mysqldump --skip-opt --add-drop-table --create-options " .
      "--disable-keys --extended-insert --set-charset " . 
      $this->_content_sync_format_db_credentials(
      ($direction == 'to') ? $dbDataLocal : $dbDataRemote);
    if ($direction == 'from')
    {
      $cmd = $this->_content_sync_build_remote_cmd($pathRemote, $cmd);
    }
    $cmd .= " > sqldump.$stamp"; 
    $this->_content_sync_system($cmd);

    if ($direction == 'to')
    {
      $this->_content_sync_rsync("sqldump.$stamp", $pathRemote . "/sqldump.$stamp");
    }
    $cmd = "mysql " . $this->_content_sync_format_db_credentials(
      ($direction == 'to') ? $dbDataRemote : $dbDataLocal);
    
    if ($direction == 'to')
    {
      $this->_content_sync_remote_system($pathRemote, "$cmd < sqldump.$stamp");
    }
    else
    {
      $this->_content_sync_system("$cmd < sqldump.$stamp");
    }
    $asData = sfConfig::get('app_sfSyncContent_content',
      array());
    foreach ($asData as $path)
    {
      if ($direction == 'to')
      {
        $from = "$pathLocal/$path";
        $to = "$pathRemote/$path";
      }
      else
      {
        $to = "$pathLocal/$path";
        $from = "$pathRemote/$path";
      }
      $to = dirname($to);
      $this->_content_sync_rsync($from, $to);
    }
    unlink("sqldump.$stamp");
  }

  function _content_sync_rsync($path1, $path2)
  {
    $this->_content_sync_system("rsync -azC --force --delete --progress " . escapeshellarg($path1) . " " . escapeshellarg($path2));
  }

  function _content_sync_format_db_credentials($dbData)
  {
    return "--user=" . escapeshellarg($dbData['user']) . 
      " --password=" .  escapeshellarg($dbData['password']) . 
      " -h " . escapeshellarg($dbData['host']) .
      " " . $dbData['database'];
  }

  function _content_sync_file_get_contents($path, $file)
  {
    if (!preg_match("/^(\S+\@\S+)\:(.*)$/", $path, $args))
    {
      // Local, easy-peasy
      if (file_exists("$path/$file"))
      {
        return file_get_contents("$path/$file");
      }
      throw new sfException("File $path/$file not found");
    }
    // Not too hard either
    $cmd = $this->_content_sync_build_remote_cmd($path, "cat " . escapeshellarg($file));
    echo("cmd is $cmd\n");
    $in = popen($cmd, "r");
    $data = stream_get_contents($in);
    // Note that this doesn't really mean the file doesn't exist;
    // it means the whole remote operation failed
    if ($data === false)
    {
      throw new sfException("Read from remote command $cmd failed");
    }    
    pclose($in);
    return $data;
  }

  function _content_sync_get_db_data($application, $path, $env, $connectionName = false)
  {
    // Symfony 1.2 will use an application-specific databases.yml
    // if there is one; we need to do that too
    
    // This isn't the best error check imaginable; we don't capture
    // errors from a remote 'cat' command yet. It works for our
    // limited purposes here
    try
    {
      $data = $this->_content_sync_file_get_contents($path, "apps/$application/config/databases.yml");
    } catch (Exception $e)
    {
      $data = false;
    }
    if (!strlen($data))
    {
      $data = $this->_content_sync_file_get_contents($path, "config/databases.yml");
    }
    $dbSettings = sfYaml::load($data);
    if (!$dbSettings)
    {
      throw new sfException("Unable to load databases.yml from $path");
    }
   
    $dbData = array();
    if (isset($dbSettings['all']))
    {
      $dbData = $dbSettings['all'];
    }
    if (isset($dbSettings[$env]))
    {
      $dbData = array_merge($dbData, $dbSettings[$env]);
    }
    if ($connectionName === false)
    {
      foreach ($dbData as $connectionName => $connection)
      {
        // Grab the first one
        break;
      }
    }
    else
    {
      if (!isset($dbData[$connectionName]))
      {
        throw new sfException("Specified db connection $connectionName does not appear in config/databases.yml");
      }
      $connection = $dbData[$connectionName];
    }
    if (!strlen($connectionName))
    {
      throw new sfException("No db connections configured in config/databases.yml");
    }    
    if (!isset($connection['class']))
    {
      throw new sfException("db connection without a class attribute encountered");
    }
    if ($connection['class'] === 'sfPropelDatabase')
    {
      if (isset($connection['param']['dsn']))
      {
        if (!preg_match("/^mysql\:\/\/(.*)?:(.*)?\@(.*?)\/(.*?)\s*$/", $dbData['propel']['param']['dsn'], $matches))
        {
          throw new sfException("dsn is incorrect or not a MySQL dsn");
        }
        $dbData = array();
        list($dummy, $user, $password, $host, $database) = $matches;
      } 
      elseif (isset($connection['param']['username']))
      {
        if ($connection['param']['phptype'] != 'mysql')
        {
          throw new sfException("phptype is not set to MySQL");
        }  
        $user = $connection['param']['username'];
        $password = $connection['param']['password'];
        $host = $connection['param']['host'];
        $database = $connection['param']['database'];
      }
      else
      {
        throw new sfException("There is no dsn or username setting for $env or all in properties.ini");
      }  
    }
    elseif ($connection['class'] === 'sfDoctrineDatabase')
    {
      if (!isset($connection['param']['dsn']))
      {
        throw new sfException("dsn is not set");
      }
      $dsn = $connection['param']['dsn'];
      if (!preg_match("/^mysql\:(.*)$/", $dsn, $matches))
      {
        throw new sfException("dsn must be a MySQL dsn");
      }
      $rawArgs = explode(";", $matches[1]);
      $args = array();
      foreach ($rawArgs as $rawArg)
      {
        list($key, $val) = explode("=", $rawArg);
        $args[$key] = $val;
      }
      if (!isset($args['dbname']))
      {
        throw new sfException("dsn has no dbname");
      }
      if (!isset($args['host']))
      {
        throw new sfException("dsn has no host");
      }
      $database = $args['dbname'];
      $host = $args['host'];
      $user = $connection['param']['username']; 
      $password = $connection['param']['password']; 
    }

    return array(
      "user" => $user,
      "password" => $password,
      "host" => $host,
      "database" => $database
    );
  }

  function _content_sync_build_remote_cmd($pathRemote, $cmd)
  {
    if (preg_match("/^(.*?\@.*?)\:(.*)$/", $pathRemote, $args))
    {
      $auth = $args[1];
      $path = $args[2];
      $cmd = "ssh $auth " . escapeshellarg("(cd " . escapeshellarg($path) . 
        "; " . $cmd . ")");
      return $cmd;
    }
    else
    {
      echo("we received $pathRemote $cmd\n");
      exit(0);
    }
  }

  function _content_sync_remote_system($pathRemote, $cmd)
  {
    return $this->_content_sync_system($this->_content_sync_build_remote_cmd($pathRemote, $cmd));
  }

  function _content_sync_system($cmd)
  {
    echo("Executing $cmd\n");
    system($cmd, $result);
    if ($result != 0)
    {
      throw new sfException("Command $cmd failed, halting");
    }    
  }
}
