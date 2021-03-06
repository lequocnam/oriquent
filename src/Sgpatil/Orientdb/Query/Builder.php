<?php

namespace Sgpatil\Orientdb\Query;

use Closure;
use Sgpatil\Orientdb\Connection;
use Illuminadte\Database\Query\Expression;
use Illuminate\Database\Eloquent\Collection;
use Sgpatil\Orientdb\Query\Grammars\Grammar;
use Illuminate\Database\Query\Builder as IlluminateQueryBuilder;

class Builder extends IlluminateQueryBuilder {

    /**
     * The database connection instance
     *
     * @var Sgpatil\Orientdb\Connection
     */
    protected $connection;

    /**
     * The database active client handler
     *
     * @var \Orientdb\Client
     */
    protected $client;

    /**
     * The matches constraints for the query.
     *
     * @var array
     */
    public $matches = array();

    /**
     * The WITH parts of the query.
     *
     * @var array
     */
    public $with = array();

    /**
     * The current query value bindings.
     *
     * @var array
     */
    protected $bindings = array(
        'matches' => [],
        'select' => [],
        'join' => [],
        'where' => [],
        'having' => [],
        'order' => []
    );

    /**
     * All of the available clause operators.
     *
     * @var array
     */
    protected $operators = array(
        '+', '-', '*', '/', '%', '^', // Mathematical
        '=', '<>', '<', '>', '<=', '>=', // Comparison
        'IS NULL', 'IS NOT NULL',
        'AND', 'OR', 'XOR', 'NOT', // Boolean
        'IN, [x], [x .. y]', // Collection
        '=~'                             // Regular Expression
    );

    /**
     * Create a new query builder instance.
     *
     * @param Sgpatil\Orientdb\Connection $connection
     * @return void
     */
    public function __construct(Connection $connection, Grammar $grammar) {
        $this->grammar = $grammar;
        $this->grammar->setQuery($this);

        $this->connection = $connection;

        $this->client = $connection->getClient();
    }

    /**
     * Set the node's label which the query is targeting.
     *
     * @param  string  $label
     * @return \Sgpatil\Orientdb\Query\Builder|static
     */
    public function from($label) {
        $this->from = $label;

        return $this;
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param  array   $values
     * @param  string  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null) {
        $sql = $this->grammar->compileInsertGetId($this, $values, $sequence);
        $values = $this->cleanBindings($values);
        $res = $this->connection->insert($sql);
        $res = $res->getData();
        if (isset($res[0])) {
            return $res[0]['@rid'];
        }
        return false;
    }

    /**
     * Update a record in the database.  
     *
     * @param  array  $values
     * @return int
     */
    public function update(array $values) {
        $cypher = $this->grammar->compileUpdate($this, $values);

        $bindings = $this->getBindingsMergedWithValues($values);

        $updated = $this->connection->update($cypher, $bindings);

        return (isset($updated[0]) && isset($updated[0][0])) ? $updated[0][0] : 0;
    }

    /**
     *  Bindings should have the keys postfixed with _update as used
     *  in the CypherGrammar so that we differentiate them from
     *  query bindings avoiding clashing values.
     *
     * @param  array $values
     * @return array
     */
    protected function getBindingsMergedWithValues(array $values) {
        $bindings = [];

        foreach ($values as $key => $value) {
            $bindings[$key . '_update'] = $value;
        }

        return array_merge($this->getBindings(), $bindings);
    }

    /**
     * Get the current query value bindings in a flattened array
     * of $key => $value.
     *
     * @return array
     */
    public function getBindings() {
        $bindings = [];

        // We will run through all the bindings and pluck out
        // the component (select, where, etc.)
        foreach ($this->bindings as $component => $binding) {
            if (!empty($binding)) {
                // For every binding there could be multiple
                // values set so we need to add all of them as
                // flat $key => $value item in our $bindings.
                foreach ($binding as $key => $value) {
                    $bindings[$key] = $value;
                }
            }
        }

        return $bindings;
    }

