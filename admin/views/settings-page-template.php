<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Template for the Multi AD Login settings page.
 * Called by MADL_Admin_Settings::settings_page_html()
 */

$db_handler = new MADL_Db(); // Removed: Instance was not used. Static calls are used below.
$all_profiles = MADL_Db::get_all_ad_profiles(); // Static call

$current_action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
$profile_id_param = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0; // Renamed for clarity

$profile_to_edit = null;
$error_message_profile_not_found = ''; // To store a potential error message

if ( $current_action === 'edit' ) {
    if ( $profile_id_param > 0 ) {
        $profile_to_edit = MADL_Db::get_ad_profile( $profile_id_param );
        if ( ! $profile_to_edit ) {
            $error_message_profile_not_found = __( 'Error: The AD profile you are trying to edit could not be found.', 'multi-ad-login' );
            // Optional: Force back to list if profile not found for edit
            // $current_action = 'list';
        }
    } else {
        $error_message_profile_not_found = __( 'Error: No profile ID provided for editing.', 'multi-ad-login' );
        // Optional: Force back to list
        // $current_action = 'list';
    }
} elseif ( $current_action === 'add' ) {
    if ( $profile_id_param > 0 ) { // Attempting to "duplicate" or "add from" an existing profile
        $base_profile = MADL_Db::get_ad_profile( $profile_id_param );
        if ( $base_profile ) {
            $profile_to_edit = clone $base_profile; // Clone to avoid modifying an original object if it's cached
            $profile_to_edit->id = 0; // Crucial: This is a new profile
            $profile_to_edit->profile_name .= ' (' . __( 'Copy', 'multi-ad-login' ) . ')';
            $profile_to_edit->is_default = 0; // A copy should generally not be default
            $profile_to_edit->bind_password = ''; // Password should not be copied
        }
        // If $base_profile is not found, $profile_to_edit remains null,
        // and the next block will initialize with fresh defaults.
    }

    if ( ! $profile_to_edit ) { // Standard "add new" or if "duplicate from" ID was invalid
        $profile_to_edit = (object) array(
            'id' => 0,
            'profile_name' => '',
            'is_default' => 0,
            'domain_identifier' => '',
            'base_dn' => 'DC=example,DC=com',
            'domain_controllers' => 'dc1.example.com;dc2.example.com',
            'port' => 389,
            'use_tls' => 0,
            'use_ssl' => 0,
            'allow_self_signed' => 0,
            'network_timeout' => 5,
            'account_suffixes' => '@example.com',
            'bind_username' => '',
            'bind_password' => '' // Will be empty for a new profile
        );
    }
}

