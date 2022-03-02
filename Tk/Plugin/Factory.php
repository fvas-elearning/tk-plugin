<?php
namespace Tk\Plugin;

use Tk\ConfigTrait;
use Tk\EventDispatcher\EventDispatcher;

/**
 * @author Michael Mifsud <info@tropotek.com>
 * @see http://www.tropotek.com/
 * @license Copyright 2016 Michael Mifsud
 */
class Factory
{
    use ConfigTrait;

    /**
     * @var string
     */
    public static $STARTUP_CLASS = 'Plugin';

    /**
     * @var string
     */
    public static $DB_TABLE = '_plugin';

    /**
     * @var Factory
     */
    public static $instance = null;

    /**
     * Active plugins
     * @var array|Iface[]
     */
    protected $activePlugins = array();

    /**
     * @var array|Iface[]
     */
    protected $zonePlugins = array();

    /**
     * @var \Tk\Db\Pdo
     */
    protected $db = null;

    /**
     * @var string
     */
    protected $pluginPath = '';

    /**
     * @var EventDispatcher
     */
    protected $dispatcher = null;


    /**
     * constructor
     *
     * @param $db
     * @param $pluginPath
     * @param EventDispatcher|null $dispatcher
     */
    protected function __construct($db, $pluginPath, $dispatcher = null)
    {
        $this->pluginPath = $pluginPath;
        $this->dispatcher = $dispatcher;

        try {
            $this->setDb($db);
            $this->initActivePlugins();
        } catch (\Tk\Db\Exception $e) {
        } catch (Exception $e) {
            \Tk\Alert::addError($e->getMessage());
            \Tk\Log::warning($e->__toString());
        }
    }

    /**
     * Get an instance of this object
     *
     * @param $db
     * @param string $pluginPath
     * @param EventDispatcher|null $dispatcher
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
     * @return EventDispatcher
     */
    public function getDispatcher()
    {
        return $this->dispatcher;
    }

