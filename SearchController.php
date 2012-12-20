<?php
require_once 'Zend/Search/Lucene.php';
require_once 'Zend/Session/Namespace.php';
require_once 'Zend/Cache/Core.php';
require_once 'mapInteractive.php';
require_once 'luceneSearch.php';

class SearchController extends Zend_Controller_Action
{
    public function init()
    {
        /* Initialize action controller here */
    }

    public function indexAction()
    {
		//this is the landing page
		//checks for authorization. If not signed in, go to the login page. 
		//Otherwise go to the view search/index.phtml
		$storage = new Zend_Auth_Storage_Session();
        $data = $storage->read();
        if(!$data){
      		$this->_redirect('auth/login');     
        }
        
        //Find object according to current user id and current client id
        $userMapper = new Application_Model_Winsarr_UserMapper();
        $user = $userMapper->find($data->UserId);
        
        $authNamespace->UserName = $data->UserName;
    	$authNamespace->ClientId = $data->ClientId;
    	$authNamespace->wholename = $data->Firstname . " " . $data->Lastname;
		$authNamespace->ClientName = $data->ClientName;
		$authNamespace->isAdmin = $data->isAdmin;
		$authNamespace->isClientAdmin = $data->isClientAdmin;
		
		$this->view->userinfo = $authNamespace;
		$this->view->expr = '';
      	// action body
      	$id = $authNamespace->ClientId;

      	//Fetch all the applications that are in the user role list
        $roleMapper = new Application_Model_Winsarr_RoleMapper();
        $applications = array();
        $roles = array();
        if(is_object($user))
        {
        	$roles = $user->getRoles();
        }
        foreach($roles as $role)
        {
        	if($role > 1)
        	{
	        	$roleObject = $roleMapper->fetchWhere('RoleId = '.$role);
	        	foreach($roleMapper->fetchApplications($roleObject) as $applicationForThisRole)
	        	{
	        		$applications[] = $applicationForThisRole;
	        	}
        	}	
        }
        
        $where = "";
        foreach($applications as $application)
        {
        	$where .= " OR ApplicationId = ".$application->getApplicationId();
        }

        $application = new Application_Model_DbTable_Application();
        $this->view->application = $application->fetchAll($application->select()->where('ClientId = ?'.$where, 0)
        		->order('ApplicationName'));

        $client = new Application_Model_DbTable_Client();
        $this->view->client = $client->fetchRow('ClientId = ' . $authNamespace->ClientId);

        $detail = new Application_Model_DbTable_Detail();
        
        
        $this->view->showSearch = true;
        
        if($data->isAdmin || $data->isClientAdmin)
        {
        	$this->view->showSearch = false;
        }
        
    }
	
