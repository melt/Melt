<?php

namespace nanomvc\qmi;

/**
 * An interface to a model.
 * Designed to be used directly in the view.
 */
class ModelInterface {
    private $instances = array();
    private $components = array();
    private $setters = array();
    private $component_name_use = array();
    private $success_url;
    private $pending_invalid_session_data_clear = false;

    /**
     * Constructs this interface. Prints a HTML form start tag.
     */
    public function __construct($success_url = null, $extra_attributes = array()) {
        // Initialize interface data
        $this->success_url = $success_url;
        $attributes = array();
        foreach ($extra_attributes as $key => $value)
            $attributes = '$key="' . escape($value) .'"';
        echo '<form enctype="multipart/form-data" action="' . url(REQ_URL) . '" method="post" ' . implode(" ", $attributes) . '>';
    }

    public function __destruct() {
        if (!$this->pending_invalid_session_data_clear)
            return;
        // Clearing the invalidation data as it was used this request.
        unset($_SESSION["qmi_invalid"]);
    }

    private function getInstanceKey(Model $instance) {
        // Stores and identifies instances by appending the ID to the class name.
        $id = $instance->getID();
        $instance_key = ($id > 0? $id: 0) . get_class($instance);
        if (!in_array($instance_key, $this->instances))
            $this->instances[] = $instance_key;
        return $instance_key;
    }

    /**
     * Specifies that some arguments on the given instance should be
     * @param Model $instance
     * @param <type> $values
     */
    public function setOnSuccess(Model $instance, $values) {
        // Reading the setters from the rest of the arguments.
        $instance_key = $this->getInstanceKey($instance);
        foreach ($values as $field_name => $value) {
            if (!isset($instance->$field_name))
                trigger_error("'$field_name' is not a valid field/column name!", \E_USER_ERROR);
            $this->setters[] = array($instance_key, $field_name, $value);
        }
    }

    /**
     * This function returns a set of HTML components for this model.
     * @param Model $instance Instance to operate on.
     * @param array $components Name of the fields to generate HTML components for mapped to their labels.
     * @return array
     */
    public function getComponents(Model $instance, $components, $component_css_class = "qmi_component", $invalid_label_css_class = "qmi_invalid_label") {
        $html_components = array();
        $invalidation_data = array();
        $instance_key = $this->getInstanceKey($instance);
        if (isset($_SESSION["qmi_invalid"][$instance_key])) {
            $invalidation_data = $_SESSION["qmi_invalid"];
            // Flag clearing the model interface when the request is complete
            // as invalid data has been used and displayed.
            $this->pending_invalid_session_data_clear = true;
        }
        foreach ($components as $field_name => $component_label) {
            if (is_integer($field_name)) {
                // Numeric indexes represents components without labels.
                $field_name = $component_label;
                $component_label = null;
            }
            if (!isset($instance->$field_name))
                trigger_error("'$field_name' is not a valid field/column name!", \E_USER_ERROR);
            // Set the output html name.
            $component_html_name = \nanomvc\string\random_alphanum_str(7);
            // Get the output html array key.
            if (isset($this->component_name_use[$field_name])) {
                $this->component_name_use[$field_name] = 0;
                $output_key = $field_name;
            } else
                $output_key = $field_name . "_" . ($this->component_name_use[$field_name]++);
            // Generate the component interface.
            $component_interface = $instance->$field_name->getInterface($component_html_name, $component_label);
            // If an interface was returned, output it.
            if (is_string($component_interface) && strlen($component_interface) > 0) {
                // Append error label if one is specified.
                if (isset($invalidation_data[$instance_key][$component_html_name])) {
                    $component_interface .= "<span class=\"$invalid_label_css_class\">"
                    . $invalidation_data[$instance_key][$component_html_name]
                    . "</span>";
                }
                // Returning the interface.
                $html_components[$output_key] = "<div class=\"$component_css_class\">"
                . $component_interface . "</div>";
            }
            // Registering the component.
            $this->components[$component_html_name] = array($instance_key, $field_name);
        }
        return $html_components;
    }

    /**
     * Ends the interface. Prints a html form end tag.
     */
    public function finalize() {
        $qmi_key = get_qmi_key();
        $qmi_data = string\simple_crypt(gzcompress(serialize(array($this->success_url, $this->instances, $this->components, $this->setters))));
        echo '<input type="hidden" name="_qmi" value="' . $qmi_data . '" />';
        echo "</form>";
    }

    /**
     * Do not call directly.
     */
    public static function _interface_callback() {
        $qmi_data = \nanomvc\string\simple_decrypt(@$_POST['_qmi'], get_qmi_key());
        if ($qmi_data === false) {
            \nanomvc\messenger\redirectMessage(url(REQ_URL), __("The action failed, your session might have timed out. Please try again."));
            return;
        }
        list($success_url, $instances, $components, $setters) = unserialize(gzuncompress($qmi_data));
        // Fetch all instances.
        foreach ($instances as &$instance) {
            $id = intval($instance);
            $name = substr($instance, strlen($id));
            if ($id > 0) {
                $instance = call_user_func(array($name, "selectByID"), $id);
                if ($instance === false) {
                    \nanomvc\messenger\redirectMessage(url(REQ_URL), __("Action failed, the entry you edited has been deleted."));
                    return;
                }
            } else
                $instance = call_user_func(array($name, "insert"));
        }
        // Read all components from post data.
        foreach ($components as $component_name => $component) {
            list($instance_key, $field_name) = $component;
            $instance = $instances[$instance_key];
            $instance->$field->name = $field_name;
            $instance->$field->readInterface();
        }
        // Process all setters.
        foreach ($setters as $setter) {
            list($instance_key, $field_name, $value) = $setter;
            $instances[$instance_key]->$field_name->set($value);
        }
        // Validate all instances.
        $invalidation_data = array();
        foreach ($instances as $instance) {
            $ret = $instance->validate();
            // Not array or empty array = validation success.
            if (!is_array($ret) || count($ret) == 0)
                continue;
            // Store this invalidation data so it can be forwarded.
            $instance_key = $instance->getID() . get_class($instance);
            $invalidation_data[$instance_key] = $ret;
        }
        if (count($invalidation_data) > 0) {
            // Store invalid and reload this URL.
            $_SESSION['qmi_invalid'] = $invalidation_data;
            \nanomvc\messenger\redirectMessage(url(REQ_URL), __("Validation failed. Please check your input."));
        }
        // Store all instances.
        foreach ($instances as $instance)
            $instance->store();
        // Redirect to the success url and don't display success message (overkill).
        \nanomvc\request\reset();
        \nanomvc\request\redirect($success_url);
    }
}
