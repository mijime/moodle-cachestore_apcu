<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * APCu cache store main library.
 *
 * @package    cachestore_apcu
 * @copyright  2012 Sam Hemelryk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
 
 defined('MOODLE_INTERNAL') || die;

/**
 * The APCu cache store class.
 *
 * @copyright  2012 Sam Hemelryk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cachestore_apcu extends cache_store implements cache_is_key_aware, cache_is_searchable, cache_is_configurable {

    /**
     * The required version of APCu for this extension.
     */
    const REQUIRED_VERSION = '4.0.8';

    /**
     * The name of this store instance.
     * @var string
     */
    protected $name;

    /**
     * The definition used when this instance was initialised.
     * @var cache_definition
     */
    protected $definition = null;

    /**
     * The prefix to use on all keys.
     * @var string
     */
    protected $prefix = null;
    protected $prefix_length = "";

    /**
     * The prefix for this instance
     * @var string
     */
    protected $instance_id = "";

    /**
     * Static method to check that the APCu stores requirements have been met.
     *
     * It checks that the APCu extension has been loaded and that it has been enabled.
     *
     * @return bool True if the stores software/hardware requirements have been met and it can be used. False otherwise.
     */
    public static function are_requirements_met() {
        if (!extension_loaded('apcu') ||    // APCu PHP extension is not available.
            ! ( ini_get('apc.enabled') || ini_get('apcu.enabled') ) // APCu is not enabled.
        ) {
            return false;
        }

        $version = phpversion('apcu');
        return $version && version_compare($version, self::REQUIRED_VERSION, '>=');
    }

    /**
     * Static method to check if a store is usable with the given mode.
     *
     * @param int $mode One of cache_store::MODE_*
     * @return bool True if the mode is supported.
     */
    public static function is_supported_mode($mode) {
        return ($mode === self::MODE_APPLICATION || $mode === self::MODE_SESSION);
    }

    /**
     * Returns the supported features as a binary flag.
     *
     * @param array $configuration The configuration of a store to consider specifically.
     * @return int The supported features.
     */
    public static function get_supported_features(array $configuration = array()) {
        return self::SUPPORTS_DATA_GUARANTEE + self::SUPPORTS_NATIVE_TTL + self::IS_SEARCHABLE;
    }

    /**
     * Returns the supported modes as a binary flag.
     *
     * @param array $configuration The configuration of a store to consider specifically.
     * @return int The supported modes.
     */
    public static function get_supported_modes(array $configuration = array()) {
        return self::MODE_APPLICATION + self::MODE_SESSION;
    }

    /**
     * Constructs an instance of the cache store.
     *
     * This method should not create connections or perform and processing, it should be used
     *
     * @param string $name The name of the cache store
     * @param array $configuration The configuration for this store instance.
     */
    public function __construct($name, array $configuration = array()) {
        $this->name = $name;
        if(isset($configuration['instance_id']) && !empty($configuration['instance_id'])) {
            $this->instance_id = $configuration['instance_id'];
        }
    }

    /**
     * Returns the name of this store instance.
     * @return string
     */
    public function my_name() {
        return $this->name;
    }

    /**
     * Initialises a new instance of the cache store given the definition the instance is to be used for.
     *
     * This function should prepare any given connections etc.
     *
     * @param cache_definition $definition
     * @return bool
     */
    public function initialise(cache_definition $definition) {
        $this->definition = $definition;
        $this->prefix = $this->instance_id.'\\'.$definition->get_mode().'\\'.$definition->get_area().'\\'.$definition->get_component().'\\';
        $this->prefix_length = strlen($this->prefix);
        return true;
    }

    /**
     * Returns true if this cache store instance has been initialised.
     * @return bool
     */
    public function is_initialised() {
        return ($this->definition !== null);
    }

    /**
     * Prepares the given key for use.
     *
     * Should be called before all interaction.
     *
     * @return string
     */
    protected function prepare_key($key) {
        return $this->prefix . $key;
    }

    /**
     * Retrieves an item from the cache store given its key.
     *
     * @param string $key The key to retrieve
     * @return mixed The data that was associated with the key, or false if the key did not exist.
     */
    public function get($key) {
        return apcu_fetch($this->prepare_key($key));
    }

    /**
     * Retrieves several items from the cache store in a single transaction.
     *
     * If not all of the items are available in the cache then the data value for those that are missing will be set to false.
     *
     * @param array $keys The array of keys to retrieve
     * @return array An array of items from the cache. There will be an item for each key, those that were not in the store will
     *      be set to false.
     */
    public function get_many($keys) {
        $map = array();
        foreach ($keys as $key) {
            $map[$key] = $this->prepare_key($key);
        }
        $outcomes = array();
        $results = apcu_fetch($map);
        foreach ($map as $key => $ckey) {
            if ($success && isset($results[$ckey]) && !empty($results[$ckey])) {
                $outcomes[$key] = $results[$ckey];
            } else {
                $outcomes[$key] = false;
            }
        }
        return $outcomes;
    }

    /**
     * Sets an item in the cache given its key and data value.
     *
     * @param string $key The key to use.
     * @param mixed $data The data to set.
     * @return bool True if the operation was a success false otherwise.
     */
    public function set($key, $data) {
        return apcu_store($this->prepare_key($key), $data, $this->definition->get_ttl());
    }

    /**
     * Sets many items in the cache in a single transaction.
     *
     * @param array $keyvaluearray An array of key value pairs. Each item in the array will be an associative array with two
     *      keys, 'key' and 'value'.
     * @return int The number of items successfully set. It is up to the developer to check this matches the number of items
     *      sent ... if they care that is.
     */
    public function set_many(array $keyvaluearray) {
        $store = array();
        foreach ($keyvaluearray as $pair) {
            $store[$this->prepare_key($pair['key'])] = $pair['value'];
        }
        $result = apcu_store($store, null, $this->definition->get_ttl());
        return count($map) - count($result);
    }

    /**
     * Deletes an item from the cache store.
     *
     * @param string $key The key to delete.
     * @return bool Returns true if the operation was a success, false otherwise.
     */
    public function delete($key) {
        return apcu_delete($this->prepare_key($key));
    }

    /**
     * Deletes several keys from the cache in a single action.
     *
     * @param array $keys The keys to delete
     * @return int The number of items successfully deleted.
     */
    public function delete_many(array $keys) {
        $count = 0;
        foreach ($keys as $key) {
            if ($this->delete($key)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Purges the cache deleting all items within it.
     *
     * @return boolean True on success. False otherwise.
     */
    public function purge() {
        $iterator = new APCUIterator('#^' . preg_quote($this->prefix, '#') . '#');
        return apcu_delete($iterator);
    }

    /**
     * Performs any necessary clean up when the store instance is being deleted.
     */
    public function instance_deleted() {
        $this->purge();
    }

    /**
     * Generates an instance of the cache store that can be used for testing.
     *
     * Returns an instance of the cache store, or false if one cannot be created.
     *
     * @param cache_definition $definition
     * @return cache_store
     */
    public static function initialise_test_instance(cache_definition $definition) {
        $testperformance = get_config('cachestore_apcu', 'testperformance');
        if (empty($testperformance)) {
            return false;
        }
        if (!self::are_requirements_met()) {
            return false;
        }
        $name = 'APCu test';
        $cache = new cachestore_apcu($name);
        $cache->initialise($definition);
        return $cache;
    }

    /**
     * Test is a cache has a key.
     *
     * @param string|int $key
     * @return bool True if the cache has the requested key, false otherwise.
     */
    public function has($key) {
        return apcu_exists($this->prepare_key($key));
    }

    /**
     * Test if a cache has at least one of the given keys.
     *
     * @param array $keys
     * @return bool True if the cache has at least one of the given keys
     */
    public function has_any(array $keys) {
        foreach($keys as $key) {
            if(apcu_exists($this->prepare_key($key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Test is a cache has all of the given keys.
     *
     * @param array $keys
     * @return bool True if the cache has all of the given keys, false otherwise.
     */
    public function has_all(array $keys) {
        foreach($keys as $key) {
            if(!apcu_exists($this->prepare_key($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Find all of the keys being used by the cache store
     *
     * @return array.
     */
    public function find_all() {
         return $this->find_by_prefix("");
    }

    /**
     * Find all of the keys whose keys start with the given prefix
     *
     * @param string $prefix
     * @return array.
     */
    public function find_by_prefix($prefix) {
         $prefix = $this->prepare_key($prefix);
         $iter = new APCUIterator('#^' . preg_quote($prefix, '#') . '#', APC_ITER_KEY);
         $result = array();
         foreach($iter as $key => $junk) {
             $result[] = substr($key, $this->prefix_length);
         }
         return $result;
    }

    /**
     * Given the data from the add instance form this function creates a configuration array.
     *
     * @param stdClass $data
     * @return array
     */
    public static function config_get_configuration_array($data) {
        return array(
            "instance_id" => $data->instance_id
        );
    }

    /**
     * Allows the cache store to set its data against the edit form before it is shown to the user.
     *
     * @param moodleform $editform
     * @param array $config
     */
    public static function config_set_edit_form_data(moodleform $editform, array $config){
        $editform->set_data($config);
    }

    /**
     * Generates an instance of the cache store that can be used for testing.
     *
     * @param cache_definition $definition
     * @return cachestore_apcu|false
     */
    public static function initialise_unit_test_instance(cache_definition $definition) {
        if (!self::are_requirements_met()) {
            return false;
        }
        if (!defined('TEST_CACHESTORE_APCU')) {
            return false;
        }

        $store = new cachestore_apcu('Test APCu', array());
        if (!$store->is_ready()) {
            return false;
        }
        $store->initialise($definition);

        return $store;
    }
}
