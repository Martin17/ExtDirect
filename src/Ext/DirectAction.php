<?php
namespace Ext;

/**
 * Call a Ext.Direct API class method and format the results
 *
 * @author J. Bruni
 */
class DirectAction
{
    /**
     * @var string   API class name
     */
    public $action;

    /**
     * @var string   Method name
     */
    public $method;

    /**
     * @var array   Method parameters
     */
    public $parameters;

    /**
     * @var integer   Unique identifier for the transaction
     */
    public $transaction_id;

    /**
     * @var boolean   True if there is a file upload; false otherwise
     */
    public $upload = false;

    /**
     * @var boolean   True if this action is handling a form; false otherwise
     */
    public $form_handler = false;

    /**
     * @var boolean   False only when "authorization_function" (if configured)
     * returns a non-true value
     */
    public $authorized = true;

    /**
     * @var \Exception   \Exception object, instantiated if an exception occurs while
     * executing the action
     */
    public $exception;

    /**
     * @param string $action   API class name
     * @param string $method   Method name
     * @param array $parameters   Method parameters
     * @param integer $transaction_id   Unique identifier for the transaction
     * @param boolean $upload   True if there is a file upload; false otherwise
     * @param boolean $form_handler   True if the action is a form handler; false
     * otherwise
     */
    public function __construct($action, $method, $parameters, $transaction_id, $upload = false, $form_handler = false)
    {
        foreach (array('action', 'method', 'parameters', 'transaction_id', 'upload', 'form_handler') as $parameter)
            $this->$parameter = $$parameter;


        if (empty($this->parameters))
            $this->parameters = array();

    }

    /**
     * @return array   Result of the action execution
     */
    public function run()
    {
        $response = array(
            'type' => 'rpc',
            'tid' => $this->transaction_id,
            'action' => $this->action,
            'method' => $this->method
        );

        try {
            $result = $this->call_action();
            $response['result'] = $result;
        } catch (\Exception $e) {
            $response['result'] = '\Exception';
            if (Direct::$debug)
                $response = array(
                    'type' => 'exception',
                    'tid' => $this->transaction_id,
                    'message' => $e->getMessage(),
                    'where' => $e->getTraceAsString()
                );
            $this->exception = $e;
        }

        if (is_callable(Direct::$transform_response_function))
            $response = call_user_func(Direct::$transform_response_function, $this, $response);

        if (Direct::$utf8_encode)
            array_walk_recursive($response, array(
                $this,
                'utf8_encode'
            ));

        return $response;
    }

    /**
     * @param mixed $value   If it is a string, it will be UTF8 encoded
     * @param mixed $key   Not used (passed by "array_walk_recursive" function)
     * @return mixed   UTF8 encoded string, or unchanged value if not a string
     */
    protected function & utf8_encode(&$value, $key)
    {
        if (is_string($value))
            $value = utf8_encode($value);
        return $value;
    }

