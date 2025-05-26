<?php
// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

class MADL_Db
{

    private static $table_name_static = ''; // Renamed for clarity in static context if needed

    // Instance property for table name
    private $table_name_instance = '';

    public function __construct()
    {
        global $wpdb;
        // Initialize both static and instance properties for flexibility
        // Though instance one is primarily used by instance methods.
        self::$table_name_static = $wpdb->prefix . 'madl_ad_profiles';
        $this->table_name_instance = $wpdb->prefix . 'madl_ad_profiles';
    }

    /**
     * Get the fully qualified table name.
     * Useful for static methods if they aren't passed $wpdb or don't want to re-declare prefix.
     * @return string
     */
    private static function get_table_name() {
        global $wpdb;
        if (empty(self::$table_name_static)) { // Ensure it's set if constructor hasn't run (e.g. direct static call)
            self::$table_name_static = $wpdb->prefix . 'madl_ad_profiles';
        }
        return self::$table_name_static;
    }

    /**
     * Create the AD profiles table.
     */
    public static function create_table()
    {
        global $wpdb;
        // Use the static getter for consistency, though local var is also fine.
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // It's crucial that $charset_collate is not empty for dbDelta.
        if (empty($charset_collate)) {
            // Fallback to a common default if somehow $wpdb->get_charset_collate() is empty.
            // This should ideally not happen in a normal WP environment.
            $charset_collate = "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            MADL_Logger::log("Warning: \$wpdb->get_charset_collate() was empty. Using fallback: " . $charset_collate, 'WARNING');
        }

        // Removed end-of-line comments from column definitions as dbDelta can be picky.
        // Added UNSIGNED to network_timeout for clarity, though tinyint(3) default 5 is fine.
        $sql = "CREATE TABLE {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            profile_name varchar(255) NOT NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            domain_identifier varchar(255) DEFAULT NULL,
            base_dn varchar(255) NOT NULL,
            domain_controllers text NOT NULL,
            port smallint(5) UNSIGNED NOT NULL DEFAULT 389,
            use_tls tinyint(1) NOT NULL DEFAULT 0,
            use_ssl tinyint(1) NOT NULL DEFAULT 0,
            allow_self_signed tinyint(1) NOT NULL DEFAULT 0,
            network_timeout tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
            account_suffixes text DEFAULT NULL,
            bind_username varchar(255) DEFAULT NULL,
            bind_password text DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_profile_name (profile_name),
            KEY domain_identifier_key (domain_identifier)
        ) {$charset_collate};";

        MADL_Logger::log("Preparing to execute dbDelta. SQL: " . $sql, 'DEBUG'); // Log the SQL

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // --- Fix for "unexpected output" and better logging ---
        ob_start(); // Start output buffering
        $dbdelta_results = dbDelta($sql); // dbDelta returns an array of messages
        $dbdelta_buffered_output = ob_get_clean(); // Get any direct output from dbDelta and stop buffering

        if (!empty($dbdelta_buffered_output)) {
            MADL_Logger::log("Direct output captured from dbDelta: " . trim($dbdelta_buffered_output), 'DEBUG');
        }

        if (is_array($dbdelta_results) && !empty($dbdelta_results)) {
            MADL_Logger::log("dbDelta execution results (array): " . print_r($dbdelta_results, true), 'DEBUG');
            foreach ($dbdelta_results as $result_key => $result_message) {
                // You can check $result_message for specific errors reported by dbDelta itself
                // e.g., "Table '...' already exists and is different..."
                if (stripos($result_message, 'error') !== false || 
                    (stripos($result_message, 'created') === false && 
                     stripos($result_message, 'altered') === false &&
                     stripos($result_message, 'already up-to-date') === false && // WordPress 5.3+
                     stripos($result_message, 'already exists') === false )) { // Older WordPress might just say "already exists"
                    MADL_Logger::log("dbDelta message for {$result_key}: {$result_message}", 'WARNING');
                }
            }
        } else {
            MADL_Logger::log("dbDelta did not return specific results (or table was up to date).", 'DEBUG');
        }
        // --- End fix ---

        MADL_Logger::log("Database table {$table_name} checked/created (dbDelta process completed).", 'INFO');

