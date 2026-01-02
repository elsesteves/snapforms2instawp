<?php
/**
 * Plugin Name: SnapForms2InstaWP - InstaWP Integration for SnapForms submissions
 * Description: Enables creating temporary InstaWP demo sites with SnapForms installed upon form submission.
 * Version:     1.0.0
 * Author:      Eduardo Esteves
 * Author URI: https://edluis97.github.io/ 
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function getSnapForms2InstaWPConfigs() {
    require_once __DIR__.'/includes/InstaWPClient.php';
    global $snapforms2instawp_configsDir;
    global $snapforms2instawp_forms;

    // Store config outside plugin: wp-content/snapforms/addons/instawp/config.json
    $snapforms2instawp_configsDir = trailingslashit(WP_CONTENT_DIR) . 'snapforms/addons/instawp/config.json';
    try {
        $snapforms2instawp_forms = InstaWPClient::loadFormsConfig($snapforms2instawp_configsDir);
    } catch (Exception $e) {
        // Fail gracefully if config is missing/invalid
        error_log('[SnapForms2InstaWP] Config error: ' . $e->getMessage());
    }

    return $snapforms2instawp_forms;
}

add_filter('query_vars', function ($vars) {
    $vars[] = 'id_form';
    $vars[] = 'form';
    $vars[] = 'id_submission';
    $vars[] = 'submission';
    $vars[] = 'token';
    return $vars;
});

add_action('snapforms.submission.new', function($args) {
    $snapforms2instawp_forms = getSnapForms2InstaWPConfigs();

    $id_submission = $args['id_submission'];
    $id_form = $args['id_form'];

    if(empty($id_submission) || empty($id_form)) {
        return;
    }
    
    $formConfig = $snapforms2instawp_forms[$id_form] ?? null;

    if (!$formConfig) {
        return; // No config for this form
    }

    $submission = apply_filters('snapforms_msgbus', "submission/".$id_form."/".$id_submission."/obtain")['data'] ?? null;
    if(empty($submission)) {
        print '<div class="notice notice-error">
            <p>Submission not found.</p>
        </div>';
        return;
    }

    $token = $submission['token'];

    $vrfy_link = home_url().'/forms/?sf_addon_action=snapf2instawp_demo_request&form='.urlencode($submission['form_uuid']).'&submission='.urlencode($submission['uuid']).'&token='.urlencode($token);

    $replace = [
        '{recipient:name}' => $submission['recipient']['name'],
        '{vrfy_link}' => $vrfy_link,
    ];

    $msgBody = strtr($formConfig['emails']['verification']['body'] ?? '', $replace);
    
    apply_filters('snapforms_msgbus', "submission/".$id_form."/".$id_submission."/email/send", [
        "from_email" => $formConfig['emails']['verification']['email_address'] ?? '',
        'subject' => $formConfig['emails']['verification']['subject'] ?? 'Demo Site Request',
        'body' => $msgBody
    ]);
    
});

add_action('snapf2instawp_demo_request', function() {    
    $snapforms2instawp_forms = getSnapForms2InstaWPConfigs();

    $id_form = get_query_var('id_form');
    $form_uuid = get_query_var('form');
    $id_submission = get_query_var('id_submission');
    $submission_uuid = get_query_var('submission');
    $token = get_query_var('token');

    if((empty($id_form) && empty($form_uuid)) || (empty($id_submission) && empty($submission_uuid)) || empty($token)) {
        return;        
    }

    $search = [
        "form_uuid" => !empty($form_uuid) ? $form_uuid : '',
        "submission_uuid" => !empty($submission_uuid) ? $submission_uuid : '',
        "token" => $token,
        "external" => true,
    ];

    $form = apply_filters('snapforms_msgbus', "form/obtain", $search)['data'] ?? null;
    $submission = apply_filters('snapforms_msgbus', "submission/obtain", $search)['data'] ?? null;

    if(empty($form) ||empty($submission) || $submission['token'] != $token) {
        print '<div class="notice notice-error">
            <p>Invalid request parameters.</p>
        </div>';
        return;
    }

    $id_form = $submission['id_form'];
    $id_submission = $submission['id_submission'];

    $formConfig = $snapforms2instawp_forms[$id_form] ?? null;
    if (!$formConfig) {
        return; // No config for this form
    }

    $title = $formConfig['title'] ?? $form['form'];
    print '<h2>'.$title.'</h2>';

    if($submission['status'] == '2') {//approved
        print '<div class="notice notice-error">
            <p>This demo request has already been processed.</p>
        </div>';
        return;
    } elseif($submission['status'] != '1') {//not pending
        print '<div class="notice notice-error">
            <p>This demo request is not available.</p>
        </div>';
        return;
    }

    $replace = [
        '{recipient:name}' => $submission['recipient']['name'],
    ];

    ?>
    <? if(!empty($formConfig['pages']['verification']['message'])): ?>
    <?= strtr($formConfig['pages']['verification']['message'], $replace) ?>
    <br>
    <? endif; ?>
    <form method="post" onsubmit="snapformsDemoCreate()" action="<?php echo home_url().'/forms/'; ?>" enctype="multipart/form-data">
        <input type="hidden" name="sf_addon_action" value="snapf2instawp_demo_confirm">
        <input type="hidden" name="form" value="<?php echo esc_attr($form_uuid); ?>">
        <input type="hidden" name="submission" value="<?php echo esc_attr($submission_uuid); ?>">
        <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">

        <div class="text-end mt-3 p-3">
            <button type="submit" id="create-demo-btn" class="btn btn-primary"><?= $formConfig['pages']['verification']['button']['label'] ?? 'Create Demo' ?></button>
        </div>
    </form>

    <script>
        function snapformsDemoCreate() {
            const btn = document.querySelector('#create-demo-btn');
            btn.disabled = true;
            btn.innerText = "<?= $formConfig['pages']['verification']['button']['post_click_label'] ?? 'Create Demo... Please wait.' ?>";
        }
    </script>
    <?php
});

add_action('snapf2instawp_demo_confirm', function() {    
    $snapforms2instawp_forms = getSnapForms2InstaWPConfigs();
    
    $id_form = get_query_var('id_form');
    $form_uuid = get_query_var('form');
    $id_submission = get_query_var('id_submission');
    $submission_uuid = get_query_var('submission');
    $token = get_query_var('token');

    if((empty($id_form) && empty($form_uuid)) || (empty($id_submission) && empty($submission_uuid)) || empty($token)) {
        return;        
    }

    $search = [
        "form_uuid" => !empty($form_uuid) ? $form_uuid : '',
        "submission_uuid" => !empty($submission_uuid) ? $submission_uuid : '',
        "token" => $token,
        "external" => true,
    ];

    $form = apply_filters('snapforms_msgbus', "form/obtain", $search)['data'] ?? null;
    $submission = apply_filters('snapforms_msgbus', "submission/obtain", $search)['data'] ?? null;

    if(empty($form) ||empty($submission) || $submission['token'] != $token) {
        print '<div class="notice notice-error">
            <p>Invalid request parameters.</p>
        </div>';
        return;
    }

    $id_form = $submission['id_form'];
    $id_submission = $submission['id_submission'];

    $formConfig = $snapforms2instawp_forms[$id_form] ?? null;
    if (!$formConfig) {
        return; // No config for this form
    }

    $title = $formConfig['title'] ?? $form['form'];
    print '<h2>'.$title.'</h2>';

    if($submission['status'] == '2') {//approved
        print '<div class="notice notice-error">
            <p>This demo request has already been processed.</p>
        </div>';
        return;
    } elseif($submission['status'] != '1') {//not pending
        print '<div class="notice notice-error">
            <p>This demo request is not available.</p>
        </div>';
        return;
    }

    $backBtnUrl = home_url().'/forms/?sf_addon_action=snapf2instawp_demo_request&form='.urlencode($form_uuid).'&submission='.urlencode($submission_uuid).'&token='.urlencode($token);

    $instaWP = new InstaWPClient($formConfig);
    $site = $instaWP->createDemo($submission['uuid']);

    if(is_wp_error($site)) {
        $errorMsg = $site->get_error_data();
        $errorMsgTxt = json_decode($errorMsg['response_body'], true)['message'] ?? $errorMsg;

        print '<div class="notice notice-error">
                <p>Error creating InstaWP demo site - '.$site->get_error_message().'</p>
                <p>Error Data: '.$errorMsgTxt.'</p>
            </div>
            <div class="text-end mt-3 p-3">
                <a href="'.$backBtnUrl.'" class="btn btn-secondary">Go Back</a>
            </div>';
        return;

    } elseif(!isset($site['id'])) {
        print '<div class="notice notice-error">
                <p>Error creating InstaWP demo site - unknown error</p>                
            </div>
            <div class="text-end mt-3 p-3">
                <a href="'.$backBtnUrl.'" class="btn btn-secondary">Go Back</a>
            </div>';
        return;

    } else {
        $websiteDelay = $formConfig['delays']['post_website_creation'] ?? 30;
        sleep($websiteDelay);//wait for site to be ready
        $install = $instaWP->managePlugins();

        if(is_wp_error($install)) {
            $instaWP->deleteDemo();

            $errorMsg = $install->get_error_data();
            $errorMsgTxt = json_decode($errorMsg['response_body'], true)['message'] ?? $errorMsg;            

            print '<div class="notice notice-error">
                    <p>Error installing SnapForms demo plugin on InstaWP demo site - '.$install->get_error_message().'</p>
                    <p>Error Data: '.$errorMsgTxt.'</p>                    
                </div>
                <div class="text-end mt-3 p-3">
                    <a href="'.$backBtnUrl.'" class="btn btn-secondary">Go Back</a>
                </div>';
            return;
        } elseif(!(isset($install['status']) && $install['status'] == true)) {
            $instaWP->deleteDemo();

            print '<div class="notice notice-error">
                    <p>Error installing SnapForms demo plugin on InstaWP demo site - unknown error</p>                    
                </div>
                <div class="text-end mt-3 p-3">
                    <a href="'.$backBtnUrl.'" class="btn btn-secondary">Go Back</a>
                </div>';
            return;

        } else {
            $entry_link = $site['url'];
            if(isset($formConfig['user_entry_point']) && !empty($formConfig['user_entry_point'])) {
                $entry_link .= $formConfig['user_entry_point'];
            }

            $replace = [
                '{recipient:name}' => $submission['recipient']['name'],
                '{site:url}' => $site['url'],
                '{site:username}' => $site['username'],
                '{site:password}' => $site['password'],
                '{entry_link}' => $entry_link,
            ];

            if(!empty($formConfig['pages']['confirmation']['message'])) { 
                print strtr($formConfig['pages']['confirmation']['message'], $replace);
            } ?>
            <div class="text-end mt-3 p-3">
                <a href="<?= $entry_link ?>" target="_blank" class="btn btn-primary"><?= $formConfig['pages']['confirmation']['button']['label'] ?? 'Access Demo Site' ?></a>
            </div>
            <?php

            $msgBody = strtr($formConfig['emails']['confirmation']['body'] ?? '', $replace);

            apply_filters('snapforms_msgbus', "submission/".$id_form."/".$id_submission."/email/send", [
                "from_email" =>  $formConfig['emails']['confirmation']['email_address'] ?? '',
                'subject' => $formConfig['emails']['confirmation']['subject'] ?? 'Demo Site Available',
                'body' => $msgBody
            ]);

            apply_filters('snapforms_msgbus', "submission/".$id_form."/".$id_submission."/approve");
        }
    }

    
});