<?php

/*
 * Copyright (c) 2014 Palo Alto Networks, Inc. <info@paloaltonetworks.com>
 * Author: Christophe Painchaud cpainchaud _AT_ paloaltonetworks.com
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.

 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
*/


class Address
{
	use ReferencableObject {unrefInRule as super_unrefInRule;}
	use PathableName;
	use XmlConvertible;

    /**
     * @var string|null
     */
	protected $value;

    /**
     * @var null|string
     */
    protected $description;


    /**
     * @var null|string[]|DOMElement
     */
	public $xmlroot = null;

    /**
     * @var AddressStore|null
     */
	public $owner;

	/**
	 * @var TagStore
	 */
	public $tags;

	const TypeTmp = 0;
	const TypeIpNetmask = 1;
	const TypeIpRange = 2;
	const TypeFQDN = 3;
	const TypeDynamic = 4;

	static private $AddressTypes = Array(self::TypeTmp => 'tmp',
										self::TypeIpNetmask => 'ip-netmask',
										self::TypeIpRange => 'ip-range',
										self::TypeFQDN => 'fqdn',
										self::TypeDynamic => 'dynamic'  );

	protected $type = self::TypeTmp;

	
	/**
	* you should not need this one for normal use
     * @param string $name
     * @param AddressStore $owner
     * @param bool $fromXmlTemplate
	*/
	function Address( $name, $owner, $fromXmlTemplate = false)
	{
        $this->owner = $owner;

        if( $fromXmlTemplate )
        {

			$doc = new DOMDocument();
			$doc->loadXML(self::$templatexml);

			$node = DH::findFirstElementOrDie('entry',$doc);

			$rootDoc = $this->owner->addrroot->ownerDocument;
			$this->xmlroot = $rootDoc->importNode($node, true);
			$this->load_from_domxml($this->xmlroot);

            $this->setName($name);
        }

        $this->name = $name;

		$this->tags = new TagRuleContainer('tag', $this);
		
	}

	public function API_delete()
	{
		if($this->isTmpAddr())
			derr('cannot be called on a Tmp address object');

		$connector = findConnectorOrDie($this);
		$xpath = $this->getXPath();

		$connector->sendDeleteRequest($xpath);
	}


	/**
	* @ignore
	*
	*/
	public function load_from_domxml(DOMElement $xml)
	{
		
		$this->xmlroot = $xml;
		
		$this->name = DH::findAttribute('name', $xml);
		if( $this->name === FALSE )
			derr("address name not found\n");
		
		//print "object named '".$this->name."' found\n";


		$typeFound = false;

		foreach($xml->childNodes as $node)
		{
			if( $node->nodeType != 1  )
				continue;

			$lsearch = array_search($node->nodeName, self::$AddressTypes);
			if( $lsearch !== FALSE )
			{
				$typeFound = true;
				$this->type = $lsearch;
				$this->value = $node->textContent;
			}
			elseif( $node->nodeName == 'description' )
			{
				$this->description = $node->textContent;
			}
		}

		if( !$typeFound )
			derr('object type not found or not supported');

		if( $this->owner->owner->version >= 60 )
		{
			$tagRoot = DH::findFirstElement('tag', $xml);
			if( $tagRoot !== false )
				$this->tags->load_from_domxml($tagRoot);
		}

	}

    /**
     * @return null|string
     */
	public function value()
	{
		return $this->value;
	}

	public function description()
	{
		return $this->description;
	}

	/**
	 * @param string|null $newDesc
	 * @return bool
	 */
	public function setDescription($newDesc)
	{
		if( $newDesc === null || strlen($newDesc) < 1)
		{
			if($this->description === null )
				return false;

			$this->description = null;
			$tmpRoot = DH::findFirstElement('description', $this->xmlroot);

			if( $tmpRoot === false )
				return true;
			$this->xmlroot->removeChild($tmpRoot);
		}
		else
		{
			if( $this->description == $newDesc )
				return false;
			$this->description = $newDesc;
			$tmpRoot = DH::findFirstElementOrCreate('description', $this->xmlroot);
			$tmpRoot->nodeValue = $this->description();
		}

		return true;
	}

	/**
	 * @param string|null $newDesc
	 * @return bool
	 */
	public function API_setDescription($newDesc)
	{
		$ret = $this->setDescription($newDesc);

		if( $ret )
		{
			$con = findConnectorOrDie($this);
			if( $this->description === null )
				$con->sendDeleteRequest($this->getXPath().'/description');
			else
				$con->sendSetRequest($this->getXPath(), '<description>'.$this->description.'</description>');
		}

		return $ret;
	}

	public function setValue( $newValue, $rewriteXml = true )
	{
		if( !is_string($newValue) )
			derr('value can be text only');

		if( $newValue == $this->value )
			return false;

		if( $this->isTmpAddr() )
			return false;

		$this->value = $newValue;

		if( $rewriteXml)
		{

			$valueRoot = DH::findFirstElementOrDie(self::$AddressTypes[$this->type], $this->xmlroot);
			$valueRoot->nodeValue = $this->value;

		}

		return true;
	}

