<?php

namespace Symlink\ORM;

use Symlink\ORM\Repositories\BaseRepository;

class QueryBuilder {

  private $join;
  private $joinClassname;

  private $where;

  private $order_by;

  private $limit;

  /**
   * The prepared query run through $wpdb->prepare().
   * @var
   */
  private $query;

  /**
   * Reference to the repository.
   * @var \Symlink\ORM\Repositories\BaseRepository
   */
  private $repository;

  /**
   * QueryBuilder constructor.
   */
  public function __construct(BaseRepository $repository) {
    // Set some default values.
    $this->where = [];
    $this->order_by;
    $this->limit;

    $this->join;
    $this->joinClassname = [];

    // And store the sent repository.
    $this->repository = $repository;
  }

    /**
     * @param $classname
     * @param $property
     * @throws Exceptions\PropertyDoesNotExistException
     * @throws Exceptions\RepositoryClassNotDefinedException
     * @throws Exceptions\RequiredAnnotationMissingException
     * @throws Exceptions\UnknownColumnTypeException
     */
  public function join($classname, $property, $alias) {
      global $wpdb;

      // Get the annotations for this class.
      $annotations = Mapping::getMapper()->getProcessed($classname);

      // Get the repository for this class (this has already been validated in the Mapper).
      if (!isset($annotations['ORM_Table']) || !isset($annotations['ORM_Type']) || $annotations['ORM_Type'] !== 'Entity') {
          return $this;
      }

      // Check if alias already use
      if (array_key_exists($alias, $this->joinClassname) || $alias === $this->repository->getDBTable()) {
          throw new \Symlink\ORM\Exceptions\AliasExistException(sprintf(__('Alias %s already exist on query for model %s.'), $alias, $this->repository->getObjectClass()));
      }

      // Check the property exists.
      $this->checkPropertyExist($property);

      $this->joinClassname[$alias] = $classname;

      $this->join .= "JOIN " . $wpdb->prefix . $annotations['ORM_Table'] . " " . $alias .
          " ON " . $alias . ".ID = " . (substr_count($property, '.') === 1 ? '' : $this->repository->getDBTable()) . "." . $property . " ";

      return $this;
  }

  /**
   * Add a WHERE clause to the query.
   *
   * @param $property
   * @param $value
   * @param $operator
   *
   * @return $this
   * @throws \Symlink\ORM\Exceptions\InvalidOperatorException
   * @throws \Symlink\ORM\Exceptions\PropertyDoesNotExistException
   */
  public function where($property, $value, $operator) {
      // Check the property exists.
      $this->checkPropertyExist($property);

    // Check the operator is valid.
    if (!in_array($operator, [
      '<',
      '<=',
      '=',
      '!=',
      '>',
      '>=',
      'IN',
      'NOT IN'
    ])
    ) {
      throw new \Symlink\ORM\Exceptions\InvalidOperatorException(sprintf(__('Operator %s is not valid.'), $operator));
    }

    // Add the entry.
    $this->where[] = [
      'property' => $property,
      'operator' => $operator,
      'value' => $value,
      'placeholder' => $this->repository->getObjectPropertyPlaceholders()[$property]
    ];

    return $this;
  }

  /**
   * Set the ORDER BY clause.
   *
   * @param $property
   * @param $operator
   *
   * @return $this
   * @throws \Symlink\ORM\Exceptions\InvalidOperatorException
   * @throws \Symlink\ORM\Exceptions\PropertyDoesNotExistException
   */
  public function orderBy($property, $operator) {
    // Check the property exists.
    $this->checkPropertyExist($property);

    // Check the operator is valid.
    if (!in_array($operator, [
      'ASC',
      'DESC',
    ])
    ) {
      throw new \Symlink\ORM\Exceptions\InvalidOperatorException(sprintf(__('Operator %s is not valid.'), $operator));
    }

    // Save it
    if (!$this->order_by)
        $this->order_by = "ORDER BY " . $property . " " . $operator . " ";
    else
        $this->order_by .= ", " . $property . " " . $operator . " ";

    return $this;
  }

  /**
   * Set the limit clause.
   *
   * @param $count
   * @param int $offset
   *
   * @return $this
   */
  public function limit($count, $offset = 0) {
    // Ignore if not valid.
    if (is_numeric($offset) && is_numeric($count) && $offset >= 0 && $count > 0) {
      $this->limit = "LIMIT " . $count . " OFFSET " . $offset . "
      ";
    }

    return $this;
  }

