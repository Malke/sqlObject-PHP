<?php

class SQLObject {
	
	public 		$DBHost; 
	public 		$DBName; 
	public 		$DBUser; 
	public 		$DBPassword; 
	protected 	$errorHandler; 
	public	  	$Request; 
	protected 	$Connexion; 
	public 		$requestMicroTime; 
	public    	$num_of_rows; 
	public    	$orderController; 
	public	 	$Connected;
	protected 	$error;
	
	/*
	 * pour l'interface fluide
	 */
	protected $joinList = array();
	protected $fieldList = "";
	public $fromList = array();
	protected $whereList = array();
	protected $groupBy = "";
	protected $orderList = array();
	protected $limit = NULL;

	protected $params = array();

	/*
	 * Pour le cache
	 */
	public $cache = null; // référence à la classe de cache
	public $maj_table_path = ""; // référence au path du fichier contenant les dates de mises à jour des tables
	public $isApcCache = false;
	public $isFileCache = false;
	public $isMemCache = false;
	
	
	/*
	 * Pour les subqueries
	 */
	public $cache_index_table = array();
	public $subquery_list = array();
	public $instance_id;

	//uniquement pour les test
	public $cacheLoaded = false;
	public $cacheKey;

	private $eventDispatcher;
	
	
	public function SQLObject($DBName = null, $options = array())
	{
				
		$this->eventDispatcher = \dispatcher::getInstance();
		
		if (false === empty($DBName)) {
			if (true === is_array($DBName)) {
				$SQLDatas = $DBName;
			} else {
				$SQLDatas = array(
						'DBHost'     => DB_HOST,
						'DBName'     => $DBName,
						'DBUser'     => DB_USER,
						'DBPassword' => DB_PASSWORD
				);
			}
		} else {
			$SQLDatas = array(
					'DBHost'     => DB_HOST,
					'DBName'     => DB_NAME,
					'DBUser'     => DB_USER,
					'DBPassword' => DB_PASSWORD
			);
		}

		$this->DBHost = $SQLDatas['DBHost'];
		$this->DBName = $SQLDatas['DBName'];
		$this->DBUser = $SQLDatas['DBUser'];
		$this->DBPassword = $SQLDatas['DBPassword'];

		//rccc
		//Quel cache ?
		//Si pas d'option déclarée, alors APC est le cache par défaut
		if(false === isset($options['cache'])){
			$options['cache'] = 'Cache_Apc';
			//$options['cache'] = 'Cache_Memcache';
			//$options['cache_options'] = array("server"=>MEMCACHED_SERVER_1, "port"=>MEMCACHED_PORT_1);
		}
		
		if(true === isset($options['cache'])){
			
			if(true === class_exists($options['cache'])){
				$class = $options['cache'];
				$args = false;
				if (true === isset($options['cache_options'])) { 
					$args = $options['cache_options'];
				}
				$this->cache = new $class($args);	
			}else{
				throw new Exception("SQLObject::_construct : la classe de cache spécifiée en paramètres de SQLobject n'existe pas");
			}
			
			//Si le cache est Cache_apc ou Cache_memcache, alors maj_table_path est le nom de la clé
			//par laquelle le contenu de maj_table est accessible.
			if($this->cache instanceof Cache_Apc){
				$this->maj_table_path = "SQLObject_maj_table";	
				$this->isApcCache = true;
			}elseif($this->cache instanceof Cache_Memcache){
				$this->maj_table_path = "SQLObject_maj_table";
				$this->isMemCache = true;				
			}
			//Si le cache est Cache_File, alors maj_table_path est le chemin d'accès au fichier sur le disque
			elseif($this->cache instanceof Cache_File){
				$this->maj_table_path = ROOT . "cache/maj_tables.php";
				$this->isFileCache = true;
			}
			
		}
		
		$this->eventDispatcher = dispatcher::getInstance();

		parent::__construct(/*array("DBName"=>$this->DBName)*/);

		$this->Request = "";

		if( !$this->Connected ){
			$this->connect();
		}

	}

	public static function getInstance($params = DB_NAME, $options = array()) {
	
				
		if (false === empty($params)) {
			if (true === is_array($params)) {
				$DBName = $params['DBName'];
			}
			else if (true === is_string($params)) {
				$DBName = $params;
			}
				
			$sqllist = parent::getInstances("SQLObject");
				
			if (false === empty($sqllist) && true === is_array($sqllist)) {
	
				foreach ($sqllist as $key=>$value) {
								
					if ( ($value->DBName == $DBName  && false === isset($options['cache']) ) 
							|| ($value->DBName == $DBName  && true === isset($options['cache'])  && $value->cache === $options['cache']) ) {						
						$instance = \Controller::getInstance("SQLObject", $key);
						$instance->clean();
						return $instance;
					}
				}

			}
		}
			
		return new self($params, $options);
	
	}
	
