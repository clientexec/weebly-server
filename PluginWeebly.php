<?php

require_once 'library/CE/NE_MailGateway.php';
require_once 'modules/admin/models/ServerPlugin.php';

class PluginWeebly extends ServerPlugin {

    public $features = array(
        'packageName' => true,
        'testConnection' => true,
        'showNameservers' => false,
        'directlink' => true
    );
    public $url = "https://api.weeblycloud.com/hosts/";

    private function setup ( $args ) {
        if ( isset($args['server']['variables']['plugin_weebly_API_Key']) && isset($args['server']['variables']['plugin_weebly_API_Secret']) ) {
            return true;
        } else {
            throw new CE_Exception("Missing Server Credentials: please fill out all information when editing the server.");
        }
    }

    function getVariables() {

        $variables = array (
            lang("Name") => array (
                "type"=>"hidden",
                "description"=>"Used by CE to show plugin - must match how you call the action function names",
                "value"=>"Weebly"
            ),
            lang("Description") => array (
                "type"=>"hidden",
                "description"=>lang("Description viewable by admin in server settings"),
                "value"=>lang("Weebly Cloud integration")
            ),
            lang("API Key") => array (
                "type"=>"text",
                "description"=>lang("API Key"),
                "value"=>"",
                "encryptable"=>true
            ),
            lang("API Secret") => array (
                "type"=>"text",
                "description"=>lang("API Secret"),
                "value"=>"",
                "encryptable"=>true
            ),
            lang("Failure E-mail") => array (
                "type"=>"text",
                "description"=>lang("E-mail address error messages will be sent to"),
                "value"=>""
            ),
            lang("Actions") => array (
                "type"=>"hidden",
                "description"=>lang("Current actions that are active for this plugin per server"),
                "value"=>"Create,Delete,Suspend,UnSuspend"
            ),
            lang('Registered Actions For Customer') => array(
                "type"=>"hidden",
                "description"=>lang("Current actions that are active for this plugin per server for customers"),
                "value"=>""
            ),
            lang("reseller") => array (
                "type"=>"hidden",
                "description"=>lang("Whether this server plugin can set reseller accounts"),
                "value"=>"0",
            ),
            lang("package_addons") => array (
                "type"=>"hidden",
                "description"=>lang("Supported signup addons variables"),
                "value"=>"",
            ),
            lang("Weebly User ID Custom Field") => array(
                "type"        => "text",
                "description" => lang("Enter the name of the customer custom field that will hold the Weebly User ID."),
                "value"       => ""
            ),
        );

        return $variables;
    }

    function doDelete($args) {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->delete($args);
        return 'Package has been deleted.';
    }

    function doCreate($args) {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->create($args);
        return 'Package has been created.';
    }

    function doSuspend($args) {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->suspend($args);
        return 'Package has been suspended.';
    }

    function doUnSuspend($args) {
        $userPackage = new UserPackage($args['userPackageId']);
        $args = $this->buildParams($userPackage);
        $this->unsuspend($args);
        return 'Package has been unsuspended.';
    }


    function unsuspend($args) {
        $this->setup($args);
        $params = array();

        $userId = $this->getUserId($args);

        $result = $this->call($params, $args, "user/{$userId}/enable", 'POST');
    }

    function suspend($args) {
        $this->setup($args);
        $params = array();

        $userId = $this->getUserId($args);

        $result = $this->call($params, $args, "user/{$userId}/disable", 'POST');
    }

    public function delete($args) {
        $this->setup($args);
        $params = array();

        $userId = $this->getUserId($args);
        $siteId = $this->getSiteId($args);

        $result = $this->call($params, $args, "user/{$userId}/site/{$siteId}", 'DELETE');

        $userPackage = new UserPackage($args['package']['id']);
        $userPackage->setCustomField('Server Acct Properties', '');
    }

