<?php

namespace Shyim;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Dotenv\Dotenv;
use Shyim\Api\Client;

/**
 * Class PluginInstaller
 */
class ComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var IOInterface
     */
    public static $io;

    /**
     * @var Client
     */
    private $api;

    /**
     * @var array
     */
    private $extra;

    /**
     * @var array
     */
    private $plugins = [];

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'installPlugins',
            ScriptEvents::POST_UPDATE_CMD => 'installPlugins',
        ];
    }

    /**
     * We dont need activate
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @param Event $e
     *
     * @throws \Exception
     */
    public static function installPlugins(Event $e)
    {
        $self = new static();

        $self->loadEnv();
        LocalCache::init($e->getComposer()->getConfig()->get('cache-dir'));

        Util::$io = $e->getIO();
        $silentfail = $_ENV['SW_STORE_PLUGIN_INSTALLER_SILENTFAIL'] ? $_ENV['SW_STORE_PLUGIN_INSTALLER_SILENTFAIL'] : false;
        Util::$silentFail = filter_var($silentfail, FILTER_VALIDATE_BOOLEAN);

        self::$io = $e->getIO();

        if ($self->readPlugins($e)) {
            $installer = new PluginInstaller($self->api, $self->extra);

            try {
                foreach ($self->plugins as $name => $version) {
                    $installer->installPlugin($name, $version);
                }
            } catch (\Exception $e) {
                Util::throwException($e);
            }
        }
    }

    /**
     * Read plugins from the plugins.ini from root
     */
    private function readPlugins(Event $e)
    {
        $this->extra = $e->getComposer()->getPackage()->getExtra();

        if (isset($this->extra['plugins'])) {
            $env = $_ENV['SHOPWARE_ENV'] ? $_ENV['SHOPWARE_ENV'] : 'production';

            if (!isset($this->extra['plugins'][$env])) {
                self::$io->write(sprintf('Cannot find plugins for environment "%s"', $env), true);

                return false;
            }

            $this->plugins = $this->extra['plugins'][$env];
        } else {
            self::$io->write('[Installer] Cannot find plugins in composer.json extra', true);
        }

        if ($_ENV['SHOP_ALTERNATIVE_URL'] != '') {
            $domain = parse_url($_ENV['SHOP_ALTERNATIVE_URL'], PHP_URL_HOST);
        } else {
            $domain = parse_url($_ENV['SHOP_URL'], PHP_URL_HOST);
        }

        $success = true;

        try {
            $this->api = new Client($_ENV['ACCOUNT_USER'], $_ENV['ACCOUNT_PASSWORD'], $domain);
        } catch (\Exception $e) {
            Util::throwException($e);
            $success = false;
        }

        return $success;
    }

    private function loadEnv()
    {
        $envFile = getcwd() . '/.env';

        if (file_exists($envFile)) {
            if (method_exists(Dotenv::class, 'createImmutable')) {
                (Dotenv::createImmutable(getcwd()))->load();
            } elseif (method_exists(Dotenv::class, 'create')) {
                (Dotenv::create(getcwd()))->load();
            } else {
                (new Dotenv(getcwd()))->load();
            }
        }
    }
}
