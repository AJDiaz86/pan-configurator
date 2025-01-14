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

class DH
{
	/**
	 * @param DOMNode $a
	 * @param $objects
	 * @param string $tagName
	 * @param bool $showAnyIfZero
	 * @param string $valueOfAny
	 */
	static function Hosts_to_xmlDom(DOMNode $a, &$objects, $tagName = 'member', $showAnyIfZero=true, $valueOfAny = 'any')
	{
		//print_r($a);
		
		while( $a->hasChildNodes() )
			$a->removeChild($a->childNodes->item(0));
		
		$c = count($objects);
		if( $c == 0 && $showAnyIfZero == true)
		{
			$tmp = $a->ownerDocument->createElement($tagName);
			$tmp = $a->appendChild($tmp);
			$tmp->appendChild( $a->ownerDocument->createTextNode($valueOfAny) );
			return;
		}
		
		foreach( $objects as $o )
		{
			$tmp = $a->ownerDocument->createElement($tagName);
			$tmp = $a->appendChild($tmp);
			$tmp->appendChild( $a->ownerDocument->createTextNode($o->name()) );
		}
		//print_r($a);
	}

	static function setDomNodeText(DOMNode $node, $text)
	{
		DH::clearDomNodeChilds($node);
		$node->appendChild( $node->ownerDocument->createTextNode($text) );
	}

    static function makeElementAsRoot(DOMElement $newRoot, DOMNode $doc)
    {
        $doc->appendChild($newRoot);

        $nodes = Array();
        foreach( $doc->childNodes as $node )
        {
            $nodes[] = $node;
        }

        foreach( $nodes as $node )
        {
            if( !$newRoot->isSameNode($node) )
                $doc->removeChild($node);
        }

    }

	static function removeReplaceElement( DOMElement $el, $newName )
	{
		$ret = $el->ownerDocument->createElement($newName);
		$ret= $el->parentNode->replaceChild($ret, $el);

		return $ret;
	}

	static function clearDomNodeChilds(DOMNode $node)
	{
		while( $node->hasChildNodes() )
			$node->removeChild($node->childNodes->item(0));
	}

	/**
	 * @param DOMNode $node
	 * @return bool|DOMElement
	 */
	static function firstChildElement(DOMNode $node)
	{
		foreach( $node->childNodes as $child )
		{
			if( $child->nodeType == 1 )
				return $child;
		}

		return FALSE;
	}

	static function findFirstElementOrDie($tagName, DOMNode $node)
	{
		$ret = DH::findFirstElement($tagName, $node);

		if( $ret === FALSE )
			derr(' xml element <'.$tagName.'> was not found');

		return $ret;
	}

    /**
     * @param $tagName
     * @param DOMNode $node
     * @return bool|DOMNode
     */
	static function findFirstElement($tagName, DOMNode $node)
	{
		foreach( $node->childNodes as $lnode )
		{
			if( $lnode->nodeName == $tagName )
				return $lnode;
		}

		return FALSE;
	}

	static function removeChild(DOMNode $parent, DOMNode $child)
	{
		if( $child->parentNode === $parent )
		{
			$parent->removeChild($child);
		}
	}

	static function createElement(DOMNode $parent,$tagName, $withText = null)
	{
		$ret = $parent->ownerDocument->createElement($tagName);
		$ret = $parent->appendChild($ret);
		if( !is_null($withText) )
		{
			$tmp = $parent->ownerDocument->createTextNode($withText);
			$ret->appendChild($tmp);
		}

		return $ret;
	}

    /**
     * @param string $tagName
     * @param DOMNode $node
     * @param null|string $withText
     * @return bool|DOMElement|DOMNode
     */
	static function findFirstElementOrCreate($tagName, DOMNode $node, $withText = null)
	{
		$ret = DH::findFirstElement($tagName, $node);

		if( $ret === FALSE )
		{
			return DH::createElement($node, $tagName, $withText);
		}

		return $ret;
	}

