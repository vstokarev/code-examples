<?php

namespace App\Models;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FilterBuilder
{
    /**
     * @var string|null
     */
    private $pivot;

    /**
     * @var array
     */
    private $vertex = ['region', 'system', 'spa', 'headend', 'zip', 'city', 'node'];

    /**
     * @var array
     */
    private $edges = [
        [1 => 1],
        [3 => 1, 0 => 2],
        [3 => 1, 4 => 2, 5 => 2, 6 => 2],
        [1 => 1, 2 => 2, 4 => 2, 5 => 2, 6 => 2],
        [2 => 2, 3 => 1, 5 => 2, 6 => 2],
        [2 => 2, 3 => 1, 4 => 2, 6 => 2],
        [2 => 2, 3 => 1, 4 => 2, 5 => 2]
    ];

    /** @var Builder */
    private $builder;

    public function build(string $targetEntity, ?string $filterEntity, ?string $filterValue): ?Collection
    {
        // Get the shortest path between entities
        $entitiesPath = $this->findShortestPath($targetEntity, $filterEntity);
        if (sizeof($entitiesPath) == 0) {
            return null;
        }

        // Get target entity table name and init query builder
        $targetModelClass = 'App\Models\\' . ucfirst($targetEntity);
        $targetModelTableName = (new $targetModelClass)->getTable();

        /** @var Builder $builder */
        $this->builder = DB::table($targetModelTableName);

        $this->builder->orderBy($targetModelTableName . '.name');

        // If no filter has been specified, just return the whole list of requested type of entities
        if ($filterEntity == '') {
            return $this->builder->get([$targetModelTableName.'.*']);
        }

        $this->joinNext($entitiesPath, true);

        $filterModelClass = 'App\Models\\' . ucfirst($filterEntity);
        $filterTableName = (new $filterModelClass)->getTable();

        $this->builder->where($filterTableName.'.name', '=', $filterValue);
        $this->builder->groupBy($targetModelTableName . '.id');

        return $this->builder->get([$targetModelTableName.'.*']);
    }

    /**
     * @param string $source Source entity
     * @param string $target Target entity
     * @return array
     */
    protected function findShortestPath(string $source, ?string $target): array
    {
        if ($target == null) {
            return [$source];
        }

        $sequence = [];

        $sourcePos = array_search($source, $this->vertex);
        $targetPos = array_search($target, $this->vertex);

        if ($sourcePos === false || $targetPos === false) {
            return [];
        }

        $curPos = $sourcePos;
        $prev = null;
        while ($prev != $target) {
            $prev = $this->vertex[$curPos];
            $sequence[] = $prev;

            $vertexEdges = $this->edges[$curPos];

            $minWeight = 1000;
            foreach ($vertexEdges as $vertexId => $nodeWeight) {
                if (in_array($this->vertex[$vertexId], $sequence)) {
                    continue;
                }

                if ($this->vertex[$vertexId] == $target) {
                    break 2;
                }

                if ($nodeWeight < $minWeight) {
                    $minWeight = $nodeWeight;
                    $curPos = $vertexId;
                }
            }
        }

        $sequence[] = $target;

        return $sequence;
    }

    private function joinNext(array $chain, bool $firstCall = false): void
    {
        if (sizeof($chain) == 1) {
            return;
        }

        $entities = [array_shift($chain)];
        $entities[] = $chain[0];
        sort($entities);

        $method = 'join' . ucfirst($entities[0]) . ucfirst($entities[1]) . 'Pivot';
        call_user_func_array([$this, $method], [$chain]);
    }

    private function joinHeadend(array $chain): void
    {
        if ($this->pivot) {
            $this->builder->join('headends', 'headends.id', '=', $this->pivot . '.headend_id');
            $this->pivot = null;
            $this->joinNext($chain);
        }
    }

    private function joinZip(array $chain): void
    {
        if ($this->pivot) {
            $this->builder->join('zips', 'zips.id', '=', $this->pivot . '.zip_id');
            $this->pivot = null;
            $this->joinNext($chain);
        }
    }

    private function joinCity(array $chain): void
    {
        if ($this->pivot) {
            $this->builder->join('cities', 'cities.id', '=', $this->pivot . '.city_id');
            $this->pivot = null;
            $this->joinNext($chain);
        }
    }

    private function joinNode(array $chain): void
    {
        if ($this->pivot) {
            $this->builder->join('nodes', 'nodes.id', '=', $this->pivot . '.node_id');
            $this->pivot = null;
            $this->joinNext($chain);
        }
    }

    private function joinSpa(array $chain): void
    {
        if ($this->pivot) {
            $this->builder->join('spas', 'spas.id', '=', $this->pivot . '.spa_id');
            $this->pivot = null;
            $this->joinNext($chain);
        }
    }

    private function joinSystem(array $chain): void
    {
        if ($this->pivot) {
            $this->builder->join('systems', 'systems.id', '=', $this->pivot . '.system_id');
            $this->pivot = null;
            $this->joinNext($chain);
        }
    }

    private function joinRegion(array $chain): void
    {
        $this->builder->join('regions', 'regions.id', '=', 'systems.region_id');
        $this->joinNext($chain);
    }

