<?php
/*
 +------------------------------------------------------------------------+
 | Kitsune                                                                |
 +------------------------------------------------------------------------+
 | Copyright (c) 2015-2015 Phalcon Team and contributors                  |
 +------------------------------------------------------------------------+
 | This source file is subject to the New BSD License that is bundled     |
 | with this package in the file docs/LICENSE.txt.                        |
 |                                                                        |
 | If you did not receive a copy of the license and are unable to         |
 | obtain it through the world-wide-web, please send an email             |
 | to license@phalconphp.com so we can send you a copy immediately.       |
 +------------------------------------------------------------------------+
*/

/**
 * Bootstrap.php
 * \Kitsune\Bootstrap
 *
 * Bootstraps the application
 */
namespace Kitsune;

use Phalcon\DI\FactoryDefault as PhDI;
use Phalcon\Config;
use Phalcon\Loader;
use Phalcon\Logger;
use Phalcon\Logger\Adapter\File as LoggerFile;
use Phalcon\Logger\Formatter\Line as LoggerFormatter;

use Phalcon\Mvc\Application;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\Url as UrlProvider;
use Phalcon\Mvc\Router;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Volt as VoltEngine;
use Phalcon\Session\Adapter\Files as SessionAdapter;
use Phalcon\Events\Manager as EventsManager;

use Ciconia\Ciconia;
use Ciconia\Extension\Gfm\FencedCodeBlockExtension;

use Kitsune\PostFinder;
use Kitsune\Plugins\NotFoundPlugin;
use Kitsune\Markdown\TableExtension;
use Kitsune\Markdown\UrlAutoLinkExtension;
use Kitsune\Utils;

/**
 * Class Bootstrap
 */