?>
<div class="wrap madl-settings-wrap">
    <h1><?php esc_html_e( 'Multi AD Login Settings', 'multi-ad-login' ); ?></h1>

    <?php
    // Display messages
    if ( isset( $_GET['madl_message'] ) ) {
        $message_key = sanitize_key( $_GET['madl_message'] );
        $messages = array(
            'profile_added' => __( 'AD Profile added successfully.', 'multi-ad-login' ),
            'profile_updated' => __( 'AD Profile updated successfully.', 'multi-ad-login' ),
            'profile_deleted' => __( 'AD Profile deleted successfully.', 'multi-ad-login' ),
        );
        if ( isset( $messages[ $message_key ] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p">' . esc_html( $messages[ $message_key ] ) . '</p></div>';
        }
    }
    if ( isset( $_GET['madl_error'] ) ) {
        $error_key = sanitize_key( $_GET['madl_error'] );
         $errors = array(
            'missing_fields' => __( 'Error: Required fields are missing.', 'multi-ad-login' ),
            'add_failed' => __( 'Error: Could not add AD Profile.', 'multi-ad-login' ),
            'update_failed' => __( 'Error: Could not update AD Profile.', 'multi-ad-login' ),
            'delete_failed' => __( 'Error: Could not delete AD Profile.', 'multi-ad-login' ),
            'nonce_failed_delete' => __( 'Error: Security check failed for deletion.', 'multi-ad-login' ),
            'missing_params_delete' => __( 'Error: Missing parameters for deletion.', 'multi-ad-login' ),
            'profile_not_found_edit' => __( 'Error: The AD profile you are trying to edit could not be found.', 'multi-ad-login') // Could also come from save handler
        );
        if ( isset( $errors[ $error_key ] ) ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $errors[ $error_key ] ) . '</p></div>';
        }
    }

    // Display error if profile for editing was not found directly on this page load
    if ( ! empty( $error_message_profile_not_found ) ) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error_message_profile_not_found ) . '</p></div>';
    }
    ?>

    <?php
    // Show form only if we are in 'add' mode, or in 'edit' mode AND a profile was successfully loaded.
    $can_show_form = ($current_action === 'add' && $profile_to_edit) || ($current_action === 'edit' && $profile_to_edit);

    if ( $can_show_form ) :
        $is_editing_existing_profile = ($current_action === 'edit' && $profile_to_edit->id > 0);
    ?>
        <h2><?php echo $is_editing_existing_profile ? esc_html__( 'Edit AD Profile', 'multi-ad-login' ) : esc_html__( 'Add New AD Profile', 'multi-ad-login' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="madl_save_profile">
            <input type="hidden" name="madl_profile_id" value="<?php echo esc_attr( $profile_to_edit->id ); ?>">
            <?php wp_nonce_field( 'madl_save_profile_action', 'madl_save_profile_nonce' ); ?>

            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label for="madl_profile_name"><?php esc_html_e( 'Profile Name', 'multi-ad-login' ); ?></label></th>
                        <td><input name="madl_profile_name" type="text" id="madl_profile_name" value="<?php echo esc_attr( $profile_to_edit->profile_name ); ?>" class="regular-text" required>
                        <p class="description"><?php esc_html_e( 'A unique name for this AD configuration (e.g., "Main Office AD", "Sales Dept AD").', 'multi-ad-login' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="madl_is_default"><?php esc_html_e( 'Is Default Profile?', 'multi-ad-login' ); ?></label></th>
                        <td><input name="madl_is_default" type="checkbox" id="madl_is_default" value="1" <?php checked( $profile_to_edit->is_default, 1 ); ?>>
                        <p class="description"><?php esc_html_e( 'If checked, this profile will be used for users logging in with just a username (no @domain.com). Only one profile can be default.', 'multi-ad-login' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="madl_domain_identifier"><?php esc_html_e( 'Domain Identifier (for UPNs)', 'multi-ad-login' ); ?></label></th>
                        <td><input name="madl_domain_identifier" type="text" id="madl_domain_identifier" value="<?php echo esc_attr( $profile_to_edit->domain_identifier ); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e( 'The domain part of a UPN, e.g., "example.com" for users like "user@example.com". Leave empty if this profile is only for default non-UPN logins.', 'multi-ad-login' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="madl_base_dn"><?php esc_html_e( 'Base DN', 'multi-ad-login' ); ?></label></th>
                        <td><input name="madl_base_dn" type="text" id="madl_base_dn" value="<?php echo esc_attr( $profile_to_edit->base_dn ); ?>" class="regular-text" required>
                        <p class="description"><?php esc_html_e( 'The Base Distinguished Name for LDAP searches, e.g., "DC=example,DC=com".', 'multi-ad-login' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="madl_domain_controllers"><?php esc_html_e( 'Domain Controller(s)', 'multi-ad-login' ); ?></label></th>
                        <td><textarea name="madl_domain_controllers" id="madl_domain_controllers" class="large-text" rows="3" required><?php echo esc_textarea( $profile_to_edit->domain_controllers ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Hostname(s) or IP address(es) of domain controllers, separated by semicolons, e.g., "dc1.example.com;dc2.example.com".', 'multi-ad-login' ); ?></p></td>
                    </tr>
                     <tr>
                        <th scope="row"><label for="madl_account_suffixes"><?php esc_html_e( 'Account Suffix(es)', 'multi-ad-login' ); ?></label></th>
                        <td><textarea name="madl_account_suffixes" id="madl_account_suffixes" class="large-text" rows="2"><?php echo esc_textarea( $profile_to_edit->account_suffixes ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Account suffixes (like @example.com) used by AdLdap, separated by semicolons. The first one may be used for sAMAccountName logins if UPN is not formed. For UPN logins (user@domain), the suffix is derived from the login.', 'multi-ad-login' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="madl_port"><?php esc_html_e( 'LDAP Port', 'multi-ad-login' ); ?></label></th>
                        <td><input name="madl_port" type="number" step="1" min="1" id="madl_port" value="<?php echo esc_attr( $profile_to_edit->port ); ?>" class="small-text" required>
                        <p class="description"><?php esc_html_e( 'Usually 389 (LDAP/TLS) or 636 (LDAPS).', 'multi-ad-login' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Encryption', 'multi-ad-login' ); ?></th>
                        <td>
                            <fieldset>
                                <label><input name="madl_use_tls" type="checkbox" id="madl_use_tls" value="1" <?php checked( $profile_to_edit->use_tls, 1 ); ?>> <?php esc_html_e( 'Use TLS (STARTTLS)', 'multi-ad-login' ); ?></label><br>
                                <label><input name="madl_use_ssl" type="checkbox" id="madl_use_ssl" value="1" <?php checked( $profile_to_edit->use_ssl, 1 ); ?>> <?php esc_html_e( 'Use SSL (LDAPS)', 'multi-ad-login' ); ?></label>
                                <p class="description"><?php esc_html_e( 'Choose one or none. TLS is generally preferred over SSL if available.', 'multi-ad-login' ); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                     <tr>
                        <th scope="row"><label for="madl_allow_self_signed"><?php esc_html_e( 'Allow Self-Signed Certificates?', 'multi-ad-login' ); ?></label></th>
                        <td><input name="madl_allow_self_signed" type="checkbox" id="madl_allow_self_signed" value="1" <?php checked( $profile_to_edit->allow_self_signed, 1 ); ?>>
                        <p class="description"><?php esc_html_e( 'Only check this for testing or if you understand the security implications.', 'multi-ad-login' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="madl_network_timeout"><?php esc_html_e( 'Network Timeout (seconds)', 'multi-ad-login' ); ?></label></th>
                        <td><input name="madl_network_timeout" type="number" step="1" min="1" id="madl_network_timeout" value="<?php echo esc_attr( $profile_to_edit->network_timeout ); ?>" class="small-text" required>
                         <p class="description"><?php esc_html_e( 'Timeout for LDAP operations.', 'multi-ad-login' ); ?></p></td>
                    </tr>
                    <tr><td colspan="2"><h3><?php esc_html_e( 'Optional Bind Credentials', 'multi-ad-login' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Leave blank to attempt anonymous bind or if your AdLdap library handles service account binding differently. These are for the plugin to connect to AD, not for user authentication.', 'multi-ad-login' ); ?></p>
                    </td></tr>
                    <tr>
                        <th scope="row"><label for="madl_bind_username"><?php esc_html_e( 'Bind Username', 'multi-ad-login' ); ?></label></th>
                        <td><input name="madl_bind_username" type="text" id="madl_bind_username" value="<?php echo esc_attr( $profile_to_edit->bind_username ); ?>" class="regular-text" autocomplete="username">
                        <p class="description"><?php esc_html_e( 'e.g., "cn=service_account,cn=Users,dc=example,dc=com" or "service_account@example.com".', 'multi-ad-login' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="madl_bind_password"><?php esc_html_e( 'Bind Password', 'multi-ad-login' ); ?></label></th>
                        <td><input name="madl_bind_password" type="password" id="madl_bind_password" value="" class="regular-text" autocomplete="new-password"> <?php // Always empty for security, never pre-fill ?>
                        <p class="description"><?php echo $is_editing_existing_profile ? esc_html__( 'Leave blank to keep the current password. Enter a new password to change it.', 'multi-ad-login' ) : esc_html__( 'Enter the password for the bind account.', 'multi-ad-login' ); ?></p>
                        <?php if ( $is_editing_existing_profile && !empty($profile_to_edit->bind_password) ): // bind_password here is a placeholder, actual password is not sent to client ?>
                             <label><input name="madl_clear_bind_password" type="checkbox" value="1"> <?php esc_html_e( 'Clear current password (set to empty)', 'multi-ad-login' ); ?></label>
                        <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="submit">
                <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo $is_editing_existing_profile ? esc_attr__( 'Update Profile', 'multi-ad-login' ) : esc_attr__( 'Add Profile', 'multi-ad-login' ); ?>">
                <a href="<?php echo esc_url( add_query_arg( array( 'page' => MADL_SETTINGS_SLUG ), admin_url( 'options-general.php' ) ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'multi-ad-login' ); ?></a>
            </p>
        </form>

    <?php else : // This 'else' corresponds to !( $can_show_form ), meaning it's the List view or an error occurred preventing form display ?>
        <h2><?php esc_html_e( 'Manage AD Profiles', 'multi-ad-login' ); ?>
            <a href="<?php echo esc_url( add_query_arg( array( 'page' => MADL_SETTINGS_SLUG, 'action' => 'add' ), admin_url( 'options-general.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'multi-ad-login' ); ?></a>
        </h2>
        <p><?php esc_html_e('Define configurations for connecting to different Active Directory servers. Users logging in with a simple username (no @) will use the "Default" profile. Users logging in with a UPN (e.g., user@example.com) will use the profile matching the "Domain Identifier" (e.g., "example.com").', 'multi-ad-login');?></p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Profile Name', 'multi-ad-login' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Default', 'multi-ad-login' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Domain Identifier', 'multi-ad-login' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Base DN', 'multi-ad-login' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Domain Controllers', 'multi-ad-login' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Actions', 'multi-ad-login' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $all_profiles ) ) : ?>
                    <?php foreach ( $all_profiles as $profile ) : ?>
                        <tr>
                            <td><strong><a href="<?php echo esc_url( add_query_arg( array( 'page' => MADL_SETTINGS_SLUG, 'action' => 'edit', 'profile_id' => $profile->id ), admin_url( 'options-general.php' ) ) ); ?>"><?php echo esc_html( $profile->profile_name ); ?></a></strong>
                                <div class="row-actions">
                                    <span class="edit"><a href="<?php echo esc_url( add_query_arg( array( 'page' => MADL_SETTINGS_SLUG, 'action' => 'edit', 'profile_id' => $profile->id ), admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'multi-ad-login' ); ?></a> | </span>
                                    <span class="duplicate"><a href="<?php echo esc_url( add_query_arg( array( 'page' => MADL_SETTINGS_SLUG, 'action' => 'add', 'profile_id' => $profile->id ), admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Duplicate', 'multi-ad-login' ); ?></a> | </span>
                                    <span class="delete"><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'madl_delete_profile', 'madl_profile_id' => $profile->id ), admin_url( 'admin-post.php' ) ), 'madl_delete_profile_action_' . $profile->id, 'madl_delete_profile_nonce' ) ); ?>"
                                       onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this profile?', 'multi-ad-login' ); ?>');"
                                       style="color:red;"><?php esc_html_e( 'Delete', 'multi-ad-login' ); ?></a></span>
                                </div>
                            </td>
                            <td><?php echo $profile->is_default ? esc_html__( 'Yes', 'multi-ad-login' ) : esc_html__( 'No', 'multi-ad-login' ); ?></td>
                            <td><?php echo esc_html( $profile->domain_identifier ); ?></td>
                            <td><?php echo esc_html( $profile->base_dn ); ?></td>
                            <td><?php echo nl2br(esc_html( str_replace(';', "; ", $profile->domain_controllers ) ) ); ?></td> <?php // Added space after ; for readability ?>
                            <td>
                                <a href="<?php echo esc_url( add_query_arg( array( 'page' => MADL_SETTINGS_SLUG, 'action' => 'edit', 'profile_id' => $profile->id ), admin_url( 'options-general.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'multi-ad-login' ); ?></a> |
                                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'madl_delete_profile', 'madl_profile_id' => $profile->id ), admin_url( 'admin-post.php' ) ), 'madl_delete_profile_action_' . $profile->id, 'madl_delete_profile_nonce' ) ); ?>"
                                   onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this profile?', 'multi-ad-login' ); ?>');"
                                   style="color:red;"><?php esc_html_e( 'Delete', 'multi-ad-login' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="6"><?php esc_html_e( 'No AD profiles configured yet.', 'multi-ad-login' ); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p style="margin-top:20px;">
            <strong><?php esc_html_e('Example AD Values:', 'multi-ad-login'); ?></strong><br>
            <?php esc_html_e('Profile Name: My Company AD', 'multi-ad-login'); ?><br>
            <?php esc_html_e('Is Default: (Checked if this is your primary AD for non-UPN logins)', 'multi-ad-login'); ?><br>
            <?php esc_html_e('Domain Identifier: company.com', 'multi-ad-login'); ?><br>
            <?php esc_html_e('Base DN: DC=company,DC=com', 'multi-ad-login'); ?><br>
            <?php esc_html_e('Domain Controller(s): dc1.company.com;dc2.company.com', 'multi-ad-login'); ?><br>
            <?php esc_html_e('Account Suffixes: @company.com;@staff.company.com', 'multi-ad-login'); ?><br>
            <?php esc_html_e('LDAP Port: 389 (or 636 for LDAPS)', 'multi-ad-login'); ?><br>
            <?php esc_html_e('Encryption: None / TLS / SSL', 'multi-ad-login'); ?><br>
            <?php esc_html_e('Network Timeout: 5 (seconds)', 'multi-ad-login'); ?><br>
            <?php esc_html_e('Bind Username (Optional): CN=ServiceLDAP,OU=ServiceAccounts,DC=company,DC=com', 'multi-ad-login'); ?><br>
            <?php esc_html_e('Bind Password (Optional): YourSecurePassword', 'multi-ad-login'); ?><br>
        </p>
         <p>
            <strong><?php esc_html_e('Logging:', 'multi-ad-login'); ?></strong><br>
            <?php printf(
                // translators: %1$s: code string, %2$s: filename, %3$s: filepath, %4$s: code string, %5$s: PHP constant name
                esc_html__('To enable detailed logging, define %1$s in your %2$s file. Logs will be written to %3$s (ensure this file is writable by the web server). You can also define %4$s to force logging even if %5$s is false.', 'multi-ad-login'),
                '<code>define(\'MADL_ENABLE_LOGGING\', true);</code>',
                '<code>wp-config.php</code>',
                '<code>' . esc_html( defined('MADL_LOG_FILE') ? MADL_LOG_FILE : 'madl-debug.log' ) . '</code>', // Check if constant defined
                '<code>define(\'MADL_FORCE_LOGGING\', true);</code>',
                '<code>WP_DEBUG_LOG</code>'
            ); ?>
        </p>
    <?php endif; ?>
</div>