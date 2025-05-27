<?php
// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

class MADL_Auth
{

    public function hooks()
    {
        // Hook with a priority higher than typical (10), but not so high it breaks other critical things.
        // NADI often uses 10. If this is to replace NADI's auth, it should run before or NADI should be disabled.
        add_filter('authenticate', array($this, 'authenticate_user'), 20, 3);
    }

    /**
     * Authenticate user against AD based on Strategy C.
     *
     * @param WP_User|WP_Error|null $user     WordPress user object or error.
     * @param string                $username Login username.
     * @param string                $password Login password.
     * @return WP_User|WP_Error|null
     */
    public function authenticate_user($user, $username, $password)
    {
        MADL_Logger::log("Authentication attempt received for username: '{$username}'. Current WP user object type: " . (is_object($user) ? get_class($user) : gettype($user)), 'INFO');

        // If already authenticated by a higher-priority filter, or if it's an error, don't interfere.
        if ($user instanceof WP_User) {
            MADL_Logger::log("User '{$username}' already authenticated by a higher priority filter. Skipping MADL.", 'INFO');
            return $user;
        }
        // if ( is_wp_error($user) ) {
        //     MADL_Logger::log( "Authentication for '{$username}' already resulted in WP_Error. Skipping MADL.", 'INFO' );
        //     return $user; // Let WordPress handle this error (e.g. empty username/password)
        // }


        if (empty($username) || empty($password)) {
            MADL_Logger::log("Username or password empty for '{$username}'. Passing to WordPress default.", 'WARNING');
            return $user; // Let WordPress handle this (it will return an error)
        }

        $clean_username = sanitize_user($username, true); // Sanitize for security, true for strict
        // Password is used directly with LDAP, not further sanitized here beyond what WP does.

        $ad_user_info = false;
        $used_profile_name = 'N/A';

        if (strpos($clean_username, '@') === false) {
            // No '@' - sAMAccountName: Try default AD profile
            MADL_Logger::log("Username '{$clean_username}' does not contain '@'. Attempting default AD profile.", 'INFO');
            $default_profile = MADL_Db::get_default_ad_profile();

            if ($default_profile) {
                MADL_Logger::log("Default AD profile found: '{$default_profile->profile_name}'. Attempting authentication.", 'INFO');
                $used_profile_name = $default_profile->profile_name;
                $ad_user_info = MADL_Ldap_Handler::authenticate_with_profile($default_profile, $clean_username, $password);
                if ($ad_user_info) {
                    MADL_Logger::log("SUCCESS: User '{$clean_username}' authenticated against default AD profile '{$used_profile_name}'.", 'INFO');
                } else {
                    MADL_Logger::log("FAIL: User '{$clean_username}' failed authentication against default AD profile '{$used_profile_name}'.", 'WARNING');
                }
            } else {
                MADL_Logger::log("No default AD profile configured. Cannot attempt sAMAccountName-only login against default.", 'WARNING');
            }
        } else {
            // Username contains '@' - UPN/Email: Try specific AD profile
            list($user_part, $domain_part) = explode('@', $clean_username, 2);
            $domain_part = strtolower(trim($domain_part)); // Normalize domain part

            MADL_Logger::log("Username '{$clean_username}' contains '@'. Domain part: '{$domain_part}', User part: '{$user_part}'.", 'INFO');
            $specific_profile = MADL_Db::get_ad_profile_by_identifier($domain_part);

            if ($specific_profile) {
                MADL_Logger::log("Specific AD profile '{$specific_profile->profile_name}' found for domain identifier '{$domain_part}'. Attempting authentication.", 'INFO');
                $used_profile_name = $specific_profile->profile_name;
                // Pass the user_part for authentication, and full UPN for context
                $ad_user_info = MADL_Ldap_Handler::authenticate_with_profile($specific_profile, $user_part, $password, $clean_username);
                if ($ad_user_info) {
                    MADL_Logger::log("SUCCESS: User '{$clean_username}' authenticated against specific AD profile '{$used_profile_name}'.", 'INFO');
                } else {
                    MADL_Logger::log("FAIL: User '{$clean_username}' failed authentication against specific AD profile '{$used_profile_name}'.", 'WARNING');
                }
            } else {
                MADL_Logger::log("No specific AD profile found for domain identifier '{$domain_part}'.", 'WARNING');
            }
        }

        if ($ad_user_info) {
            MADL_Logger::log("AD User info retrieved for '{$clean_username}': " . print_r(($ad_user_info), true), 'DEBUG');
            return $this->get_or_create_wp_user($ad_user_info, $password, $used_profile_name);
        }

        MADL_Logger::log("MADL: AD authentication failed or no applicable profile for '{$clean_username}'. Passing to next handler or WordPress default.", 'INFO');
        // If $user was null and we didn't authenticate, return null to let WP generate "unknown username" or "incorrect password".
        // If $user was an error from a previous (lower priority) authenticate call, return that error.
        return $user;
    }