    /**
     * Add a basic where clause to the query.
     *
     * @param  string  $column
     * @param  string  $operator
     * @param  mixed   $value
     * @param  string  $boolean
     * @return \Illuminate\Database\Query\Builder|static
     *
     * @throws \InvalidArgumentException
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and') {
        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        if (is_array($column)) {
            return $this->whereNested(function ($query) use ($column) {
                        foreach ($column as $key => $value) {
                            $query->where($key, '=', $value);
                        }
                    }, $boolean);
        }

        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        if (func_num_args() == 2) {
            list($value, $operator) = [$operator, '='];
        } elseif ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        // If the columns is actually a Closure instance, we will assume the developer
        // wants to begin a nested where statement which is wrapped in parenthesis.
        // We'll add that Closure to the query then return back out immediately.
        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if (!in_array(strtolower($operator), $this->operators, true)) {
            list($value, $operator) = [$operator, '='];
        }

        // If the value is a Closure, it means the developer is performing an entire
        // sub-select within the query and we will need to compile the sub-select
        // within the where clause to get the appropriate query record results.
        if ($value instanceof Closure) {
            return $this->whereSub($column, $operator, $value, $boolean);
        }

        // If the value is "null", we will just assume the developer wants to add a
        // where null clause to the query. So, we will allow a short-cut here to
        // that method for convenience so the developer doesn't have to check.
        if (is_null($value)) {
            return $this->whereNull($column, $boolean, $operator != '=');
        }

        // Now that we are working with just a simple query we can put the elements
        // in our array and add the query binding to our array of bindings that
        // will be bound to each SQL statements when it is finally executed.
        $type = 'Basic';

        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        if (!$value instanceof Expression) {
            $this->addBinding($value, 'where');
        }

        return $this;
    }

    /**
     * Increment the value of an existing column on a where clause.
     * Used to allow querying on the same attribute with different values.
     *
     * @param  string $column
     * @return string
     */
    protected function prepareBindingColumn($column) {
        $count = $this->columnCountForWhereClause($column);
        return ($count > 0) ? $column . '_' . ($count + 1) : $column;
    }

    /**
     * Get the number of occurrences of a column in where clauses.
     *
     * @param  string $column
     * @return int
     */
    protected function columnCountForWhereClause($column) {
        if (is_array($this->wheres))
            return count(array_filter($this->wheres, function($where) use($column) {
                        return $where['column'] == $column;
                    }));
    }

    /**
     * Add a "where in" clause to the query.
     *
     * @param  string  $column
     * @param  mixed   $values
     * @param  string  $boolean
     * @param  bool    $not
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false) {
        $type = $not ? 'NotIn' : 'In';

        // If the value of the where in clause is actually a Closure, we will assume that
        // the developer is using a full sub-select for this "in" statement, and will
        // execute those Closures, then we can re-construct the entire sub-selects.
        if ($values instanceof Closure) {
            return $this->whereInSub($column, $values, $boolean, $not);
        }

        $property = $column;

//        if ($column == 'id')
//            $column = 'id(' . $this->modelAsNode() . ')';

        $this->wheres[] = compact('type', 'column', 'values', 'boolean');

        $property = $this->wrap($property);

        $this->addBinding([$property => $values], 'where');

        return $this;
    }

    /**
     * Add a where between statement to the query.
     *
     * @param  string  $column
     * @param  array   $values
     * @param  string  $boolean
     * @param  bool  $not
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereBetween($column, array $values, $boolean = 'and', $not = false) {
        $type = 'between';

        $property = $column;

        if ($column == 'id')
            $column = 'id(' . $this->modelAsNode() . ')';

        $this->wheres[] = compact('column', 'type', 'boolean', 'not');

        $this->addBinding([$property => $values], 'where');

        return $this;
    }

    /**
     * Add a "where null" clause to the query.
     *
     * @param  string  $column
     * @param  string  $boolean
     * @param  bool    $not
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereNull($column, $boolean = 'and', $not = false) {
        $type = $not ? 'NotNull' : 'Null';

        if ($column == 'id')
            $column = 'id(' . $this->modelAsNode() . ')';

        $binding = $this->prepareBindingColumn($column);

        $this->wheres[] = compact('type', 'column', 'boolean', 'binding');

        return $this;
    }

    /**
     * Add a WHERE statement with carried identifier to the query.
     *
     * @param  string $column
     * @param  string $operator
     * @param  string $value
     * @param  string $boolean
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function whereCarried($column, $operator = null, $value = null, $boolean = 'and') {
        $type = 'Carried';

        $this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

        return $this;
    }

    /**
     * Add a WITH clause to the query.
     *
     * @param  array  $parts
     * @return \Sgpatil\Orientdb\Query\Builder|static
     */
    public function with(array $parts) {
        foreach ($parts as $key => $part) {
            $this->with[$key] = $part;
        }

        return $this;
    }

