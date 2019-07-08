<?php

namespace app\graphql\types;

use app\components\helpers\GraphQLHelper;
use app\graphql\BaseObjectType;
use app\models\db\FilmComment;

class FilmCommentEdgeType extends BaseObjectType
{
	public function resolveCursor(FilmComment $comment)
	{
		return GraphQLHelper::encodeId($comment->id);
	}

	public function resolveNode(FilmComment $comment)
	{
		return $comment;
	}
}