	public static function create($params = DB_NAME, $options = array()) {
		
		if (false === empty($params)) {
			if (true === is_array($params)) {
				$DBName = $params['DBName'];
			}
			else if (true === is_string($params)) {
				$DBName = $params;
			}
				
			$sqllist = parent::getInstances("SQLObject");
			
			if (false === empty($sqllist) && true === is_array($sqllist)) {
	
				foreach ($sqllist as $key=>$value) {
								
					if ( ($value->DBName == $DBName  && false === isset($options['cache']) ) 
							|| ($value->DBName == $DBName  && true === isset($options['cache'])  && $value->cache === $options['cache']) ) {						
						$instance = clone \Controller::getInstance("SQLObject", $key);
						$instance->clean();
						return $instance;
					}
				}

			}
		}
		
		return new self($params, $options);
	}
	
		
	/**
	 * 
	 * @throws Exception
	 * @return boolean
	 */
	private function checkError() {
		$errno = @mysql_errno($this->Connexion);
		$error = @mysql_error($this->Connexion);
		$error .= "\n Request : \n" . $this->Request ."\n";
		
		$this->setError($error);
	
		if($errno !== 0 && false === empty($error)){
			$errorHandler = \Controller::getInstance(\Controller::ERROR_HANDLER, 0);
			$errorHandler->SQLErrorHandler($error, $errno, $this->Request);
		}

	}

	public function setError($error){
		$this->error = $error;
	}
	
	public function getError(){
		return $this->error;
	}
	
	public function connect() {
		$f = FirePHP::getInstance(true);
		
		if ( $this->Connexion = @mysql_connect( $this->DBHost, $this->DBUser, $this->DBPassword ) ){
			
			if( mysql_select_db( $this->DBName , $this->Connexion ) ){
				$this->Connected = true;
				mysql_set_charset('utf8',$this->Connexion);
				return true;
			}else{
				$this->Connected = false;
				$this->checkError();
				return false;
			}
		}else{
			$this->Connected = false;
			$this->checkError();
			return false;
		}

			
	}
	
	
	public function close() {
		if( $this->Connexion ){
			mysql_close( $this->Connexion );
			$this->Connected = false;
		}
	}

	public function select( $table , $fields = "*" , $where = "" , $order = NULL , $limit = NULL, $joinList = array(), $exec= true , $cache = false) {
		
		//event dispatcher			
	//	$this->eventDispatcher->notify(  new sfEvent($this, 'sqlobject_insert', array("id"=>"0", "table"=>$table)) );

		
		//doit on retrouver les données en cache ?
		if($cache){
						
			$cacheKey = $this->setCacheKey( $table , $fields, $where, $order , $limit, $joinList );
			
			$datas = $this->processCache($cacheKey);			
			
			if(false !== $datas || false === empty($datas)) {
				$this->cacheLoaded = true;
				return $datas;
			}
			
		}

		///////////TIMER////////////////////////////////////////////
		$debut = microtime();
		////////////////////////////////////////////////////////////

		$Response = array();

		if( $this->orderController && $exec == true){ //exec == false  : pour les sous requêtes
			$this->Request="SELECT SQL_CALC_FOUND_ROWS $fields FROM $table ";
		}else{
			$this->Request="SELECT $fields FROM $table ";
		}


		//ici on traite les jointures ...
		if(count($joinList)){

			foreach($joinList as $join){
				$reference = explode('.', $join['reference']);
				$this->Request .= strtoupper($join['mode']) . " JOIN " . $reference[0] . " ON " . $join['reference']. " = ". $join['cible'] . " " ;
			}
		}


		$where_generated = SQLObject::generateWhere($where);

		if (false === empty($this->params) && true === is_string($where_generated)) {
			foreach($this->params as $key => $value) {
				if (false !== strpos($where_generated,":".$key)) {
					$where_generated = str_replace(":".$key, $value, $where_generated);
				}
			}
		} 

		$where = false;

		if ($where_generated){

			$this->Request.="WHERE ".$where_generated;
			$where = true;

		}


		if( $this->orderController ){
			if( $this->orderController->where ){
				//S'il y a un controleur d'affecté...
				if ($where){
					$this->Request .= " AND ".$this->orderController->getWhere();
				}else{
					$this->Request .= "WHERE ".$this->orderController->getWhere();
				}
			}
		}

		if( $this->groupBy ){
			$this->Request .= " GROUP BY ".$this->groupBy;
		}

		//S'il y a un controleur d'affecté...
		if( $this->orderController ){

			//...et il récupère les données de tri spécifié dans l'instance
			if ($this->orderController->order){
				$this->Request.=" ORDER BY ".$this->orderController->order;
			}
			if ($this->orderController->limit){
				$this->Request.=" LIMIT ".$this->orderController->getSqlLimit();
			}

		}else{
			if (false === empty($order)){
				$this->Request.=" ORDER BY $order";
			}
			if (false === empty($limit)){
				$this->Request.=" LIMIT $limit";
			}
		}
		
		//on peut ne pas vouloir executer la requête - en cas de test
		if(!$exec) return;

		if (true === is_resource($Select = mysql_query( $this->Request , $this->Connexion))){

			//Recup le nombre de resultats sans limit
			if( $this->orderController ){

				//.... il lui envoi le nombre total de résultats...
				$num = mysql_fetch_assoc(mysql_query( "SELECT FOUND_ROWS() AS found_rows" , $this->Connexion ));
				$this->num_of_rows = $num_of_rows = $num['found_rows'];
				//$this->orderController->giveNumOfRowsTotal( $num_of_rows );

			}else{

				$this->num_of_rows = $num_of_rows = mysql_num_rows($Select);
			}


			if( $num_of_rows > 0) {
				//$index_for_anchor = 0;
				while ($row = mysql_fetch_array($Select, MYSQL_ASSOC)) {
					$Response[]=$row;
				}

				///////////TIMER////////////////////////////////////////////
				if(APPLICATION == "DEV") {

					$fin = microtime();

					list($usec1, $sec1) = explode(" ", $debut);

					list($usec2, $sec2) = explode(" ", $fin);

					$this->requestMicroTime = ((($sec2-$sec1)+($usec2-$usec1))*1000)."msec";

					//notification de la requête
					//if($this->eventDispatcher)
						//$this->eventDispatcher->notify( new sfEvent($this, 'sql_select', array('request' => $this->Request, "requestMicroTime" => $this->requestMicroTime)) );
					
				}
				////////////////////////////////////////////////////////////
				//doit on conserver une copie des resultats en cache ?
				if($cache){
					$this->doCache($cacheKey, $this->Request, $table, $Response);
				}
				return $Response;
			}
			else if (true === empty($num_of_rows)) {
				return 0;
			}
			else{
				return false;
			}
		}else{
			$this->checkError();
			return false;
		}

	}
	
