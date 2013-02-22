<?php


/**
 *
 * Utility methods interfacing with Semantic Mediawiki.
 * @author jason
 *
 */
class SMWUtil
{
	/**
	 *
	 * Load all semantic properties of a page as a hash
	 * @param String $pagename
	 * @param Integer $namespace
	 * @param Boolean $normalizeTitle
	 * @return an associative array with the property name as key and property value as value.
	 */
	public static function &loadSemanticProperties($pagename, $namespace=NS_MAIN, $normalizeTitle=true)
	{
		//-----normalize title
		$data=null;
		if ($normalizeTitle)
		{
			$title=MWUtil::normalizePageTitle($pagename, false);	
			$di=SMWDIWikiPage::newFromTitle( $title );
	 		$data = smwfGetStore()->getSemanticData($di);
		} else
		{
			$di=new SMWDIWikiPage( $pagename, $namespace,'');
			$data = smwfGetStore()->getSemanticData($di);
		}
		$valuehash=array();
	 	$diProperties = $data->getProperties();
		foreach ( $diProperties as $diProperty ) {
			$dvProperty = SMWDataValueFactory::newDataItemValue( $diProperty, null );
			$name=null;
			if ( $dvProperty->isVisible() ) {
				$name = $diProperty->getLabel();
			} elseif ( $diProperty->getKey() == '_INST' ) {
				$name = 'Categories' ;
			}  else {
				continue; // skip this line
			}
			
			if (!$name)
			{
				continue;
			}
			$values = $data->getPropertyValues( $diProperty );
			$vs=array();
			foreach ( $values as $di )
			{
				$dv = SMWDataValueFactory::newDataItemValue( $di, $diProperty );
				$vs[]=$dv->getWikiValue();
			}
			if (count($vs)==1)
			{
				$valuehash[$name]=$vs[0];
			} else
			{
				$valuehash[$name]=$vs;	
			}
		}
		#error_log(print_r($valuehash, true));
		return $valuehash;	
		
	}
	
	
	/**
	 * 
	 * Retrieve all internal objects and their properties.
	 * @param String $pagename
	 * @param String $internalproperty
	 * @return an array. Each element is an associative array and represents an internal object.
	 * 
	 * E.G: {{#ptest:Chross|Is a parameter in an application}}
	 * 
	 */
	public static function getSemanticInternalObjects($pagename, $internalproperty, $namespace=NS_MAIN)
	{
		$params=array("[[{$internalproperty}::{$pagename}]]", "format=list", "link=none", "headers=hide", "sep=;","limit=5000");
		$result=SMWQueryProcessor::getResultFromFunctionParams($params,SMW_OUTPUT_WIKI);
		$result=trim($result);
		$sios=array();
		if ($result)
		{
			$sios=explode(";", $result);
		}
		$ret=array();
		foreach ($sios as $sio)
		{
			#remove namespace prefix.
			$sio=preg_replace("/^[^:]+:/", "", $sio);
			$sio=str_replace(" ", "_", $sio);
			#remove fragment to -
			#$sio=str_replace("#", "-23", $sio);
			array_push($ret, self::loadSemanticProperties($sio, $namespace, true));
		}
		
		return $ret;
	}
	
}


?>