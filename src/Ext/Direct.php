<?php
namespace Ext;

/**
 * Configurations for Direct integration
 * Default values for all boolean configurations is "false" (this is easy to
 * remember)
 *
 * @author J. Bruni
 */
class Direct
{
    /**
     * @var array   Name of the classes to be published to the Ext.Direct API
     */
    static public $api_classes = array();

    /**
     * @var array   Name of the methods to be flagged as "formHandler = true" (use
     * "class::method" string format)
     */
    static public $form_handlers = array();

    /**
     * @var string   Ext.Direct API attribute "url"
     */
    static public $url;

    /**
     * @var string   Ext.Direct API attribute "namespace"
     */
    static public $namespace = 'Ext.php';

    /**
     * @var string   Ext.Direct API attribute "descriptor"
     */
    static public $descriptor = 'Ext.php.REMOTING_API';

    /**
     * @var string   Ext.Direct Provider attribute "id"
     */
    static public $id = '';

    /**
     * @var string   Ext.Direct Provider attribute "timeout"
     */
    static public $timeout = '30000';

    /**
     * @var string   Ext.Direct Provider attribute "maxRetries"
     */
    static public $maxRetries = 1;

    /**
     * @var boolean   Set this to true to count only the required parameters of a
     * method for the API "len" attribute
     */
    static public $count_only_required_params = false;

    /**
     * @var boolean   Set this to true to include static methods in the API
     * declaration
     */
    static public $include_static_methods = false;

    /**
     * @var boolean   Set this to true to include inherited methods in the API
     * declaration
     */
    static public $include_inherited_methods = false;

    /**
     * @var boolean   Set this to true to create an object instance of a class even
     * if the method being called is static
     */
    static public $instantiate_static = false;

    /**
     * @var boolean   Set this to true to call the action class constructor sending
     * the action parameters to it
     */
    static public $constructor_send_params = false;

    /**
     * @var boolean   Set this to true to allow detailed information about exceptions
     * in the output
     */
    static public $debug = false;

    /**
     * @var boolean   Set this to true to pass all action method call results through
     * utf8_encode function
     */
    static public $utf8_encode = false;

    /**
     * @var string   Available options are "json" (good for Ext Designer) and
     * "javascript"
     */
    static public $default_api_output = 'json';

    /**
     * @var callback   Function to be called before the API action call, to perform
     * authorization; parameter: DirectAction $action
     */
    static public $authorization_function = null;

    /**
     * @var callback   Function to be called after the API action call, to transform
     * its results; parameters: DirectAction $action, mixed $result
     */
    static public $transform_result_function = null;

    /**
     * @var callback   Function to be called after the API action call, to transform
     * the response structure; parameters: DirectAction $action, array $response
     */
    static public $transform_response_function = null;

    /**
     * @var callback   Function to be called during the API generation, allowing a
     * method to be declared or not; parameters: string $class, string $method
     */
    static public $declare_method_function = null;

    /**
     * @var array   Parameters to be sent to the class constructor (use the class
     * name as key); example: array( 'MyClass' => array( 'param1', 'param2' ) )
     */
    static public $constructor_params = array();

    /**
     * @return string   Array containing the full API declaration
     */
    static public function get_api_array()
    {
        $api_array = array(
            'id' => self::$id,
            'url' => (empty(self::$url) ? $_SERVER['PHP_SELF'] : self::$url),
            'type' => 'remoting',
            'namespace' => self::$namespace,
            'descriptor' => self::$descriptor,
            'timeout' => self::$timeout,
            'maxRetries' => self::$maxRetries
        );

        if (empty($api_array['id']))
            unset($api_array['id']);

        $actions = array();

        foreach (self::$api_classes as $key => $class) {
            $methods = array();
            $fullClass = (is_string($key)) ? $class['namespace'] . "\\" . $class['class'] : $class;
            $class = (is_string($key)) ? $class['class'] : $class;
            $reflection = new \ReflectionClass($fullClass);
            //	var_dump($fullClass);
            //	var_dump($reflection -> getMethods());
            foreach ($reflection->getMethods() as $method) {
                // Only public methods will be declared
                if (!$method->isPublic())
                    continue;

                // Don't declare constructor, destructor or abstract methods
                if ($method->isConstructor() || $method->isDestructor() || $method->isAbstract())
                    continue;

                // Only declare static methods according to "include_static_methods"
                // configuration
                if (!self::$include_static_methods && $method->isStatic())
                    continue;

                // Do not declare inherited methods, according to "include_inherited_methods"
                // configuration
                if (!self::$include_inherited_methods && ($method->getDeclaringClass()->name != $fullClass))
                    continue;

                // If "declare_method_function" is set, we test if the method can be declared,
                // according to its return result
                if (!empty(self::$declare_method_function) && !call_user_func(self::$declare_method_function, $fullClass, $method->getName()))
                    continue;

                // Count only required parameters or count them all, according to
                // "count_only_required_params" configuration
                if (self::$count_only_required_params)
                    $api_method = array(
                        'name' => $method->getName(),
                        'len' => $method->getNumberOfRequiredParameters()
                    );
                else
                    $api_method = array(
                        'name' => $method->getName(),
                        'len' => $method->getNumberOfParameters()
                    );
                if (in_array($class . '::' . $method->getName(), self::$form_handlers) || (strpos($method->getDocComment(), '@formHandler') !== false))
                    $api_method['formHandler'] = true;

                $methods[] = $api_method;
            }
            $actions[$class] = $methods;
        }

        $api_array['actions'] = $actions;

        return $api_array;
    }

    /**
     * @return string   JSON encoded array containing the full API declaration
     */
    static public function get_api_json()
    {
        return json_encode(self::get_api_array());
    }

    /**
     * @return string   JavaScript code containing the full API declaration
     */
    static public function get_api_javascript()
    {
        $template = <<<JAVASCRIPT
if ( Ext.syncRequire ){
	Ext.syncRequire( 'Ext.direct.Manager' );
}
Ext.namespace( '[%namespace%]' );
[%descriptor%] = [%actions%];
Ext.Direct.addProvider( [%descriptor%] );

JAVASCRIPT;

        $elements = array(
            '[%actions%]' => self::get_api_json(),
            '[%namespace%]' => Direct::$namespace,
            '[%descriptor%]' => Direct::$descriptor
        );

        return strtr($template, $elements);
    }

    /**
     * @return string   JSON encoded array containing the full API declaration
     */
    static public function get_api_javascript_mvc()
    {
        $template = <<<JAVASCRIPT
    Ext.ns('[%namespace%]');
    [%descriptor%] = [%actions%];

JAVASCRIPT;

        $elements = array(
            '[%actions%]' => self::get_api_json(),
            '[%namespace%]' => Direct::$namespace,
            '[%descriptor%]' => Direct::$descriptor
        );

        return strtr($template, $elements);
    }

    /**
     * Provide access via Ext.Direct to the specified class or classes
     * This method does one of the following two things, depending on the HTTP
     * request.
     * 1) Outputs the API declaration in the chosen format (JSON or JavaScript)
     * 2) Process the action(s) and return its result(s) (JSON)
     * @param string | array $api_classes   Class name(s) to publish in the API
     * declaration
     */
    static public function provide($api_classes = null)
    {
        new DirectController($api_classes);
    }

}
?>