    /**
     * @param string $tagName
     * @param $value
     * @param DOMNode $node
     * @return DOMNode|bool
     */
	static function findFirstElementByNameAttrOrDie($tagName, $value, DOMNode $node)
	{
		foreach( $node->childNodes as $lnode )
		{
			if( $lnode->nodeName == $tagName )
			{
				$attr = $lnode->attributes->getNamedItem('name');
				if( !is_null($attr) )
				{
					if( $attr->nodeValue == $value )
					return $lnode;
				}
			}
		}

		derr(' xml element <'.$tagName.' name="'.$value.'"> was not found');
        return FALSE;
	}

    /**
     * @param $attrName
     * @param DOMElement|DOMNode $node
     * @return bool|string
     */
	static function findAttribute($attrName, DOMElement $node)
	{

		$node = $node->getAttributeNode($attrName);

		if( $node === false )
				return false;

		return $node->nodeValue;

	}

    /**
     * @param DOMNode $node
     * @param int $indenting
     * @param bool $lineReturn
     * @param int $limitSubLevels
     * @return string
     */
	static function &dom_to_xml(DOMNode $node, $indenting = 0, $lineReturn = true, $limitSubLevels = -1)
	{
		$ind = '';
		$out = '';

        if( $limitSubLevels >= 0 && $limitSubLevels == $indenting )
            return $ind;

        $ind = str_pad('', $indenting, ' ');
		
		$firstTag = $ind.'<'.$node->nodeName;

        if( get_class($node) != 'DOMDocument' )
            foreach($node->attributes as $at)
            {
                $firstTag .= ' '.$at->name.'="'.$at->value.'"';
            }
		
		//$firsttag .= '>';
		
		$c = 0;
		$wroteChildren = false;
		
		$tmpout = '';
		
		if( DH::firstChildElement($node) !== FALSE )
		{
			foreach( $node->childNodes as $n)
			{
				if( $n->nodeType != 1 ) continue;

				if( $indenting != -1 )
					$tmpout .= DH::dom_to_xml($n, $indenting + 1,$lineReturn, $limitSubLevels);
				else
					$tmpout .= DH::dom_to_xml($n, -1, $lineReturn, $limitSubLevels);
				$wroteChildren = true;
			}
		}

			
		if( $wroteChildren == false )
		{

			if( DH::firstChildElement($node) !== FALSE || is_null($node->textContent) || strlen($node->textContent) < 1 )
			{
				if( $lineReturn )
					$out .= $firstTag."/>\n";
				else
					$out .= $firstTag."/>";
			}
			else
			{
				if( $lineReturn )
					$out .= $firstTag.'>'.str_replace( self::$charsToConvert, self::$charsToConvertInto, $node->nodeValue).'</'.$node->nodeName.">\n";
				else
					$out .= $firstTag.'>'.str_replace( self::$charsToConvert, self::$charsToConvertInto, $node->nodeValue).'</'.$node->nodeName.">";
			}
		}
		else
		{
			if( $lineReturn )
				$out .= $firstTag.">\n".$tmpout.$ind.'</'.$node->nodeName.">\n";
			else
				$out .= $firstTag.">".$tmpout.$ind.'</'.$node->nodeName.">";
		}

		return $out;	
	}

	static private $charsToConvert = array('&','>','<','"');
	static private $charsToConvertInto = array('&amp;','&gt;','&lt;','&quot;');


	/**
	 * @param DOMDocument $xmlDoc
	 * @param string $xmlString
	 * @return DOMElement
	 */
	static public function importXmlStringOrDie(DOMDocument $xmlDoc, $xmlString)
	{
		$fragment = $xmlDoc->createDocumentFragment();
		if( !$fragment->appendXML($xmlString) )
			derr('malformed xml: '.$xmlString);

		$element = DH::firstChildElement($fragment);

		if( $element === null or $element === false )
			derr('cannot find first element in :'.$xmlString);

		return $element;

	}
}

