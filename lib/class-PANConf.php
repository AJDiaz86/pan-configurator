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
 * Your journey will start from PANConf or PanoramaConf
 *
 * Code:
 *
 *  $pan = new PANConf();
 *
 *  $pan->load_from_file('config.txt');
 *
 *  $vsys1 = $pan->findVirtualSystem('vsys1');
 *
 *  $vsys1->display_statistics();
 *
 * And there you go !
 *
 */
class PANConf
{
	
	use PathableName;
	use centralTagStore;
	use centralAppStore;
	use PanSubHelperTrait;

	public $xmlroot;
	
	public $sharedroot;
	public $devicesroot;
	public $localhostlocaldomain;

	/**
	 * @var DOMElement|null
	 */
	public $vsyssroot;

	public $name = '';

    /**
     * @var AddressStore
     */
    public $addressStore=null;
    /**
     * @var ServiceStore
     */
    public $serviceStore=null;

    public $version = null;

    /**
     * @var VirtualSystem[]
     */
	public $virtualSystems = Array();

    /**
     * @var PanAPIConnector|null
     */
	public $connector = null;


	/**
	 * @var NetworkPropertiesContainer
	 */
	public $network;


	public function name()
	{
		return $this->name;
	}
	
	/**
	 * @param PanoramaConf|null $withPanorama
	 */
	public function PANConf($withPanorama = null, $serial = null)
	{
		if( !is_null($withPanorama) )
			$this->panorama = $withPanorama;
		if( !is_null($serial) )
			$this->serial = $serial;

		$this->tagStore = new TagStore($this);
		$this->tagStore->setName('tagStore');
		$this->tagStore->setCentralStoreRole(true);
		
		$this->zoneStore = new ZoneStore($this);
		$this->zoneStore->setName('zoneStore');
		$this->zoneStore->setCentralStoreRole(true);
		
		$this->appStore = new AppStore($this);
		$this->appStore->setName('appStore');
		$this->appStore->setCentralStoreRole(true);
		$this->appStore->load_from_predefinedfile();

		$this->serviceStore = new ServiceStore($this,true);
		$this->serviceStore->name = 'services';
		if( !is_null($withPanorama) )
			$this->serviceStore->panoramaShared = $this->panorama->serviceStore;


		$this->addressStore = new AddressStore($this,true);
		$this->addressStore->name = 'addresses';
		if( !is_null($withPanorama) )
			$this->addressStore->panoramaShared = $this->panorama->addressStore;

		$this->network = new NetworkPropertiesContainer($this);

		
	}


	public function load_from_xmlstring(&$xml)
	{
		$xmlDoc = new DOMDocument();

		if ($xmlDoc->loadXML($xml, LIBXML_PARSEHUGE) !== TRUE)
			derr('Invalid XML file found');

		$this->load_from_domxml($xmlDoc);
	}

