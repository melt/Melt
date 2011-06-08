<?php namespace nmvc\core;

class EnumType extends \nmvc\AppType {
    private $enumeration = null;

    public function __construct(array $enumeration) {
        parent::__construct();
        if (\count($enumeration) == 0)
            \trigger_error("The enumeration must have at least one index.", \E_USER_ERROR);
        $this->enumeration = \array_combine($enumeration, $enumeration);
    }

    /** Make sure GET never returns an invalid value for this type. */
    public function get() {
        if (isset($this->enumeration[$this->value])) {
            return $this->value;
        } else {
            return \reset($this->enumeration);
        }
    }

    public function set($value) {
        if (!\is_scalar($value) || !isset($this->enumeration[$value]))
            $this->value = \reset($this->enumeration);
        else
            $this->value = $value;
    }

    public function getEnum() {
        return $this->enumeration;
    }

    public function getSQLType() {
        return "ENUM(" . \implode(", ", \array_map(function($enum_token) {
            return \nmvc\db\strfy($enum_token);
        }, $this->enumeration)) . ")";
    }

    public function getSQLValue() {
        return \nmvc\db\strfy($this->get());
    }

    public function getInterface($name) {
        $html = "<select name=\"$name\" id=\"$name\">";
        $selected = ' selected="selected"';
        foreach ($this->enumeration as $enum_token) {
            $label = escape($enum_token);
            $s = ($this->value == $enum_token)? $selected: null;
            $html .= "<option$s value=\"$label\">$label</option>";
        }
        $html .= "</select>";
        return $html;
    }

    public function readInterface($name) {
        $this->set(@$_POST[$name]);
    }

    public function __toString() {
        return escape($this->get());
    }
}