    /**
     * Get an existing WordPress user or create/update one based on AD info.
     *
     * @param array $ad_user_info Raw user info from AdLdap.
     * @param string $password The password used (for setting if user is new, though AD is master).
     * @param string $profile_name The name of the AD profile used for auth.
     * @return WP_User|WP_Error
     */
    protected function get_or_create_wp_user($ad_user_info, $password, $profile_name)
    {
        MADL_Logger::log("get_or_create_wp_user() called with ad_user_info: " . print_r(MADL_Ldap_Handler::sanitize_user_info_for_log($ad_user_info), true), 'DEBUG');

        // Extract standard attributes - AdLdap returns arrays for attributes
        $sam_account_name = isset($ad_user_info['samaccountname']) ? $ad_user_info['samaccountname'] : null;  //  <---  CHANGED
        $user_principal_name = isset($ad_user_info['userprincipalname']) ? $ad_user_info['userprincipalname'] : null;  //  <---  CHANGED
        $email = isset($ad_user_info['mail']) ? $ad_user_info['mail'] : null;  //  <---  CHANGED
        $first_name = isset($ad_user_info['givenname']) ? $ad_user_info['givenname'] : '';  //  <---  CHANGED
        $last_name = isset($ad_user_info['sn']) ? $ad_user_info['sn'] : '';  //  <---  CHANGED
        $display_name = isset($ad_user_info['displayname']) ? $ad_user_info['displayname'] : '';  //  <---  CHANGED
        $object_guid = isset($ad_user_info['objectguid']) ? $this->format_guid($ad_user_info['objectguid']) : null;  //  <---  CHANGED


        if (empty($sam_account_name)) {
            MADL_Logger::log("AD attribute 'sAMAccountName' is missing for authenticated user from profile '{$profile_name}'. Cannot create/update WP user.", 'ERROR');
            return new WP_Error('madl_missing_samaccountname', __('AD user data is incomplete (missing sAMAccountName).', 'multi-ad-login'));
        }
        if (empty($email) && empty($user_principal_name)) {
            MADL_Logger::log("AD attributes 'mail' and 'userPrincipalName' are missing for '{$sam_account_name}' from profile '{$profile_name}'. Cannot reliably create/update WP user email.", 'ERROR');
            return new WP_Error('madl_missing_email_upn', __('AD user data is incomplete (missing email/UPN).', 'multi-ad-login'));
        }
        // Prefer mail, fallback to UPN for email if mail is empty
        $wp_email = !empty($email) ? $email : $user_principal_name;

        // **Use email/UPN as WordPress username**
        $wp_username = $wp_email;

        // Try to find user by AD GUID first (most reliable)
        $wp_user = null;
        if ($object_guid) {
            $users_by_guid = get_users(array(
                'meta_key'   => 'madl_ad_object_guid',
                'meta_value' => $object_guid,
                'number'     => 1,
                'count_total' => false
            ));
            if (! empty($users_by_guid)) {
                $wp_user = $users_by_guid[0];
                MADL_Logger::log("Found existing WP user ID {$wp_user->ID} by ObjectGUID '{$object_guid}' for AD user '{$sam_account_name}'.", 'INFO');
            }
        }

        // If not found by GUID, try by email (which is now WP username)
        if (! $wp_user) {
            $wp_user = get_user_by('login', $wp_username);
            if ($wp_user) {
                MADL_Logger::log("Found existing WP user ID {$wp_user->ID} by login '{$wp_username}'.", 'INFO');
            }
        }

        // If not found by login, try by email
        if (! $wp_user) {
            $wp_user = get_user_by('email', $wp_email);
            if ($wp_user) {
                MADL_Logger::log("Found existing WP user ID {$wp_user->ID} by email '{$wp_email}'.", 'INFO');
            }
        }

        $user_data = array(
            'user_login'    => $wp_username,
            'user_email'    => $wp_email,
            'first_name'    => $first_name,
            'last_name'     => $last_name,
            'display_name'  => !empty($display_name) ? $display_name : $sam_account_name,
            'user_pass'     => $password, // Set password - AD is master, but WP needs one.
            'role'          => get_option('default_role', 'subscriber'), // Or make this configurable
        );

        if ($wp_user) {
            // Update existing user
            $user_data['ID'] = $wp_user->ID;
            MADL_Logger::log("Updating WP user ID {$wp_user->ID} ('{$wp_user->user_login}') with data from AD user '{$sam_account_name}'.", 'INFO');
            wp_update_user($user_data);
        } else {
            // Create new user
            MADL_Logger::log("Creating new WP user for AD user '{$sam_account_name}'.", 'INFO');
            $user_id = wp_insert_user($user_data);
            if (is_wp_error($user_id)) {
                MADL_Logger::log("Error creating WP user for '{$sam_account_name}': " . $user_id->get_error_message(), 'ERROR');
                return $user_id; // Return WP_Error
            }
            $wp_user = get_user_by('id', $user_id);
            MADL_Logger::log("Created new WP user ID {$user_id} for AD user '{$sam_account_name}'.", 'INFO');
        }

        // Store/Update AD ObjectGUID and last used profile for reference
        if ($object_guid && $wp_user && !is_wp_error($wp_user)) {
            update_user_meta($wp_user->ID, 'madl_ad_object_guid', $object_guid);
            update_user_meta($wp_user->ID, 'madl_last_auth_profile', $profile_name);
            update_user_meta($wp_user->ID, 'madl_last_ad_upn', $user_principal_name); // Store UPN from AD
            MADL_Logger::log("Updated/Set user meta (ObjectGUID, profile) for WP user ID {$wp_user->ID}.", 'DEBUG');
        }

        // Allow other plugins to do things after successful AD login and WP user sync
        do_action('madl_after_successful_login', $wp_user, $ad_user_info, $profile_name);

        return $wp_user;
    }

