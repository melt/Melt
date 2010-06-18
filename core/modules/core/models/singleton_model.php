<?php namespace nmvc\core;

/**
 * A singleton model is a model that only and always has exactly one instance.
 * Singleton models does not have an unlinked state.
 * Use get() to get the instance.
 */
abstract class SingletonModel extends \nmvc\AppModel {
    /**
     * Returns this SingletonModel instance.
     * This function ensures that exactly one exists.
     */
    public static function get() {
        static $singleton_model_cache = array();
        $class_name = get_called_class();
        if (isset($singleton_model_cache[$class_name]))
            return $singleton_model_cache[$class_name];
        // Fetching singleton model is done in an atomic operations to ensure no duplicates.
        static::lock();
        $singleton_model = parent::selectFirst("");
        if ($singleton_model === null)
            $singleton_model = self::insert();
        $singleton_model->store();
        // Exiting critical section.
        \nmvc\db\unlock();
        return $singleton_model_cache[$class_name] = $singleton_model;
    }

    private static function noSupport($function) {
        throw new \Exception("SingletonModels does not support $function().", \E_USER_ERROR);
    }


    public static function selectByID($id) {
        return self::get();
    }

    public static function selectFirst($where, $order = "") {
        return self::get();
    }

    public static function insert() {
        return self::get();
    }

    // Functions not supported by SingletonModels.
    public static function selectFreely($sqldata) { self::noSupport(__FUNCTION__);}
    public static function selectWhere($where = "", $offset = 0, $limit = 0, $order = "") { self::noSupport(__FUNCTION__);}
    public static function unlinkByID($id) { self::noSupport(__FUNCTION__);}
    public static function unlinkFreely($sqldata) { self::noSupport(__FUNCTION__);}
    public static function unlinkWhere($where = "", $offset = 0, $limit = 0) { self::noSupport(__FUNCTION__);}
    public function unlink() { self::noSupport(__FUNCTION__);}
    public static function count($where = "") { self::noSupport(__FUNCTION__);}
    public function __clone() { self::noSupport(__FUNCTION__);}
}
