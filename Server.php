<?php
//
// +----------------------------------------------------------------------+
// | PHP Version 4                                                        |
// +----------------------------------------------------------------------+
// | Copyright (c) 1997-2002 The PHP Group                                |
// +----------------------------------------------------------------------+
// | This source file is subject to version 2.02 of the PHP license,      |
// | that is bundled with this package in the file LICENSE, and is        |
// | available at through the world-wide-web at                           |
// | http://www.php.net/license/2_02.txt.                                 |
// | If you did not receive a copy of the PHP license and are unable to   |
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Authors: Shane Caraveo <Shane@Caraveo.com>   Port to PEAR and more   |
// | Authors: Dietrich Ayala <dietrich@ganx4.com> Original Author         |
// +----------------------------------------------------------------------+
//
// $Id$
//

require_once 'SOAP/Base.php';
require_once 'SOAP/Fault.php';
require_once 'SOAP/Parser.php';
require_once 'SOAP/Value.php';

$soap_server_fault = NULL;
function SOAP_ServerErrorHandler($errno, $errmsg, $filename, $linenum, $vars) {
    global $soap_server_fault;
    $detail = "Errno: $errno\nFilename: $filename\nLineno: $linenum\n";
    $soap_server_fault = new SOAP_Fault($errmsg, 'Server', NULL,NULL, array('detail'=>$detail));
}


/**
*  SOAP::Server
* SOAP Server Class
*
* originaly based on SOAPx4 by Dietrich Ayala http://dietrich.ganx4.com/soapx4
*
* @access   public
* @version  $Id$
* @package  SOAP::Client
* @author   Shane Caraveo <shane@php.net> Conversion to PEAR and updates
* @author   Dietrich Ayala <dietrich@ganx4.com> Original Author
*/
class SOAP_Server extends SOAP_Base
{

    /**
    *
    * @var  array
    */    
    var $dispatch_map = array(); // create empty dispatch map
    var $dispatch_objects = array();
    var $soapobject = NULL;
    
    /**
    *
    * @var  string
    */
    var $headers = '';
    
    /**
    *
    * @var  string
    */
    var $request = '';
    
    /**
    *
    * @var  string  XML-Encoding
    */
    var $xml_encoding = SOAP_DEFAULT_ENCODING;
    var $response_encoding = 'UTF-8';
    /**
    * 
    * @var  boolean
    */
    var $soapfault = false;
    
    var $result = 'successful'; // for logging interop results to db

    var $endpoint = ''; // the uri to ME!
    
    var $service = ''; //soapaction header
    var $method_namespace = NULL;
    
    function SOAP_Server() {
        ini_set('track_errors',1);
        parent::SOAP_Base('Server');
    }
    