	public function load_from_domxml(DOMDocument $xml)
	{

		$this->xmldoc = $xml;


        $this->configroot = DH::findFirstElementOrDie('config', $this->xmldoc);
        $this->xmlroot = $this->configroot;


        $versionAttr = DH::findAttribute('version', $this->configroot);
        if( $versionAttr !== false )
        {
            $this->version = PH::versionFromString($versionAttr);
        }
        else
        {
            if( isset($this->connector) && $this->connector !== null )
                $version = $this->connector->getSoftwareVersion();
            else
                derr('cannot find PANOS version used for make this config');

            $this->version = $version['version'];
        }


		$this->sharedroot = DH::findFirstElementOrDie('shared', $this->configroot);

		$this->devicesroot = DH::findFirstElementOrDie('devices', $this->configroot);

		$this->localhostroot = DH::findFirstElementByNameAttrOrDie('entry', 'localhost.localdomain',$this->devicesroot);

		$this->vsyssroot = DH::findFirstElementOrDie('vsys', $this->localhostroot);




        //
        // Extract Tag objects
        //
        if( $this->version >= 60 )
        {
            $tmp = DH::findFirstElementOrCreate('tag', $this->sharedroot);
            $this->tagStore->load_from_domxml($tmp);
        }
        // End of Tag objects extraction


		//
		// Shared address objects extraction
		//
		$tmp = DH::findFirstElementOrCreate('address', $this->sharedroot);
		$this->addressStore->load_addresses_from_domxml($tmp);
		// end of address extraction

		//
		// Extract address groups 
		//
		$tmp = DH::findFirstElementOrCreate('address-group', $this->sharedroot);
		$this->addressStore->load_addressgroups_from_domxml($tmp);
		// End of address groups extraction

		//
		// Extract services
		//
		$tmp = DH::findFirstElementOrCreate('service', $this->sharedroot);
		$this->serviceStore->load_services_from_domxml($tmp);
		// End of address groups extraction

		//
		// Extract service groups 
		//
		$tmp = DH::findFirstElementOrCreate('service-group', $this->sharedroot);
		$this->serviceStore->load_servicegroups_from_domxml($tmp);
		// End of address groups extraction

		//
		// Extract network related configs
		//
		$tmp = DH::findFirstElementOrCreate('network', $this->localhostroot );
		$this->network->load_from_domxml($tmp);
		//
		
		
		// Now listing and extracting all VirtualSystem configurations
		foreach( $this->vsyssroot->childNodes as $node )
		{
			if( $node->nodeType != 1 ) continue;
			//print "DOM type: ".$node->nodeType."\n";
			$lvsys = new VirtualSystem($this);

			$lvname = DH::findAttribute('name', $node);

			if( $lvname === FALSE )
				derr('cannot finc VirtualSystem name');

			if( isset($this->panorama) )
			{
				$dg = $this->panorama->findApplicableDGForVsys($this->serial , $lvname);
				if( $dg !== FALSE )
				{
					$lvsys->addressStore->panoramaDG = $dg->addressStore;
					$lvsys->serviceStore->panoramaDG = $dg->serviceStore;
				}
			}

			$lvsys->load_from_domxml($node);
			$this->virtualSystems[] = $lvsys;
		}


	}


    /**
     * !!OBSOLETE!!
     *
     * @param string $name
     * @return VirtualSystem|null
     */
	public function findVSYS_by_Name($name)
	{
        mwarning('use of obsolete function, please use findVirtualSystem() instead!');
		return $this->findVirtualSystem($name);
	}

    /**
     * @param string $name
     * @return VirtualSystem|null
     */
    public function findVirtualSystem($name)
    {
        foreach( $this->virtualSystems as $vsys )
        {
            if( $vsys->name() == $name )
            {
                return $vsys;
            }
        }

        return null;
    }
	
	public function save_to_file($filename)
	{
		print "Now saving PANConf to file '$filename'...";
		if( PH::$UseDomXML === TRUE )
		{
			$xml = &DH::dom_to_xml($this->xmlroot);
			file_put_contents ( $filename , $xml);	
		}
		else
		{
			$xml = &array_to_xml($this->xmlroot);
			file_put_contents ( $filename , $xml);	
		}
		print "     done!\n\n";
	}
	
	public function load_from_file($filename)
	{
		$filecontents = file_get_contents($filename);

		if( PH::$UseDomXML === TRUE )
			$this->load_from_xmlstring($filecontents);
		else
			$this->load_from_xml($filecontents);
	}

	public function API_load_from_running( PanAPIConnector $conn )
	{
		$this->connector = $conn;


        if( PH::$UseDomXML === TRUE )
        {
            $xmlDoc = $this->connector->getRunningConfig();
            $this->load_from_domxml($xmlDoc);
        }
        else
        {
            $xmlarr = $this->connector->getRunningConfig();
            $this->load_from_xmlarr($xmlarr);
        }
	}

	public function API_load_from_candidate( PanAPIConnector $conn )
	{
		$this->connector = $conn;

		$xmlDoc = $this->connector->getCandidateConfig();
		$this->load_from_domxml($xmlDoc);
	}

