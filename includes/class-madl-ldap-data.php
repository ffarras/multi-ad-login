<?php
// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

class MADL_Ldap_Data
{

    /**
     * Parses the raw output of ldap_get_entries() into a standardized user data array.
     *
     * @param array $raw_ldap_data The raw data from ldap_get_entries().
     * @return array The parsed user data.
     */
    public static function parse_ldap_data(array $raw_ldap_data): array
    {
        $parsed_data = [];
        if (isset($raw_ldap_data[0]) && is_array($raw_ldap_data[0])) {
            foreach ($raw_ldap_data[0] as $key => $value) {
                if (!is_int($key) && $key !== 'count' && $key !== 'dn') {
                    if (isset($value['count']) && $value['count'] > 0) {
                        $parsed_data[strtolower($key)] = array_slice($value, 0, $value['count']);
                    }
                }
            }
        }
        return $parsed_data;
    }

    /**
     * Safely retrieves a user attribute from the parsed LDAP data.
     *
     * @param array $parsed_data The parsed user data.
     * @param string $attribute_name The name of the attribute to retrieve (e.g., 'samaccountname').
     * @param mixed $default_value A default value to return if the attribute is not found.
     * @return mixed The attribute value or the default value.
     */
    public static function get_attribute(array $parsed_data, string $attribute_name, $default_value = null)
    {
        return isset($parsed_data[strtolower($attribute_name)][0]) ? $parsed_data[strtolower($attribute_name)][0] : $default_value;
    }
}