    /**
     * Insert a new record into the database.
     *
     * @param  array  $values
     * @return bool
     */
    public function insert(array $values) {
        // Since every insert gets treated like a batch insert, we will make sure the
        // bindings are structured in a way that is convenient for building these
        // inserts statements by verifying the elements are actually an array.
        if (!is_array(reset($values))) {
            $values = array($values);
        }

        // Since every insert gets treated like a batch insert, we will make sure the
        // bindings are structured in a way that is convenient for building these
        // inserts statements by verifying the elements are actually an array.
        else {
            foreach ($values as $key => $value) {
                ksort($value);
                $values[$key] = $value;
            }
        }

        // We'll treat every insert like a batch insert so we can easily insert each
        // of the records into the database consistently. This will make it much
        // easier on the grammars to just handle one type of record insertion.
        $bindings = array();

        foreach ($values as $record) {
            $bindings[] = $record;
        }

        $cypher = $this->grammar->compileInsert($this, $values);


        // Once we have compiled the insert statement's Cypher we can execute it on the
        // connection and return a result as a boolean success indicator as that
        // is the same type of result returned by the raw connection instance.
        $bindings = $this->cleanBindings($bindings);


        return $this->connection->insert($cypher, $bindings);
    }

    /**
     * Create a new node with related nodes with one database hit.
     *
     * @param  array  $model
     * @param  array  $related
     * @return \Sgpatil\Orientdb\Eloquent\Model
     */
    public function createWith(array $model, array $related) {
        $cypher = $this->grammar->compileCreateWith($this, compact('model', 'related'));

        // Indicate that we need the result returned as is.
        $result = true;
        return $this->connection->statement($cypher, [], $result);
    }

    /**
     * Execute the query as a fresh "select" statement.
     *
     * @param  array  $columns
     * @return array|static[]
     */
    public function getFresh($columns = array('*')) {
        if (is_null($this->columns))
            $this->columns = $columns;

        return $this->runSelect();
    }

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return array
     */
    protected function runSelect() {
        return $this->connection->select($this->toCypher(), $this->getBindings());
    }

    /**
     * Get the Cypher representation of the traversal.
     *
     * @return string
     */
    public function toCypher() {
        return $this->grammar->compileSelect($this);
    }

    /**
     * Add a relationship MATCH clause to the query.
     *
     * @param  \Sgpatil\Orientdb\Eloquent\Model $parent       The parent model of the relationship
     * @param  \Sgpatil\Orientdb\Eloquent\Model $related      The related model
     * @param  string $relatedNode  The related node' placeholder
     * @param  string $relationship The relationship title
     * @param  string $property     The parent's property we are matching against
     * @param  string $value
     * @param  string $direction Possible values are in, out and in-out
     * @return \Sgpatil\Orientdb\Query\Builder|static
     */
    public function matchRelation($parent, $related, $relatedNode, $relationship, $property, $value = null, $direction = 'out') {
        $parentLabels = $parent->getTable();
        $relatedLabels = $related->getTable();
        $parentNode = $this->modelAsNode([$parentLabels]);

        $this->matches[] = array(
            'type' => 'Relation',
            'property' => $property,
            'direction' => $direction,
            'relationship' => $relationship,
            'parent' => array(
                'node' => $parentNode,
                'labels' => $parentLabels
            ),
            'related' => array(
                'node' => $relatedNode,
                'labels' => $relatedLabels
            )
        );

        $this->addBinding(array($this->wrap($property) => $value), 'matches');

        return $this;
    }