    function getAvailableActions($userPackage) {
        $args = $this->buildParams($userPackage);
        $this->setup($args);

        $actions = array();
        $userId = $this->getUserId($args);
        $siteId = $this->getSiteId($args);

        if ( $userId == '' || $siteId == '' ) {
            $actions[] = 'Create';
        } else {
            try {
                $result = $this->call($params, $args, "user/{$userId}/site/{$siteId}", 'GET');
                $actions[] = 'Suspend';
                $actions[] = 'UnSuspend';
                $actions[] = 'Delete';
            } catch ( Exception $e ) {
                $actions[] = 'Create';
            }
        }

        return $actions;
    }

    public function create($args) {
        $this->setup($args);
        $userPackage = new UserPackage($args['package']['id']);
        $user = new User($args['customer']['id']);

        // check if a user exists.
        $userId = $this->getUserId($args);
        if ( $userId == '' ) {
            // Create Weebly User
            $params = array();
            $params['email'] = $args['customer']['email'];
            $result = $this->call($params, $args, 'user', 'POST');
            $userId = $result->user_id;
            $user->updateCustomTag($args['server']['variables']['plugin_weebly_Weebly_User_ID_Custom_Field'], $userId);
        }

        // Create Weebly Site
        $params = array();
        $params['domain'] = $userPackage->getCustomField('Domain Name');
        if ( $args['package']['name_on_server'] != ''  ) {
            $params['plan_id'] = $args['package']['name_on_server'];
        }
        $result = $this->call($params, $args, "user/{$userId}/site", 'POST');
        $userPackage->setCustomField('Server Acct Properties', $result->site->site_id);
    }

    private function getUserId($args)
    {
        $user = new User($args['customer']['id']);

        $user->getCustomFieldsValue($args['server']['variables']['plugin_weebly_Weebly_User_ID_Custom_Field'], $userId);
        return $userId;
    }

    private function getSiteId($args)
    {
        $userPackage = new UserPackage($args['package']['id']);
        return $userPackage->getCustomField('Server Acct Properties');
    }

    private function call($params, $args, $requestUrl, $requestType)
    {
        if ( !function_exists('curl_init') )
        {
            throw new CE_Exception('cURL is required in order to connect to SolusVM');
        }

        if ( count($params) > 0 ) {
            CE_Lib::log(4, 'Weebly Params: ' . print_r($params, true));
        }

        $apiKey = $args['server']['variables']['plugin_weebly_API_Key'];
        $apiSecret = $args['server']['variables']['plugin_weebly_API_Secret'];
        $content = json_encode($params);

        $hash = base64_encode(hash_hmac('SHA256', $requestType . "\n" . $requestUrl . "\n" . $content, $apiSecret));

        CE_Lib::log(4, 'Weebly URL: ' . $this->url . $requestUrl);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url . $requestUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestType);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-type: application/json',
            'X-Public-Key: ' . $apiKey,
            'X-Signed-Request-Hash: ' . $hash
        ));
        curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/../../../library/cacert.pem');

        $data = curl_exec($ch);
        if ( $data === false )
        {
            $error = "Weebly API Request / cURL Error: ".curl_error($ch);
            CE_Lib::log(4, $error);
            throw new CE_Exception($error);
        }

        $responseInfo = curl_getinfo($ch);
        curl_close($ch);
        $data = json_decode($data);

        if ( $responseInfo['http_code'] === 200 ) {
            return $data;
        } else {
            CE_Lib::log(4, 'Weebly Error: ' . $data->error->message);
            throw new CE_Exception($data->error->message);
        }
    }

    public function testConnection($args)
    {
        CE_Lib::log(4, 'Testing connection to Weebly');
        $this->setup($args);

        $params = array();
        $result = $this->call($params, $args, 'account', 'GET');
    }

    public function getDirectLink($userPackage)
    {
        $args = $this->buildParams($userPackage);
        $this->setup($args);

        $userId = $this->getUserId($args);
        $siteId = $this->getSiteId($args);
        if ( $userId == '' || $siteId == '' ) {
            return array(
                'link' => '',
                'form' => ''
            );
        } else {
            $result = $this->call(array(), $args, "user/{$userId}/site/{$siteId}/loginLink", 'POST');
            return array(
                'link' => '<li><a target="_blank" href="' . $result->link . '">' . $this->user->lang('Login to Weebly') . '</a></li>',
                'form' => ''
            );
        }
    }
}