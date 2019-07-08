<?php

namespace app\graphql\types;

use app\components\helpers\GraphQLHelper;
use app\graphql\BaseObjectType;
use app\models\AuthUser;
use yii\db\ActiveQuery;

class FilmCommentConnectionType extends BaseObjectType
{
	/**
	 * @var mixed
	 */
	private $dataBuffer = [];

	public function resolveEdges(array $connectionData)
	{
		$this->initPaginationAndData($connectionData);
		return $this->getBufferedData($connectionData['query']);
	}

	public function resolveTotalCount(array $connectionData)
	{
		return $connectionData['query']->count();
	}

	public function resolvePageInfo(array $connectionData)
	{
		$this->initPaginationAndData($connectionData);
		$dataBuffer = $this->getBufferedData($connectionData['query']);

		$startCursor = is_array($dataBuffer) && sizeof($dataBuffer) > 0 ?
			GraphQLHelper::encodeId($dataBuffer[0]->id) :
			null;
		$endCursor = is_array($dataBuffer) && sizeof($dataBuffer) > 0 ?
			GraphQLHelper::encodeId($dataBuffer[sizeof($dataBuffer)-1]->id) :
			null;

		return [
			'hasNextPage' => $this->hasNextPage($connectionData['query'], $endCursor),
			'hasPreviousPage' => $this->hasPreviousPage($connectionData['query'], $startCursor),
			'startCursor' => $startCursor,
			'endCursor' => $endCursor
		];
	}

	private function initPaginationAndData(array $connectionData): void
	{
		$primaryModel = $connectionData['query']->primaryModel;
		if (isset($this->dataBuffer[$primaryModel->id])) {
			return;
		}

		$pageSize = $connectionData['args']['first'] ?? $connectionData['args']['last'] ?? 5;
		if (isset($connectionData['args']['last'])) {
			$inverseResult = true;
		} else {
			$connectionData['query']->orderBy('date_added DESC');
		}

		/** @var ActiveQuery $query */
		$query = clone $connectionData['query'];
		$query->limit($pageSize);

		if (isset($connectionData['args']['after'])) {
			$query->andWhere('id < :id', [':id' => GraphQLHelper::decodeId($connectionData['args']['after'])]);
		} elseif (isset($connectionData['args']['before'])) {
			$query->andWhere('id > :id', [':id' => GraphQLHelper::decodeId($connectionData['args']['before'])]);
		}

		$query->joinWith(['user' => function(ActiveQuery $query) {
			$query->select(['id', 'name', 'lastname']);
		}]);

		$this->dataBuffer[$primaryModel->id] = isset($inverseResult) ? array_reverse($query->all()) : $query->all();
	}

	private function getBufferedData(ActiveQuery $query): ?array
	{
		return $this->dataBuffer[$query->primaryModel->id] ?? null;
	}

	private function hasPreviousPage(ActiveQuery $query, string $before)
	{
		$queryClone = clone $query;
		return $queryClone->andWhere('id > :id', [':id' => GraphQLHelper::decodeId($before)])->count() > 0;
	}

	private function hasNextPage(ActiveQuery $query, string $after)
	{
		$queryClone = clone $query;
		return $queryClone->andWhere('id < :id', [':id' => GraphQLHelper::decodeId($after)])->count() > 0;
	}
}