    /**
     * @param $newType string
     * @param bool $rewritexml
     * @return bool true if successful
     */
	public function setType( $newType, $rewritexml = true )
	{

		$tmp = array_search( $newType, self::$AddressTypes );
		if( $tmp=== FALSE )
			derr('this type is not supported : '.$newType);

		if( $newType === $tmp )
			return false;

		$this->type = $tmp;

		if( $rewritexml)
			$this->rewriteXML();

		return true;
	}

    /**
     * @param $newType string
     * @return bool true if successful
     */
	public function API_setType($newType)
	{
		if( !$this->setType($newType) )
			return false;

		$c = findConnectorOrDie($this);
		$xpath = $this->getXPath();

        // TODO fix for domXML
		$c->sendSetRequest($xpath,  array_to_xml($this->xmlroot,-1,false) );

		$this->setType($newType);

        return true;
	}

    /**
     * @param string $newValue
     * @return bool
     */
	public function API_setValue($newValue)
	{
		if( !$this->setValue($newValue) )
			return false;

		$c = findConnectorOrDie($this);
		$xpath = $this->getXPath();

        // TODO fix for domXML
		$c->sendSetRequest($xpath,  array_to_xml($this->xmlroot,-1,false) );

		$this->setValue($newValue);

        return true;
	}
	
	
	
	public function rewriteXML()
	{
        if( $this->isTmpAddr() )
            return;

		DH::clearDomNodeChilds($this->xmlroot);

		$tmp = DH::createElement($this->xmlroot, self::$AddressTypes[$this->type], $this->value);

		if( $this->description !== null && strlen($this->description) > 0 )
		{
			DH::createElement($this->xmlroot, 'description', $this->description );
		}

	}
	
	/**
	* change the name of this object
	* @param string $newname
     *
	*/
	public function setName($newname)
	{
		$this->setRefName($newname);

		$this->xmlroot->getAttributeNode('name')->nodeValue = $newname;

	}

	public function API_setName($newname)
	{
		$c = findConnectorOrDie($this);
		$path = $this->getXPath();

		$url = "type=config&action=rename&xpath=$path&newname=$newname";

		$c->sendRequest($url);

		$this->setName($newname);	
	}


	public function &getXPath()
	{
		$str = $this->owner->getAddressStoreXPath()."/entry[@name='".$this->name."']";

		return $str;
	}


	/**
	* @return string ie: ip-netmask
	*/
	public function type()
	{
		return self::$AddressTypes[$this->type];
	}

	public function isGroup()
	{
		return false;
	}

	public function isAddress()
	{
		return true;
	}

	public function isTmpAddr()
	{
		if( $this->type == self::TypeTmp )
			return true;

		return false;
	}

	public function equals( $otherObject )
	{
		if( ! $otherObject->isAddress() )
			return false;

		if( $otherObject->name != $this->name )
			return false;

		return $this->sameValue( $otherObject);
	}

	public function sameValue( Address $otherObject)
	{
		if( $this->isTmpAddr() && !$otherObject->isTmpAddr() )
			return false;

		if( $otherObject->isTmpAddr() && !$this->isTmpAddr() )
			return false;

		if( $otherObject->type !== $this->type )
			return false;

		if( $otherObject->value !== $this->value )
			return false;

		return true;
	}

	/**
	* Return an array['start']= startip and ['end']= endip
	* @return array 
	*/
	public function & resolveIP_Start_End()
	{
		$res = Array();

		if( $this->isTmpAddr() )
			derr('cannot resolve a Temporary object !');

		if( $this->type != self::TypeIpRange && $this->type != self::TypeIpNetmask )
			derr('cannot resolve an object of type '.$this->type());

		if( $this->type == self::TypeIpRange )
		{
			$ex = explode('-', $this->value);

			if( count($ex) != 2 )
				derr('IP range has wrong syntax: '.$this->value);

			$res['start'] = ip2log($ex[0]);
			$res['end'] = ip2log($ex[1]);
		}
		elseif( $this->type == self::TypeIpNetmask )
		{
			if( strlen($this->value) < 1 )
				derr("cannot resolve object with no value");

			$ex = explode('/', $this->value);
			if( count($ex) > 1 && $ex[1] != '32')
	    	{
	    		//$netmask = cidr::cidr2netmask($ex[0]);
	    		$bmask = 0;
	    		for($i=1; $i<= (32-$ex[1]); $i++)
	    			$bmask += pow(2, $i-1);

	    		$subNetwork = ip2long($ex[0]) & ((-1 << (32 - (int)$ex[1])) );
	    		$subBroadcast = ip2long($ex[0]) | $bmask;
	    	}
	    	elseif( $ex[1] == '32' )
	    	{
				$subNetwork = ip2long($ex[0]);
	    		$subBroadcast = $subNetwork;
	    	}
	    	else
	    	{
	    		$subNetwork = ip2long($this->value);
	    		$subBroadcast = $subNetwork;
	    	}
	    	$res['start'] = $subNetwork;
	    	$res['end'] = $subBroadcast;
		}
		else
		{
			derr("unexpected type");
		}

		return $res;
	}

	public function unrefInRule($object)
	{
		$this->super_unrefInRule($object);

		if( $this->isTmpAddr() && $this->countReferences() == 0 && $this->owner !== null )
		{
			//$this->owner->remove($this);
		}

	}

    static protected $templatexml = '<entry name="**temporarynamechangeme**"><ip-netmask>tempvaluechangeme</ip-netmask></entry>';
    static protected $templatexmlroot = null;
	
}