    /**
     * @return mixed   Result of the action
     */
    protected function call_action()
    {
        $class = $this->action;

        $key = array_key_exists($class, Direct::$api_classes);

        if ($key == false)
            // Accept only calls to classes defined at "api_classes" configuration
            if (!in_array($class, Direct::$api_classes))
                throw new \Exception('Call to undefined or not allowed class ' . $class, E_USER_ERROR);

        //$fullClass = (is_string($key)) ? $key . "\\" . $class : $class;
        $fullClass = ($key == true) ? Direct::$api_classes[$class]['namespace'] . "\\" . Direct::$api_classes[$class]['class'] : $class;

        $class = ($key == true) ? Direct::$api_classes[$class]['class'] : $class;
        // Do not allow calls to magic methods; only allow calls to methods returned by
        // "get_class_methods" function
        if ((substr($this->method, 0, 2) == '__') || !in_array($this->method, get_class_methods($fullClass)))
            throw new \Exception('Call to undefined or not allowed method ' . $fullClass . '::' . $this->method, E_USER_ERROR);

        // Do not allow calls to methods that do not pass the declare_method_function (if
        // configured)
        if (!empty(self::$declare_method_function) && !call_user_func(self::$declare_method_function, $fullClass, $this->method))
            throw new \Exception('Call to undefined or not allowed method ' . $fullClass . '::' . $this->method, E_USER_ERROR);

        $ref_method = new \ReflectionMethod($fullClass, $this->method);

        // Get number of parameters for the method
        if (Direct::$count_only_required_params)
            $params = $ref_method->getNumberOfRequiredParameters();
        else
            $params = $ref_method->getNumberOfParameters();

        if ($this->upload && (count($_FILES) == 1))
            $params -= 1;

        if (count($this->parameters) < $params)
            throw new \Exception('Call to ' . $fullClass . ' method ' . $this->method . ' needs at least ' . $params . ' parameters', E_USER_ERROR);

        // Check inheritance
        if (!Direct::$include_inherited_methods && ($ref_method->getDeclaringClass()->name != $fullClass))
            throw new \Exception('Call to ' . $fullClass . ' inhreited method ' . $this->method . ' not allowed', E_USER_ERROR);

        // Confirm if the method is a formHandler
        $this->form_handler = $this->form_handler && (in_array($class . '::' . $this->method, Direct::$form_handlers) || (strpos($ref_method->getDocComment(), '@formHandler') !== false));

        if (!$this->form_handler)
            $parameters = $this->parameters;
        else {
            $parameters = array();
            // We treat formHandler's parameters in a special way
            foreach ($ref_method->getParameters() as $ref_parameter) {
                $param_name = $ref_parameter->getName();

                if (isset($this->parameters[$param_name]))
                    $value = $this->parameters[$param_name];
                elseif ($this->upload && isset($_FILES[$param_name]))
                    $value = $_FILES[$param_name]; elseif ($ref_parameter->isDefaultValueAvailable())
                    $value = $ref_parameter->getDefaultValue(); else
                    $value = null;

                $parameters[] = $value;
            }
        }

        if ($ref_method->isStatic()) {
            if (!Direct::$include_static_methods)
                throw new \Exception('Call to static method ' . $fullClass . '::' . $this->method . ' not allowed', E_USER_ERROR);

            // If the method is static, we usually don't need to create an instance
            if (!Direct::$instantiate_static)
                return $this->call_action_func_array(array(
                    $class,
                    $this->method
                ), $parameters);
        }

        // By default, we don't send parameters to constructor, but
        // "constructor_send_params" and "constructor_params" configurations allow this
        if (!Direct::$constructor_send_params && empty(Direct::$constructor_params[$class]))
            $this->instance = new $fullClass;
        else {
            if (empty(Direct::$constructor_params[$class]))
                $constructor_params = $this->parameters;
            else
                $constructor_params = Direct::$constructor_params[$class];

            $ref_class = new \ReflectionClass($fullClass);
            $this->instance = $ref_class->newInstanceArgs($constructor_params);
        }

        return $this->call_action_func_array(array(
            $this->instance,
            $this->method
        ), $parameters);
    }

    /**
     * Checks for authorization (if "authorization_function" is configured), calls
     * the action method,
     * transform the results (if "transform_result_function" is configured), and then
     * return the results
     *
     * @param callback $callback   Action method to be called
     * @param array $parameters   Parameters to pass to the action method
     * @return mixed   Result of the action method
     */
    protected function call_action_func_array($callback, $parameters)
    {
        if (is_callable(Direct::$authorization_function)) {
            $auth_result = call_user_func(Direct::$authorization_function, $this);

            $this->authorized = ($auth_result === true);

            if ($auth_result === false)
                throw new \Exception('Not authorized to call ' . $this->action . '::' . $this->method, E_USER_ERROR);
            elseif ($auth_result !== true)
                $result = $auth_result;
        }

        if (!isset($result))
            $result = call_user_func_array($callback, $parameters);

        if (is_callable(Direct::$transform_result_function))
            $result = call_user_func(Direct::$transform_result_function, $this, $result);

        return $result;
    }

}