	public function call($proc, array $parameters = array())
	{
		if (false === empty($proc)) {
			
			if (false === empty($proc) && true === is_array($parameters)) {
				
				foreach ($parameters as $key=>$value) {
					
					if (false === empty($key)) {
						$proc = str_replace($key, $value, $proc);	
					}
				}
						
			}
			$execute = $this->query(sprintf("CALL %s", $proc));
	
			
			if (true === empty($execute)) {
				if (APPLICATION == "DEV") {
					   throw new \Exception(sprintf('Error request given %s', $execute));	
				}
			}
			
			return true;
			
		}
	    

	}
	

	
	public function insert( $table , $fields , $values = NULL , $onDuplicateKey = NULL, $execute = true) {


		//ici on mets à jour le fichier contenant les dates de modifications des table
		$this->update_maj_tables($table);

		$listFields = array();
		$values_suivi = "" ;
		//Transforme les tableaux en chaine/////////////////////////////////////////////////////////
		if( is_array($fields) && !$values ){
			
			$multi_array = $fields;
			$fields = array();

			foreach( $multi_array as $key => $value ){
				$fields[] = $key;
				$value = stripslashes(rawurldecode($value));
				$values[] = addslashes($value);
			}
			$fields = implode( "," , $fields );
			$values = "('".implode( "','" , $values )."')";
	
						
		}elseif( is_array($fields) && is_array($values) ){

						
			if( true === isset($value[0]) && is_array( $values[0] ) ){

				for( $i = 0 ; $i < count($values) ; $i++ ){
					for( $j = 0 ; $j < count($values[$i]) ; $j++ ){
						$values[$i][$j] =  "'".$values[$i][$j]."'" ;
					}
					$tab[] = "(". implode( "," , $values[$i] ) . $values_suivi .")";
				}

				$values = implode( "," , $tab );


			}else{

				foreach($values as $key => $value){
					$values[$key] = "'".$value."'";
				}
				
				
				$values = "(". implode( "," , $values ) . $values_suivi .")";

			}
			$fields = implode( "," , $fields );

			
		}else{
			$values = "(".$values.$values_suivi.")";
		}

		if( $onDuplicateKey ){
			$this->Request="INSERT INTO $table( $fields ) VALUES $values ON DUPLICATE KEY UPDATE ".$onDuplicateKey;
		}else{
			$this->Request="INSERT INTO $table( $fields ) VALUES $values ";
		}
		
		if(true === $execute){
			if ( $Insert=mysql_query( $this->Request , $this->Connexion ) ){			
				if( $id = mysql_insert_id( /*$this->Connexion*/ ) ){	
					
					//event dispatcher			
					$this->eventDispatcher->notify(new sfEvent($this, 'database_insert', array('id' => $id, 'table' => $table, "request"=>$this->Request, "params"=>$this->params)));
	
					return $id;
				}else{
					return true;
				}
			}else{
				return $this->checkError();
				//return false;
			}
		
		print $this->Request;
		
		}

	}
	public function update( $table , $set , $where ) {

		//ici on mets à jour le fichier contenant les dates de mises à jour des tables
		$this->update_maj_tables($table);
		
		if( is_array( $set ) ){
			$values = array();
			foreach( $set as $key=>$value ){
				$value = stripslashes(rawurldecode($value));
				//rajoute des antislashs pour l'update
				$value = addslashes($value);
				$values[] =  $key."='".$value."'" ;
			}
			
			$set = implode( "," , $values );
		}


		if (false === empty($this->params) && true === is_string($where)) {
		    
			foreach($this->params as $key => $value) {
				if (false !== strpos($where,":".$key)) {
					$where = str_replace(":".$key, $value, $where);
				}
			}
		} 

		$console = \Controller::getInstance(MAINCONTAINER)->get('console');
		
		$this->Request="UPDATE $table SET $set WHERE $where";

		if ( $Update=mysql_query( $this->Request , $this->Connexion ) ){
			//event dispatcher			
			$this->eventDispatcher->notify(  new sfEvent($this, 'database_update', array("table"=>$table, "request"=>$this->Request, "params"=>$this->params) ));

			return true;
		}else{
			//$this->error = mysql_error();
			return $this->checkError();
			//return false;
		}
		
		return $this;
	}
	
