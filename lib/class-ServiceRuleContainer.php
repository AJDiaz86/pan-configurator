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
/**
 * Class ServiceRuleContainer
 * @property Service[]|ServiceGroup[] $o
 * @property Rule|SecurityRule|NatRule $owner
 *
 */
class ServiceRuleContainer extends ObjRuleContainer
{
    /**
     * @var null|string[]|DOMElement
     */
    public $xmlroot=null;

    /**
     * @var null|ServiceStore
     */
    public $parentCentralStore = null;

    private $appDef = false;



    public function ServiceRuleContainer($owner)
    {
        $this->owner = $owner;
        $this->o = Array();

        $this->findParentCentralStore();
    }


    /**
     * @param Service|ServiceGroup $Obj
     * @param bool $rewriteXml
     * @return bool
     */
    public function add( $Obj, $rewriteXml = true )
    {
        $this->fasthashcomp = null;

        $ret = parent::add($Obj);
        if( $ret && $rewriteXml )
        {
            $this->appDef = false;
            $this->rewriteXML();
        }
        return $ret;
    }

    /**
     * @param Service|ServiceGroup $Obj
     * @param bool $rewritexml
     * @return bool
     */
    public function API_add( $Obj, $rewritexml = true )
    {
        if( $this->add($Obj, $rewritexml) )
        {
            $xpath = &$this->getXPath();
            $con = findConnectorOrDie($this);

            if( count($this->o) == 1 )
            {
                $url = "type=config&action=delete&xpath=" . $xpath;
                $con->sendRequest($url);
            }

            $url = "type=config&action=set&xpath=$xpath&element=<member>".$Obj->name()."</member>";
            $con->sendRequest($url);

            return true;
        }

        return false;
    }

    public function isApplicationDefault()
    {
        return $this->appDef;
    }


    /**
     * @param Service|ServiceGroup $Obj
     * @param bool $rewriteXml
     * @param bool $forceAny
     *
     * @return bool  True if Zone was found and removed. False if not found.
     */
    public function remove( $Obj, $rewriteXml = true, $forceAny = false )
    {
        $count = count($this->o);

        $ret = parent::remove($Obj);

        if( $ret && $count == 1 && !$forceAny  )
        {
            derr("you are trying to remove last Object from a rule which will set it to ANY, please use forceAny=true for object: "
                .$this->toString() ) ;
        }

        if( $ret && $rewriteXml )
        {
            $this->rewriteXML();
        }
        return $ret;
    }

    /**
     * @param Service|ServiceGroup $Obj
     * @param bool $rewriteXml
     * @param bool $forceAny
     * @return bool
     */
    public function API_remove( $Obj, $rewriteXml = true, $forceAny = false )
    {
        if( $this->remove($Obj, $rewriteXml, $forceAny) )
        {
            $xpath = &$this->getXPath();
            $con = findConnectorOrDie($this);

            if( count($this->o) == 0 )
            {
                $url = "type=config&action=delete&xpath=" . $xpath;
                $con->sendRequest($url);
                $url = "type=config&action=set&xpath=$xpath&element=<member>any</member>";
                $con->sendRequest($url);
                return true;
            }

            $url = "type=config&action=delete&xpath=" . $xpath."/member[text()='".$Obj->name()."']";
            $con->sendRequest($url);

            return true;
        }

        return false;
    }

    public function setAny()
    {
        $this->fasthashcomp = null;

        foreach( $this->o as $o )
        {
            $this->remove($o, false, true);
        }

        $this->appDef = false;
        $this->rewriteXML();
    }

    function setApplicationDefault( )
    {
        if( $this->appDef )
            return false;

        $this->fasthashcomp = null;

        $this->appDef = true;

        foreach( $this->o as $o )
        {
            $this->remove($o, false, true);
        }

        $this->rewriteXML();

        return true;
    }

    /**
     * @param Service|ServiceGroup|string $object can be Service|ServiceGroup object or object name (string)
     * @return bool
     */
    public function has( $object, $caseSensitive = true )
    {
        return parent::has($object, $caseSensitive);
    }


    /**
     * return an array with all objects
     * @return Service[]|ServiceGroup[]
     */
    public function members()
    {
        return $this->o;
    }

