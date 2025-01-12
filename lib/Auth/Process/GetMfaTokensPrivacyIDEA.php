<?php

declare(strict_types=1);

namespace SimpleSAML\Module\authswitcher\Auth\Process;

use SimpleSAML\Configuration;
use SimpleSAML\Logger;
use SimpleSAML\Module\authswitcher\AuthSwitcher;
use SimpleSAML\Store;

class GetMfaTokensPrivacyIDEA extends \SimpleSAML\Auth\ProcessingFilter
{
    private const DEBUG_PREFIX = 'authswitcher:GetMfaTokensPrivacyIDEA: ';
    private const AS_PI = 'as_pi';
    private const AS_PI_AUTH_TOKEN = 'auth_token';
    private const AS_PI_AUTH_TOKEN_ISSUED_AT = 'auth_token_issued_at';

    private $tokens_attr = 'mfaTokens';

    private $privacy_idea_username;

    private $privacy_idea_passwd;

    private $privacy_idea_domain;

    private $tokens_type = ['TOTP', 'WebAuthn'];

    private $user_attribute = 'eduPersonPrincipalName';

    private $token_type_attr = 'type';

    private $enable_cache = false;

    private $cache_expiration_seconds = 55 * 60;

    public function __construct($config, $reserved)
    {
        parent::__construct($config, $reserved);

        $config = Configuration::loadFromArray($config['config']);
        $this->tokens_attr = $config->getString('tokens_Attr', $this->tokens_attr);
        $this->privacy_idea_username = $config->getString('privacy_idea_username');
        $this->privacy_idea_passwd = $config->getString('privacy_idea_passwd');
        $this->privacy_idea_domain = $config->getString('privacy_idea_domain');
        $this->tokens_type = $config->getArray('tokens_type', $this->tokens_type);
        $this->user_attribute = $config->getString('user_attribute', $this->user_attribute);
        $this->token_type_attr = $config->getString('token_type_attr', $this->token_type_attr);
        $this->enable_cache = $config->getBoolean('enable_cache', $this->enable_cache);
        $this->cache_expiration_seconds = $config->getInteger(
            'cache_expiration_seconds',
            $this->cache_expiration_seconds
        );
    }

    public function process(&$state)
    {
        $state[Authswitcher::PRIVACY_IDEA_FAIL] = false;
        $state['Attributes'][$this->tokens_attr] = [];
        $admin_token = $this->getAdminToken();
        if (null === $admin_token) {
            $state[AuthSwitcher::PRIVACY_IDEA_FAIL] = true;

            return;
        }
        foreach ($this->tokens_type as $token_type) {
            $tokens = self::getPrivacyIdeaTokensByType($state, strtolower($token_type), $admin_token);
            $this->saveTokensToStateAttributes($state, $tokens);
        }
    }

    private function getAdminToken()
    {
        if ($this->enable_cache) {
            $store = Store::getInstance();
            $issued_at = $store->get(self::AS_PI, self::AS_PI_AUTH_TOKEN_ISSUED_AT);
            if ($issued_at && time() - $issued_at < $this->cache_expiration_seconds) {
                $admin_token = $store->get(self::AS_PI, self::AS_PI_AUTH_TOKEN);
                if ($admin_token) {
                    Logger::debug(self::DEBUG_PREFIX . 'Using auth token from cache');

                    return $admin_token;
                }
            }
        }
        $admin_token = $this->getAuthToken();
        if ($this->enable_cache) {
            $store->set(self::AS_PI, self::AS_PI_AUTH_TOKEN, $admin_token);
            $store->set(self::AS_PI, self::AS_PI_AUTH_TOKEN_ISSUED_AT, time());
        }

        return $admin_token;
    }

    private function getAuthToken()
    {
        $data = [
            'username' => $this->privacy_idea_username,
            'password' => $this->privacy_idea_passwd,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->privacy_idea_domain . '/auth');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        $paramsJson = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $paramsJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        if (200 !== curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
            Logger::warning(sprintf(self::DEBUG_PREFIX . 'getAuthToken Response from PrivacyIDEA API: %s', $response));

            return null;
        }
        curl_close($ch);
        $response = json_decode($response, true);

        return $response['result']['value']['token'];
    }

    private function getPrivacyIdeaTokensByType($state, $type, $admin_token)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->privacy_idea_domain . '/token/?user=' .
            $state['Attributes'][$this->user_attribute][0] . '&active=True&type=' . $type);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization:' . $admin_token]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        if (200 !== curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
            Logger::warning(sprintf(self::DEBUG_PREFIX .
                'getPrivacyIdeaTokens type: %s Response from PrivacyIDEA API: %s', $type, $response));
            $state[AuthSwitcher::PRIVACY_IDEA_FAIL] = true;

            return [];
        }
        $response = json_decode($response, true);
        curl_close($ch);

        return $response['result']['value']['tokens'];
    }

    private function saveTokensToStateAttributes(&$state, $tokens)
    {
        foreach ($tokens as $token) {
            foreach ($this->tokens_type as $type) {
                if ($token['tokentype'] === strtolower($type)) {
                    $token[$this->token_type_attr] = $type;
                    $state['Attributes'][$this->tokens_attr][] = $token;
                    break;
                }
            }
        }
    }
}
