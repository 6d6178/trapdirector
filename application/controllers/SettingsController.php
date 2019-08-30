<?php

namespace Icinga\Module\Trapdirector\Controllers;

use Icinga\Data\ResourceFactory;
use Icinga\Web\Url;
use Icinga\Application\Icinga;
use Icinga\Exception\ProgrammingError;
use RunTimeException;

use Icinga\Module\TrapDirector\Config\TrapModuleConfig;
use Icinga\Module\Trapdirector\TrapsController;
use Icinga\Module\Trapdirector\Forms\TrapsConfigForm;
use Icinga\Module\Trapdirector\Icinga2Api;

use Trap;

class SettingsController extends TrapsController
{
  public function indexAction()
  {

    // CHeck permissions : display tests in any case, but no configuration.
	$this->view->configPermission=$this->checkModuleConfigPermission(1);
	// But check read permission
	$this->checkReadPermission();
	// Get message : sent on configuration problems detected by controllers
	$this->view->errorDetected=$this->params->get('dberror');
	
	// Test Database
	$db_message=array( // index => ( message OK, message NOK, optional link if NOK ) 
		0	=>	array('Database configuration OK','',''),
		1	=>	array('Database set in config.ini','No database in config.ini',''),
		2	=>	array('Database exists in Icingaweb2 config','Database does not exist in Icingaweb2 : ',
					Url::fromPath('config/resource')),
		3	=>	array('Database credentials OK','Database does not exist/invalid credentials/no schema : ',
					Url::fromPath('trapdirector/settings/createschema')),
		4	=>	array('Schema is set','Schema is not set for ',
					Url::fromPath('trapdirector/settings/createschema')),					
		5	=>	array('Schema is up to date','Schema is outdated :',
					Url::fromPath('trapdirector/settings/updateschema')),
	);
		
	$dberror=$this->getDb(true); // Get DB in test mode
	
	$this->view->db_error=$dberror[0];
	switch ($dberror[0]) 
	{
		case 2:
		case 4:
			$db_message[$dberror[0]][1] .= $dberror[1];
			break;
		case 3:
			$db_message[$dberror[0]][1] .= $dberror[1] . ', Message : ' . $dberror[2];
			break;
		case 5:
			$db_message[$dberror[0]][1] .= ' version '. $dberror[1] . ', version needed : ' .$dberror[2];
			break;
		case 0:
		case 1:
			break;
		default:
			new ProgrammingError('Out of bond result from database test');
	}
	$this->view->message=$db_message;
	
	//********* Test API
	if ($this->Config()->get('config', 'icingaAPI_host') != '')
	{
	    $apitest=new Icinga2Api($this->Config()->get('config', 'icingaAPI_host'),$this->Config()->get('config', 'icingaAPI_port'));
    	$apitest->setCredentials($this->Config()->get('config', 'icingaAPI_user'), $this->Config()->get('config', 'icingaAPI_password'));
    	try {
    	    list($this->view->apimessageError,$this->view->apimessage)=$apitest->test(TrapModuleConfig::getapiUserPermissions());
    	    //$this->view->apimessageError=false;
    	} catch (RuntimeException $e) {
    	    $this->view->apimessage='API config : ' . $e->getMessage();
    	    $this->view->apimessageError=true;
    	} 
	}
	else
	{
	    $this->view->apimessage='API parameters not configured';
	    $this->view->apimessageError=true;
	}
	
	//*********** Test snmptrapd alive and options
	list ($this->view->snmptrapdError, $this->view->snmptrapdMessage) = $this->checkSnmpTrapd();

	// List DB in $ressources
	$resources = array();
	$allowed = array('mysql', 'pgsql'); // TODO : check pgsql OK and maybe other DB
	foreach (ResourceFactory::getResourceConfigs() as $name => $resource) {
		if ($resource->get('type') === 'db' && in_array($resource->get('db'), $allowed)) {
			$resources[$name] = $name;
		}
	}

    $this->view->tabs = $this->Module()->getConfigTabs()->activate('config');

	$this->view->form = $form = new TrapsConfigForm();
	$this->view->form->setPaths($this->Module()->getBaseDir(),Icinga::app()->getConfigDir());

	// Check standard Icingaweb2 path
	$this->view->icingaEtcWarn=0;
	$icingaweb2_etc=$this->Config()->get('config', 'icingaweb2_etc');
	if ($icingaweb2_etc != "/etc/icingaweb2/")
	{
	    $output=array();
	    
	    exec('cat ' . $this->module->getBaseDir() .'/bin/trap_in.php | grep "\$icingaweb2_etc=" ',$output);
	    
	    if (! preg_match('#"'. $icingaweb2_etc .'"#',$output[0]))
	    {
    	    $this->view->icingaEtcWarn=1;
	        $this->view->icingaweb2_etc=$icingaweb2_etc;
	    }
	}
	    
	// Setup path for mini documentation
	$this->view->traps_in_config= PHP_BINARY . ' ' . $this->Module()->getBaseDir() . '/bin/trap_in.php';
	// Make form handle request.
	$form->setIniConfig($this->Config())
		->setDBList($resources)
		->handleRequest();
        
  }

