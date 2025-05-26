<?php
// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

class MADL_Ldap_Handler
{

    /**
     * Authenticate a user against a specific AD profile.
     *
     * @param object $ad_profile The AD profile object from the database.
     * @param string $username The username (sAMAccountName or UPN user part).
     * @param string $password The user's password.
     * @param string|null $full_upn Optional full UPN (user@domain) if available, for logging or specific AdLdap needs.
     * @return array|false User info array on success, false on failure.
     */
    public static function authenticate_with_profile($ad_profile, $username, $password, $full_upn = null)
    {
        if (! class_exists('Dreitier\AdLdap\AdLdap')) {
            MADL_Logger::log('AdLdap class not found. Ensure library is correctly placed in includes/lib/.', 'ERROR');
            return false;
        }

        $login_identifier = $full_upn ? $full_upn : $username;
        MADL_Logger::log("Attempting LDAP authentication for '{$login_identifier}' against profile '{$ad_profile->profile_name}'.", 'INFO');

        $domain_controllers = array_map('trim', explode(';', $ad_profile->domain_controllers));
        if (empty($domain_controllers) || empty($domain_controllers[0])) {
            MADL_Logger::log("No domain controllers configured for profile '{$ad_profile->profile_name}'.", 'ERROR');
            return false;
        }

        $adldap_config = array(
            'base_dn'            => $ad_profile->base_dn,
            'domain_controllers' => $domain_controllers,
            'ad_port'            => (int) $ad_profile->port,
            'use_tls'            => (bool) $ad_profile->use_tls,
            'use_ssl'            => (bool) $ad_profile->use_ssl,
            'allow_self_signed'  => (bool) $ad_profile->allow_self_signed,
            'network_timeout'    => (int) $ad_profile->network_timeout,
            // Use profile-specific bind credentials if provided, otherwise AdLdap might attempt anonymous bind or use its defaults
            'ad_username'        => !empty($ad_profile->bind_username) ? $ad_profile->bind_username : null,
            'ad_password'        => !empty($ad_profile->bind_password) ? $ad_profile->bind_password : null,
        );

        // Account suffix for AdLdap needs to be determined.
        // AdLdap's authenticate method typically takes username (sAMAccountName) and appends its configured account_suffix.
        // If $username is already a UPN user part and $full_upn has the domain, we need to ensure AdLdap uses the correct suffix.
        // The 'account_suffixes' field in the profile stores suffixes like '@example.com'.
        // We need to pick one, or let AdLdap try if it supports multiple.
        // For simplicity, if $full_upn is provided, we can extract the suffix.
        // If only $username (sAMAccountName) is provided, we use the first configured suffix for the profile.

        $account_suffix_to_use = null;
        if (!empty($ad_profile->account_suffixes)) {
            // Always try to get suffix from profile first
            $profile_suffixes = array_map('trim', explode(';', $ad_profile->account_suffixes));
            if (!empty($profile_suffixes[0])) {
                $account_suffix_to_use = $profile_suffixes[0]; // Use the first one for sAMAccountName auth
            }
        } elseif ($full_upn && strpos($full_upn, '@') !== false) {
            $account_suffix_to_use = '@' . substr(strstr($full_upn, '@'), 1);
        }

        if (empty($account_suffix_to_use)) {
            MADL_Logger::log("No suitable account suffix found or configured for profile '{$ad_profile->profile_name}' for user '{$username}'. Authentication might fail if UPN format is expected by AD.", 'WARNING');
            // AdLdap might still work if username is full UPN and it doesn't append suffix, or if AD allows sAMAccountName bind.
        }
        $adldap_config['account_suffix'] = $account_suffix_to_use; // AdLdap will append this if username doesn't have '@'

        MADL_Logger::log("AdLdap config for '{$ad_profile->profile_name}': " . print_r(array_merge($adldap_config, ['ad_password' => '***']), true), 'DEBUG');

        try {
            $adldap = new \Dreitier\AdLdap\AdLdap($adldap_config);

            // The AdLdap::authenticate method usually expects sAMAccountName and uses its internal account_suffix.
            // If $username is already a UPN (e.g. user@domain.com), AdLdap might handle it or it might expect just the user part.
            // The AdLdap library's authenticate method: $this->_bind = @ldap_bind($this->_conn, $username . $this->_account_suffix, $password);
            // So, if $username is 'user' and suffix is '@domain.com', it tries 'user@domain.com'.
            // If $username is 'user@domain.com' and suffix is also '@domain.com', it might try 'user@domain.com@domain.com'.
            // We should pass only the user part if a suffix is being used.
            $auth_username = $username;
            // if (strpos($username, '@') !== false && $account_suffix_to_use) {
            //     // If username looks like UPN and we have a suffix, only pass the user part
            //     $parts = explode('@', $username, 2);
            //     $auth_username = $parts[0];
            // }


            MADL_Logger::log("Calling AdLdap->authenticate() with username: '{$auth_username}', password: '***', effective suffix: '{$adldap->get_account_suffix()}'", 'DEBUG');

            if ($adldap->authenticate($auth_username, $password)) {
                MADL_Logger::log("LDAP authentication successful for '{$login_identifier}' with profile '{$ad_profile->profile_name}'. Fetching user info.", 'INFO');

                // Fetch user attributes for WordPress mapping
                // Use the sAMAccountName (auth_username if it was UPN part, or original username if sAMAccountName)
                // or if $full_upn was 'user@domain.com', AdLdap might need 'user@domain.com' for user_info if it doesn't use sAMAccountName.
                // AdLdap's user_info usually takes sAMAccountName or full UPN.
                // Let's try with $auth_username (which should be sAMAccountName or UPN user part)
                // If $auth_username was just the user part of a UPN, user_info might need the full UPN or rely on its suffix.
                // For safety, if the original input was a UPN, use that for user_info. Otherwise, use the (potentially sAMAccountName) $auth_username.
                // $user_info_principal = (strpos($login_identifier, '@') !== false) ? $login_identifier : $auth_username;
                $user_info_principal = $username;

                $attributes_to_fetch = array('samaccountname', 'mail', 'givenname', 'sn', 'displayname', 'objectguid', 'userprincipalname');
                MADL_Logger::log("Calling AdLdap->user_info() with principal: '{$user_info_principal}' and attributes: " . print_r($attributes_to_fetch, true), 'DEBUG');

                $user_info = $adldap->user_info($user_info_principal, $attributes_to_fetch);

                MADL_Logger::log("AdLdap->user_info() -raw result: " . print_r(($user_info), true), 'DEBUG');

                if ($user_info && isset($user_info[0])) {
                    MADL_Logger::log("Fetched AD user info for '{$login_identifier}': " . print_r(self::sanitize_user_info_for_log($user_info[0]), true), 'DEBUG');
                    // $parsed_user_info = MADL_Ldap_Data::parse_ldap_data($user_info); // Use the data handling class
                    // return $parsed_user_info;
                    $processed_user_info = self::process_ldap_entry($user_info[0]);
                    return $processed_user_info;
                } else {
                    MADL_Logger::log("LDAP authentication succeeded for '{$login_identifier}', but failed to fetch user info. LDAP error: " . $adldap->get_last_error(), 'ERROR');
                    return false;
                }
            } else {
                MADL_Logger::log("LDAP authentication failed for '{$login_identifier}' with profile '{$ad_profile->profile_name}'. LDAP error: " . $adldap->get_last_error(), 'WARNING');
                return false;
            }
        } catch (\Dreitier\AdLdap\AdLdapException $e) {
            MADL_Logger::log("AdLdapException during authentication for '{$login_identifier}' with profile '{$ad_profile->profile_name}': " . $e->getMessage(), 'ERROR');
            return false;
        } catch (\Exception $e) {
            MADL_Logger::log("Generic Exception during authentication for '{$login_identifier}' with profile '{$ad_profile->profile_name}': " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * Processes a raw LDAP entry (from ldap_get_entries) into a cleaner associative array.
     */
    private static function process_ldap_entry($entry)
    {
        $processed = array();
        foreach ($entry as $key => $value) {
            if (is_string($key) && is_array($value) && isset($value[0])) {
                $processed[$key] = $value[0];
            }
        }
        return $processed;
    }

    /**
     * Sanitizes user info for logging (removes potentially sensitive binary data like objectGUID raw).
     */
    public static function sanitize_user_info_for_log($user_info_entry)
    {
        $safe_info = [];
        if (is_array($user_info_entry)) {
            foreach ($user_info_entry as $key => $value) {
                if ($key === 'objectguid' && isset($value[0]) && !is_string($value[0])) { // Assuming objectguid might be binary
                    $safe_info[$key] = '[binary GUID data]';
                } elseif (is_array($value) && isset($value[0])) {
                    $safe_info[$key] = $value[0];
                } elseif (!is_array($value)) {
                    $safe_info[$key] = $value;
                }
            }
        }
        return $safe_info;
    }
}