        // Check $wpdb->last_error AFTER dbDelta call
        if (!empty($wpdb->last_error)) {
            MADL_Logger::log("WPDB error after dbDelta: " . $wpdb->last_error, 'ERROR');
        }
    }

    /**
     * Add a new AD profile.
     *
     * @param array $data Profile data.
     * @return int|false Inserted ID or false on failure.
     */
    public function add_ad_profile($data)
    {
        global $wpdb;

        // Use the instance property for table name
        $table_name = $this->table_name_instance;
        if (empty($table_name)) { // Fallback if constructor wasn't called properly (should not happen with `new`)
            $table_name = self::get_table_name();
            MADL_Logger::log("Warning: Instance table name was empty in add_ad_profile. Using static getter.", 'WARNING');
        }


        // If this profile is set as default, ensure no other profile is default.
        if (! empty($data['is_default']) && $data['is_default']) {
            $wpdb->update($table_name, array('is_default' => 0), array('is_default' => 1), array('%d'), array('%d'));
            MADL_Logger::log("Cleared previous default AD profiles.", 'DEBUG');
        }

        $insert_data = array(
            'profile_name'        => sanitize_text_field($data['profile_name']),
            'is_default'          => ! empty($data['is_default']) ? 1 : 0,
            'domain_identifier'   => isset($data['domain_identifier']) ? sanitize_text_field($data['domain_identifier']) : null,
            'base_dn'             => sanitize_text_field($data['base_dn']),
            'domain_controllers'  => sanitize_textarea_field($data['domain_controllers']),
            'port'                => isset($data['port']) ? absint($data['port']) : 389, // Provide default if missing
            'use_tls'             => ! empty($data['use_tls']) ? 1 : 0,
            'use_ssl'             => ! empty($data['use_ssl']) ? 1 : 0,
            'allow_self_signed'   => ! empty($data['allow_self_signed']) ? 1 : 0,
            'network_timeout'     => isset($data['network_timeout']) ? absint($data['network_timeout']) : 5, // Provide default
            'account_suffixes'    => isset($data['account_suffixes']) ? sanitize_textarea_field($data['account_suffixes']) : null,
            'bind_username'       => isset($data['bind_username']) ? sanitize_text_field($data['bind_username']) : null,
            'bind_password'       => isset($data['bind_password']) ? $data['bind_password'] : null,
        );

        // Define formats for $wpdb->insert
        $formats = array(
            '%s', // profile_name
            '%d', // is_default
            '%s', // domain_identifier
            '%s', // base_dn
            '%s', // domain_controllers
            '%d', // port
            '%d', // use_tls
            '%d', // use_ssl
            '%d', // allow_self_signed
            '%d', // network_timeout
            '%s', // account_suffixes
            '%s', // bind_username
            '%s', // bind_password - store as string, even if sensitive
        );


        $result = $wpdb->insert(
            $table_name,
            $insert_data,
            $formats
        );

        if ($result) {
            MADL_Logger::log("Added AD profile: " . $data['profile_name'] . " with ID: " . $wpdb->insert_id, 'INFO');
            return $wpdb->insert_id;
        } else {
            MADL_Logger::log("Failed to add AD profile: " . $data['profile_name'] . " - WPDB Error: " . $wpdb->last_error, 'ERROR');
            // Log the data that failed to insert for debugging (careful with sensitive data in production logs)
            // MADL_Logger::log("Data for failed insert: " . print_r($insert_data, true), 'DEBUG');
            return false;
        }
    }

    /**
     * Update an existing AD profile.
     *
     * @param int   $id   Profile ID.
     * @param array $data Profile data.
     * @return bool True on success, false on failure.
     */
    public function update_ad_profile($id, $data)
    {
        global $wpdb;
        $id = absint($id);
        $table_name = $this->table_name_instance;

        // If this profile is set as default, ensure no other profile is default (except this one).
        if (! empty($data['is_default']) && $data['is_default']) {
            $wpdb->query($wpdb->prepare("UPDATE " . $table_name . " SET is_default = 0 WHERE is_default = 1 AND id != %d", $id));
            MADL_Logger::log("Cleared previous default AD profiles before update.", 'DEBUG');
        }

        $update_data = array(
            'profile_name'        => sanitize_text_field($data['profile_name']),
            'is_default'          => ! empty($data['is_default']) ? 1 : 0,
            'domain_identifier'   => isset($data['domain_identifier']) ? sanitize_text_field($data['domain_identifier']) : null,
            'base_dn'             => sanitize_text_field($data['base_dn']),
            'domain_controllers'  => sanitize_textarea_field($data['domain_controllers']),
            'port'                => isset($data['port']) ? absint($data['port']) : 389,
            'use_tls'             => ! empty($data['use_tls']) ? 1 : 0,
            'use_ssl'             => ! empty($data['use_ssl']) ? 1 : 0,
            'allow_self_signed'   => ! empty($data['allow_self_signed']) ? 1 : 0,
            'network_timeout'     => isset($data['network_timeout']) ? absint($data['network_timeout']) : 5,
            'account_suffixes'    => isset($data['account_suffixes']) ? sanitize_textarea_field($data['account_suffixes']) : null,
            'bind_username'       => isset($data['bind_username']) ? sanitize_text_field($data['bind_username']) : null,
            // bind_password handled separately below
        );
        $update_formats = array(
            '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s'
        );

        // Handle password update separately
        if (isset($data['bind_password'])) {
            // Update if password is not empty OR if 'clear_bind_password' is explicitly set to true/1
            // This allows setting an empty password if desired, or leaving it unchanged if key not present.
            if (!empty($data['bind_password']) || (isset($data['clear_bind_password']) && $data['clear_bind_password'] == '1')) {
                $update_data['bind_password'] = $data['bind_password']; // Sanitize if needed, but passwords often stored as-is before hashing/encryption
                $update_formats[] = '%s';
            }
        }

        $result = $wpdb->update(
            $table_name,
            $update_data,
            array('id' => $id), // WHERE clause
            $update_formats,    // formats for $update_data
            array('%d')         // format for WHERE clause
        );


        if ($result !== false) { // Update returns number of rows affected (0 if no change) or false on error
            MADL_Logger::log("Updated AD profile ID: " . $id . " (Rows affected: " . $result . ")", 'INFO');
            return true;
        } else {
            MADL_Logger::log("Failed to update AD profile ID: " . $id . " - WPDB Error: " . $wpdb->last_error, 'ERROR');
            return false;
        }
    }

    /**
     * Delete an AD profile.
     *
     * @param int $id Profile ID.
     * @return bool True on success, false on failure.
     */
    public function delete_ad_profile($id)
    {
        global $wpdb;
        $id = absint($id);
        $table_name = $this->table_name_instance;
        $result = $wpdb->delete($table_name, array('id' => $id), array('%d'));

        if ($result) {
            MADL_Logger::log("Deleted AD profile ID: " . $id, 'INFO');
            return true;
        } else {
            MADL_Logger::log("Failed to delete AD profile ID: " . $id . " - WPDB Error: " . $wpdb->last_error, 'ERROR');
            return false;
        }
    }

    /**
     * Get a specific AD profile by ID.
     *
     * @param int $id Profile ID.
     * @return object|null Profile object or null if not found.
     */
    public static function get_ad_profile($id)
    {
        global $wpdb;
        $id = absint($id);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::get_table_name() . " WHERE id = %d", $id));
    }

    /**
     * Get all AD profiles.
     *
     * @return array Array of profile objects.
     */
    public static function get_all_ad_profiles()
    {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM " . self::get_table_name() . " ORDER BY profile_name ASC");
    }

    /**
     * Get the default AD profile.
     *
     * @return object|null Default profile object or null if not found.
     */
    public static function get_default_ad_profile()
    {
        global $wpdb;
        return $wpdb->get_row("SELECT * FROM " . self::get_table_name() . " WHERE is_default = 1 LIMIT 1");
    }

    /**
     * Get an AD profile by its domain identifier.
     *
     * @param string $identifier The domain identifier (e.g., example.com).
     * @return object|null Profile object or null if not found.
     */
    public static function get_ad_profile_by_identifier($identifier)
    {
        global $wpdb;
        $identifier = sanitize_text_field($identifier);
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::get_table_name() . " WHERE domain_identifier = %s LIMIT 1", $identifier));
    }
}