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
class Tag
{
	use ReferencableObject;
	use PathableName;
    use XmlConvertible;

    /**
     * @var TagStore|null
     */
	public $owner = null;

    private $isTmp = true;

    /**
     * @var DOMNode|null
     */
    public $xmlroot = null;

    /**
     * @param string $name
     * @param TagStore|null $owner
     */
	public function Tag($name, $owner, $fromXmlTemplate=false)
	{
        $this->name = $name;


        if( $fromXmlTemplate )
        {
            if( !PH::$UseDomXML )
            {
                if( $owner->owner->version < 60 )
                    derr('tag stores were introduced in 6.0');

                $xmlobj = new XmlArray();
                $xmlArray = $xmlobj->load_string(self::$templatexml_v6);
                $this->load_from_xml($xmlArray);
            }
            else
            {
                $doc = new DOMDocument();
                if( $owner->owner->version < 60 )
                    derr('tag stores were introduced in v6.0');
                else
                    $doc->loadXML(self::$templatexml_v6);

                $node = DH::findFirstElement('entry',$doc);

                $rootDoc = $owner->xmlroot->ownerDocument;

                $this->xmlroot = $rootDoc->importNode($node, true);
                $this->load_from_domxml($this->xmlroot);

            }
            $this->setName($name);
        }

        $this->owner = $owner;

	}

    public function setName($newName)
    {
        $ret = $this->setRefName($newName);

        if( $this->xmlroot === null )
            return $ret;

        if( PH::$UseDomXML === TRUE )
            $this->xmlroot->getAttributeNode('name')->nodeValue = $newName;
        else
            $this->xmlroot['attributes']['name'] = $newName;

        return $ret;
    }

    public function isTmp()
    {
        return $this->isTmp;
    }

    public function load_from_xml(&$xmlArray)
    {
        $this->xmlroot = &$xmlArray;
        $this->isTmp = false;

        if( !isset($xmlArray['attributes']['name']) )
            derr('Tag name not found');

        $this->name = $this->xmlroot['attributes']['name'];

        if( strlen($this->name) < 1  )
            derr("Tag name '".$this->name."' is not valid");
    }

    public function load_from_domxml(DOMNode $xml)
    {
        $this->xmlroot = $xml;

        $this->name = DH::findAttribute('name', $xml);
        if( $this->name === FALSE )
            derr("tag name not found\n", $xml);

        if( strlen($this->name) < 1  )
            derr("Tag name '".$this->name."' is not valid.", $xml);

    }


    static protected $templatexml_v6 = '<entry name="**temporarynamechangeme**"></entry>';
}