    /**
     * Install the plugin tables
     * @throws \Tk\Db\Exception
     */
    protected function install()
    {
        
        if (!$this->getDb()->hasTable($this->getTable())) {
            $tbl = $this->getDb()->quoteParameter($this->getTable());
            $zoneTable = $this->getDb()->quoteParameter($this->getZoneTable());

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
CREATE TABLE IF NOT EXISTS $zoneTable (
  plugin_name VARCHAR(128) NOT NULL,
  zone_name VARCHAR(128) NOT NULL,
  zone_id INT(10) NOT NULL DEFAULT 0,
  PRIMARY KEY (plugin_name, zone_name, zone_id)
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
CREATE TABLE IF NOT EXISTS $zoneTable (
  plugin_name VARCHAR(128) NOT NULL,
  zone_name VARCHAR(128) NOT NULL,
  zone_id INT(10) NOT NULL DEFAULT 0,
  PRIMARY KEY (plugin_name, zone_name, zone_id)
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
CREATE TABLE IF NOT EXISTS $zoneTable (
  plugin_name VARCHAR(128) NOT NULL,
  zone_name VARCHAR(128) NOT NULL,
  zone_id INT(10) NOT NULL DEFAULT 0,
  PRIMARY KEY (plugin_name, zone_name, zone_id)
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
     * @throws \Tk\Db\Exception
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
     * @throws \Tk\Db\Exception
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

//            try {
                $plugin->doDeactivate();
                $this->dbDeactivate($pluginName);
                unset($this->activePlugins[$pluginName]);
//            } catch (\Exception $e) {
//                //$this->dbActivate($pluginName, $version);
//                //vd($e->__toString());
//                throw new Exception($e->getMessage(), $e->getCode(), $e);
//            }
        }
        return true;
    }

    /**
     * @param $pluginName
     * @param string $version
     * @throws \Tk\Db\Exception
     */
    protected function dbActivate($pluginName, $version = '0.0.0')
    {
        $pluginName = $this->cleanPluginName($pluginName);
        $sql = sprintf('INSERT INTO %s VALUES (NULL, %s, %s, NOW())',
            $this->getDb()->quoteParameter($this->getTable()), $this->getDb()->quote($pluginName), $this->getDb()->quote($version));
        $this->getDb()->query($sql);
    }

    /**
     * @param $pluginName
     * @param string $version
     * @throws \Tk\Db\Exception
     */
    protected function dbUpgrade($pluginName, $version = '0.0.0')
    {
        $pluginName = $this->cleanPluginName($pluginName);
        $sql = sprintf('UPDATE %s SET version = %s WHERE name = %s',
            $this->getDb()->quoteParameter($this->getTable()), $this->getDb()->quote($version), $this->getDb()->quote($pluginName));
        $this->getDb()->query($sql);
    }

    /**
     * @param $pluginName
     * @throws \Tk\Db\Exception
     */
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
     * @return array|Iface[]
     * @throws Exception
     * @throws \Tk\Db\Exception
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
     * @throws \Tk\Db\Exception
     */
    protected function makePluginInstance($pluginName)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        if (!$this->isActive($pluginName)) {
            throw new Exception('Cannot instantiate an inactive plugin: ' . $pluginName);
        }

        $pluginConfig = $this->getPluginPath($pluginName) . '/config.php';
        if (is_file($pluginConfig)) {
            include_once $pluginConfig;
        };
        $pluginInclude = $this->getPluginPath($pluginName) . '/' . self::$STARTUP_CLASS . '.php';
        if (is_file($pluginInclude)) {
            include_once $pluginInclude;
        }
        $class = $this->makePluginClassname($pluginName);

//        if (!class_exists($class)){
//            $pluginInclude = $this->getPluginPath($pluginName) . '/' . self::$STARTUP_CLASS . '.php';
//            if (!is_file($pluginInclude)) {
//                $this->deactivatePlugin($pluginName);
//                throw new Exception('Cannot locate plugin file. You may need to run `composer update` to fix this.');
//            } else {
//                include_once $pluginInclude;
//            }
//        }
        
        $data = $this->getDbPlugin($pluginName);
        /* @var Iface $plugin */
        $plugin = new $class($data->id, $pluginName);
        if (!$plugin instanceof Iface) {
            throw new Exception('Plugin class uses the incorrect interface: ' . $class);
        }
        $plugin->setPluginFactory($this);

        // If all ok, check if the plugin needs to be upgraded.
        if (version_compare($plugin->getInfo()->version, $data->version, '>')) {
            //vd('Upgrade: ' . $data->version . ' => ' . $plugin->getInfo()->version);
            $plugin->doUpgrade($data->version, $plugin->getInfo()->version);
            $this->dbUpgrade($pluginName, $plugin->getInfo()->version);
        }

        return $plugin;
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
        $class = '\\' . $ns . '\\' . self::$STARTUP_CLASS;    // Used for non-composer packages (remember to include all required files in your plugin)
        return $class;
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
     * Get Plugin Meta Data from the composer.json file if one exists
     *
     * @param $pluginName
     * @return \stdClass
     */
    public function getPluginInfo($pluginName)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        $file = $this->getPluginPath($pluginName) . '/composer.json';
        if (is_readable($file)) {
            $info = json_decode(file_get_contents($file));
        } else {
            // Info not found return a default info object
            // TODO: should we get this onfo from another place controllable by the plugin, ie an ini file or static method???
            $info = new \stdClass();
            $info->name = 'ttek-plg/' . $pluginName;
            $info->version = '0.0.1';
            $info->time = \Tk\Date::create()->format(\Tk\Date::FORMAT_ISO_DATETIME);
            if (is_dir(dirname($file))) {
                $info->time = \Tk\Date::create(filectime(dirname($file)))->format(\Tk\Date::FORMAT_ISO_DATETIME);
            }
        }
        if (!isset($info->version)) {
            $verFile = $this->getPluginPath($pluginName) . '/version.md';
            if (is_readable($verFile)) {
                $info->version = file_get_contents($verFile);
            }
        }
        if (!isset($info->version)) {
            $info->version = '0.0.1';
        }
        return $info;
    }

    /**
     *
     * @param string $pluginName
     * @return bool
     * @throws \Tk\Db\Exception
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
     * @throws \Tk\Db\Exception
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
     * @throws \Tk\Db\Exception
     */
    public function setDb($db)
    {
        $this->db = $db;
        $this->install();
        return $this;
    }

    /**
     * Get the table name for queries
     *
     * @return string
     */
    protected function getZoneTable()
    {
        return $this->getTable().'_zone';
    }


    /**
     * @param Iface $plugin
     * @param $zoneName
     * @return $this
     */
    public function registerZonePlugin(Iface $plugin, $zoneName)
    {
        if (!isset($this->zonePlugins[$zoneName])) {
            $this->zonePlugins[$zoneName] = array();
        }
        $this->zonePlugins[$zoneName][$plugin->getName()] = $plugin;
        return $this;
    }

    /**
     * @param $zoneName
     * @return array|Iface[]
     */
    public function getZonePluginList($zoneName)
    {
        if (isset($this->zonePlugins[$zoneName]))
            return $this->zonePlugins[$zoneName];
        return array();
    }

    /**
     * Find all the enabled zone ids for a plugin
     *
     * @param string $pluginName
     * @param string $zoneName
     * @return array|\stdClass
     * @throws \Exception
     */
    public function getPluginZoneIdList($pluginName, $zoneName)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        $zoneName = $this->cleanPluginName($zoneName);

        $sql = sprintf('SELECT * FROM %s WHERE plugin_name = %s AND zone_name = %s',
            $this->getDb()->quoteParameter($this->getZoneTable()),
            $this->getDb()->quote($pluginName), $this->getDb()->quote($zoneName));
        $res = $this->getDb()->query($sql);
        return $res->fetchAll();
    }

    /**
     *
     * @param string $pluginName
     * @param string $zoneName
     * @param string $zoneId
     * @return bool
     * @throws \Tk\Db\Exception
     */
    public function isZonePluginEnabled($pluginName, $zoneName, $zoneId)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        $zoneName = $this->cleanPluginName($zoneName);

        $sql = sprintf('SELECT * FROM %s WHERE plugin_name = %s AND zone_name = %s AND zone_id = %d ',
            $this->getDb()->quoteParameter($this->getZoneTable()),
            $this->getDb()->quote($pluginName), $this->getDb()->quote($zoneName), (int)$zoneId);
        $res = $this->getDb()->query($sql);
        return ($res->rowCount() > 0);
    }

