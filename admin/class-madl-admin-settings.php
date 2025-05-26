<?php
// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

class MADL_Admin_Settings
{

    private $db;

    public function __construct()
    {
        $this->db = new MADL_Db();
    }

    public function hooks()
    {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('admin_post_madl_save_profile', array($this, 'handle_save_profile'));
        add_action('admin_post_madl_delete_profile', array($this, 'handle_delete_profile'));
    }

    public function add_admin_menu()
    {
        add_options_page(
            __('Multi AD Login Settings', 'multi-ad-login'),
            __('Multi AD Login', 'multi-ad-login'),
            'manage_options',
            MADL_SETTINGS_SLUG,
            array($this, 'settings_page_html')
        );
    }

    public function settings_init()
    {
        // This function can be used to register settings with the WordPress Settings API
        // For this custom table, we'll handle saving manually via admin_post actions.
    }

    public function handle_save_profile()
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'multi-ad-login'));
        }
        check_admin_referer('madl_save_profile_action', 'madl_save_profile_nonce');

        $profile_id = isset($_POST['madl_profile_id']) ? absint($_POST['madl_profile_id']) : 0;

        $data = array(
            'profile_name'        => sanitize_text_field($_POST['madl_profile_name']),
            'is_default'          => isset($_POST['madl_is_default']) ? 1 : 0,
            'domain_identifier'   => sanitize_text_field($_POST['madl_domain_identifier']),
            'base_dn'             => sanitize_text_field($_POST['madl_base_dn']),
            'domain_controllers'  => sanitize_textarea_field($_POST['madl_domain_controllers']),
            'port'                => absint($_POST['madl_port']),
            'use_tls'             => isset($_POST['madl_use_tls']) ? 1 : 0,
            'use_ssl'             => isset($_POST['madl_use_ssl']) ? 1 : 0,
            'allow_self_signed'   => isset($_POST['madl_allow_self_signed']) ? 1 : 0,
            'network_timeout'     => absint($_POST['madl_network_timeout']),
            'account_suffixes'    => sanitize_textarea_field($_POST['madl_account_suffixes']),
            'bind_username'       => sanitize_text_field($_POST['madl_bind_username']),
            'bind_password'       => $_POST['madl_bind_password'], // Not sanitized, handled by DB class (or should be encrypted)
            'clear_bind_password' => isset($_POST['madl_clear_bind_password']) ? '1' : '0',
        );

        // Basic validation
        if (empty($data['profile_name']) || empty($data['base_dn']) || empty($data['domain_controllers'])) {
            wp_redirect(add_query_arg(array('page' => MADL_SETTINGS_SLUG, 'madl_error' => 'missing_fields'), admin_url('options-general.php')));
            exit;
        }


        if ($profile_id > 0) {
            // Update existing
            if ($this->db->update_ad_profile($profile_id, $data)) {
                wp_redirect(add_query_arg(array('page' => MADL_SETTINGS_SLUG, 'madl_message' => 'profile_updated'), admin_url('options-general.php')));
            } else {
                wp_redirect(add_query_arg(array('page' => MADL_SETTINGS_SLUG, 'madl_error' => 'update_failed'), admin_url('options-general.php')));
            }
        } else {
            // Add new
            if ($this->db->add_ad_profile($data)) {
                wp_redirect(add_query_arg(array('page' => MADL_SETTINGS_SLUG, 'madl_message' => 'profile_added'), admin_url('options-general.php')));
            } else {
                wp_redirect(add_query_arg(array('page' => MADL_SETTINGS_SLUG, 'madl_error' => 'add_failed'), admin_url('options-general.php')));
            }
        }
        exit;
    }

    public function handle_delete_profile()
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'multi-ad-login'));
        }
        if (! isset($_GET['madl_profile_id']) || ! isset($_GET['madl_delete_profile_nonce'])) {
            wp_redirect(add_query_arg(array('page' => MADL_SETTINGS_SLUG, 'madl_error' => 'missing_params_delete'), admin_url('options-general.php')));
            exit;
        }

        $profile_id = absint($_GET['madl_profile_id']);
        $nonce = sanitize_text_field($_GET['madl_delete_profile_nonce']);

        if (! wp_verify_nonce($nonce, 'madl_delete_profile_action_' . $profile_id)) {
            wp_redirect(add_query_arg(array('page' => MADL_SETTINGS_SLUG, 'madl_error' => 'nonce_failed_delete'), admin_url('options-general.php')));
            exit;
        }

        if ($this->db->delete_ad_profile($profile_id)) {
            wp_redirect(add_query_arg(array('page' => MADL_SETTINGS_SLUG, 'madl_message' => 'profile_deleted'), admin_url('options-general.php')));
        } else {
            wp_redirect(add_query_arg(array('page' => MADL_SETTINGS_SLUG, 'madl_error' => 'delete_failed'), admin_url('options-general.php')));
        }
        exit;
    }


    public function settings_page_html()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        // The actual HTML form will be in a separate template file for clarity
        include_once MADL_PLUGIN_DIR . 'admin/views/settings-page-template.php';
    }
}
