<?php
class ExtendedClient extends SoapClient {

	protected $merchantId;
	protected $transactionKey;
	
	function __construct($wsdl, $options = null) {
		parent::__construct($wsdl, $options);
		
		$this->merchantId 		=  $options['merchantID'];
		$this->transactionKey 	=  $options['transactionKey'];
		 
	}

	
	function __doRequest($request, $location, $action, $version,  $one_way = 0) {
		
		$user 		= $this->merchantId;
		$password 	= $this->transactionKey ;
		$soapHeader = "<SOAP-ENV:Header xmlns:SOAP-ENV=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:wsse=\"http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd\"><wsse:Security SOAP-ENV:mustUnderstand=\"1\"><wsse:UsernameToken><wsse:Username>$user</wsse:Username><wsse:Password Type=\"http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText\">$password</wsse:Password></wsse:UsernameToken></wsse:Security></SOAP-ENV:Header>";
		
		$requestDOM = new DOMDocument('1.0');
		$soapHeaderDOM = new DOMDocument('1.0');

		try {

			$requestDOM->loadXML($request);
			$soapHeaderDOM->loadXML($soapHeader);

			$node = $requestDOM->importNode($soapHeaderDOM->firstChild, true);
			$requestDOM->firstChild->insertBefore(
			$node, $requestDOM->firstChild->firstChild);

			$request = $requestDOM->saveXML();

			//printf( "Modified Request:\n*$request*\n" );

		} catch (DOMException $e) {
			die( 'Error adding UsernameToken: ' . $e->code);
		}

		return parent::__doRequest($request, $location, $action, $version);
	}
}