    /**
     * return an array with all objects
     * @return Service[]|ServiceGroup[]
     */
    public function all()
    {
        return $this->o;
    }


    /**
     * should only be called from a Rule constructor
     * @ignore
     */
    public function load_from_xml(&$xml)
    {
        $this->xmlroot = &$xml;

        foreach( $xml['children'] as &$cur)
        {
            $lower = strtolower($cur['content']);

            if( $lower == 'any' )
            {
                $this->o = Array();
                return;
            }
            else if($lower == 'application-default')
            {
                $this->o = Array();
                $this->appDef = true;
                return;
            }

            $f = $this->parentCentralStore->findOrCreate( $cur['content'], $this);
            $this->o[] = $f;
        }

    }

    /**
     * should only be called from a Rule constructor
     * @ignore
     */
    public function load_from_domxml($xml)
    {
        //print "started to extract '".$this->toString()."' from xml\n";
        $this->xmlroot = $xml;
        $i=0;
        foreach( $xml->childNodes as $node )
        {
            if( $node->nodeType != 1 ) continue;

            $lower = strtolower($node->textContent);

            if( $lower == 'any' )
            {
                $this->o = Array();
                return;
            }
            else if($lower == 'application-default')
            {
                $this->o = Array();
                $this->appDef = true;
                return;
            }

            $f = $this->parentCentralStore->findOrCreate( $node->textContent, $this);
            $this->o[] = $f;
            $i++;
        }
    }


    public function rewriteXML()
    {
        if( PH::$UseDomXML === TRUE )
        {
            if( $this->appDef )
                DH::Hosts_to_xmlDom($this->xmlroot, $this->o, 'member', true, 'application-default');
            else
                DH::Hosts_to_xmlDom($this->xmlroot, $this->o, 'member', true);
        }
        else
        {
            if( $this->appDef )
                Hosts_to_xmlA($this->xmlroot['children'], $this->o, 'member', true, 'application-default');
            else
                Hosts_to_xmlA($this->xmlroot['children'], $this->o, 'member', true);
        }

    }


    /**
     *
     * @ignore
     */
    protected function findParentCentralStore()
    {
        $this->parentCentralStore = null;

        if( $this->owner )
        {
            $currentObject = $this;
            while( isset($currentObject->owner) && !is_null($currentObject->owner) )
            {

                if( isset($currentObject->owner->serviceStore) &&
                    !is_null($currentObject->owner->serviceStore)				)
                {
                    $this->parentCentralStore = $currentObject->owner->serviceStore;
                    //print $this->toString()." : found a parent central store: ".$parentCentralStore->toString()."\n";
                    return;
                }
                $currentObject = $currentObject->owner;
            }
        }

        mwarning('no parent store found!');

    }


    /**
     * Merge this set of objects with another one (in paramater). If one of them is 'any'
     * then the result will be 'any'.
     * @param ServiceRuleContainer $other
     *
     */
    public function merge(ServiceRuleContainer $other)
    {
        $this->fasthashcomp = null;

        if( $this->appDef && !$other->appDef || !$this->appDef && $other->appDef  )
            derr("You cannot merge 'application-default' type service stores with app-default ones");

        if( $this->appDef && $other->appDef )
            return;

        if( $this->isAny() )
            return;

        if( $other->isAny() )
        {
            $this->setAny();
            return;
        }

        foreach($other->o as $s)
        {
            $this->add($s, false);
        }

        $this->rewriteXML();
    }

    /**
     * To determine if a container has all the zones from another container. Very useful when looking to compare similar rules.
     * @param $other
     * @param $anyIsAcceptable
     * @return boolean true if Zones from $other are all in this store
     */
    public function includesContainer(ServiceRuleContainer $other, $anyIsAcceptable=true )
    {

        if( !$anyIsAcceptable )
        {
            if( $this->count() == 0 || $other->count() == 0 )
                return false;
        }

        if( $this->count() == 0 )
            return true;

        if( $other->count() == 0 )
            return false;

        $objects = $other->members();

        foreach( $objects as $o )
        {
            if( !$this->has($o) )
                return false;
        }

        return true;

    }

