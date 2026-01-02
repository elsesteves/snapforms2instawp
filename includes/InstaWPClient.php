<?php

class InstaWPClient {
    private $apiBaseUrl = 'https://app.instawp.io/api/v2';
    private $apiKey = '';
    private $config = [];
    public $site_id = null;

    // Construct with per-form config array
    public function __construct(array $config) {
        $this->config = $config;
        $this->apiKey = $config['api_token'] ?? '';
    }

    //helper method for HTTP requests
    private function httpRequest($method, $url, $args = []) {
        $defaults = [
            'headers' => [],
            'body'    => null,
            'timeout' => $this->timeout,
        ];
        $opts = array_merge($defaults, $args);

        return wp_remote_request($url, [
            'method'  => strtoupper($method),
            'headers' => $opts['headers'],
            'body'    => $opts['body'],
            'timeout' => (int)$opts['timeout'],
        ]);
    }

    // Load all form configs from a JSON file
    public static function loadFormsConfig($configPath) {
        if (!file_exists($configPath)) {
            throw new Exception('InstaWP config file not found: ' . $configPath);
        }

        $json = file_get_contents($configPath);
        $config = json_decode($json, true);
        if (!is_array($config)) {
            throw new Exception('Invalid InstaWP config JSON');
        }
        
        if (isset($config['forms']) && is_array($config['forms'])) {
            $forms = $config['forms'];
            $indexed = [];
            foreach ($forms as $form) {
                if (!is_array($form) || !isset($form['id_form'])) {
                    throw new Exception('Each form entry must include an id_form');
                }
                $indexed[(string)$form['id_form']] = $form;
            }
            return $indexed;
        }

        //No matching forms found
        return null;
    }