    public function matchMorphRelation($parent, $relatedNode, $property, $value = null, $direction = 'out') {
        $parentLabels = $parent->getTable();
        $parentNode = $this->modelAsNode($parentLabels);

        $this->matches[] = array(
            'type' => 'MorphTo',
            'property' => $property,
            'direction' => $direction,
            'related' => array('node' => $relatedNode),
            'parent' => array(
                'node' => $parentNode,
                'labels' => $parentLabels
            )
        );

        $this->addBinding(array($property => $value), 'matches');

        return $this;
    }

    /**
     * the percentile of a given value over a group,
     * with a percentile from 0.0 to 1.0.
     * It uses a rounding method, returning the nearest value to the percentile.
     *
     * @param  string $column
     * @return mixed
     */
    public function percentileDisc($column, $percentile = 0.0) {
        return $this->aggregate(__FUNCTION__, array($column), $percentile);
    }

    /**
     * Retrieve the percentile of a given value over a group,
     * with a percentile from 0.0 to 1.0. It uses a linear interpolation method,
     * calculating a weighted average between two values,
     * if the desired percentile lies between them.
     *
     * @param  string $column
     * @return mixed
     */
    public function percentileCont($column, $percentile = 0.0) {
        return $this->aggregate(__FUNCTION__, array($column), $percentile);
    }

    /**
     * Retrieve the standard deviation for a given column.
     *
     * @param  string $column
     * @return mixed
     */
    public function stdev($column) {
        return $this->aggregate(__FUNCTION__, array($column));
    }

    /**
     * Retrieve the standard deviation of an entire group for a given column.
     *
     * @param  string $column
     * @return mixed
     */
    public function stdevp($column) {
        return $this->aggregate(__FUNCTION__, array($column));
    }

    /**
     * Get the collected values of the give column.
     *
     * @param  string $column
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function collect($column) {
        $row = $this->aggregate(__FUNCTION__, array($column));

        $collected = [];

        foreach ($row as $value) {
            $collected[] = $value;
        }

        return new Collection($collected);
    }

    /**
     * Get the count of the disctinct values of a given column.
     *
     * @param  string $column
     * @return int
     */
    public function countDistinct($column) {
        return (int) $this->aggregate(__FUNCTION__, array($column));
    }

    /**
     * Execute an aggregate function on the database.
     *
     * @param  string  $function
     * @param  array   $columns
     * @return mixed
     */
    public function aggregate($function, $columns = array('*'), $percentile = null) {
        $this->aggregate = array_merge([
            'label' => $this->from
                ], compact('function', 'columns', 'percentile'));

        $previousColumns = $this->columns;

        $results = $this->get($columns);


        // Once we have executed the query, we will reset the aggregate property so
        // that more select queries can be executed against the database without
        // the aggregate value getting in the way when the grammar builds it.
        $this->aggregate = null;

        $this->columns = $previousColumns;

        if ($results->valid()) {
            $data = $results->getData();
            if (isset($data)) {
                $result = array_change_key_case((array) $data[0]);

                return $result['aggregate'];
            }

            return $results->current()[0];
        }
    }