  /**
   * Build the query and process through $wpdb->prepare().
   * @return $this
   */
  public function buildQuery() {
    global $wpdb;

    $values = [];

    $sql = "SELECT " . $this->repository->getDBTable() . ".* FROM " . $wpdb->prefix . $this->repository->getDBTable() . " " . $this->repository->getDBTable() . "
    ";

      // Add the ORDER BY clause.
      if ($this->join) {
          $sql .= $this->join;
      }

    // Combine the WHERE clauses and add to the SQL statement.
    if (count($this->where)) {
      $sql .= "WHERE
      ";

      $combined_where = [];
      foreach ($this->where as $where) {

        // Operators is not "IN" or "NOT IN"
        if ($where['operator'] != 'IN' && $where['operator'] != 'NOT IN') {
          $combined_where[] = $where['property'] . " " . $where['operator'] . " " . $where['placeholder'] . "
          ";
          $values[] = $where['value'];
        }
        // Operator is "IN" or "NOT IN"
        else {
          // Fail silently.
          if (is_array($where['value'])) {
            $combined_where[] = $where['property'] . " " . $where['operator'] . " (" . implode(", ", array_pad([], count($where['value']), $where['placeholder'])) . ")
          ";

            $values = array_merge($values, $where['value']);
          }
        }
      }

      $sql .= implode(' AND ', $combined_where);  // @todo - should allow more than AND in future.
    }

    // Add the ORDER BY clause.
    if ($this->order_by) {
      $sql .= $this->order_by;
    }

    // Add the LIMIT clause.
    if ($this->limit) {
      $sql .= $this->limit;
    }

    // Save it.
    if (!empty($values))
      $this->query = $wpdb->prepare($sql, $values);
    else
      $this->query = $sql;
    return $this;
  }

  /**
   * Run the query returning either a single object or an array of objects.
   *
   * @param bool $always_array
   *
   * @return array|bool|mixed
   * @throws \Symlink\ORM\Exceptions\NoQueryException
   */
  public function getResults($always_array = FALSE) {
    global $wpdb;

    if ($this->query) {

      // Classname for this repository.
      $object_classname = $this->repository->getObjectClass();

      // Loop through the database results, building the objects.
      $objects = array_map(function ($result) use(&$object_classname) {

        // Create a new blank object.
        $object = new $object_classname();

        // Fill in all the properties.
        array_walk($result, function ($value, $property) use (&$object) {
          $object->set($property, $value);
        });

        // Track the object.
        $em = Manager::getManager();
        $em->track($object);

        // Save it.
        return $object;
      }, $wpdb->get_results($this->query));

      // There were no results.
      if (!count($objects)) {
        return FALSE;
      }

      // Return just an object if there was only one result.
      if (count($objects) == 1 && !$always_array) {
        return $objects[0];
      }

      // Otherwise, the return an array of objects.
      return $objects;

    }
    else {
      throw new \Symlink\ORM\Exceptions\NoQueryException(__('No query was built. Run ->buildQuery() first.'));
    }
  }

    /**
     * @param $property
     * @throws Exceptions\AliasNotExistException
     * @throws Exceptions\PropertyDoesNotExistException
     */
  private function checkPropertyExist($property) {
      if (substr_count($property, '.') === 1) {
          list($alias, $property) = explode('.', $property, 2);

          // Check if alias exist
          if (!array_key_exists($alias, $this->joinClassname)) {
              throw new \Symlink\ORM\Exceptions\AliasNotExistException(sprintf(__('Alias %s does not exist in query for model %s.'), $alias, $this->repository->getObjectClass()));
          }

          // Check the property exists.
          if (!in_array($property, $this->repository->getObjectProperties($this->joinClassname[$alias])) && $property != 'ID') {
              throw new \Symlink\ORM\Exceptions\PropertyDoesNotExistException(sprintf(__('Property %s does not exist in model %s.'), $property, $this->joinClassname[$alias]));
          }
      } else {
          // Check the property exists.
          if (!in_array($property, $this->repository->getObjectProperties()) && $property != 'ID') {
              throw new \Symlink\ORM\Exceptions\PropertyDoesNotExistException(sprintf(__('Property %s does not exist in model %s.'), $property, $this->repository->getObjectClass()));
          }
      }
  }
}