  public function createschemaAction()
  {
	$this->checkModuleConfigPermission();
	$this->getTabs()->add('create_schema',array(
		'active'	=> true,
		'label'		=> $this->translate('Create Schema'),
		'url'		=> Url::fromRequest()
	));
	// check if needed
	
	$dberror=$this->getDb(true); // Get DB in test mode
	
	if ($dberror[0] == 0)
	{
		echo 'Schema already exists <br>';
	}
	else
	{
		echo 'Creating schema : <br>';
		
		echo '<pre>';
		require_once($this->Module()->getBaseDir() .'/bin/trap_class.php');
		
		$icingaweb2_etc=$this->Config()->get('config', 'icingaweb2_etc');
		$debug_level=4;
		$Trap = new Trap($icingaweb2_etc);
		$Trap->setLogging($debug_level,'display');
		
		$prefix=$this->Config()->get('config', 'database_prefix');
		$schema=$this->Module()->getBaseDir() . 
			'/SQL/schema_v'. $this->getModuleConfig()->getDbCurVersion() .'.sql';
		
		$Trap->create_schema($schema,$prefix);
		echo '</pre>';
	}
  }

  public function updateschemaAction()
  {
	  $this->checkModuleConfigPermission();
    	$this->getTabs()->add('get',array(
    		'active'	=> true,
    		'label'		=> $this->translate('Update Schema'),
    		'url'		=> Url::fromRequest()
    	));
	  // check if needed
	  
	  $dberror=$this->getDb(true); // Get DB in test mode
	  
	  if ($dberror[0] == 0)
	  {
	      echo 'Schema already exists and is up to date<br>';
	      return;
	  }
	  if ($dberror[0] != 5)
	  {
	      echo 'Database does not exists or is not setup correctly<br>';
	      return;
	  }
	  $target_version=$dberror[2];
	  echo 'Updating schema to '. $target_version . ': <br>';
	  echo '<pre>';
	  require_once($this->Module()->getBaseDir() .'/bin/trap_class.php');
	  
	  $icingaweb2_etc=$this->Config()->get('config', 'icingaweb2_etc');
	  $debug_level=4;
	  $Trap = new Trap($icingaweb2_etc);
	  $Trap->setLogging($debug_level,'display');
	  
	  $prefix=$this->Config()->get('config', 'database_prefix');
	  $updateSchema=$this->Module()->getBaseDir() . '/SQL/update_schema_v';
	  
	  $Trap->update_schema($updateSchema,$target_version,$prefix);
	  echo '</pre>';
  }  

  private function checkSnmpTrapd()
  {
      $psOutput=array();
      // First check is someone is listening to port 162. As not root, we can't have pid... 
      exec('netstat -an |grep -E "udp.*:162"',$psOutput);
      if (count($psOutput) == 0)
      {
          return array(1,'Port UDP/162 is not open : snmptrapd must not be started');
      }
      $psOutput=array();
      exec('ps fax |grep snmptrapd |grep -v grep',$psOutput);
      if (count($psOutput) == 0)
      {
          return array(1,"UDP/162 : OK, but no snmptrapd process (?)");
      }
      // Assume there is only one line... TODO : see if there is a better way to do this
      $line = preg_replace('/^.*snmptrapd /','',$psOutput[0]);
      if (!preg_match('/-n/',$line))
          return array(1,'snmptrapd has no -n option : '.$line);
      if (!preg_match('/-O[^ ]*n/',$line))
          return array(1,'snmptrapd has no -On option : '.$line);
      if (!preg_match('/-O[^ ]*e/',$line))
          return array(1,'snmptrapd has no -Oe option : '.$line);
      
      return array(0,'snmptrapd listening to UDP/162, options : '.$line);
  }
}
