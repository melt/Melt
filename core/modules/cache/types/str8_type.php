<?php

namespace nanomvc\cache;

/**
 * Tag module - only designed to be used inside cache module.
 */
class Str8Type extends \nanomvc\Type {    
    public function getSQLType() {
        return "varchar(8)";
    }

    public function getInterface($name, $label) { }

    public function readInterface($name) { }
}