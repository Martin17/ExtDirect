<?php
namespace Ext;

/**
 * Store HTTP response contents for output
 *
 * @author J. Bruni
 */
class DirectResponse
{
    /**
     * @var array   HTTP headers to be sent in the response
     */
    public $headers = array();

    /**
     * @var contents   HTTP body to be sent in the response
     */
    public $contents = '';
}