    /**
     *
     * @param string $pluginName
     * @param string $zoneName
     * @param string $zoneId
     * @return $this
     * @throws \Tk\Db\Exception
     */
    public function enableZonePlugin($pluginName, $zoneName, $zoneId)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        $zoneName = $this->cleanPluginName($zoneName);

        if ($this->isZonePluginEnabled($pluginName, $zoneName, $zoneId)) return $this;

        $sql = sprintf('INSERT INTO %s VALUES (%s, %s, %d)',
            $this->getDb()->quoteParameter($this->getZoneTable()),
            $this->getDb()->quote($pluginName), $this->getDb()->quote($zoneName), (int)$zoneId);
        $this->getDb()->query($sql);

        /** @var Iface $plugin */
        $plugin = $this->getPlugin($pluginName);
        if ($plugin) {
            $plugin->doZoneEnable($zoneName, $zoneId);
        }
        return $this;
    }

    /**
     *
     * @param string $pluginName
     * @param string $zoneName
     * @param string $zoneId
     * @return $this
     * @throws \Tk\Db\Exception
     */
    public function disableZonePlugin($pluginName, $zoneName, $zoneId)
    {
        $pluginName = $this->cleanPluginName($pluginName);
        $zoneName = $this->cleanPluginName($zoneName);

        $sql = sprintf('DELETE FROM %s WHERE plugin_name = %s AND zone_name = %s AND zone_id = %d ',
            $this->getDb()->quoteParameter($this->getZoneTable()),
            $this->getDb()->quote($pluginName), $this->getDb()->quote($zoneName), (int)$zoneId);
        $this->getDb()->query($sql);
        /** @var Iface $plugin */
        $plugin = $this->getPlugin($pluginName);
        if ($plugin) {
            $plugin->doZoneDisable($zoneName, $zoneId);
        }
        return $this;
    }



}