<?php
namespace Tk\Plugin;




/**
 * @author Michael Mifsud <info@tropotek.com>
 * @link http://www.tropotek.com/
 * @license Copyright 2016 Michael Mifsud
 */
class Factory
{

    /**
     * @var string
     */
    public static $STARTUP_CLASS = 'Plugin';

    /**
     * @var string
     */
    public static $DB_TABLE = 'plugin';

    /**
     * @var Factory
     */
    public static $instance = null;

    /**
     * Active plugins
     * @var array
     */
    protected $activePlugins = array();

    /**
     * @var \Tk\Db\Pdo
     */
    protected $db = null;

    /**
     * @var string
     */
    protected $pluginPath = '';

    /**
     * @var \Tk\Event\Dispatcher
     */
    protected $dispatcher = null;
    
    


    /**
     * constructor
     *
     * @param $db
     * @param $pluginPath
     * @param \Tk\Event\Dispatcher|null $dispatcher
     */
    protected function __construct($db, $pluginPath, $dispatcher = null)
    {
        $this->pluginPath = $pluginPath;
        $this->dispatcher = $dispatcher;

        $this->setDb($db);
        $this->initActivePlugins();
    }

    /**
     * Get an instance of this object
     *
     * @param $db
     * @param $pluginPath
     * @param \Tk\Event\Dispatcher|null $dispatcher
     * @return Factory
     */
    public static function getInstance($db, $pluginPath = '', $dispatcher = null)
    {
        if (self::$instance === null) {
            self::$instance = new static($db, $pluginPath, $dispatcher);
        }
        return self::$instance;
    }

    /**
     * Check the live site composer.json to see if this plugin
     * was installed by `composer update` or a archive install
     *
     *
     * @param $pluginName
     * @param \Composer\Autoload\ClassLoader $composer
     * @return bool
     */
    public static function isComposer($pluginName, $composer = null)
    {
        if (!$composer)
            $composer = \Tk\Config::getInstance()->getComposer();

        // Disable deletion of plugins that are installed via composer
        $result = call_user_func_array('array_merge', $composer->getPrefixes());
        foreach ($result as $item) {
            if (preg_match('/'.preg_quote($pluginName).'$/', $item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get Plugin instance
     * Can only be called if the plugin is active
     * Returns null otherwise...
     *
     * @param $pluginName
     * @return Iface|null
     */
    public function getPlugin($pluginName)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        if (isset($this->activePlugins[$pluginName]))
            return $this->activePlugins[$pluginName];
        return null;
    }

    /**
     * @return \Tk\Event\Dispatcher
     */
    public function getDispatcher()
    {
        return $this->dispatcher;
    }

    /**
     * Install the plugin tables
     */
    protected function install()
    {
        
        if (!$this->getDb()->tableExists($this->getTable())) {
            $tbl = $this->getDb()->quoteParameter($this->getTable());

            $sql = '';
            if ($this->getDb()->getDriver() == 'mysql') {
                $sql = <<<SQL
CREATE TABLE IF NOT EXISTS $tbl (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(128) NOT NULL,
  `version` VARCHAR(16) NOT NULL,
  `created` TIMESTAMP NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`name`)
) ENGINE=InnoDB;
SQL;
            } else if ($this->getDb()->getDriver() == 'pgsql') {
                $sql = <<<SQL
CREATE TABLE IF NOT EXISTS $tbl (
  id SERIAL PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  version VARCHAR(16) NOT NULL,
  created TIMESTAMP NOT NULL,
  CONSTRAINT plugin_name UNIQUE (name)
);
SQL;
            } else if ($this->getDb()->getDriver() == 'sqlite') {
                $sql = <<<SQL
CREATE TABLE IF NOT EXISTS $tbl (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  version TEXT NOT NULL,
  created TIMESTAMP NOT NULL,
  UNIQUE (name)
);
SQL;
            }

            $this->getDb()->exec($sql);

            if ($this->getDispatcher()) {
                $event = new \Tk\Event\Event();
                $event->set('plugin.factory', $this);
                $this->getDispatcher()->dispatch(Events::INSTALL, $event);
            }
        }
        return $this;
    }

    /**
     * Activate and install the plugin
     * Calling the plugin activate method
     *
     * @param string $pluginName
     * @return bool
     * @throws Exception
     */
    public function activatePlugin($pluginName)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        if ($this->isActive($pluginName))
            throw new Exception ('Plugin currently active.');

        $info = $this->getPluginInfo($pluginName);
        $version = '0.0.0';
        if (!empty($info->version)) $version = $info->version;

        if ($this->getDispatcher()) {
            $event = new \Tk\Event\Event();
            $event->set('pluginName', $pluginName);
            $event->set('info', $info);
            $this->getDispatcher()->dispatch(Events::ACTIVATE, $event);
        }

        // Activate plugin by database entry
        $this->dbActivate($pluginName, $version);
        
        $plugin = $this->makePluginInstance($pluginName);
        if ($plugin) {
            try {
                $plugin->doActivate();
                $this->activePlugins[$pluginName] = $plugin;
            } catch (\Exception $e) {
                $this->dbDeactivate($pluginName);
                vd($e->__toString());
                throw new Exception($e->getMessage(), $e->getCode(), $e);
            }
        }
        return true;
    }

    /**
     * deactivate the plugin calling the deactivate method
     *
     * @param string $pluginName
     * @return bool
     * @throws Exception
     */
    public function deactivatePlugin($pluginName)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        if (!$this->isActive($pluginName))
            throw new Exception ('Plugin currently inactive.');

        /* @var Iface $plugin */
        if (!empty($this->activePlugins[$pluginName])) {
            $plugin = $this->activePlugins[$pluginName];
            if (!$plugin) return false;

            if ($this->dispatcher) {
                $event = new \Tk\Event\Event();
                $event->set('plugin', $plugin);
                $this->dispatcher->dispatch(Events::DEACTIVATE, $event);
            }
            $version = '0.0.0';
            if (!empty($plugin->getInfo()->version)) $version = $plugin->getInfo()->version;

            try {
                $plugin->doDeactivate();

                $this->dbDeactivate($pluginName);
                unset($this->activePlugins[$pluginName]);
            } catch (\Exception $e) {
                $this->dbActivate($pluginName, $version);
                vd($e->__toString());
                throw new Exception($e->getMessage(), $e->getCode(), $e);
            }
        }
        return true;
    }