    function _getContentEncoding($content_type)
    {
        // get the character encoding of the incoming request
        // treat incoming data as UTF-8 if no encoding set
        $this->xml_encoding = 'UTF-8';
        if (strpos($content_type,'=')) {
            $enc = strtoupper(str_replace('"',"",substr(strstr($content_type,'='),1)));
            if (!in_array($enc, $this->_encodings)) {
                return FALSE;
            }
            $this->xml_encoding = $enc;
        }
        return TRUE;
    }
    
    
    // parses request and posts response
    function service($data, $endpoint = '', $test = FALSE)
    {
        $response = NULL;
        $attachments = array();
        $headers = array();
        $useEncoding = 'DIME';
        // figure out our endpoint
        $this->endpoint = $endpoint;
        if (!$test && !$this->endpoint) {
            // we'll try to build our endpoint
            $this->endpoint = 'http://'.$_SERVER['SERVER_NAME'];
            if ($_SERVER['SERVER_PORT']) $this->endpoint .= ':'.$_SERVER['SERVER_PORT'];
            $this->endpoint .= $_SERVER['SCRIPT_NAME'];
        }

        // get the character encoding of the incoming request
        // treat incoming data as UTF-8 if no encoding set
        if (isset($_SERVER['CONTENT_TYPE'])) {
            if (strcasecmp($_SERVER['CONTENT_TYPE'],'application/dime')==0) {
                $this->decodeDIMEMessage($data,$headers,$attachments);
                $useEncoding = 'DIME';
            } else if (stristr($_SERVER['CONTENT_TYPE'],'multipart/related')) {
                // this is a mime message, lets decode it.
                $data = 'Content-Type: '.stripslashes($_SERVER['CONTENT_TYPE'])."\r\n\r\n".$data;
                $this->decodeMimeMessage($data,$headers,$attachments);
                $useEncoding = 'Mime';
            }
            if (!isset($headers['content-type'])) {
                $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
            }
            if (!$this->soapfault &&
                !$this->_getContentEncoding($headers['content-type'])) {
                $this->xml_encoding = SOAP_DEFAULT_ENCODING;
                // an encoding we don't understand, return a fault
                $this->makeFault('Server','Unsupported encoding, use one of ISO-8859-1, US-ASCII, UTF-8');
            }
        }
        
        // if this is not a POST with Content-Type text/xml, try to return a WSDL file
        if (!$this->soapfault  && !$test && ($_SERVER['REQUEST_METHOD'] != 'POST' ||
            strncmp($headers['content-type'],'text/xml',8) != 0)) {
                // this is not possibly a valid soap request, try to return a WSDL file
                $this->makeFault('Server',"Invalid SOAP request, must be POST with content-type: text/xml, got: {$headers['content-type']}");
        }
        
        if (!$this->soapfault) {
            // $response is a soap_msg object
            $soap_msg = $this->parseRequest($data, $attachments);
            
            // handle Mime or DIME encoding
            // XXX DIME Encoding should move to the transport, do it here for now
            // and for ease of getting it done
            if (count($this->attachments)) {
                if ($useEncoding == 'Mime') {
                    $soap_msg = $this->_makeMimeMessage($soap_msg);
                } else {
                    // default is dime
                    $soap_msg = $this->_makeDIMEMessage($soap_msg);
                    $header['Content-Type'] = 'application/dime';
                }
                if (PEAR::isError($soap_msg)) {
                    return $this->raiseSoapFault($soap_msg);
                }
            }
            
            if (is_array($soap_msg)) {
                $response = $soap_msg['body'];
                if (count($soap_msg['headers'])) {
                    $header = $soap_msg['headers'];
                }
            } else {
                $response = $soap_msg;
            }
        }
        
        if ($this->soapfault) {
            $hdrs = "HTTP/1.1 500 Soap Fault\r\n";
            $response = $this->getFaultMessage();
        } else {
           $hdrs = "HTTP/1.1 200 OK\r\n";
        }
        header($hdrs);

        $header['Server'] = SOAP_LIBRARY_NAME;
        if (!isset($header['Content-Type']))
            $header['Content-Type'] = "text/xml; charset=$this->response_encoding";
        $header['Content-Length'] = strlen($response);
        
        reset($header);
        foreach ($header as $k => $v) {
            header("$k: $v");
            $hdrs .= "$k: $v\r\n";
        }

        $this->response = $hdrs . "\r\n" . $response;
        print $response;
    }
    
    function callMethod($methodname, &$args) {
        global $soap_server_fault;
        set_error_handler("SOAP_ServerErrorHandler");
        if ($args) {
            // call method with parameters
            if (is_object($this->soapobject)) {
                $ret = @call_user_func_array(array(&$this->soapobject, $methodname),$args);
            } else {
                $ret = @call_user_func_array($methodname,$args);
            }
        } else {
            // call method w/ no parameters
            if (is_object($this->soapobject)) {
                $ret = @call_user_func(array(&$this->soapobject, $methodname));
            } else {
                $ret = @call_user_func($methodname);
            }
        }
        restore_error_handler();
        if ($soap_server_fault) {
            $this->soapfault = $soap_server_fault;
            return $soap_server_fault->message();
        }
        return $ret;
    }
    
    // create soap_val object w/ return values from method, use method signature to determine type
    function buildResult(&$method_response, &$return_type, $return_name='return', $namespace = '')
    {
        if (gettype($method_response) == 'object' && is_a($method_response,'soap_value')) {
            $return_val = array($method_response);
        } else {
            if (is_array($return_type) && is_array($method_response)) {
                $i = 0;

                foreach ($return_type as $key => $type) {
                    if (is_numeric($key)) $key = 'item';
                    if (is_a($method_response[$i],'soap_value')) {
                        $return_val[] = $method_response[$i++];
                    } else {
                        $qn = new QName($key, $namespace);
                        $return_val[] = new SOAP_Value($qn->fqn(),$type,$method_response[$i++]);
                    }
                }
            } else {
                if (is_array($return_type)) {
                    $keys = array_keys($return_type);
                    if (!is_numeric($keys[0])) $return_name = $keys[0];
                    $values = array_values($return_type);
                    $return_type = $values[0];
                }
                $qn = new QName($return_name, $namespace);
                $return_val = array(new SOAP_Value($qn->fqn(),$return_type,$method_response));
            }
        }
        return $return_val;
    }
    