    public function API_setAny()
    {
        $this->setAny();
        $xpath = &$this->getXPath();
        $con = findConnectorOrDie($this);

        $url = "type=config&action=delete&xpath=".$xpath;
        $con->sendRequest($url);

        $url = "type=config&action=set&xpath=$xpath&element=<member>any</member>";
        $con->sendRequest($url);
    }

    /**
     * @return bool true if not already App Default
     */
    public function API_setApplicationDefault()
    {
        $ret = $this->setApplicationDefault();

        if( !$ret )
            return false;

        $con = findConnectorOrDie($this);
        $xpath = &$this->getXPath();

        $con->sendDeleteRequest($xpath);

        $con->sendSetRequest($xpath, '<member>application-default</member>');

        return true;
    }

    /**
     * @param ServiceRuleContainer $other
     * @return bool
     */
    public function equals( $other )
    {

        if( count($this->o) != count($other->o) )
            return false;

        if( $this->appDef != $other->appDef )
            return false;

        foreach($this->o as $o)
        {
            if( ! in_array($o, $other->o, true) )
                return false;
        }


        return true;
    }


    /**
     * @return string
     */
    public function &getXPath()
    {

        $str = $this->owner->getXPath().'/'.$this->name;

        return $str;

    }

    /**
     * @return bool
     */
    public function isAny()
    {
        if( $this->appDef )
            return false;

        return ( count($this->o) == 0 );
    }


    /**
     * @param Service|ServiceGroup
     * @param bool $anyIsAcceptable
     * @return bool
     */
    public function hasObjectRecursive( $object, $anyIsAcceptable=false)
    {
        if( $object === null )
            derr('cannot work with null objects');

        if( $anyIsAcceptable && $this->count() == 0 )
            return false;

        foreach( $this->o as $o )
        {
            if( $o === $object )
                return true;
            if( $o->isGroup() )
                if( $o->hasObjectRecursive($object) ) return true;
        }

        return false;
    }


    /**
     * To determine if a store has all the Service from another store, it will expand ServiceGroups instead of looking for them directly. Very useful when looking to compare similar rules.
     * @param ServiceRuleContainer $other
     * @param bool $anyIsAcceptable if any of these objects is Any the it will return false
     * @return bool true if Service objects from $other are all in this store
     */
    public function includesStoreExpanded(ServiceRuleContainer $other, $anyIsAcceptable=true )
    {

        if( !$anyIsAcceptable )
        {
            if( $this->count() == 0 || $other->count() == 0 )
                return false;
        }

        if( $this->count() == 0 )
            return true;

        if( $other->count() == 0 )
            return false;

        $localA = Array();
        $A = Array();

        foreach( $this->o as $object )
        {
            if( $object->isGroup() )
            {
                $flat = $object->expand();
                $localA = array_merge($localA, $flat);
            }
            else
                $localA[] = $object;
        }
        $localA = array_unique_no_cast($localA);

        $otherAll = $other->all();

        foreach( $otherAll as $object )
        {
            if( $object->isGroup() )
            {
                $flat = $object->expand();
                $A = array_merge($A, $flat);
            }
            else
                $A[] = $object;
        }
        $A = array_unique_no_cast($A);

        $diff = array_diff_no_cast($A, $localA);

        if( count($diff) > 0 )
        {
            return false;
        }


        return true;

    }


    public function &toString_inline()
    {
        $arr = &$this->o;
        $c = count($arr);

        if( $this->appDef )
        {
            $ret = 'application-default';
            return $ret;
        }

        if( $c == 0 )
        {
            $ret = '*ANY*';
            return $ret;
        }

        $first = true;

        $ret = '';

        foreach ( $arr as $s )
        {
            if( $first)
            {
                $ret .= $s->name();
            }
            else
                $ret .= ','.$s->name();


            $first = false;
        }

        return $ret;

    }

    public function generateFastHashComp($force=false )
    {
        if( !is_null($this->fasthashcomp) && !$force )
            return;

        $class = get_class($this);
        $fasthashcomp = $class;

        $tmpa = $this->o;

        usort($tmpa, "__CmpObjName");

        foreach( $tmpa as $o )
        {
            $fasthashcomp .= '.*/'.$o->name();
        }

        if( $this->appDef )
            $fasthashcomp .= '.app-default';

        $this->fasthashcomp = md5($fasthashcomp,true);

    }

}