    /**
     * Add a binding to the query.
     *
     * @param  mixed   $value
     * @param  string  $type
     * @return \Illuminate\Database\Query\Builder
     */
    public function addBinding($value, $type = 'where') {
        if (is_array($value)) {
            $key = array_keys($value)[0];

            if (strpos($key, '.') !== false) {
                $binding = $value[$key];
                unset($value[$key]);
                $key = explode('.', $key)[1];
                $value[$key] = $binding;
            }
        }

        if (!array_key_exists($type, $this->bindings)) {
            throw new \InvalidArgumentException("Invalid binding type: {$type}.");
        }

        if (is_array($value)) {
            $this->bindings[$type] = array_merge($this->bindings[$type], $value);
        } else {
            $this->bindings[$type][] = $value;
        }

        return $this;
    }

    /**
     * Convert a string into a Orientdb Label.
     *
     * @param   string  $label
     * @return \Orientdb\Label
     */
    public function makeLabel($label) {
        return $this->client->makeLabel($label);
    }

    /**
     * Tranfrom a model's name into a placeholder
     * for plucked properties. i.e.:
     *
     * MATCH (user:`User`)... "user" is what this method returns
     * out of User (and other labels).
     * PS: It consideres the first value in $labels
     *
     * @param  array $labels
     * @return string
     */
    public function modelAsNode(array $labels = null) {
        $labels = (!is_null($labels)) ? $labels : $this->from;

        return $this->grammar->modelAsNode($labels);
    }

    /**
     * Merge an array of where clauses and bindings.
     *
     * @param  array  $wheres
     * @param  array  $bindings
     * @return void
     */
    public function mergeWheres($wheres, $bindings) {
        $this->wheres = array_merge((array) $this->wheres, (array) $wheres);

        $this->bindings['where'] = array_merge_recursive($this->bindings['where'], (array) $bindings);
    }

    public function wrap($property) {
        return $this->grammar->getIdReplacement($property);
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function newQuery() {
        return new Builder($this->connection, $this->grammar);
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param  array   $values
     * @param  string  $sequence
     * @return int
     */
    public function createClass($className) {

        // create a neo4j Node
        $node = $this->client->makeClass($className);
        // save the node
        $node->save();
    }

    /**
     * Get an array with the values of a given column.
     *
     * @param  string  $column
     * @param  string  $key
     * @return array
     */
    public function lists($column, $key = null) {


        $columns = $this->getListSelect($column, $key);


        // First we will just get all of the column values for the record result set
        // then we can associate those values with the column if it was specified
        // otherwise we can just give these values back without a specific key.
        $res = $this->get($columns);

        $results = new Collection($res->getData());


        $values = $results->pluck($columns[0])->all();

        // If a key was specified and we have results, we will go ahead and combine
        // the values with the keys of all of the records so that the values can
        // be accessed by the key of the rows instead of simply being numeric.
        if (!is_null($key) && count($results) > 0) {
            $keys = $results->pluck($key)->all();

            return array_combine($keys, $values);
        }

        return $values;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array  $columns
     * @return array|static[]
     */
    public function get($columns = array('*')) {
        //      // No cache is this version
        //if ( ! is_null($this->cacheMinutes)) return $this->getCached($columns);

        return $this->getFresh($columns);
    }

    /**
     * Alias to set the "limit" value of the query.
     *
     * @param  int  $value
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function take($value) {
        return $this->limit($value);
    }

    /**
     * Insert a new record into the database.
     *
     * @param  array  $values
     * @return bool
     */
    public function insertRelationship($parent, $related, $relationship, $bindings = []) {
        $cypher = $this->grammar->compileEdge($this, $parent, $related, $relationship, $bindings);
        return $this->connection->insert($cypher, $bindings);
    }

    /**
     * Get the columns that should be used in a list array.
     *
     * @param  string  $column
     * @param  string  $key
     * @return array
     */
    protected function getListSelect($column, $key) {
        $select = is_null($key) ? [$column] : [$column, $key];

        // If the selected column contains a "dot", we will remove it so that the list
        // operation can run normally. Specifying the table is not needed, since we
        // really want the names of the columns as it is in this resulting array.
        return array_map(function ($column) {
            $dot = strpos($column, '.');

            return $dot === false ? $column : substr($column, $dot + 1);
        }, $select);
    }
}