    function parseRequest($data='', $attachments=NULL)
    {
        // parse response, get soap parser obj
        $parser = new SOAP_Parser($data,$this->xml_encoding,$attachments);
        // if fault occurred during message parsing
        if ($parser->fault) {
            $this->soapfault = $parser->fault;
            return NULL;
        }

        //*******************************************************
        // handle message headers

        $request_headers = $parser->getHeaders();
        $header_results = array();

        if ($request_headers) {
            if (!is_a($request_headers,'soap_value')) {
                $this->makeFault('Server',"parser did not return SOAP_Value object: $request_headers");
                return NULL;
            }
            if ($request_headers->value) {
            // handle headers now
            foreach ($request_headers->value as $header_val) {
                $f_exists = $this->validateMethod($header_val->name, $header_val->namespace);
                
                # XXX this does not take into account message routing yet
                $myactor = (
                    !$header_val->actor ||
                    $header_val->actor == 'http://schemas.xmlsoap.org/soap/actor/next' ||
                    $header_val->actor == $this->endpoint);
                
                if (!$f_exists && $header_val->mustunderstand && $myactor) {
                    $this->makeFault('Server',"I don't understand header $header_val->name.");
                    return NULL;
                }
                
                // we only handle the header if it's for us
                $isok = $f_exists && $myactor;
                
                if ($isok) {
                    # call our header now!
                    $header_method = $header_val->name;
                    $header_data = array($this->decode($header_val));
                    // if there are parameters to pass
                    $hr = $this->callMethod($header_method, $header_data);
                    # if they return a fault, then it's all over!
                    if (is_a($hr,'soap_value') && stristr($hr->value->name,'fault')) {
                        return $this->_makeEnvelope($hr, NULL, $this->response_encoding);
                    }
                    $header_results[] = array_shift($this->buildResult($hr, $this->return_type, $header_method, $header_val->namespace));
                }
            }
            }
        }

        //*******************************************************
        // handle the method call
        
        // evaluate message, getting back a SOAP_Value object
        $this->methodname = $parser->root_struct_name[0];

        // figure out the method_namespace
        $this->method_namespace = $parser->message[$parser->root_struct[0]]['namespace'];
        // does method exist?
        if (!$this->methodname || !$this->validateMethod($this->methodname)) {
            $this->makeFault('Server',"method '$this->methodname' not defined in service");
            return NULL;
        }

        if (!$request_val = $parser->getResponse()) {
            return NULL;
        }
        if (!is_a($request_val,'soap_value')) {
            $this->makeFault('Server',"parser did not return SOAP_Value object: $request_val");
            return NULL;
        }
        
        // verify that SOAP_Value objects in request match the methods signature
        if (!$this->verifyMethod($request_val)) {
            // verifyMethod creates the fault
            return NULL;
        }
        
        // need to set special error detection inside the value class
        // so as to differentiate between no params passed, and an error decoding
        $request_data = $this->decode($request_val);

        $method_response = $this->callMethod($this->methodname, $request_data);

        // get the method result
        if (is_null($method_response))
            $return_val = NULL;
        else
            $return_val = $this->buildResult($method_response, $this->return_type);
        
        $qn = new QName($this->methodname.'Response',$this->method_namespace);
        $methodValue = new SOAP_Value($qn->fqn(), 'Struct', $return_val);
        return $this->_makeEnvelope($methodValue, $header_results, $this->response_encoding);
    }
    