	/**
	* send current config to the firewall and save under name $config_name
	* TODO : replace by PANAPI Connector
	*/
	public function API_uploadConfig( $config_name = 'panconfigurator-default.xml' )
	{

		print "Uploadig config to device....";

		$url = "&type=import&category=configuration&category=configuration";

		$answer = &$this->connector->sendRequest($url, false, DH::dom_to_xml($this->xmlroot), $config_name );

		print "OK!\n";

	}

    /**
     * @return VirtualSystem[]
     */
    public function getVirtualSystems()
    {
        return $this->virtualSystems;
    }


	public function display_statistics()
	{

		$numSecRules = 0;
		$numNatRules = 0;
		$numDecryptRules = 0;


		$gnservices = $this->serviceStore->countServices();
		$gnservicesUnused = $this->serviceStore->countUnusedServices();
		$gnserviceGs = $this->serviceStore->countServiceGroups();
		$gnserviceGsUnused = $this->serviceStore->countUnusedServiceGroups();
		$gnTmpServices = $this->serviceStore->countTmpServices();

		$gnaddresss = $this->addressStore->countAddresses();
		$gnaddresssUnused = $this->addressStore->countUnusedAddresses();
		$gnaddressGs = $this->addressStore->countAddressGroups();
		$gnaddressGsUnused = $this->addressStore->countUnusedAddressGroups();
		$gnTmpAddresses = $this->addressStore->countTmpAddresses();

		$numInterfaces = $this->network->ipsecTunnelStore->count() + $this->network->ethernetIfStore->count();
		$numSubInterfaces = $this->network->ethernetIfStore->countSubInterfaces();


		foreach($this->virtualSystems as $vsys )
		{

			$numSecRules += $vsys->securityRules->count();
			$numNatRules += $vsys->natRules->count();
			$numDecryptRules += $vsys->decryptionRules->count();

			$gnservices += $vsys->serviceStore->countServices();
			$gnservicesUnused += $vsys->serviceStore->countUnusedServices();
			$gnserviceGs += $vsys->serviceStore->countServiceGroups();
			$gnserviceGsUnused += $vsys->serviceStore->countUnusedServiceGroups();
			$gnTmpServices += $vsys->serviceStore->countTmpServices();

			$gnaddresss += $vsys->addressStore->countAddresses();
			$gnaddresssUnused += $vsys->addressStore->countUnusedAddresses();
			$gnaddressGs += $vsys->addressStore->countAddressGroups();
			$gnaddressGsUnused += $vsys->addressStore->countUnusedAddressGroups();
			$gnTmpAddresses += $vsys->addressStore->countTmpAddresses();

		}

		print "Statistics for PANConf '".$this->name."'\n";
		print "- ".$numSecRules." Security Rules\n";

		print "- ".$numNatRules." Nat Rules\n";

		print "- ".$numDecryptRules." Deryption Rules\n";

		print "- ".$this->addressStore->countAddresses()." (".$gnaddresss.") address objects. {$gnaddresssUnused} unused\n";

		print "- ".$this->addressStore->countAddressGroups()." (".$gnaddressGs.") address groups. {$gnaddressGsUnused} unused\n";

		print "- ".$this->serviceStore->countServices()." (".$gnservices.") service objects. {$gnservicesUnused} unused\n";

		print "- ".$this->serviceStore->countServiceGroups()." (".$gnserviceGs.") service groups. {$gnserviceGsUnused} unused\n";

		print "- ".$this->addressStore->countTmpAddresses()." (".$gnTmpAddresses.") temporary address objects\n";

		print "- ".$this->serviceStore->countTmpServices()." (".$gnTmpServices.") temporary service objects\n";

		print "- ".$this->zoneStore->count()." zones\n";
		print "- ".$this->tagStore->count()." tags\n";
		print "- $numInterfaces interfaces (Ethernet:{$this->network->ethernetIfStore->count()})\n";
		print "- $numSubInterfaces sub-interfaces (Ethernet:{$this->network->ethernetIfStore->countSubInterfaces()})\n";
	}


	public function isPanOS()
	{
		return true;
	}

}