	public function delete( $table , $where ) {

		//mettre à jour le fichier contenant les dates de lises à jour des tables
		$this->update_maj_tables($table);

		if (false === empty($this->params) && true === is_string($where)) {
			foreach($this->params as $key => $value) {
				if (false !== strpos($where,":".$key)) {
					$where = str_replace(":".$key, $value, $where);
				}
			}
		} 

		$this->Request="DELETE FROM $table WHERE $where";
		$Delete = mysql_query( $this->Request , $this->Connexion );
		
		/** si aucune requête trouvé à delete **/
		if (mysql_affected_rows() == 0) {
			return 0; 	
		}
		else if (false === empty($Delete)){
			return $Delete;
		}else{
			$this->checkError();
			return false;
		}
	}

	/**
	 * Create request sql and return one result
	 *
	 * @param string $table
	 * @param string $fields
	 * @param string $where
	 * @param string $order
	 */
	public function selectOne($table, $fields, $where = false, $order = null, $limit = null,$joinList = array(), $exec= true, $cache = false)
	{

		//doit on retrouver les données en cache ?
		if($cache){
		
			$cacheKey = $this->setCacheKey( $table , $fields, $where, $order , $limit, $joinList );
		
			$datas = $this->processCache($cacheKey);
		
			if(!empty($datas)) return $datas;
		}
		
		
		$debut = microtime();
		$response = array();

		$this->Request = 'SELECT ' . $fields . ' FROM ' . $table . ' ';
			
		//ici on traite les jointures ...
		if(count($joinList)){

			foreach($joinList as $join){
				$reference = explode('.', $join['reference']);
				$this->Request .= strtoupper($join['mode']) . " JOIN " . $reference[0] . " ON " . $join['reference']. " = ". $join['cible'] . " " ;
			}
		}


		$where_generated = SQLObject::generateWhere($where);

		if (false === empty($this->params) && true === is_string($where_generated)) {
			foreach($this->params as $key => $value) {
				if (false !== strpos($where_generated,":".$key)) {
					$where_generated = str_replace(":".$key, $value, $where_generated);
				}
			}
		} 

		
		if (false === empty($where_generated)) {
			$this->Request .= ' WHERE ' . $where_generated;
		}
			
		//S'il y a un controleur d'affecté...
		if( $this->orderController ){

			//...et il récupère les données de tri spécifié dans l'instance
			if ($this->orderController->order){
				$this->Request.=" ORDER BY ".$this->orderController->order;
			}
			if ($this->orderController->limit){
				$this->Request.=" LIMIT ".$this->orderController->getSqlLimit();
			}

		}else{
			if (false === empty($this->groupBy)) {
				$this->Request .= ' GROUP BY ' . $this->groupBy;
			}
				
			if (false === empty($order)) {
				$this->Request .= ' ORDER BY ' . $order;
			}
		}
		$this->Request .= ' LIMIT 0,1';

			
		if(!$exec) return;
		
	
		if (false === is_resource($select = mysql_query($this->Request, $this->Connexion))) {
			return $this->checkError();
			//return false;
		}

		$this->num_of_rows = mysql_num_rows($select);
		

		
		if (mysql_num_rows($select) > 0) {
			$Response = mysql_fetch_array($select, MYSQL_ASSOC);
		}
		else if (mysql_num_rows($select) === 0) {
			$Response = 0;
		}
		
		//doit on conserver une copie des resultats en cache ?
		if($cache){ 
			$this->doCache($cacheKey, $this->Request, $table, $Response);
		}
		return $Response;
		
		if(APPLICATION == "DEV") {

			$fin = microtime();

			list($usec1, $sec1) = explode(" ", $debut);

			list($usec2, $sec2) = explode(" ", $fin);

			$this->requestMicroTime = ((($sec2-$sec1)+($usec2-$usec1))*1000)."msec";

			//notification de la requête
			if($this->eventDispatcher)
				$this->eventDispatcher->notify( new sfEvent($this, 'sql_select', array('request' => $this->Request, "requestMicroTime" => $this->requestMicroTime)) );

		}


		return false;
	}

