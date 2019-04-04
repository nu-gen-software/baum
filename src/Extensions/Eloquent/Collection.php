<?php namespace Baum\Extensions\Eloquent;

use Baum\Node;
use Illuminate\Database\Eloquent\Collection as BaseCollection;

/**
 * Class Collection
 *
 * @package Baum\Extensions\Eloquent
 */
class Collection extends BaseCollection
{
    /**
     * @return BaseCollection
     */
    public function toHierarchy()
    {
        return new BaseCollection(
            $this->hierarchical(
                $this->getDictionary()
            )
        );
    }

    /**
     * @return BaseCollection
     */
    public function toSortedHierarchy()
    {
        $dict = $this->getDictionary();

        // Enforce sorting by $orderColumn setting in Baum\Node instance
        uasort($dict, function (Node $a, Node $b) {
            return ($a->getOrder() >= $b->getOrder()) ? 1 : -1;
        });

        return new BaseCollection($this->hierarchical($dict));
    }

    /**
     * @param array $result
     * @return array
     */
    protected function hierarchical(array $result)
    {
        /** @var Node $node */
        foreach ($result as $node) {
            $node->setRelation('children', new BaseCollection());
        }

        $nestedKeys = [];

        foreach ($result as $node)
        {
            $parentKey = $node->getParentId();

            if (
                null !== $parentKey &&
                array_key_exists($parentKey, $result)
            ) {
                $result[$parentKey]->children[] = $node;
                $nestedKeys[] = $node->getKey();
            }
        }

        foreach ($nestedKeys as $key) {
            unset($result[$key]);
        }

        return $result;
    }
}