	public function searchAction() {
		//the search action does the main work of the site
		//set the constants
		set_time_limit ( 0 );
		ini_set ( 'memory_limit', '-1' );
		$startRow = 0;
		$hitsPerPage = 20;
		
		//load the authorization data from the session
		$storage = new Zend_Auth_Storage_Session ();
		$data = $storage->read ();
		if (! $data) {
			$this->_redirect ( 'auth/login' );
		}
		$authNamespace->UserName = $data->UserName;
		$authNamespace->ClientId = $data->ClientId;
		$authNamespace->wholename = $data->Firstname . " " . $data->Lastname;
		$authNamespace->ClientName = $data->ClientName;
		$this->view->userinfo = $authNamespace;
		
		$params = $this->getRequest()->getParams();

		//set up the pagination array
		$applicationQueryData = array();
		foreach($params as $key => $val)
		{
			if(preg_match('/^page-/', $key))
			{
				$applicationQueryData[substr($key, 5)]["page"] = $val;
			}
		}
		
		$this->view->pageStatus = $applicationQueryData;
		
		//get the search expression from the user
		$expr = $params["search-expression"]; //$this->_request->getPost ( 'search-expression' );
		$this->view->queryParams = "?search-expression=" . urlencode($params["search-expression"]);
		if ($this->view->escape ( $expr ) == "")
			$this->_redirect ( '/search' );
		
		$application = new Application_Model_DbTable_Application ();
		
		// Set available applications in view
		$this->view->application = $application->fetchAll ( $application->select ()->where ( 'ClientId = ?', $authNamespace->ClientId )->order ( 'ApplicationName' ) );
		
		$searchapps = '';
		$pattern = "/appFilter/";
		$applicationSearchCriteria = array ();
		foreach ( $params as $key => $value ) {
			error_log ( "key=" . $key . " value=" . $value );
			if (preg_match ( $pattern, $key )) {
				$searchapps .= $value;
				$applicationSearchCriteria [] = $value;
			}
		}
		
		// if no applications are selected, select all of them
		if (count ( $applicationSearchCriteria ) == 0) {
			foreach ( $this->view->application as $app ) {
				$applicationSearchCriteria [] = $this->view->escape ( $app->ApplicationId );
			}
		}
		
		$this->view->expr = $expr;
		error_log ( "search-expression=" . $expr . " searchapps=" . $searchapps );
		
		// cacheid for pagination
		$cacheId = md5 ( $expr );
		
		error_log ( "query=" . print_r ( $expr, true ) . " strlen of searchapps=" . strlen ( $searchapps ) );
		$hits = array ();
		
		
		
	/* 
		
		foreach($applicationSearchCriteria as $aSC)
		{
			$row = $application->fetchRow($application->select()->where('ApplicationId = ?', $aSC));
			$lucenePathSuffix[] = "/".str_replace(" ", "_", $row->ApplicationName);
		}
		
		
		//Replace search engine here
		$searchengine = new luceneSearch();
		$hits=$searchengine->search($lucenePathSuffix,$expr);
	 */	
		
		//set up the search criteria
		foreach ( $applicationSearchCriteria as $aSC ) {
			$row = $application->fetchRow ( $application->select ()->where ( 'ApplicationId = ?', $aSC ) );
				
				
			$temp["name"] = $row->ApplicationName;
			$temp["path"] = str_replace ( " ", "+", $row->ApplicationName );
			$lucenePathSuffix [$aSC] = $temp;
		}	
		
		//search. The search engine is replacable. We have Lucene and Solr modules as of 3/29/2012
		$searchengine = new Application_Model_Solr_Search();
		$searchengine->setHitsPerPage($hitsPerPage);
		$searchengine->setApplicationQueryData($applicationQueryData);
		
		$hits= $searchengine->search($lucenePathSuffix, $expr);
		$this->view->paginationData = $searchengine->paginationData;
		$this->view->globalTotalHits = $searchengine->globalTotalHits;
		
		error_log ( "count(hits)=" . count ( $hits ) );

		//process the hits coming back from the search engine		
		$res = array ();
		$results = array ();
		$used_rids = array ();
		$counter = 0;
		$dupes = 0;
		$appcounter = 0;
		$totalCount = 0;
		$lastappname = '';
		$lastappid = 0;
		$counterapp = 0;
		$mapcounter = 0;
		$appcounts = array ();
		$details = new Application_Model_DbTable_Detail ();
		$viewmapname = array ();
		if (count ( $hits ) > 0) {
			foreach ( $hits as $hit ) {
				if (! $hit->RoleId && ($hit->ClientId == $authNamespace->ClientId || $authNamespace->ClientId == 0)) {
					if (! in_array ( $hit->RecordId, $used_rids ) && ! in_array ( $hit->ParentRecordId, $used_rids )) {
						if ($hit->ApplicationName != $lastappname) {
							if ($counter > 0) {
								$appcounts [str_replace ( '_x0020_', ' ', $lastappname )] = $appcounter;
								$totalCount += $appcounter;
								$appcounter = 0;
								// set the map array for the last app
								$viewlastappid = "appmaps" . $lastappid;
								$this->view->$viewlastappid = $viewmapname;
								$mapcounter = 0;
								$counterapp ++;
							}
							// create a new array for mapping by application
							$viewmapname = array ();
						}
						$appcounter ++;
						$results [$counter] ["score"] = $hit->score;
						$results [$counter] ["ApplicationName"] = str_replace ( '_x0020_', ' ', $hit->ApplicationName );
						$results [$counter] ["RoleId"] = $hit->RoleId;
						$results [$counter] ["ClientId"] = $hit->ClientId;
						$results [$counter] ["RecordId"] = $hit->RecordId;
						$results [$counter] ["RecordType"] = $hit->RecordType;
						$results [$counter] ["Value"] = str_replace ( '_x0020_', ' ', $hit->Value );
						$results [$counter] ["AppId"] = $hit->AppId;
						$results [$counter] ["ParentRecordId"] = $hit->ParentRecordId;
						$results [$counter] ["FieldName"] = str_replace ( '_x0020_', ' ', $hit->FieldName );
						$results [$counter] ["SequenceNumber"] = $hit->SequenceNumber;
						
						// change for ParentId. If there is a ParentRecordId,
						// get all the details for the associated records
 						if (strlen ( $results [$counter] ["ParentRecordId"] ) > 0) {
							$results [$counter] ["Details"] = $details->fetchAll ( $details->select ()->where ( 'ParentRecordId = ' . $hit->ParentRecordId . ' and SequenceNumber>0')->order ( 'SequenceNumber' ) );
						} else {
							$results [$counter] ["Details"] = $details->fetchAll ( $details->select ()->where ( 'RecordId = ' . $hit->RecordId . ' and SequenceNumber>0')->order ( 'SequenceNumber' ) ); 
						} 
						// end change for ParentId
						
						$lastrecno = '';
						foreach ( $results [$counter] ["Details"] as $det ) {
							// get only the first address for each record for the map by application
							if ($det ["RecordType"] == "Address" && $hit->RecordId != $lastrecno) {
								if ($det ["latitude"] != NULL) {
									$viewmapname [$mapcounter] ["latitude"] = $det ["latitude"];
									$viewmapname [$mapcounter] ["longitude"] = $det ["longitude"];
									$viewmapname [$mapcounter] ["address"] = $det ["Value"];
									$viewmapname [$mapcounter] ["FieldName"] = $det ["FieldName"];
									$mapcounter ++;
									$lastrecno = $hit->RecordId;
								}
							}
						}
						
						if (strlen ( $results [$counter] ["ParentRecordId"] ) > 0) {
							$used_rids [$counter] = $hit->ParentRecordId;
						} else {
							$used_rids [$counter] = $hit->RecordId;
						}
						$counter ++;
					} else {
						$dupes ++;
						error_log ( "Record # " . $hit->RecordId . " Duplicates: " . $dupes );
					}
					$lastappname = $hit->ApplicationName;
					$lastappid = $hit->AppId;
				}
			}
		}
		// appcounts is the array for counts by application for the view
		$appcounts [$lastappname] = $appcounter;
		$totalCount += $appcounter;
		$appcounts ["total"] = $totalCount;
		$mapviewname = "appmaps" . $lastappid;
		$this->view->$mapviewname = $viewmapname;
		error_log ( "end of search" );
		error_log ( "count(results):" . count ( $results ) );
		
		
		$this->view->results = $results;
		$this->view->appcounts = $appcounts;
	}

