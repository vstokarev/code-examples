<?php

namespace app\graphql\types;

use app\graphql\BaseObjectType;
use app\models\db\FilmComment;

class FilmCommentType extends BaseObjectType
{
	public function resolveId(FilmComment $comment)
	{
		return $comment->id;
	}

	public function resolveAuthor(FilmComment $comment)
	{
		return $comment->user;
	}

	public function resolveBody(FilmComment $comment)
	{
		return $comment->comment;
	}

	public function resolveCreatedAt(FilmComment $comment)
	{
		return $comment->date_added;
	}
}