	function selectUnion( $selects , $order = NULL , $limit = NULL, $exec= true, $cache = false ) {
		
		$this->Request = array();
		$tableTab = array();
		$fieldTab = array();
		$whereTab = array();
		$orderTab = array();
		$limitTab = array();
		
		foreach( $selects as $select ){
			if (false === empty($select)) {
				if (true === is_array($select)) {
					$request = "SELECT ".$select[1]." FROM ".$select[0]." ";
					
					//collecte des tables;
					$tableTab[] = $select[0];
					$fieldTab[] = $select[1];
					
					if ( $select[2] ){
						$request.="WHERE ".$select[2];
						$whereTab[] = $select[2];
					
					}
					if ($select[3]){
						$request .= " ORDER BY ".$select[3];
						$orderTab[] = $select[3];
					
					}
					if ($select[4]){
						$request .= " LIMIT ".$select[4];
						$limitTab[] = $select[4];
					}
	
					$this->Request[] = "(".$request.")";
				}
				else if ($select instanceof \SQLObject) {
					//on execute la requête à vide pour récupérer la Requete complète
					$select->execute(false,true,false);
					
					//collecte des tables;
					$tableTab[] = $select->getFrom();
					$fieldTab[] = $select->getField();
					$whereTab[] = $select->getWhere();
					
					$this->Request[] = "(".$select->Request.")";
				}
				
			}
		
		}
		$table = implode(" ,",$tableTab);
		//existance du cache ?
		if($cache){
	
			$cacheKey = $this->setCacheKey( $table , implode(" ,",$fieldTab),  implode(" ",$whereTab), $order , $limit );
		
			$datas = $this->processCache($cacheKey);
		
			if(!empty($datas)) return $datas;
		}
		
		

		$this->Request = implode( " UNION " , $this->Request );

		if ($order){
			$this->Request.=" ORDER BY $order";
		}
		if ($limit){
			$this->Request.=" LIMIT $limit";
		}

		if(true === empty($exec)) return;
			
		if ($Select=mysql_query( $this->Request , $this->Connexion)){
			if(mysql_num_rows($Select)>0){
					
				while ($row = mysql_fetch_array($Select, MYSQL_ASSOC)) {
					$Response[]=$row;
				}
			
				//doit on conserver une copie des resultats en cache ?
				if($cache){ 
					$this->doCache($cacheKey, $this->Request, $table, $Response);
				}
		
				return $Response;
			}else{
				return false;
			}
		}else{
			return $this->checkError();
			//return false;
		}

	}
	function printRequest() {
		echo $this->Request;
	}
	
	/**
	 * 
	 * @param boolean $clean_index_table ( pour les requêtes imbriquées, ne pas effacer le tablea "index_table" )
	 * @return SQLObject
	 */
	function clean($clean_index_table = true){
		$this->joinList = array();
		$this->fieldList = "";
		$this->fromList = array();
		$this->whereList = array();
		$this->groupBy = "";
		$this->orderList = array();
		$this->limit = NULL;
		$this->Request = "";
		$this->num_of_rows = "";
		$this->requestMicroTime = "";
		$this->orderController = NULL;
		
		if(true === $clean_index_table){
			$this->cache_index_table = array();
		}
		
		if( mysql_select_db( $this->DBName , $this->Connexion ) ){
			$this->Connected = true;
			mysql_set_charset('utf8',$this->Connexion);
			return true;
		}else{
			$this->Connected = false;
			$this->checkError();
			return false;
		}
		
		return $this;

	}
	function createTable( $name  , $fields ) {

		//Vérifie si la table n'éxiste pas
		if ( !$this->showTables( $name ) ){


			//Créé la table
			$this->Request = "CREATE TABLE $name ( id INT NOT NULL , ".$fields." , PRIMARY KEY ( id ) ) ENGINE = INNODB" . ' COLLATE "utf8_general_ci"';

			if ( mysql_query( $this->Request , $this->Connexion) ){
				//Transforme id en AUTOINCREMENT
				$this->Request="ALTER TABLE $name CHANGE `id` `id` INT( 11 ) NOT NULL AUTO_INCREMENT";

				if ( mysql_query( $this->Request , $this->Connexion) ){
					return true;
				}else{
					return false;
				}

			}else{
				return $this->checkError();
				//return false;
			}

		}
		return true;

	}
	function showTables( $name = NULL ) {
		$Response = array();

		if( $name ){
			$this->Request="show tables like '$name'";
		}else{
			$this->Request="show tables";
		}

		if ($Select=mysql_query( $this->Request , $this->Connexion)){
			if(mysql_num_rows($Select)>0){
					
				while ($row = mysql_fetch_array($Select, MYSQL_ASSOC)) {
					$Response[]=$row;
				}
				return $Response;
			}else{
				return false;
			}
		}else{
			return $this->checkError();
			//return false;
		}

	}
	function dropTable( $name ) {

		$this->Request="DROP TABLE IF EXISTS $name";

		if ($Select=mysql_query( $this->Request , $this->Connexion)){
			return true;
		}else{
			return $this->checkError();
			//return false;
		}

	}
	function truncateTable( $name ) {

		$this->Request="TRUNCATE TABLE $name";

		if ($Select=mysql_query( $this->Request , $this->Connexion)){
			return true;
		}else{
			return $this->checkError();
			//return false;
		}

	}
	static function generateWhere( $where ) {
		$return = "";

		if( is_array( $where ) ){

			$count_where = 0;
			foreach( $where as $w ){

				if( is_array($w) ){
					if( count($w) > 0 ){
						if( $count_where > 0 ){
							$return .= " AND (".implode(" AND " , $w ).")";
						}else{
							$return .= "(".implode(" AND " , $w ).")";
						}
					}
				}elseif( $w ){
					if( $count_where > 0 ){
						$return .= " AND ".$w;
					}else{
						$return .= $w;
					}
				}
				$count_where++;
			}

		}elseif( $where ){
			$return = $where;
		}

		return $return;
	}

	/**
	 *
	 * permets de tracer les requêtes executées au chargement d'une page
	 * @author rccc
	 *
	 */
	public static function traceTime(sfEvent $event){
		if (null !== $event->getSubject()->errorHandler) {
			foreach($event as $key => $value){
				$event->getSubject()->errorHandler->MiscErrorHandler($key . " => " . $value);
			}
		}
	}


	/**
	 * Interface fluide pour manipuler plus facilement SQLObject
	 *
	 */

