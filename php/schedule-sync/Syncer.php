<?php

namespace app\components\sync;

use app\components\context\AppContext;
use app\components\helpers\Profiler;
use app\components\services\presenters\UCSPremieraPresenter;
use app\components\services\vendors\ucs\premiera\dataobjects\getupdates\{Query, Update};
use app\components\services\vendors\ucs\premiera\PremieraService;
use app\models\Cinema;
use app\models\db\
{PremieraQuery, PremieraUpdate, Sequence};
use Cascade\Cascade;
use yii\db\Expression;
use yii\helpers\ArrayHelper;

/**
 * Class Syncer
 * @package app\components\sync
 */
class Syncer
{
	public const LOGGER = 'syncer';
	public const JOB = 'sync';

	/**
	 * @var AppContext
	 */
	protected $appContext;

	/**
	 * @var Cinema
	 */
	protected $cinema;

	/**
	 * @var UCSPremieraPresenter
	 */
	protected $presenter;

	/**
	 * @var FilmsLinker
	 */
	protected $filmsLinker;

	public function __construct(Cinema $cinema, AppContext $appContext)
	{
		$this->cinema = $cinema;
		$this->appContext = $appContext;

		$this->init();
	}

	public function init(): void
	{
		// Init presenter
		$this->presenter = new UCSPremieraPresenter('Syncer', $this->cinema->cinemaTsConfig);

		// Enable proxy for dev environment (as we cannot send requests directly in this case)
		if (YII_DEBUG === true) {
			$this->presenter->getService()->enableProxy();
		}

		// FilmLinker is a class, that helps in finding links between movie titles from ticket system and
		// movies stored in local DB.
		$this->filmsLinker = new FilmsLinker($this->presenter);
	}