    function verifyMethod($request)
    {
        //return true;
        $params = $request->value;

        // get the dispatch map if one exists
        $map = NULL;
        if (array_key_exists($this->methodname, $this->dispatch_map)) {
            $map = $this->dispatch_map[$this->methodname];
        } else if ($this->soapobject) {
            $obv = get_object_vars($this->soapobject);
            if (array_key_exists('dispatch_map',$obv) &&
                array_key_exists($this->methodname, $this->soapobject->dispatch_map)) {
                    $map = $this->soapobject->dispatch_map[$this->methodname];
            }
        }
        // we'll let it through
        if (!$map) return TRUE;
        
        // if there are input parameters required...
        if ($sig = $map['in']) {
            $this->input_value = count($sig);
            $this->return_type = $this->getReturnType($map['out']);
            if (is_array($params)) {
                // validate the number of parameters
                if (count($params) == count($sig)) {
                    // make array of param types
                    foreach ($params as $param) {
                        $p[] = strtolower($param->type);
                    }
                    $sig_t = array_values($sig);
                    // validate each param's type
                    for($i=0; $i < count($p); $i++) {
                        // type not match
                        // if soap types do not match, we ok it if the mapped php types match
                        // this allows using plain php variables to work (ie. stuff like Decimal would fail otherwise)
                        // we only error if the types exist in our type maps, and they differ
                        if (strcasecmp($sig_t[$i],$p[$i])!=0 &&
                            (isset($this->_typemap[SOAP_XML_SCHEMA_VERSION][$sig_t[$i]]) &&
                            strcasecmp($this->_typemap[SOAP_XML_SCHEMA_VERSION][$sig_t[$i]],$this->_typemap[SOAP_XML_SCHEMA_VERSION][$p[$i]])!=0)) {

                            $param = $params[$i];
                            $this->makeFault('Client',"soap request contained mismatching parameters of name $param->name had type [{$p[$i]}], which did not match signature's type: [{$sig_t[$i]}], matched? ".(strcasecmp($sig_t[$i],$p[$i])));
                            return false;
                        }
                    }
                    return true;
                // oops, wrong number of paramss
                } else {
                    $this->makeFault('Client',"soap request contained incorrect number of parameters. method '$this->methodname' required ".count($sig).' and request provided '.count($params));
                    return false;
                }
            // oops, no params...
            } else {
                $this->makeFault('Client',"soap request contained incorrect number of parameters. method '$this->methodname' requires ".count($sig).' parameters, and request provided none');
                return false;
            }
        // no params
        }
        // we'll try it anyway
        return true;
    }
    
    // get string return type from dispatch map
    function getReturnType($returndata)
    {
        if (is_array($returndata)) {
            if (count($returndata) > 1) {
                return $returndata;
            }
            $type = array_shift($returndata);
            return $type;
        }
        return false;
    }
    
    function validateMethod($methodname, $namespace = NULL)
    {
        $this->soapobject =  NULL;
        $this->method_namespace = NULL;
        
        /* if it's in our function list, ok */
        if (array_key_exists($methodname, $this->dispatch_map) &&
            (!$namespace || !array_key_exists('namespace', $this->dispatch_map[$methodname]) ||
             $namespace == $this->dispatch_map[$methodname]['namespace'])) {
                if (array_key_exists('namespace', $this->dispatch_map[$methodname]))
                    $this->method_namespace = $this->dispatch_map[$methodname]['namespace'];
            return TRUE;
        }
        
        /* if it's in an object, it's ok */
        foreach ($this->dispatch_objects as $obj) {
            if (method_exists($obj, $methodname) &&
                (!$namespace || !$obj->method_namespace || $namespace == $obj->method_namespace)) {
                $this->method_namespace = $obj->method_namespace;
                $obv = get_object_vars($obj);
                if (array_key_exists('dispatch_map',$obv) &&
                    array_key_exists($this->methodname, $obj->dispatch_map) &&
                    array_key_exists('namespace', $obj->dispatch_map[$this->methodname])) {
                        $this->method_namespace = $obj->dispatch_map[$this->methodname]['namespace'];
                }
                $this->soapobject =  &$obj;
                return TRUE;
            }
        }
        return FALSE;
    }
    
    function addObjectMap(&$obj)
    {
        $this->dispatch_objects[] = &$obj;
    }
    
    // add a method to the dispatch map
    function addToMap($methodname, $in, $out, $namespace = NULL)
    {
        if (!function_exists($methodname)) {
            $this->makeFault('Server',"error mapping function\n");
            return FALSE;
        }
        $this->dispatch_map[$methodname]['in'] = $in;
        $this->dispatch_map[$methodname]['out'] = $out;
        if ($namespace) $this->dispatch_map[$methodname]['namespace'] = $namespace;
        return TRUE;
    }
    
    // set up a fault
    function getFaultMessage()
    {
        if (!$this->soapfault) {
            $this->makeFault('Server','fault message requested, but no fault has occured!');
        }
        return $this->soapfault->message();
    }
    
    function makeFault($fault_code, $fault_string)
    {
        $this->soapfault = new SOAP_Fault($fault_string, $fault_code);
    }
}



?>