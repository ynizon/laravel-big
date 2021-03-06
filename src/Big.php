<?php

namespace Prologuetech\Big;

use Carbon\Carbon;
use Exception;
use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Table;
use Google\Cloud\ServiceBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Big
{
    /**
     * @var BigQueryClient
     */
    public $query;

    /**
     * @var array
     */
    public $options;

    /**
     * @var string
     */
    public $defaultDataset;

    /**
     * Setup our Big wrapper with Google's BigQuery service
     */
    public function __construct()
    {
        // Set default options
        $this->options = [
            'useLegacySql' => false,
            'useQueryCache' => false,
        ];

        // Build our Google config options
        $config = [
            'projectId' => config('prologue-big.big.project_id'),
        ];

        // Allow Google's default application credentials if developer chooses
        if (!is_null(config('prologue-big.big.auth_file'))) {
            $config['keyFilePath'] = config('prologue-big.big.auth_file');
        }

        // Setup google service with credentials
        $googleService = new ServiceBuilder($config);

        // Set a default dataset
        $this->defaultDataset = config('prologue-big.big.default_dataset');

        // Return our instance of BigQuery
        $this->query = $googleService->bigQuery();
    }

    /**
     * Wrap around Google's BigQuery run method and handle results
     *
     * @param string $query
     * @param array|null $options
	 * @param boolean $bReturn (determine if the function need to return data)
     *
     * @return \Illuminate\Support\Collection
     */
    public function run($query, $options = null, $bReturn = true)
    {
        // Set default options if nothing is passed in
        $options = $options ?? $this->options;

        $queryResults = $this->query->runQuery($this->query->query($query, $options));

        // Setup our result checks
        $isComplete = $queryResults->isComplete();

        while (!$isComplete) {
            sleep(.5); // let's wait for a moment...
            $queryResults->reload(); // trigger a network request
            $isComplete = $queryResults->isComplete(); // check the query's status
        }

        // Mutate into a laravel collection
		if ($bReturn){
			$data = array();
			foreach ($queryResults->rows() as $row) {
				$data[] = $row;
			}

			return collect($data ?? []);
		}
    }

    /**
     * Wrap around Google's BigQuery insert method
     *
     * @param Table $table
     * @param array $rows
     * @param array|null $options
     *
     * @return bool|array
     * @throws \Exception
     */
    public function insert($table, $rows, $options = null, $verbose = null)
    {
        // Set default options if nothing is passed in
        $options = $options ?? ['ignoreUnknownValues' => true];

        $insertResponse = $table->insertRows($rows, $options);


        if ($insertResponse->isSuccessful() && ! $verbose) {
            return true;
        } else {
            foreach ($insertResponse->failedRows() as $row) {
                foreach ($row['errors'] as $error) {
                    $errors[] = $error;
                }
            }
            // If verbose return affected_rows, info, and any errors
            if ($verbose)
            {
                $errors = $errors ?? [];

                return ['affected_rows' => count($rows) - count($errors), 'errors' => $errors, 'info' => $insertResponse->info()];
            }
            else {
                return $errors ?? [];
            }
        }


    }

    /**
     * @param string $tableName
     * @param string|null $dataset
     *
     * @return Table|null
     * @throws Exception
     */
    public function getTable($tableName, $dataset = null)
    {
        // Defaults
        $dataset = $dataset ?? $this->defaultDataset;

        $tables = $this->query->dataset($dataset)->tables();

        /** @var Table $table */
        foreach ($tables as $table) {
            if ($table->id() == $tableName) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection|array $data
     *
     * @return array
     */
    public function prepareData($data)
    {
        $preparedData = [];

        // We loop our data and handle object conversion to an array
        foreach ($data as $item) {
            if (!is_array($item)) {
                $item = $item->toArray();
            }

            $struct = [];

            // Handle nested array's as STRUCT<>
            foreach ($item as $field => $value) {
                // Map array's to STRUCT name/type
                if (is_array($value)) {
                    foreach ($value as $key => $attr) {
                        $struct[] = [
                            'name' => $key,
                            'type' => strtoupper(gettype($attr)),
                        ];
                    }
                }
            }

            // If we have an id column use Google's insertId
            // https://cloud.google.com/bigquery/streaming-data-into-bigquery#dataconsistency
            if (array_key_exists('id', $item)) {
                $rowData = [
                    'insertId' => $item['id'],
                    'data' => $item,
                    'fields' => $struct,
                ];
            } else {
                $rowData = ['data' => $item];
            }

            // Set our struct definition if we have one
            if (!empty($struct)) {
                $rowData['fields'] = $struct;
            }

            $preparedData[] = $rowData;
        }

        return $preparedData;
    }

    /**
     * Wrapper function around the BigQuery create_table() function.
     * We also have the benefit of mutating a Laravel Eloquent Model into a proper field map for automation
     *
     * Example:
     * $fields = [
     *     [
     *         'name' => 'field1',
     *         'type' => 'string',
     *         'mode' => 'required'
     *     ],
     *     [
     *         'name' => 'field2',
     *         'type' => 'integer'
     *     ],
     * ];
     * $schema = ['fields' => $fields];
     * create_table($projectId, $datasetId, $tableId, $schema);
     *
     * @param string $datasetId
     * @param string $tableId
     * @param Model $model
     * @param array|null $structs
     * @param bool $useDelay
     *
     * @throws Exception
     * @return Table|null
     */
    public function createFromModel($datasetId, $tableId, $model, $structs = null, $useDelay = true)
    {
        // Check if we have this table
        $table = $this->getTable($tableId, $datasetId);

        // If this table has been created, return it
        if ($table instanceof Table) {
            return $table;
        }

        // Generate a new dataset
        $dataset = $this->query->dataset($datasetId);

        // Flip our Eloquent model into a BigQuery schema map
        $options = ['schema' => static::flipModel($model, $structs)];

        // Create the table
        $table = $dataset->createTable($tableId, $options);

        // New tables are not instantly available, we will insert a delay to help the developer
        if ($useDelay) {
            sleep(10);
        }

        return $table;
    }

    /**
     * Flip a Laravel Eloquent Models into a Big Query Schemas
     *
     * @param Model $model
     * @param array|null $structs
     *
     * @throws Exception
     * @return array
     */
    public static function flipModel($model, $structs)
    {
        // Verify we have an Eloquent Model
        if (!$model instanceof Model) {
            throw new Exception(__METHOD__ . ' requires a Eloquent model, ' . get_class($model) . ' used.');
        }

        // Cache name based on table
        $cacheName = __CLASS__ . '.cache.' . $model->getTable();

        // Cache duration
        $liveFor = Carbon::now()->addDays(5);

        // Cache our results as these rarely change
		/*
        $fields = Cache::remember($cacheName, $liveFor, function () use ($model) {
            return DB::select('describe ' . $model->getTable());
        });
		*/
		$fields = DB::select('describe ' . $model->getTable());

        // Loop our fields and return a Google BigQuery field map array
        return ['fields' => static::fieldMap($fields, $structs)];
    }

	/**
     * Flip a Laravel Big Query Schemas into a Eloquent Models 
     *
     * @param Model $model
     * @param array|null $results from run(SQL Query )
     *
     * @throws Exception
     * @return array
     */
    public static function unflipModel($model, $results)
    {
        // Verify we have an Eloquent Model
        if (!$model instanceof Model) {
            throw new Exception(__METHOD__ . ' requires a Eloquent model, ' . get_class($model) . ' used.');
        }

       $data = array();
	   foreach ($results as $result){
		   $o = new $model();

			foreach ($result as $column=>$value){
				if (is_a($value, 'Google\Cloud\BigQuery\Timestamp')) {
					$o->$column = date("Y-m-d H:i:s",strtotime($value->__toString()));
				}else{
					$o->$column = $value;
				}
			}

			$data[] = $o;
	   }	   
	   
        return collect($data ?? []);
    }
	
    /**
     * Map our fields to BigQuery compatible data types
     *
     * @param array $fields
     * @param array|null $structs
     *
     * @return array
     */
    public static function fieldMap($fields, $structs)
    {
        // Holders
        $map = [];

        // Loop our fields and map them
        foreach ($fields as $value) {
            // Compute short name for matching type
            $shortType = trim(explode('(', $value->Type)[0]);
            switch ($shortType) {
                // Custom handler
                case Types::TIMESTAMP:
                    $type = 'TIMESTAMP';
                    break;
                // Custom handler
                case Types::INT:
                    $type = 'INTEGER';
                    break;
                // Custom handler
                case Types::TINYINT:
                    $type = 'INTEGER';
                    break;
                case Types::BIGINT:
                    $type = 'INTEGER';
                    break;
                case Types::BOOLEAN:
                    $type = 'BOOLEAN';
                    break;
                case Types::DATE:
                    $type = 'DATETIME';
                    break;
                case Types::DATETIME:
                    $type = 'DATETIME';
                    break;
                case Types::DECIMAL:
                    $type = 'FLOAT';
                    break;
                case Types::FLOAT:
                    $type = 'FLOAT';
                    break;
                case Types::INTEGER:
                    $type = 'INTEGER';
                    break;
                case Types::SMALLINT:
                    $type = 'INTEGER';
                    break;
                case Types::TIME:
                    $type = 'TIME';
                    break;
                case Types::DOUBLE:
                    $type = 'FLOAT';
                    break;
                case Types::JSON:
                    // JSON data-types require a struct to be defined, here we check for developer hints or skip these
                    if (!empty($structs)) {
                        $struct = $structs[$value->Field];
                    } else {
                        continue 2;
                    }

                    $type = 'STRUCT';

                    break;
                default:
                    $type = 'STRING';
                    break;
            }

            // Nullable handler
            $mode = (strtolower($value->Null) === 'yes' ? 'NULLABLE' : 'REQUIRED');

            // Construct our BQ schema data
            $fieldData = [
                'name' => $value->Field,
                'type' => $type,
                'mode' => $mode,
            ];

            // Set our struct definition if we have one
            if (!empty($struct)) {
                $fieldData['fields'] = $struct;

                unset($struct);
            }

            $map[] = $fieldData;
        }

        // Return our map
        return $map;
    }

    /**
     * Return the max ID
     *
     * @param string $table
     * @param string|null $dataset
     *
     * @return mixed
     */
    public function getMaxId($table, $dataset = null)
    {
        // Defaults
        $dataset = $dataset ?? $this->defaultDataset;

        // Run our max ID query
        $results = $this->run('SELECT max(id) id FROM `' . $dataset . '.' . $table . '`');

        return $results->first()['id'];
    }

    /**
     * Return the max created_at date
     *
     * @param string $table
     * @param string|null $dataset
     *
     * @return mixed
     */
    public function getMaxCreationDate($table, $dataset = null)
    {
        // Defaults
        $dataset = $dataset ?? $this->defaultDataset;

        // Run our max created_at query
        $results = $this->run('SELECT max(created_at) created_at FROM `' . $dataset . '.' . $table . '`');

        return $results->first()['created_at'];
    }

    /**
     * Return the max of field
     *
     * @param string $table
     * @param string $field
     * @param string|null $dataset
     *
     * @return mixed
     */
    public function getMaxField($table, $field, $dataset = null)
    {
        // Defaults
        $dataset = $dataset ?? $this->defaultDataset;

        // Run our max query
        $results = $this->run('SELECT max(' . $field . ') ' . $field . ' FROM `' . $dataset . '.' . $table . '`');

        return $results->first()[$field];
    }
	
	/**
     * Bind data to sql query and replace table name with the correct dataset and project name
     *
     * @param object(Illuminate\Database\Eloquent\Builder) $query with ? for bind data
     * @param array $tables (table name used in the query) for replacing with the correct name project+dataset+table
     * @param int $num (numbert of items in pagination) use 0 for illimited
	 * @param int $page (page parameter for pagination)
	 * @param array $arrLikeFields (fields with "like queries" to replace with lower case)
	 * 
     * @return mixed
	 *
	 * Example:
	 *  $query = Product::where("sku","=",$sku);
	 *	$sql = $big->bindQuery($query, array("products"));
	 *	$results = Big::unflipModel(new Product(), $big->run($sql));
     */
	public function bindQuery($query, $tables, $num=15, $page = 1, $arrLikeFields =[]){
		
		$sql = $query->toSql();
		$bindDataArr =$query->getBindings();
		
		foreach($bindDataArr as $binding)
		{
			$value = is_numeric($binding) ? $binding : "'".$binding."'";
			$sql = preg_replace('/\?/', $value, $sql, 1);
		}
		
		foreach ($arrLikeFields as $field){
			$sql = str_ireplace("`".$field."` LIKE","LOWER(`".$field."`) LIKE",$sql);
		}
		
		$dataset = $dataset ?? $this->defaultDataset;
		foreach ($tables as $table){
			$sql = str_ireplace("`".$table."`","`".config('prologue-big.big.project_id').".".$dataset.".".$table."`",$sql);
		}
		
		if (!isset($page)){
			$page = 1;
		}
		
		if ($num>0){
			$sql .= " limit ".$num." offset ".($num*($page-1));
		}
		
		$sql = str_ireplace("`RAND()`","RAND()",$sql);
		//echo $sql;
		return $sql;
	}
	
	/**
     * Bind data to sql query and replace table name with the correct dataset and project name
     *
     * @param object(Illuminate\Database\Eloquent\Builder) $query with ? for bind data
     * @param array $tables (table name used in the query) for replacing with the correct name project+dataset+table
	 * @param array $arrLikeFields (fields with "like queries" to replace with lower case)
	 * 
     * @return mixed
	 *
	 * Example:
	 *  $query = Product::where("sku","=",$sku);
	 *	$sql = $big->bindCountQuery($query, array("products"));
     */
	public function bindCountQuery($sql, $tables, $arrLikeFields =[]){
		
		$sql = $query->toSql();
		$sql = str_ireplace("select *", "select count(*) as nb", $sql);
		$bindDataArr =$query->getBindings();
		
		foreach($bindDataArr as $binding)
		{
			$value = is_numeric($binding) ? $binding : "'".$binding."'";
			$sql = preg_replace('/\?/', $value, $sql, 1);
		}
		
		foreach ($arrLikeFields as $field){
			$sql = str_ireplace("`".$field."` LIKE","LOWER(`".$field."`) LIKE",$sql);
		}
		
		$dataset = $dataset ?? $this->defaultDataset;
		foreach ($tables as $table){
			$sql = str_ireplace("`".$table."`","`".config('prologue-big.big.project_id').".".$dataset.".".$table."`",$sql);
		}

		return $sql;
	}
	
	/**
	When you need to extract only the first line of a group, you can use row_number filter
	In your request select, use "ROW_NUMBER()  OVER(PARTITION BY field)  as row_number"
	It will generates and run a request like this:
	Ex: select * from (
			select `field1`, `field2`, ROW_NUMBER()  OVER(PARTITION BY field)  as `row_number` from `...` where `...
			group by field1
		) where row_number=1
	*/
	public function runByRowNumber($psql,$iRowNumber = 1){
		$sql = $this->getByRowNumber($psql,$iRowNumber);
		
		return $this->run($sql);
	}
	
	/**
	When you need to extract only the first line of a group, you can use row_number filter
	In your request select, use "ROW_NUMBER()  OVER(PARTITION BY field)  as row_number"
	It will generates and run a request like this:
	Ex: select * from (
			select `field1`, `field2`, ROW_NUMBER()  OVER(PARTITION BY field)  as `row_number` from `...` where `...
			group by field1
		) where row_number=1
	*/
	public function getByRowNumber($psql,$iRowNumber = 1, $num=15, $page = 1){
		$psql = str_ireplace("`ROW_NUMBER()","ROW_NUMBER()",$psql);
		$psql = str_ireplace(")` as `row_number",") as `row_number",$psql);
		$sql = "SELECT * from (".$psql.") where row_number=".$iRowNumber;
		
		if (!isset($page)){
			$page = 1;
		}
		
		if ($num>0){
			$sql .= " limit ".$num." offset ".($num*($page-1));
		}
		
		return $sql;
	}
	
}