	/**
	 * Débute une transaction
	 * http://www.devarticles.com/c/a/MySQL/Using-Transactions-with-MySQL-4.0-and-PHP/
	 */
	public function begin(){
		@mysql_query("BEGIN");
	}

	public function commit(){
		@mysql_query("COMMIT");
	}

	public function rollback(){
		@mysql_query("ROLLBACK");
	}

	/**
	 * Jointure
	 *
	 * @author rccc
	 * @param string $reference
	 * @param string $cible
	 * @param string $mode
	 * @throws Exception
	 * @return object
	 */
	public function addJoin($reference, $cible, $mode = "LEFT"){

		if(empty($reference) || empty($cible)) throw new Exception("SQLObject::addJoin : paramètres manquants");
		
		array_push( $this->joinList, array("reference" => $reference, "cible" => $cible, "mode" => $mode) );
		
		$ref_table_list = explode('.', $reference);
		$ref_cible_list = explode('.', $cible);
		$ref_table = array_shift($ref_table_list);
		$ref_cible = array_shift($ref_cible_list);
		
		$this->addIndexTable(array($ref_table,$ref_cible));
		
		return $this;
	}


	/**
	 * Ajoute un champs/colonne à retourner
	 */
	public function addField($field){
		$this->fieldList .= !empty($this->fieldList) ? ', ' . $field : ' ' . $field;
		return $this;
	}

	/**
	 * Ajoute les champs/colonnes à retourner
	 * @param String $fields
	 */
	public function addFields($fields){
		$this->fieldList .= !empty($this->fieldList) ? ', ' . $fields : ' ' . $fields;
		return $this;
	}

	/**
	 * Spécifie les tables sur lesquelles on effectue la recherche
	 *
	 * @param string $tables
	 */
	public function addFrom($tables){
		array_push($this->fromList, $tables);
		$this->addIndexTable($tables);
		return $this;
	}


	
	public function addDistinct(){
		$this->fieldList = " DISTINCT " . $this->fieldList;
		return $this;
	}

	public function addParams($datas = null) {
		if (false === empty($datas) && true === is_array($datas)) {
			foreach($datas as $key => $value){
				$this->params[$key] = $value;
			}
		}
	}

	public function getParams($key = null) {
		if (false === empty($key) && true === is_string($key) && true === isset($this->params[$key])) {
			return $this->params[$key];
		}
		return false;
	}

	public function getAllParams() {
		if (false === empty($this->params)) {
			return $this->params;
		}
		return false;
	}
	/**
	 * ajoute une clause Where pour les droits
	 *
	 * @param String $type news | collection | games | apps | apps_menu | apps_help | operations | sharing | images | albums
	 */
	public function addWhereRights($table_perm_name, $field_perm_name, $id_member = null){

        // table_perm_name => adm_perm_images 
	 	                        // field_perm_name => image 
	 	                if ('DEV'===APPLICATION) {
	 	                	if (true === empty($this->joinList)) {
	 	                		throw new Exception(sprintf("AddWhereRights : n'oubliez pas d'indiquer la jointure pour le controls de droits %s.%s", $table_perm_name, $field_perm_name));
	 	             	   }
	 	            	}
	 	            	
	 	          		$user_right_checked = \Controller::getInstance(MAINCONTAINER)->get("user");
   

	 	          		if (false === empty($user_right_checked) && $user_right_checked instanceof \User && true === is_numeric($user_right_checked->id)) {

	 	          			if ((true === is_null($id_member) || false === is_numeric($id_member) ) ) {
	 	            			$id_member = $user_right_checked->id;
	 	            		}

		 	                if(true === empty($table_perm_name) || true === empty($field_perm_name)) throw new Exception("SQLObject::addWhereRights : paramètre manquant"); 
		 	 
		 	                $rightController = \Controller::getInstance("RightsController",0); 
		 	 				

		 	                $group_user_available = $rightController->Grp_User_List($user_right_checked); 


		 	                //Si c'est un admin il n'a pas besoin de conditions d'exclusion
		 	                if (false === empty($group_user_available) && (false === empty($group_user_available[GROUP_ADMIN]) || false === empty($group_user_available['00000001']))){ 
		 	                        return $this; 
		 	                } 
		 	                //sinon on check les droits spécifiques du membres
 		 	                if (false === empty($group_user_available) && true === is_array($group_user_available)) { 
		 	                    $where = false; 
		 	                    $coor = "AND"; 
								$groupswhere = "";
								$prefix = "(";
								$sufix = ")";
		 	                    foreach ($group_user_available as $grp) { 
		 	                                if (false === empty($grp)) { 
												if (false === empty($groupswhere)) {
													$groupswhere .= " OR ";	
												}
		 	                                    $where = true; 
		 	                                    $groupswhere .= $table_perm_name.".groups = '".$grp."'";         
		 	                                } 
		 	                    }

		 	                    if (true === is_numeric($id_member)) {
		 	                		$memberadd = sprintf(" OR ( %s.member = %d) ",$table_perm_name,$id_member);
		 	             		}

								$this->addWhere($prefix.$groupswhere.$memberadd.$sufix, $coor);                            
		 	                }

		 	               
	 	           		}
	 	           		else {
	 	           		
	 	           			throw new Exception("User not Available");
	 	           		}
	 	            
		return $this;
	}