    /**
     * Formats a binary GUID from AdLdap into a string representation.
     * AdLdap's user_info might return it already formatted or binary.
     * This is a basic attempt; AdLdap might have its own utility.
     */
    protected function format_guid($guid_data)
    {
        if (is_string($guid_data)) {
            // Check if it's already in typical string format
            if (preg_match('/^[0-9A-Fa-f]{8}-([0-9A-Fa-f]{4}-){3}[0-9A-Fa-f]{12}$/', $guid_data)) {
                return strtoupper($guid_data);
            }
            // If it's binary data in string form, try to convert (this is simplistic)
            // A proper binary to string GUID conversion is more complex.
            // The AdLdap library itself should ideally provide this.
            // For now, if it's not already a string GUID, we log a warning and return as is or hex encoded.
            // The AdLdap class has a binary2text method. If we had an AdLdap instance here, we'd use it.
            // Since we don't, we'll just hexlify it if it looks binary.
            if (function_exists('mb_check_encoding') && !mb_check_encoding($guid_data, 'ASCII')) {
                MADL_Logger::log("GUID data appears binary, attempting hex conversion: " . bin2hex($guid_data), 'DEBUG');
                return bin2hex($guid_data); // Fallback, not a proper string GUID
            }
        }
        MADL_Logger::log("Received GUID data that may not be properly formatted or is already a string: " . print_r($guid_data, true), 'DEBUG');
        return $guid_data; // Return as is if it's not clearly binary or already a string
    }
}
