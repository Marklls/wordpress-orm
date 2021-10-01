<?php

namespace Symlink\ORM\Repositories;

use Symlink\ORM\Mapping;
use Symlink\ORM\QueryBuilder;

class BaseRepository {

  private $classname;

  /**
   * BaseRepository constructor.
   *
   * @param $classname
   */
  public function __construct($classname) {
    $this->classname = $classname;
  }

  /**
   * @param $classname
   *
   * @return \Symlink\ORM\Repositories\BaseRepository
   */
  public static function getInstance($classname) {
    // Get the class (as this could be a child of BaseRepository)
    $this_repository_class = get_called_class();

    // Return a new instance of the class.
    return new $this_repository_class($classname);
  }

  /**
   * @return \Symlink\ORM\QueryBuilder
   */
  public function createQueryBuilder() {
    return new QueryBuilder($this);
  }

  /**
   * Getter used in the query builder.
   * @return mixed
   */
  public function getObjectClass() {
    return $this->classname;
  }

  /**
   * Getter used in the query builder
   * @return mixed
   */
  public function getDBTable($classname = null) {
    return Mapping::getMapper()->getProcessed($classname ?: $this->classname)['ORM_Table'];
  }

  /**
   * Getter used in the query builder.
   * @return array
   */
  public function getObjectProperties($classname = null) {
    return array_merge(
      ['ID'],
      array_keys(Mapping::getMapper()->getProcessed($classname ?: $this->classname)['schema'])
    );
  }

  /**
   * Getter used in the query builder.
   * @return array
   */
  public function getObjectPropertyPlaceholders($classname = null) {
    return array_merge(
      ['ID' => '%d'],
      Mapping::getMapper()->getProcessed($classname ?: $this->classname)['placeholder']
    );
  }

  /**
   * Find a single object by ID.
   *
   * @param $id
   *
   * @return array|bool|mixed
   */
  public function find($id) {
    return $this->createQueryBuilder()
      ->where('ID', $id, '=')
      ->orderBy('ID', 'ASC')
      ->buildQuery()
      ->getResults();
  }

    /**
     * Return all objects of this type.
     *
     * @return array
     */
  public function findAll() {
    return $this->createQueryBuilder()
      ->orderBy('ID', 'ASC')
      ->buildQuery()
      ->getResults(true);
  }

  /**
   * Returns all objects with matching property value.
   *
   * @param array $criteria
   *
   * @return array
   */
  public function findBy(array $criteria) {
    $qb = $this->createQueryBuilder();
    foreach ($criteria as $property => $value) {
      $qb->where($property, $value, '=');
    }
    return $qb->orderBy('ID', 'ASC')
      ->buildQuery()
      ->getResults(true);
  }

}
