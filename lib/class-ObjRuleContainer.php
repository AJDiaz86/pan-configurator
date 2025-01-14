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

class ObjRuleContainer
{
    use PathableName;
    use XmlConvertible;


    public $owner = null;
    public $name = '';

    public $o = null;
    protected $classn=null;

    public $fasthashcomp = null;


    public function count()
    {
        return count($this->o);
    }

    public function setName($newname)
    {
        $this->name = $newname;
    }


    /**
     * Return true if all objects from this store are the same then in the other store.
     *
     */
    public function equals($ostore)
    {
        if( count($ostore->o) != count($this->o) )
        {
            //print "Not same count '".count($ostore->o)."':'".count($this->o)."'\n";
            return false;
        }
        //print "passed\n";
        foreach($this->o as $o)
        {
            if( ! in_array($o, $ostore->o, true) )
                return false;
        }
        return true;
    }



    public function equals_fasterHash( $other )
    {
        if( is_null($this->fasthashcomp) )
        {
            $this->generateFastHashComp();
        }
        if( is_null($other->fasthashcomp) )
        {
            $other->generateFastHashComp();
        }

        if( $this->fasthashcomp == $other->fasthashcomp  )
        {
            if( $this->equals($other) )
                return true;
        }

        return false;
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

        $this->fasthashcomp = md5($fasthashcomp,true);

    }

    public function getFastHashComp()
    {
        if( !isset($this->fasthashcomp) || $this->fasthashcomp === null )
            $this->generateFastHashComp();

        return $this->fasthashcomp;
    }




    protected function has( $obj, $caseSensitive = true)
    {
        if( is_string($obj) )
        {
            if( !$caseSensitive )
                $obj = strtolower($obj);

            foreach($this->o as $o)
            {
                if( !$caseSensitive )
                {
                    if( $obj == strtolower($o->name()) )
                    {
                        return true;
                    }
                }
                else
                {
                    if( $obj == $o->name() )
                        return true;
                }
            }
            return false;
        }

        foreach( $this->o as $o )
        {
            if( $o === $obj )
                return true;
        }

        return false;

    }


    /**
     *
     *
     */
    public function display($indent = 0)
    {
        $indent = '';

        for( $i=0; $i<$indent; $i++ )
        {
            $indent .= ' ';
        }

        $c = count($this->o);

        echo "$indent";
        print "Displaying the $c ".$this->classn."(s) in ".$this->toString()."\n";

        foreach( $this->o as $o)
        {
            print $indent.$o->name()."\n";
        }
    }

    public function &toString_inline()
    {
        return PH::list_to_string($this->o);
    }


    public function hostChanged($h)
    {
        if( in_array($h,$this->o) )
        {
            $this->fasthashcomp = null;
            $this->rewriteXML();
        }
    }

    public function replaceHostObject($old, $new)
    {

        $pos = array_search($old, $this->o, TRUE);

        // this object was not found so we exit and return false
        if( $pos === FALSE )
            return false;

        // remove $old from the list and unreference it
        unset($this->o[$pos]);
        $old->unrefInRule($this);

        // is $new already in the list ? if not then we insert it
        if( $new !== null && array_search($new, $this->o, TRUE) === FALSE )
        {
            $this->o[] = $new;
            $new->refInRule($this);
        }

        // let's update XML code
        $this->rewriteXML();

        return true;

    }

    /**
     *
     * @ignore
     **/
    protected function add($Obj)
    {
        if( !in_array($Obj,$this->o,true) )
        {
            $this->fasthashcomp = null;

            $this->o[] = $Obj;

            $Obj->refInRule($this);

            return true;
        }

        return false;
    }

    protected function removeAll()
    {
        $this->fasthashcomp = null;

        foreach( $this->o as $o)
        {
            $o->unrefInRule($this);
        }

        $this->o = Array();

    }

    protected function remove($Obj)
    {
        $this->fasthashcomp = null;

        $pos = array_search($Obj,$this->o,true);
        if( $pos !== FALSE )
        {
            unset($this->o[$pos]);

            $Obj->unrefInRule($this);

            return true;
        }

        return false;
    }

    /**
     * Returns an array with all objects in store
     * @return array
     */
    public function getAll()
    {
        return $this->o;
    }

    public function __destruct()
    {
        if( PH::$ignoreDestructors )
            return;

        if( $this->o === null )
            return;

        // remove this object from the referencers list
        foreach($this->o as $o)
        {
            $o->unrefInRule($this);
        }

        $this->o = null;
    }



    /*public function rewriteXML()
    {
        if( $this->centralStore )
        {
            clearA($this->xmlroot['children']);
        }

    }*/



}