class Bootstrap
{
    public static function run($di, array $options = [])
    {
        $memoryUsage = memory_get_usage();
        $currentTime = microtime(true);

        /**
         * The app path
         */
        if (!defined('K_PATH')) {
            define('K_PATH', dirname(dirname(dirname(__FILE__))));

        }
        
        /**
         * We will need the Utils class
         */
        require_once K_PATH . '/library/Kitsune/Utils.php';

        /**
         * Check if this is a CLI app or not
         */
        $cli    = Utils::fetch($options, 'cli', false);
        if (!defined('K_CLI')) {
            define('K_CLI', $cli);
        }

        $tests  = Utils::fetch($options, 'tests', false);
        if (!defined('K_TESTS')) {
            define('K_TESTS', $tests);
        }

        /**
         * The configuration is split into two different files. The first one
         * is the base configuration. The second one is machine/installation 
         * specific.
         */
        if (!file_exists(K_PATH . '/var/config/base.php')) {
            throw new \Exception('Base configuration files are missing');
        }

        if (!file_exists(K_PATH . '/var/config/config.php')) {
            throw new \Exception('Configuration files are missing');
        }

        /**
         * Get the config files and merge them
         */
        $base     = require(K_PATH . '/var/config/base.php');
        $specific = require(K_PATH . '/var/config/config.php');
        $combined = array_replace_recursive($base, $specific);

        $di->set(
            'config',
            function () use ($combined) {
                return new Config($combined);
            },
            true
        );

        $config = $di->get('config');

        /**
         * Check if we are in debug/dev mode
         */
        if (!defined('K_DEBUG')) {
            $debugMode = boolval(
                Utils::fetch($config, 'debugMode', false)
            );
            define('K_DEBUG', $debugMode);
        }

        /**
         * Access to the debug/dev helper functions
         */
        if (K_DEBUG) {
            require_once K_PATH . '/library/Kitsune/Debug.php';
        }

        /**
         * We're a registering a set of directories taken from the configuration file
         */
        $loader = new Loader();
        $loader->registerNamespaces($config->namespaces->toArray());
        $loader->register();

        require K_PATH . '/vendor/autoload.php';

        /**
         * LOGGER
         *
         * The essential logging service
         */
        $di->set(
            'logger',
            function () use ($config, $di) {
                $format = '[%date%][%type%] %message%';
                $name   = K_PATH
                    . '/var/log/'
                    . date('Y-m-d') . '-kitsune.log';
                $logger = new LoggerFile($name);
                $formatter = new LoggerFormatter($format);
                $logger->setFormatter($formatter);
                return $logger;
            },
            true
        );
        $logger = $di->get('logger');

        /**
         * ERROR HANDLING
         */
        ini_set('display_errors', boolval(K_DEBUG));

        error_reporting(E_ALL);

        set_error_handler(
            function ($exception) use ($logger) {
                if ($exception instanceof \Exception) {
                    $logger->error($exception->__toString());
                } else {
                    $logger->error(json_encode(debug_backtrace()));
                }
            }
        );

        set_exception_handler(
            function ($exception) use ($logger) {
                $logger->error($exception->getMessage());
            }
        );

        register_shutdown_function(
            function () use ($logger, $memoryUsage, $currentTime) {
                $memoryUsed = number_format(
                    (memory_get_usage() - $memoryUsage) / 1024,
                    3
                );
                $executionTime = number_format(
                    (microtime(true) - $currentTime),
                    4
                );
                if (K_DEBUG) {
                    $logger->info(
                        'Shutdown completed [Memory: ' . $memoryUsed . 'Kb] ' .
                        '[Execution: ' . $executionTime .']'
                    );
                }
            }
        );

        $timezone = $config->get('app_timezone', 'US/Eastern');
        date_default_timezone_set($timezone);

        /**
         * Routes
         */
        if (!K_CLI) {

            $di->set(
                'router',
                function () use ($config) {
                    $router = new Router(false);
                    $routes = $config->routes->toArray();
                    foreach ($routes as $pattern => $options) {
                        $router->add($pattern, $options);
                    }

                    return $router;
                },
                true
            );
        }

        /**
         * We register the events manager
         */
        $di->set(
            'dispatcher',
            function () use ($di) {

                $eventsManager = new EventsManager;

                /**
                 * Handle exceptions and not-found exceptions using NotFoundPlugin
                 */
                $eventsManager->attach('dispatch:beforeException', new NotFoundPlugin);

                $dispatcher = new Dispatcher;
                $dispatcher->setEventsManager($eventsManager);

                $dispatcher->setDefaultNamespace('Kitsune\Controllers');

                return $dispatcher;
            }
        );

        /**
         * The URL component is used to generate all kind of urls in the application
         */
        $di->set(
            'url',
            function () use ($config) {
                $url = new UrlProvider();
                $url->setBaseUri($config->baseUri);
                return $url;
            }
        );

        $di->set(
            'view',
            function () use ($config) {

                $view = new View();

                $view->setViewsDir(K_PATH . '/app/views/');

                $view->registerEngines([".volt" => 'volt']);

                //Create an events manager
                //$eventsManager = new EventsManager();

                //Attach a listener for type "view"
                //$eventsManager->attach("view", function($event, $view) {
                //    file_put_contents('a.txt', $event->getType() . ' - ' . $view->getActiveRenderPath() . PHP_EOL, FILE_APPEND);
                //});

                //Bind the eventsManager to the view component
                //$view->setEventsManager($eventsManager);

                return $view;
            }
        );

        /**
         * Setting up volt
         */
        $di->set(
            'volt',
            function ($view, $di) {

                $volt = new VoltEngine($view, $di);

                $volt->setOptions(
                    [
                        "compiledPath" => K_PATH . '/var/cache/volt/',
                        'stat'              => true,
                        'compileAlways'     => true,
                    ]
                );

                $volt->getCompiler()->addFunction(
                    'markdown',
                    function ($parameters) {
                        return "\$this->markdown->render({$parameters})";
                    }
                );

                return $volt;
            },
            true
        );

        /**
         * Start the session the first time some component request the session
         * service
         */
        $di->set(
            'session',
            function () {
                $session = new SessionAdapter();
                $session->start();
                return $session;
            }
        );

        /**
         * Cache
         */
        $di->set(
            'viewCache',
            function () {
                $session = new SessionAdapter();
                $session->start();
                return $session;
            }
        );

        /**
         * viewCache
         */
        $di->set(
            'cache',
            function () use ($config) {
                $frontConfig = $config->cache_data->front->toArray();
                $backConfig  = $config->cache_data->back->toArray();
                $class       = '\Phalcon\Cache\Frontend\\' . $frontConfig['adapter'];
                $frontCache  = new $class($frontConfig['params']);
                /**
                 * Backend cache uses our own component which extends Libmemcached
                 */
                $class       = '\Phalcon\Cache\Backend\\' . $backConfig['adapter'];
                $cache       = new $class($frontCache, $backConfig['params']);
                return $cache;
            },
            true
        );

        $di->set(
            'viewCache',
            function () use ($config) {
                $frontConfig = $config->cache_view->front->toArray();
                $backConfig  = $config->cache_view->back->toArray();
                $class       = '\Phalcon\Cache\Frontend\\' . $frontConfig['adapter'];
                $frontCache  = new $class($frontConfig['params']);
                /**
                 * Backend cache uses our own component which extends Libmemcached
                 */
                $class       = '\Phalcon\Cache\Backend\\' . $backConfig['adapter'];
                $cache       = new $class($frontCache, $backConfig['params']);
                return $cache;
            }
        );

        /**
         * Markdown renderer
         */
        $di->set(
            'markdown',
            function () {
                $ciconia = new Ciconia();

                $ciconia->addExtension(new TableExtension());
                $ciconia->addExtension(new UrlAutoLinkExtension());
                $ciconia->addExtension(new FencedCodeBlockExtension());
                return $ciconia;
            },
            true
        );

        $cache = $di->get('cache');
        $di->set(
            'finder',
            function () use ($cache) {
                $key = 'post.finder.cache';
                $postFinder = $cache->get($key);
                if (null === $postFinder) {
                    $postFinder = new PostFinder();
                    $cache->save($key, $postFinder);
                }
                return $postFinder;
            },
            true
        );

        /**
         * Run
         */
        PhDI::setDefault($di);

        /**
         * For CLI I only need the dep injector
         */
        if (K_CLI) {
            return $di;
        }

        $application = new Application($di);

        if (K_TESTS || K_CLI) {
            return $application;
        } else {
            return $application->handle()->getContent();
        }
    }
}