	/**
	 * Runs sync process for one selected cinema
	 */
	public function run(): void
	{
		Profiler::timeStart('syncer-' . $this->cinema->id);

		// Init sequence
		try {
			$this->appContext->startNewSequence(self::JOB, $this->cinema->id);
		} catch (\Exception $exception) {
			Cascade::logger(self::LOGGER)->critical(
				'Не удалось создать sequence: ' . $exception->getMessage(),
				['exception' => $exception]
			);
			return;
		}

		Cascade::logger(self::LOGGER)->info('Последовательность: {sequence}', [
			'sequence' => $this->appContext->getSequence()->guid
		]);

		// Iterate through pending queries first (if we have any)
		$pendingQueries = $this->cinema->getPremieraQueries(true)->all();
		$processedQueries = [];
		if (sizeof($pendingQueries) > 0) {
			Cascade::logger(self::LOGGER)->info('Отложенные запросы: {number}', [
				'number' => sizeof($pendingQueries)
			]);

			foreach ($pendingQueries as $i => $pendingQuery) {
				Cascade::logger(self::LOGGER)->info('Отложенный запрос {num} из {count}', [
					'num' => $i + 1,
					'count' => sizeof($pendingQueries)
				]);

				if ($this->processQuery($pendingQuery)) {
					$pendingQuery->delete();
					$processedQueries[] = $pendingQuery;
				}
			}
		}

		// Get list of processed updates
		// It consists of updates ids that we processed last time. This list must be sent to ticket system,
		// so that it knows that we already processed them before.
		$processedUpdates = $this->cinema->premieraUpdates;
		$updatesIds = sizeof($processedUpdates) > 0 ? ArrayHelper::getColumn($processedUpdates, 'update_id') : [];

		// Get updates list
		$premieraUpdatesResponse = $this->presenter->getUpdates(
			implode(
				';',
				[PremieraService::LIST_TYPE_SESSION, PremieraService::LIST_TYPE_HALL, PremieraService::LIST_TYPE_PLACE]
			),
			$this->cinema->cinemaTsConfig->ext_id,
			sizeof($updatesIds) > 0 ? implode(';', $updatesIds) : null
		);

		// In case of Ok response remove processed Ids from DB
		if ($premieraUpdatesResponse->isOk) {
			array_walk($processedUpdates, function ($processedUpdate) {
				$processedUpdate->delete();
			});
		} else {
			Cascade::logger(self::LOGGER)->error(
				'Не удалось получить список обновлений: {errorMessage} ({errorCode})', [
					'errorMessage' => $premieraUpdatesResponse->getErrorMessage(),
					'errorCode' => $premieraUpdatesResponse->getErrorCode()
				]
			);

			$this->appContext->finishSequence(false, Sequence::ERROR, 'Не получен список обновлений');
			return;
		}

		Cascade::logger(self::LOGGER)->info('Найдено обновлений: {updatesCount}', [
			'updatesCount' => sizeof($premieraUpdatesResponse->updates)
		]);

		// Iterate through update blocks and check updates types, calling appropriate methods to process updates
		$processedUpdates = [];
		if (sizeof($premieraUpdatesResponse->updates) > 0) {
			foreach ($premieraUpdatesResponse->updates as $i => $premieraUpdate) {
				Cascade::logger(self::LOGGER)->info('Обновление {id} ({num} из {count})', [
					'id' => $premieraUpdate->id,
					'num' => $i + 1,
					'count' => sizeof($premieraUpdatesResponse->updates)
				]);

				if ($this->processUpdate($premieraUpdate)) {
					$processedUpdates[] = $premieraUpdate;
				}
			}
		}

		$pendingQueriesCount = sizeof($pendingQueries);
		$premieraUpdatesCount = sizeof($premieraUpdatesResponse->updates);
		$failedPendingQueriesCount = sizeof($pendingQueries) - sizeof($processedQueries);
		$failedPremieraUpdatesCount = sizeof($premieraUpdatesResponse->updates) - sizeof($processedUpdates);

		// Finish sequence
		if ($pendingQueriesCount == 0 && $premieraUpdatesCount == 0) {
			$this->appContext->finishSequence(true, Sequence::OKEMPTY);
		} elseif ($failedPendingQueriesCount == 0 && $failedPremieraUpdatesCount == 0) {
			$this->appContext->finishSequence(true, Sequence::OK);
		} else {
			if ($failedPremieraUpdatesCount == $premieraUpdatesCount && $failedPendingQueriesCount == $pendingQueriesCount) {
				$this->appContext->finishSequence(false, Sequence::ERROR, 'Обновление не проведено');
			} else {
				$message = 'Обновления: ' . sizeof($processedUpdates) . ' из ' . $premieraUpdatesCount;
				if (sizeof($pendingQueries) > 0) {
					$message .= ', отложенные запросы: ' . sizeof($processedQueries) . ' из ' . $pendingQueriesCount;
				}

				$this->appContext->finishSequence(true, Sequence::OKPARTIAL, $message);
			}
		}

		Cascade::logger(self::LOGGER)->info('Использовано памяти: {memoryUsed}, время выполнения: {execTime} сек.', [
			'memoryUsed' => \Yii::$app->formatter->asShortSize(memory_get_usage(), 2),
			'execTime' => sprintf('%.2f', Profiler::timeEnd('syncer-' . $this->cinema->id))
		]);
	}