    protected function dbActivate($pluginName, $version = '0.0.0')
    {
        $pluginName = $this->cleanPluginName($pluginName);
        $sql = sprintf('INSERT INTO %s VALUES (NULL, %s, %s, NOW())',
            $this->getDb()->quoteParameter($this->getTable()), $this->getDb()->quote($pluginName), $this->getDb()->quote($version));
        $this->getDb()->query($sql);
    }

    protected function dbDeactivate($pluginName)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        $sql = sprintf('DELETE FROM %s WHERE name = %s', $this->getDb()->quoteParameter($this->getTable()), $this->getDb()->quote($pluginName));
        $this->getDb()->query($sql);
    }
    
    /**
     * Registration adds the plugin to the list of plugins, and also
     * includes it's code into our runtime.
     *
     */
    protected function initActivePlugins()
    {
        $available = $this->getAvailablePlugins();
        $this->activePlugins = array();
        foreach ($available as $pluginName) {
            if (!$this->isActive($pluginName)) continue;
            $plugin = $this->makePluginInstance($pluginName);
            if ($plugin) {
                $plugin->doInit();
                $this->activePlugins[$pluginName] = $plugin;
            }
        }
        return $this->activePlugins;
    }

    /**
     * @param $pluginName
     * @return Iface
     * @throws Exception
     */
    protected function makePluginInstance($pluginName)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        if (!$this->isActive($pluginName)) {
            throw new Exception('Cannot instantiate an inactive plugin: ' . $pluginName);
        }
        $class = $this->makePluginClassname($pluginName);
        if (!class_exists($class)){
            $pluginInclude = $this->getPluginPath($pluginName) . '/' . self::$STARTUP_CLASS . '.php';
            if (!is_file($pluginInclude)) {
                $this->deactivatePlugin($pluginName);
                throw new Exception('Cannot locate plugin file. You may need to run `composer update` to fix this.');
            }
            include_once $pluginInclude;
        }
        
        $data = $this->getDbPlugin($pluginName);
        /* @var Iface $plugin */
        $plugin = new $class($data->id, $pluginName);
        if (!$plugin instanceof Iface) {
            throw new Exception('Plugin class uses the incorrect interface: ' . $class);
        }
        $plugin->setPluginFactory($this);
        return $plugin;
    }

    /**
     * getAvailablePlugins
     *
     * @return array
     */
    public function getAvailablePlugins()
    {
        $fileList = array();
        if (is_dir($this->getPluginPath())) {
            $fileList = scandir($this->getPluginPath());
            foreach ($fileList as $i => $plugPath) {
                if (preg_match('/^(\.|_)/', $plugPath) || !is_dir($this->getPluginPath($plugPath))) {
                    unset($fileList[$i]);
                }
            }
        }
        return array_merge($fileList);
    }

    /**
     * The plugin main executable class name is made using this function
     *
     * @param string $pluginName
     * @return string
     */
    public function makePluginClassname($pluginName)
    {
        $pluginName = trim($this->cleanPluginName($pluginName));
        // this may need to be made into a callback per plugin for custom configs?
        if (!empty($this->getPluginInfo($pluginName)->autoload->{'psr-0'})) {
            $ns = current(array_keys(get_object_vars($this->getPluginInfo($pluginName)->autoload->{'psr-0'})));
            $class = '\\' . $ns . self::$STARTUP_CLASS;
            if (class_exists($class)) return $class;        // Return the composer class name
        }
        $ns = preg_replace('/[^a-z0-9]/', '-', $pluginName);
        if (strstr($ns, '-') !== false) {
            $ns =  substr($ns, strrpos($ns, '-')+1);
        }
        return '\\' . $ns . '\\' . self::$STARTUP_CLASS;    // Used for non-composer packages (remember to include all required files in your plugin)
    }

    /**
     * Get the main path for the plugin
     *
     * @param $pluginName
     * @return string
     */
    public function getPluginPath($pluginName = '')
    {
        $pluginName = $this->cleanPluginName($pluginName);
        if (!$pluginName) return $this->pluginPath;
        return $this->pluginPath . '/' . trim($pluginName, '/');
    }

    /**
     * Get Plugin Meta Data
     *
     * @param $pluginName
     * @return \stdClass
     */
    public function getPluginInfo($pluginName)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        $file = $this->getPluginPath($pluginName) . '/composer.json';
        if (is_readable($file))
            return json_decode(file_get_contents($file));
        // info not found return a default info object
        $info = new \stdClass();
        $info->name = 'ttek-plg/' . $pluginName;
        $info->version = '0.0.1';
        $info->time = \Tk\Date::create()->format(\Tk\Date::FORMAT_ISO_DATE);
        if (is_dir(dirname($file))) {
            $info->time = \Tk\Date::create(filectime(dirname($file)))->format(\Tk\Date::FORMAT_ISO_DATE);
        }
        return $info;
    }

    /**
     *
     * @param string $pluginName
     * @return bool
     */
    public function isActive($pluginName)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        $sql = sprintf('SELECT * FROM %s WHERE name = %s', $this->getDb()->quoteParameter($this->getTable()), $this->getDb()->quote($pluginName));
        $res = $this->getDb()->query($sql);
        if ($res->rowCount() > 0) {
            return true;
        }
        return false;
    }

    /**
     *
     * @param string $pluginName
     * @return \StdClass
     */
    public function getDbPlugin($pluginName)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        $sql = sprintf('SELECT * FROM %s WHERE name = %s', $this->getDb()->quoteParameter($this->getTable()), $this->getDb()->quote($pluginName));
        $res = $this->getDb()->query($sql);
        return $res->fetch();
    }

    /**
     * @param $pluginName
     * @return mixed
     */
    public function cleanPluginName($pluginName)
    {
        if (strstr($pluginName, '/')) {
            $pluginName = substr($pluginName, strrpos($pluginName,'/')+1);
        }
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $pluginName);
    }

    /**
     * Get the table name for queries
     *
     * @return string
     */
    protected function getTable()
    {
        return self::$DB_TABLE;
    }

    /**
     * @return \Tk\Db\Pdo
     */
    public function getDb()
    {
        return $this->db;
    }

    /**
     * @param \Tk\Db\Pdo $db
     * @return $this
     */
    public function setDb($db)
    {
        $this->db = $db;
        $this->install();
        return $this;
    }


    /**
     * @return \Tk\Config
     */
    public function getConfig()
    {
        return \Tk\Config::getInstance();
    }

}