    private function joinHeadendZipPivot(array $chain, ?string $from=null): void
    {
        $this->pivot = 'headend_zip';
        if ($chain[0] == 'headend') {
            $this->builder->join('headend_zip', 'headend_zip.zip_id', '=', 'zips.id');
            $this->joinHeadend($chain);
        } else {
            $this->builder->join('headend_zip', 'headend_zip.headend_id', '=', 'headends.id');
            $this->joinZip($chain);
        }
    }

    private function joinCityHeadendPivot(array $chain, ?string $from=null): void
    {
        $this->pivot = 'headend_city';
        if ($chain[0] == 'headend') {
            $this->builder->join('headend_city', 'cities.id', '=', 'headend_city.city_id');
            $this->joinHeadend($chain);
        } else {
            $this->builder->join('headend_city', 'headends.id', '=', 'headend_city.headend_id');
            $this->joinCity($chain);
        }
    }

    private function joinCityNodePivot(array $chain, ?string $from=null): void
    {
        $this->pivot = 'city_node';
        if ($chain[0] == 'city') {
            $this->builder->join('city_node', 'nodes.id', '=', 'city_node.node_id');
            $this->joinCity($chain);
        } else {
            $this->builder->join('city_node', 'cities.id', '=', 'city_node.city_id');
            $this->joinNode($chain);
        }
    }

    private function joinCitySpaPivot(array $chain, ?string $from=null): void
    {
        $this->pivot = 'city_spa';
        if ($chain[0] == 'city') {
            $this->builder->join('city_spa', 'spas.id', '=', 'city_spa.spa_id');
            $this->joinCity($chain);
        } else {
            $this->builder->join('city_spa', 'cities.id', '=', 'city_spa.city_id');
            $this->joinSpa($chain);
        }
    }

    private function joinCityZipPivot(array $chain, ?string $from=null): void
    {
        $this->pivot = 'city_zip';
        if ($chain[0] == 'city') {
            $this->builder->join('city_zip', 'zips.id', '=', 'city_zip.zip_id');
            $this->joinCity($chain);
        } else {
            $this->builder->join('city_zip', 'cities.id', '=', 'city_zip.city_id');
            $this->joinZip($chain);
        }
    }

    private function joinHeadendNodePivot(array $chain, ?string $from=null): void
    {
        $this->pivot = 'headend_node';
        if ($chain[0] == 'headend') {
            $this->builder->join('headend_node', 'nodes.id', '=', 'headend_node.node_id');
            $this->joinHeadend($chain);
        } else {
            $this->builder->join('headend_node', 'headends.id', '=', 'headend_node.headend_id');
            $this->joinNode($chain);
        }
    }

    private function joinHeadendSpaPivot(array $chain, ?string $from=null): void
    {
        $this->pivot = 'headend_spa';
        if ($chain[0] == 'headend') {
            $this->builder->join('headend_spa', 'spas.id', '=', 'headend_spa.spa_id');
            $this->joinHeadend($chain);
        } else {
            $this->builder->join('headend_spa', 'headends.id', '=', 'headend_spa.headend_id');
            $this->joinSpa($chain);
        }
    }

    private function joinHeadendSystemPivot(array $chain, ?string $from=null): void
    {
        $this->pivot = 'headend_system';
        if ($chain[0] == 'headend') {
            $this->builder->join('headend_system', 'systems.id', '=', 'headend_system.system_id');
            $this->joinHeadend($chain);
        } else {
            $this->builder->join('headend_system', 'headends.id', '=', 'headend_system.headend_id');
            $this->joinSystem($chain);
        }
    }

    private function joinNodeSpaPivot(array $chain, ?string $from=null): void
    {
        $this->pivot = 'node_spa';
        if ($chain[0] == 'node') {
            $this->builder->join('node_spa', 'spas.id', '=', 'node_spa.spa_id');
            $this->joinNode($chain);
        } else {
            $this->builder->join('node_spa', 'nodes.id', '=', 'node_spa.node_id');
            $this->joinSpa($chain);
        }
    }

    private function joinNodeZipPivot(array $chain, ?string $from=null): void
    {
        $this->pivot = 'node_zip';
        if ($chain[0] == 'node') {
            $this->builder->join('node_zip', 'zips.id', '=', 'node_zip.zip_id');
            $this->joinNode($chain);
        } else {
            $this->builder->join('node_zip', 'nodes.id', '=', 'node_zip.node_id');
            $this->joinZip($chain);
        }
    }

    private function joinRegionSystemPivot(array $chain, ?string $from=null): void
    {
        if ($chain[0] == 'system') {
            $this->builder->join('systems', 'systems.region_id', '=', 'regions.id');
        } else {
            $this->builder->join('regions', 'regions.id', '=', 'systems.region_id');
        }

        $this->joinNext($chain);
    }

    private function joinSpaZipPivot(array $chain, ?string $from=null): void
    {
        $this->pivot = 'spa_zip';
        if ($chain[0] == 'zip') {
            $this->builder->join('spa_zip', 'spas.id', '=', 'spa_zip.spa_id');
            $this->joinZip($chain);
        } else {
            $this->builder->join('spa_zip', 'zips.id', '=', 'spa_zip.zip_id');
            $this->joinSpa($chain);
        }
    }
}