	/**
	 * Process one selected update
	 *
	 * @param Update $update
	 * @return  bool
	 */
	public function processUpdate(Update $update): bool
	{
		$queriesCount = sizeof($update->queries);

		Cascade::logger(self::LOGGER)->info('Запросов в обновлении: {queriesCount}', [
			'queriesCount' => $queriesCount
		]);

		if ($queriesCount == 0) {
			return true;
		}

		$failedQueries = [];
		foreach ($update->queries as $i => $query) {
			Cascade::logger(self::LOGGER)->info('Обработка запроса {num} из {count}', [
				'num' => $i + 1,
				'count' => sizeof($update->queries)
			]);

			if (!$this->processQuery($query)) {
				$failedQueries[] = $query;
			}
		}

		// No queries have been processed, do not save this update id, we'll have to run it again later
		if (sizeof($failedQueries) == sizeof($update->queries)) {
			Cascade::logger(self::LOGGER)->info('Обновление не обработано');
			return false;
		}

		// Save update as processed
		$this->registerProcessedUpdate($update);

		// Register failed query as pending, run them later again
		if (sizeof($failedQueries) > 0) {
			array_walk($failedQueries, [$this, 'registerPendingQuery']);
			Cascade::logger(self::LOGGER)->info('Обновление обработано частично, необработанных 
			запросов: {queriesCount}', [
				'queriesCount' => sizeof($failedQueries)
			]);

			return false;
		} else {
			Cascade::logger(self::LOGGER)->info('Обновление обработано успешно');
			return true;
		}
	}

	/**
	 * Process one query from update
	 *
	 * @param Query|PremieraQuery $query
	 * @return bool
	 */
	public function processQuery($query): bool
	{
		$paramsBag = $query->asParamsBag();

		// For GetSessions query we'll need to add MovieName in order to make sure we'll have movie names in response
		if ($query->name == PremieraService::GET_SESSIONS) {
			$paramsBag->listType = $paramsBag->listType == '' ? 'MovieName' : $paramsBag->listType . ';MovieName';
			$paramsBag->pastTime = '1';
		}

		Cascade::logger(self::LOGGER)->info('Тип запроса: {queryType}', ['queryType' => $query->name]);

		$queryResponse = $this->presenter->runPredefinedQuery($query->name, $paramsBag);
		if (!$queryResponse->isOk) {
			Cascade::logger(self::LOGGER)->error(
				'Не удалось выполнить запрос из списка обновлений: {query} (ошибка: {errorMessage})', [
					'query' => $query->query,
					'errorMessage' => $queryResponse->getErrorMessage()
				]
			);
			return false;
		}

		switch ($query->name) {
			case PremieraService::GET_SESSIONS:
				return (new Schedule($this->cinema, $this->presenter, $this->filmsLinker))
					->syncSchedule($queryResponse, $query->asParamsBag());
				break;
			case PremieraService::GET_HALLS:
				return (new Halls($this->cinema, $this->presenter))->syncHalls($queryResponse);
				break;
			case PremieraService::GET_HALL_PLAN:
				return (new Places($this->cinema, $this->presenter))->syncPlaces($queryResponse, $query->asParamsBag());
				break;
			default:
				Cascade::logger(self::LOGGER)->error(
					'Не найден обработчик для запроса типа {type}', ['type' => $query->name]
				);
				return false;
		}
	}

	/**
	 * Saves processed update id in database (we'll need it during the next sync script run)
	 *
	 * @param Update $update
	 */
	protected function registerProcessedUpdate(Update $update): void
	{
		$processedUpdate = new PremieraUpdate();

		$processedUpdate->cinema_id = $this->cinema->id;
		$processedUpdate->update_id = $update->id;
		$processedUpdate->processed_at = new Expression('NOW()');

		if (!$processedUpdate->save()) {
			Cascade::logger(self::LOGGER)->error(
				'Не удалось сохранить обработанное обновление в БД: {errorMessage}', [
					'errorMessage' => $processedUpdate->getSingleError()
				]
			);
		}
	}

	/**
	 * Saves pending query in database, we'll run it later again
	 *
	 * @param Query $query
	 */
	protected function registerPendingQuery(Query $query): void
	{
		$pendingQuery = new PremieraQuery();

		$pendingQuery->cinema_id = $this->cinema->id;

		if ($query->name == PremieraService::GET_SESSIONS) {
			$paramsBag = $query->asParamsBag();
			if ($paramsBag->dateList !== null) {
				try {
					$pendingQuery->target_date = \Yii::$app->formatter->asDate($paramsBag->dateList, 'php:Y-m-d');
				} catch (\Exception $exception) {
					$pendingQuery->target_date = null;
				}
			}
		}

		$pendingQuery->name = $query->name;
		$pendingQuery->query = $query->query;

		if (!$pendingQuery->save()) {
			Cascade::logger(self::LOGGER)->error(
				'Не удалось сохранить необработанный запрос в БД: {errorMessage}', [
					'errorMessage' => $pendingQuery->getSingleError()
				]
			);
		}
	}
}