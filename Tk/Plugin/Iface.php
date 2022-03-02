<?php
namespace Tk\Plugin;

use Tk\ConfigTrait;

/**
 * @author Michael Mifsud <info@tropotek.com>
 * @see http://www.tropotek.com/
 * @license Copyright 2016 Michael Mifsud
 */
abstract class Iface
{
    use ConfigTrait;

    /**
     * Use the type to extend the plugin system
     */
    const TYPE_SYSTEM = 'system';

    /**
     * @var int
     */
    protected $id = null;

    /**
     * @var string
     */
    protected $name = null;

    /**
     * @var string
     */
    protected $type = 'system';

    /**
     * @var \stdClass
     */
    protected $info = null;

    /**
     * @var Factory
     */
    protected $pluginFactory = null;

    /**
     * @var \Tk\Db\Data
     */
    protected $_data = null;


    /**
     * @param $id
     * @param string $name
     */
    public function __construct($id, $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    /**
     * Init the plugin
     * This is called when the session first registers the plugin to the queue
     * So it is the first called method after the constructor.....
     */
    abstract function doInit();

    /**
     * Activate the plugin, essentially
     * installing any DB and settings required to run
     * Will only be called when activating the plugin in the
     * plugin control panel
     */
    abstract function doActivate();

    /**
     * Upgrade the plugin
     * Called when the file version is larger than the version in the DB table
     *
     * @param string $oldVersion
     * @param string $newVersion
     */
    function doUpgrade($oldVersion, $newVersion) { }

    /**
     * Deactivate the plugin removing any DB data and settings
     * Will only be called when deactivating the plugin in the
     * plugin control panel
     */
    abstract function doDeactivate();

    /**
     * Return the URI of the plugin's configuration page
     * Return null for none
     *
     * @return \Tk\Uri
     */
    public function getSettingsUrl()
    {
        return null;
    }

    /**
     * @param string $zoneName
     * @param string $zoneId
     */
    public function doZoneEnable($zoneName, $zoneId) { }

    /**
     * @param string $zoneName
     * @param string $zoneId
     */
    public function doZoneDisable($zoneName, $zoneId) { }

    /**
     * @param string $zoneName
     * @param string $zoneId
     * @return bool
     * @throws \Tk\Db\Exception
     */
    public function isZonePluginEnabled($zoneName, $zoneId)
    {
        return $this->getPluginFactory()->isZonePluginEnabled($this->getName(), $zoneName, $zoneId);
    }

    /**
     * Get the zone settings URL, if null then there is none
     * <code>
     *   // Some example code for zone setup urls
     *   switch ($zoneName) {
     *     case 'institution':
     *       return Uri::create('/lti/institutionSettings.html');
     *   }
     * </code>
     * @param string $zoneName
     * @param string $zoneId
     * @return string|\Tk\Uri|null
     */
    public function getZoneSettingsUrl($zoneName, $zoneId)
    {
        return null;
    }


    /**
     * @return bool
     * @throws \Tk\Db\Exception
     */
    public function isActive()
    {
        return $this->getPluginFactory()->isActive($this->getName());
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get the plugin/package name. Same name as stored in teh DB of active plugins
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param string $type
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * @return Factory
     */
    public function getPluginFactory()
    {
        return $this->pluginFactory;
    }

    /**
     * @param Factory $pluginFactory
     */
    public function setPluginFactory($pluginFactory)
    {
        $this->pluginFactory = $pluginFactory;
    }

    /**
     * Get Plugin Meta Data
     *
     * @return \stdClass
     */
    public function getInfo()
    {
        if (!$this->info) {
            $this->info = $this->getPluginFactory()->getPluginInfo($this->getName());
        }
        return $this->info;
    }

    /**
     * @return \Tk\Db\Data
     */
    public function getData()
    {
        if (!$this->_data) {
            $this->_data = \Tk\Db\Data::create($this->getName());
        }
        return $this->_data;
    }

    /**
     * Get the plugin relative path
     * EG: '/plugin/plg-plugin/'
     *
     * @return string
     */
    public function getPluginPath()
    {
        return dirname(str_replace($this->getConfig()->getSitePath(), '', \Tk\ObjectUtil::classPath(get_class($this))));
    }

}
