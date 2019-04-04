<?php

namespace Baum;

use Closure;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Class SetMapper
 * @package Baum
 */
class SetMapper
{
    /**
     * Node instance for reference.
     *
     * @var Node|null
     */
    protected $node = null;

    /**
     * Children key name.
     *
     * @var string
     */
    protected $childrenKeyName = 'children';

    /**
     * Create a new \Baum\SetBuilder class instance.
     *
     * @param Node $node
     * @param string $childrenKeyName
     */
    public function __construct(Node $node, $childrenKeyName = 'children')
    {
        $this->node = $node;

        $this->childrenKeyName = $childrenKeyName;
    }

    /**
     * Maps a tree structure into the database. Unguards & wraps in transaction.
     *
     * @param array|Arrayable $nodeList
     * @return bool
     */
    public function map($nodeList)
    {
        $self = $this;

        return $this->wrapInTransaction(function() use ($self, $nodeList) {
            forward_static_call([get_class($self->node), 'unguard']);
            $result = $self->mapTree($nodeList);
            forward_static_call([get_class($self->node), 'reguard']);

            return $result;
        });
    }
	
	/**
    * Update a tree structure in the database. Unguards & wraps in transaction.
    *
    * @param   array|\Illuminate\Support\Contracts\ArrayableInterface
    * @return  boolean
    */
   public function updateMap($nodeList) {
     $self = $this;
 
     return $this->wrapInTransaction(function() use ($self, $nodeList) {
       forward_static_call(array(get_class($self->node), 'unguard'));
       $result = true;
       try {
         $flattenTree = $this->flattenNestable($nodeList);
         foreach ($flattenTree as $branch) {
           $self->node->flushEventListeners();
           $model = $self->node->find($branch['id']);
           $model->fill($branch);
           $model->save();
         }
       } catch (\Exception $e) {
           $result = false;
       }
       forward_static_call(array(get_class($self->node), 'reguard'));
       return $result;
     });
   }
 
   /**
    * Flattens an array to contain 'id', 'lft', 'rgt', 'depth', 'parent_id' as a valid tree
    * @param $nestableArray
    * @param null $parent_id
    * @param int $depth
    * @return array
    */
   private $bound = 0;
   public function flattenNestable($nestableArray, $parent_id = null, $depth = 0)
   {
     $return = array();
     foreach ($nestableArray as $subArray) {
       $returnSubSubArray = array();
       $lft = $this->bound;
       if (isset($subArray['children'])) {
         $returnSubSubArray = $this->flattenNestable($subArray['children'], $subArray['id'], ($depth + 1));
         $rgt = $this->bound + 1;
         $this->bound;
       } else {
         $rgt = $this->bound;
       }
       $return[] = array('id' => $subArray['id'], 'parent_id' => $parent_id, 'depth' => $depth, 'lft' => $lft, 'rgt' => $rgt);
       $return = array_merge($return, $returnSubSubArray);
     }
     return $return;
   }
 
    /**
     * Maps a tree structure into the database without unguarding nor wrapping
     * inside a transaction.
     *
     * @param array|Arrayable $nodeList
     *
     * @return bool
     */
    public function mapTree($nodeList)
    {
        $tree = $nodeList instanceof Arrayable ? $nodeList->toArray() : $nodeList;

        $affectedKeys = [];

        $result = $this->mapTreeRecursive($tree, $this->node->getKey(), $affectedKeys);

        if ($result && count($affectedKeys) > 0) {
            $this->deleteUnaffected($affectedKeys);
        }

        return $result;
    }

    /**
     * Returns the children key name to use on the mapping array.
     *
     * @return string
     */
    public function getChildrenKeyName()
    {
        return $this->childrenKeyName;
    }

    /**
     * Maps a tree structure into the database.
     *
     * @param array $tree
     * @param int|string|null $parentKey
     * @param array $affectedKeys
     * @return bool
     */
    protected function mapTreeRecursive(array $tree, $parentKey = null, array &$affectedKeys = [], $root = true)
    {
        // For every attribute entry: We'll need to instantiate a new node either
        // from the database (if the primary key was supplied) or a new instance. Then,
        // append all the remaining data attributes (including the `parent_id` if
        // present) and save it. Finally, tail-recurse performing the same
        // operations for any child node present. Setting the `parent_id` property at
        // each level will take care of the nesting work for us.
		$sibling = null;
        foreach ($tree as $attributes) {
            $node = $this->firstOrNew($this->getSearchAttributes($attributes));

            $data = $this->getDataAttributes($attributes);
            if (null !== $parentKey) {
                $data[$node->getParentColumnName()] = $parentKey;
            }

            $node->fill($data);

            $result = $node->save();

            if (!$result) {
                return false;
            }

            if($root) {
				if($sibling) {
					$node->moveToRightOf($sibling);
				}

				$sibling = $node;
			} else {
                $node->makeLastChildOf($node->parent);
            }

            $affectedKeys[] = $node->getKey();

            if (array_key_exists($this->getChildrenKeyName(), $attributes)) {
                $children = $attributes[$this->getChildrenKeyName()];

                if (count($children) > 0) {
                    $result = $this->mapTreeRecursive($children, $node->getKey(), $affectedKeys, false);

                    if (!$result) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param array|string $attributes
     * @return array
     */
    protected function getSearchAttributes($attributes)
    {
        $searchable = [$this->node->getKeyName()];

        return array_only($attributes, $searchable);
    }

    /**
     * @param array|string $attributes
     * @return array
     */
    protected function getDataAttributes($attributes)
    {
        $exceptions = [$this->node->getKeyName(), $this->getChildrenKeyName()];

        return array_except($attributes, $exceptions);
    }

    /**
     * @param mixed $attributes
     * @return mixed
     */
    protected function firstOrNew($attributes)
    {
        $className = get_class($this->node);

        if (count($attributes) === 0) {
            return new $className();
        }

        return forward_static_call([$className, 'firstOrNew'], $attributes);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function pruneScope()
    {
        if ($this->node->exists) {
            return $this->node->descendants();
        }

        return $this->node->newNestedSetQuery();
    }

    /**
     * @param array $keys
     * @return bool
     */
    protected function deleteUnaffected($keys = [])
    {
        return $this->pruneScope()->whereNotIn($this->node->getKeyName(), $keys)->delete();
    }

    /**
     * @param Closure $callback
     * @return mixed
     */
    protected function wrapInTransaction(Closure $callback)
    {
        return $this->node->getConnection()->transaction($callback);
    }
}
