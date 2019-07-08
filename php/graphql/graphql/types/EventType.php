<?php

namespace app\graphql\types;

use app\components\helpers\Image;
use app\graphql\BaseObjectType;
use app\graphql\buffers\FilmRatingsBuffer;
use app\graphql\buffers\GenresBuffer;
use app\models\db\FilmRating;
use app\models\Film;
use GraphQL\Deferred;

class EventType extends BaseObjectType
{
	public function resolveNameRussian(Film $event)
	{
		return $event->name_rus;
	}

	public function resolveNameOriginal(Film $event)
	{
		return $event->name_orig;
	}

	public function resolveDuration(Film $event)
	{
		return [
			'totalMinutes' => $event->timeline,
			'hours' => $event->getDurationHours(),
			'minutes' => $event->getDurationMinutes()
		];
	}

	public function resolveTrailer(Film $event)
	{
		return [
			'type' => 'youtube',
			'id' => $event->trailer_id,
			'url' => 'https://www.youtube.com/watch?v=' . $event->trailer_id
		];
	}

	public function resolveVerticalPoster(Film $event, $args)
	{
		return Image::getVerticalPoster($event);
	}

	public function resolveHorizontalPoster(Film $event, $args)
	{
		return Image::getHorizontalPoster($event);
	}

	public function resolveBlurredPoster(Film $event)
	{
		return Image::getBlurredPoster($event);
	}

	public function resolveGenres(Film $event)
	{
		GenresBuffer::add($event->genre1);
		GenresBuffer::add($event->genre2);
		GenresBuffer::add($event->genre3);
		GenresBuffer::add($event->genre4);
		GenresBuffer::add($event->genre5);

		return new Deferred(function () use ($event) {
			GenresBuffer::loadBuffered();

			$genres = [
				GenresBuffer::get($event->genre1),
				GenresBuffer::get($event->genre2),
				GenresBuffer::get($event->genre3),
				GenresBuffer::get($event->genre4),
				GenresBuffer::get($event->genre5)
			];

			$genres = array_filter($genres, function ($value) {
				return $value !== null;
			});

			return array_map(function ($value) {
				return $value->name;
			}, $genres);
		});
	}

	public function resolveRating(Film $event)
	{
		FilmRatingsBuffer::add($event->id);

		return new Deferred(function () use ($event) {
			FilmRatingsBuffer::loadBuffered();

			$rating = FilmRatingsBuffer::get($event->id);
			if ($rating === null) {
				$rating = new FilmRating();
				$rating->initDefaults();
			}

			return $rating;
		});
	}

	public function resolveComments(Film $event, $args)
	{
		// This should be resolved in FilmCommentConnectionType
		return [
			'query' => $event->getComments(),
			'args' => $args
		];
	}
}