	/**
	 * add where condition
	 * @param String $condition
	 */
	public function addWhere($condition, $connector = "AND"){
		if(count($this->whereList) == 0 ) $connector = " ";
		array_push($this->whereList, " " . $connector ." ". $condition);
		return $this;
	}

	/**
	 * ???
	 * @param unknown_type $col
	 * @param unknown_type $condition
	 * @param unknown_type $connector
	 * @return SQLObject
	 */
	public function addWhereIn ($col, $condition, $connector = "AND"){
		
		if ( $condition instanceof \SQLObject) {
			$exec = $condition->execute(true,true,true);
		
			if (false === empty($exec)) {
				$array_implode = array();
				foreach ($exec as $sub) {
					$array_implode[] = $sub["result"];
				}
				$condition = implode(',',$array_implode);
			}
		
		}
		
		if(count($this->whereList) == 0 ) $connector = " ";
		array_push($this->whereList, " " . $connector ." ". $col ." IN (". $condition .")");
				
		return $this;
		
	}
	

	/**
	 * Dans le cas ou on souhaite une seule requête contenant des requêtes imbriquées
	 * 
	 * @param string $queryString
	 * @param string $connector
	 * 
	 */
	public function addSubQueryString($col, $queryString, $criteria = "IN",  $connector = "AND"){
		
		if(count($this->whereList) == 0 ) $connector = " ";
		array_push($this->whereList, " " . $connector ." " .$col. " ". $criteria ." (". $queryString .")");	

		return $this;
	}
	
	
	/**
	 * addGroupBy clause
	 */
	public function addGroupBy($groupBy){
		$this->groupBy = $groupBy;

		return $this;
	}


	/**
	 * add Order clause
	 */
	public function addOrder($order, $mode =''){
		
		if(! $this->orderController instanceof OrderController){
			$this->orderController = new OrderController();
		}

		//if(empty($mode)) $mode = "DESC";
		
		$this->orderController->defineOrder($order .' '. $mode);

		return $this;
	}

	/**
	 * add Limit clause
	 *
	 *
	 */
	public function addLimit($offset, $limit){

		if(! $this->orderController instanceof OrderController){
			$this->orderController = new OrderController();
		}

		$limit = ( $limit > 1 ) ? $limit : 1;
		$this->limit = array('offset'=> $offset, 'limit' => $limit);
					
		$this->orderController->definePage(floor($offset/$limit));
		$this->orderController->defineLimit($limit);

		return $this;
	}


	/**
	 * execute select statement
	 *
	 */
	public function execute($exec = true, $multi = true, $cache = false){
		$method = ($multi == true) ? 'select' : 'selectOne';
		$return_select =  $this->$method(
				implode(', ', $this->fromList),
				$this->fieldList,
				implode(' ', $this->whereList),
				implode(', ', $this->orderList),
				/*$this->limit['offset'] .','. $this->limit['limit']*/ '', /* on passe par l'instance d'orderController ... */
				$this->joinList,
				$exec,
				$cache
		);
				
		return $return_select;
	}

	/**
	 * update le fichier contenant les dates de mise à jour des tables
	 * @param string $table
	 */
	public function update_maj_tables($table){
		
		
		//if(APPLICATION == "DEV") $t1 = xdebug_time_index();
		
		// on filtre certaines tables
		if(in_array($table, array('cntrl_in', 'adm_members'))) return;

				
		if(true === $this->isApcCache || true === $this->isMemCache){
			$maj_tables = unserialize($this->cache->fetch($this->maj_table_path));
		}elseif(true === $this->isFileCache){
			$maj_tables = @include($this->maj_table_path);
		}
		
		//nanotime
		$nanotime = trim(shell_exec('date +%s%N'));
		
		//microseconde
	//	$microseconde = microtime();
	
		
		if(true === is_array($maj_tables)){			
			$maj_tables[$table] = $nanotime;
		}else{
			$maj_tables = array();
			$maj_tables[$table] = $nanotime;
		}
		
		
		if(true === $this->isFileCache){
			$content = "<?php return " .  var_export($maj_tables, true) . ";";
		}elseif(true === $this->isApcCache || true === $this->isMemCache){
			$content = serialize($maj_tables);
		}

		if(true === $this->isApcCache || true === $this->isMemCache){
			$this->cache->store($this->maj_table_path, $content);
		}elseif(true === $this->isFileCache){
			file_put_contents($this->maj_table_path, $content, LOCK_EX);
		}
		
// 		if(APPLICATION == "DEV" && $this->eventDispatcher){
// 			$this->eventDispatcher->notify( new sfEvent($this, 'update_maj_table', array("cache_time" => xdebug_time_index()-$t1)) );
// 		}
	}

	/**
	 * check si peut renvoyer le cache -  s'il existe -  ou pas
	 * @param string $params continet les paramètres de la requête
	 * @return BOOLEAN true si le cache peut être renvoyé, false le cas échéant
	 */
	public function check_cache($key){
		
		if($this->cache->exists($key)){
			
			$from_cache = $this->cache->fetch($key);
			
						
			//les tables ont elle été mis à jour depuis la création du cache ?
			//on récupère la liste des tables passées en paramètre de la méthode "select"

			$request_tables = explode(',', $from_cache['tables']);
			
			//on récupère le fichier contenant les dates de modification des tables
			if(true === $this->isApcCache || true === $this->isMemCache){
				$maj_tables = unserialize($this->cache->fetch($this->maj_table_path));
			}
			elseif(true === $this->isFileCache){
				$maj_tables = @include($this->maj_table_path);
			}

			foreach($request_tables  as $table){
					
				if(true === isset($maj_tables[$table])){
					if($maj_tables[$table] > $from_cache['date']){
						//on supprime le cache
						$this->cache->delete($key);
						return false;
					}
				}
			}
			
			return $from_cache ;
		}
		
		return false;

	}

