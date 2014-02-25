<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2014 Zend Technologies USA Inc. (http://www.zend.com)
 */

namespace ZF\Apigility\Documentation;

use InvalidArgumentException;
use Zend\ModuleManager\ModuleManager;
use ZF\Apigility\Provider\ApigilityProviderInterface;
use ZF\Configuration\ModuleUtils as ConfigModuleUtils;

class ApiFactory
{
    /**
     * @var ModuleManager
     */
    protected $moduleManager;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var ConfigModuleUtils
     */
    protected $configModuleUtils;

    /**
     * @var array
     */
    protected $docs = array();

    /**
     * @param ModuleManager $moduleManager
     * @param array $config
     * @param ConfigModuleUtils $configModuleUtils
     */
    public function __construct(ModuleManager $moduleManager, $config, ConfigModuleUtils $configModuleUtils)
    {
        $this->moduleManager = $moduleManager;
        $this->config = $config;
        $this->configModuleUtils = $configModuleUtils;
    }

    /**
     * Create list of available API modules
     *
     * @return array
     */
    public function createApiList()
    {
        $apigilityModules = array();
        $q = preg_quote('\\');
        foreach ($this->moduleManager->getModules() as $moduleName) {
            $module = $this->moduleManager->getModule($moduleName);
            if ($module instanceof ApigilityProviderInterface) {
                $versionRegex = '#' . preg_quote($moduleName) . $q . 'V(?P<version>[^' . $q . ']+)' . $q . '#';
                $versions = array();
                $serviceConfigs = array();
                if ($this->config['zf-rest']) {
                    $serviceConfigs = array_merge($serviceConfigs, $this->config['zf-rest']);
                }
                if ($this->config['zf-rpc']) {
                    $serviceConfigs = array_merge($serviceConfigs, $this->config['zf-rpc']);
                }

                foreach ($serviceConfigs as $serviceName => $serviceConfig) {
                    if (!preg_match($versionRegex, $serviceName, $matches)) {
                        continue;
                    }
                    $version = $matches['version'];
                    if (!in_array($version, $versions)) {
                        $versions[] = $version;
                    }
                }

                $apigilityModules[] = array(
                    'name'     => $moduleName,
                    'versions' => $versions,
                );
            }
        }
        return $apigilityModules;
    }

    /**
     * Create documentation details for a given API module and version
     *
     * @param string $apiName
     * @param int|string $apiVersion
     * @return Api
     */
    public function createApi($apiName, $apiVersion = 1)
    {
        $api = new Api;

        $api->setVersion($apiVersion);
        $api->setName($apiName);

        $serviceConfigs = array();
        if ($this->config['zf-rest']) {
            $serviceConfigs = array_merge($serviceConfigs, $this->config['zf-rest']);
        }
        if ($this->config['zf-rpc']) {
            $serviceConfigs = array_merge($serviceConfigs, $this->config['zf-rpc']);
        }

        foreach ($serviceConfigs as $serviceName => $serviceConfig) {
            if (strpos($serviceName, $apiName . '\\') === 0
                && strpos($serviceName, '\V' . $api->getVersion() . '\\')) {
                $service = $this->createService($api, $serviceConfig['service_name']);
                if ($service) {
                    $api->addService($service);
                }
            }
        }

        return $api;
    }

    /**
     * Create documentation details for a given service in a given version of
     * an API module
     *
     * @param string $apiName
     * @param int|string $apiVersion
     * @param string $serviceName
     * @return Service
     */
    public function createService(Api $api, $serviceName)
    {
        $service = new Service();
        $service->setApi($api);

        $serviceData = null;

        foreach ($this->config['zf-rest'] as $serviceClassName => $restConfig) {
            if ((strpos($serviceClassName, $api->getName() . '\\') === 0)
                && ($restConfig['service_name'] === $serviceName)
                && (strstr($serviceClassName, '\\V' . $api->getVersion() . '\\') !== false)
            ) {
                $serviceData = $restConfig;
                break;
            }
        }

        if (!$serviceData) {
            foreach ($this->config['zf-rpc'] as $serviceClassName => $rpcConfig) {
                if ((strpos($serviceClassName, $api->getName() . '\\') === 0)
                    && ($rpcConfig['service_name'] === $serviceName)
                    && (strstr($serviceClassName, '\\V' . $api->getVersion() . '\\') !== false)
                ) {
                    $serviceData = $rpcConfig;
                    break;
                }
            }
        }

        if (!$serviceData || !isset($serviceClassName)) {
            return false;
        }

        $docsArray = $this->getDocumentationConfig($api->getName());

        $service->setName($serviceData['service_name']);
        if (isset($docsArray[$serviceClassName]['description'])) {
            $service->setDescription($docsArray[$serviceClassName]['description']);
        }

        $route = $this->config['router']['routes'][$serviceData['route_name']]['options']['route'];
        $service->setRoute(str_replace('[/v:version]', '', $route)); // remove internal version prefix, hacky

        $baseOperationData = (isset($serviceData['collection_http_methods']))
            ? $serviceData['collection_http_methods']
            : $serviceData['http_methods'];

        $ops = array();
        foreach ($baseOperationData as $httpMethod) {
            $op = new Operation();
            $op->setHttpMethod($httpMethod);
            if (isset($docsArray[$serviceClassName]['collection'][$httpMethod])) {
                $op->setDescription($docsArray[$serviceClassName]['collection'][$httpMethod]['description']);
                $op->setRequestDescription($docsArray[$serviceClassName]['collection'][$httpMethod]['request']);
                $op->setResponseDescription($docsArray[$serviceClassName]['collection'][$httpMethod]['response']);
            }
            $ops[] = $op;
        }
        $service->setOperations($ops);

        if (isset($serviceData['entity_http_methods'])) {
            $ops = array();
            foreach ($serviceData['entity_http_methods'] as $httpMethod) {
                $op = new Operation();
                $op->setHttpMethod($httpMethod);
                if (isset($docsArray[$serviceClassName]['collection'][$httpMethod])) {
                    $op->setDescription($docsArray[$serviceClassName]['collection'][$httpMethod]['description']);
                    $op->setRequestDescription($docsArray[$serviceClassName]['collection'][$httpMethod]['request']);
                    $op->setResponseDescription($docsArray[$serviceClassName]['collection'][$httpMethod]['response']);
                }
                $ops[] = $op;
            }
            $service->setEntityOperations($ops);
        }

        if (isset($this->config['zf-content-validation'][$serviceClassName]['input_filter'])) {
            $validatorName = $this->config['zf-content-validation'][$serviceClassName]['input_filter'];
            $fields = array();
            if (isset($this->config['input_filters'][$validatorName])) {
                foreach ($this->config['input_filters'][$validatorName] as $fieldData) {
                    $fields[] = $field = new Field();
                    $field->setName($fieldData['name']);
                    if (isset($fieldData['description'])) {
                        $field->setDescription($fieldData['description']);
                    }
                    $field->setRequired($fieldData['required']);
                }
                $service->setFields($fields);
            }
        }

        if (isset($this->config['zf-content-negotiation']['accept_whitelist'][$serviceClassName])) {
            $service->setRequestAcceptTypes($this->config['zf-content-negotiation']['accept_whitelist'][$serviceClassName]);
        }

        if (isset($this->config['zf-content-negotiation']['content_type_whitelist'][$serviceClassName])) {
            $service->setRequestContentTypes($this->config['zf-content-negotiation']['content_type_whitelist'][$serviceClassName]);
        }

        return $service;
    }

    /**
     * Retrieve the documentation for a given API module
     *
     * @param string $apiName
     * @return array
     */
    protected function getDocumentationConfig($apiName)
    {
        if (isset($this->docs[$apiName])) {
            return $this->docs[$apiName];
        }

        $moduleConfigPath = $this->configModuleUtils->getModuleConfigPath($apiName);
        $docConfigPath = dirname($moduleConfigPath) . '/documentation.config.php';
        if (file_exists($docConfigPath)) {
            $this->docs[$apiName] = include $docConfigPath;
        } else {
            $this->docs[$apiName] = array();
        }

        return $this->docs[$apiName];
    }
}