    /**
     * Helper to instantiate a demo in which to install the demo content
     * @return array|WP_Error Array with 'id', 'url', 'username', 'password' on success, WP_Error on failure
     */
    public function createDemo($identifier = '') {
        if(empty($identifier)) {
            $identifier = time();
        }

        $body = $this->config['instance_settings'];

        if(isset($this->config['instance_prefix']) 
            && !empty($this->config['instance_prefix'])) {
            $body['site_name'] = $this->config['instance_prefix'] . '-'. $identifier;
        }

        $url = $this->apiBaseUrl.'/sites';
        if(isset($body['snapshot_slug']) 
            && !empty($body['snapshot_slug'])) {
            $url .= '/snapshot';
        }

        $response = $this->httpRequest('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => 30,
        ]);

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if($response_code === 200) {
            $data = json_decode($response_body, true);
            $this->site_id = $data['data']['id'];

            $site = [
                'id' => $data['data']['id'],
                'url' => $data['data']['wp_url'],
                'username' => $data['data']['wp_username'],
                'password' => $data['data']['wp_password'],
            ];

            return $site;

        } else {
            return new WP_Error('instawp_demo_create_failed', 'Failed to create InstaWP demo site', [
                'response_code' => $response_code,
                'response_body' => $response_body,
            ]);
        }        
    }

    /**
     * Helper to delete a demo in the event of failure
     * @return array|WP_Error
     */
    public function deleteDemo() {
        if(empty($this->site_id)) {
            return new WP_Error('instawp_demo_delete_failed', 'No site ID provided for deletion', []);
        }

        $body = [];
        $url = $this->apiBaseUrl.'/sites/'.$this->site_id;

        $response = $this->httpRequest('DELETE', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => 30,
        ]);

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if($response_code === 200) {
            $data = json_decode($response_body, true);

            return $data;

        } else {
            return new WP_Error('instawp_demo_delete_failed', 'Failed to delete InstaWP demo site', [
                'response_code' => $response_code,
                'response_body' => $response_body,
            ]);
        }
    }

    public function managePlugins() {
        if(isset($this->config['plugins']) && !empty($this->config['plugins'])) {
            $install = $this->installPlugins($this->config['plugins']);
            if(is_wp_error($install)) {
                return new WP_Error('instawp_plugin_install_failed', $install->get_error_message(), []);
            }

            //cmd only available if in a paid plan (at least sandbox plan)
            if(isset($this->config['activation']['enabled']) && !empty($this->config['activation']['enabled'])
                && isset($this->config['instance_settings']['plan_id']) && $this->config['instance_settings']['plan_id'] > 1) {
                $activationCommand = [];

                if(isset($this->config['activation']['command_id']) && !empty($this->config['activation']['command_id'])
                    && isset($this->config['activation']['argument_name']) && !empty($this->config['activation']['argument_name'])) {
                    $activationCommand = [
                        'command_id' => $this->config['activation']['command_id'],
                        'argument_name' => $this->config['activation']['argument_name']
                    ];
                }
                if(empty($activationCommand)) {
                    //return new WP_Error('instawp_plugin_activate_failed', 'No command ID provided for plugin activation', []);
                    $activationCommand = $this->getActivationCommand();
                    if(empty($activationCommand)) {
                        $activationCommand = $this->createActivationCommand();
                    }
                }

                if(!empty($activationCommand)) {
                    $pluginsDelay = $this->config['delays']['pre_plugin_activation'] ?? 15;
                    sleep($pluginsDelay);//wait for plugins to be ready for activation
                    $this->activatePlugins($activationCommand, $this->config['plugins']);
                }
            }

            return $install;            
        }
        return null;
    }

    protected function installPlugins($plugins) {
        if(empty($plugins) || !is_array($plugins)) {
            return new WP_Error('instawp_plugin_install_failed', 'No plugins provided for installation', []);
        }

        $pluginUrls = [];
        foreach($plugins as $plugin) {
            if(!empty($plugin['url'])) {
                $pluginUrls[] = $plugin['url'];
            }
        }

        if(empty($pluginUrls)) {
            return new WP_Error('instawp_plugin_install_failed', 'No plugin URLs provided for installation', []);
        }

        $body = [
            "plugin_urls" => implode(',', $pluginUrls),
        ];

        $url = $this->apiBaseUrl.'/sites/'.$this->site_id.'/install-content';

        $response = $this->httpRequest('PUT', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => 30,
        ]);

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if($response_code === 200) {
            $msg = json_decode($response_body, true);
            return $msg;

        } else {
            return new WP_Error('instawp_plugin_install_failed', 'Failed to install plugin on demo site', [
                'response_code' => $response_code,
                'response_body' => $response_body,
            ]);
        }
    }

    protected function activatePlugins(array $command, $plugins) {
        if(empty($plugins) || !is_array($plugins)) {
            return new WP_Error('instawp_plugin_install_failed', 'No plugins provided for activation', []);
        }

        $url = $this->apiBaseUrl.'/sites/'.$this->site_id.'/execute-command';

        foreach($plugins as $plugin) {
            if(!isset($plugin['slug']) || empty($plugin['slug'])) {
                continue;
            }

            $body = [
                "command_id" => $command['command_id'],
                "commandArguments" => [
                    $command['argument_name'] => $plugin['slug']
                ]
            ];

            $response = $this->httpRequest('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($body),
                'timeout' => 30,
            ]);

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if($response_code === 200) {
                $msg = json_decode($response_body, true);
                return $msg;

            } else {
                return new WP_Error('instawp_plugin_install_failed', 'Failed to install plugin on demo site', [
                    'response_code' => $response_code,
                    'response_body' => $response_body,
                ]);
            }
        }
    }

    protected function createActivationCommand() {
        $url = $this->apiBaseUrl.'/commands';

        //Default command to activate a plugin
        $body = [
            "name" => "activate plugin",
            "command" => "wp plugin activate {{plugin_slug}}"
        ];

        $response = $this->httpRequest('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => 30,
        ]);

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if($response_code === 200) {
            $msg = json_decode($response_body, true);

            return [
                'command_id' => $msg['data']['id'],
                'argument_name' => 'plugin_slug'
            ];

        } else {

            return false;
        }
    }

    protected function getActivationCommand() {
        $url = $this->apiBaseUrl.'/commands';

        $response = $this->httpRequest('GET', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        if ($response_code == 200) {
            $reply = json_decode($response_body, true);
        } else {
            return null;
        }

        $data = $reply['data'] ?? [];
        $pattern = '/wp plugin activate {{(.+)}}/m';
        foreach ($data as $command) {
            $command_payload = $command['command_payload'] ?? '';

            preg_match_all($pattern, $command_payload, $matches, PREG_SET_ORDER, 0);
            if(!empty($matches)) {
                $argument_name = $matches[0][1];

                return [
                    'command_id' => $command['id'],
                    'argument_name' => $argument_name
                ];
            }
        }
        return null;
    }

}