	public function setCacheKey( $table , $fields ="", $where ="", $order ="" , $limit="", $joinList="" ){

		if(!$this->orderController instanceof OrderController){
			$this->orderController = new OrderController();
		}

		if(!empty($order)){
			$this->orderController->defineOrder($order);
		}

		if(!empty($limit)){
				
			$this->orderController->defineLimit($limit);
		}

		if(is_array($joinList)){
			$joinString = array();
			foreach($joinList as $joinItem){
				$joinString[] = $joinItem['reference'];
			}
			$joinString = implode('_', $joinString);
		}else{
			$joinString = "";
		}

		$this->cacheKey = 'sqlobject_' . $this->cache->createKey( $table . $fields . $where . $this->orderController->getOrder() . $this->orderController->getSqlLimit() . $joinString );
		
		return $this->cacheKey;
	}

	/**
	 * 
	 * @param string $cacheKey
	 */
	public function processCache($cacheKey){
		
		if(APPLICATION == "DEV") $t1 = xdebug_time_index();
		
		$from_cache = $this->check_cache($cacheKey);

		if(APPLICATION == "DEV" && $this->eventDispatcher) {
			$this->eventDispatcher->notify( new sfEvent($this, 'cache_time', array("cache_time" => xdebug_time_index()-$t1)) );
		}

		if(false !== $from_cache ){
			$this->num_of_rows = $from_cache['num_of_rows'];
			$this->Request = $from_cache['request'];
			return $from_cache['rows'];
		}
		
		return false;
	}
	
	private function doCache($cacheKey,$request, $table, $Response){

		$jointures = array();
		if(false === empty($joinList)){
			$jointures = array_merge($joinList['reference'], $joinList['cible']);
		}
		
		//$nanotime = system('date +%s%N');
		$nanotime = shell_exec('date +%s%N');
		
		$cache_datas = array(
				"rows" => $Response,
				"num_of_rows" => $this->num_of_rows,
				"date" => $nanotime,
				"tables" => implode(',',$this->cache_index_table), //$table .  implode(' ', $jointures),
				"request" => $request
		);

		$this->cache->store($cacheKey, $cache_datas);
		
		//on vide le tableau index_table
		$this->cache_index_table = array();
	}

	/**
	 * Mets à jour le tableau des tables sollicitée pour une requête
	 * @param mixed string | array $tables
	 */
	protected function addIndexTable($tables){
		if(false === is_array($tables)) {
			$tables = array($tables);
		}
		foreach($tables as $table){
			$table_list = explode(',', $table);
			if(false === in_array($table_list[0],$this->cache_index_table)){
				array_push($this->cache_index_table,$table_list[0]);
			}
		}
	}
	
	
	public function getFrom() {
		$from = false;
		if (false === empty($this->fromList)) {
			$from = implode(', ', $this->fromList);
		}
		
		return $from;			
	}
	
	public function getField() {
		$field = false;
	
		if ( false === empty($this->fieldList)) {
			if (true === is_array($this->fieldList)) {
				$field = implode(', ', $this->fieldList);
			}
			else if (true === is_string($this->fieldList)) {
				$field = $this->fieldList;
			}
		}
		return $field;
	}
	
	public function getWhere() {
		$where = false;

		if (false === empty($this->whereList)) {
			$where = implode(' ', $this->whereList);
		}
		return $where;
	}
	
	public function getOrder() {
		$order = false;
		if (false === empty($this->orderList)) {
			$order = implode(', ', $this->orderList);	
		}
		return $order;
	}
	
	/**
	 * 
	 * @param string $sql
	 */

	public function query($sql,$multiple = false, $cache = false){
		
		//doit on retrouver les données en cache ?
		if($cache){
			if (false === empty($multiple)) { $multiple_cache = 1; }
			$cacheKey = $this->setCacheKey( $table.$multiple_cache );
		
			$datas = $this->processCache($cacheKey);
		
			if(!empty($datas)) return $datas;
		}
		
		
		
		$debut = microtime();
		$res = mysql_query($sql, $this->Connexion);
		$response = array();
		if(true === is_bool($res)){
			return $res;
		}
		else{
			if (false === $multiple) {
				if($row = mysql_fetch_array($res, MYSQL_ASSOC)){
					$this->Request = $sql;
					return $row;
				}
			}
			else if (true === $multiple) {
				while ($row = mysql_fetch_array($res, MYSQL_ASSOC)){
					$this->Request = $sql;
					$response[] = $row;
				}
				
				$fin = microtime();

				list($usec1, $sec1) = explode(" ", $debut);

				list($usec2, $sec2) = explode(" ", $fin);

				$this->requestMicroTime = ((($sec2-$sec1)+($usec2-$usec1))*1000)."msec";
					
				return $response;
			}
		}
		
		return false;
	}

}