    public function detailsAction()
    {
		$rID = $this->_request->getParam('rid');		
		$details = new Application_Model_DbTable_Detail();
		$this->view->records = $details->fetchAll($details->select()->where('RecordId = '. $rID)->order('SequenceNumber'));
		$results=array();
    }

    public function searchindAction()
    {
    	//This is the index function; it can be fully indexed just by calling the action or it can be indexed by
    	//client or application by adding the ClientId or ApplicationIId in the URL The actual searchng is done by
    	//a changable search object in library. Lucene uses this. Solr does not. It is called by URL 
		//(http://dev.winsarr.com:8983/solr/dataimport?command=full-import for the devlopment site and 
		//http://www.winsarr.com:8983/solr/dataimport?command=full-import for the production site).

    	set_time_limit (0);
    	ini_set('memory_limit', '-1');
    	//connect to DB
    	$dbc = @mysql_connect('localhost', 'xxxxxxx', 'xxxxxxxxzzzz') or die ('I cannot connect to the database because: ' . mysql_error());
    	mysql_select_db('sssddddadsda');
    	
    	//get POST data, if any and set where clause
    	//valid criteria are ClientId and ApplicationId
    	foreach($this->_request->getParams() as $key => $value){
    		error_log("key=" . $key . " value=" . $value);
    		$indexCriteria[$key]=$value;
    	}
    	$myClientId='';
    	$myAppId='';
    	$myWhere='';
    	if(strlen($indexCriteria['ClientId'])>0){
    		$myClientId=$indexCriteria['ClientId'];
    		$myWhere.=' where ClientId=' . $myClientId;
    	}
    	if(strlen($indexCriteria['ApplicationId'])>0){
    		$myAppId=$indexCriteria['ApplicationId'];
    		if($myWhere==''){
    			$myWhere=' where AppId=' . $myAppId;
    		}else{
    			$myWhere.=' and AppId=' . $myAppId;
    		}
    	}
    	//Get unique ApplicationName(s)
    	$dtAppSQL = 'SELECT DISTINCT ApplicationName FROM `detail`' . $myWhere;
    	$dq1 = mysql_query($dtAppSQL);
    	//Foreach Application, check/create new index
    	
    	//Replace search engine here
    	$searchengine = new luceneSearch();
    	$done=$searchengine->index($dq1);
    	$this->_redirect('/search');
    }

}








