<?php
/** Definition for filter yubikey:OTP
 * @see https://github.com/simplesamlphp/simplesamlphp-module-yubikey */
class aswAuthFilterMethod_yubikey_OTP extends sspmod_authswitcher_AuthFilterMethodWithSimpleSecret {
    public function getTargetFieldName() {
        return 'yubikey';
    }
}

/** Definition for filter simpletotp:2fa
 * @see https://github.com/aidan-/SimpleTOTP */
class aswAuthFilterMethod_simpletotp_2fa extends sspmod_authswitcher_AuthFilterMethodWithSimpleSecret {
    public function getTargetFieldName() {
        return 'totp_secret';
    }
}

/** Definition for filter authTiqr:Tiqr */
class aswAuthFilterMethod_authTiqr_Tiqr extends sspmod_authswitcher_AuthFilterMethod {
    /** @override */
    public function process(&$request) {
        // TODO
    }
    
    /** @override */
    public function __construct($methodParams) {
        // TODO
    }
}

class sspmod_authswitcher_Auth_Process_SwitchAuth extends SimpleSAML_Auth_ProcessingFilter {
    /* constants */
    const DEBUG_PREFIX = 'authswitcher:SwitchAuth: ';
    /** If true, then e.g. the absence of methods for 2nd factor mean that 3rd factor won't be tried, even if configured. */
    const FINISH_WHEN_NO_METHODS = true;

    /* configurable attributes */
    /** Associative array where keys are in form 'module:filter' and values are config arrays to be passed to those filters. */
    private $configs = array();
    /** Maximal supported "n" in "n-th factor authentication" */
    private $supportedFactorMax = sspmod_authswitcher_AuthSwitcherFactor::SECOND;
    /** DataAdapter configuration */
    private $dataAdapterConfig = array();
    /** DataAdapter implementation class name */
    private $dataAdapterClassName = "sspmod_authswitcher_DbDataAdapter";

    /** Second constructor parameter */
    private $reserved;
    /** DataAdapter for getting users' settings. */
    private $dataAdapter = null;

    /** Lazy getter for DataAdapter */
    private function getData() {
        if ($this->dataAdapter == null) {
            $className = $this->dataAdapterClassName;
            $this->dataAdapter = new $className($this->dataAdapterConfig);
        }
        return $this->dataAdapter;
    }
    
    /* logging */
    /** Log a warning. */
    private function warning($message) {
        SimpleSAML_Logger::warning(self::DEBUG_PREFIX . $message);
    }
    /** Log an info. */
    private function info($message) {
        SimpleSAML_Logger::info(self::DEBUG_PREFIX . $message);
    }

    /** @override */
    public function __construct($config, $reserved) {
        parent::__construct($config, $reserved);

        assert(interface_exists('sspmod_authswitcher_DataAdapter'));

        $this->getConfig($config);

        $this->reserved = $reserved;
    }
    
    private function getConfig($config) {
        if (!is_array($config['configs'])) {
            throw new SimpleSAML_Error_Exception(self::DEBUG_PREFIX . 'Configurations are missing.');
        }
        $filterModules = array_keys($config['configs']);
        if (sspmod_authswitcher_Utils::areFilterModulesEnabled($filterModules)) {
            $this->warning('Some modules in the configuration are missing or disabled.');
        }
        $this->configs = $config['configs'];
        
        if (isset($config['supportedFactorMax'])) {
            if (!is_int($config['supportedFactorMax']) || $config['supportedFactorMax'] < sspmod_authswitcher_AuthSwitcher::FACTOR_MIN) {
               throw new SimpleSAML_Error_Exception(self::DEBUG_PREFIX . 'Invalid configuration parameter supportedFactorMax.');
            }
            $this->supportedFactorMax = $config['supportedFactorMax'];
        }
        
        if (is_array($config['dataAdapterConfig'])) {
            $this->dataAdapterConfig = $config['dataAdapterConfig'];
        }
        
        if (isset($config['dataAdapterClassName'])) {
            if (!(
               is_string($config['dataAdapterClassName']) &&
               class_exists($config['dataAdapterClassName']) &&
               in_array('sspmod_authswitcher_DataAdapter', class_implements($config['dataAdapterClassName']))
            )) {
                throw new SimpleSAML_Error_Exception(self::DEBUG_PREFIX . 'Invalid dataAdapterClassName supplied.');
            }
        }
    }

    /** Prepare before running auth proc filter (e.g. add atributes with secret keys) */
    private function prepareBeforeAuthProcFilter($method, &$request) {
        list($module, $simpleClass) = explode(":", $method->method);
        $filterMethodClassName = "aswAuthFilterMethod_" . $module . "_" . $simpleClass;
        $filterMethod = new $filterMethodClassName($method);
        $filterMethod->process($request);
    }
    
    /** @override */
    public function process(&$request) {
        $uid = $request['Attributes'][sspmod_authswitcher_AuthSwitcher::UID_ATTR][0];
        for ($factor = sspmod_authswitcher_AuthSwitcher::FACTOR_MIN; $factor <= $this->supportedFactorMax; $factor++) {
            $methods = $this->getData()->getMethodsActiveForUidAndFactor($uid, $factor);

            if (count($methods) == 0) {
                $this->logNoMethodsForFactor($uid, $factor);

                if (self::FINISH_WHEN_NO_METHODS) return;
                else continue;
            }

            $method = $this->chooseMethod($methods);
            $methodClass = $method->method;

            if (!isset($this->configs[$methodClass])) {
                throw new SimpleSAML_Error_Exception(self::DEBUG_PREFIX . 'Configuration for ' . $methodClass . ' is missing.');
            }

            $this->prepareBeforeAuthProcFilter($method, $request);

            sspmod_authswitcher_Utils::runAuthProcFilter($methodClass, $this->configs[$methodClass], $request, $this->reserved);
        }
    }
    
    /** Choose an appropriate method from the set.
     * @todo filter methods based on device (availability)
     */
    private function chooseMethod($methods) {
        return $methods[0];
    }
    
    /** Log that a user has no methods for n-th factor. */
    private function logNoMethodsForFactor($uid, $factor) {
        if ($factor == sspmod_authswitcher_AuthSwitcher::FACTOR_MIN) {
            $this->info('User '.$uid.' has no methods for factor '.$factor.'. MFA not performed at all.');
        } else {
            $this->info('User '.$uid.' has no methods for factor '.$factor);
        }
    }
}

