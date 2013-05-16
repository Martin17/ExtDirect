<?php
namespace Ext;

/**
 * Process Ext.Direct HTTP requests
 *
 * @author J. Bruni
 */
class DirectRequest
{
    /**
     * @var array   Actions to be executed in this request
     */
    public $actions = array();

    /**
     * @var boolean   True if there is a file upload; false otherwise
     */
    public $upload = false;

    /**
     * Call the correct actions processing method according to $_POST['extAction']
     * availability
     */
    public function __construct()
    {
        if (isset($_POST['extAction']))
            $this->get_form_action();
        else
            $this->get_request_actions();
    }

    /**
     * Instantiate actions to be executed in this request using "extAction" (form)
     */
    protected function get_form_action()
    {
        $extParameters = $_POST;

        foreach (array('extAction', 'extMethod', 'extTID', 'extUpload', 'extType') as $variable) {
            if (!isset($extParameters[$variable]))
                $$variable = '';
            else {
                $$variable = $extParameters[$variable];
                unset($extParameters[$variable]);
            }
        }

        if ($extType == 'rpc') {
            $this->actions[] = new DirectAction($extAction, $extMethod, $extParameters, $extTID, ($extUpload == 'true'), true);
            $this->upload = ($extUpload == 'true');
        }
    }

    /**
     * Instantiate actions to be executed in this request (without "extAction")
     */
    protected function get_request_actions()
    {
        $input = file_get_contents('php://input');

        $request = json_decode($input);

        if (!is_array($request))
            $request = array($request);

        foreach ($request as $rpc) {
            foreach (array('type', 'action', 'method', 'data', 'tid') as $variable)
                $$variable = (isset($rpc->$variable) ? $rpc->$variable : '');

            if ($type == 'rpc')
                $this->actions[] = new DirectAction($action, $method, $data, $tid, false, false);
        }
    }

}
